<?php

namespace Restruct\WkhtmltoPdf\Tests\Visual;

use Restruct\WkhtmltoPdf\WkhtmltoPdf;
use Restruct\WkhtmltoPdf\WkhtmltoPdfStatic;
use Restruct\Xpdf\Xpdf;
use Symfony\Component\Process\Process;

/**
 * Visual comparison check: renders a fixed fixture with wkhtmltopdf and compares the result,
 * so a change of wkhtmltopdf version, strategy (bundled binary, native install, Docker) or host
 * fonts that changes the output is caught before it reaches real documents.
 *
 * Two kinds of evidence per render:
 * - geometry: page count and every page's size in points, read with pdfinfo, compared exactly;
 * - pixels: every page rasterised with pdftopng at DPI and compared with GD against another
 *   render (a committed baseline, or a second strategy), with a per-pixel threshold and a
 *   tolerance in percent of differing pixels.
 *
 * Used by VisualRegressionTest (PHPUnit group "visual") and by visual-check.php (CLI).
 * Needs restruct/xpdf-static (require-dev) and ext-gd.
 */
final class VisualCheck
{
    /** Rasterisation resolution. 1 mm is about 3.9 px here, so a 1 mm shift moves whole pixels. */
    public const DPI = 100;

    /**
     * A pixel counts as different when one RGB channel differs by more than this (0-255). Above
     * zero so an anti-aliasing step on a glyph edge does not count, low enough that any real
     * stroke or fill change (black on white is 255) does.
     */
    public const PIXEL_THRESHOLD = 64;

    /**
     * A page fails when more than this share of its pixels (in percent) differs. Same-binary
     * renders on one host have 0 differing pixels; 0.1 % of an A4 page at 100 dpi is about
     * 970 px, a few glyphs' worth, so a single changed glyph passes and a shifted line of text,
     * a moved box or a scaled page does not.
     */
    public const TOLERANCE_PERCENT = 0.1;

    /** Seconds allowed for one wkhtmltopdf render */
    private const RENDER_TIMEOUT = 120;

    /**
     * The fixture cases. Each is one HTML file from fixture/ with a set of wkhtmltopdf options
     * (wrapper format: name without dashes => value, true for a flag, false to leave it out),
     * plus the geometry it must have. The option sets are the ones real document pipelines use;
     * see the README section "Visual check" for where each comes from.
     *
     * "{dir}" in an option value is replaced with the render's working directory.
     *
     * @return array<string, array{html: string, options: array<string, string|bool|int|float>, pages: int, size: string, about: string}>
     */
    public static function cases(): array
    {
        # The flags almost every 0.12.6 template needs: local file access for the CSS, font and
        # image next to the HTML (off by default since 0.12.6), and no smart shrinking (on by
        # default, it scales the page and broke mm-exact layouts on the 0.12.4 to 0.12.6 move).
        $base = [
            'enable-local-file-access' => true,
            'disable-smart-shrinking' => true,
        ];
        $noMargins = [
            'margin-top' => '0px',
            'margin-right' => '0px',
            'margin-bottom' => '0px',
            'margin-left' => '0px',
        ];

        return [
            'fullbleed' => [
                'about' => 'two exact A4 page boxes, zero page margins, everything placed in mm',
                'html' => 'cover.html',
                'options' => $noMargins + $base,
                'pages' => 2,
                'size' => '595 x 842',
            ],
            'lowquality' => [
                # knp-snappy passes --lowquality by default, so code migrated from it to this
                # wrapper (which adds no defaults) silently loses it. This case pins what it does.
                'about' => 'fullbleed plus --lowquality, the knp-snappy default',
                'html' => 'cover.html',
                'options' => $noMargins + $base + ['lowquality' => true],
                'pages' => 2,
                'size' => '595 x 842',
            ],
            'flow' => [
                'about' => 'flowing content with 16 mm top and 20 mm bottom page margins, page-break-* CSS',
                'html' => 'flow.html',
                'options' => [
                    'margin-top' => '16mm',
                    'margin-right' => '0mm',
                    'margin-bottom' => '20mm',
                    'margin-left' => '0mm',
                ] + $base,
                'pages' => 3,
                'size' => '595 x 842',
            ],
            'header-footer' => [
                'about' => 'flowing content between --header-html and --footer-html with spacing',
                'html' => 'header-footer.html',
                'options' => [
                    'margin-top' => '25mm',
                    'margin-right' => '0mm',
                    'margin-bottom' => '20mm',
                    'margin-left' => '0mm',
                    'header-html' => '{dir}/header.html',
                    'footer-html' => '{dir}/footer.html',
                    'header-spacing' => 5,
                    'footer-spacing' => 5,
                ] + $base,
                'pages' => 2,
                'size' => '595 x 842',
            ],
        ];
    }

    /**
     * Option changes the check must catch, each applied to the "fullbleed" case. The test suite
     * renders each and asserts the comparison against the fullbleed baseline FAILS: proof that
     * the check is sensitive to the kind of change it exists for, not just green.
     *
     * @return array<string, array<string, string|bool|int|float>>
     */
    public static function mutations(): array
    {
        return [
            # Smart shrinking back on (the 0.12.6 default)
            'smart shrinking on' => ['disable-smart-shrinking' => false],
            # A 2 % scale change, smaller than any version jump has caused
            'zoom 1.02' => ['zoom' => 1.02],
            # A 1 mm offset of the whole page content. In px because wkhtmltopdf refuses mixed
            # margin units ("Currently all margin units must be the same!") and the case uses px.
            'margin-left 4px (about 1 mm)' => ['margin-left' => '4px'],
            # Another paper size: caught by the geometry check alone
            'page-size Letter' => ['page-size' => 'Letter'],
        ];
    }

    # --- Paths ---

    public static function fixtureDir(): string
    {
        return __DIR__ . '/fixture';
    }

    /**
     * @param string|null $root Another baselines root than the committed one (see writeBaselines())
     */
    public static function baselineDir(string $platform, string $case, ?string $root = null): string
    {
        return ($root ?? __DIR__ . '/baselines') . '/' . $platform . '/' . $case;
    }

    /**
     * Where failing renders, their pages and diff images are written. WKHTMLTOPDF_VISUAL_OUT
     * overrides it (CI points it at a directory it uploads).
     */
    public static function outDir(): string
    {
        $env = getenv('WKHTMLTOPDF_VISUAL_OUT');

        return ($env !== false && $env !== '') ? rtrim($env, '/') : __DIR__ . '/out';
    }

    # --- Prerequisites ---

    /**
     * Why the check cannot run here, or null when it can.
     */
    public static function missingPrerequisite(string $strategy): ?string
    {
        if (!extension_loaded('gd')) {
            return 'ext-gd is not loaded (needed for the pixel comparison)';
        }
        if (!class_exists(Xpdf::class) || !Xpdf::isAvailable()) {
            return 'xpdf (restruct/xpdf-static, require-dev) is not available for pdfinfo/pdftopng';
        }

        return self::rendererProblem($strategy);
    }

    /**
     * Why a strategy cannot render here, or null when it can.
     */
    public static function rendererProblem(string $strategy): ?string
    {
        if ($strategy === 'auto') {
            return WkhtmltoPdfStatic::isAvailable() ? null : 'wkhtmltopdf is not available (no binary or Docker image)';
        }
        if (self::isDocker($strategy)) {
            $process = new Process(['docker', 'info']);
            $process->setTimeout(10);
            try {
                $process->run();
            } catch (\Exception $e) {
                return 'docker is not available';
            }

            return $process->isSuccessful() ? null : 'docker is not running';
        }
        $binary = self::binaryFor($strategy);

        return WkhtmltoPdfStatic::probeBinary($binary) ? null : "no runnable wkhtmltopdf at $binary";
    }

    # --- Strategies ---

    /**
     * Human-readable description of what a strategy resolves to.
     *
     * Strategies: "auto" (whatever this package resolves, through the WkhtmltoPdf wrapper, so
     * the wrapper itself is under test too), "bundled" (x64/<os>/wkhtmltopdf), "native"
     * (/usr/local/bin/wkhtmltopdf, the official .deb location), "docker" or "docker:<image>",
     * or an absolute path to any wkhtmltopdf binary.
     */
    public static function describe(string $strategy): string
    {
        if ($strategy === 'auto') {
            $resolved = WkhtmltoPdfStatic::getStrategy();

            return 'auto (' . ($resolved === 'docker' ? 'docker ' . WkhtmltoPdfStatic::getDockerImage() : $resolved . ' ' . WkhtmltoPdfStatic::getPath()) . ')';
        }
        if (self::isDocker($strategy)) {
            return 'docker ' . self::dockerImage($strategy);
        }

        return $strategy . ' (' . self::binaryFor($strategy) . ')';
    }

    /**
     * The platform whose baselines a strategy is compared against: where the rendering actually
     * happens, so Docker on a Mac is "linux". Baselines are per platform because fonts and the
     * macOS and Linux builds of wkhtmltopdf render text differently.
     */
    public static function platformFor(string $strategy): string
    {
        if (self::isDocker($strategy) || ($strategy === 'auto' && WkhtmltoPdfStatic::getStrategy() === 'docker')) {
            return 'linux';
        }

        return PHP_OS_FAMILY === 'Darwin' ? 'darwin' : 'linux';
    }

    private static function isDocker(string $strategy): bool
    {
        return $strategy === 'docker' || str_starts_with($strategy, 'docker:');
    }

    private static function dockerImage(string $strategy): string
    {
        if (str_starts_with($strategy, 'docker:')) {
            return substr($strategy, 7);
        }
        $env = getenv('WKHTMLTOPDF_DOCKER_IMAGE');

        return ($env !== false && $env !== '') ? $env : 'ghcr.io/restruct/wkhtmltopdf:0.12.6';
    }

    private static function binaryFor(string $strategy): string
    {
        return match ($strategy) {
            'bundled' => dirname(__DIR__, 2) . '/x64/' . (PHP_OS_FAMILY === 'Darwin' ? 'mac' : 'linux') . '/wkhtmltopdf',
            'native' => '/usr/local/bin/wkhtmltopdf',
            default => $strategy,
        };
    }

    # --- Rendering ---

    /**
     * Render one case into $workDir and return the PDF path.
     *
     * The fixture is copied into $workDir first, and $workDir must be under sys_get_temp_dir():
     * that is the directory the wrapper's Docker strategy mounts into the container, so all
     * strategies see the same files at the same paths.
     *
     * @param array<string, string|bool|int|float> $overrides Options merged over the case's (false removes one)
     * @throws \RuntimeException when wkhtmltopdf fails or writes no PDF
     */
    public static function render(string $strategy, string $case, string $workDir, array $overrides = []): string
    {
        $def = self::cases()[$case] ?? throw new \InvalidArgumentException("Unknown case: $case");
        if (!is_dir($workDir) && !mkdir($workDir, 0777, true) && !is_dir($workDir)) {
            throw new \RuntimeException("Could not create $workDir");
        }
        foreach (glob(self::fixtureDir() . '/*') ?: [] as $file) {
            if (is_file($file)) {
                copy($file, $workDir . '/' . basename($file));
            }
        }

        $options = array_merge($def['options'], $overrides);
        foreach ($options as $name => $value) {
            if (is_string($value)) {
                $options[$name] = str_replace('{dir}', $workDir, $value);
            }
        }
        $input = $workDir . '/' . $def['html'];
        $pdf = $workDir . '/' . $case . '.pdf';

        if ($strategy === 'auto') {
            # Through the public wrapper API; a local path works where a URL is expected
            $result = WkhtmltoPdf::create()->setOptions($options)->timeout(self::RENDER_TIMEOUT)->generateFromUrl($input, $pdf);
            $exit = $result->exitCode;
            $stderr = $result->errorOutput;
        } else {
            $prefix = self::isDocker($strategy)
                ? ['docker', 'run', '--rm', '-v', "$workDir:$workDir", self::dockerImage($strategy)]
                : [self::binaryFor($strategy)];
            $process = new Process(array_merge($prefix, self::buildArgs($options), [$input, $pdf]));
            $process->setTimeout(self::RENDER_TIMEOUT);
            $process->run();
            $exit = $process->getExitCode();
            $stderr = $process->getErrorOutput();
        }

        # wkhtmltopdf writes progress to stderr even on success, so the exit code and the file decide
        if ($exit !== 0 || !is_file($pdf) || filesize($pdf) === 0) {
            throw new \RuntimeException("Render of '$case' with $strategy failed (exit $exit): " . trim($stderr));
        }

        return $pdf;
    }

    /**
     * Same mapping as WkhtmltoPdf::buildArgs(): true is a bare flag, false leaves the option out.
     *
     * @param array<string, string|bool|int|float> $options
     * @return string[]
     */
    private static function buildArgs(array $options): array
    {
        $args = [];
        foreach ($options as $name => $value) {
            if ($value === true) {
                $args[] = '--' . $name;
            } elseif ($value !== false) {
                $args[] = '--' . $name;
                $args[] = (string) $value;
            }
        }

        return $args;
    }

    # --- Geometry ---

    /**
     * Page count and per-page size ("W x H" in points, as pdfinfo prints them) of a PDF.
     *
     * @return array{pages: int, sizes: array<int, string>}
     */
    public static function geometry(string $pdf): array
    {
        $info = Xpdf::run('pdfinfo', [$pdf], 30);
        if (!$info->isSuccessful() || !preg_match('/^Pages:\s+(\d+)/m', $info->output, $m)) {
            throw new \RuntimeException("pdfinfo failed on $pdf: " . trim($info->errorOutput));
        }
        $pages = (int) $m[1];

        # -f/-l make pdfinfo print one "Page N size:" line per page instead of only the first
        $sizes = [];
        $perPage = Xpdf::run('pdfinfo', ['-f', '1', '-l', (string) $pages, $pdf], 30);
        preg_match_all('/^Page\s+(\d+) size:\s+([\d.]+ x [\d.]+) pts/m', $perPage->output, $all, PREG_SET_ORDER);
        foreach ($all as $row) {
            $sizes[(int) $row[1]] = $row[2];
        }

        return ['pages' => $pages, 'sizes' => $sizes];
    }

    /**
     * Differences between a PDF's geometry and a case's expected geometry, empty when it matches.
     *
     * @param array{pages: int, sizes: array<int, string>} $geometry
     * @return string[]
     */
    public static function geometryProblems(array $geometry, int $pages, string $size): array
    {
        $problems = [];
        if ($geometry['pages'] !== $pages) {
            $problems[] = "page count {$geometry['pages']}, expected $pages";
        }
        foreach ($geometry['sizes'] as $page => $actual) {
            if ($actual !== $size) {
                $problems[] = "page $page size $actual pts, expected $size pts";
            }
        }

        return $problems;
    }

    # --- Pixels ---

    /**
     * Rasterise every page of a PDF at DPI. Returns the PNG paths in page order.
     *
     * @return string[]
     */
    public static function rasterise(string $pdf, string $pngRoot): array
    {
        $result = Xpdf::run('pdftopng', ['-r', (string) self::DPI, $pdf, $pngRoot], 120);
        if (!$result->isSuccessful()) {
            throw new \RuntimeException("pdftopng failed on $pdf: " . trim($result->errorOutput));
        }
        $files = glob($pngRoot . '-*.png') ?: [];
        sort($files);

        return $files;
    }

    /**
     * Compare two page images pixel by pixel.
     *
     * Returns the share of differing pixels in percent (100 when the image sizes differ, since
     * then the page itself changed size). When $diffPng is given and anything differs, writes a
     * diff image there: differing pixels red, the rest the expected page washed out to light grey.
     *
     * @return array{percent: float, pixels: int, reason: ?string}
     */
    public static function compareImages(string $expectedPng, string $actualPng, ?string $diffPng = null): array
    {
        $a = imagecreatefrompng($expectedPng);
        $b = imagecreatefrompng($actualPng);
        if ($a === false || $b === false) {
            throw new \RuntimeException("Could not read $expectedPng or $actualPng");
        }
        $w = imagesx($a);
        $h = imagesy($a);
        if ($w !== imagesx($b) || $h !== imagesy($b)) {
            return ['percent' => 100.0, 'pixels' => $w * $h, 'reason' => sprintf('image size %dx%d, expected %dx%d', imagesx($b), imagesy($b), $w, $h)];
        }

        # Palette PNGs would make imagecolorat() return indexes, not RGB
        imagepalettetotruecolor($a);
        imagepalettetotruecolor($b);

        $diff = $diffPng !== null ? imagecreatetruecolor($w, $h) : null;
        $red = $diff !== null ? imagecolorallocate($diff, 255, 0, 0) : 0;
        $count = 0;
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $pa = imagecolorat($a, $x, $y);
                $pb = imagecolorat($b, $x, $y);
                $different = $pa !== $pb && max(
                    abs((($pa >> 16) & 0xFF) - (($pb >> 16) & 0xFF)),
                    abs((($pa >> 8) & 0xFF) - (($pb >> 8) & 0xFF)),
                    abs(($pa & 0xFF) - ($pb & 0xFF))
                ) > self::PIXEL_THRESHOLD;
                if ($different) {
                    $count++;
                }
                if ($diff !== null) {
                    if ($different) {
                        imagesetpixel($diff, $x, $y, $red);
                    } else {
                        # Expected page at a quarter of its contrast, so the red stands out
                        $grey = 191 + intdiv((($pa >> 16) & 0xFF) + (($pa >> 8) & 0xFF) + ($pa & 0xFF), 12);
                        imagesetpixel($diff, $x, $y, ($grey << 16) | ($grey << 8) | $grey);
                    }
                }
            }
        }
        if ($diff !== null && $count > 0) {
            if (!is_dir(dirname($diffPng))) {
                mkdir(dirname($diffPng), 0777, true);
            }
            imagepng($diff, $diffPng);
        }

        return ['percent' => $count * 100 / ($w * $h), 'pixels' => $count, 'reason' => null];
    }

    /**
     * Compare a set of page images with another, page by page.
     *
     * @param string[] $expected PNG paths in page order
     * @param string[] $actual PNG paths in page order
     * @param string|null $diffRoot Diff images are written as "$diffRoot-page-N.diff.png"
     * @return array<int, array{percent: float, pixels: int, reason: ?string, pass: bool, diff: ?string}> keyed by page number
     */
    public static function comparePages(array $expected, array $actual, ?string $diffRoot = null): array
    {
        $report = [];
        $count = max(count($expected), count($actual));
        for ($i = 0; $i < $count; $i++) {
            $page = $i + 1;
            if (!isset($expected[$i], $actual[$i])) {
                $report[$page] = ['percent' => 100.0, 'pixels' => 0, 'reason' => isset($expected[$i]) ? 'page missing' : 'extra page', 'pass' => false, 'diff' => null];
                continue;
            }
            $diffPng = $diffRoot !== null ? "$diffRoot-page-$page.diff.png" : null;
            $r = self::compareImages($expected[$i], $actual[$i], $diffPng);
            $r['pass'] = $r['reason'] === null && $r['percent'] <= self::TOLERANCE_PERCENT;
            $r['diff'] = ($diffPng !== null && is_file($diffPng)) ? $diffPng : null;
            $report[$page] = $r;
        }

        return $report;
    }

    /**
     * Committed baseline PNGs of a case for a platform, in page order (empty if none).
     *
     * @return string[]
     */
    public static function baselinePages(string $platform, string $case): array
    {
        $files = glob(self::baselineDir($platform, $case) . '/page-*.png') ?: [];
        natsort($files);

        return array_values($files);
    }

    /**
     * Replace a case's baselines with the given page images.
     *
     * With $root, writes them in the same layout under another directory instead: a run without
     * baselines proposes its render that way (CI uploads it), so adopting it is one copy into
     * tests/Visual/baselines after looking at it.
     *
     * @param string[] $pages PNG paths in page order
     */
    public static function writeBaselines(string $platform, string $case, array $pages, ?string $root = null): void
    {
        $dir = self::baselineDir($platform, $case, $root);
        if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new \RuntimeException("Could not create $dir");
        }
        foreach (glob($dir . '/page-*.png') ?: [] as $old) {
            unlink($old);
        }
        foreach (array_values($pages) as $i => $png) {
            copy($png, $dir . '/page-' . ($i + 1) . '.png');
        }
    }

    # --- Scratch space ---

    /**
     * A fresh working directory under sys_get_temp_dir() (see render() for why there).
     */
    public static function makeWorkDir(string $label): string
    {
        $dir = sys_get_temp_dir() . '/wkhtmltopdf-visual-' . preg_replace('/[^a-z0-9]+/i', '-', $label) . '-' . bin2hex(random_bytes(4));
        mkdir($dir, 0777, true);

        return $dir;
    }

    public static function removeTree(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            foreach (scandir($path) as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    self::removeTree($path . '/' . $entry);
                }
            }
            rmdir($path);
        } elseif (file_exists($path) || is_link($path)) {
            unlink($path);
        }
    }

    /**
     * Copy a failing render (PDF and page PNGs; diff images are already written there) into
     * outDir()/$label, so it survives the work dir's removal. Returns that directory.
     *
     * @param array<int|string, string> $files Paths; a string key is used as the copy's file name
     */
    public static function keep(string $label, array $files): string
    {
        $dir = self::outDir() . '/' . preg_replace('/[^a-z0-9._-]+/i', '-', $label);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        foreach ($files as $name => $file) {
            if (is_file($file)) {
                copy($file, $dir . '/' . (is_string($name) ? $name : basename($file)));
            }
        }

        return $dir;
    }
}
