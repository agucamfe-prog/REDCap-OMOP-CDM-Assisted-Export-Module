<?php
// Spec 008 — Mapping advisor
use OmopCdm\OmopCdmExportModule\{MappingAdvisor, MappingStore, Vocabulary};

/**
 * Warnings of a field with a mapping stored as is (bypassing the store's
 * validations, so that what arrives imported can be tested too).
 * The field is added to the test data dictionary; $settings overrides settings.
 */
$adv = function (array $meta, array $spec, array $values = [], array $settings = [], ?Vocabulary $vocab = null): array {
    \REDCap::$dict = fixture_dict();
    \REDCap::$dict[$meta['field_name']] = $meta;
    $name = $meta['field_name'];
    $vals = [];
    foreach ($values as $raw => $v) {
        $vals[(string) $raw] = $v + ['raw_value' => (string) $raw, 'source_value' => '', 'emit' => true,
                                     'concept_id' => null, 'concept_name' => null];
    }
    $m = make_module($settings + [MappingStore::SETTING_KEY => json_encode([
        'fields' => [$name => $spec + ['state' => 'proposed', 'edited' => true]],
        'values' => $vals ? [$name => $vals] : [],
    ])]);
    if ($vocab !== null) {
        $m->setVocabulary($vocab);
    }
    return $m->advise($name);
};
$codes = fn(array $w) => array_column($w, 'severity', 'code');
$has = fn(array $w, string $code, string $sev) => ($codes($w)[$code] ?? null) === $sev;
$hasnt = fn(array $w, string $code) => !isset($codes($w)[$code]);

$num   = f('x', 'base', 'text', 'Hemoglobin', '', 'number');
$txt   = f('x', 'base', 'text', 'Comments');
$yesno = f('x', 'base', 'yesno', 'Given');
$meas  = ['domain' => 'Measurement', 'concept_id' => 3000963, 'concept_name' => 'Hemoglobin',
          'unit_source_value' => 'g/dL', 'date_field' => 'visit_date'];

section('Contract');
$w = $adv($num, ['unit_source_value' => null] + $meas);
test('FR-008-01', 'each warning is {severity, code, text} and the text comes from the language file, as plain text',
    $w && array_keys($w[0]) === ['severity', 'code', 'text'] && $w[0]['code'] === 'measurement_no_unit'
    && $w[0]['text'] === html_entity_decode(\ExternalModules\AbstractExternalModule::$lang['adv_measurement_no_unit'], ENT_QUOTES));
test('FR-008-01', 'without a domain, or in NotMapped or DateAnchor, there are no warnings',
    $adv($txt, ['domain' => null]) === [] && $adv($txt, ['domain' => 'NotMapped']) === []
    && $adv($txt, ['domain' => 'DateAnchor']) === []);
$w = $adv(f('x', 'base', 'text', 'Hemoglobin', '', 'number'), ['date_field' => 'med_start'] + $meas);
test('FR-008-01', 'arguments are interpolated unescaped (escaping happens when rendering)',
    strpos($w[0]['text'], 'med_start') !== false && strpos($w[0]['text'], '&') === false);
\REDCap::$dict = fixture_dict();
$m = make_module();
curate($m);
$m->store()->updateField('weight', ['state' => 'excluded']);
$m->store()->updateField('weight', ['unit_source_value' => '']);
$all = $m->adviseAll();
test('FR-008-01', 'the project summary leaves out excluded fields and fields without warnings',
    array_keys($all) === ['glu'] && !isset($all['weight']), implode(',', array_keys($all)));

section('Severities');
$b = [['severity' => 'block', 'code' => 'a', 'text' => '']];
$wn = [['severity' => 'warn', 'code' => 'b', 'text' => '']];
$i = [['severity' => 'info', 'code' => 'c', 'text' => '']];
test('FR-008-02', 'blocks() only with a "block" warning', MappingAdvisor::blocks($b) && !MappingAdvisor::blocks($wn) && !MappingAdvisor::blocks($i));
test('FR-008-02', 'needsConfirm() only with a "warn" warning; "info" asks for nothing',
    MappingAdvisor::needsConfirm($wn) && !MappingAdvisor::needsConfirm($i) && !MappingAdvisor::needsConfirm($b)
    && !MappingAdvisor::blocks([]) && !MappingAdvisor::needsConfirm([]));

section('Blocks: what would produce impossible data');
$w = $adv(f('base_complete', 'base', 'dropdown', 'Complete?', '0, Incomplete | 1, Unverified | 2, Complete'),
    ['domain' => 'Observation', 'concept_id' => 4000000, 'concept_name' => 'Status', 'date_field' => 'visit_date', 'value_strategy' => 'single_concept']);
test('FR-008-03', 'a REDCap *_complete field blocks (redcap_complete_field)', $has($w, 'redcap_complete_field', 'block'));
test('FR-008-03', 'a negative concept_id blocks (concept_negative)', $has($adv($num, ['concept_id' => -3000963] + $meas), 'concept_negative', 'block'));
$w = $adv($num, ['date_field' => null] + $meas, [], ['default-date-field' => '']);
test('FR-008-03', 'without its own date or a default date it blocks (no_date_at_all)', $has($w, 'no_date_at_all', 'block'));
test('FR-008-03', 'a date field that does not exist blocks (date_field_gone)',
    $has($adv($num, ['date_field' => 'gone_date'] + $meas), 'date_field_gone', 'block'));
$drug = f('x', 'meds', 'dropdown', 'Drug name', '1, Isoniazid | 2, Rifampin');
$w = $adv($drug, ['domain' => 'Drug', 'value_strategy' => 'per_choice', 'date_field' => 'med_start', 'end_date_field' => 'gone_end'],
    ['1' => ['concept_id' => 1782521, 'concept_name' => 'isoniazid'], '2' => ['concept_id' => 1763204, 'concept_name' => 'rifampin']]);
test('FR-008-03', 'so does an end date that does not exist', $has($w, 'date_field_gone', 'block'));
$vis = f('x', 'base', 'dropdown', 'Visit type', '1, Outpatient | 2, Inpatient');
$visSpec = ['domain' => 'Visit', 'value_strategy' => 'single_concept', 'concept_id' => 9202, 'concept_name' => 'Outpatient Visit',
            'date_field' => 'visit_date'];
test('FR-008-03', 'a field in Visit with automatic visit generation enabled blocks (visit_conflict)',
    $has($adv($vis, $visSpec), 'visit_conflict', 'block') && $hasnt($adv($vis, $visSpec, [], ['generate-visits' => false]), 'visit_conflict'));

section('Dates');
$w = $adv($num, ['date_field' => null] + $meas);
test('FR-008-04', 'without its own date the default one is used, and it says which (using_default_date)',
    $has($w, 'using_default_date', 'info') && strpos($w[0]['text'] . ($w[1]['text'] ?? ''), 'visit_date') !== false);
test('FR-008-04', 'a date from another instrument warns (date_other_form)', $has($adv($num, ['date_field' => 'med_start'] + $meas), 'date_other_form', 'warn'));
test('FR-008-04', 'a date from the same instrument does not warn', $adv($num, $meas) === []);
test('FR-008-04', 'an end date in a domain without an end date is ignored, and it informs (end_date_ignored)',
    $has($adv($num, ['end_date_field' => 'med_end'] + $meas), 'end_date_ignored', 'info'));
$w = $adv(f('x', 'base', 'yesno', 'Died'), ['domain' => 'Death', 'value_strategy' => 'per_choice'], [], ['default-date-field' => '']);
test('FR-008-04', 'Person and Death skip the date checks', $hasnt($w, 'no_date_at_all') && $hasnt($w, 'using_default_date'));

section('Instrument design');
$obs = ['domain' => 'Observation', 'concept_id' => 4000000, 'concept_name' => 'Finding', 'date_field' => 'visit_date'];
test('FR-008-05', 'date label on text without validation (label_date_no_validation)',
    $has($adv(f('x', 'base', 'text', 'Date of onset'), $obs), 'label_date_no_validation', 'warn'));
test('FR-008-05', 'numeric label on text without validation (label_numeric_no_validation)',
    $has($adv(f('x', 'base', 'text', 'Number of cigarettes'), $obs), 'label_numeric_no_validation', 'warn'));
test('FR-008-05', 'unit in parentheses on text without validation (label_unit_no_validation)',
    $has($adv(f('x', 'base', 'text', 'Ferritin (mg/dL)'), $obs), 'label_unit_no_validation', 'warn'));
$w = $adv(f('x', 'base', 'text', 'Date of count (kg)'), $obs);
test('FR-008-05', 'only the first one that applies is given', $has($w, 'label_date_no_validation', 'warn')
    && $hasnt($w, 'label_numeric_no_validation') && $hasnt($w, 'label_unit_no_validation'));
$w = $adv(f('x', 'base', 'text', 'Number of cigarettes (n)', '', 'integer'), $obs);
test('FR-008-05', 'with validation there is no design warning', $hasnt($w, 'label_numeric_no_validation') && $hasnt($w, 'label_unit_no_validation'));

section('Field content');
test('FR-008-06', 'a field flagged as an identifier warns (identifier)',
    $has($adv(f('x', 'base', 'text', 'Hemoglobin', '', 'number', '', 'y'), $meas), 'identifier', 'warn'));
test('FR-008-06', 'free text warns (free_text), except in Note',
    $has($adv($txt, $obs), 'free_text', 'warn') && $hasnt($adv($txt, ['domain' => 'Note', 'concept_id' => 44814637, 'concept_name' => 'Note', 'date_field' => 'visit_date']), 'free_text'));
$note = ['domain' => 'Note', 'concept_id' => 44814637, 'concept_name' => 'Discharge summary', 'date_field' => 'visit_date'];
test('FR-008-06', 'Note on something that is not text warns (note_not_text)', $has($adv($num, $note), 'note_not_text', 'warn'));
test('FR-008-06', 'Note with free-text suppression enabled warns that nothing will be exported (note_suppressed)',
    $has($adv(f('x', 'base', 'notes', 'Clinical notes'), $note, [], ['suppress-free-text' => true]), 'note_suppressed', 'warn')
    && $adv(f('x', 'base', 'notes', 'Clinical notes'), $note) === []);

section('Yes/no in risky domains');
$dz = ['domain' => 'Drug', 'value_strategy' => 'per_choice', 'date_field' => 'visit_date'];
$yes = ['1' => ['concept_id' => 1782521, 'concept_name' => 'isoniazid'], '0' => ['emit' => false]];
test('FR-008-07', 'a yes/no that does not say what was given, in Drug, warns (vague_yesno)',
    $has($adv(f('x', 'base', 'yesno', 'Medication given'), $dz, $yes), 'vague_yesno', 'warn'));
$w = $adv(f('x', 'base', 'yesno', 'Isoniazid'), $dz, $yes);
test('FR-008-07', 'a specific yes/no in Drug only informs (yesno_in_domain)', $has($w, 'yesno_in_domain', 'info') && $hasnt($w, 'vague_yesno'));
$w = $adv(f('x', 'base', 'radio', 'Procedure performed', '1, Yes | 2, No'), ['domain' => 'Procedure'] + $dz, $yes);
test('FR-008-07', 'a two-option radio with "Yes" also counts as yes/no', $has($w, 'vague_yesno', 'warn'));
$w = $adv(f('x', 'base', 'yesno', 'Fever'), ['domain' => 'Condition'] + $dz, ['1' => ['concept_id' => 437663, 'concept_name' => 'Fever'], '0' => ['emit' => false]]);
test('FR-008-07', 'in Condition a yes/no is normal and does not warn', $hasnt($w, 'vague_yesno') && $hasnt($w, 'yesno_in_domain'));

section('Measurements');
test('FR-008-08', 'a numeric measurement without a unit warns (measurement_no_unit)', $has($adv($num, ['unit_source_value' => null] + $meas), 'measurement_no_unit', 'warn'));
test('FR-008-08', 'a text measurement warns (measurement_text)', $has($adv($txt, $meas), 'measurement_text', 'warn'));
$res = f('x', 'base', 'radio', 'Culture', '1, Positive | 2, Negative | 3, Contaminated');
$rv = ['1' => ['concept_id' => 9191, 'concept_name' => 'Positive'], '2' => ['concept_id' => 9189, 'concept_name' => 'Negative'],
       '3' => ['concept_id' => 4000002, 'concept_name' => 'Contaminated']];
test('FR-008-08', 'a categorical result "one concept per option" warns (categorical_per_choice)',
    $has($adv($res, ['value_strategy' => 'per_choice'] + $meas, $rv), 'categorical_per_choice', 'warn'));
test('FR-008-08', 'with "value per option" it does not warn', $hasnt($adv($res, ['value_strategy' => 'value_concept'] + $meas, $rv), 'categorical_per_choice'));
test('FR-008-08', 'a yes/no per option in Measurement does not warn',
    $hasnt($adv(f('x', 'base', 'yesno', 'Anemia'), ['value_strategy' => 'per_choice'] + $meas, $yes), 'categorical_per_choice'));

section('Scales');
$scale = f('x', 'base', 'dropdown', 'Pain', '0, 0 - None | 1, 1 | 2, 2 | 3, 3 | 4, 4 - Worst');
test('FR-008-09', 'a rating scale option by option warns (scale_per_choice)',
    $has($adv($scale, ['domain' => 'Observation', 'value_strategy' => 'per_choice', 'date_field' => 'visit_date']), 'scale_per_choice', 'warn'));
test('FR-008-09', 'with a single concept it does not warn',
    $hasnt($adv($scale, $obs + ['value_strategy' => 'single_concept']), 'scale_per_choice'));
$w = $adv($scale, ['domain' => 'Condition', 'value_strategy' => 'single_concept', 'concept_id' => 4000000, 'concept_name' => 'Pain', 'date_field' => 'visit_date']);
test('FR-008-09', 'a scale outside Measurement or Observation warns (scale_wrong_domain)', $has($w, 'scale_wrong_domain', 'warn'));
test('FR-008-09', 'in Measurement it does not', $hasnt($adv($scale, ['value_strategy' => 'single_concept'] + $meas), 'scale_wrong_domain'));
$five = f('x', 'base', 'dropdown', 'Region', '1, North | 2, South | 3, East | 4, West | 5, Centre');
$w = $adv($five, ['domain' => 'Condition', 'value_strategy' => 'per_choice', 'date_field' => 'visit_date']);
test('FR-008-09', 'a scale is recognised by its labels, not by the codes 1, 2, 3', $hasnt($w, 'scale_per_choice') && $hasnt($w, 'scale_wrong_domain'));

section('Person and Death');
$per = f('x', 'base', 'dropdown', 'Marital status', '1, Single | 2, Married');
test('FR-008-10', 'a field in Person that the configuration does not use generates no rows (person_no_rows)',
    $has($adv($per, ['domain' => 'Person', 'value_strategy' => 'per_choice']), 'person_no_rows', 'warn'));
test('FR-008-10', 'if the PERSON configuration is not done, it asks for it to be configured (person_not_configured)',
    $has($adv($per, ['domain' => 'Person', 'value_strategy' => 'per_choice'], [], ['gender-field' => '', 'birth-date-field' => '']), 'person_not_configured', 'warn'));
\REDCap::$dict = fixture_dict();
$m = make_module();
curate($m);
test('FR-008-10', 'the fields that the configuration does use (sex) do not warn', $m->advise('sex') === []);
$died = f('x', 'base', 'yesno', 'Died');
test('FR-008-10', 'Death without a configured death date, on a field that is not a date, warns (death_no_date_config)',
    $has($adv($died, ['domain' => 'Death', 'value_strategy' => 'per_choice'], [], ['death-date-field' => '']), 'death_no_date_config', 'warn'));
test('FR-008-10', 'if the field itself is a date, it does not', $hasnt($adv(f('x', 'base', 'text', 'Date of death', '', 'date_ymd'), ['domain' => 'Death'], [], ['death-date-field' => '']), 'death_no_date_config'));
test('FR-008-10', 'a concept in Death is reported as the cause of death (death_concept_is_cause)',
    $has($adv($died, ['domain' => 'Death', 'value_strategy' => 'per_choice'], ['1' => ['concept_id' => 4306655, 'concept_name' => 'Death']]), 'death_concept_is_cause', 'info')
    && $hasnt($adv($died, ['domain' => 'Death', 'value_strategy' => 'per_choice']), 'death_concept_is_cause'));

section('Data that is lost');
$doses = f('x', 'meds', 'text', 'Missed doses', '', 'integer');
$w = $adv($doses, ['domain' => 'Drug', 'concept_id' => 1782521, 'concept_name' => 'isoniazid', 'date_field' => 'med_start']);
test('FR-008-11', 'a number in a domain without a value column is lost (numeric_lost), with the table',
    $has($w, 'numeric_lost', 'warn') && strpos($w[0]['text'], 'DRUG_EXPOSURE') !== false);
test('FR-008-11', 'in Observation it is not lost', $hasnt($adv($doses, ['domain' => 'Observation', 'concept_id' => 4000000, 'concept_name' => 'Doses', 'date_field' => 'med_start']), 'numeric_lost'));
$fev = f('x', 'base', 'yesno', 'Fever');
$cond = ['domain' => 'Condition', 'value_strategy' => 'per_choice', 'date_field' => 'visit_date'];
test('FR-008-11', 'a clinical yes/no that also emits the "No" warns (emits_negative)',
    $has($adv($fev, $cond, ['1' => ['concept_id' => 437663, 'concept_name' => 'Fever'], '0' => ['concept_id' => 437663, 'concept_name' => 'Fever']]), 'emits_negative', 'warn'));
test('FR-008-11', 'a freshly opened yes/no does not warn: the default "No" does not emit',
    $hasnt($adv($fev, $cond), 'emits_negative') && $hasnt($adv($fev, $cond, ['1' => ['concept_id' => 437663, 'concept_name' => 'Fever'], '0' => ['emit' => false]]), 'emits_negative'));

section('Concepts, without a vocabulary');
test('FR-008-12', 'a concept_id that is 0 as an integer is reported (concept_zero)', $has($adv($num, ['concept_id' => '00'] + $meas), 'concept_zero', 'info'));
test('FR-008-12', 'a 0 stored as a number counts as "no concept" and is not reported', $hasnt($adv($num, ['concept_id' => 0] + $meas), 'concept_zero'));
$com = f('x', 'base', 'checkbox', 'Comorbidities', '1, Diabetes | 2, Hypertension');
$cc = ['domain' => 'Condition', 'value_strategy' => 'per_choice', 'date_field' => 'visit_date'];
test('FR-008-12', 'the same concept on every option warns (same_concept_all)',
    $has($adv($com, $cc, ['1' => ['concept_id' => 201820, 'concept_name' => 'Diabetes mellitus'], '2' => ['concept_id' => 201820, 'concept_name' => 'Diabetes mellitus']]), 'same_concept_all', 'warn'));
test('FR-008-12', 'a concept without a name warns (no_concept_name)', $has($adv($num, ['concept_name' => null] + $meas), 'no_concept_name', 'warn'));
$w = $adv($com, $cc, ['1' => ['concept_id' => 201820, 'concept_name' => null], '2' => ['concept_id' => 316866, 'concept_name' => 'Hypertensive disorder']]);
test('FR-008-12', 'an option with a concept and no name warns, naming the option (no_concept_name_option)',
    $has($w, 'no_concept_name_option', 'warn') && strpos($w[0]['text'], '1') !== false);
test('FR-008-12', 'a Spanish name does not come from Athena (concept_name_is_label)', $has($adv($num, ['concept_name' => 'Peso corporal'] + $meas), 'concept_name_is_label', 'warn'));
test('FR-008-12', 'nor does a question', $has($adv($num, ['concept_name' => 'Do you smoke?'] + $meas), 'concept_name_is_label', 'warn'));
test('FR-008-12', 'an English name that matches the label does not warn', $adv(f('x', 'base', 'text', 'Body weight', '', 'number'),
    ['concept_name' => 'Body weight', 'unit_source_value' => 'kg'] + $meas) === []);

section('Extension points: vocabulary (014)');
$tmp = sys_get_temp_dir() . '/omop_test_vocab008_' . getmypid() . '.sqlite';
@unlink($tmp);
Vocabulary::buildIndex(dirname(__DIR__) . '/fixtures/vocab/CONCEPT.csv', $tmp);
$v = new Vocabulary($tmp, null);
$w = $adv(f('x', 'base', 'text', 'Hemoglobin', '', 'number'), ['concept_id' => 3000963, 'concept_name' => null] + $meas, [], [], $v);
test('FR-008-13', 'with a vocabulary, the name-shape checks give way to the vocabulary checks',
    $hasnt($w, 'no_concept_name') && $hasnt($w, 'concept_name_is_label'), json_encode($codes($w)));
@unlink($tmp);

section('Warnings always up to date');
\REDCap::$dict = fixture_dict();
$m = make_module();
curate($m);
$before = $m->advise('weight');
$m->store()->updateField('weight', ['unit_source_value' => '']);
test('FR-008-14', 'warnings are recomputed after every save in the same request',
    $before === [] && $has($m->advise('weight'), 'measurement_no_unit', 'warn'));
test('FR-008-14', 'a field that does not exist has no warnings', $m->advise('no_such_field') === []);

section('Confirmation when approving');
$runPage = require dirname(__DIR__) . '/support/run_page.php';
\REDCap::$dict = fixture_dict();
$m = make_module();
$m->store()->updateField('glu', ['domain' => 'Measurement', 'concept_id' => '3004501', 'concept_name' => 'Glucose', 'date_field' => 'visit_date']);
$post = ['action' => 'save-field', 'csrf' => 'tok', 'field' => 'glu', 'state' => 'approved'];
$r = $runPage('pages/editor.php', ['settings' => $m->settings, 'post' => $post]);
test('FR-008-15', 'with "warn" warnings, approving asks for confirmation and does not approve',
    ($r['json']['confirm'] ?? false) === true && $r['json']['spec']['state'] === 'proposed', $r['err'] . substr($r['out'], 0, 200));
$r = $runPage('pages/editor.php', ['settings' => $m->settings, 'post' => $post + ['confirmed' => '1']]);
$l = array_values(array_filter($r['logs'], fn($x) => $x[0] === 'Approved with warnings'));
test('FR-008-15', 'once confirmed, it is approved and logged with the warning codes',
    $r['json']['spec']['state'] === 'approved' && count($l) === 1 && $l[0][1]['field'] === 'glu'
    && strpos($l[0][1]['codes'], 'free_text') !== false);
$m->store()->updateField('weight', ['domain' => 'Measurement', 'concept_id' => '3025315', 'concept_name' => 'Body weight',
                                    'unit_source_value' => 'kg', 'date_field' => 'gone_date']);
$r = $runPage('pages/editor.php', ['settings' => $m->settings, 'post' => ['field' => 'weight', 'confirmed' => '1'] + $post]);
test('FR-008-15', 'with a "block" warning it is not approved even when confirming', ($r['json']['blocked'] ?? false) === true
    && $r['json']['spec']['state'] === 'proposed');
$m->store()->updateField('weight', ['date_field' => '']);
$r = $runPage('pages/editor.php', ['settings' => $m->settings, 'post' => ['field' => 'weight'] + $post]);
test('FR-008-15', 'with only "info" warnings it is approved without confirming', empty($r['json']['confirm']) && $r['json']['spec']['state'] === 'approved'
    && $has($r['json']['warnings'], 'using_default_date', 'info'));

section('Non-functional');
$src = file_get_contents(dirname(__DIR__, 2) . '/classes/MappingAdvisor.php');
preg_match_all("/w\('(block|warn|info)', '([a-z_]+)'/", $src, $mm, PREG_SET_ORDER);
$bySev = [];
foreach ($mm as [, $sev, $code]) {
    $bySev[$sev][$code] = true;
}
$blocks = array_keys($bySev['block']);
sort($blocks);
test('NFR-008-01', 'the bar for blocking is high: only five codes block',
    $blocks === ['concept_negative', 'date_field_gone', 'no_date_at_all', 'redcap_complete_field', 'visit_conflict'], implode(',', $blocks));
$es = parse_ini_file(dirname(__DIR__, 2) . '/lang/Spanish.ini');
$en = parse_ini_file(dirname(__DIR__, 2) . '/lang/English.ini');
$all = array_unique(array_column($mm, 2));
$missing = array_filter($all, fn($c) => !isset($es['adv_' . $c], $en['adv_' . $c]));
test('NFR-008-02', 'the 40 codes have their text in Spanish and in English', count($all) === 40 && !$missing, implode(',', $missing));
\REDCap::$dict = fixture_dict();
