<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }   // command line only: never from the browser
// projects.json (with the API tokens) and the results live OUTSIDE the module.
$WORK = getenv('OMOP_EVAL_WORK') ?: '/tmp/omoptest';
/**
 * Curates the 10 projects through the editor (the same requests the
 * browser makes) and saves metrics in results.json.
 *   php curate.php [P1,P2,...]
 */
require __DIR__ . '/client.php';
$P   = require __DIR__ . '/specs.php';
$ids = json_decode(file_get_contents($WORK . '/projects.json'), true);
$resFile = $WORK . '/results.json';
$R = is_file($resFile) ? json_decode(file_get_contents($resFile), true) : [];
$only = isset($argv[1]) ? explode(',', $argv[1]) : array_keys($P);

foreach ($only as $key) {
    $p   = $P[$key];
    $pid = (int) $ids[$key]['pid'];
    $e   = new Ed($pid);
    $t0  = microtime(true);
    $m = ['pid' => $pid, 'lang' => $p['lang'], 'mode' => $p['mode'], 'fields' => count($p['fields']),
          'requests' => 0, 'warnings' => [], 'blocked' => [], 'confirmed' => 0, 'suggest' => [], 'errors' => []];
    $post = function (array $d) use ($e, &$m) { $m['requests']++; return $e->post($d); };

    // --- Classifier against the reference
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
        if (in_array($r['suggested_domain'], $truth, true)) { $hit++; } else { $miss[] = $r['field_name'] . ': ' . $r['suggested_domain'] . ' ≠ ' . implode('|', $truth); }
    }
    $m['classifier'] = ['hit' => $hit, 'n' => $n, 'miss' => $miss];

    // --- Reuse
    if ($p['mode'] === 'annotation') {
        $r = $post(['action' => 'bulk', 'which' => 'adopt-annotations']);
        $m['adopted'] = $r['changed'] ?? 0;
        $r = $post(['action' => 'bulk', 'which' => 'approve-ready']);
        $m['bulk_approved'] = $r['changed'] ?? 0;
    }
    if ($p['mode'] === 'import') {
        $src = (int) $ids[$p['import_from']]['pid'];
        $src = new Ed($src);
        $json = $src->get('pages/export', '&doc=json');
        $r = $post(['action' => 'import-mapping', 'json' => $json]);
        $m['imported'] = count($r['imported'] ?? []);
        $m['import_skipped'] = $r['skipped'] ?? [];
        $r = $post(['action' => 'bulk', 'which' => 'approve-ready']);
        $m['bulk_approved'] = $r['changed'] ?? 0;
    }

    // --- Bulk exclusion of what has no target
    $prev = $post(['action' => 'bulk-preview', 'which' => 'exclude-notmapped']);
    $post(['action' => 'bulk', 'which' => 'exclude-notmapped']);
    $m['bulk_excluded'] = $prev['count'] ?? 0;

    // --- Field by field, whatever is left
    $dump = json_decode($e->get('pages/export', '&doc=json'), true);
    foreach ($p['fields'] as $f) {
        $map = $f['m'];
        if (!$map) continue;
        $name = $f['field_name'];
        $state = $dump['fields'][$name]['state'] ?? 'proposed';
        if (!empty($map['x'])) {
            if ($state !== 'excluded') { $post(['action' => 'save-field', 'field' => $name, 'state' => 'excluded']); }
            continue;
        }
        if ($state === 'approved') continue;

        // Concept looked up with the suggestions button
        $cid = $map['c'] ?? null; $cname = $map['cn'] ?? null;
        if (is_string($cid) && strpos($cid, 'sug:') === 0) {
            $kw = substr($cid, 4);
            $s = $post(['action' => 'suggest', 'field' => $name, 'raw_value' => '']);
            $pick = null;
            foreach ($s['results'] ?? [] as $c) {
                if (stripos($c['name'], $kw) !== false) { $pick = $c; break; }
            }
            $m['suggest'][] = ['field' => $name, 'query' => $s['query'] ?? '', 'n' => count($s['results'] ?? []),
                               'picked' => $pick ? $pick['id'] . ' ' . $pick['name'] : null, 'rank' => $pick ? array_search($pick, $s['results']) + 1 : null];
            if (!$pick) { $m['errors'][] = "$name: no valid suggestion"; continue; }
            $cid = $pick['id']; $cname = $pick['name'];
        }
        foreach ($map['v'] ?? [] as $code => $v) {
            $ch = $v === '-' ? ['emit' => '0', 'concept_id' => '', 'concept_name' => '']
                             : ['emit' => '1', 'concept_id' => (string) ($v[0] ?? ''), 'concept_name' => (string) ($v[1] ?? '')];
            $post(['action' => 'save-value', 'field' => $name, 'raw_value' => (string) $code] + $ch);
        }
        $fields = ['domain' => $map['d'], 'value_strategy' => $map['s'] ?? '', 'concept_id' => (string) ($cid ?? ''),
                   'concept_name' => (string) ($cname ?? ''), 'date_field' => $map['date'] ?? '', 'end_date_field' => $map['end'] ?? '',
                   'unit_source_value' => $map['u'] ?? ''];
        $r = $post(['action' => 'save-field', 'field' => $name, 'state' => 'approved'] + $fields);
        foreach ($r['warnings'] ?? [] as $w) { $m['warnings'][$w['code']] = ($m['warnings'][$w['code']] ?? 0) + 1; }
        if (!empty($r['blocked'])) {
            $m['blocked'][] = $name . ': ' . implode(',', array_column(array_filter($r['warnings'], fn($w) => $w['severity'] === 'block'), 'code'));
            continue;
        }
        if (!empty($r['confirm'])) {
            $r = $post(['action' => 'save-field', 'field' => $name, 'state' => 'approved', 'confirmed' => '1'] + $fields);
            $m['confirmed']++;
        }
        if (($r['spec']['state'] ?? '') !== 'approved') {
            $m['errors'][] = "$name: " . ($r['error'] ?? json_encode($r['spec']['state'] ?? $r));
        }
    }

    $dump = json_decode($e->get('pages/export', '&doc=json'), true);
    $m['states'] = $dump['summary']['by_state'];
    $m['by_domain'] = $dump['summary']['by_domain'];
    $m['seconds'] = round(microtime(true) - $t0, 1);
    $R[$key] = $m;
    printf("%-4s pid %d %s  classif %d/%d  approved %d  excluded %d  pending %d  warnings %s  blocked %d  conf %d  err %d  (%ss, %d requests)\n",
        $key, $pid, $p['lang'], $hit, $n, $m['states']['approved'] ?? 0, $m['states']['excluded'] ?? 0, $m['states']['proposed'] ?? 0,
        json_encode($m['warnings']), count($m['blocked']), $m['confirmed'], count($m['errors']), $m['seconds'], $m['requests']);
    foreach ($m['errors'] as $er) echo "     ! $er\n";
    foreach ($m['blocked'] as $er) echo "     # $er\n";
    file_put_contents($resFile, json_encode($R, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}
