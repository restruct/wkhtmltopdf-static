<?php

namespace Restruct\WkhtmltoPdf\Tests;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Restruct\WkhtmltoPdf\WkhtmltoPdfStatic;

/**
 * Docker strategy hardening (issue #3): the command line, and removal of the container when a
 * render does not finish.
 *
 * No Docker daemon is needed. The command line is a pure function; the run tests put a fake
 * `docker` executable first on PATH that logs every call it gets.
 */
class DockerStrategyTest extends TestCase
{
    private const IMAGE = 'ghcr.io/restruct/wkhtmltopdf:0.12.6@sha256:3b95bf589c125dbcbbb0e33619d1241953d6da019d80272d85858816d01c8396';

    /** Scratch directory for the fake docker and its call log, removed in tearDown() */
    private string $workDir;

    protected function setUp(): void
    {
        $this->workDir = sys_get_temp_dir() . '/wkhtmltopdf-docker-test-' . bin2hex(random_bytes(6));
        mkdir($this->workDir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->workDir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->workDir);
    }

    public function testCommandRunsAsGivenUserWithInitAndName(): void
    {
        $command = WkhtmltoPdfStatic::buildDockerCommand(
            self::IMAGE,
            ['--quiet', '/tmp/in.html', '/tmp/out.pdf'],
            'wkhtmltopdf-abc',
            '1000:1001',
            '',
            '/tmp'
        );

        $this->assertSame(
            [
                'docker', 'run', '--rm', '--init', '--name', 'wkhtmltopdf-abc', '--user', '1000:1001',
                '-v', '/tmp:/tmp', self::IMAGE, '--quiet', '/tmp/in.html', '/tmp/out.pdf',
            ],
            $command
        );
    }

    public function testCommandAddsNetworkOnlyWhenConfigured(): void
    {
        $withNetwork = WkhtmltoPdfStatic::buildDockerCommand(self::IMAGE, ['a', 'b'], 'n', '1:1', 'none', '/tmp');
        $withoutNetwork = WkhtmltoPdfStatic::buildDockerCommand(self::IMAGE, ['a', 'b'], 'n', '1:1', '', '/tmp');

        $this->assertSame('none', $this->valueAfter($withNetwork, '--network'));
        # Control: the default network must not get a --network flag at all
        $this->assertNotContains('--network', $withoutNetwork);
    }

    public function testCommandWithoutKnownUserLeavesImageDefault(): void
    {
        $command = WkhtmltoPdfStatic::buildDockerCommand(self::IMAGE, ['a', 'b'], 'n', null, '', '/tmp');

        $this->assertNotContains('--user', $command);
        # The image and its arguments still come last, in order
        $this->assertSame([self::IMAGE, 'a', 'b'], array_slice($command, -3));
    }

    /**
     * Before 1.2.0 a timeout only stopped the `docker` CLI; the container kept running.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testTimeoutRemovesTheNamedContainer(): void
    {
        $log = $this->installFakeDocker('exec sleep 30');

        $start = microtime(true);
        $result = WkhtmltoPdfStatic::run(['in.html', 'out.pdf'], 1);

        $this->assertSame(-1, $result->exitCode);
        $this->assertLessThan(20, microtime(true) - $start, 'The render must give up at its timeout');

        $calls = $this->calls($log);
        $run = $this->findCall($calls, 'run');
        $this->assertNotNull($run, 'docker run was called');
        $name = $this->valueAfter($run, '--name');
        $this->assertMatchesRegularExpression('/^wkhtmltopdf-[0-9a-f]{16}$/', $name);
        $this->assertContains(['rm', '-f', $name], $calls, 'The timed-out container is removed by name');
    }

    /**
     * A render that finishes leaves removal to --rm; no extra docker call.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testFinishedRenderDoesNotRemoveContainer(): void
    {
        $log = $this->installFakeDocker('exit 0');

        $result = WkhtmltoPdfStatic::run(['in.html', 'out.pdf'], 10);

        $this->assertTrue($result->isSuccessful());
        $this->assertNull($this->findCall($this->calls($log), 'rm'));
    }

    /**
     * The command that actually runs carries the PHP process's uid:gid, the pinned image and the
     * policy flag, and reads the configured network.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testRunPassesProcessUserAndConfiguredNetwork(): void
    {
        if (!function_exists('posix_geteuid')) {
            $this->markTestSkipped('Needs the posix extension to know the expected uid');
        }
        define('WKHTMLTOPDF_DOCKER_NETWORK', 'none');
        define('WKHTMLTOPDF_LOCAL_FILE_ACCESS', 'deny');
        $log = $this->installFakeDocker('exit 0');

        WkhtmltoPdfStatic::run(['in.html', 'out.pdf'], 10);

        $run = $this->findCall($this->calls($log), 'run');
        $this->assertSame(posix_geteuid() . ':' . posix_getegid(), $this->valueAfter($run, '--user'));
        $this->assertSame('none', $this->valueAfter($run, '--network'));
        $this->assertContains('--init', $run);
        $this->assertSame(
            [self::IMAGE, '--disable-local-file-access', 'in.html', 'out.pdf'],
            array_slice($run, -4)
        );
    }

    /**
     * Puts a fake `docker` first on PATH and pins the strategy to Docker.
     *
     * The fake answers `docker info` with success (so the strategy resolves to docker), logs every
     * call one argument per tab-separated field, and runs $runBody for `docker run`.
     *
     * @return string Path of the call log
     */
    private function installFakeDocker(string $runBody): string
    {
        $log = $this->workDir . '/calls.log';
        $fake = $this->workDir . '/docker';
        file_put_contents($fake, <<<SH
#!/bin/sh
# One line per call, arguments separated by tabs
( IFS="$(printf '\\t')"; echo "\$*" ) >> '{$log}'
case "\$1" in
    info) exit 0 ;;
    run) {$runBody} ;;
    *) exit 0 ;;
esac
SH);
        chmod($fake, 0755);
        touch($log);

        putenv('PATH=' . $this->workDir . PATH_SEPARATOR . getenv('PATH'));
        # Fresh process (RunInSeparateProcess), so bootstrap.php has not defined these yet
        define('WKHTMLTOPDF_PATH', '');
        define('WKHTMLTOPDF_DOCKER_IMAGE', self::IMAGE);

        return $log;
    }

    /**
     * @return list<list<string>> One argument list per logged call
     */
    private function calls(string $log): array
    {
        $lines = array_filter(explode("\n", (string) file_get_contents($log)), fn($l) => $l !== '');

        return array_values(array_map(fn($l) => explode("\t", $l), $lines));
    }

    /**
     * @param list<list<string>> $calls
     * @return list<string>|null The first call with this docker subcommand
     */
    private function findCall(array $calls, string $subcommand): ?array
    {
        foreach ($calls as $call) {
            if (($call[0] ?? null) === $subcommand) {
                return $call;
            }
        }

        return null;
    }

    private function valueAfter(array $command, string $flag): ?string
    {
        $i = array_search($flag, $command, true);

        return $i === false ? null : ($command[$i + 1] ?? null);
    }
}
