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
     * Directories whose contents an `--allow` path may never be bind-mounted from under Docker:
     * the path itself and everything below it is refused. System and secret locations that no
     * HTML asset belongs in, see dockerMountPath().
     */
    private const DOCKER_MOUNT_REFUSED_TREES = [
        '/proc', '/sys', '/dev', '/etc', '/root', '/boot', '/run', '/var/run',
        '/private/etc', '/private/var/run', '/System', '/Library',
    ];

    /**
     * Parents whose direct children (home directories) are refused as a whole; a folder further
     * down, e.g. /home/forge/example.com/public/pdf-assets, is fine. A home directory holds
     * `.ssh`, `.env` files and every site of that user.
     */
    private const DOCKER_MOUNT_REFUSED_HOMES = ['/home', '/Users'];

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
     * - `-v <dir>:<dir>:ro` per read-only mount (from 1.2.0): the `--allow` directories, so a
     *   file:// URL into them resolves in the container as it does on the host, see dockerAllowMounts().
     *
     * @param string $image Image reference (tag and/or digest)
     * @param string[] $args wkhtmltopdf arguments
     * @param string $containerName Name for the container
     * @param string|null $user `uid:gid`, or null to leave the image's default user (root)
     * @param string $network Network name, empty for Docker's default
     * @param string|string[] $tempDir Host directory mounted read-write at the same path in the
     *        container; several when the temp dir has two spellings, see runDocker()
     * @param array<string, string> $readOnlyMounts Container path => host path, mounted read-only
     * @return string[]
     */
    public static function buildDockerCommand(
        string $image,
        array $args,
        string $containerName,
        ?string $user,
        string $network,
        string|array $tempDir,
        array $readOnlyMounts = []
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

        foreach ((array) $tempDir as $dir) {
            $command[] = '-v';
            $command[] = "{$dir}:{$dir}";
        }

        # After the temp dir, so a mount below it (never produced by dockerAllowMounts()) would still win
        foreach ($readOnlyMounts as $target => $source) {
            $command[] = '-v';
            $command[] = "{$source}:{$target}:ro";
        }

        $command[] = $image;

        return array_merge($command, $args);
    }

    /**
     * The read-only bind mounts the Docker strategy needs for the `--allow` paths in $args.
     *
     * The container only sees the temp dir otherwise, so an allowed directory outside it would be
     * allowed by wkhtmltopdf but missing in the container, and every file:// URL into it would
     * fail. Each `--allow <dir>` (or `--allow=<dir>`) is mounted read-only at the same path,
     * after dockerMountPath() has checked it. Paths inside the temp dir are left out: that is
     * already mounted (read-write). Duplicates collapse into one mount.
     *
     * @param string[] $args wkhtmltopdf arguments (after applyLocalFileAccessPolicy())
     * @param string $tempDir The temp dir runDocker() mounts
     * @return array<string, string> Container path => host path, for buildDockerCommand()
     * @throws \InvalidArgumentException if an allowed path cannot or may not be mounted
     */
    public static function dockerAllowMounts(array $args, string $tempDir): array
    {
        $mounts = [];
        $args = array_values($args);

        # Both spellings of the temp dir: on macOS sys_get_temp_dir() is below /var, a symlink to /private/var
        $tempRoots = array_unique(array_filter([rtrim($tempDir, '/'), rtrim((string) realpath($tempDir), '/')]));

        $isInTemp = static function (string $candidate) use ($tempRoots): bool {
            foreach ($tempRoots as $root) {
                if ($candidate === $root || str_starts_with($candidate, $root . '/')) {
                    return true;
                }
            }

            return false;
        };

        foreach ($args as $i => $arg) {
            $arg = (string) $arg;
            if ($arg === '--allow') {
                $path = (string) ($args[$i + 1] ?? '');
            } elseif (str_starts_with($arg, '--allow=')) {
                $path = substr($arg, strlen('--allow='));
            } else {
                continue;
            }

            [$target, $source] = self::dockerMountPath($path);

            if ($isInTemp($source)) {
                # Already in the container through the temp dir mount
                continue;
            }
            if ($isInTemp($target)) {
                # A symlink in the temp dir pointing out of it: in the container it would dangle,
                # and a mount on top of a path inside the temp dir mount is not worth the edge cases
                throw new \InvalidArgumentException(
                    "--allow path '{$path}' is a link in the temp dir to '{$source}'; allow '{$source}' itself"
                );
            }

            $mounts[$target] = $source;
        }

        return $mounts;
    }

    /**
     * Check that an `--allow` path may be bind-mounted into the container, and resolve it.
     *
     * Refused (InvalidArgumentException), for the path as given and for its realpath, so a
     * symlink cannot point a harmless-looking path at a refused one:
     * - not absolute, or containing `.`/`..` segments, a `:` or `,` (Docker's mount separators)
     *   or a NUL byte;
     * - not an existing directory (a single file: allow its directory instead);
     * - `/` or any top-level directory (`/var`, `/home`, `/srv`, ...);
     * - a home directory as a whole (`/home/<user>`, `/Users/<user>`);
     * - anything in or below DOCKER_MOUNT_REFUSED_TREES (`/etc`, `/proc`, `/root`, ...).
     *
     * The container path is the path as given (trailing slash dropped), because that is the
     * spelling the HTML's file:// URLs and wkhtmltopdf's `--allow` use; the host path is the
     * realpath, so a symlinked deploy directory (e.g. `current/` -> a release) mounts what it
     * points at now.
     *
     * @return array{0: string, 1: string} [container path, host path]
     * @throws \InvalidArgumentException
     */
    public static function dockerMountPath(string $path): array
    {
        $refuse = static function (string $why) use ($path): never {
            throw new \InvalidArgumentException("--allow path '{$path}' cannot be mounted into the Docker container: {$why}");
        };

        if ($path === '' || $path[0] !== '/') {
            $refuse('not an absolute path');
        }
        if (strpbrk($path, ":,\0") !== false) {
            $refuse('contains a colon, comma or NUL byte');
        }
        $target = rtrim($path, '/');
        $segments = explode('/', $target);
        if (in_array('.', $segments, true) || in_array('..', $segments, true)) {
            $refuse('contains . or .. segments');
        }

        $source = realpath($path);
        if ($source === false || !is_dir($source)) {
            $refuse('not an existing directory (to allow one file, allow its directory)');
        }

        foreach (array_unique([$target, $source]) as $candidate) {
            $depth = count(array_filter(explode('/', $candidate), 'strlen'));
            if ($depth <= 1) {
                $refuse("'{$candidate}' is the root or a top-level directory");
            }
            if ($depth === 2 && in_array(dirname($candidate), self::DOCKER_MOUNT_REFUSED_HOMES, true)) {
                $refuse("'{$candidate}' is a whole home directory");
            }
            foreach (self::DOCKER_MOUNT_REFUSED_TREES as $tree) {
                if ($candidate === $tree || str_starts_with($candidate, $tree . '/')) {
                    $refuse("'{$candidate}' is in {$tree}");
                }
            }
        }

        return [$target, $source];
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
     * to both host and container, and (from 1.2.0) each `--allow` directory read-only.
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
        # Both spellings when the temp dir is behind a symlink: on macOS sys_get_temp_dir() is
        # /var/folders/..., but tempnam() returns the resolved /private/var/folders/... path, and
        # the container only had the first, so wkhtmltopdf did not find its input there and tried
        # it as a URL ("Host private not found"). Seen with OrbStack on macOS.
        $tempDirs = array_values(array_unique(array_filter([rtrim($tempDir, '/'), rtrim((string) realpath($tempDir), '/')])));
        $name = 'wkhtmltopdf-' . bin2hex(random_bytes(8));

        # An output path outside the temp dir does not exist in the container ("Unable to write to
        # destination"). Render into a temp file instead and move it there afterwards (from 1.2.0).
        $requestedOutput = self::dockerOutputRedirect($args, $tempDir);
        $tmpOutput = null;
        if ($requestedOutput !== null) {
            $tmpOutput = self::dockerTempOutput($tempDir, $requestedOutput);
            $args[array_key_last($args)] = $tmpOutput;
        }

        # Build Docker command: docker run --rm -v /tmp:/tmp image [args...]
        # From 1.2.0 also --init, --name, --user uid:gid and optionally --network, see buildDockerCommand()
        # From 1.2.0 the --allow directories are mounted read-only too; a refused path throws here,
        # before any container starts
        $command = self::buildDockerCommand(
            $image,
            $args,
            $name,
            self::dockerUser(),
            self::getDockerNetwork(),
            $tempDirs,
            self::dockerAllowMounts($args, $tempDir)
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
            # Timeout or exception: the container is gone now, so nothing writes the temp output
            # any more and it can be removed. On success it is moved below and removed after that.
            if (!$finished && $tmpOutput !== null) {
                self::removeTempOutput($tmpOutput);
            }
        }

        $exitCode = $process->getExitCode() ?? -1;
        $errorOutput = $process->getErrorOutput();

        if ($tmpOutput !== null) {
            # Moved whatever wkhtmltopdf wrote, also on a non-zero exit: the binary strategy leaves
            # that file (e.g. the blank PDF after a blocked resource) at the requested path too
            if (is_file($tmpOutput) && !self::moveFile($tmpOutput, $requestedOutput)) {
                $errorOutput .= "\nCould not move the rendered file from {$tmpOutput} to {$requestedOutput}";
                # A render that succeeded must not report success without its file
                if ($exitCode === 0) {
                    $exitCode = 1;
                }
            }
            self::removeTempOutput($tmpOutput);
        }

        return new WkhtmltoPdfResult($process->getOutput(), $errorOutput, $exitCode);
    }

    /**
     * The output path to render somewhere else under Docker, or null to leave the arguments as they are.
     *
     * wkhtmltopdf takes the output file as its last argument. It needs moving when it is a file
     * path outside the temp dir (the only writable mount). Not for `-` (stdout), an option as the
     * last argument (e.g. `-V`), fewer than two arguments, or a relative path: the wrapper always
     * passes absolute paths, and a relative one is left exactly as before 1.2.0 (it resolves
     * against the container's working directory, not PHP's, so it is not usable under Docker).
     *
     * @param string[] $args wkhtmltopdf arguments
     * @param string $tempDir The temp dir runDocker() mounts (either spelling)
     * @return string|null Absolute requested output path
     */
    public static function dockerOutputRedirect(array $args, string $tempDir): ?string
    {
        $args = array_values($args);
        if (count($args) < 2) {
            return null;
        }

        $output = (string) $args[count($args) - 1];
        if ($output === '' || $output[0] !== '/') {
            # Also covers `-` (stdout) and an option such as `-V` in the last position
            return null;
        }

        # Compare the output's directory with both spellings of the temp dir (macOS: /var vs /private/var)
        $dir = dirname($output);
        $dirReal = realpath($dir);
        foreach (array_filter([rtrim($tempDir, '/'), rtrim((string) realpath($tempDir), '/')]) as $root) {
            foreach (array_filter([$dir, $dirReal]) as $candidate) {
                if ($candidate === $root || str_starts_with($candidate, $root . '/')) {
                    return null;
                }
            }
        }

        return $output;
    }

    /**
     * Move a file, by rename() where possible, else copy and unlink (rename() fails across file
     * systems, e.g. from a tmpfs /tmp to the disk the site lives on).
     *
     * @return bool True if $to holds the file afterwards
     */
    public static function moveFile(string $from, string $to): bool
    {
        if (@rename($from, $to)) {
            return true;
        }

        if (!@copy($from, $to)) {
            return false;
        }
        @unlink($from);

        return true;
    }

    /**
     * Reserve a temp output path in the temp dir with the requested file's extension.
     *
     * Same reservation scheme as WkhtmltoPdf::createTempPath(): tempnam() creates the bare file,
     * which is kept so no other call gets the same name, and removeTempOutput() deletes both.
     * The extension is kept because wkhtmltopdf's sibling tools pick the format from it.
     *
     * @throws \RuntimeException if no temp file can be created
     */
    private static function dockerTempOutput(string $tempDir, string $requestedOutput): string
    {
        $base = tempnam($tempDir, 'wkdock_');
        if ($base === false) {
            throw new \RuntimeException('Could not create a temp file in ' . $tempDir);
        }
        $ext = pathinfo($requestedOutput, PATHINFO_EXTENSION);

        return $base . ($ext !== '' ? '.' . $ext : '.out');
    }

    /**
     * Delete a dockerTempOutput() path and its bare tempnam() reservation.
     */
    private static function removeTempOutput(string $path): void
    {
        @unlink($path);
        $base = preg_replace('/\.[^.\/]+$/', '', $path);
        if ($base !== null && $base !== $path) {
            @unlink($base);
        }
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
