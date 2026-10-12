<?php

namespace Restruct\WkhtmltoPdf;

use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessTimedOutException;

/**
 * Main entry point for wkhtmltopdf binary/Docker resolution.
 *
 * Detects the best execution strategy: direct binary or Docker container.
 * Provides a generic run() method for executing wkhtmltopdf commands.
 */
class WkhtmltoPdfStatic
{
    private static bool $initialized = false;

    /** Default process timeout in seconds (PDF generation can be slow) */
    private static int $defaultTimeout = 120;

    /** Cached Docker availability check */
    private static ?bool $dockerAvailable = null;

    /**
     * Per-process cache of binary probe results, keyed by path.
     *
     * @var array<string, bool>
     */
    private static array $probeCache = [];

    /** Timeout in seconds for one binary probe (`--version`) */
    private static int $probeTimeout = 10;

    /** Accepted values of WKHTMLTOPDF_LOCAL_FILE_ACCESS, see applyLocalFileAccessPolicy() */
    public const LOCAL_FILE_ACCESS_PERMISSIVE = 'permissive';
    public const LOCAL_FILE_ACCESS_ALLOWLIST = 'allowlist';
    public const LOCAL_FILE_ACCESS_DENY = 'deny';

    /**
     * Names of Docker containers started by this process that have not finished yet.
     * A shutdown function removes whatever is left here, see runDocker().
     *
     * @var array<string, true>
     */
    private static array $liveContainers = [];

    /** Whether the shutdown function that removes leftover containers is registered */
    private static bool $shutdownRegistered = false;

    /** Cached `uid:gid` for `docker run --user`; false = not resolved yet, null = unknown */
    private static string|null|false $dockerUser = false;

    /**
     * Include the bootstrap file (once).
     */
    public static function init(): void
    {
        if (!self::$initialized) {
            require_once __DIR__ . '/../bootstrap.php';
            self::$initialized = true;
        }
    }

    /**
     * Get the resolved path to the wkhtmltopdf binary.
     *
     * @return string Full path, or empty string if no direct binary available
     */
    public static function getPath(): string
    {
        self::init();

        return defined('WKHTMLTOPDF_PATH') ? WKHTMLTOPDF_PATH : '';
    }

    /**
     * Get the configured Docker image name.
     *
     * @return string Image name, or empty string if not configured
     */
    public static function getDockerImage(): string
    {
        self::init();

        return defined('WKHTMLTOPDF_DOCKER_IMAGE') ? WKHTMLTOPDF_DOCKER_IMAGE : '';
    }

    /**
     * Get the Docker network the container joins (`docker run --network`).
     *
     * @return string Network name (e.g. 'none'), or empty string for Docker's default network
     */
    public static function getDockerNetwork(): string
    {
        self::init();

        return defined('WKHTMLTOPDF_DOCKER_NETWORK') ? (string) WKHTMLTOPDF_DOCKER_NETWORK : '';
    }

    /**
     * Get the local file access policy, see applyLocalFileAccessPolicy().
     *
     * @return string One of the LOCAL_FILE_ACCESS_* constants (or an invalid value, which run() rejects)
     */
    public static function getLocalFileAccessMode(): string
    {
        self::init();

        return defined('WKHTMLTOPDF_LOCAL_FILE_ACCESS') && WKHTMLTOPDF_LOCAL_FILE_ACCESS !== ''
            ? (string) WKHTMLTOPDF_LOCAL_FILE_ACCESS
            : self::LOCAL_FILE_ACCESS_PERMISSIVE;
    }

    /**
     * Apply the local file access policy to a wkhtmltopdf argument list.
     *
     * - 'permissive' (default up to and including 1.2): arguments pass unchanged, as before 1.2.0.
     * - 'allowlist': `--enable-local-file-access` is refused; `--allow <path>` is accepted, so a
     *   project can still name the directories its HTML may read from.
     * - 'deny': both `--enable-local-file-access` and `--allow` are refused.
     *
     * In 'allowlist' and 'deny' mode `--disable-local-file-access` is put in front of the
     * arguments. wkhtmltopdf 0.12.6 already defaults to it, but WKHTMLTOPDF_PATH can point at any
     * build, and 0.12.5 and older default to local file access ENABLED. It goes first so it
     * applies to the page, which is the only position wkhtmltopdf reads it from here.
     *
     * @param string[] $args wkhtmltopdf arguments
     * @param string|null $mode Policy to apply, null = the configured one
     * @return string[] The arguments to run
     * @throws \InvalidArgumentException if the arguments ask for local file access the policy refuses
     * @throws \RuntimeException if the configured policy is not a known value
     */
    public static function applyLocalFileAccessPolicy(array $args, ?string $mode = null): array
    {
        $mode ??= self::getLocalFileAccessMode();

        if ($mode === self::LOCAL_FILE_ACCESS_PERMISSIVE) {
            return $args;
        }

        if ($mode !== self::LOCAL_FILE_ACCESS_ALLOWLIST && $mode !== self::LOCAL_FILE_ACCESS_DENY) {
            # Fail loudly: a typo must not silently fall back to permissive
            throw new \RuntimeException(
                "Invalid WKHTMLTOPDF_LOCAL_FILE_ACCESS '{$mode}', expected permissive, allowlist or deny"
            );
        }

        foreach ($args as $arg) {
            $arg = (string) $arg;
            if ($arg === '--enable-local-file-access') {
                throw new \InvalidArgumentException(
                    "--enable-local-file-access is refused (WKHTMLTOPDF_LOCAL_FILE_ACCESS={$mode})"
                    . ($mode === self::LOCAL_FILE_ACCESS_ALLOWLIST ? '; name the directories with --allow instead' : '')
                );
            }
            # `--allow=path` is checked too, in case a wkhtmltopdf build accepts that spelling
            if ($mode === self::LOCAL_FILE_ACCESS_DENY && ($arg === '--allow' || str_starts_with($arg, '--allow='))) {
                throw new \InvalidArgumentException(
                    "--allow is refused (WKHTMLTOPDF_LOCAL_FILE_ACCESS={$mode})"
                );
            }
        }

        return array_merge(['--disable-local-file-access'], $args);
    }

    /**
     * Build the `docker run` command line for one render.
     *
     * Pure function, no Docker involved, so the command can be tested without a daemon.
     *
     * - `--rm`: the container is deleted when it exits.
     * - `--init`: a minimal init runs as PID 1, so signals reach wkhtmltopdf and stray child
     *   processes are reaped; without it wkhtmltopdf is PID 1 and ignores SIGTERM by default.
     * - `--name`: lets runDocker() remove this exact container when the render times out or the
     *   PHP process ends early; killing the `docker` CLI process does not stop the container.
     * - `--user uid:gid`: the PHP process's own ids, so the output PDF in the shared temp dir is
     *   owned by the PHP user and can be deleted by it (sticky /tmp). The image has no USER, so
     *   without this the container runs as root (issue #3).
     * - `--network`: only when configured; the default network stays so HTML can load http assets.
     *
     * @param string $image Image reference (tag and/or digest)
     * @param string[] $args wkhtmltopdf arguments
     * @param string $containerName Name for the container
     * @param string|null $user `uid:gid`, or null to leave the image's default user (root)
     * @param string $network Network name, empty for Docker's default
     * @param string $tempDir Host directory mounted at the same path in the container
     * @return string[]
     */
    public static function buildDockerCommand(
        string $image,
        array $args,
        string $containerName,
        ?string $user,
        string $network,
        string $tempDir
    ): array {
        $command = ['docker', 'run', '--rm', '--init', '--name', $containerName];

        if ($user !== null) {
            $command[] = '--user';
            $command[] = $user;
        }

        if ($network !== '') {
            $command[] = '--network';
            $command[] = $network;
        }

        $command[] = '-v';
        $command[] = "{$tempDir}:{$tempDir}";
        $command[] = $image;

        return array_merge($command, $args);
    }

    /**
     * Check whether wkhtmltopdf is available via any strategy.
     */
    public static function isAvailable(): bool
    {
        return self::getStrategy() !== '';
    }

    /**
     * Determine the execution strategy.
     *
     * @return string 'binary', 'docker', or '' if unavailable
     */
    public static function getStrategy(): string
    {
        # Prefer direct binary (faster, no container overhead). It must actually start: a binary
        # that is executable but cannot load its shared libraries (e.g. one linked against
        # OpenSSL 1.1 on Ubuntu 22.04+, issue #6) would otherwise be chosen and fail every render,
        # where falling through to Docker could still work.
        $path = self::getPath();
        if ($path !== '' && self::probeBinary($path)) {
            return 'binary';
        }

        # Fall back to Docker
        $image = self::getDockerImage();
        if ($image !== '' && self::isDockerAvailable()) {
            return 'docker';
        }

        return '';
    }

    /**
     * Check that a wkhtmltopdf binary can actually start, by running it with `--version`.
     *
     * is_executable() only checks the file mode; it says nothing about whether the dynamic loader
     * can resolve the binary's shared libraries. A missing library makes the loader exit with
     * 127 before wkhtmltopdf runs at all, so the exit code of a cheap `--version` call is the
     * signal. The result is cached per path for the rest of the process, so the probe costs one
     * extra process start per path, not one per render.
     *
     * @param string $path Full path to a candidate binary
     * @return bool True if it ran and exited 0 within the probe timeout
     */
    public static function probeBinary(string $path): bool
    {
        if (array_key_exists($path, self::$probeCache)) {
            return self::$probeCache[$path];
        }

        # No point starting a process for a path that is not an executable file
        if ($path === '' || !is_file($path) || !is_executable($path)) {
            return self::$probeCache[$path] = false;
        }

        try {
            $process = new Process([$path, '--version']);
            $process->setTimeout(self::$probeTimeout);
            $process->run();
            $usable = $process->getExitCode() === 0;
        } catch (\Exception $e) {
            # Timed out (ProcessTimedOutException) or could not be started at all
            # (Symfony's RuntimeException): either way not a binary to render with
            $usable = false;
        }

        return self::$probeCache[$path] = $usable;
    }

    /**
     * Set the timeout for one binary probe. A probe that times out counts as unusable.
     */
    public static function setProbeTimeout(int $seconds): void
    {
        self::$probeTimeout = $seconds;
    }

    /**
     * Get the wkhtmltopdf version string.
     */
    public static function version(): string
    {
        $result = self::run(['-V']);

        return $result->isSuccessful() ? trim($result->output) : '';
    }

    /**
     * Set the default timeout for all executions.
     */
    public static function setDefaultTimeout(int $seconds): void
    {
        self::$defaultTimeout = $seconds;
    }

    /**
     * Get the default timeout.
     */
    public static function getDefaultTimeout(): int
    {
        return self::$defaultTimeout;
    }

    /**
     * Run wkhtmltopdf with the given arguments.
     *
     * Automatically chooses between direct binary and Docker execution.
     *
     * @param array $args Command-line arguments
     * @param int|null $timeout Timeout in seconds (null = default)
     * @return WkhtmltoPdfResult
     * @throws \RuntimeException if wkhtmltopdf is not available
     */
    public static function run(array $args = [], ?int $timeout = null): WkhtmltoPdfResult
    {
        $strategy = self::getStrategy();
        if ($strategy === '') {
            throw new \RuntimeException('wkhtmltopdf is not available (no binary or Docker image found)');
        }

        $effectiveTimeout = $timeout ?? self::$defaultTimeout;

        # One choke point for both strategies, so raw run() calls are covered too
        $args = self::applyLocalFileAccessPolicy($args);

        if ($strategy === 'docker') {
            return self::runDocker($args, $effectiveTimeout);
        }

        return self::runBinary($args, $effectiveTimeout);
    }

    /**
     * Execute via direct binary.
     */
    private static function runBinary(array $args, int $timeout): WkhtmltoPdfResult
    {
        $command = array_merge([self::getPath()], $args);
        $process = new Process($command);
        $process->setTimeout($timeout);

        try {
            $process->run();
        } catch (ProcessTimedOutException $e) {
            return new WkhtmltoPdfResult('', "Process timed out after {$timeout}s", -1);
        }

        return new WkhtmltoPdfResult(
            $process->getOutput(),
            $process->getErrorOutput(),
            $process->getExitCode() ?? -1
        );
    }

    /**
     * Execute via Docker container.
     *
     * Mounts the system temp directory so input/output files are accessible
     * to both host and container.
     *
     * The container gets a unique name. When the render does not finish normally (timeout, an
     * exception, or the PHP process ending mid-render) the container is removed by that name:
     * Symfony Process only signals the `docker` CLI on a timeout, and the container would keep
     * running and could write its PDF after the caller cleaned up (issue #3).
     */
    private static function runDocker(array $args, int $timeout): WkhtmltoPdfResult
    {
        $image = self::getDockerImage();
        $tempDir = sys_get_temp_dir();
        $name = 'wkhtmltopdf-' . bin2hex(random_bytes(8));

        # Build Docker command: docker run --rm -v /tmp:/tmp image [args...]
        # From 1.2.0 also --init, --name, --user uid:gid and optionally --network, see buildDockerCommand()
        $command = self::buildDockerCommand(
            $image,
            $args,
            $name,
            self::dockerUser(),
            self::getDockerNetwork(),
            $tempDir
        );

        $process = new Process($command);
        $process->setTimeout($timeout);

        self::trackContainer($name);
        $finished = false;

        try {
            $process->run();
            # The container exited on its own and --rm removes it
            $finished = true;
        } catch (ProcessTimedOutException $e) {
            return new WkhtmltoPdfResult('', "Docker process timed out after {$timeout}s", -1);
        } finally {
            if (!$finished) {
                self::removeContainer($name);
            }
            unset(self::$liveContainers[$name]);
        }

        return new WkhtmltoPdfResult(
            $process->getOutput(),
            $process->getErrorOutput(),
            $process->getExitCode() ?? -1
        );
    }

    /**
     * Remember a running container, and make sure it is removed if PHP ends before the render does.
     *
     * A `finally` block does not run when PHP stops on a fatal error such as max_execution_time,
     * but shutdown functions do. A SIGKILL of the PHP process itself (e.g. an FPM worker killed
     * by request_terminate_timeout) runs neither; that container lives until wkhtmltopdf exits.
     */
    private static function trackContainer(string $name): void
    {
        self::$liveContainers[$name] = true;

        if (!self::$shutdownRegistered) {
            self::$shutdownRegistered = true;
            register_shutdown_function(static function (): void {
                foreach (array_keys(self::$liveContainers) as $leftover) {
                    self::removeContainer($leftover);
                }
                self::$liveContainers = [];
            });
        }
    }

    /**
     * Force-remove a container by name (`docker rm -f` kills it first if it still runs).
     *
     * Failures are ignored: the container may already be gone, or be mid-removal by --rm.
     */
    private static function removeContainer(string $name): void
    {
        try {
            $process = new Process(['docker', 'rm', '-f', $name]);
            $process->setTimeout(30);
            $process->run();
        } catch (\Exception $e) {
            # Best effort, the render result is already decided
        }
    }

    /**
     * The `uid:gid` the PHP process runs as, for `docker run --user`.
     *
     * posix_geteuid()/posix_getegid() when the posix extension is loaded, otherwise `id -u` and
     * `id -g`. Not getmyuid(): that is the owner of the running script file, not the process.
     * Null if neither works; the container then runs as the image's default user (root).
     */
    private static function dockerUser(): ?string
    {
        if (self::$dockerUser !== false) {
            return self::$dockerUser;
        }

        if (function_exists('posix_geteuid') && function_exists('posix_getegid')) {
            return self::$dockerUser = posix_geteuid() . ':' . posix_getegid();
        }

        try {
            $uid = new Process(['id', '-u']);
            $uid->run();
            $gid = new Process(['id', '-g']);
            $gid->run();
            $u = trim($uid->getOutput());
            $g = trim($gid->getOutput());
            if ($uid->isSuccessful() && $gid->isSuccessful() && ctype_digit($u) && ctype_digit($g)) {
                return self::$dockerUser = "{$u}:{$g}";
            }
        } catch (\Exception $e) {
            # No `id` either; fall through to null
        }

        return self::$dockerUser = null;
    }

    /**
     * Check if Docker is available and running.
     */
    private static function isDockerAvailable(): bool
    {
        if (self::$dockerAvailable !== null) {
            return self::$dockerAvailable;
        }

        try {
            $process = new Process(['docker', 'info']);
            $process->setTimeout(5);
            $process->run();
            self::$dockerAvailable = $process->isSuccessful();
        } catch (\Exception $e) {
            self::$dockerAvailable = false;
        }

        return self::$dockerAvailable;
    }
}
