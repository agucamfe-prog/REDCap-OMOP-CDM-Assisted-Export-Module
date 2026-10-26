<?php
/**
 * Test project shared by every spec.
 *
 * A tuberculosis study in English with a baseline instrument and a repeating
 * medication instrument: small enough to read at a glance and varied enough to
 * exercise every CDM table. Every tests/specs file starts with reset_fixture(),
 * so no file depends on what another one left behind.
 */

use OmopCdm\OmopCdmExportModule\OmopCdmExportModule;

/** One data dictionary row, in the format of REDCap::getDataDictionary('array'). */
function f($name, $form, $type, $label, $choices = '', $val = '', $ann = '', $ident = ''): array
{
    return ['field_name' => $name, 'form_name' => $form, 'section_header' => '', 'field_type' => $type,
            'field_label' => $label, 'select_choices_or_calculations' => $choices, 'field_note' => '',
            'text_validation_type_or_show_slider_number' => $val, 'text_validation_min' => '',
            'text_validation_max' => '', 'identifier' => $ident, 'branching_logic' => '',
            'required_field' => '', 'custom_alignment' => '', 'question_number' => '',
            'matrix_group_name' => '', 'matrix_ranking' => '', 'field_annotation' => $ann];
}

function fixture_dict(): array
{
    $dict = [];
    foreach ([
        f('record_id', 'base', 'text', 'Record ID'),
        f('dob', 'base', 'text', 'Date of birth', '', 'date_ymd'),
        f('sex', 'base', 'radio', 'Sex', '1, Male | 2, Female'),
        f('visit_date', 'base', 'text', 'Visit date', '', 'date_ymd'),
        f('weight', 'base', 'text', 'Weight (kg)', '', 'number'),
        f('glu', 'base', 'text', 'Glucose (mg/dL)'),
        f('bac', 'base', 'radio', 'Sputum smear', '1, Positive | 2, Negative'),
        f('fever', 'base', 'yesno', 'Fever'),
        f('comorb', 'base', 'checkbox', 'Comorbidities', '1, Diabetes | 2, Hypertension | 3, None'),
        f('notes', 'base', 'notes', 'Clinical notes'),
        f('site', 'base', 'dropdown', 'Clinic', '1, North | 2, South'),
        f('death_date', 'base', 'text', 'Date of death', '', 'date_ymd'),
        f('exitus', 'base', 'yesno', 'Died'),
        f('hr', 'base', 'text', 'Heart rate (bpm)', '', 'integer',
          "@HIDDEN\n@OMOP-DOMAIN=Measurement @OMOP-CONCEPT=\"3027018 | Heart rate\" @OMOP-UNIT=\"{beats}/min\" @OMOP-DATE=visit_date"),
        f('smear2', 'base', 'radio', 'Second smear', '1, Positive | 2, Negative', '',
          '@OMOP-DOMAIN=Measurement @OMOP-STRATEGY=value_concept @OMOP-CONCEPT="3016102 | AFB" @OMOP-VALUES="1=9191 | Positive; 2=-"'),
        f('med_start', 'meds', 'text', 'Start date', '', 'date_ymd'),
        f('drug', 'meds', 'dropdown', 'Drug', '1, Isoniazid | 2, Rifampin'),
        f('med_end', 'meds', 'text', 'End date', '', 'date_ymd'),
    ] as $m) {
        $dict[$m['field_name']] = $m;
    }
    return $dict;
}

/** One record in the format of REDCap::getData('array'), with repeated instances of "meds". */
function rec(string $id, array $base, array $meds = []): array
{
    $row = [];
    foreach (\REDCap::$dict as $n => $m) {
        $row[$n] = $m['field_type'] === 'checkbox' ? ['1' => '0', '2' => '0', '3' => '0'] : '';
    }
    $row['record_id'] = $id;
    foreach ($base as $k => $v) {
        $row[$k] = $v;
    }
    $out = [1 => $row];
    foreach ($meds as $i => $m) {
        $out['repeat_instances'][1]['meds'][$i + 1] = $m;
    }
    return $out;
}

function fixture_records(): array
{
    return [
        '1' => rec('1', ['dob' => '1960-05-10', 'sex' => '1', 'visit_date' => '2024-03-01', 'weight' => '70',
                         'glu' => '< 70', 'bac' => '1', 'fever' => '1', 'comorb' => ['1' => '1', '2' => '1', '3' => '0'],
                         'notes' => 'Patient John Smith reports cough', 'site' => '1', 'hr' => '72', 'smear2' => '1'],
                   [['med_start' => '2024-03-02', 'drug' => '1', 'med_end' => '2024-03-20'],
                    ['med_start' => '2024-03-05', 'drug' => '2', 'med_end' => '2024-03-01']]),
        '2' => rec('2', ['dob' => '1970-01-01', 'sex' => '2', 'visit_date' => '2024-04-01', 'weight' => '80',
                         'glu' => '1.234', 'bac' => '2', 'fever' => '0', 'site' => '2', 'death_date' => '2024-06-01',
                         'exitus' => '1', 'comorb' => ['1' => '0', '2' => '0', '3' => '1']]),
        '3' => rec('3', ['dob' => '1980-02-02', 'sex' => '1', 'visit_date' => '2024-05-01', 'weight' => '90',
                         'glu' => '95', 'bac' => '1', 'fever' => '1', 'site' => '1']),
    ];
}

/** Resets the simulated REDCap environment to a freshly created state. */
function reset_fixture(): void
{
    \REDCap::$dict = fixture_dict();
    \REDCap::$data = fixture_records();
    \REDCap::$dag = [];
    \Project::$conf = ['RepeatingFormsEvents' => [1 => ['meds' => '']], 'forms' => ['base' => [], 'meds' => []]];
    \TestUser::$super = true;
    \TestUser::$rights = [];
    \ExternalModules\AbstractExternalModule::$lang = parse_ini_file(dirname(__DIR__, 2) . '/lang/English.ini');
}

/** The module configured for the test project; $settings replaces or adds settings. */
function make_module(array $settings = []): OmopCdmExportModule
{
    $m = new OmopCdmExportModule();
    $m->settings = $settings + [
        'birth-date-field' => 'dob', 'gender-field' => 'sex', 'default-date-field' => 'visit_date',
        'generate-visits' => true, 'visit-concept-id' => '9202', 'death-date-field' => 'death_date',
        'care-site-field' => 'site', 'cdm-holder' => 'Test', 'vocabulary-version' => 'v5',
        'cdm-source-name' => 'Test', 'reserved-language-project' => 'English',
    ];
    return $m;
}

/** Approves the mapping a curator would make for the test project. */
function curate(OmopCdmExportModule $m): void
{
    $s = $m->store();
    $d = \REDCap::$dict;
    $p = $m->profile();
    $ap = function ($f, $ch) use ($s, $d, $p) {
        return $s->updateField($f, $ch + ['state' => 'approved'], $d[$f], $p[$f]['choices'] ?? []);
    };
    $s->updateValue('sex', '1', ['emit' => '1', 'concept_id' => '8507', 'concept_name' => 'MALE'], 'Male', $d['sex']);
    $s->updateValue('sex', '2', ['emit' => '1', 'concept_id' => '8532', 'concept_name' => 'FEMALE'], 'Female', $d['sex']);
    $ap('sex', ['domain' => 'Person', 'value_strategy' => 'per_choice']);
    $ap('weight', ['domain' => 'Measurement', 'concept_id' => '3025315', 'concept_name' => 'Body weight', 'unit_source_value' => 'kg', 'date_field' => 'visit_date']);
    $ap('glu', ['domain' => 'Measurement', 'concept_id' => '3004501', 'concept_name' => 'Glucose', 'unit_source_value' => 'mg/dL', 'date_field' => 'visit_date']);
    $s->updateValue('bac', '1', ['emit' => '1', 'concept_id' => '9191', 'concept_name' => 'Positive'], 'Positive', $d['bac']);
    $s->updateValue('bac', '2', ['emit' => '1', 'concept_id' => '9189', 'concept_name' => 'Negative'], 'Negative', $d['bac']);
    $ap('bac', ['domain' => 'Measurement', 'value_strategy' => 'value_concept', 'concept_id' => '3016102', 'concept_name' => 'AFB', 'date_field' => 'visit_date']);
    $s->updateValue('fever', '1', ['emit' => '1', 'concept_id' => '437663', 'concept_name' => 'Fever'], 'Yes', $d['fever']);
    $ap('fever', ['domain' => 'Condition', 'value_strategy' => 'per_choice', 'date_field' => 'visit_date']);
    $s->updateValue('comorb', '1', ['emit' => '1', 'concept_id' => '201820', 'concept_name' => 'Diabetes mellitus'], 'Diabetes', $d['comorb']);
    $s->updateValue('comorb', '2', ['emit' => '1', 'concept_id' => '316866', 'concept_name' => 'Hypertensive disorder'], 'Hypertension', $d['comorb']);
    $s->updateValue('comorb', '3', ['emit' => '0'], 'None', $d['comorb']);
    $ap('comorb', ['domain' => 'Condition', 'value_strategy' => 'per_choice', 'date_field' => 'visit_date']);
    $ap('notes', ['domain' => 'Note', 'concept_id' => '44814637', 'concept_name' => 'Discharge summary', 'date_field' => 'visit_date']);
    $s->updateValue('drug', '1', ['emit' => '1', 'concept_id' => '1782521', 'concept_name' => 'isoniazid'], 'Isoniazid', $d['drug']);
    $s->updateValue('drug', '2', ['emit' => '1', 'concept_id' => '1763204', 'concept_name' => 'rifampin'], 'Rifampin', $d['drug']);
    $ap('drug', ['domain' => 'Drug', 'value_strategy' => 'per_choice', 'date_field' => 'med_start', 'end_date_field' => 'med_end']);
    $ap('exitus', ['domain' => 'Death', 'value_strategy' => 'per_choice']);
}

/** Groups rows by the value of a column. */
function group_by(array $rows, string $col): array
{
    $o = [];
    foreach ($rows as $r) {
        $o[$r[$col]][] = $r;
    }
    return $o;
}

/** Classification of a single field (shortcut for the classifier specs). */
function classify(string $label, string $type = 'text', string $val = '', string $ch = '', string $name = 'x'): string
{
    $prof = new \OmopCdm\OmopCdmExportModule\DictionaryProfiler();
    return $prof->profile([$name => f($name, 'f', $type, $label, $ch, $val)])[$name]['suggested_domain'];
}
