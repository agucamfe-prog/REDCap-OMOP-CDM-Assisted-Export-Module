<?php
// Spec 011 — Export, report and mapping document

// Opens the ZIP from writeZip(), returns [name => content] and deletes it.
$openZip = function (array $zip): array {
    $z = new \ZipArchive();
    $z->open($zip['path']);
    $out = [];
    for ($i = 0; $i < $z->numFiles; $i++) {
        $n = $z->getNameIndex($i);
        $out[$n] = $z->getFromName($n);
    }
    $z->close();
    @unlink($zip['path']);
    return $out;
};
// Exports with the given module and returns the contents of the ZIP.
$export = function ($m) use ($openZip): array {
    return $openZip($m->exporter()->writeZip($m->transformer()->run()));
};
// RFC 4180 CSV (empty escape) → rows.
$csv = function (string $s): array {
    $fh = fopen('php://temp', 'w+');
    fwrite($fh, $s);
    rewind($fh);
    $rows = [];
    while (($r = fgetcsv($fh, 0, ',', '"', '')) !== false) {
        $rows[] = $r;
    }
    fclose($fh);
    return $rows;
};
$spanish = parse_ini_file(dirname(__DIR__, 2) . '/lang/Spanish.ini');
$english = \ExternalModules\AbstractExternalModule::$lang;
$who = defined('USERID') ? (string) USERID : 'system';

// ---------------------------------------------------------------------------
section('ZIP and table CSVs');

$m = make_module();
curate($m);
$res = $m->transformer()->run();
$zip = $m->exporter()->writeZip($res);
$tmpDir = substr($zip['path'], 0, -4);
$f = $openZip($zip);

$withRows = array_keys(array_filter($res['tables']));
$expected = array_map(fn($t) => strtoupper($t) . '.csv', $withRows);
test('FR-011-01', 'the ZIP has one CSV per table with rows, named after the table in upper case',
    !array_diff($expected, array_keys($f)), implode(' ', array_keys($f)));
test('FR-011-01', 'a table without rows produces no CSV',
    empty($res['tables']['observation']) && !isset($f['OBSERVATION.csv']) && !isset($f['SPECIMEN.csv']));
test('FR-011-01', 'writeZip returns how many rows it wrote per table',
    $zip['files'] === array_map('count', array_filter($res['tables'])) && $zip['bytes'] > 0);

$headersOk = true;
$rowsOk = true;
foreach ($withRows as $t) {
    $rows = $csv($f[strtoupper($t) . '.csv']);
    $headersOk = $headersOk && $rows[0] === \OmopCdm\OmopCdmExportModule\CdmModel::columns($t);
    $rowsOk = $rowsOk && count($rows) - 1 === count($res['tables'][$t]);
}
test('FR-011-02', 'the header of each CSV is the CdmModel columns in the official order', $headersOk);
test('FR-011-02', 'each CSV has one line per table row', $rowsOk);
$person = $csv($f['PERSON.csv']);
$col = array_flip($person[0]);
test('FR-011-02', 'a null value comes out as an empty cell', $person[1][$col['location_id']] === '');

test('NFR-011-01', 'the temporary directory with the CSVs is deleted after creating the ZIP', !is_dir($tmpDir));

// ---------------------------------------------------------------------------
section('RFC 4180 CSV and truncation');

reset_fixture();
$text = 'He said "hi" C:\\dir\\" end';
\REDCap::$data['1'][1]['notes'] = $text;
$m = make_module(['cdm-source-abbreviation' => str_repeat('A', 30)]);
curate($m);
$f = $export($m);
$note = $csv($f['NOTE.csv']);
test('FR-011-03', 'a text with quotes and a backslash comes out intact (RFC 4180 CSV, no escape)',
    $note[1][array_flip($note[0])['note_text']] === $text);
$src = $csv($f['CDM_SOURCE.csv']);
test('FR-011-04', 'a value longer than the DDL limit is truncated to that limit',
    $src[1][array_flip($src[0])['cdm_source_abbreviation']] === str_repeat('A', 25));
test('FR-011-04', 'the report counts truncations per column and says the value comes from the configuration',
    strpos($f['INFORME.txt'], 'VALUES TRUNCATED TO THE CDM LIMIT') !== false
    && preg_match('/cdm_source_abbreviation\s+1 value\(s\), max 25 characters/', $f['INFORME.txt'])
    && strpos($f['INFORME.txt'], 'cdm_source_abbreviation comes from the module configuration') !== false);

// ---------------------------------------------------------------------------
section('CDM_SOURCE');

reset_fixture();
$m = make_module();
curate($m);
$f = $export($m);
$src = $csv($f['CDM_SOURCE.csv']);
$row = array_combine($src[0], $src[1]);
test('FR-011-05', 'CDM_SOURCE has a single row',
    count($src) === 2);
test('FR-011-05', 'CDM_SOURCE takes name, holder and vocabulary version from the configuration',
    $row['cdm_source_name'] === 'Test' && $row['cdm_holder'] === 'Test' && $row['vocabulary_version'] === 'v5');
test('FR-011-05', 'CDM_SOURCE declares v5.4 (756265), the module as ETL and the current date',
    $row['cdm_version'] === 'v5.4' && $row['cdm_version_concept_id'] === '756265'
    && $row['cdm_etl_reference'] === 'OMOP-CDM Export vtest' && $row['cdm_release_date'] === date('Y-m-d'));
$m = make_module(['cdm-source-name' => '']);
$conf = $m->cdmSourceConfig();
test('FR-011-05', 'with no configured name or abbreviation, «REDCap project N» and «RCN» are used',
    $conf['name'] === 'REDCap project 1' && $conf['abbreviation'] === 'RC1');

// ---------------------------------------------------------------------------
section('INFORME.txt');

$inf = $f['INFORME.txt'];
test('FR-011-06', 'the report is in the project language (English) with title, project and module',
    strpos($inf, 'OMOP CDM v5.4 TRANSFORMATION REPORT') === 0
    && strpos($inf, 'REDCap project:  1') !== false && strpos($inf, 'OMOP-CDM Export vtest') !== false);
test('FR-011-06', 'the report gives records processed, rows generated and rows per table',
    strpos($inf, 'RECORDS PROCESSED: 3') !== false && strpos($inf, 'ROWS PER TABLE') !== false
    && preg_match('/^\s+PERSON\s+3$/m', $inf) && preg_match('/^\s+MEASUREMENT\s+9$/m', $inf));
test('FR-011-07', 'the report counts the values not emitted by design',
    strpos($inf, 'VALUES NOT EMITTED BY DESIGN: 2') !== false);
test('FR-011-07', 'the report explains the ambiguous numbers that were not converted',
    strpos($inf, 'VALUES THAT ARE NOT A NUMBER') !== false && strpos($inf, "1 answer(s) with an ambiguous format") !== false);
test('FR-011-07', 'the report counts the values with an operator',
    strpos($inf, 'VALUES WITH AN OPERATOR') !== false && strpos($inf, '1 measurement(s) such as') !== false);
test('FR-011-07', 'the report warns about end dates earlier than the start',
    strpos($inf, "END DATES BEFORE START\n") !== false && strpos($inf, '1 end date(s) earlier than their start') !== false);
test('FR-011-09', 'the report explains the identifiers',
    strpos($inf, "IDENTIFIERS\n") !== false && strpos($inf, 'person_id, visit_occurrence_id') !== false);
test('FR-011-09', 'the report recommends the next step with the load schema',
    strpos($inf, 'RECOMMENDED NEXT STEP') !== false && strpos($inf, '(schema cdm)') !== false);
test('FR-011-09', 'the report ends with the personal data notice',
    preg_match('/PERSONAL DATA NOTICE\n-+\n  This package contains participant data\.[^\n]*\n[^\n]*\n$/', $inf) === 1);

// Dropped persons, non-numeric values and sex not approved.
reset_fixture();
\REDCap::$data['2'][1]['dob'] = '';
\REDCap::$data['3'][1]['glu'] = 'pending';
$m = make_module();
curate($m);
$m->store()->updateField('sex', ['state' => 'proposed'], \REDCap::$dict['sex'], $m->profile()['sex']['choices']);
$m->invalidateMappingCache();
$inf = $export($m)['INFORME.txt'];
test('FR-011-07', 'the report counts the dropped persons, their rows and which records they were',
    strpos($inf, 'PERSONS DROPPED: 1') !== false && preg_match('/4 of their fact\s+row\(s\)/', $inf)
    && strpos($inf, 'Affected records: 2') !== false);
test('FR-011-07', 'the report counts the answers with no numeric value',
    strpos($inf, '1 measurement answer(s) with no numeric value') !== false);
test('FR-011-07', 'the report warns that sex is not approved',
    strpos($inf, 'The sex field is not approved in the editor') !== false);

// Empty required fields and discards.
reset_fixture();
\REDCap::$data['3'][1]['visit_date'] = '';
$m = make_module(['cdm-holder' => '']);
curate($m);
$inf = $export($m)['INFORME.txt'];
test('FR-011-08', 'the report lists the empty required columns with their number of rows',
    strpos($inf, 'WARNING: REQUIRED FIELDS ARE EMPTY') !== false
    && preg_match('/CDM_SOURCE\.cdm_holder\s+1 empty row\(s\)/', $inf));
test('FR-011-08', 'the report lists each discard reason, translated, and the total',
    strpos($inf, "Discarded data\n") !== false && strpos($inf, 'no reference date (weight)') !== false
    && strpos($inf, 'TOTAL DISCARDED: 4 values') !== false);

// Every discard reason of the engine has its translated line in the report.
preg_match_all("/->skip\\('([a-z_]+)'/", file_get_contents(dirname(__DIR__, 2) . '/classes/Transformer.php'), $codes);
$missingText = [];
foreach (array_unique($codes[1]) as $c) {
    if (!isset($english['skip_' . $c], $spanish['skip_' . $c])) {
        $missingText[] = $c;
    }
}
test('FR-011-08', 'every discard reason used by the engine has its text in English and Spanish',
    count($codes[1]) > 0 && !$missingText, implode(', ', $missingText));

// With hashing, the report carries no Record ID in clear.
reset_fixture();
\REDCap::$data['2'][1]['dob'] = '';
$m = make_module(['hash-person-source-value' => true]);
curate($m);
$inf = $export($m)['INFORME.txt'];
test('FR-011-07', 'with a hashed person_source_value, the report does not list the dropped Record IDs and explains why',
    strpos($inf, 'PERSONS DROPPED: 1') !== false && strpos($inf, 'Affected records') === false
    && strpos($inf, 'The affected Record IDs are not listed because person_source_value') !== false
    && !preg_match('/^\s+2$/m', $inf));

// Distinct section titles and order of the warnings.
reset_fixture();
\REDCap::$data['3'][1]['visit_date'] = '';
$m = make_module();
curate($m);
$m->store()->updateField('sex', ['state' => 'proposed'], \REDCap::$dict['sex'], $m->profile()['sex']['choices']);
$m->invalidateMappingCache();
$inf = $export($m)['INFORME.txt'];
preg_match_all('/^(\S[^\n]*)\n-{60}$/m', $inf, $tt);
test('FR-011-07', 'each section of the report has a distinct title',
    count($tt[1]) === count(array_unique($tt[1])) && count($tt[1]) > 8, implode(' | ', $tt[1]));
test('FR-011-07', 'the warning about persons without an observation period comes before the identifiers',
    strpos($inf, 'PERSONS WITHOUT AN OBSERVATION PERIOD') !== false
    && strpos($inf, 'PERSONS WITHOUT AN OBSERVATION PERIOD') < strpos($inf, "IDENTIFIERS\n"));
$exampleOk = true;
foreach ([$english, $spanish] as $l) {
    $exampleOk = $exampleOk && strpos($l['rep_not_numeric'], '< 50') === false && strpos($l['rep_not_numeric'], '<') === false;
}
test('FR-011-07', 'the example of a value with no number is not a value with an operator (which is converted)', $exampleOk);

// Without a load kit, the report does not point to it.
reset_fixture();
$m = make_module();
curate($m);
$noKit = new class($m) extends \OmopCdm\OmopCdmExportModule\CsvExporter {
    protected function ddlDir(): string
    {
        return '/does/not/exist/';
    }
};
$fk = $openZip($noKit->writeZip($m->transformer()->run()));
test('FR-011-09', 'if the load kit could not be included, the report says so and does not point to carga_cdm or cargar.sh',
    strpos($fk['INFORME.txt'], 'RECOMMENDED NEXT STEP') !== false
    && strpos($fk['INFORME.txt'], 'load kit could not be included') !== false
    && strpos($fk['INFORME.txt'], 'carga_cdm') === false && strpos($fk['INFORME.txt'], 'cargar.sh') === false);

// In Spanish.
reset_fixture();
\ExternalModules\AbstractExternalModule::$lang = $spanish;
$m = make_module(['reserved-language-project' => 'Spanish']);
curate($m);
$inf = $export($m)['INFORME.txt'];
test('FR-011-06', 'with the project in Spanish the report comes out in Spanish',
    strpos($inf, 'INFORME DE TRANSFORMACIÓN A OMOP CDM v5.4') === 0 && strpos($inf, 'REGISTROS PROCESADOS: 3') !== false
    && strpos($inf, 'FILAS POR TABLA') !== false && strpos($inf, 'TRANSFORMATION REPORT') === false);
test('FR-011-07', 'in Spanish: values not emitted by design and values with an operator',
    strpos($inf, 'VALORES NO EMITIDOS A PROPÓSITO: 2') !== false && strpos($inf, 'VALORES CON OPERADOR') !== false);
test('FR-011-09', 'in Spanish: identifiers, next step and personal data notice',
    strpos($inf, 'IDENTIFICADORES') !== false && strpos($inf, 'SIGUIENTE PASO RECOMENDADO') !== false
    && strpos($inf, 'AVISO SOBRE DATOS PERSONALES') !== false);
\ExternalModules\AbstractExternalModule::$lang = $english;

// ---------------------------------------------------------------------------
section('The mapping travels with the data');

reset_fixture();
$m = make_module();
curate($m);
$f = $export($m);
test('FR-011-10', 'the ZIP carries the mapping document in HTML and JSON',
    isset($f['MAPEO.html'], $f['MAPEO.json']) && strpos($f['MAPEO.html'], '<!DOCTYPE html>') === 0
    && isset(json_decode($f['MAPEO.json'], true)['fields']['weight']));

$broken = new class extends \OmopCdm\OmopCdmExportModule\OmopCdmExportModule {
    public function mappingDocument(): \OmopCdm\OmopCdmExportModule\MappingDocument
    {
        throw new \RuntimeException('broken document');
    }
};
$broken->settings = make_module()->settings;
curate($broken);
$f = $export($broken);
test('FR-011-10', 'if the document fails, the data export goes ahead without it',
    isset($f['PERSON.csv'], $f['INFORME.txt']) && !isset($f['MAPEO.html']));

$tmp = sys_get_temp_dir() . '/omop_minizip_' . uniqid();
mkdir($tmp . '/sub', 0700, true);
file_put_contents($tmp . '/A.csv', "a,b\n1,2\n");
file_put_contents($tmp . '/sub/x.sql', 'select 1;');
\OmopCdm\OmopCdmExportModule\MiniZip::create($tmp . '.zip', [$tmp . '/A.csv' => 'A.csv', $tmp . '/sub/x.sql' => 'sub/x.sql']);
$z = new \ZipArchive();
$ok = $z->open($tmp . '.zip') === true && $z->getFromName('A.csv') === "a,b\n1,2\n" && $z->getFromName('sub/x.sql') === 'select 1;';
$z->close();
unlink($tmp . '/A.csv');
unlink($tmp . '/sub/x.sql');
rmdir($tmp . '/sub');
rmdir($tmp);
unlink($tmp . '.zip');
test('NFR-011-02', 'without the zip extension, the built-in writer produces a standard ZIP, with folders', $ok);

// ---------------------------------------------------------------------------
section('Mapping document: data and JSON');

reset_fixture();
\REDCap::$dict['notes']['field_label'] = 'Notas clínicas';
$m = make_module();
curate($m);
$doc = $m->mappingDocument();
$d = $doc->build();
test('FR-011-11', 'the document includes the configuration: source, person, death, visits and project',
    $d['cdm_source']['name'] === 'Test' && $d['person']['birth_date_field'] === 'dob'
    && $d['death']['date_field'] === 'death_date' && $d['visits']['enabled'] === true
    && $d['project']['record_id_field'] === 'record_id' && $d['cdm_version'] === 'v5.4');
test('FR-011-11', 'the summary counts fields per state and, per domain, only the approved ones',
    $d['summary']['total'] === 18 && $d['summary']['by_state']['approved'] === 9
    && $d['summary']['by_domain']['Measurement'] === 3 && !isset($d['summary']['by_domain']['NotMapped']));
$w = $d['fields']['weight'];
test('FR-011-11', 'each field carries concept, table, unit, date and who approved it and when',
    (string) $w['concept_id'] === '3025315' && $w['concept_name'] === 'Body weight' && $w['cdm_table'] === 'measurement'
    && $w['unit_source_value'] === 'kg' && $w['date_field'] === 'visit_date'
    && $w['approved_by'] === $who && !empty($w['approved_at']) && is_array($w['warnings']));
$op = array_column($d['fields']['comorb']['choices'], 'emit', 'raw_value');
test('FR-011-11', 'each choice says whether it produces a row (including the one that does not emit)',
    $op === ['1' => true, '2' => true, '3' => false]);
$op = array_column($d['fields']['exitus']['choices'], 'emit', 'raw_value');
test('FR-011-11', 'a choice with no saved decision reflects the default: the «No» does not emit',
    $op === ['1' => true, '0' => false]);
test('FR-011-11', 'the JSON is the same structure, readable and with accents unescaped',
    json_decode($doc->json(), true)['fields'] == $d['fields']
    && strpos($doc->json(), "\n    \"module\"") !== false && strpos($doc->json(), '"Notas clínicas"') !== false);

// ---------------------------------------------------------------------------
section('Mapping document: HTML');

\REDCap::$dict['weight']['field_label'] = 'Body <b>weight</b> & "net"';
$m = make_module();
curate($m);
$m->store()->updateField('weight', ['notes' => '<i>review</i> & check', 'state' => 'approved'],
    \REDCap::$dict['weight'], []);
$m->invalidateMappingCache();
$html = $m->mappingDocument()->html();
$external = false;
foreach (['<script', '<link', 'src=', 'url(', 'http://', 'https://', '@import'] as $x) {
    $external = $external || stripos($html, $x) !== false;
}
test('FR-011-12', 'the HTML is self-contained: inline styles, no scripts or external resources',
    strpos($html, '<!DOCTYPE html>') === 0 && strpos($html, '<style>') !== false && !$external);
test('FR-011-12', 'the HTML escapes the project texts',
    strpos($html, 'Body weight &amp; &quot;net&quot;') !== false
    && strpos($html, '&lt;i&gt;review&lt;/i&gt; &amp; check') !== false && strpos($html, '<i>review') === false);
test('FR-011-12', 'the HTML declares the project language', strpos($html, '<html lang="en">') !== false);
test('FR-011-13', 'the HTML shows the configuration and the summary',
    strpos($html, 'Source and configuration') !== false && strpos($html, 'Summary') !== false
    && strpos($html, 'Measurement: <b>3</b>') !== false);
test('FR-011-13', 'each exported field shows its concept, unit, reference date and approver',
    strpos($html, 'Fields exported to the CDM') !== false && strpos($html, '<code>3025315</code> Body weight') !== false
    && strpos($html, 'kg') !== false && strpos($html, '<code>visit_date</code>') !== false
    && strpos($html, htmlspecialchars($who)) !== false);
test('FR-011-13', 'choices that produce no row are flagged',
    strpos($html, '<li class="no-row">None — emits no row</li>') !== false);
test('FR-011-13', 'fields not exported are listed with their reason',
    strpos($html, 'Fields not exported') !== false && strpos($html, '<code>hr</code>') !== false
    && strpos($html, 'Record identifier: exported as person_source_value') !== false);
\ExternalModules\AbstractExternalModule::$lang = $spanish;
$m = make_module(['reserved-language-project' => 'Spanish']);
curate($m);
$html = $m->mappingDocument()->html();
test('FR-011-12', 'with the project in Spanish, the HTML comes out in Spanish', strpos($html, '<html lang="es">') !== false);
\ExternalModules\AbstractExternalModule::$lang = $english;

// ---------------------------------------------------------------------------
section('Preview');

reset_fixture();
for ($i = 4; $i <= 25; $i++) {
    \REDCap::$data[(string) $i] = rec((string) $i, ['dob' => '1990-01-01', 'sex' => '1', 'visit_date' => '2024-01-01', 'weight' => '60']);
}
$m = make_module();
curate($m);
$prev = $m->transformer()->run(20, true);
test('FR-011-14', 'the preview processes at most 20 records',
    $prev['stats']['records'] === 20 && count($prev['tables']['person']) === 20);
test('FR-011-14', 'the preview does not consume identifiers', $m->getProjectSetting('omop-id-registry') === null);
$full = $m->transformer()->run();
test('FR-011-14', 'the real export processes all records and saves the identifiers',
    $full['stats']['records'] === 25 && $m->getProjectSetting('omop-id-registry') !== null);
