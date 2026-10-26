<?php
// Spec 018 — Validation with realistic projects
use OmopCdm\OmopCdmExportModule\{DictionaryProfiler, CdmModel, MappingStore};

$root   = dirname(__DIR__, 2);
$corpus = dirname(__DIR__) . '/fixtures/eval10/fields.csv';
$eval   = dirname(__DIR__) . '/eval';

// ------------------------------------------------------------------ Corpus
$readCsv = function (string $path): array {
    $fh = fopen($path, 'r');
    $h = fgetcsv($fh, 0, ',', '"', '');
    $rows = [];
    while ($r = fgetcsv($fh, 0, ',', '"', '')) {
        $rows[] = array_combine($h, $r);
    }
    fclose($fh);
    return [$h, $rows];
};
[$header, $fields] = $readCsv($corpus);
$byProject = [];
foreach ($fields as $r) {
    $byProject[$r['project']][] = $r;
}
$langs = [];
foreach ($fields as $r) {
    $langs[$r['project']][$r['lang']] = true;
}
// A corpus field as a REDCap dictionary row.
$meta = fn(array $r) => f($r['field_name'], $r['form_name'], $r['field_type'], $r['label'], $r['choices'],
                          $r['validation'], '', $r['identifier']);

section('eval10 regression corpus');
test('FR-018-01', 'the corpus has the agreed columns',
    $header === ['lang', 'project', 'field_name', 'form_name', 'field_type', 'label', 'choices', 'validation', 'identifier', 'expected'],
    implode(',', $header));
test('FR-018-01', '186 fields from 10 projects (P1…P10)', count($fields) === 186
    && array_keys($byProject) === ['P1', 'P2', 'P3', 'P4', 'P5', 'P6', 'P7', 'P8', 'P9', 'P10']);
$es = array_filter($fields, fn($r) => $r['lang'] === 'es');
$en = array_filter($fields, fn($r) => $r['lang'] === 'en');
test('FR-018-01', 'five projects in Spanish (96 fields) and five in English (90 fields)',
    count($es) === 96 && count($en) === 90
    && array_keys(array_filter($langs, fn($l) => isset($l['es']))) === ['P1', 'P2', 'P3', 'P4', 'P5']
    && !array_filter($langs, fn($l) => count($l) !== 1));
$valid = CdmModel::validDomains();
$bad = array_filter($fields, fn($r) => array_diff(explode('|', $r['expected']), $valid));
test('FR-018-01', 'every expected domain is a valid domain of the module', !$bad,
    implode(',', array_map(fn($r) => $r['project'] . '.' . $r['field_name'], $bad)));
$dups = [];
foreach ($byProject as $p => $rows) {
    if (count(array_unique(array_column($rows, 'field_name'))) !== count($rows)) {
        $dups[] = $p;
    }
}
test('FR-018-01', 'no project repeats a field name', !$dups, implode(',', $dups));

test('FR-018-02', 'the record_id of every project is expected as Person',
    !array_filter($fields, fn($r) => $r['field_name'] === 'record_id' && $r['expected'] !== 'Person')
    && count(array_filter($fields, fn($r) => $r['field_name'] === 'record_id')) === 10);
test('FR-018-02', 'the identifiers the curator excludes are expected as NotMapped (DNI, NHC, initials)',
    !array_filter($fields, fn($r) => in_array($r['field_name'], ['dni', 'nhc', 'iniciales'], true) && $r['expected'] !== 'NotMapped'));
$regen = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($eval . '/build_corpus.php') . ' - 2>/dev/null');
test('FR-018-02', 'build_corpus.php regenerates exactly the versioned corpus from specs.php',
    is_string($regen) && $regen !== '' && $regen === file_get_contents($corpus));

// ------------------------------------------------------------------ Measurement
section('Classifier on the 10 projects');
$prof = new DictionaryProfiler();
$acc = ['total' => [0, 0], 'es' => [0, 0], 'en' => [0, 0]];
$perProject = [];
$misses = [];
$proposed = [];
foreach ($byProject as $p => $rows) {
    $dict = [];
    foreach ($rows as $r) {
        $dict[$r['field_name']] = $meta($r);
    }
    // As in REDCap: the first field of the project identifies the record.
    $res = $prof->profile($dict, ['record_id_field' => $rows[0]['field_name']]);
    foreach ($rows as $r) {
        $got = $res[$r['field_name']]['suggested_domain'];
        $proposed[] = $got;
        $hit = in_array($got, explode('|', $r['expected']), true);
        foreach (['total', $r['lang']] as $k) {
            $acc[$k][0] += $hit ? 1 : 0;
            $acc[$k][1]++;
        }
        $perProject[$p][0] = ($perProject[$p][0] ?? 0) + ($hit ? 1 : 0);
        $perProject[$p][1] = ($perProject[$p][1] ?? 0) + 1;
        if (!$hit) {
            $misses[] = sprintf('     %-4s %-3s %-16s %-36s → %s (expected %s)', $p, $r['lang'], $r['field_name'],
                mb_substr($r['label'], 0, 36), $got, $r['expected']);
        }
    }
}
if (!Harness::$quiet) {
    echo '     ', implode('  ', array_map(fn($p, $x) => "$p {$x[0]}/{$x[1]}", array_keys($perProject), $perProject)), "\n";
    foreach ($acc as $k => [$a, $n]) {
        printf("     %-5s %d/%d = %.1f%%\n", $k, $a, $n, 100 * $a / max(1, $n));
    }
    echo $misses ? implode("\n", $misses) . "\n" : '';
}
test('FR-018-03', 'the measurement covers the 186 fields and each one gets a valid domain',
    $acc['total'][1] === 186 && !array_diff($proposed, $valid));
test('FR-018-03', 'the number of misses matches the accuracy', count($misses) === $acc['total'][1] - $acc['total'][0]);
test('NFR-018-01', sprintf('overall accuracy ≥ 97 %% (%d/%d)', $acc['total'][0], $acc['total'][1]),
    $acc['total'][0] / $acc['total'][1] >= 0.97);
test('NFR-018-02', sprintf('accuracy in Spanish ≥ 95 %% (%d/%d)', $acc['es'][0], $acc['es'][1]),
    $acc['es'][0] / $acc['es'][1] >= 0.95);
test('NFR-018-02', sprintf('accuracy in English ≥ 95 %% (%d/%d)', $acc['en'][0], $acc['en'][1]),
    $acc['en'][0] / $acc['en'][1] >= 0.95);

// The holdout set of spec 004's benchmark was not looked at while fixing: it is still the honest measure.
[, $bench] = $readCsv(dirname(__DIR__) . '/fixtures/benchmark.csv');
$ho = [0, 0];
foreach ($bench as $r) {
    if ($r['set'] !== 'holdout') {
        continue;
    }
    $got = $prof->profile([$r['field_name'] => $meta($r + ['form_name' => 'f'])],
        ['record_id_field' => in_array($r['field_name'], ['record_id', 'study_id'], true) ? $r['field_name'] : null])[$r['field_name']]['suggested_domain'];
    $ho[0] += in_array($got, explode('|', $r['expected']), true) ? 1 : 0;
    $ho[1]++;
}
test('NFR-018-03', sprintf('the holdout benchmark stays ≥ 70 %% (%d/%d)', $ho[0], $ho[1]), $ho[1] > 0 && $ho[0] / $ho[1] >= 0.70);

// ------------------------------------------------------------------ Multicentre
section('Multicentre scenario: from the London site (P10) to the Madrid site (P5)');
$dictOf = function (string $p) use ($byProject, $meta): array {
    $d = [];
    foreach ($byProject[$p] as $r) {
        $d[$r['field_name']] = $meta($r);
    }
    return $d;
};
$settings = ['birth-date-field' => 'dob', 'gender-field' => 'sex', 'default-date-field' => 'admission_date',
             'death-date-field' => 'death_date', 'care-site-field' => '', 'visit-concept-id' => '9201'];
\REDCap::$dict = $dictOf('P10');
\REDCap::$data = [];
\Project::$conf = ['RepeatingFormsEvents' => [], 'forms' => ['baseline' => [], 'followup' => []]];
$lon = make_module($settings);
$s = $lon->store();
$d = \REDCap::$dict;
$ch = fn(string $f) => $lon->profile()[$f]['choices'] ?? [];
foreach (['1' => [8507, 'MALE'], '2' => [8532, 'FEMALE']] as $code => [$id, $name]) {
    $s->updateValue('sex', $code, ['emit' => '1', 'concept_id' => (string) $id, 'concept_name' => $name], $ch('sex')[$code], $d['sex']);
}
$s->updateField('sex', ['domain' => 'Person', 'value_strategy' => 'per_choice', 'state' => 'approved'], $d['sex'], $ch('sex'));
foreach (['1' => [1112807, 'Aspirin'], '2' => [1322184, 'clopidogrel'], '3' => [1310149, 'Warfarin']] as $code => [$id, $name]) {
    $s->updateValue('discharge_med', $code, ['emit' => '1', 'concept_id' => (string) $id, 'concept_name' => $name], $ch('discharge_med')[$code], $d['discharge_med']);
}
$s->updateValue('discharge_med', '4', ['emit' => '0'], 'None', $d['discharge_med']);
$s->updateField('discharge_med', ['domain' => 'Drug', 'value_strategy' => 'per_choice', 'date_field' => 'admission_date', 'state' => 'approved'],
    $d['discharge_med'], $ch('discharge_med'));
$s->updateField('glucose', ['domain' => 'Measurement', 'concept_id' => '3004501', 'concept_name' => 'Glucose lab',
    'unit_source_value' => 'mg/dL', 'date_field' => 'admission_date', 'state' => 'approved'], $d['glucose'], []);
$s->updateField('ct_result', ['state' => 'excluded'], $d['ct_result'], $ch('ct_result'));
$approvedLondon = array_keys(array_filter($lon->mappingRows(), fn($r) => $r['state'] === 'approved'));
$doc = json_decode($lon->mappingDocument()->json(), true);

\REDCap::$dict = $dictOf('P5');
$mad = make_module($settings);
$r = $mad->store()->importMapping($doc, $mad->profile(), \REDCap::$dict);
$sm = $mad->store();
test('FR-018-04', 'the London site approves sex, antithrombotic and glucose',
    !array_diff(['sex', 'discharge_med', 'glucose'], $approvedLondon), implode(',', $approvedLondon));
test('FR-018-04', 'Madrid imports the fields of the translated instrument (same names, types and codes)',
    !array_diff(['sex', 'discharge_med', 'glucose', 'ct_result'], $r['imported']), json_encode($r));
test('FR-018-04', 'nothing imported ends up approved in Madrid',
    !array_filter($r['imported'], fn($f) => $sm->spec($f)['state'] === MappingStore::S_APPROVED));
test('FR-018-04', 'the exclusion decided in London travels to Madrid', $sm->spec('ct_result')['state'] === MappingStore::S_EXCLUDED);
test('FR-018-04', 'the translated options are flagged (Hombre/Male, Ácido acetilsalicílico/Aspirin…)',
    $sm->spec('sex')['import_diff'] === ['1', '2'] && $sm->spec('discharge_med')['import_diff'] === ['1', '3', '4'],
    json_encode([$sm->spec('sex')['import_diff'] ?? null, $sm->spec('discharge_med')['import_diff'] ?? null]));
test('FR-018-04', 'and ask for confirmation with the import_labels_differ warning, which does not block',
    in_array('import_labels_differ', array_column($mad->advise('discharge_med'), 'code'), true)
    && array_column($mad->advise('discharge_med'), 'severity', 'code')['import_labels_differ'] === 'warn');
test('FR-018-04', 'a field without options (glucose) arrives without a label warning',
    empty($sm->spec('glucose')['import_diff']) && (int) $sm->spec('glucose')['concept_id'] === 3004501);
reset_fixture();

// ------------------------------------------------------------------ Evaluation material
section('Evaluation scripts and results');
$scripts = array_merge(glob($eval . '/*.php'), glob($eval . '/*.sh'));
$unguarded = array_filter(glob($eval . '/*.php'),
    fn($f) => strpos(file_get_contents($f), "if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }") === false);
test('FR-018-05', 'the evaluation scripts are versioned in tests/eval',
    !array_diff(['specs.php', 'generate.php', 'configure.php', 'curate.php', 'measure.php', 'summary.php', 'client.php',
                 'build_corpus.php', 'export_all.sh', 'checks.sh', 'checks.sql', 'integrity.sql', 'README.md'],
                array_map('basename', glob($eval . '/*'))));
test('FR-018-05', 'every PHP script refuses to run from the browser', !$unguarded, implode(',', array_map('basename', $unguarded)));
$secrets = array_filter($scripts, fn($f) => preg_match('/\b[0-9A-F]{32}\b|redcap123/', file_get_contents($f)));
test('FR-018-05', 'there is no projects.json, API token or password in the module',
    !is_file($eval . '/projects.json') && !$secrets, implode(',', array_map('basename', $secrets)));
test('FR-018-05', 'projects.json and the results are written outside the module (OMOP_EVAL_WORK)',
    !array_filter(glob($eval . '/*.php'), fn($f) => preg_match("#__DIR__ \\. ['\"]/(projects|results|classifier|summary|checks)#", file_get_contents($f))));

$r1 = json_decode(file_get_contents($eval . '/results/results_round1.json'), true);
$t1 = ['es' => [0, 0], 'en' => [0, 0]];
foreach ($r1 as $p) {
    $t1[$p['lang']][0] += $p['classifier']['hit'];
    $t1[$p['lang']][1] += $p['classifier']['n'];
}
test('FR-018-06', 'round 1 recorded: 154/186 (ES 82/96, EN 72/90)',
    $t1['es'] === [82, 96] && $t1['en'] === [72, 90], json_encode($t1));
$r2 = json_decode(file_get_contents($eval . '/results/classifier_round2.json'), true);
test('FR-018-06', 'round 2 recorded: 184/186, and the corpus gives no less today',
    $r2['total'] === [184, 186] && $acc['total'][1] === 186 && $acc['total'][0] >= 184,
    json_encode([$r2['total'], $acc['total']]));
$integ = ['fk_person_broken', 'fk_visit_broken', 'fk_care_site_broken', 'fk_provider_broken', 'fact_outside_visit',
          'fact_outside_period', 'fact_before_birth', 'fact_after_death', 'end_before_start', 'person_without_period'];
$checks = [];
foreach (file($eval . '/results/checks.txt') as $l) {
    $x = explode('|', trim($l));
    if (count($x) === 3 && in_array($x[1], $integ, true)) {
        $checks[$x[0]][$x[1]] = (int) $x[2];
    }
}
test('FR-018-06', 'integrity recorded: 10 schemas × 10 checks, 0 issues',
    count($checks) === 10 && !array_filter($checks, fn($c) => count($c) !== 10) && array_sum(array_map('array_sum', $checks)) === 0);

$sql = file_get_contents($eval . '/integrity.sql');
preg_match_all("/select :'s', '([a-z_]+)'/", $sql, $mm);
test('FR-018-07', 'integrity.sql carries the 10 checks, per schema (psql -v s=…)', $mm[1] === $integ, implode(',', $mm[1]));
test('FR-018-07', 'checks.sh runs the same 10 checks for p1…p10',
    !array_filter($integ, fn($c) => strpos(file_get_contents($eval . '/checks.sh'), "'$c'") === false));
