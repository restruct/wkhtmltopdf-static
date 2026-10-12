<?php

namespace Restruct\WkhtmltoPdf\Tests;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Restruct\WkhtmltoPdf\WkhtmltoPdf;
use Restruct\WkhtmltoPdf\WkhtmltoPdfStatic;

/**
 * The local file access policy (WKHTMLTOPDF_LOCAL_FILE_ACCESS, from 1.2.0).
 *
 * The policy function takes the mode as a parameter, so most tests need no process isolation.
 */
class LocalFileAccessTest extends TestCase
{
    private const ARGS = ['--margin-top', '0px', '/tmp/in.html', '/tmp/out.pdf'];

    public function testPermissiveLeavesArgumentsUnchanged(): void
    {
        $args = ['--enable-local-file-access', '--allow', '/srv/img', '/tmp/in.html', '/tmp/out.pdf'];

        $this->assertSame($args, WkhtmltoPdfStatic::applyLocalFileAccessPolicy($args, 'permissive'));
    }

    public function testStrictModesPutDisableFlagFirst(): void
    {
        foreach (['allowlist', 'deny'] as $mode) {
            $this->assertSame(
                array_merge(['--disable-local-file-access'], self::ARGS),
                WkhtmltoPdfStatic::applyLocalFileAccessPolicy(self::ARGS, $mode),
                $mode
            );
        }
    }

    public function testAllowlistRefusesEnableButAcceptsAllow(): void
    {
        $withAllow = ['--allow', '/srv/img', '/tmp/in.html', '/tmp/out.pdf'];
        $this->assertSame(
            array_merge(['--disable-local-file-access'], $withAllow),
            WkhtmltoPdfStatic::applyLocalFileAccessPolicy($withAllow, 'allowlist')
        );

        $this->expectException(\InvalidArgumentException::class);
        WkhtmltoPdfStatic::applyLocalFileAccessPolicy(['--enable-local-file-access', '/tmp/in.html', '/tmp/out.pdf'], 'allowlist');
    }

    public function testDenyRefusesEnable(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        WkhtmltoPdfStatic::applyLocalFileAccessPolicy(['--enable-local-file-access', '/tmp/in.html', '/tmp/out.pdf'], 'deny');
    }

    public function testDenyRefusesAllowInBothSpellings(): void
    {
        foreach ([['--allow', '/srv/img'], ['--allow=/srv/img']] as $allow) {
            try {
                WkhtmltoPdfStatic::applyLocalFileAccessPolicy(array_merge($allow, ['/tmp/in.html', '/tmp/out.pdf']), 'deny');
                $this->fail('deny accepted ' . implode(' ', $allow));
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('--allow', $e->getMessage());
            }
        }
    }

    public function testUnknownModeFailsLoudly(): void
    {
        # A typo must not quietly mean permissive
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Invalid WKHTMLTOPDF_LOCAL_FILE_ACCESS 'denny'");
        WkhtmltoPdfStatic::applyLocalFileAccessPolicy(self::ARGS, 'denny');
    }

    /**
     * The default when nothing is configured is permissive in 1.x, so existing callers that pass
     * enable-local-file-access keep working.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testDefaultModeIsPermissive(): void
    {
        putenv('WKHTMLTOPDF_LOCAL_FILE_ACCESS');

        $this->assertSame('permissive', WkhtmltoPdfStatic::getLocalFileAccessMode());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testModeIsReadFromEnvironment(): void
    {
        putenv('WKHTMLTOPDF_LOCAL_FILE_ACCESS=deny');

        $this->assertSame('deny', WkhtmltoPdfStatic::getLocalFileAccessMode());
    }

    /**
     * The fluent wrapper goes through run(), so the policy applies to it before any process starts.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testFluentWrapperIsRefusedUnderDeny(): void
    {
        define('WKHTMLTOPDF_LOCAL_FILE_ACCESS', 'deny');
        # Some strategy must resolve, else run() throws "not available" before the policy is checked
        $fake = sys_get_temp_dir() . '/wkhtmltopdf-lfa-fake-' . bin2hex(random_bytes(6));
        file_put_contents($fake, "#!/bin/sh\nexit 0\n");
        chmod($fake, 0755);
        define('WKHTMLTOPDF_PATH', $fake);

        try {
            $this->expectException(\InvalidArgumentException::class);
            WkhtmltoPdf::create()->enableLocalFileAccess()->getOutputFromHtml('<p>x</p>');
        } finally {
            unlink($fake);
        }
    }

    /**
     * allowPath() repeats --allow, one per path (the options array could hold only one).
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAllowPathRepeatsFlag(): void
    {
        define('WKHTMLTOPDF_LOCAL_FILE_ACCESS', 'allowlist');
        # A fake binary that records its arguments, one per line
        $dir = sys_get_temp_dir() . '/wkhtmltopdf-lfa-' . bin2hex(random_bytes(6));
        mkdir($dir);
        $log = $dir . '/args.log';
        $fake = $dir . '/wkhtmltopdf';
        file_put_contents($fake, "#!/bin/sh\nfor a in \"\$@\"; do echo \"\$a\" >> '{$log}'; done\nexit 0\n");
        chmod($fake, 0755);
        define('WKHTMLTOPDF_PATH', $fake);

        try {
            WkhtmltoPdf::create()->allowPath('/srv/a')->allowPath('/srv/b')->generateFromHtml('<p>x</p>', $dir . '/out.pdf');
            # Line 1 is the probe's --version, the render's arguments follow
            $args = array_slice(file($log, FILE_IGNORE_NEW_LINES), 1);

            $this->assertSame(['--disable-local-file-access', '--allow', '/srv/a', '--allow', '/srv/b'], array_slice($args, 0, 5));
        } finally {
            foreach (glob($dir . '/*') ?: [] as $f) {
                unlink($f);
            }
            rmdir($dir);
        }
    }
}
