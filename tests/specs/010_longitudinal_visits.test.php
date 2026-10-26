<?php
// Spec 010 — Longitudinal projects, visits and entities
use OmopCdm\OmopCdmExportModule\Transformer;

section('Classic project');
$m = make_module();
curate($m);
$res = $m->transformer()->run();
$T = $res['tables'];
$P = array_column($T['person'], null, 'person_id');
$drug = array_column($T['drug_exposure'], null, 'drug_concept_id');
test('FR-010-12', 'CARE_SITE from the site field, linked from PERSON', count($T['care_site']) === 2 && $P[1]['care_site_id'] !== null
    && $P[1]['care_site_id'] === $P[3]['care_site_id'] && $P[1]['care_site_id'] !== $P[2]['care_site_id']);
$cs = array_column($T['care_site'], null, 'care_site_source_value');
test('FR-010-12', 'the care site name is the option label and the source is its code',
    $cs['1']['care_site_name'] === 'North' && $cs['2']['care_site_name'] === 'South');
$visits = $T['visit_occurrence'];
test('FR-010-12', 'visits carry their care site', $visits && $visits[0]['care_site_id'] !== null);
test('FR-010-02', 'the medication from the repeating instrument is linked to its event\'s visit',
    $drug[1782521]['visit_occurrence_id'] !== null && $drug[1782521]['visit_occurrence_id'] === $visits[0]['visit_occurrence_id']);
test('FR-010-04', 'in a classic project, one visit per record with the single event as the occasion',
    count($visits) === 3 && $visits[0]['visit_source_value'] === 'event_1');
\REDCap::$data['3'][1]['dob'] = '';
$md = make_module();
$md->settings = $m->settings;
$rd = $md->transformer()->run();
test('FR-010-04', 'a person dropped for lack of a birth date leaves no visits',
    count($rd['tables']['visit_occurrence']) === 2 && !in_array(3, array_column($rd['tables']['visit_occurrence'], 'person_id'), true));

// ------------------------------------------------------- Longitudinal project
// Two arms; in arm 1: baseline and month 2 with repeating medication, a
// follow-up that repeats as a whole and a closure with only the death.
reset_fixture();
\REDCap::$dict['doctor'] = f('doctor', 'base', 'dropdown', 'Attending physician', '1, Dr Alvarez | 2, Dr Brown');
\REDCap::$dict['tb_dx_date'] = f('tb_dx_date', 'base', 'text', 'Date of TB diagnosis', '', 'date_ymd');
\REDCap::$dict['clinic_txt'] = f('clinic_txt', 'base', 'text', 'Clinic (free text)');
\Project::$conf = [
    'longitudinal' => true,
    'eventInfo' => [
        10 => ['descrip' => 'Baseline', 'arm_num' => 1, 'day_offset' => 0],
        20 => ['descrip' => 'Month 2', 'arm_num' => 1, 'day_offset' => 60],
        30 => ['descrip' => 'Follow-up', 'arm_num' => 1, 'day_offset' => 90],
        40 => ['descrip' => 'Closure', 'arm_num' => 1, 'day_offset' => 180],
        50 => ['descrip' => 'Baseline B', 'arm_num' => 2, 'day_offset' => 0],
    ],
    'uniqueEventNames' => [10 => 'baseline_arm_1', 20 => 'month2_arm_1', 30 => 'followup_arm_1',
                           40 => 'closure_arm_1', 50 => 'baseline_arm_2'],
    'RepeatingFormsEvents' => [10 => ['meds' => ''], 20 => ['meds' => ''], 30 => 'WHOLE'],
    'forms' => ['base' => [], 'meds' => []],
];
$makeRow = function (string $id, array $vals): array {
    $r = [];
    foreach (\REDCap::$dict as $n => $meta) {
        $r[$n] = $meta['field_type'] === 'checkbox' ? ['1' => '0', '2' => '0', '3' => '0'] : '';
    }
    $r['record_id'] = $id;
    return $vals + $r;
};
\REDCap::$data = [
    'L1' => [
        10 => $makeRow('L1', ['dob' => '1960-05-10', 'sex' => '1', 'visit_date' => '2024-01-10', 'weight' => '70',
                              'site' => '1', 'doctor' => '1', 'fever' => '1', 'tb_dx_date' => '2023-12-15']),
        20 => $makeRow('L1', ['visit_date' => '2024-03-10', 'weight' => '72', 'glu' => '95', 'site' => '2', 'doctor' => '2']),
        40 => $makeRow('L1', ['death_date' => '2024-06-01', 'exitus' => '1']),
        'repeat_instances' => [
            10 => ['meds' => [1 => ['med_start' => '2024-01-11', 'drug' => '1', 'med_end' => '2024-01-30'],
                              2 => ['med_start' => '', 'drug' => '2', 'med_end' => '']]],
            20 => ['meds' => [1 => ['med_start' => '', 'drug' => '1', 'med_end' => ''],
                              2 => ['med_start' => '2024-03-12', 'drug' => '2', 'med_end' => '']]],
            30 => ['' => [1 => $makeRow('L1', ['visit_date' => '2024-04-10', 'weight' => '73']),
                          2 => $makeRow('L1', ['visit_date' => '2024-05-10', 'weight' => '74'])]],
        ],
    ],
    'L2' => [
        50 => $makeRow('L2', ['dob' => '1975-03-03', 'sex' => '2', 'visit_date' => '2024-02-01', 'weight' => '65',
                              'site' => '1', 'doctor' => '1']),
    ],
    'L3' => [
        10 => $makeRow('L3', ['dob' => '1980-02-02', 'sex' => '1', 'visit_date' => '', 'site' => '2']),
        20 => $makeRow('L3', ['visit_date' => '2024-03-01', 'weight' => '81']),
        'repeat_instances' => [
            10 => ['meds' => [1 => ['med_start' => '2024-02-05', 'drug' => '1', 'med_end' => ''],
                              2 => ['med_start' => '2024-02-07', 'drug' => '2', 'med_end' => ''],
                              3 => ['med_start' => '', 'drug' => '1', 'med_end' => '']]],
        ],
    ],
];
$m = make_module(['provider-field' => 'doctor']);
curate($m);
$m->store()->updateField('tb_dx_date', ['domain' => 'Condition', 'concept_id' => '434557', 'state' => 'approved'],
    \REDCap::$dict['tb_dx_date'], []);
$res = $m->transformer()->run();
$T = $res['tables'];
$P = array_column($T['person'], null, 'person_source_value');
$V = array_column($T['visit_occurrence'], null, 'visit_occurrence_id');
$visit = function (string $rec, string $src) use ($T, $P) {
    foreach ($T['visit_occurrence'] as $v) {
        if ($v['person_id'] === $P[$rec]['person_id'] && $v['visit_source_value'] === $src) {
            return $v;
        }
    }
    return null;
};
$ofRecord = fn(array $rows, string $rec) => array_values(array_filter($rows, fn($r) => $r['person_id'] === $P[$rec]['person_id']));
$weights = $ofRecord(group_by($T['measurement'], 'measurement_concept_id')[3025315], 'L1');
$drugL1 = $ofRecord($T['drug_exposure'], 'L1');
$drugL3 = $ofRecord($T['drug_exposure'], 'L3');

section('Events, arms and instances');
$struct = $m->getProjectStructure();
test('NFR-010-01', 'the structure provides the unique event names and the whole repeating event',
    $struct['longitudinal'] && $struct['events'][10]['unique_name'] === 'baseline_arm_1' && $struct['events'][50]['arm_num'] === 2
    && in_array(['event_id' => 30, 'form' => ''], $struct['repeating'], true));
test('FR-010-01', 'the events of both arms are read', count($P) === 3
    && count($ofRecord(group_by($T['measurement'], 'measurement_concept_id')[3025315], 'L2')) === 1);
test('FR-010-01', 'each instance of a whole repeating event is a collection row',
    in_array('2024-04-10', array_column($weights, 'measurement_date'), true) && in_array('2024-05-10', array_column($weights, 'measurement_date'), true));
test('FR-010-01', 'each instance of a repeating instrument is a collection row', count($drugL1) === 4);
test('FR-010-03', 'the same field on four occasions gives four rows, each with its own date',
    array_column($weights, 'measurement_date') === ['2024-01-10', '2024-03-10', '2024-04-10', '2024-05-10']);

section('Occasions and VISIT_OCCURRENCE');
$base = $visit('L1', 'baseline_arm_1');
$month2 = $visit('L1', 'month2_arm_1');
test('FR-010-04', 'one visit per occasion with facts: 4 for L1, 1 for L2, 2 for L3',
    count($T['visit_occurrence']) === 7 && ($res['stats']['visits'] ?? 0) === 7);
test('FR-010-04', 'configured visit type, record type 32809 and the event\'s unique name as source',
    $base['visit_concept_id'] === 9202 && $base['visit_type_concept_id'] === Transformer::TYPE_EHR_FORM
    && $visit('L2', 'baseline_arm_2') !== null);
test('FR-010-02', 'a whole repeating event gives one visit per instance, with "#instance" in the source',
    $visit('L1', 'followup_arm_1#1') !== null && $visit('L1', 'followup_arm_1#2') !== null);
$byDate = array_column($drugL1, null, 'drug_exposure_start_date');
test('FR-010-02', 'repeating medication belongs to its event\'s visit, not to a visit of its own',
    $byDate['2024-01-11']['visit_occurrence_id'] === $base['visit_occurrence_id']
    && $byDate['2024-03-12']['visit_occurrence_id'] === $month2['visit_occurrence_id']);
test('FR-010-06', 'the visit starts on the occasion date and is extended by later facts',
    $base['visit_start_date'] === '2024-01-10' && $base['visit_end_date'] === '2024-01-11'
    && $month2['visit_start_date'] === '2024-03-10' && $month2['visit_end_date'] === '2024-03-12');
$b3 = $visit('L3', 'baseline_arm_1');
test('FR-010-06', 'without an occasion date, the visit spans from its first to its last fact',
    $b3['visit_start_date'] === '2024-02-05' && $b3['visit_end_date'] === '2024-02-07');
$tb = group_by($T['condition_occurrence'], 'condition_concept_id')[434557][0];
test('FR-010-07', 'a fact earlier than the visit does not stretch it backwards and is left without a visit',
    $tb['condition_start_date'] === '2023-12-15' && $tb['visit_occurrence_id'] === null && $base['visit_start_date'] === '2024-01-10');
test('FR-010-07', 'facts outside their visit are counted', ($res['stats']['facts_outside_visit'] ?? 0) === 1);
$inside = true;
$linked = 0;
foreach (Transformer::DATE_COLUMN as $t => $col) {
    foreach ($T[$t] as $r) {
        if (!empty($r['visit_occurrence_id'])) {
            $linked++;
            $v = $V[$r['visit_occurrence_id']];
            $inside = $inside && $r['person_id'] === $v['person_id']
                && $r[$col] >= $v['visit_start_date'] && $r[$col] <= $v['visit_end_date'];
        }
    }
}
test('FR-010-08', 'no linked fact falls outside its visit\'s dates or belongs to another person', $inside && $linked > 10);
test('FR-010-09', 'DEATH does not create visits: the closure event with only the death has no visit',
    count($T['death']) === 1 && $T['death'][0]['death_date'] === '2024-06-01' && $visit('L1', 'closure_arm_1') === null);

section('Date of a repeating instance');
test('FR-010-10', 'without its own date, the instance takes the date of the main row of its same event',
    isset($byDate['2024-01-10']) && isset($byDate['2024-03-10'])
    && $byDate['2024-03-10']['visit_occurrence_id'] === $month2['visit_occurrence_id']);
test('FR-010-10', 'never another event\'s: if its event has no date, it is skipped and counted',
    count($drugL3) === 2 && ($res['skipped']['no_date|drug'] ?? 0) === 1);

section('CARE_SITE and PROVIDER');
$PL1 = $P['L1'];
test('FR-010-12', 'PERSON takes the care site from the record\'s first value and each visit the one from its occasion',
    $PL1['care_site_id'] === $base['care_site_id'] && $month2['care_site_id'] !== $base['care_site_id']
    && $month2['care_site_id'] !== null);
$csL = array_column($T['care_site'], null, 'care_site_source_value');
test('FR-010-12', 'one CARE_SITE per code used', count($T['care_site']) === 2 && $csL['2']['care_site_name'] === 'South');
$pr = array_column($T['provider'], null, 'provider_source_value');
test('FR-010-13', 'PROVIDER from the provider field: code as source, no name',
    count($T['provider']) === 2 && $pr['1']['provider_name'] === null && $pr['2']['provider_id'] !== $pr['1']['provider_id']);
test('FR-010-13', 'the provider is linked from PERSON and VISIT_OCCURRENCE',
    $PL1['provider_id'] === $pr['1']['provider_id'] && $base['provider_id'] === $pr['1']['provider_id']
    && $month2['provider_id'] === $pr['2']['provider_id']);
$weightMonth2 = array_column($weights, null, 'measurement_date')['2024-03-10'];
test('FR-010-13', 'and from the occasion\'s facts, including repeating medication',
    $weightMonth2['provider_id'] === $pr['2']['provider_id'] && $byDate['2024-01-11']['provider_id'] === $pr['1']['provider_id']);
$mt = make_module(['care-site-field' => 'clinic_txt']);
$mt->settings = ['care-site-field' => 'clinic_txt'] + $m->settings;
\REDCap::$data['L2'][50]['clinic_txt'] = 'Hospital Norte';
$rt = $mt->transformer()->run();
test('FR-010-12', 'a free-text field does not generate care sites', empty($rt['tables']['care_site'])
    && array_filter(array_column($rt['tables']['person'], 'care_site_id')) === []);

section('No visit type, or a Visit field');
$mv = make_module();
$mv->settings = ['visit-concept-id' => ''] + $m->settings;
$rv = $mv->transformer()->run();
$noVisit = true;
foreach (Transformer::DATE_COLUMN as $t => $col) {
    foreach ($rv['tables'][$t] as $r) {
        $noVisit = $noVisit && $r['visit_occurrence_id'] === null;
    }
}
test('FR-010-05', 'without a visit type concept no visits are generated and no fact points to one',
    empty($rv['tables']['visit_occurrence']) && $noVisit && !isset($rv['stats']['visits']));
$mo = make_module();
$mo->settings = ['generate-visits' => false] + $m->settings;
test('FR-010-05', 'with generation turned off, none either', empty($mo->transformer()->run()['tables']['visit_occurrence']));

reset_fixture();
$mc = make_module();
curate($mc);
$mc->store()->updateField('visit_date', ['domain' => 'Visit', 'concept_id' => '9202', 'state' => 'approved'],
    \REDCap::$dict['visit_date'], []);
$rc = $mc->transformer()->run();
test('FR-010-11', 'with automatic visits, a field approved as Visit is dropped and explained',
    ($rc['skipped']['visit_domain_conflict|visit_date'] ?? 0) === 1 && count($rc['tables']['visit_occurrence']) === 3);
$mc2 = make_module();
$mc2->settings = ['generate-visits' => false] + $mc->settings;
$rc2 = $mc2->transformer()->run();
$vf = $rc2['tables']['visit_occurrence'];
test('FR-010-11', 'without automatic visits, the Visit field generates its own visits with its date',
    count($vf) === 3 && $vf[0]['visit_concept_id'] === 9202 && $vf[0]['visit_start_date'] === '2024-03-01'
    && !isset($rc2['skipped']['visit_domain_conflict|visit_date']));
