<?php

namespace Restruct\WkhtmltoPdf\Tests;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Restruct\WkhtmltoPdf\WkhtmltoPdfStatic;

/**
 * Output paths outside the temp dir under Docker (from 1.2.0).
 *
 * The container can only write to the temp dir, so before 1.2.0 an output path elsewhere failed
 * with "Unable to write to destination". The render now goes to a temp file that is moved to the
 * requested path afterwards. No Docker daemon is needed: the run tests use a fake `docker` that
 * writes a file at its last argument, the way wkhtmltopdf writes its output.
 */
class DockerOutputTest extends TestCase
{
    private const IMAGE = 'ghcr.io/restruct/wkhtmltopdf:0.12.6@sha256:3b95bf589c125dbcbbb0e33619d1241953d6da019d80272d85858816d01c8396';

    /** Scratch directory in the temp dir for the fake docker and its log */
    private string $workDir;

    /** Scratch directory OUTSIDE the temp dir, the requested output location */
    private string $outDir;

    protected function setUp(): void
    {
        $this->workDir = sys_get_temp_dir() . '/wkhtmltopdf-output-test-' . bin2hex(random_bytes(6));
        mkdir($this->workDir);
        # Next to the tests, which is outside the temp dir on every machine running the suite
        $this->outDir = __DIR__ . '/.output-test-' . bin2hex(random_bytes(6));
        mkdir($this->outDir);
    }

    protected function tearDown(): void
    {
        foreach ([$this->workDir, $this->outDir] as $dir) {
            foreach (glob($dir . '/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($dir);
        }
    }

    public function testOutputOutsideTempDirIsRedirected(): void
    {
        $out = $this->outDir . '/doc.pdf';

        $this->assertSame($out, WkhtmltoPdfStatic::dockerOutputRedirect(['in.html', $out], sys_get_temp_dir()));
    }

    public function testOutputInsideTempDirIsLeftAlone(): void
    {
        $tmp = sys_get_temp_dir();
        $this->assertNull(WkhtmltoPdfStatic::dockerOutputRedirect(['in.html', $tmp . '/doc.pdf'], $tmp));
        # The resolved spelling counts as inside too (macOS: /private/var/folders/...)
        $this->assertNull(WkhtmltoPdfStatic::dockerOutputRedirect(['in.html', realpath($tmp) . '/doc.pdf'], $tmp));
    }

    public function testStdoutOptionsAndSingleArgumentsAreLeftAlone(): void
    {
        $tmp = sys_get_temp_dir();
        $this->assertNull(WkhtmltoPdfStatic::dockerOutputRedirect(['in.html', '-'], $tmp), 'stdout');
        $this->assertNull(WkhtmltoPdfStatic::dockerOutputRedirect(['-V'], $tmp), 'version call');
        $this->assertNull(WkhtmltoPdfStatic::dockerOutputRedirect(['--quiet', '--version'], $tmp), 'option last');
    }

    public function testRelativeOutputIsLeftAsBefore(): void
    {
        # The wrapper passes absolute paths; a raw run() with a relative one behaves as before 1.2.0
        $this->assertNull(WkhtmltoPdfStatic::dockerOutputRedirect(['in.html', 'out/doc.pdf'], '/nonexistent-temp'));
    }

    public function testMoveFileRenamesAndReportsFailure(): void
    {
        $from = $this->workDir . '/a.pdf';
        file_put_contents($from, 'pdf');

        $this->assertTrue(WkhtmltoPdfStatic::moveFile($from, $this->outDir . '/b.pdf'));
        $this->assertSame('pdf', file_get_contents($this->outDir . '/b.pdf'));
        $this->assertFileDoesNotExist($from);

        # A destination directory that does not exist: rename and copy both fail, the source stays
        file_put_contents($from, 'pdf');
        $this->assertFalse(WkhtmltoPdfStatic::moveFile($from, $this->outDir . '/missing/b.pdf'));
        $this->assertFileExists($from);
    }

    /**
     * The container is given a temp path, and the file ends up at the requested path.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testRunRendersIntoTempAndMovesToRequestedPath(): void
    {
        $log = $this->installFakeDocker('exit 0');
        $out = $this->outDir . '/doc.pdf';

        $result = WkhtmltoPdfStatic::run(['in.html', $out], 10);

        $this->assertTrue($result->isSuccessful(), $result->errorOutput);
        $this->assertSame('%PDF fake', trim((string) file_get_contents($out)));

        $run = $this->runCall($log);
        $given = end($run);
        $this->assertNotSame($out, $given, 'The container must not get the path outside the temp dir');
        $this->assertStringEndsWith('.pdf', $given);
        $this->assertTempOutputGone($given);
    }

    /**
     * A failed render keeps its own exit code and error output; whatever it wrote is moved too,
     * as the binary strategy would have left it at the requested path.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testFailedRenderKeepsItsExitCode(): void
    {
        $this->installFakeDocker('echo "Exit with code 1 due to network error" >&2; exit 1');
        $out = $this->outDir . '/doc.pdf';

        $result = WkhtmltoPdfStatic::run(['in.html', $out], 10);

        $this->assertSame(1, $result->exitCode);
        $this->assertStringContainsString('network error', $result->errorOutput);
        $this->assertStringNotContainsString('Could not move', $result->errorOutput);
        $this->assertFileExists($out);
    }

    /**
     * A render that succeeded but cannot be moved must not report success.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testFailedMoveTurnsSuccessIntoFailure(): void
    {
        $log = $this->installFakeDocker('exit 0');

        $result = WkhtmltoPdfStatic::run(['in.html', $this->outDir . '/missing/doc.pdf'], 10);

        $this->assertSame(1, $result->exitCode);
        $this->assertStringContainsString('Could not move the rendered file', $result->errorOutput);
        $run = $this->runCall($log);
        $this->assertTempOutputGone(end($run));
    }

    /**
     * On a timeout the temp output is removed and nothing reaches the requested path.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testTimeoutRemovesTempOutput(): void
    {
        $log = $this->installFakeDocker('exec sleep 30');
        $out = $this->outDir . '/doc.pdf';

        $result = WkhtmltoPdfStatic::run(['in.html', $out], 1);

        $this->assertSame(-1, $result->exitCode);
        $this->assertFileDoesNotExist($out);
        $run = $this->runCall($log);
        $this->assertTempOutputGone(end($run));
    }

    private function assertTempOutputGone(string $tmpOutput): void
    {
        $this->assertFileDoesNotExist($tmpOutput, 'temp output removed');
        # And its bare tempnam() reservation (the same path without the extension)
        $this->assertFileDoesNotExist(preg_replace('/\.[^.\/]+$/', '', $tmpOutput), 'tempnam reservation removed');
    }

    /**
     * Fake `docker` first on PATH: `info` succeeds, every call is logged one argument per tab,
     * and `run` writes "%PDF fake" to its last argument before running $runBody.
     */
    private function installFakeDocker(string $runBody): string
    {
        $log = $this->workDir . '/calls.log';
        $fake = $this->workDir . '/docker';
        file_put_contents($fake, <<<SH
#!/bin/sh
( IFS="$(printf '\\t')"; echo "\$*" ) >> '{$log}'
case "\$1" in
    run) for a in "\$@"; do last="\$a"; done; echo '%PDF fake' > "\$last"; {$runBody} ;;
    *) exit 0 ;;
esac
SH);
        chmod($fake, 0755);
        touch($log);

        putenv('PATH=' . $this->workDir . PATH_SEPARATOR . getenv('PATH'));
        define('WKHTMLTOPDF_PATH', '');
        define('WKHTMLTOPDF_DOCKER_IMAGE', self::IMAGE);

        return $log;
    }

    /**
     * @return list<string>|null The arguments of the first `docker run`
     */
    private function runCall(string $log): ?array
    {
        foreach (file($log, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $call = explode("\t", $line);
            if ($call[0] === 'run') {
                return $call;
            }
        }

        return null;
    }
}
