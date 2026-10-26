<?php
// Spec 002 — OMOP CDM v5.4 model
use OmopCdm\OmopCdmExportModule\{CdmModel, OmopCdmExportModule};

section('Tables against the official OHDSI specification');
$res = dirname(__DIR__, 2) . '/resources';
$fh = fopen($res . '/OMOP_CDMv5.4_Field_Level.csv', 'r');
$hdr = fgetcsv($fh, 0, ',', '"', '');
$ref = [];
while ($r = fgetcsv($fh, 0, ',', '"', '')) {
    $r = array_combine($hdr, array_slice(array_pad($r, count($hdr), ''), 0, count($hdr)));
    $ref[strtolower($r['cdmTableName'])][] = $r;
}
fclose($fh);

$expected = ['person', 'observation_period', 'visit_occurrence', 'condition_occurrence', 'drug_exposure',
    'procedure_occurrence', 'measurement', 'observation', 'device_exposure', 'specimen', 'death', 'note',
    'provider', 'source_to_concept_map', 'location', 'care_site', 'cdm_source'];
test('FR-002-01', 'the model defines exactly the 17 tables the module generates', array_keys(CdmModel::tables()) === $expected);
test('FR-002-01', 'every table in the model exists in the official specification',
    array_diff($expected, array_keys($ref)) === []);

foreach (CdmModel::tables() as $t => $cols) {
    $rc = array_map(fn($r) => strtolower($r['cdmFieldName']), $ref[$t] ?? []);
    test('FR-002-02', "$t: DDL columns and order", $rc === $cols);
}
test('FR-002-03', 'REQUIRED covers the 17 tables of the model', array_diff($expected, array_keys(CdmModel::REQUIRED)) === []
    && count(CdmModel::REQUIRED) === 17);
foreach (CdmModel::tables() as $t => $cols) {
    $req = array_values(array_map(fn($r) => strtolower($r['cdmFieldName']),
        array_filter($ref[$t] ?? [], fn($r) => strtoupper($r['isRequired']) === 'TRUE')));
    $mod = CdmModel::REQUIRED[$t] ?? null;
    test('FR-002-03', "$t: NOT NULL columns", is_array($mod) && array_diff($req, $mod) === [] && array_diff($mod, $req) === []);
}

section('Access to columns and rows');
test('FR-002-04', 'columns() returns the columns of a table', CdmModel::columns('death')[1] === 'death_date');
test('FR-002-04', 'columns() of an unknown table is an empty list', CdmModel::columns('visit_detail') === []);
$row = CdmModel::emptyRow('death');
test('FR-002-04', 'emptyRow() gives every column in order, set to null',
    array_keys($row) === CdmModel::columns('death') && array_filter($row, fn($v) => $v !== null) === []);

section('Domains');
test('FR-002-05', 'every OMOP domain has its table', CdmModel::tableForDomain('Measurement') === 'measurement'
    && CdmModel::tableForDomain('Drug') === 'drug_exposure' && CdmModel::tableForDomain('Visit') === 'visit_occurrence'
    && CdmModel::tableForDomain('Note') === 'note' && count(CdmModel::DOMAIN_TABLE) === 11);
test('FR-002-05', 'DateAnchor, NotMapped and unknown domains have no table',
    CdmModel::tableForDomain('DateAnchor') === null && CdmModel::tableForDomain('NotMapped') === null
    && CdmModel::tableForDomain('Episode') === null);
test('FR-002-05', 'every domain table exists in the model', array_diff(CdmModel::DOMAIN_TABLE, array_keys(CdmModel::tables())) === []);
test('FR-002-06', 'valid domains: the 11 OMOP ones plus DateAnchor and NotMapped',
    count(CdmModel::validDomains()) === 13
    && array_diff(array_keys(CdmModel::DOMAIN_TABLE), CdmModel::validDomains()) === []
    && in_array('DateAnchor', CdmModel::validDomains(), true) && in_array('NotMapped', CdmModel::validDomains(), true));
test('FR-002-06', 'the module accepts exactly the model\'s domains', OmopCdmExportModule::validDomains() === CdmModel::validDomains());

section('Required columns and lengths');
$missing = CdmModel::missingRequired([
    'death'    => [['person_id' => 1, 'death_date' => ''], ['person_id' => null, 'death_date' => '2020-01-01'],
                   ['person_id' => 2, 'death_date' => null]],
    'location' => [['location_id' => null]],
    'person'   => [['person_id' => 1, 'gender_concept_id' => 8507, 'year_of_birth' => 1960,
                    'race_concept_id' => 0, 'ethnicity_concept_id' => 0]],
]);
test('FR-002-07', 'missingRequired counts, per column, the rows with null or an empty string',
    $missing === ['death' => ['person_id' => 1, 'death_date' => 2], 'location' => ['location_id' => 1]]);
test('FR-002-07', 'an unknown table is not checked', CdmModel::missingRequired(['visit_detail' => [['x' => null]]]) === []);
test('FR-002-07', '0 counts as a value: a complete person does not appear', !isset($missing['person']));

$types = [];
foreach ($ref as $t => $rows) {
    foreach ($rows as $r) {
        $types[$t][strtolower($r['cdmFieldName'])] = strtolower($r['cdmDatatype']);
    }
}
$wrong = [];
foreach (CdmModel::TEXT_LIMITS as $col => $lim) {
    foreach (CdmModel::tables() as $t => $cols) {
        if (in_array($col, $cols, true)) {
            $expectedType = $lim === null ? 'varchar(max)' : "varchar($lim)";
            if (($types[$t][$col] ?? '') !== $expectedType) {
                $wrong[] = "$t.$col";
            }
        }
    }
}
test('FR-002-08', 'every text limit matches the official varchar(n) in the model\'s tables', $wrong === [], implode(',', $wrong));
test('FR-002-08', 'sig has no limit (varchar(MAX))', array_key_exists('sig', CdmModel::TEXT_LIMITS) && CdmModel::TEXT_LIMITS['sig'] === null);
test('FR-002-09', 'fit() truncates to the limit counting characters, not bytes', CdmModel::fit('stop_reason', str_repeat('á', 30)) === str_repeat('á', 20));
test('FR-002-09', 'fit() leaves null, the empty string and columns without a limit untouched',
    CdmModel::fit('note_text', null) === null && CdmModel::fit('stop_reason', '') === ''
    && CdmModel::fit('sig', str_repeat('a', 300)) === str_repeat('a', 300)
    && CdmModel::fit('measurement_source_value', 'short') === 'short');

section('Operators, units and fixed concepts');
test('FR-002-10', 'the five OMOP operators with their concept_id', CdmModel::OPERATORS === [
    '<' => 4171756, '<=' => 4171754, '=' => 4172703, '>=' => 4171755, '>' => 4172704]);
test('FR-002-11', 'eight safe UCUM units with their concept_id', CdmModel::UCUM_BUILTIN === [
    'kg' => 9529, 'cm' => 8582, 'mm[Hg]' => 8876, '%' => 8554, 'g/dL' => 8713, 'mg/dL' => 8840,
    'mmol/L' => 8753, 'umol/L' => 8749]);
test('FR-002-12', 'ucumCode translates the form spelling into the UCUM code',
    CdmModel::ucumCode('mmHg') === 'mm[Hg]' && CdmModel::ucumCode('µmol/l') === 'umol/L'
    && CdmModel::ucumCode('lpm') === '{beats}/min' && CdmModel::ucumCode('bpm') === '{beats}/min'
    && CdmModel::ucumCode('°C') === 'Cel' && CdmModel::ucumCode('kg/m²') === 'kg/m2'
    && CdmModel::ucumCode('mg/dl') === 'mg/dL' && CdmModel::ucumCode('copias/mL') === '{copies}/mL');
test('FR-002-12', 'ucumCode ignores case and spaces', CdmModel::ucumCode(' KG ') === 'kg' && CdmModel::ucumCode('mg / dL') === 'mg/dL');
test('FR-002-12', 'an unknown, empty or null unit has no code', CdmModel::ucumCode('xyz') === null
    && CdmModel::ucumCode('') === null && CdmModel::ucumCode(null) === null);
test('FR-002-13', 'gender concepts and «no matching concept»', CdmModel::GENDER_MALE === 8507 && CdmModel::GENDER_FEMALE === 8532
    && CdmModel::GENDER_UNKNOWN === 8551 && CdmModel::CONCEPT_NO_MATCH === 0);

section('Reference files');
$notice = (string) @file_get_contents($res . '/NOTICE.txt');
test('FR-002-14', 'NOTICE declares origin, author and the Apache 2.0 licence', strpos($notice, 'OHDSI/CommonDataModel') !== false
    && strpos($notice, 'Observational Health Data Sciences and Informatics') !== false && strpos($notice, 'Apache License 2.0') !== false);
preg_match_all('#^resources/\S+#m', $notice, $listed);
test('FR-002-14', 'every third-party file listed in NOTICE exists',
    count($listed[0]) >= 4 && array_filter($listed[0], fn($p) => !is_file(dirname($res) . '/' . $p)) === []);
test('FR-002-14', 'the official file has the columns of the OHDSI specification',
    array_slice($hdr, 0, 4) === ['cdmTableName', 'cdmFieldName', 'isRequired', 'cdmDatatype'] && count($ref) >= 39);

section('Non-functional requirements');
$src = (string) file_get_contents(dirname($res) . '/classes/CdmModel.php');
test('NFR-002-01', 'CdmModel depends neither on REDCap nor on the module', strpos($src, '\\REDCap') === false
    && strpos($src, 'ExternalModules') === false
    && strpos($src, '$this') === false && strpos($src, 'getProjectSetting') === false);
test('NFR-002-02', 'the model does not read the official file at run time and its documentation does not refer to phases',
    strpos($src, 'fopen') === false && strpos($src, 'file_get_contents') === false
    && !preg_match('/\b(Fase|Phase)\b/u', $src));
