<?php

namespace Restruct\WkhtmltoPdf\Tests;

use PHPUnit\Framework\TestCase;
use Restruct\WkhtmltoPdf\WkhtmltoPdf;
use Restruct\WkhtmltoPdf\WkhtmltoPdfStatic;

/**
 * Temp-file hygiene (issue #1) and output-directory creation (issue #2).
 *
 * Render tests need a working wkhtmltopdf (bundled binary or Docker image) and are skipped without one.
 */
class WkhtmltoPdfTest extends TestCase
{
    private const HTML = '<html><body><h1>Test</h1></body></html>';

    /** Scratch directory for this test's own files, removed in tearDown() */
    private string $workDir;

    protected function setUp(): void
    {
        $this->workDir = sys_get_temp_dir() . '/wkhtmltopdf-static-test-' . bin2hex(random_bytes(6));
        mkdir($this->workDir);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->workDir);
    }

    public function testGetOutputFromHtmlLeavesNoTempFiles(): void
    {
        $this->requireRenderer();
        $before = $this->tempFiles();

        $pdf = WkhtmltoPdf::create()->getOutputFromHtml(self::HTML);

        $this->assertStringStartsWith('%PDF', $pdf);
        # Before the fix the bare tempnam() files (wkhtml_XXXX, wkpdf_XXXX) were left behind, empty
        $this->assertSame([], array_values(array_diff($this->tempFiles(), $before)));
    }

    public function testGenerateFromHtmlLeavesNoTempFiles(): void
    {
        $this->requireRenderer();
        $before = $this->tempFiles();

        $result = WkhtmltoPdf::create()->generateFromHtml(self::HTML, $this->workDir . '/out.pdf');

        $this->assertTrue($result->isSuccessful(), $result->errorOutput);
        $this->assertSame([], array_values(array_diff($this->tempFiles(), $before)));
    }

    public function testGetOutputFromUrlLeavesNoTempFiles(): void
    {
        $this->requireRenderer();
        $page = $this->workDir . '/page.html';
        file_put_contents($page, self::HTML);
        $before = $this->tempFiles();

        # wkhtmltopdf accepts a local file path wherever it takes a URL
        $pdf = WkhtmltoPdf::create()->getOutputFromUrl($page);

        $this->assertStringStartsWith('%PDF', $pdf);
        $this->assertSame([], array_values(array_diff($this->tempFiles(), $before)));
    }

    public function testGenerateFromHtmlCreatesMissingOutputDirectory(): void
    {
        $this->requireRenderer();
        $outputFile = $this->workDir . '/missing/nested/out.pdf';

        $result = WkhtmltoPdf::create()->generateFromHtml(self::HTML, $outputFile);

        # Before the fix: exit 1, "Unable to write to destination"
        $this->assertTrue($result->isSuccessful(), $result->errorOutput);
        $this->assertFileExists($outputFile);
    }

    public function testGenerateFromUrlCreatesMissingOutputDirectory(): void
    {
        $this->requireRenderer();
        $page = $this->workDir . '/page.html';
        file_put_contents($page, self::HTML);
        $outputFile = $this->workDir . '/missing/out.pdf';

        $result = WkhtmltoPdf::create()->generateFromUrl($page, $outputFile);

        $this->assertTrue($result->isSuccessful(), $result->errorOutput);
        $this->assertFileExists($outputFile);
    }

    public function testUncreatableOutputDirectoryThrows(): void
    {
        # A regular file where the directory should go: mkdir() must fail. No renderer needed,
        # because the directory is checked before wkhtmltopdf runs.
        $blocker = $this->workDir . '/blocker';
        touch($blocker);
        $before = $this->tempFiles();

        # Caught by hand rather than expectException(), so the temp-file check below still runs.
        # $this->fail() cannot sit inside the try: PHPUnit's failure is itself a RuntimeException.
        $thrown = null;
        try {
            WkhtmltoPdf::create()->generateFromHtml(self::HTML, $blocker . '/out.pdf');
        } catch (\RuntimeException $e) {
            $thrown = $e;
        }

        $this->assertNotNull($thrown, 'Expected a RuntimeException for an output directory that cannot be created');
        $this->assertStringContainsString('Could not create output directory', $thrown->getMessage());

        # The check runs before the HTML temp file is written, so nothing is left behind either
        $this->assertSame([], array_values(array_diff($this->tempFiles(), $before)));
    }

    private function requireRenderer(): void
    {
        if (!WkhtmltoPdfStatic::isAvailable()) {
            $this->markTestSkipped('wkhtmltopdf is not available (no binary or Docker image)');
        }
    }

    /**
     * This package's temp files in sys_get_temp_dir(), by name. Compared as a before/after set
     * difference, so files other processes own do not count.
     *
     * @return string[]
     */
    private function tempFiles(): array
    {
        $dir = sys_get_temp_dir();

        return array_merge(glob($dir . '/wkhtml_*') ?: [], glob($dir . '/wkpdf_*') ?: []);
    }

    private function removeTree(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            foreach (scandir($path) as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    $this->removeTree($path . '/' . $entry);
                }
            }
            rmdir($path);
        } elseif (file_exists($path) || is_link($path)) {
            unlink($path);
        }
    }
}
