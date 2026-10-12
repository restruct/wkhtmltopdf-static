<?php

namespace Restruct\WkhtmltoPdf\Tests;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Restruct\WkhtmltoPdf\WkhtmltoPdf;
use Restruct\WkhtmltoPdf\WkhtmltoPdfStatic;

/**
 * Read-only bind mounts of the `--allow` directories under Docker (from 1.2.0).
 *
 * Before, the container only saw the temp dir, so a directory allowed with allowPath() outside it
 * was allowed by wkhtmltopdf but missing in the container. No Docker daemon is needed: the mount
 * list and the command are pure functions of the arguments and the filesystem, and the run test
 * uses a fake `docker` that logs its calls.
 */
class DockerMountTest extends TestCase
{
    private const IMAGE = 'ghcr.io/restruct/wkhtmltopdf:0.12.6@sha256:3b95bf589c125dbcbbb0e33619d1241953d6da019d80272d85858816d01c8396';

    /** Scratch directory in the temp dir, for links and the fake docker, removed in tearDown() */
    private string $workDir;

    protected function setUp(): void
    {
        $this->workDir = sys_get_temp_dir() . '/wkhtmltopdf-mount-test-' . bin2hex(random_bytes(6));
        mkdir($this->workDir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->workDir . '/*') ?: [] as $file) {
            # Links first: is_dir() follows them, unlink() removes the link itself
            is_link($file) || !is_dir($file) ? unlink($file) : rmdir($file);
        }
        rmdir($this->workDir);
    }

    /**
     * A real directory outside the temp dir that exists on every machine running the suite.
     */
    private function assetDir(): string
    {
        return __DIR__ . '/Visual/fixture';
    }

    public function testCommandAddsReadOnlyMountsAfterTheTempDir(): void
    {
        $command = WkhtmltoPdfStatic::buildDockerCommand(
            self::IMAGE,
            ['--allow', '/srv/assets', 'in.html', 'out.pdf'],
            'n',
            '1:1',
            '',
            '/tmp',
            ['/srv/assets' => '/srv/releases/7/assets', '/srv/fonts' => '/srv/fonts']
        );

        $this->assertSame(
            [
                'docker', 'run', '--rm', '--init', '--name', 'n', '--user', '1:1',
                '-v', '/tmp:/tmp',
                '-v', '/srv/releases/7/assets:/srv/assets:ro',
                '-v', '/srv/fonts:/srv/fonts:ro',
                self::IMAGE, '--allow', '/srv/assets', 'in.html', 'out.pdf',
            ],
            $command
        );
    }

    public function testAllowedDirectoryIsMountedAtTheSamePathInBothSpellings(): void
    {
        $dir = $this->assetDir();
        $real = realpath($dir);

        $this->assertSame(
            [$dir => $real],
            WkhtmltoPdfStatic::dockerAllowMounts(['--allow', $dir, 'in.html', 'out.pdf'], '/nonexistent-temp')
        );
        $this->assertSame(
            [$dir => $real],
            WkhtmltoPdfStatic::dockerAllowMounts(['--allow=' . $dir . '/', 'in.html', 'out.pdf'], '/nonexistent-temp'),
            'The = spelling and a trailing slash give the same mount'
        );
        # Control: no --allow, no mounts
        $this->assertSame([], WkhtmltoPdfStatic::dockerAllowMounts(['--enable-local-file-access', 'in.html', 'out.pdf'], '/nonexistent-temp'));
    }

    public function testDuplicatePathsGiveOneMount(): void
    {
        $dir = $this->assetDir();

        $this->assertCount(1, WkhtmltoPdfStatic::dockerAllowMounts(['--allow', $dir, '--allow', $dir . '/'], '/nonexistent-temp'));
    }

    public function testPathsInsideTheTempDirAreNotMountedAgain(): void
    {
        # The temp dir is mounted read-write already; a second mount on top would be pointless
        $this->assertSame([], WkhtmltoPdfStatic::dockerAllowMounts(['--allow', $this->workDir], sys_get_temp_dir()));
    }

    public function testLinkInTempDirPointingOutIsRefused(): void
    {
        $link = $this->workDir . '/assets';
        symlink($this->assetDir(), $link);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('is a link in the temp dir');
        WkhtmltoPdfStatic::dockerAllowMounts(['--allow', $link], sys_get_temp_dir());
    }

    /**
     * Each refused path, with the part of the message that names why.
     */
    public function testUnsafeOrUnusablePathsAreRefused(): void
    {
        $file = $this->assetDir() . '/fixture.css';
        $etcLink = $this->workDir . '/harmless';
        symlink('/etc', $etcLink);

        $cases = [
            'relative' => ['assets/img', 'not an absolute path'],
            'empty' => ['', 'not an absolute path'],
            'colon' => ['/srv/a:b', 'colon'],
            'comma' => ['/srv/a,b', 'comma'],
            'dotdot' => [$this->assetDir() . '/../Visual/fixture', '. or .. segments'],
            'missing' => ['/srv/does-not-exist-' . bin2hex(random_bytes(4)), 'not an existing directory'],
            'file' => [$file, 'not an existing directory'],
            'root' => ['/', 'top-level'],
            'top-level' => ['/usr', 'top-level'],
            'etc subdir' => ['/etc/ssl', '/etc'],
            'link to etc' => [$etcLink, 'etc'],
        ];
        # A home directory as a whole, where one exists to test with
        $home = getenv('HOME');
        if (is_string($home) && preg_match('#^/(home|Users)/[^/]+$#', $home) && is_dir($home)) {
            $cases['home'] = [$home, 'whole home directory'];
        }

        foreach ($cases as $label => [$path, $why]) {
            try {
                WkhtmltoPdfStatic::dockerMountPath($path);
                $this->fail("Accepted the {$label} path '{$path}'");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString($why, $e->getMessage(), $label);
            }
        }
    }

    /**
     * The command that actually runs carries the mount; a refused path throws before any
     * container starts.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testRunMountsAllowedDirectoryReadOnly(): void
    {
        define('WKHTMLTOPDF_LOCAL_FILE_ACCESS', 'allowlist');
        $log = $this->installFakeDocker();
        $dir = $this->assetDir();

        WkhtmltoPdf::create()->allowPath($dir)->generateFromHtml('<p>x</p>', $this->workDir . '/out.pdf');

        $run = $this->runCall($log);
        $this->assertNotNull($run, 'docker run was called');
        $this->assertContains(realpath($dir) . ':' . $dir . ':ro', $run);

        # A refused path: exception, and no second docker run
        try {
            WkhtmltoPdf::create()->allowPath('/etc')->generateFromHtml('<p>x</p>', $this->workDir . '/out.pdf');
            $this->fail('/etc was mounted');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('/etc', $e->getMessage());
        }
        $runs = array_filter(file($log, FILE_IGNORE_NEW_LINES), fn($l) => str_starts_with($l, "run\t"));
        $this->assertCount(1, $runs);
    }

    /**
     * Fake `docker` first on PATH: `info` succeeds, every call is logged one argument per tab.
     */
    private function installFakeDocker(): string
    {
        $log = $this->workDir . '/calls.log';
        $fake = $this->workDir . '/docker';
        file_put_contents($fake, <<<SH
#!/bin/sh
( IFS="$(printf '\\t')"; echo "\$*" ) >> '{$log}'
exit 0
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
