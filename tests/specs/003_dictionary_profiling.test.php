<?php
// Spec 003 — Data dictionary profiling
use OmopCdm\OmopCdmExportModule\{DictionaryProfiler, CdmModel};

// Profile of a single field, with no translator (the reason comes out as «key(args)»).
$one = function (string $label, string $type = 'text', string $val = '', string $ch = '', string $name = 'x',
                 string $ident = '', array $structure = []): array {
    $p = new DictionaryProfiler();
    return $p->profile([$name => f($name, 'f', $type, $label, $ch, $val, '', $ident)], $structure)[$name];
};

section('Profile of the test project');
$m = make_module();
$p = $m->profile();
test('FR-003-01', 'one row per field, in dictionary order', array_keys($p) === array_keys(\REDCap::$dict));
test('FR-003-01', 'every row has the 16 profile keys', array_keys($p['weight']) === ['field_name', 'form_name',
    'field_type', 'validation', 'field_label', 'is_identifier', 'value_kind', 'unit_hint', 'suggested_strategy',
    'mappable', 'choices', 'choice_count', 'suggested_domain', 'suggested_table', 'confidence', 'reason']);
test('FR-003-01', 'the row copies name, instrument, type and validation from the dictionary',
    $p['drug']['form_name'] === 'meds' && $p['drug']['field_type'] === 'dropdown' && $p['dob']['validation'] === 'date_ymd');
test('FR-003-01', 'suggested_table is the table of the proposed domain (null if it has none)',
    $p['weight']['suggested_table'] === 'measurement' && $p['drug']['suggested_table'] === 'drug_exposure'
    && $p['site']['suggested_table'] === null && $p['med_start']['suggested_table'] === null);
$ok = true;
foreach ($p as $r) {
    $ok = $ok && $r['suggested_table'] === CdmModel::tableForDomain($r['suggested_domain']);
}
test('FR-003-01', 'suggested_table matches CdmModel::tableForDomain for every field', $ok);

test('FR-003-02', 'the label is stripped of HTML and whitespace', $one('  <b>Weight</b> (kg) ', 'text', 'number')['field_label'] === 'Weight (kg)');
test('FR-003-02', 'HTML in the label does not prevent recognising the unit', $one('<span>Peso</span> (kg)', 'text', 'number')['unit_hint'] === 'kg');

section('Nature of the value');
test('FR-003-03', 'radio, dropdown, checkbox, yes/no and true/false are categorical',
    $p['sex']['value_kind'] === 'categorical' && $p['site']['value_kind'] === 'categorical'
    && $p['comorb']['value_kind'] === 'categorical' && $p['fever']['value_kind'] === 'categorical'
    && $one('Is it true', 'truefalse')['value_kind'] === 'categorical');
test('FR-003-03', 'text with numeric validation and slider are numeric',
    $p['weight']['value_kind'] === 'numeric' && $p['hr']['value_kind'] === 'numeric'
    && $one('Something', 'text', 'number_2dp')['value_kind'] === 'numeric' && $one('Something', 'slider')['value_kind'] === 'numeric');
test('FR-003-03', 'text without numeric validation and notes are text',
    $p['glu']['value_kind'] === 'text' && $p['notes']['value_kind'] === 'text' && $one('Something', 'text', 'email')['value_kind'] === 'text');
test('FR-003-03', 'date* and datetime* validations are dates',
    $p['dob']['value_kind'] === 'date' && $one('Time', 'text', 'datetime_seconds_ymd')['value_kind'] === 'date');
test('FR-003-03', 'the Record ID is «identifier» and value-less types are «none»',
    $p['record_id']['value_kind'] === 'identifier' && $one('Info', 'descriptive')['value_kind'] === 'none');

section('Choices');
test('FR-003-04', 'dictionary choices are turned into code => label',
    $p['comorb']['choices'] === ['1' => 'Diabetes', '2' => 'Hypertension', '3' => 'None'] && $p['comorb']['choice_count'] === 3);
test('FR-003-04', 'it splits on the first comma, trims and drops malformed chunks',
    DictionaryProfiler::parseChoices('1, Sí | 0, No | sin coma | 2,  Tal, cual |  | 3,x')
    === ['1' => 'Sí', '0' => 'No', '2' => 'Tal, cual', '3' => 'x']);
test('FR-003-04', 'an empty string has no choices', DictionaryProfiler::parseChoices('') === []);
test('FR-003-04', 'the module exposes the same parser', \OmopCdm\OmopCdmExportModule\OmopCdmExportModule::parseChoices('1, A | 2, B') === ['1' => 'A', '2' => 'B']);
test('FR-003-05', 'a yes/no has its implicit choices 1 = Yes, 0 = No', $p['fever']['choices'] === ['1' => 'Yes', '0' => 'No']);
test('FR-003-05', 'a true/false has 1 = True, 0 = False', $one('Something', 'truefalse')['choices'] === ['1' => 'True', '0' => 'False']);
test('FR-003-05', 'implicit choices override whatever the dictionary contains',
    DictionaryProfiler::choicesFor(['field_type' => 'yesno', 'select_choices_or_calculations' => '9, Otro']) === ['1' => 'Yes', '0' => 'No']);
$bmi = $one('IMC', 'calc', '', 'round([peso]*10000/([talla]*[talla]),1)');
test('FR-003-05', 'the formula of a calculated field is not parsed as choices',
    $bmi['choices'] === [] && $bmi['choice_count'] === 0
    && DictionaryProfiler::choicesFor(['field_type' => 'calc', 'select_choices_or_calculations' => 'if([a]=1, 2, 3)']) === []);
test('FR-003-05', 'a non-categorical field never has choices, whatever the column contains',
    DictionaryProfiler::choicesFor(['field_type' => 'text', 'select_choices_or_calculations' => '1, A | 2, B']) === []
    && DictionaryProfiler::choicesFor(['field_type' => 'slider', 'select_choices_or_calculations' => '0, Nada | 100, Mucho']) === []
    && DictionaryProfiler::choicesFor(['field_type' => 'sql', 'select_choices_or_calculations' => 'select a, b from t']) === []);
test('FR-003-05', 'radio, dropdown and checkbox keep their declared choices',
    DictionaryProfiler::choicesFor(['field_type' => 'radio', 'select_choices_or_calculations' => '1, A | 2, B']) === ['1' => 'A', '2' => 'B']
    && DictionaryProfiler::choicesFor(['field_type' => 'checkbox', 'select_choices_or_calculations' => '1, A']) === ['1' => 'A']);
test('FR-003-05', 'the negative choice only exists in categorical fields',
    !DictionaryProfiler::isNegativeChoice(['field_type' => 'calc', 'select_choices_or_calculations' => 'if([a]=1, No | 2, Sí'], 'if([a]=1')
    && DictionaryProfiler::isNegativeChoice(['field_type' => 'radio', 'select_choices_or_calculations' => '1, Sí | 2, No'], '2'));

section('Unit');
test('FR-003-06', 'the unit in the trailing parenthesis is detected in numeric fields', $p['weight']['unit_hint'] === 'kg' && $p['hr']['unit_hint'] === 'bpm');
test('FR-003-06', 'any parenthesis with / % ^ ° is accepted',
    $one('Glucose (mmol/L)', 'text', 'number')['unit_hint'] === 'mmol/L' && $one('Saturación (%)', 'text', 'integer')['unit_hint'] === '%'
    && $one('Temperatura (°C)', 'text', 'number_1dp')['unit_hint'] === '°C');
test('FR-003-06', 'an abbreviation that is not a unit is not taken for a unit',
    $one('LDL (LDL)', 'text', 'number')['unit_hint'] === null && $one('Colesterol (poner número exacto)', 'text', 'number')['unit_hint'] === null);
test('FR-003-06', 'a non-numeric field carries no unit', $p['glu']['unit_hint'] === null && $one('Peso (kg)')['unit_hint'] === null);
test('FR-003-06', 'the unit is added to the reason', strpos($one('Something (mg)', 'text', 'integer')['reason'], 'r_fb_num_unit(mg)') === 0);

section('Identifiers');
test('FR-003-07', 'the REDCap identifier flag is honoured («y», case-insensitive)',
    $one('Something', 'text', '', '', 'x', ' Y ')['is_identifier'] === true && $p['weight']['is_identifier'] === false);
$cp = $one('Postal code', 'text', '', '', 'cp', 'y');
test('FR-003-07', 'a field flagged as identifier does not reach the CDM', $cp['suggested_domain'] === 'NotMapped'
    && $cp['confidence'] === 'high' && $cp['reason'] === 'r_phi_flag' && $cp['mappable'] === 'no');
test('FR-003-07', 'exception: a flagged date of birth is still Person data',
    $one('Date of birth', 'text', 'date_dmy', '', 'dob', 'y')['suggested_domain'] === 'Person');
$sx = $one('Sexo', 'radio', '', '1, Hombre | 2, Mujer', 'sx', 'y');
test('FR-003-07', 'exception: a flagged sex field is still Person data, with a warning in the reason',
    $sx['suggested_domain'] === 'Person' && substr($sx['reason'], -9) === 'r_sfx_phi');

$rid = $one('Anything', 'text', '', '', 'study_id', 'y', ['record_id_field' => 'study_id']);
test('FR-003-08', 'the project Record ID is Person with high confidence, even if flagged',
    $rid['suggested_domain'] === 'Person' && $rid['confidence'] === 'high' && $rid['reason'] === 'r_record_id');
test('FR-003-08', 'without a structure there is no Record ID: the same field is excluded by its flag',
    $one('Anything', 'text', '', '', 'study_id', 'y')['suggested_domain'] === 'NotMapped');

section('Fields that do not represent an observed value');
$ok = true;
foreach (['descriptive', 'file', 'signature', 'calc'] as $type) {
    $r = $one('Peso (kg)', $type, '', $type === 'calc' ? '[a]+[b]' : '');
    $ok = $ok && $r['suggested_domain'] === 'NotMapped' && $r['confidence'] === 'high' && $r['mappable'] === 'no'
        && $r['reason'] === "r_skip_type($type)" && $r['unit_hint'] === null;
}
test('FR-003-09', 'descriptive, file, signature and calculated fields → NotMapped, even with a clinical label', $ok);

section('Dates');
$f = $one('Fecha', 'text', 'date_ymd');
test('FR-003-10', 'a date with no meaning of its own is a candidate date anchor', $f['suggested_domain'] === 'DateAnchor'
    && $f['confidence'] === 'high' && $f['reason'] === 'r_date_anchor(date_ymd)' && $f['unit_hint'] === null);
test('FR-003-10', 'the test project dates without semantics are anchors', $p['med_start']['suggested_domain'] === 'DateAnchor'
    && $p['med_end']['suggested_domain'] === 'DateAnchor');

section('Mappability and strategy');
test('FR-003-11', 'excluded → «no»', $p['site']['mappable'] === 'no');
test('FR-003-11', 'free text → «manual»', $p['glu']['mappable'] === 'manual');
test('FR-003-11', 'clinical note (text that goes to NOTE) → «auto»', $p['notes']['suggested_domain'] === 'Note' && $p['notes']['mappable'] === 'auto');
test('FR-003-11', 'numeric, categorical or date → «auto»', $p['weight']['mappable'] === 'auto' && $p['sex']['mappable'] === 'auto'
    && $p['dob']['mappable'] === 'auto');
test('FR-003-12', 'a non-categorical field has no strategy', $p['weight']['suggested_strategy'] === null);
test('FR-003-12', 'a rating scale → a single concept',
    DictionaryProfiler::suggestedStrategy('categorical', ['0' => '0 Nada', '1' => '1', '2' => '2', '3' => '3', '4' => '4', '5' => '5 Máximo'], 'Measurement') === 'single_concept');
test('FR-003-12', 'a categorical (non yes/no) Measurement or Observation result → value_concept',
    $p['bac']['suggested_strategy'] === 'value_concept' && $p['smear2']['suggested_strategy'] === 'value_concept');
test('FR-003-12', 'yes/no, sex, comorbidities and drugs → one concept per choice',
    $p['fever']['suggested_strategy'] === 'per_choice' && $p['sex']['suggested_strategy'] === 'per_choice'
    && $p['comorb']['suggested_strategy'] === 'per_choice' && $p['drug']['suggested_strategy'] === 'per_choice');
test('FR-003-12', 'a Measurement yes/no is still per choice',
    DictionaryProfiler::suggestedStrategy('categorical', ['1' => 'Sí', '0' => 'No'], 'Measurement') === 'per_choice');

section('Translated reasons');
test('FR-003-13', 'without a translator, the reason is the key with its arguments', $one('Peso (kg)', 'text', 'number')['reason'] === 'r_keyword(peso)r_sfx_unit(kg)');
$tr = new DictionaryProfiler(fn($k, ...$a) => strtoupper($k) . (count($a) ? ':' . implode(',', $a) : ''));
test('FR-003-13', 'with a translator, the reason is built with it',
    $tr->profile(['x' => f('x', 'f', 'text', 'Fecha', '', 'date_ymd')])['x']['reason'] === 'R_DATE_ANCHOR:date_ymd');
test('FR-003-13', 'the module translates reasons into the project language',
    $p['weight']['reason'] === "Matches 'weight' in the field name or label. Unit detected in the label: 'kg'");

section('Project structure');
$e = $m->getProjectStructure();
test('FR-003-14', 'the structure gives the Record ID, the instruments and the repeating ones',
    $e['record_id_field'] === 'record_id' && $e['forms'] === ['base', 'meds'] && $e['longitudinal'] === false
    && $e['repeating'] === [['event_id' => 1, 'form' => 'meds']] && $e['project_id'] === 1);
\Project::$conf['RepeatingFormsEvents'] = [1 => 'WHOLE'];
$e2 = make_module()->getProjectStructure();
test('FR-003-14', 'a whole repeating event appears with an empty instrument', $e2['repeating'] === [['event_id' => 1, 'form' => '']]);
\Project::$conf['RepeatingFormsEvents'] = [1 => ['meds' => '']];
test('FR-003-14', 'the module passes the structure\'s Record ID to the profiler', $p['record_id']['reason'] === 'Project Record ID → person_source_value');

section('Candidate dates');
$m = make_module();
test('FR-003-15', 'dateFields() lists the fields with date validation and their label',
    $m->dateFields() === ['dob' => 'Date of birth', 'visit_date' => 'Visit date', 'death_date' => 'Date of death',
                          'med_start' => 'Start date', 'med_end' => 'End date']);
test('FR-003-15', 'isDateField() recognises date validation', $m->isDateField('dob') && !$m->isDateField('weight') && !$m->isDateField('nope'));
test('FR-003-15', 'a field is dated with the first date of its instrument, not counting the date of birth',
    $m->suggestedDateField('weight') === 'visit_date' && $m->suggestedDateField('drug') === 'med_start');
test('FR-003-15', 'a date field is dated by itself', $m->suggestedDateField('med_end') === 'med_end');
test('FR-003-15', 'a Death fact is dated with the configured date of death', $m->suggestedDateField('exitus', 'Death') === 'death_date');
\REDCap::$dict['other_field'] = f('other_field', 'no_dates', 'text', 'Other', '', 'integer');
$m = make_module();
test('FR-003-15', 'with no dates in the instrument, none is proposed', $m->suggestedDateField('other_field') === null
    && $m->suggestedDateField('does_not_exist') === null);
reset_fixture();

section('Non-functional requirements');
$before = \REDCap::$dict;
(new DictionaryProfiler())->profile(\REDCap::$dict, ['record_id_field' => 'record_id']);
test('NFR-003-01', 'the profiler works without the module or REDCap and does not modify the dictionary', \REDCap::$dict === $before);
$large = [];
for ($i = 0; $i < 1000; $i++) {
    $large["c$i"] = f("c$i", 'f' . ($i % 10), ['text', 'radio', 'yesno', 'notes'][$i % 4],
        ['Peso (kg)', 'Diagnóstico principal', 'Centro de salud', 'Fecha de la visita'][$i % 4],
        $i % 4 === 1 ? '1, A | 2, B | 3, C' : '', $i % 4 === 0 ? 'number' : '');
}
$t0 = microtime(true);
$profile = (new DictionaryProfiler())->profile($large);
$secs = microtime(true) - $t0;
// With Xdebug loaded (development environment) PHP runs several times slower: the limit is multiplied by 5.
$limit = extension_loaded('xdebug') ? 10.0 : 2.0;
test('NFR-003-02', 'profiling 1,000 fields takes less than 2 s (10 s with Xdebug)', count($profile) === 1000 && $secs < $limit, sprintf('%.2f s', $secs));
