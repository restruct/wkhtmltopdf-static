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
     */
    private static function runDocker(array $args, int $timeout): WkhtmltoPdfResult
    {
        $image = self::getDockerImage();
        $tempDir = sys_get_temp_dir();

        # Build Docker command: docker run --rm -v /tmp:/tmp image [args...]
        $command = array_merge(
            ['docker', 'run', '--rm', '-v', "{$tempDir}:{$tempDir}"],
            [$image],
            $args
        );

        $process = new Process($command);
        $process->setTimeout($timeout);

        try {
            $process->run();
        } catch (ProcessTimedOutException $e) {
            return new WkhtmltoPdfResult('', "Docker process timed out after {$timeout}s", -1);
        }

        return new WkhtmltoPdfResult(
            $process->getOutput(),
            $process->getErrorOutput(),
            $process->getExitCode() ?? -1
        );
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
