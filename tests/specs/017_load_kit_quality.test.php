<?php
// Spec 017 — Load kit and quality control

$root = dirname(__DIR__, 2);
$ddlDir = $root . '/resources/ddl/postgresql/';
$spanish = parse_ini_file($root . '/lang/Spanish.ini');
$english = \ExternalModules\AbstractExternalModule::$lang;

// Exports and returns [name => content] of the ZIP.
$export = function ($m): array {
    $zip = $m->exporter()->writeZip($m->transformer()->run());
    $z = new \ZipArchive();
    $z->open($zip['path']);
    $out = [];
    for ($i = 0; $i < $z->numFiles; $i++) {
        $n = $z->getNameIndex($i);
        $out[$n] = $z->getFromName($n);
    }
    $z->close();
    unlink($zip['path']);
    return $out;
};
// Rows of the Usagi CSV indexed by sourceCode.
$usagi = function (string $s): array {
    $fh = fopen('php://temp', 'w+');
    fwrite($fh, $s);
    rewind($fh);
    $head = fgetcsv($fh, 0, ',', '"', '');
    $rows = [];
    while (($r = fgetcsv($fh, 0, ',', '"', '')) !== false) {
        $rows[$r[0]] = array_combine($head, $r);
    }
    fclose($fh);
    return [$head, $rows];
};

// ---------------------------------------------------------------------------
section('The kit travels in the ZIP');

$m = make_module();
curate($m);
$f = $export($m);
test('FR-017-01', 'the load kit travels in the ZIP, in the carga_cdm folder', !array_diff([
    'carga_cdm/01_ddl.sql', 'carga_cdm/02_carga.sql', 'carga_cdm/03_primary_keys.sql', 'carga_cdm/04_indices.sql',
    'carga_cdm/cargar.sh', 'carga_cdm/dqd.R', 'carga_cdm/LEEME.txt'], array_keys($f)), implode(' ', array_keys($f)));

$official = file_get_contents($ddlDir . 'OMOPCDM_postgresql_5.4_ddl.sql');
test('FR-017-02', 'the DDL is the official OHDSI one, with the schema replaced and preceded by CREATE SCHEMA',
    $f['carga_cdm/01_ddl.sql'] === "-- OHDSI CommonDataModel v5.4 (Apache 2.0). Schema: cdm\n"
        . "CREATE SCHEMA IF NOT EXISTS cdm;\n" . str_replace('@cdmDatabaseSchema', 'cdm', $official));
test('FR-017-02', 'the DDL creates every CDM table in the chosen schema',
    strpos($f['carga_cdm/01_ddl.sql'], 'CREATE TABLE cdm.person') !== false
    && substr_count($f['carga_cdm/01_ddl.sql'], 'CREATE TABLE cdm.') === substr_count($official, 'CREATE TABLE @cdmDatabaseSchema.'));

$loadSql = $f['carga_cdm/02_carga.sql'];
preg_match_all("/^\\\\copy cdm\\.(\\w+) FROM '([A-Z_]+\\.csv)' WITH \\(FORMAT csv, HEADER true, NULL ''\\)$/m", $loadSql, $mm);
$csvs = array_values(array_filter(array_keys($f), fn($n) => preg_match('/^[A-Z_]+\.csv$/', $n)));
sort($csvs);
$copied = $mm[2];
sort($copied);
test('FR-017-04', 'the load stops at the first error', strpos($loadSql, "\\set ON_ERROR_STOP on\n") !== false);
test('FR-017-04', 'the load includes each CSV', strpos($loadSql, "\\copy cdm.note FROM 'NOTE.csv'") !== false);
test('FR-017-04', 'there is one \\copy per exported CSV and no more', $copied === $csvs, implode(' ', $copied));
test('FR-017-04', 'each \\copy goes to the table of the CSV, in lower case',
    array_map(fn($t) => strtoupper($t) . '.csv', $mm[1]) === $mm[2] && strpos($loadSql, 'OBSERVATION.csv') === false);

$officialOk = true;
foreach (['03_primary_keys.sql' => 'OMOPCDM_postgresql_5.4_primary_keys.sql',
          '04_indices.sql' => 'OMOPCDM_postgresql_5.4_indices.sql'] as $dst => $orig) {
    $officialOk = $officialOk && $f['carga_cdm/' . $dst]
        === str_replace('@cdmDatabaseSchema', 'cdm', file_get_contents($ddlDir . $orig));
}
test('FR-017-05', 'the primary keys and indices are the official ones, with the schema replaced', $officialOk);
$leftovers = array_filter(array_keys($f), fn($n) => strpos($n, 'carga_cdm/') === 0 && strpos($f[$n], '@cdmDatabaseSchema') !== false);
test('FR-017-05', 'no unreplaced @cdmDatabaseSchema is left in the kit', !$leftovers, implode(' ', $leftovers));

$sh = $f['carga_cdm/cargar.sh'];
$p = array_map(fn($n) => strpos($sh, "psql -v ON_ERROR_STOP=1 -f carga_cdm/$n"),
    ['01_ddl.sql', '02_carga.sql', '03_primary_keys.sql', '04_indices.sql']);
test('FR-017-06', 'cargar.sh is an sh script that stops on any error and works from the root of the ZIP',
    strpos($sh, "#!/bin/sh\n") === 0 && strpos($sh, "\nset -e\n") !== false && strpos($sh, 'cd "$(dirname "$0")/.."') !== false);
test('FR-017-06', 'cargar.sh runs the DDL, the load, the keys and the indices with psql, in that order',
    !in_array(false, $p, true) && $p[0] < $p[1] && $p[1] < $p[2] && $p[2] < $p[3]);

$r = $f['carga_cdm/dqd.R'];
test('FR-017-07', 'dqd.R runs the Data Quality Dashboard on PostgreSQL with the connection details from the environment',
    strpos($r, 'library(DatabaseConnector)') !== false && strpos($r, 'library(DataQualityDashboard)') !== false
    && strpos($r, 'dbms     = "postgresql"') !== false && strpos($r, 'Sys.getenv("PGHOST"') !== false
    && strpos($r, 'Sys.getenv("PGPASSWORD")') !== false);
test('FR-017-07', 'dqd.R checks the load schema, as CDM 5.4, and leaves the results in a folder',
    strpos($r, 'executeDqChecks(') !== false && strpos($r, 'cdmDatabaseSchema      = "cdm"') !== false
    && strpos($r, 'cdmVersion             = "5.4"') !== false && strpos($r, 'outputFolder           = "dqd_resultados"') !== false);

test('FR-017-08', 'the LEEME explains the load, in the project language, with the schema',
    strpos($f['carga_cdm/LEEME.txt'], 'CDM LOAD KIT') === 0 && strpos($f['carga_cdm/LEEME.txt'], "schema 'cdm'") !== false
    && strpos($f['carga_cdm/LEEME.txt'], 'sh carga_cdm/cargar.sh') !== false);
test('FR-017-09', 'the comments of the load script and of cargar.sh are in the project language',
    strpos($loadSql, '-- Load of the exported CSVs.') === 0 && strpos($sh, '# Creates the schema, loads the CSVs') !== false);
test('FR-017-09', 'in English, neither dqd.R nor cargar.sh nor the DDL header contain Spanish text',
    strpos($r, '# OHDSI Data Quality Dashboard on the CDM loaded with cargar.sh.') === 0
    && strpos($r, '# Vocabulary configured on the REDCap server at export time: FALSE') !== false
    && strpos($sh, "\n# PGHOST, PGPORT, PGUSER, PGDATABASE and PGPASSWORD as in any PostgreSQL client.\n") !== false
    && !preg_match('/Requiere|cargadas|Vocabulario|como en cualquier|Esquema:/u', $r . $sh . strtok($f['carga_cdm/01_ddl.sql'], "\n")));
test('FR-017-07', 'dqd.R uses the name configured for CDM_SOURCE as cdmSourceName',
    strpos($r, 'cdmSourceName          = "Test"') !== false);
$m = make_module(['cdm-source-name' => 'Site "North" \\ 1']);
curate($m);
test('FR-017-07', 'the source name is escaped as an R string',
    strpos($export($m)['carga_cdm/dqd.R'], 'cdmSourceName          = "Site \\"North\\" \\\\ 1"') !== false);

// Without a readable DDL, there is no kit.
reset_fixture();
$m = make_module();
curate($m);
$noKit = new class($m) extends \OmopCdm\OmopCdmExportModule\CsvExporter {
    protected function ddlDir(): string
    {
        return '/does/not/exist/';
    }
};
$zip = $noKit->writeZip($m->transformer()->run());
$z = new \ZipArchive();
$z->open($zip['path']);
$names = [];
for ($i = 0; $i < $z->numFiles; $i++) {
    $names[] = $z->getNameIndex($i);
}
$reportNoKit = $z->getFromName('INFORME.txt');
$z->close();
unlink($zip['path']);
test('FR-017-15', 'if the official DDL cannot be read, the ZIP comes out without carga_cdm/ and with the data',
    in_array('PERSON.csv', $names, true) && !preg_grep('#^carga_cdm/#', $names), implode(' ', $names));
test('FR-017-15', 'if the official DDL cannot be read, the report says the kit was not included',
    strpos($reportNoKit, 'load kit could not be included') !== false && strpos($reportNoKit, 'cargar.sh') === false);

// ---------------------------------------------------------------------------
section('Configurable schema');

reset_fixture();
$m = make_module(['load-schema' => 'Omop_TB']);
curate($m);
$f = $export($m);
test('FR-017-03', 'the configured schema is used in lower case throughout the kit',
    $m->loadSchema() === 'omop_tb' && strpos($f['carga_cdm/01_ddl.sql'], 'CREATE SCHEMA IF NOT EXISTS omop_tb;') !== false
    && strpos($f['carga_cdm/01_ddl.sql'], 'CREATE TABLE omop_tb.person') !== false
    && strpos($f['carga_cdm/02_carga.sql'], "\\copy omop_tb.person FROM 'PERSON.csv'") !== false
    && strpos($f['carga_cdm/03_primary_keys.sql'], 'omop_tb.person') !== false
    && strpos($f['carga_cdm/dqd.R'], 'cdmDatabaseSchema      = "omop_tb"') !== false);
$valid = [];
foreach (['' => 'cdm', 'cdm; DROP TABLE person' => 'cdm', '1abc' => 'cdm', 'omop-cdm' => 'cdm',
          str_repeat('a', 64) => 'cdm', '_cdm2' => '_cdm2'] as $in => $expected) {
    $valid[] = make_module(['load-schema' => $in])->loadSchema() === $expected;
}
test('FR-017-03', 'an empty schema name, or one that is not a valid PostgreSQL identifier, is replaced by cdm',
    !in_array(false, $valid, true));
test('FR-017-03', 'the report names the load schema', strpos($f['INFORME.txt'], '(schema omop_tb)') !== false);

reset_fixture();
\ExternalModules\AbstractExternalModule::$lang = $spanish;
$m = make_module(['reserved-language-project' => 'Spanish', 'load-schema' => 'omop_tb']);
curate($m);
$f = $export($m);
test('FR-017-08', 'with the project in Spanish, the LEEME comes out in Spanish',
    strpos($f['carga_cdm/LEEME.txt'], 'KIT DE CARGA DEL CDM') === 0 && strpos($f['carga_cdm/LEEME.txt'], 'esquema «omop_tb»') !== false);
test('FR-017-09', 'with the project in Spanish, the kit comments come out in Spanish',
    strpos($f['carga_cdm/02_carga.sql'], '-- Carga de los CSV exportados.') === 0
    && strpos($f['carga_cdm/cargar.sh'], '# Crea el esquema, carga los CSV') !== false);
test('FR-017-09', 'with the project in Spanish, dqd.R, cargar.sh and the DDL header come out in Spanish',
    strpos($f['carga_cdm/dqd.R'], '# Data Quality Dashboard de OHDSI sobre el CDM cargado con cargar.sh.') === 0
    && strpos($f['carga_cdm/cargar.sh'], 'como en cualquier cliente de PostgreSQL') !== false
    && strpos($f['carga_cdm/01_ddl.sql'], "-- OHDSI CommonDataModel v5.4 (Apache 2.0). Esquema: omop_tb\n") === 0);
\ExternalModules\AbstractExternalModule::$lang = $english;

// ---------------------------------------------------------------------------
section('Mapping for Usagi');

reset_fixture();
$m = make_module();
curate($m);
$s = $m->store();
$s->updateField('hr', ['domain' => 'Measurement', 'concept_id' => '3027018', 'concept_name' => 'Heart rate',
                       'date_field' => 'visit_date'], \REDCap::$dict['hr'], []);
$s->updateField('notes', ['state' => 'excluded'], \REDCap::$dict['notes'], []);
$m->invalidateMappingCache();
$f = $export($m);
$csvUsagi = $m->mappingDocument()->usagiCsv();
[$head, $rows] = $usagi($csvUsagi);
test('FR-017-10', 'the ZIP carries MAPEO_usagi.csv, identical to the one downloaded from the page',
    isset($f['MAPEO_usagi.csv']) && $f['MAPEO_usagi.csv'] === $csvUsagi);
test('FR-017-10', 'the Usagi CSV has the columns of the Usagi format, in their order', $head === [
    'sourceCode', 'sourceName', 'sourceFrequency', 'sourceAutoAssignedConceptIds', 'matchScore', 'mappingStatus',
    'equivalence', 'statusSetBy', 'statusSetOn', 'conceptId', 'conceptName', 'domainId', 'mappingType', 'comment',
    'createdBy', 'createdOn', 'assignedReviewer']);
test('FR-017-11', 'a field with its own concept is a MAPS_TO row with its code, label, concept and domain',
    ($rows['weight']['mappingType'] ?? '') === 'MAPS_TO' && $rows['weight']['sourceName'] === 'Weight (kg)'
    && $rows['weight']['conceptId'] === '3025315' && $rows['weight']['conceptName'] === 'Body weight'
    && $rows['weight']['domainId'] === 'Measurement');
test('FR-017-11', 'with «value per choice», each answer that emits is a MAPS_TO «field=code» row',
    ($rows['sex=1']['mappingType'] ?? '') === 'MAPS_TO' && $rows['sex=1']['sourceName'] === 'Sex: Male'
    && $rows['sex=1']['conceptId'] === '8507' && isset($rows['comorb=1'], $rows['comorb=2']) && !isset($rows['sex']));
test('FR-017-11', 'with «concept + value», the question is MAPS_TO and each answer MAPS_TO_VALUE',
    ($rows['bac']['mappingType'] ?? '') === 'MAPS_TO' && $rows['bac']['conceptId'] === '3016102'
    && $rows['bac=1']['mappingType'] === 'MAPS_TO_VALUE' && $rows['bac=1']['conceptId'] === '9191'
    && $rows['bac=2']['mappingType'] === 'MAPS_TO_VALUE' && substr_count($csvUsagi, 'MAPS_TO_VALUE') === 2);
test('FR-017-11', 'a choice that produces no row, or has no concept, does not appear',
    !isset($rows['comorb=3']) && !isset($rows['exitus=1']) && !isset($rows['exitus=0']) && !isset($rows['fever=0']));
$w = $m->mappingDocument()->build()['fields']['weight'];
test('FR-017-12', 'approved fields go as APPROVED, with who approved them and when (milliseconds)',
    $rows['weight']['mappingStatus'] === 'APPROVED' && $rows['weight']['statusSetBy'] === (string) $w['approved_by']
    && $rows['weight']['statusSetOn'] === (string) (strtotime($w['approved_at']) * 1000)
    && $rows['weight']['equivalence'] === 'UNREVIEWED');
test('FR-017-12', 'proposed fields with domain and concept go as UNCHECKED',
    ($rows['hr']['mappingStatus'] ?? '') === 'UNCHECKED' && $rows['hr']['conceptId'] === '3027018'
    && $rows['hr']['statusSetOn'] === '0');
test('FR-017-12', 'excluded fields and fields without a domain do not appear',
    !isset($rows['notes']) && !isset($rows['visit_date']) && !isset($rows['record_id']) && !isset($rows['med_start']));

// ---------------------------------------------------------------------------
section('DDL license');

$notice = file_get_contents($root . '/resources/NOTICE.txt');
$allPresent = true;
foreach (['OMOPCDM_postgresql_5.4_ddl.sql', 'OMOPCDM_postgresql_5.4_primary_keys.sql', 'OMOPCDM_postgresql_5.4_indices.sql'] as $n) {
    $allPresent = $allPresent && strpos($notice, 'resources/ddl/postgresql/' . $n) !== false && is_file($ddlDir . $n);
}
test('NFR-017-01', 'the OHDSI files are distributed with their source and license (Apache 2.0) in NOTICE.txt',
    $allPresent && strpos($notice, 'https://github.com/OHDSI/CommonDataModel') !== false
    && strpos($notice, 'Apache License 2.0') !== false);
