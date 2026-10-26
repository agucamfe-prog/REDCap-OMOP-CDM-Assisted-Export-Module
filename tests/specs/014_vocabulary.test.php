<?php
// Spec 014 — Vocabulary: validation and suggestions
use OmopCdm\OmopCdmExportModule\{Vocabulary, MappingAdvisor, TextNormalizer};

$root    = dirname(__DIR__, 2);
$concept = dirname(__DIR__) . '/fixtures/vocab/CONCEPT.csv';
$tmp     = sys_get_temp_dir() . '/omop_test_vocab_' . getmypid() . '.sqlite';
$tmpCli  = sys_get_temp_dir() . '/omop_test_vocab_cli_' . getmypid() . '.sqlite';
$badCsv  = sys_get_temp_dir() . '/omop_test_concept_bad_' . getmypid() . '.csv';
@unlink($tmp);
@unlink($tmpCli);
$codes = fn(array $w) => array_column($w, 'code');

section('Local index from CONCEPT.csv');
$n = Vocabulary::buildIndex($concept, $tmp);
test('FR-014-01', 'buildIndex indexes the 51 rows of the test CONCEPT.csv', $n === 51, (string) $n);
$pdo = new PDO('sqlite:' . $tmp);
$tables = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
test('FR-014-01', 'the index has the concept table and the FTS5 table concept_fts',
    in_array('concept', $tables, true) && in_array('concept_fts', $tables, true), implode(',', $tables));
$fts = (string) $pdo->query('SELECT name FROM concept_fts WHERE rowid = 3000963')->fetchColumn();
test('FR-014-01', 'the indexed text is normalised (lower case, no punctuation)', $fts === 'hemoglobin mass volume in blood', $fts);
$pdo = null;
test('FR-014-01', 'no temporary .tmp file is left behind when it finishes', !is_file($tmp . '.tmp'));
file_put_contents($badCsv, "concept_id\tconcept_name\tdomain_id\n1\tX\tCondition\n");
test('FR-014-01', 'a CONCEPT.csv without the required columns is rejected with an exception',
    throws(fn() => Vocabulary::buildIndex($badCsv, $tmp), \RuntimeException::class));
test('FR-014-01', 'a file that cannot be read is rejected with an exception',
    throws(fn() => @Vocabulary::buildIndex('/no/such/CONCEPT.csv', $tmp), \RuntimeException::class));
test('FR-014-01', 'if the build fails, the previous good index stays intact',
    (new Vocabulary($tmp, null))->lookup([3025315]) !== null && isset((new Vocabulary($tmp, null))->lookup([3025315])[3025315]));
@unlink($badCsv);
@unlink($tmp . '.tmp');

$cli = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/resources/build_vocab_index.php');
exec($cli . ' ' . escapeshellarg($concept) . ' ' . escapeshellarg($tmpCli) . ' 2>/dev/null', $output, $rc);
test('FR-014-01', 'build_vocab_index.php builds the index from the command line',
    $rc === 0 && is_file($tmpCli) && strpos(implode("\n", $output), '51 concepts indexed') !== false, implode(' ', $output));
exec($cli . ' 2>/dev/null', $output2, $rc2);
test('FR-014-01', 'build_vocab_index.php without arguments explains its usage and exits with code 2', $rc2 === 2, (string) $rc2);
@unlink($tmpCli);

section('Vocabulary sources');
test('FR-014-02', 'with no index and no WebAPI there is no vocabulary configured', !(new Vocabulary(null, null))->configured());
test('FR-014-02', 'with a local index the source is sqlite:<file>', (new Vocabulary($tmp, null))->source() === 'sqlite:' . basename($tmp));
$wa = new Vocabulary(null, 'https://atlas.example.org/WebAPI/', 'tok');
test('FR-014-02', 'with a WebAPI the source is webapi:<url> without the trailing slash',
    $wa->configured() && $wa->source() === 'webapi:https://atlas.example.org/WebAPI', $wa->source());
test('FR-014-02', 'if the local index exists, it takes precedence over the WebAPI',
    (new Vocabulary($tmp, 'https://atlas.example.org/WebAPI'))->source() === 'sqlite:' . basename($tmp));
test('FR-014-02', 'the module without vocabulary system settings has no vocabulary', make_module()->vocabulary() === null);
$mv = make_module();
$mv->system = ['vocab-sqlite-path' => $tmp];
test('FR-014-02', 'the module reads the index path from the system settings',
    $mv->vocabulary() !== null && $mv->vocabulary()->source() === 'sqlite:' . basename($tmp));

section('Concept lookup');
$v = new Vocabulary($tmp, null);
$l = $v->lookup([3025315, 999]);
test('FR-014-04', 'lookup: returns the concept that exists and omits the one that does not',
    isset($l[3025315]) && $l[3025315]['name'] === 'Body weight' && !isset($l[999]));
test('FR-014-04', 'lookup: each concept carries id, name, domain, vocabulary, class, standard, code and invalid reason',
    array_keys($l[3025315]) === ['id', 'name', 'domain', 'vocabulary', 'class', 'standard', 'code', 'invalid']
    && $l[3025315]['vocabulary'] === 'LOINC' && $l[3025315]['code'] === '29463-7' && $l[3025315]['standard'] === 'S');
test('FR-014-04', 'lookup: ignores non-positive identifiers and an empty list gives []',
    $v->lookup([0, -5]) === [] && $v->lookup([]) === []);

section('Search for standard concepts');
$sr = $v->search('body weight', 'Measurement');
test('FR-014-05', 'search for standard concepts by domain', $sr && $sr[0]['id'] === 3025315);
$ids = array_column($v->search('diabetes', 'Condition') ?? [], 'id');
test('FR-014-05', 'only returns standard concepts: the ICD10CM code does not appear', in_array(201820, $ids, true) && !in_array(45576876, $ids, true), implode(',', $ids));
test('FR-014-05', 'never returns retired concepts', $v->search('retired test') === []);
test('FR-014-05', 'the domain filter leaves out concepts of other domains',
    $v->search('weight', 'Condition') !== [] && !array_diff(array_column($v->search('weight', 'Condition'), 'domain'), ['Condition']));
test('FR-014-05', 'an empty query returns an empty list', $v->search('   ') === []);
test('FR-014-05', 'respects the result limit', count($v->search('serum plasma', null, 2)) === 2);

section('UCUM and unit_concept_id');
test('FR-014-06', 'UCUM by code', ($v->ucum('mg/L')['id'] ?? null) === 8751);
test('FR-014-06', 'a UCUM code that is not in the vocabulary gives null', $v->ucum('furlong/fortnight') === null);
$m = make_module();
$m->setVocabulary($v);
curate($m);
test('FR-014-06', 'unit_concept_id from the vocabulary', $m->unitConcept('mg/L') === 8751);
test('FR-014-06', 'the unit written in the form is normalised to UCUM before looking it up', $m->unitConcept('mmHg') === 8876);
$noVocab = make_module();
test('FR-014-06', 'without a vocabulary, only the units of the safe built-in table',
    $noVocab->unitConcept('kg') === 9529 && $noVocab->unitConcept('mg/L') === null && $noVocab->unitConcept('') === null);
$meas = array_values(array_filter($m->transformer()->run()['tables']['measurement'],
    fn($r) => $r['measurement_concept_id'] === 3004501 && $r['value_as_number'] !== null));
test('FR-014-06', 'measurements with a number carry the unit_concept_id of their unit (mg/dL → 8840)',
    $meas && $meas[0]['unit_concept_id'] === 8840, json_encode($meas[0] ?? null));

section('Concept validation in the advisor');
$s = $m->store();
$s->updateField('weight', ['concept_id' => '999', 'concept_name' => 'Body weight']);
$adv = $m->advise('weight');
test('FR-014-07', 'a non-existent concept_id warns without blocking (the vocabulary may be partial)',
    in_array('concept_not_found', $codes($adv), true) && !MappingAdvisor::blocks($adv));
$nf = array_values(array_filter($adv, fn($w) => $w['code'] === 'concept_not_found'));
test('FR-014-07', 'the concept_not_found warning has severity warn and requires confirmation',
    ($nf[0]['severity'] ?? '') === 'warn' && MappingAdvisor::needsConfirm($adv));
$s->updateField('weight', ['concept_id' => '3025315', 'concept_name' => 'Body wieght']);
test('FR-014-08', 'name that does not match the number', in_array('concept_name_mismatch', $codes($m->advise('weight')), true),
    implode(',', $codes($m->advise('weight'))));
$s->updateField('weight', ['concept_name' => 'body weight']);
test('FR-014-08', 'case and punctuation do not count as a name mismatch', !in_array('concept_name_mismatch', $codes($m->advise('weight')), true));
$s->updateField('weight', ['concept_id' => '45576876', 'concept_name' => 'Type 2 diabetes mellitus without complications']);
$c = $codes($m->advise('weight'));
test('FR-014-08', 'non-standard concept of another domain', in_array('concept_not_standard', $c, true) && in_array('concept_wrong_domain', $c, true), implode(',', $c));
$s->updateField('weight', ['concept_id' => '4000001', 'concept_name' => 'Retired test concept']);
test('FR-014-08', 'retired concept', in_array('concept_invalid', $codes($m->advise('weight')), true));
test('FR-014-08', 'none of the concept warnings blocks approval', !MappingAdvisor::blocks($m->advise('weight')));
$s->updateValue('sex', '1', ['concept_id' => '3025315', 'concept_name' => 'Body weight'], 'Male', \REDCap::$dict['sex']);
test('FR-014-08', 'in the sex field, the concept of each option must belong to the Gender domain',
    in_array('concept_wrong_domain', $codes($m->advise('sex')), true));
test('FR-014-08', 'with "value as concept" the options (Meas Value) are not required to be of the field\'s domain',
    !in_array('concept_wrong_domain', $codes($m->advise('bac')), true), implode(',', $codes($m->advise('bac'))));

section('If the vocabulary does not respond, nothing is blocked');
$dead = new Vocabulary('/no/such/omop.sqlite', null);
test('FR-014-09', 'a configured vocabulary that does not respond returns null from lookup and search',
    $dead->configured() && $dead->lookup([3025315]) === null && $dead->search('weight') === null);
$down = new Vocabulary(null, 'http://127.0.0.1:9');
test('FR-014-09', 'an unreachable WebAPI returns null and leaves the reason in lastError()',
    $down->lookup([3025315]) === null && (string) $down->lastError() !== '', (string) $down->lastError());
$md = make_module();
$md->setVocabulary($dead);
curate($md);
$adv = $md->advise('weight');
test('FR-014-09', 'the advisor reports vocab_unavailable (info) and does not block',
    in_array('vocab_unavailable', $codes($adv), true) && !MappingAdvisor::blocks($adv)
    && array_column($adv, 'severity', 'code')['vocab_unavailable'] === 'info', implode(',', $codes($adv)));
// A vocabulary that is down must not check less than having no vocabulary:
// the shape check of the name (spec 008) still applies.
$withoutVocab = make_module();
curate($withoutVocab);
foreach ([$md, $withoutVocab] as $x) {
    $x->store()->updateField('weight', ['concept_name' => '¿Cuánto pesa el paciente hoy en la consulta?']);
    $x->store()->updateField('glu', ['concept_name' => '']);
}
$shapeDown = array_values(array_diff($codes($md->advise('weight')), ['vocab_unavailable']));
$shapeNone = $codes($withoutVocab->advise('weight'));
test('FR-014-09', 'with the vocabulary down it still warns about a pasted name that looks like the label (concept_name_is_label)',
    in_array('concept_name_is_label', $shapeDown, true), implode(',', $shapeDown));
test('FR-014-09', 'with the vocabulary down it still warns about a concept without a name (no_concept_name)',
    in_array('no_concept_name', $codes($md->advise('glu')), true), implode(',', $codes($md->advise('glu'))));
test('FR-014-09', 'vocabulary down = same warnings as without a vocabulary, plus vocab_unavailable',
    $shapeDown === $shapeNone && in_array('vocab_unavailable', $codes($md->advise('weight')), true),
    implode(',', $shapeDown) . ' / ' . implode(',', $shapeNone));
test('FR-014-09', 'the unit falls back to the built-in table', $md->unitConcept('kg') === 9529);
$run = $md->transformer()->run();
test('FR-014-09', 'the export goes on: no SOURCE_TO_CONCEPT_MAP rows, counted as unknown',
    $run['tables']['source_to_concept_map'] === [] && ($run['stats']['stcm_unknown_concept'] ?? 0) > 0 && count($run['tables']['measurement']) > 0);

section('SOURCE_TO_CONCEPT_MAP');
$m = make_module();
$m->setVocabulary($v);
curate($m);
$stcm = $m->transformer()->run()['tables']['source_to_concept_map'];
test('FR-014-10', 'SOURCE_TO_CONCEPT_MAP with a vocabulary', count($stcm) > 5 && in_array('LOINC', array_column($stcm, 'target_vocabulary_id'), true));
$byCode = array_column($stcm, null, 'source_code');
test('FR-014-10', 'one row per field with a concept: source vocabulary REDCAP_P<pid> and target from the vocabulary',
    ($byCode['weight']['target_concept_id'] ?? 0) === 3025315 && $byCode['weight']['source_vocabulary_id'] === 'REDCAP_P1'
    && $byCode['weight']['target_vocabulary_id'] === 'LOINC' && $byCode['weight']['valid_end_date'] === '2099-12-31');
test('FR-014-10', 'one row per emitted option (field=code), with its label in the description',
    ($byCode['drug=1']['target_concept_id'] ?? 0) === 1782521 && $byCode['drug=1']['target_vocabulary_id'] === 'RxNorm'
    && $byCode['drug=1']['source_code_description'] === 'Drug: Isoniazid');
test('FR-014-10', 'an option that does not emit produces no row', !isset($byCode['comorb=3']));
$x = make_module();
curate($x);
test('FR-014-10', 'without a vocabulary the table is not generated', $x->transformer()->run()['tables']['source_to_concept_map'] === []);

section('Suggestions (the editor\'s magic wand)');
$q = $m->athenaQuery('Weight (kg)', 'weight');
test('FR-014-11', 'the label "Weight (kg)" suggests Body weight in Measurement',
    ($v->search($q, $m->athenaDomain('Measurement'), 8)[0]['id'] ?? 0) === 3025315, $q);
$q = $m->athenaQuery('Frecuencia cardiaca (lpm)', 'fc');
test('FR-014-11', 'a Spanish label is translated before searching ("Frecuencia cardiaca" → Heart rate)',
    ($v->search($q, 'Measurement', 8)[0]['id'] ?? 0) === 3027018, $q);
$q = $m->athenaQuery('Haemoglobin (g/dL)', 'hb', 'g/dL');
test('FR-014-11', 'with the field\'s unit, the LOINC property narrows the search (g/dL → Hemoglobin [Mass/volume])',
    ($v->search($q, 'Measurement', 8)[0]['id'] ?? 0) === 3000963, $q);
test('FR-014-11', 'options with "value as concept" are searched in Meas Value',
    ($v->search($m->athenaQuery('Positive', 'bac'), 'Meas Value', 8)[0]['id'] ?? 0) === 9191);

section('Relaxed search and British spelling');
test('FR-014-14', 'relaxed search: "fasting glucose" finds glucose', ($v->search('fasting glucose', 'Measurement')[0]['id'] ?? 0) === 3004501);
test('FR-014-14', 'if every word appears, the search is not relaxed: "weight loss" gives only Weight loss',
    array_column($v->search('weight loss') ?? [], 'id') === [4229881]);
test('FR-014-14', 'if no word appears, the list stays empty', $v->search('zzzz yyyy') === []);
test('FR-014-15', 'British → American spelling', strtolower(TextNormalizer::americanize('Haemoglobin, anaemia, oedema')) === 'hemoglobin, anemia, edema');
test('FR-014-15', 'search in British spelling', in_array(3000963, array_column($v->search('haemoglobin', 'Measurement') ?? [], 'id'), true));
test('FR-014-15', 'the suggestion query also comes out in American spelling', strpos($m->athenaQuery('Paediatric haemoglobin', 'x'), 'haem') === false);

@unlink($tmp);
