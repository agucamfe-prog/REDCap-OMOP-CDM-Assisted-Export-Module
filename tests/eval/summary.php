<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }   // command line only: never from the browser
// projects.json (with the API tokens) and the results live OUTSIDE the module.
$WORK = getenv('OMOP_EVAL_WORK') ?: '/tmp/omoptest';
// Summary table of the evaluation (inside rc1_web). Writes summary.json.
require __DIR__ . '/client.php';
$P   = require __DIR__ . '/specs.php';
$ids = json_decode(file_get_contents($WORK . '/projects.json'), true);
$r1  = json_decode(file_get_contents($WORK . '/results_round1.json'), true);
$cur = json_decode(file_get_contents($WORK . '/results.json'), true);
$c2  = json_decode(file_get_contents($WORK . '/classifier_round2.json'), true);
$checks = [];
foreach (file($WORK . '/checks.txt') as $l) {
    $f = explode('|', trim($l));
    if (count($f) === 3) $checks[$f[0]][$f[1]] = (int) $f[2];
}
$out = [];
foreach (['P1','P2','P3','P4','P5','P6','P7','P8','P9','P10'] as $k) {
    $p = $P[$k]; $pid = (int) $ids[$k]['pid'];
    $e = new Ed($pid);
    $doc = json_decode($e->get('pages/export', '&doc=json'), true);
    $s = strtolower($k);
    $rows = array_filter($checks[$s] ?? [], fn($v, $kk) => strpos($kk, 'rows_') === 0, ARRAY_FILTER_USE_BOTH);
    $integ = array_sum(array_intersect_key($checks[$s] ?? [], array_flip(['fk_person_broken','fk_visit_broken','fk_care_site_broken','fk_provider_broken',
        'fact_outside_visit','fact_outside_period','fact_before_birth','fact_after_death','end_before_start','person_without_period'])));
    $out[$k] = [
        'pid' => $pid, 'title' => $p['title'], 'lang' => $p['lang'], 'mode' => $p['mode'], 'records' => $p['n'],
        'design' => !empty($p['events']) ? 'longitudinal (' . count($p['events']) . ' events)' : 'classic',
        'repeating' => array_keys($p['repeat']), 'dags' => $p['dags'] ?? [],
        'fields' => count($p['fields']),
        'clf_r1' => [$r1[$k]['classifier']['hit'], $r1[$k]['classifier']['n']],
        'clf_r2' => [$c2['projects'][$k]['hit'], $c2['projects'][$k]['n']],
        'states' => $doc['summary']['by_state'], 'by_domain' => $doc['summary']['by_domain'],
        'warnings' => $r1[$k]['warnings'], 'confirmed' => $r1[$k]['confirmed'] + (($k === 'P3' || $k === 'P9') ? $cur[$k]['confirmed'] : 0),
        'requests' => $r1[$k]['requests'] + (($k === 'P3' || $k === 'P9') ? $cur[$k]['requests'] : 0),
        'seconds' => $r1[$k]['seconds'],
        'adopted' => $r1[$k]['adopted'] ?? null, 'imported' => $r1[$k]['imported'] ?? null, 'bulk_approved' => $r1[$k]['bulk_approved'] ?? null,
        'suggest' => array_merge($r1[$k]['suggest'] ?? [], $cur[$k]['suggest'] ?? []),
        'cdm_rows' => array_sum($rows), 'rows_by_table' => $rows, 'integrity_issues' => $integ,
        'unit_concepts' => $checks[$s]['measurements_with_unit_concept'] ?? 0, 'value_concepts' => $checks[$s]['measurements_with_value_concept'] ?? 0,
        'operators' => $checks[$s]['measurements_with_operator'] ?? 0,
    ];
    $o = $out[$k];
    printf("%-4s %s %-14s %-10s rec %2d fields %2d  clf %2d/%2d → %2d/%2d  appr %2d excl %d conf %d pend %2d  rows %4d  integrity %d\n",
        $k, $o['lang'], $o['design'], $o['mode'], $o['records'], $o['fields'], $o['clf_r1'][0], $o['clf_r1'][1], $o['clf_r2'][0], $o['clf_r2'][1],
        $o['states']['approved'] ?? 0, $o['states']['excluded'] ?? 0, $o['states']['config'] ?? 0, $o['states']['proposed'] ?? 0, $o['cdm_rows'], $o['integrity_issues']);
}
file_put_contents($WORK . '/summary.json', json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
