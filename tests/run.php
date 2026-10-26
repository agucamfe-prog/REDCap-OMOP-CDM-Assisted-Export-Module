<?php
/**
 * Runs the spec tests, without REDCap.
 *
 *   php tests/run.php              all specs
 *   php tests/run.php 004 009      only those specs
 *   php tests/run.php --trace      also checks that every FR requirement of
 *                                  every spec.md has at least one test (or is [manual])
 *   php tests/run.php --quiet      only failures and the summary
 *
 * Exit code 1 if any test fails or, with --trace, if there are requirements
 * without tests or tests citing unknown requirements. Useful in CI.
 */
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/lib/harness.php';
require __DIR__ . '/lib/fixtures.php';

$args   = array_slice($argv, 1);
$trace  = in_array('--trace', $args, true);
Harness::$quiet = in_array('--quiet', $args, true);
$only   = array_values(array_filter($args, fn($a) => preg_match('/^\d{3}$/', $a)));
$root   = dirname(__DIR__);

$files = glob(__DIR__ . '/specs/[0-9][0-9][0-9]_*.test.php');
sort($files);
foreach ($files as $file) {
    $spec = substr(basename($file), 0, 3);
    if ($only && !in_array($spec, $only, true)) {
        continue;
    }
    Harness::$spec = $spec;
    if (!Harness::$quiet) {
        echo "\n== ", str_replace(['.test.php', '_'], ['', ' '], basename($file)), "\n";
    }
    reset_fixture();
    (static function (string $__file) {
        require $__file;
    })($file);
}

// ------------------------------------------------------------------ Summary per spec
$by = [];
foreach (Harness::$results as $r) {
    $by[$r['spec']][$r['ok'] ? 'ok' : 'fail'] = ($by[$r['spec']][$r['ok'] ? 'ok' : 'fail'] ?? 0) + 1;
}

// ------------------------------------------------------------------ Traceability
$reqs = [];      // spec => [id => ['manual' => bool, 'text' => string]]
foreach (glob($root . '/specs/[0-9][0-9][0-9]-*/spec.md') as $md) {
    $spec = substr(basename(dirname($md)), 0, 3);
    if ($only && !in_array($spec, $only, true)) {
        continue;
    }
    preg_match_all('/^\s*[-*]\s+\*\*(N?FR-\d{3}-\d{2})\*\*\s*[—–-]?\s*(.*)$/mu', file_get_contents($md), $m, PREG_SET_ORDER);
    foreach ($m as [, $id, $text]) {
        $reqs[$spec][$id] = ['manual' => strpos($text, '[manual]') !== false, 'text' => $text];
    }
}
$cited = [];
foreach (Harness::$results as $r) {
    foreach ($r['req'] as $id) {
        $cited[$id] = true;
    }
}
$orphans = $unknown = [];
foreach ($reqs as $spec => $list) {
    foreach ($list as $id => $info) {
        if (strpos($id, 'FR-') === 0 && !$info['manual'] && empty($cited[$id])) {
            $orphans[] = $id;
        }
    }
}
$known = [];
foreach ($reqs as $list) {
    $known += $list;
}
foreach (array_keys($cited) as $id) {
    if (!isset($known[$id]) && (!$only || in_array(substr($id, strpos($id, '-') + 1, 3), $only, true))) {
        $unknown[] = $id;
    }
}

echo "\n Spec  Tests        FR requirements (tested / manual / total)\n";
$tot = ['ok' => 0, 'fail' => 0];
foreach (array_unique(array_merge(array_keys($by), array_keys($reqs))) as $spec) {
    $ok = $by[$spec]['ok'] ?? 0;
    $fail = $by[$spec]['fail'] ?? 0;
    $tot['ok'] += $ok;
    $tot['fail'] += $fail;
    $fr = array_filter($reqs[$spec] ?? [], fn($i, $id) => strpos($id, 'FR-') === 0, ARRAY_FILTER_USE_BOTH);
    $man = count(array_filter($fr, fn($i) => $i['manual']));
    $cov = count(array_filter(array_keys($fr), fn($id) => !empty($cited[$id])));
    printf("  %s  %3d/%-3d %s   %2d / %2d / %2d\n", $spec, $ok, $ok + $fail, $fail ? "\033[31mFAIL\033[0m" : '    ',
        $cov, $man, count($fr));
}
printf("\n%d/%d tests passed\n", $tot['ok'], $tot['ok'] + $tot['fail']);

$bad = $tot['fail'] > 0;
if ($trace) {
    if ($orphans) {
        echo "\nRequirements without a test (and not [manual]):\n  ", implode("\n  ", $orphans), "\n";
    }
    if ($unknown) {
        echo "\nTests citing requirements that do not exist in any spec.md:\n  ", implode("\n  ", $unknown), "\n";
    }
    if (!$orphans && !$unknown) {
        echo "Full traceability: every requirement has a test or a manual verification.\n";
    }
    $bad = $bad || $orphans || $unknown;
}
exit($bad ? 1 : 0);
