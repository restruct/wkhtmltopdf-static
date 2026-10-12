<?php

/**
 * Visual check CLI: render the fixture and compare, without PHPUnit.
 *
 *   php tests/Visual/visual-check.php check    [--strategy=S] [--case=C,...]
 *       Render with strategy S (default "auto") and compare against the committed baselines of
 *       the platform S renders on. Same check as the PHPUnit group "visual".
 *
 *   php tests/Visual/visual-check.php compare A B [--case=C,...] [--a-opt=name=value] [--b-opt=name=value]
 *       Render with strategies A and B and compare them with each other, no baselines involved.
 *       Use it before switching a host from one strategy to another, e.g.
 *         compare bundled docker
 *         compare bundled /usr/local/bin/wkhtmltopdf
 *         compare bundled bundled --b-opt=zoom=1.02     (must-fail control: shows red)
 *       --a-opt/--b-opt override one wkhtmltopdf option for that side only (repeatable;
 *       "true" is a bare flag, "false" removes the option).
 *
 *   php tests/Visual/visual-check.php baseline [--strategy=S] [--case=C,...]
 *       Render with S and (re)write the baselines for S's platform. Geometry must match first.
 *       Look at the PNGs before committing them: a baseline is a claim that this output is right.
 *
 * Strategies: auto | bundled | native | docker | docker:<image> | /path/to/wkhtmltopdf
 * Exit codes: 0 pass, 1 differences found, 2 usage error or prerequisite missing.
 * Failing renders, their pages and red diff images go to tests/Visual/out/ (or $WKHTMLTOPDF_VISUAL_OUT).
 */

use Restruct\WkhtmltoPdf\Tests\Visual\VisualCheck;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$argv = $_SERVER['argv'];
array_shift($argv);
$command = array_shift($argv) ?? '';

$positional = [];
$opts = ['strategy' => 'auto', 'case' => '', 'a-opt' => [], 'b-opt' => []];
foreach ($argv as $arg) {
    if (preg_match('/^--(strategy|case)=(.*)$/', $arg, $m)) {
        $opts[$m[1]] = $m[2];
    } elseif (preg_match('/^--([ab]-opt)=([^=]+)=(.*)$/', $arg, $m)) {
        $opts[$m[1]][$m[2]] = match ($m[3]) {
            'true' => true,
            'false' => false,
            default => $m[3],
        };
    } elseif (str_starts_with($arg, '--')) {
        fwrite(STDERR, "Unknown option: $arg\n");
        exit(2);
    } else {
        $positional[] = $arg;
    }
}

$allCases = array_keys(VisualCheck::cases());
$cases = $opts['case'] === '' ? $allCases : explode(',', $opts['case']);
foreach ($cases as $case) {
    if (!in_array($case, $allCases, true)) {
        fwrite(STDERR, "Unknown case: $case (cases: " . implode(', ', $allCases) . ")\n");
        exit(2);
    }
}

/**
 * Print one page comparison table and return whether every page passed.
 *
 * @param array<int, array{percent: float, pixels: int, reason: ?string, pass: bool, diff: ?string}> $report
 */
$printPages = static function (array $report): bool {
    $ok = true;
    foreach ($report as $page => $r) {
        $ok = $ok && $r['pass'];
        printf(
            "    page %d: %s  %.4f %% differing (%d px)%s%s\n",
            $page,
            $r['pass'] ? 'PASS' : 'FAIL',
            $r['percent'],
            $r['pixels'],
            $r['reason'] !== null ? '  ' . $r['reason'] : '',
            $r['diff'] !== null ? '  diff: ' . $r['diff'] : ''
        );
    }

    return $ok;
};

$requireStrategy = static function (string $strategy): void {
    $problem = VisualCheck::missingPrerequisite($strategy);
    if ($problem !== null) {
        fwrite(STDERR, "Cannot run with '$strategy': $problem\n");
        exit(2);
    }
};

$allOk = true;

switch ($command) {
    case 'check':
    case 'baseline':
        $strategy = $opts['strategy'];
        $requireStrategy($strategy);
        $platform = VisualCheck::platformFor($strategy);
        printf("%s with %s, %s baselines, %d dpi, tolerance %.2f %%\n", $command, VisualCheck::describe($strategy), $platform, VisualCheck::DPI, VisualCheck::TOLERANCE_PERCENT);

        foreach ($cases as $case) {
            $def = VisualCheck::cases()[$case];
            $work = VisualCheck::makeWorkDir($case);
            try {
                $pdf = VisualCheck::render($strategy, $case, $work);
                $geometry = VisualCheck::geometry($pdf);
                $problems = VisualCheck::geometryProblems($geometry, $def['pages'], $def['size']);
                printf("  %s: %d page(s), %s\n", $case, $geometry['pages'], $problems === [] ? 'geometry OK' : 'GEOMETRY FAIL: ' . implode('; ', $problems));
                $pages = VisualCheck::rasterise($pdf, "$work/$case");

                if ($command === 'baseline') {
                    if ($problems !== []) {
                        $allOk = false;
                        echo "    not written: geometry must match the case definition first\n";
                        continue;
                    }
                    VisualCheck::writeBaselines($platform, $case, $pages);
                    echo '    written to ' . VisualCheck::baselineDir($platform, $case) . "\n";
                    continue;
                }

                $baseline = VisualCheck::baselinePages($platform, $case);
                if ($baseline === []) {
                    $allOk = false;
                    echo "    FAIL: no $platform baselines (write them with: baseline --strategy=$strategy --case=$case)\n";
                    VisualCheck::keep("check-$platform", array_merge([$pdf], $pages));
                    continue;
                }
                $label = "check-$platform";
                $report = VisualCheck::comparePages($baseline, $pages, VisualCheck::outDir() . "/$label/$case");
                $caseOk = $printPages($report) && $problems === [];
                if (!$caseOk) {
                    echo '    kept in ' . VisualCheck::keep($label, array_merge([$pdf], $pages)) . "\n";
                }
                $allOk = $allOk && $caseOk;
            } catch (\RuntimeException $e) {
                $allOk = false;
                echo "  $case: ERROR " . $e->getMessage() . "\n";
            } finally {
                VisualCheck::removeTree($work);
            }
        }
        break;

    case 'compare':
        if (count($positional) !== 2) {
            fwrite(STDERR, "compare needs two strategies, e.g.: compare bundled docker\n");
            exit(2);
        }
        [$a, $b] = $positional;
        $requireStrategy($a);
        $requireStrategy($b);
        printf("compare A = %s%s\n     with B = %s%s\n", VisualCheck::describe($a), $opts['a-opt'] ? ' ' . json_encode($opts['a-opt']) : '', VisualCheck::describe($b), $opts['b-opt'] ? ' ' . json_encode($opts['b-opt']) : '');

        foreach ($cases as $case) {
            $workA = VisualCheck::makeWorkDir("$case-a");
            $workB = VisualCheck::makeWorkDir("$case-b");
            try {
                $pdfA = VisualCheck::render($a, $case, $workA, $opts['a-opt']);
                $pdfB = VisualCheck::render($b, $case, $workB, $opts['b-opt']);
                $geoA = VisualCheck::geometry($pdfA);
                $geoB = VisualCheck::geometry($pdfB);
                $geoOk = $geoA === $geoB;
                printf("  %s: A %d page(s) %s, B %d page(s) %s: %s\n", $case, $geoA['pages'], implode(', ', array_unique($geoA['sizes'])), $geoB['pages'], implode(', ', array_unique($geoB['sizes'])), $geoOk ? 'geometry same' : 'GEOMETRY DIFFERS');
                $label = 'compare';
                $report = VisualCheck::comparePages(
                    VisualCheck::rasterise($pdfA, "$workA/$case-A"),
                    $pagesB = VisualCheck::rasterise($pdfB, "$workB/$case-B"),
                    VisualCheck::outDir() . "/$label/$case"
                );
                $caseOk = $printPages($report) && $geoOk;
                if (!$caseOk) {
                    echo '    kept in ' . VisualCheck::keep($label, array_merge(["$case-A.pdf" => $pdfA, "$case-B.pdf" => $pdfB], glob("$workA/$case-A-*.png") ?: [], $pagesB)) . "\n";
                }
                $allOk = $allOk && $caseOk;
            } catch (\RuntimeException $e) {
                $allOk = false;
                echo "  $case: ERROR " . $e->getMessage() . "\n";
            } finally {
                VisualCheck::removeTree($workA);
                VisualCheck::removeTree($workB);
            }
        }
        break;

    default:
        fwrite(STDERR, "Usage: php tests/Visual/visual-check.php check|compare|baseline [...] (see the header of this file)\n");
        exit(2);
}

echo $allOk ? "PASS\n" : "FAIL\n";
exit($allOk ? 0 : 1);
