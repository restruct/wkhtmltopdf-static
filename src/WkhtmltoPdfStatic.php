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
        # Prefer direct binary (faster, no container overhead)
        $path = self::getPath();
        if ($path !== '' && is_executable($path)) {
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
