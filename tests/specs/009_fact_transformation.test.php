<?php
// Spec 009 — Fact transformation
use OmopCdm\OmopCdmExportModule\CdmModel;
use OmopCdm\OmopCdmExportModule\Transformer;

/** Approves a field with its options, as the curator would in the editor. */
$approve = function ($m, string $f, array $changes, array $options = []) {
    $s = $m->store();
    $d = \REDCap::$dict;
    $p = $m->profile();
    foreach ($options as $raw => $v) {
        $s->updateValue($f, (string) $raw, $v, $p[$f]['choices'][$raw] ?? '', $d[$f]);
    }
    return $s->updateField($f, $changes + ['state' => 'approved'], $d[$f], $p[$f]['choices'] ?? []);
};
/** Rows of a table belonging to one person. */
$ofPerson = fn(array $rows, int $pid) => array_values(array_filter($rows, fn($r) => ($r['person_id'] ?? null) === $pid));

$m = make_module();
curate($m);
$res = $m->transformer()->run();
$T = $res['tables'];
$P = array_column($T['person'], null, 'person_id');

section('Only what is approved');
test('FR-009-01', 'a proposed field (hr, with @OMOP annotations) generates no row at all',
    $m->mappingRows()['hr']['state'] === 'proposed' && !isset(group_by($T['measurement'], 'measurement_concept_id')[3027018]));
reset_fixture();
$empty = make_module()->transformer()->run();
test('FR-009-01', 'with no approved fields there is no export and the reason is given',
    ($empty['error'] ?? null) === 'no_approved_fields' && empty($empty['tables']['person']));

section('PERSON');
test('FR-009-02', 'one person per record with a birth date', count($P) === 3);
test('FR-009-02', 'full birth date: year, month, day and birth_datetime; person_source_value = Record ID',
    $P[1]['year_of_birth'] === 1960 && $P[1]['month_of_birth'] === 5 && $P[1]['day_of_birth'] === 10
    && $P[1]['birth_datetime'] === '1960-05-10 00:00:00' && $P[1]['person_source_value'] === '1');
reset_fixture();
\REDCap::$dict['yob'] = f('yob', 'base', 'text', 'Year of birth', '', 'integer');
foreach (['1', '2', '3'] as $i) {
    \REDCap::$data[$i][1]['yob'] = '';
}
\REDCap::$data['3'][1]['dob'] = '';
\REDCap::$data['3'][1]['yob'] = '1981';
$my = make_module(['birth-year-field' => 'yob']);
curate($my);
$Py = array_column($my->transformer()->run()['tables']['person'], null, 'person_id');
test('FR-009-02', 'without a full date the year field is used, with no month or day',
    count($Py) === 3 && $Py[3]['year_of_birth'] === 1981 && $Py[3]['month_of_birth'] === null && $Py[3]['birth_datetime'] === null);

test('FR-009-03', 'approved sex → gender_concept_id', $P[1]['gender_concept_id'] === 8507 && $P[2]['gender_concept_id'] === 8532);
test('FR-009-03', 'race and ethnicity with no declared field → concept 0', $P[1]['race_concept_id'] === 0 && $P[1]['ethnicity_concept_id'] === 0);
reset_fixture();
$ms = make_module();
curate($ms);
$ms->store()->updateField('sex', ['state' => 'proposed']);
$rs = $ms->transformer()->run();
test('FR-009-03', 'unapproved sex → concept 0, label in gender_source_value and a warning',
    $rs['tables']['person'][0]['gender_concept_id'] === 0 && $rs['tables']['person'][0]['gender_source_value'] === 'Male'
    && ($rs['stats']['gender_not_approved'] ?? 0) === 1);

section('Persons without a year of birth');
reset_fixture();
\REDCap::$data['3'][1]['dob'] = '';
$md = make_module();
curate($md);
$rd = $md->transformer()->run();
$rows3 = 0;
foreach ($rd['tables'] as $rows) {
    $rows3 += count($ofPerson($rows, 3));
}
test('FR-009-04', 'the person without a birth date is not in PERSON and no row points to them',
    count($rd['tables']['person']) === 2 && $rows3 === 0);
test('FR-009-04', 'the drop is counted: persons, rows dragged along and Record ID',
    ($rd['stats']['persons_dropped'] ?? 0) === 1 && ($rd['stats']['rows_dropped_with'] ?? 0) === 4
    && ($rd['stats']['dropped_record_ids'] ?? '') === '3');

section('OBSERVATION_PERIOD');
$OP = array_column($T['observation_period'], null, 'person_id');
test('FR-009-05', 'one period per person, from the first to the last date of their facts, end dates included',
    count($OP) === 3 && $OP[1]['observation_period_start_date'] === '2024-03-01' && $OP[1]['observation_period_end_date'] === '2024-03-20');
$insidePeriod = true;
$endColumns = Transformer::END_DATE_COLUMN + ['visit_occurrence' => 'visit_end_date'];
foreach (Transformer::DATE_COLUMN + ['visit_occurrence' => 'visit_start_date'] as $t => $col) {
    foreach ($T[$t] as $r) {
        $op = $OP[$r['person_id']];
        $end = isset($endColumns[$t]) ? ($r[$endColumns[$t]] ?? $r[$col]) : $r[$col];
        $insidePeriod = $insidePeriod && $r[$col] >= $op['observation_period_start_date']
            && $end <= $op['observation_period_end_date'];
    }
}
test('FR-009-05', 'no fact or visit starts or ends outside its person\'s period', $insidePeriod);
test('FR-009-05', 'the date of death counts towards the end of the period', $OP[2]['observation_period_end_date'] === '2024-06-01');
test('FR-009-05', 'period id = person_id and type 32817',
    $OP[2]['observation_period_id'] === 2 && $OP[2]['period_type_concept_id'] === Transformer::TYPE_OBS_PERIOD);

section('Domains and tables');
reset_fixture();
foreach ([
    f('xray', 'base', 'yesno', 'Chest X-ray done'),
    f('catheter', 'base', 'yesno', 'Central venous catheter'),
    f('sputum_sample', 'base', 'yesno', 'Sputum sample collected'),
    f('smoking', 'base', 'dropdown', 'Smoking status', '1, Never | 2, Former | 3, Current'),
    f('pain', 'base', 'dropdown', 'Pain score', '0, 0 - No pain | 1, 1 | 2, 2 | 3, 3 | 4, 4 | 5, 5 - Worst pain'),
    f('temp', 'base', 'text', 'Temperature', '', 'number'),
    f('crp', 'base', 'text', 'C-reactive protein'),
    f('tb_dx_date', 'base', 'text', 'Date of TB diagnosis', '', 'date_ymd'),
    f('death_cause', 'base', 'text', 'Cause of death'),
] as $x) {
    \REDCap::$dict[$x['field_name']] = $x;
}
\REDCap::$data = fixture_records();
foreach ([
    ['1', 'xray', '1'], ['3', 'xray', '0'], ['1', 'catheter', '1'], ['1', 'sputum_sample', '1'],
    ['1', 'smoking', '3'], ['1', 'pain', '4'], ['1', 'temp', '37.5'], ['1', 'crp', '>= 10'],
    ['2', 'crp', '95,5'], ['3', 'crp', '<=3'], ['1', 'tb_dx_date', '2023-12-15'], ['2', 'death_cause', 'Respiratory failure'],
] as [$id, $k, $v]) {
    \REDCap::$data[$id][1][$k] = $v;
}
$mx = make_module();
curate($mx);
$approve($mx, 'xray', ['domain' => 'Procedure', 'value_strategy' => 'per_choice'], ['1' => ['emit' => '1', 'concept_id' => '4163872']]);
$approve($mx, 'catheter', ['domain' => 'Device', 'value_strategy' => 'per_choice'], ['1' => ['emit' => '1', 'concept_id' => '4222966']]);
$approve($mx, 'sputum_sample', ['domain' => 'Specimen', 'value_strategy' => 'per_choice'], ['1' => ['emit' => '1', 'concept_id' => '4002890']]);
$approve($mx, 'smoking', ['domain' => 'Observation', 'value_strategy' => 'single_concept', 'concept_id' => '43054909']);
$approve($mx, 'pain', ['domain' => 'Observation', 'value_strategy' => 'single_concept', 'concept_id' => '4137083']);
$approve($mx, 'temp', ['domain' => 'Measurement', 'concept_id' => '3020891', 'unit_source_value' => '°C']);
$approve($mx, 'crp', ['domain' => 'Measurement', 'concept_id' => '3020460', 'unit_source_value' => 'mg/L']);
$approve($mx, 'tb_dx_date', ['domain' => 'Condition', 'concept_id' => '434557']);
$approve($mx, 'death_cause', ['domain' => 'Death']);
$rx = $mx->transformer()->run();
$X = $rx['tables'];
$proc = $X['procedure_occurrence'];
test('FR-009-06', 'Procedure → PROCEDURE_OCCURRENCE, only the "Yes"',
    count($proc) === 1 && $proc[0]['procedure_concept_id'] === 4163872 && $proc[0]['procedure_date'] === '2024-03-01');
test('FR-009-06', 'Device → DEVICE_EXPOSURE', count($X['device_exposure']) === 1 && $X['device_exposure'][0]['device_concept_id'] === 4222966);
test('FR-009-06', 'Specimen → SPECIMEN', count($X['specimen']) === 1 && $X['specimen'][0]['specimen_concept_id'] === 4002890
    && $X['specimen'][0]['specimen_date'] === '2024-03-01');
test('FR-009-06', 'Observation → OBSERVATION', count($X['observation']) === 2);
$types = true;
foreach (['condition_occurrence' => 'condition', 'drug_exposure' => 'drug', 'procedure_occurrence' => 'procedure',
          'measurement' => 'measurement', 'observation' => 'observation', 'device_exposure' => 'device',
          'specimen' => 'specimen', 'note' => 'note', 'death' => 'death'] as $t => $pre) {
    foreach ($X[$t] as $r) {
        $types = $types && $r[$pre . '_type_concept_id'] === Transformer::TYPE_EHR_FORM;
    }
}
test('FR-009-06', 'every fact row carries the "study form" type (32809)', $types);
test('FR-009-06', 'the source is the label: the question in a yes/no, the option in a dropdown',
    $proc[0]['procedure_source_value'] === 'Chest X-ray done'
    && array_column($T['drug_exposure'], 'drug_source_value') === ['Isoniazid', 'Rifampin']);

section('Value strategies');
$cond = group_by($T['condition_occurrence'], 'condition_concept_id');
test('FR-009-07', 'per_choice: each chosen option is a fact with the option\'s concept',
    isset($cond[201820], $cond[316866]) && $cond[201820][0]['condition_source_value'] === 'Diabetes');
$meas = group_by($T['measurement'], 'measurement_concept_id');
$bac = array_column($meas[3016102], null, 'person_id');
test('FR-009-07', 'value_concept: the question\'s concept and the option in value_as_concept_id, never the code as a number',
    $bac[1]['value_as_concept_id'] === 9191 && $bac[2]['value_as_concept_id'] === 9189 && $bac[1]['value_as_number'] === null
    && $bac[1]['value_source_value'] === 'Positive');
$obs = array_column($X['observation'], null, 'observation_concept_id');
test('FR-009-07', 'single_concept: the option label as text, not the code',
    $obs[43054909]['value_as_string'] === 'Current' && $obs[43054909]['value_as_number'] === null);
test('FR-009-07', 'single_concept on a rating scale: the number in the label is the value',
    $obs[4137083]['value_as_number'] == 4 && $obs[4137083]['value_as_string'] === null);
reset_fixture();
$mb = make_module();
curate($mb);
$approve($mb, 'fever', ['value_strategy' => 'single_concept', 'concept_id' => '437663']);
$rb = $mb->transformer()->run();
test('FR-009-07', 'a yes/no approved as a single concept is handled per option: the "No" still has no row',
    $mb->mappingRows()['fever']['spec']['value_strategy'] === 'single_concept'
    && count(group_by($rb['tables']['condition_occurrence'], 'condition_concept_id')[437663] ?? []) === 2);

section('"Generates a row" per option');
test('FR-009-08', 'fever: only the "Yes" emits', count($cond[437663]) === 2 && !$ofPerson($cond[437663], 2));
test('FR-009-08', 'what does not emit by decision is counted separately, not as a skip',
    ($res['stats']['not_emitted'] ?? 0) === 2 && $res['skipped'] === []);
reset_fixture();
\REDCap::$data['2']['repeat_instances'][1]['meds'][1] = ['med_start' => '2024-04-02', 'drug' => '9', 'med_end' => ''];
$mu = make_module();
curate($mu);
$ru = $mu->transformer()->run();
test('FR-009-08', 'an option without a decision is not made up: it is skipped and counted',
    ($ru['skipped']['choice_undecided|drug=9'] ?? 0) === 1 && count($ofPerson($ru['tables']['drug_exposure'], 2)) === 0);

section('Checkboxes');
test('FR-009-09', 'checkboxes (nested array): diabetes and hypertension, not "None"', isset($cond[201820]) && isset($cond[316866]) && count($cond) === 3);
test('FR-009-09', '"None" does not count as a skip', !array_filter(array_keys($res['skipped']), fn($k) => strpos($k, 'comorb') !== false));
reset_fixture();
unset(\REDCap::$data['1'][1]['comorb']);
\REDCap::$data['1'][1] += ['comorb___1' => '1', 'comorb___2' => '0', 'comorb___3' => '0'];
$mc = make_module();
curate($mc);
$cc = group_by($ofPerson($mc->transformer()->run()['tables']['condition_occurrence'], 1), 'condition_concept_id');
test('FR-009-09', 'checkboxes in field___option format are read as well', isset($cc[201820]) && !isset($cc[316866]));

section('Numbers and operators');
$w = $meas[3025315][0];
$g = array_column($meas[3004501], null, 'person_id');
test('FR-009-10', 'REDCap numeric validation → value_as_number', $w['value_as_number'] == 70 && $w['value_source_value'] === '70');
test('FR-009-10', '"95" in free text is converted', $g[3]['value_as_number'] == 95);
test('FR-009-10', '"1.234" is not converted, it is kept in value_source_value and counted',
    $g[2]['value_as_number'] === null && $g[2]['value_source_value'] === '1.234' && ($res['stats']['ambiguous_number'] ?? 0) === 1);
$crp = array_column(group_by($X['measurement'], 'measurement_concept_id')[3020460], null, 'person_id');
test('FR-009-10', '"95,5" is not converted and counts as not numeric',
    $crp[2]['value_as_number'] === null && $crp[2]['value_source_value'] === '95,5' && ($rx['stats']['not_numeric'] ?? 0) === 1);
test('FR-009-10', '"< 70" → operator < and value 70', $g[1]['operator_concept_id'] === 4171756 && $g[1]['value_as_number'] == 70);
test('FR-009-10', '">= 10" and "<=3" → operators >= and <=, and they are counted',
    $crp[1]['operator_concept_id'] === CdmModel::OPERATORS['>='] && $crp[1]['value_as_number'] == 10
    && $crp[3]['operator_concept_id'] === CdmModel::OPERATORS['<='] && $crp[3]['value_as_number'] == 3
    && ($rx['stats']['with_operator'] ?? 0) === 3);

section('Units');
test('FR-009-11', 'weight with the kg unit_concept_id (9529) without a vocabulary', $w['unit_concept_id'] === 9529 && $w['unit_source_value'] === 'kg');
test('FR-009-11', 'mg/dL → 8840', $g[3]['unit_concept_id'] === 8840);
$temp = group_by($X['measurement'], 'measurement_concept_id')[3020891][0];
test('FR-009-11', 'a unit outside the short table and with no vocabulary: the text is kept, without a concept',
    $temp['unit_source_value'] === '°C' && $temp['unit_concept_id'] === null);
test('FR-009-11', 'no number, no unit', $g[2]['unit_source_value'] === null && $g[2]['unit_concept_id'] === null);

section('Dates');
test('FR-009-12', 'facts take the field\'s reference date', $w['measurement_date'] === '2024-03-01'
    && $T['drug_exposure'][0]['drug_exposure_start_date'] === '2024-03-02');
$tb = group_by($X['condition_occurrence'], 'condition_concept_id')[434557][0];
test('FR-009-12', 'a date field mapped as a fact is dated with its own value', $tb['condition_start_date'] === '2023-12-15');
reset_fixture();
\REDCap::$data['3'][1]['visit_date'] = '';
$mf = make_module();
curate($mf);
$rf = $mf->transformer()->run();
test('FR-009-12', 'no date, no row: it is skipped and counted per field',
    ($rf['skipped']['no_date|weight'] ?? 0) === 1 && ($rf['skipped']['no_date|fever'] ?? 0) === 1
    && !$ofPerson($rf['tables']['measurement'], 3));
$allDated = true;
foreach ([$T, $X, $rf['tables']] as $tables) {
    foreach (Transformer::DATE_COLUMN as $t => $col) {
        foreach ($tables[$t] as $r) {
            $allDated = $allDated && !empty($r[$col]);
        }
    }
    foreach ($tables['death'] as $r) {
        $allDated = $allDated && !empty($r['death_date']);
    }
}
test('FR-009-12', 'no row in any clinical table without a date', $allDated && !isset($res['skipped']['no_date']));

section('End dates');
$drug = array_column($T['drug_exposure'], null, 'drug_concept_id');
test('FR-009-13', 'drug end date', $drug[1782521]['drug_exposure_end_date'] === '2024-03-20'
    && $drug[1782521]['verbatim_end_date'] === '2024-03-20');
test('FR-009-13', 'end before start: the end is dropped and counted', $drug[1763204]['drug_exposure_end_date'] === '2024-03-05'
    && $drug[1763204]['verbatim_end_date'] === null && ($res['stats']['end_before_start'] ?? 0) === 1);
test('FR-009-13', 'a condition without an end field does not make up an end', $cond[437663][0]['condition_end_date'] === null);

section('DEATH');
test('FR-009-14', 'DEATH with the date of the death field and no made-up cause', count($T['death']) === 1
    && $T['death'][0]['death_date'] === '2024-06-01' && $T['death'][0]['cause_concept_id'] === null);
test('FR-009-14', 'one row per person even with two Death fields; a free-text cause goes to cause_source_value',
    count($X['death']) === 1 && $X['death'][0]['cause_source_value'] === 'Respiratory failure' && $X['death'][0]['cause_concept_id'] === null);
reset_fixture();
$mm = make_module();
curate($mm);
$approve($mm, 'exitus', [], ['1' => ['emit' => '1', 'concept_id' => '4306655']]);
$rm = $mm->transformer()->run();
test('FR-009-14', 'the option\'s approved concept is the cause (cause_concept_id)',
    $rm['tables']['death'][0]['cause_concept_id'] === 4306655 && $rm['tables']['death'][0]['death_date'] === '2024-06-01');
test('FR-009-14', 'in a yes/no, the "Yes" label is not a cause: cause_source_value stays empty',
    $rm['tables']['death'][0]['cause_source_value'] === null);
$mm->store()->updateField('exitus', ['state' => 'proposed']);
$rm2 = $mm->transformer()->run();
test('FR-009-14', 'the declared date of death is enough: DEATH comes from the configuration',
    count($rm2['tables']['death']) === 1 && $rm2['tables']['death'][0]['person_id'] === 2);
reset_fixture();
\REDCap::$dict['death_cat'] = f('death_cat', 'base', 'dropdown', 'Main cause of death', '1, Tuberculosis | 2, Cancer | 3, Other');
\REDCap::$data = fixture_records();
\REDCap::$data['2'][1]['death_cat'] = '1';
\REDCap::$data['3'][1]['death_cat'] = '3';
\REDCap::$data['3'][1]['death_date'] = '2024-07-01';
$mk = make_module();
curate($mk);
$approve($mk, 'death_cat', ['domain' => 'Death', 'value_strategy' => 'per_choice'],
    ['1' => ['emit' => '1', 'concept_id' => '434557'], '2' => ['emit' => '1', 'concept_id' => '4092217'], '3' => ['emit' => '1']]);
$Dk = array_column($mk->transformer()->run()['tables']['death'], null, 'person_id');
test('FR-009-14', 'in a categorical cause field, the label goes with the concept; an option without a concept leaves no label',
    $Dk[2]['cause_concept_id'] === 434557 && $Dk[2]['cause_source_value'] === 'Tuberculosis'
    && $Dk[3]['cause_concept_id'] === null && $Dk[3]['cause_source_value'] === null);

section('NOTE');
test('FR-009-15', 'NOTE with the full text, its class and UTF-8', count($T['note']) === 1
    && strpos($T['note'][0]['note_text'], 'John Smith') !== false && $T['note'][0]['encoding_concept_id'] === 32678
    && $T['note'][0]['note_class_concept_id'] === 44814637 && $T['note'][0]['note_title'] === 'Clinical notes'
    && $T['note'][0]['note_source_value'] === 'notes');
test('FR-009-15', 'note language: the module\'s (English, 4180186)', $T['note'][0]['language_concept_id'] === 4180186);
reset_fixture();
$long = str_repeat('Lorem ipsum dolor sit amet. ', 400) . 'FIN';
\REDCap::$data['1'][1]['notes'] = $long;
$mn = make_module(['note-language-concept-id' => '4182511']);
curate($mn);
$note = $mn->transformer()->run()['tables']['note'][0];
test('FR-009-15', 'a long text is not trimmed and the configured language wins',
    $note['note_text'] === $long && $note['language_concept_id'] === 4182511);

section('Batches, required columns and counters');
reset_fixture();
for ($i = 4; $i <= 205; $i++) {
    \REDCap::$data[(string) $i] = rec((string) $i, ['dob' => '1990-01-01', 'sex' => '1', 'visit_date' => '2024-01-01', 'weight' => '60']);
}
$ml = make_module();
curate($ml);
$rl = $ml->transformer()->run();
test('NFR-009-01', '205 records are processed in 2 batches of 200', $rl['stats']['batches'] === 2 && $rl['stats']['records'] === 205
    && count($rl['tables']['person']) === 205);
$rp = $ml->transformer()->run(10, true);
test('NFR-009-01', 'the preview processes only the requested records', $rp['stats']['records'] === 10);
test('NFR-009-02', 'no empty NOT NULL column', $res['missing'] === [] && $rx['missing'] === [], json_encode($res['missing']));
test('NFR-009-02', 'missing is the CdmModel check over the generated rows',
    $res['missing'] === CdmModel::missingRequired($res['tables']));
test('NFR-009-03', 'the result carries tables, counts, stats, skips and empty columns',
    array_keys($res) === ['tables', 'counts', 'stats', 'skipped', 'missing'] && $res['stats']['records'] === 3
    && $res['stats']['rows'] === array_sum($res['counts']));
test('NFR-009-03', 'skips are stored as "code|argument"',
    array_keys($rf['skipped']) === array_filter(array_keys($rf['skipped']), fn($k) => (bool) preg_match('/^[a-z_]+\|\w+/', $k)));
test('FR-009-06', 'without a vocabulary there is no SOURCE_TO_CONCEPT_MAP', empty($T['source_to_concept_map']));
