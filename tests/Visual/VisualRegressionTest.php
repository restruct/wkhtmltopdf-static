<?php

namespace Restruct\WkhtmltoPdf\Tests\Visual;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Visual regression check (group "visual"): renders the fixture cases and compares them with the
 * committed baselines of this platform. See VisualCheck for what is compared and how.
 *
 * Renders with the strategy in WKHTMLTOPDF_VISUAL_STRATEGY (default "auto": whatever this package
 * resolves, through the wrapper). Skipped when ext-gd, xpdf or the renderer is missing.
 *
 *   vendor/bin/phpunit --group visual
 *   WKHTMLTOPDF_VISUAL_STRATEGY=docker vendor/bin/phpunit --group visual
 */
#[Group('visual')]
class VisualRegressionTest extends TestCase
{
    /**
     * Renders already done in this process, keyed by case, so the geometry and the pixel test of
     * a case share one render.
     *
     * @var array<string, array{pdf: string, pages: string[], work: string}>
     */
    private static array $renders = [];

    public static function tearDownAfterClass(): void
    {
        foreach (self::$renders as $render) {
            VisualCheck::removeTree($render['work']);
        }
        self::$renders = [];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function caseProvider(): array
    {
        $cases = [];
        foreach (array_keys(VisualCheck::cases()) as $case) {
            $cases[$case] = [$case];
        }

        return $cases;
    }

    /**
     * @return array<string, array{array<string, string|bool|int|float>}>
     */
    public static function mutationProvider(): array
    {
        $mutations = [];
        foreach (VisualCheck::mutations() as $label => $overrides) {
            $mutations[$label] = [$overrides];
        }

        return $mutations;
    }

    #[DataProvider('caseProvider')]
    public function testGeometry(string $case): void
    {
        $render = $this->render($case);
        $def = VisualCheck::cases()[$case];

        $geometry = VisualCheck::geometry($render['pdf']);

        # Exact: wkhtmltopdf writes whole points for A4 (595 x 842), and a page count that is off
        # by one is the classic symptom of a box that no longer fits its page
        $this->assertSame($def['pages'], $geometry['pages'], "$case: page count");
        $this->assertSame(array_fill(1, $def['pages'], $def['size']), $geometry['sizes'], "$case: page sizes in pts");
    }

    #[DataProvider('caseProvider')]
    public function testPixelsMatchBaseline(string $case): void
    {
        $render = $this->render($case);
        $platform = VisualCheck::platformFor($this->strategy());
        $baseline = VisualCheck::baselinePages($platform, $case);

        if ($baseline === []) {
            # A failure, not a skip: a platform without baselines would otherwise pass green forever
            $proposed = VisualCheck::outDir() . '/proposed-baselines';
            VisualCheck::writeBaselines($platform, $case, $render['pages'], $proposed);
            $this->fail("No $platform baselines for '$case'. This render is proposed as one in $proposed/$platform/$case; look at it, then copy $proposed/$platform into tests/Visual/baselines/ (or write baselines locally with: php tests/Visual/visual-check.php baseline --strategy=" . $this->strategy() . ')');
        }

        $label = "phpunit-$platform";
        $report = VisualCheck::comparePages($baseline, $render['pages'], VisualCheck::outDir() . "/$label/$case");
        $failures = array_filter($report, static fn(array $r): bool => !$r['pass']);
        if ($failures !== []) {
            VisualCheck::keep($label, array_merge([$render['pdf']], $render['pages']));
        }

        $this->assertSame([], array_map(
            static fn(array $r): string => $r['reason'] ?? sprintf('%.4f %% of pixels differ, diff image: %s', $r['percent'], $r['diff'] ?? '(none)'),
            $failures
        ), "$case vs $platform baseline with " . VisualCheck::describe($this->strategy()) . ' (tolerance ' . VisualCheck::TOLERANCE_PERCENT . ' %)');
    }

    /**
     * Must-fail control, kept as a permanent test: each mutation of the fullbleed options is a
     * change the check exists to catch, so comparing its render with the fullbleed baseline must
     * NOT pass. If one of these ever goes green, the check has gone blind to that kind of change.
     *
     * @param array<string, string|bool|int|float> $overrides
     */
    #[DataProvider('mutationProvider')]
    public function testCheckDetectsMutation(array $overrides): void
    {
        $this->requirePrerequisites();
        $strategy = $this->strategy();
        $platform = VisualCheck::platformFor($strategy);
        $baseline = VisualCheck::baselinePages($platform, 'fullbleed');
        if ($baseline === []) {
            $this->fail("No $platform baselines for 'fullbleed' to test the mutation against");
        }

        $work = VisualCheck::makeWorkDir('mutation');
        try {
            $pdf = VisualCheck::render($strategy, 'fullbleed', $work, $overrides);
            $def = VisualCheck::cases()['fullbleed'];
            $geometryProblems = VisualCheck::geometryProblems(VisualCheck::geometry($pdf), $def['pages'], $def['size']);
            # No diff images: these failures are expected and nobody needs to look at them
            $report = VisualCheck::comparePages($baseline, VisualCheck::rasterise($pdf, "$work/fullbleed"));
            $pixelFailures = array_filter($report, static fn(array $r): bool => !$r['pass']);
        } finally {
            VisualCheck::removeTree($work);
        }

        $worst = max(array_column($report, 'percent'));
        $this->assertTrue(
            $geometryProblems !== [] || $pixelFailures !== [],
            sprintf('The check did not notice %s: geometry matched and the worst page differed by only %.4f %%', json_encode($overrides), $worst)
        );
    }

    /**
     * Render a case once per process with the configured strategy.
     *
     * @return array{pdf: string, pages: string[], work: string}
     */
    private function render(string $case): array
    {
        $this->requirePrerequisites();
        if (!isset(self::$renders[$case])) {
            $work = VisualCheck::makeWorkDir($case);
            $pdf = VisualCheck::render($this->strategy(), $case, $work);
            self::$renders[$case] = [
                'pdf' => $pdf,
                'pages' => VisualCheck::rasterise($pdf, "$work/$case"),
                'work' => $work,
            ];
        }

        return self::$renders[$case];
    }

    private function requirePrerequisites(): void
    {
        $problem = VisualCheck::missingPrerequisite($this->strategy());
        if ($problem !== null) {
            $this->markTestSkipped($problem);
        }
    }

    private function strategy(): string
    {
        $env = getenv('WKHTMLTOPDF_VISUAL_STRATEGY');

        return ($env !== false && $env !== '') ? $env : 'auto';
    }
}
