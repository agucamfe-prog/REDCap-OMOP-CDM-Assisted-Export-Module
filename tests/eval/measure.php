<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }   // command line only: never from the browser
// projects.json (with the API tokens) and the results live OUTSIDE the module.
$WORK = getenv('OMOP_EVAL_WORK') ?: '/tmp/omoptest';
// Classifier accuracy on the 10 projects against the reference → classifier_<round>.json
require __DIR__ . '/client.php';
$P   = require __DIR__ . '/specs.php';
$ids = json_decode(file_get_contents($WORK . '/projects.json'), true);
$round = $argv[1] ?? 'round2';
$out = [];
$tot = [0, 0]; $byLang = ['es' => [0, 0], 'en' => [0, 0]]; $conf = [];
foreach ($P as $key => $p) {
    $e = new Ed((int) $ids[$key]['pid']);
    $csv = preg_replace('/^\xEF\xBB\xBF/', '', $e->get('pages/dashboard', '&download=csv'));
    $rows = array_map(fn($l) => str_getcsv($l, ',', '"', ''), array_filter(explode("\n", $csv)));
    $hdr = array_shift($rows);
    $hit = 0; $n = 0; $miss = [];
    foreach ($rows as $r) {
        if (count($r) !== count($hdr)) continue;
        $r = array_combine($hdr, $r);
        $f = current(array_filter($p['fields'], fn($x) => $x['field_name'] === $r['field_name']));
        if (!$f) continue;
        $truth = !empty($f['m']['x']) ? ['NotMapped'] : (isset($f['m']['d']) ? [$f['m']['d']] : explode('|', (string) $f['t']));
        if ($f['field_name'] === 'record_id') $truth = ['Person'];
        $n++;
        $ok = in_array($r['suggested_domain'], $truth, true);
        $hit += $ok ? 1 : 0;
        $conf[$r['confidence']][$ok ? 'ok' : 'ko'] = ($conf[$r['confidence']][$ok ? 'ok' : 'ko'] ?? 0) + 1;
        if (!$ok) $miss[] = $r['field_name'] . ': ' . $r['suggested_domain'] . ' ≠ ' . implode('|', $truth);
    }
    $out[$key] = ['hit' => $hit, 'n' => $n, 'miss' => $miss, 'lang' => $p['lang']];
    $tot[0] += $hit; $tot[1] += $n;
    $byLang[$p['lang']][0] += $hit; $byLang[$p['lang']][1] += $n;
    printf("%-4s %s %2d/%2d  %s\n", $key, $p['lang'], $hit, $n, implode(' ; ', $miss));
}
printf("TOTAL %d/%d = %.1f%%   ES %d/%d = %.1f%%   EN %d/%d = %.1f%%\n", $tot[0], $tot[1], 100 * $tot[0] / $tot[1],
    $byLang['es'][0], $byLang['es'][1], 100 * $byLang['es'][0] / $byLang['es'][1], $byLang['en'][0], $byLang['en'][1], 100 * $byLang['en'][0] / $byLang['en'][1]);
echo "confidence: ", json_encode($conf), "\n";
file_put_contents($WORK . "/classifier_$round.json", json_encode(['projects' => $out, 'total' => $tot, 'by_lang' => $byLang, 'confidence' => $conf],
    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
