<?php

namespace Restruct\WkhtmltoPdf\Tests;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Restruct\WkhtmltoPdf\WkhtmltoPdfStatic;

/**
 * The binary probe (issue #6): a binary is only chosen if it can actually start.
 *
 * Uses fake executables in a temp dir, so no real wkhtmltopdf is needed, except for the test of
 * the bundled Linux binary, which only runs on x86_64 Linux.
 */
class BinaryProbeTest extends TestCase
{
    /** Scratch directory for the fake executables, removed in tearDown() */
    private string $workDir;

    protected function setUp(): void
    {
        $this->workDir = sys_get_temp_dir() . '/wkhtmltopdf-probe-test-' . bin2hex(random_bytes(6));
        mkdir($this->workDir);
    }

    protected function tearDown(): void
    {
        # Restore the default, the timeout test lowers it and the setting is static
        WkhtmltoPdfStatic::setProbeTimeout(10);

        foreach (glob($this->workDir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->workDir);
    }

    public function testProbeAcceptsBinaryThatStarts(): void
    {
        $fake = $this->fakeExecutable('starts', "echo 'wkhtmltopdf 0.12.6.1 (with patched qt)'\nexit 0");

        $this->assertTrue(WkhtmltoPdfStatic::probeBinary($fake));
    }

    public function testProbeRejectsBinaryWithMissingLibrary(): void
    {
        # What the dynamic loader does when a DT_NEEDED library is missing: message on stderr, exit 127
        $fake = $this->fakeExecutable(
            'missing-lib',
            "echo 'error while loading shared libraries: libssl.so.1.1: cannot open shared object file' >&2\nexit 127"
        );

        $this->assertTrue(is_executable($fake), 'Control: the fake passes the old is_executable() check');
        $this->assertFalse(WkhtmltoPdfStatic::probeBinary($fake));
    }

    public function testProbeRejectsFileTheKernelCannotExecute(): void
    {
        # Exec bit set, but neither a valid binary nor a script: execve() fails with ENOEXEC
        $fake = $this->workDir . '/garbage';
        file_put_contents($fake, "\x7fELF this is not a real ELF file\n");
        chmod($fake, 0755);

        $this->assertTrue(is_executable($fake), 'Control: the fake passes the old is_executable() check');
        $this->assertFalse(WkhtmltoPdfStatic::probeBinary($fake));
    }

    public function testProbeRejectsMissingAndNonExecutablePaths(): void
    {
        $plain = $this->workDir . '/not-executable';
        file_put_contents($plain, "#!/bin/sh\nexit 0\n");
        chmod($plain, 0644);

        $this->assertFalse(WkhtmltoPdfStatic::probeBinary($this->workDir . '/does-not-exist'));
        $this->assertFalse(WkhtmltoPdfStatic::probeBinary($plain));
        $this->assertFalse(WkhtmltoPdfStatic::probeBinary(''));
        # A directory has the exec bit too, but is not a binary
        $this->assertFalse(WkhtmltoPdfStatic::probeBinary($this->workDir));
    }

    public function testProbeTimesOutOnBinaryThatHangs(): void
    {
        # exec, so the timeout kill hits sleep itself and does not leave an orphan running
        $fake = $this->fakeExecutable('hangs', 'exec sleep 30');
        WkhtmltoPdfStatic::setProbeTimeout(1);

        $start = microtime(true);
        $usable = WkhtmltoPdfStatic::probeBinary($fake);

        $this->assertFalse($usable);
        $this->assertLessThan(10, microtime(true) - $start, 'The probe must give up at its timeout');
    }

    public function testProbeResultIsCachedPerProcess(): void
    {
        # Each run appends a line, so the line count is the number of times the probe started it
        $counter = $this->workDir . '/runs.log';
        $fake = $this->fakeExecutable('counted', "echo run >> '{$counter}'\nexit 0");

        $this->assertTrue(WkhtmltoPdfStatic::probeBinary($fake));
        $this->assertTrue(WkhtmltoPdfStatic::probeBinary($fake));

        $this->assertSame(1, count(file($counter)));
    }

    /**
     * The resolved binary cannot start and no Docker image is configured: before the probe,
     * getStrategy() returned 'binary' here and every render failed.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testStrategyRejectsResolvedBinaryThatCannotStart(): void
    {
        $fake = $this->fakeExecutable('missing-lib', 'exit 127');
        # Fresh process, so these constants are not defined yet; bootstrap.php leaves them alone
        define('WKHTMLTOPDF_PATH', $fake);
        define('WKHTMLTOPDF_DOCKER_IMAGE', '');

        $this->assertSame('', WkhtmltoPdfStatic::getStrategy());
        $this->assertFalse(WkhtmltoPdfStatic::isAvailable());
    }

    /**
     * An env var pointing at a binary that cannot start must not become WKHTMLTOPDF_PATH;
     * bootstrap.php falls through to its OS candidates instead.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testBootstrapSkipsEnvBinaryThatCannotStart(): void
    {
        $fake = $this->fakeExecutable('missing-lib', 'exit 127');
        putenv('WKHTMLTOPDF_PATH=' . $fake);

        $this->assertNotSame($fake, WkhtmltoPdfStatic::getPath());
    }

    /**
     * The bundled Linux binary must start on the host it is tested on. In CI that is a plain
     * Ubuntu runner with only the runtime libraries the README lists.
     */
    public function testBundledLinuxBinaryStarts(): void
    {
        if (PHP_OS_FAMILY !== 'Linux' || php_uname('m') !== 'x86_64') {
            $this->markTestSkipped('The bundled Linux binary is x86_64 Linux only');
        }

        $this->assertTrue(WkhtmltoPdfStatic::probeBinary(dirname(__DIR__) . '/x64/linux/wkhtmltopdf'));
    }

    private function fakeExecutable(string $name, string $body): string
    {
        $path = $this->workDir . '/' . $name;
        file_put_contents($path, "#!/bin/sh\n{$body}\n");
        chmod($path, 0755);

        return $path;
    }
}
