<?php

namespace Restruct\WkhtmltoPdf;

/**
 * Fluent HTML→PDF wrapper for wkhtmltopdf.
 *
 * Drop-in replacement for knplabs/knp-snappy with a fluent API.
 *
 * Usage:
 *   $pdf = WkhtmltoPdf::create()
 *       ->marginTop('0px')
 *       ->marginBottom('0px')
 *       ->enableLocalFileAccess()
 *       ->disableSmartShrinking()
 *       ->generateFromHtml($html, '/path/to/output.pdf');
 *
 * The API mirrors wkhtmltopdf CLI flags. Options can also be set
 * generically via setOption() / setOptions().
 */
class WkhtmltoPdf
{
    /** @var array<string, string|bool|int|float> Accumulated options */
    private array $options = [];

    /** @var string[] Paths for repeated `--allow <path>` flags, see allowPath() */
    private array $allowPaths = [];

    /** @var int|null Custom timeout in seconds */
    private ?int $timeout = null;

    public function __construct()
    {
    }

    /**
     * Create a new instance (fluent entry point).
     */
    public static function create(): self
    {
        return new self();
    }

    # --- Margin options ---

    public function marginTop(string $margin): self
    {
        $this->options['margin-top'] = $margin;

        return $this;
    }

    public function marginBottom(string $margin): self
    {
        $this->options['margin-bottom'] = $margin;

        return $this;
    }

    public function marginLeft(string $margin): self
    {
        $this->options['margin-left'] = $margin;

        return $this;
    }

    public function marginRight(string $margin): self
    {
        $this->options['margin-right'] = $margin;

        return $this;
    }

    /**
     * Set all margins at once.
     */
    public function margins(string $top, string $right, string $bottom, string $left): self
    {
        $this->options['margin-top'] = $top;
        $this->options['margin-right'] = $right;
        $this->options['margin-bottom'] = $bottom;
        $this->options['margin-left'] = $left;

        return $this;
    }

    # --- Page options ---

    /**
     * Page orientation: 'Portrait' or 'Landscape'.
     */
    public function orientation(string $orientation): self
    {
        $this->options['orientation'] = $orientation;

        return $this;
    }

    /**
     * Page size name (e.g. 'A4', 'Letter').
     */
    public function pageSize(string $size): self
    {
        $this->options['page-size'] = $size;

        return $this;
    }

    /**
     * Custom page width (e.g. '210mm').
     */
    public function pageWidth(string $width): self
    {
        $this->options['page-width'] = $width;

        return $this;
    }

    /**
     * Custom page height (e.g. '297mm').
     */
    public function pageHeight(string $height): self
    {
        $this->options['page-height'] = $height;

        return $this;
    }

    # --- Rendering ---

    /**
     * Allow wkhtmltopdf to access local files (CSS, images).
     * Required when HTML references local resources.
     *
     * This opens the whole filesystem to the HTML, including file:// URLs in content you did
     * not write. Prefer allowPath() for the directories you need. Refused (exception at render
     * time) when WKHTMLTOPDF_LOCAL_FILE_ACCESS is 'allowlist' or 'deny'.
     */
    public function enableLocalFileAccess(): self
    {
        $this->options['enable-local-file-access'] = true;

        return $this;
    }

    /**
     * Let the HTML load local files from this file or directory only (`--allow <path>`).
     *
     * Repeatable, one call per path. wkhtmltopdf 0.12.6 blocks other local files by default,
     * and a blocked one makes the render exit 1. Refused when WKHTMLTOPDF_LOCAL_FILE_ACCESS is 'deny'.
     */
    public function allowPath(string $path): self
    {
        $this->allowPaths[] = $path;

        return $this;
    }

    /**
     * Disable smart shrinking (fixes 0.12.4→0.12.6 scaling issues).
     */
    public function disableSmartShrinking(): self
    {
        $this->options['disable-smart-shrinking'] = true;

        return $this;
    }

    /**
     * Set zoom factor (default 1.0).
     */
    public function zoom(float $factor): self
    {
        $this->options['zoom'] = $factor;

        return $this;
    }

    /**
     * Set DPI for rendering.
     */
    public function dpi(int $dpi): self
    {
        $this->options['dpi'] = $dpi;

        return $this;
    }

    # --- Authentication ---

    /**
     * HTTP Basic Auth username.
     */
    public function username(string $username): self
    {
        $this->options['username'] = $username;

        return $this;
    }

    /**
     * HTTP Basic Auth password.
     */
    public function password(string $password): self
    {
        $this->options['password'] = $password;

        return $this;
    }

    # --- Quality ---

    /**
     * Generate a lower quality PDF (smaller file size).
     */
    public function lowQuality(): self
    {
        $this->options['lowquality'] = true;

        return $this;
    }

    /**
     * Set maximum DPI for images in output.
     */
    public function imageDpi(int $dpi): self
    {
        $this->options['image-dpi'] = $dpi;

        return $this;
    }

    /**
     * Set image compression quality (0-100).
     */
    public function imageQuality(int $quality): self
    {
        $this->options['image-quality'] = $quality;

        return $this;
    }

    # --- Headers/Footers ---

    public function headerHtml(string $url): self
    {
        $this->options['header-html'] = $url;

        return $this;
    }

    public function footerHtml(string $url): self
    {
        $this->options['footer-html'] = $url;

        return $this;
    }

    public function headerLeft(string $text): self
    {
        $this->options['header-left'] = $text;

        return $this;
    }

    public function headerCenter(string $text): self
    {
        $this->options['header-center'] = $text;

        return $this;
    }

    public function headerRight(string $text): self
    {
        $this->options['header-right'] = $text;

        return $this;
    }

    public function footerLeft(string $text): self
    {
        $this->options['footer-left'] = $text;

        return $this;
    }

    public function footerCenter(string $text): self
    {
        $this->options['footer-center'] = $text;

        return $this;
    }

    public function footerRight(string $text): self
    {
        $this->options['footer-right'] = $text;

        return $this;
    }

    public function headerSpacing(float $mm): self
    {
        $this->options['header-spacing'] = $mm;

        return $this;
    }

    public function footerSpacing(float $mm): self
    {
        $this->options['footer-spacing'] = $mm;

        return $this;
    }

    # --- JavaScript ---

    /**
     * Wait N milliseconds for JavaScript to finish.
     */
    public function javascriptDelay(int $ms): self
    {
        $this->options['javascript-delay'] = $ms;

        return $this;
    }

    /**
     * Disable JavaScript execution.
     */
    public function disableJavascript(): self
    {
        $this->options['disable-javascript'] = true;

        return $this;
    }

    # --- Generic option setters ---

    /**
     * Set any wkhtmltopdf option by name.
     *
     * @param string $name Option name without leading dashes (e.g. 'page-size')
     * @param string|bool|int|float $value Option value (true for boolean flags)
     */
    public function setOption(string $name, string|bool|int|float $value): self
    {
        $this->options[$name] = $value;

        return $this;
    }

    /**
     * Set multiple options at once.
     *
     * @param array<string, string|bool|int|float> $options
     */
    public function setOptions(array $options): self
    {
        foreach ($options as $name => $value) {
            $this->options[$name] = $value;
        }

        return $this;
    }

    # --- Timeout ---

    /**
     * Set the execution timeout.
     */
    public function timeout(int $seconds): self
    {
        $this->timeout = $seconds;

        return $this;
    }

    # --- Execution ---

    /**
     * Generate a PDF from an HTML string and save to a file.
     *
     * @param string $html HTML content
     * @param string $outputFile Path to write the PDF to
     * @return WkhtmltoPdfResult
     */
    public function generateFromHtml(string $html, string $outputFile): WkhtmltoPdfResult
    {
        # Before the temp file is written, so a failure here leaves nothing behind
        $this->ensureOutputDirectory($outputFile);
        $tmpFile = $this->writeHtmlToTempFile($html);

        try {
            $args = $this->buildArgs();
            $args[] = $tmpFile;
            $args[] = $outputFile;

            return WkhtmltoPdfStatic::run($args, $this->timeout);
        } finally {
            $this->removeTempFile($tmpFile, '.html');
        }
    }

    /**
     * Generate a PDF from a URL and save to a file.
     *
     * @param string $url URL to render
     * @param string $outputFile Path to write the PDF to
     * @return WkhtmltoPdfResult
     */
    public function generateFromUrl(string $url, string $outputFile): WkhtmltoPdfResult
    {
        $this->ensureOutputDirectory($outputFile);

        $args = $this->buildArgs();
        $args[] = $url;
        $args[] = $outputFile;

        return WkhtmltoPdfStatic::run($args, $this->timeout);
    }

    /**
     * Generate a PDF from an HTML string and return raw PDF bytes.
     *
     * @param string $html HTML content
     * @return string Raw PDF content
     * @throws \RuntimeException if generation fails
     */
    public function getOutputFromHtml(string $html): string
    {
        # Created inside the try, so whichever already exists is removed when the other one fails
        $tmpFile = null;
        $tmpOutput = null;

        try {
            $tmpFile = $this->writeHtmlToTempFile($html);
            $tmpOutput = $this->createTempPath('wkpdf_', '.pdf');

            $args = $this->buildArgs();
            $args[] = $tmpFile;
            $args[] = $tmpOutput;

            $result = WkhtmltoPdfStatic::run($args, $this->timeout);

            if (!$result->isSuccessful() || !file_exists($tmpOutput)) {
                throw new \RuntimeException(
                    "wkhtmltopdf failed (exit {$result->exitCode}): {$result->errorOutput}"
                );
            }

            return file_get_contents($tmpOutput);
        } finally {
            if ($tmpFile !== null) {
                $this->removeTempFile($tmpFile, '.html');
            }
            if ($tmpOutput !== null) {
                $this->removeTempFile($tmpOutput, '.pdf');
            }
        }
    }

    /**
     * Generate a PDF from a URL and return raw PDF bytes.
     *
     * @param string $url URL to render
     * @return string Raw PDF content
     * @throws \RuntimeException if generation fails
     */
    public function getOutputFromUrl(string $url): string
    {
        $tmpOutput = $this->createTempPath('wkpdf_', '.pdf');

        try {
            $args = $this->buildArgs();
            $args[] = $url;
            $args[] = $tmpOutput;

            $result = WkhtmltoPdfStatic::run($args, $this->timeout);

            if (!$result->isSuccessful() || !file_exists($tmpOutput)) {
                throw new \RuntimeException(
                    "wkhtmltopdf failed (exit {$result->exitCode}): {$result->errorOutput}"
                );
            }

            return file_get_contents($tmpOutput);
        } finally {
            $this->removeTempFile($tmpOutput, '.pdf');
        }
    }

    # --- Internal ---

    /**
     * Build the wkhtmltopdf CLI arguments from accumulated options.
     *
     * @return string[]
     */
    private function buildArgs(): array
    {
        $args = [];

        foreach ($this->options as $name => $value) {
            if ($value === true) {
                # Boolean flag: --flag-name
                $args[] = '--' . $name;
            } elseif ($value !== false) {
                # Key-value: --option-name value
                $args[] = '--' . $name;
                $args[] = (string) $value;
            }
        }

        # A separate list, because the options array holds one value per name and --allow repeats
        foreach ($this->allowPaths as $path) {
            $args[] = '--allow';
            $args[] = $path;
        }

        return $args;
    }

    /**
     * Write HTML to a temp file with .html extension.
     *
     * The .html extension is required for wkhtmltopdf to render correctly.
     * Files are written to sys_get_temp_dir() which is volume-mounted for Docker.
     */
    private function writeHtmlToTempFile(string $html): string
    {
        $tmpFile = $this->createTempPath('wkhtml_', '.html');
        file_put_contents($tmpFile, $html);

        return $tmpFile;
    }

    /**
     * Reserve a unique temp path that ends in $suffix.
     *
     * tempnam() cannot add a suffix, and wkhtmltopdf needs one (.html input, .pdf output).
     * tempnam() also CREATES the bare file it names. That empty file is kept as the
     * reservation, so no other tempnam() call can hand out the same base and with it the
     * same suffixed path, and removeTempFile() deletes both. Before 1.0.3 only the
     * suffixed file was deleted, and every render leaked one empty file per temp path.
     *
     * @throws \RuntimeException if no temp file can be created
     */
    private function createTempPath(string $prefix, string $suffix): string
    {
        $base = tempnam(sys_get_temp_dir(), $prefix);
        if ($base === false) {
            throw new \RuntimeException('Could not create a temp file in ' . sys_get_temp_dir());
        }

        return $base . $suffix;
    }

    /**
     * Delete a path from createTempPath() together with its bare tempnam() reservation.
     */
    private function removeTempFile(string $path, string $suffix): void
    {
        @unlink($path);

        # The reservation is the same path without the suffix createTempPath() appended
        if (str_ends_with($path, $suffix)) {
            @unlink(substr($path, 0, -strlen($suffix)));
        }
    }

    /**
     * Create the output file's directory if it does not exist yet.
     *
     * wkhtmltopdf does not create it: it exits 1 with "Unable to write to destination".
     * knp-snappy, which this class replaces, created it before rendering, so callers that
     * migrated from Snappy rely on that.
     *
     * @throws \RuntimeException if the directory cannot be created
     */
    private function ensureOutputDirectory(string $outputFile): void
    {
        $dir = dirname($outputFile);
        if (is_dir($dir)) {
            return;
        }

        # Re-check is_dir() after a failed mkdir(): a parallel render may have created it meanwhile
        if (!@mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new \RuntimeException("Could not create output directory: $dir");
        }
    }
}
