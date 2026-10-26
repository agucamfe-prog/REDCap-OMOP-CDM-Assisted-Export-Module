<?php
// Spec 004 — Bilingual domain classifier
use OmopCdm\OmopCdmExportModule\{DictionaryProfiler, TextNormalizer, CdmModel};

// Full profile row of one field (classify() only returns the domain).
$row = function (string $label, string $type = 'text', string $val = '', string $ch = '', string $name = 'x'): array {
    return (new DictionaryProfiler())->profile([$name => f($name, 'f', $type, $label, $ch, $val)])[$name];
};

section('Normalisation and matching');
test('FR-004-01', 'normalize: lower case, no accents or punctuation, collapsed whitespace',
    TextNormalizer::normalize("  ¿Tensión  ARTERIAL-sistólica? (mmHg) ") === 'tension arterial sistolica mmhg');
test('FR-004-01', 'upper case, accents and hyphens do not change the classification',
    classify('TENSIÓN-ARTERIAL sistólica', 'text', 'integer') === 'Measurement'
    && $row('TENSIÓN-ARTERIAL sistólica', 'text', 'integer')['reason'] === 'r_keyword(tension arterial)');
test('FR-004-01', 'tokens: normalised, unique words with a minimum length',
    TextNormalizer::tokens('La tos y la fiebre, FIEBRE', 3) === ['tos', 'fiebre']);
test('FR-004-02', 'a term only matches as a whole word: «lab» does not match «laboral»',
    $row('Situación laboral', 'dropdown', '', '1, A | 2, B')['reason'] === 'r_sdoh(situacion laboral)');
test('FR-004-02', 'a term with an asterisk is a stem: «diagnos*» matches «Diagnosticado»',
    $row('Diagnosticado de EPOC', 'yesno')['reason'] === 'r_keyword(diagnos*)r_sfx_choices');
test('FR-004-03', 'the label rules: «peso_visita» labelled «Peso» is Measurement',
    classify('Peso', 'text', 'number', '', 'peso_visita') === 'Measurement');
test('FR-004-03', 'the variable name only counts if the label gives no signal',
    classify('', 'yesno', '', '', 'visita_extra') === 'Visit');

section('Specificity and confidence');
test('FR-004-04', 'the longest term wins: «Pérdida de peso» is Condition, not Measurement',
    classify('Pérdida de peso', 'yesno') === 'Condition');
test('FR-004-04', 'a multi-word term gives high confidence', $row('Tensión arterial sistólica (mmHg)', 'text', 'integer')['confidence'] === 'high');
test('FR-004-04', 'a one-word term gives medium confidence', $row('Peso', 'text', 'number')['confidence'] === 'medium');

section('Administrative fields');
test('FR-004-05', 'identifiers and contact details → NotMapped with high confidence',
    $row('Teléfono de contacto')['suggested_domain'] === 'NotMapped' && $row('Teléfono de contacto')['confidence'] === 'high'
    && classify('Patient name') === 'NotMapped' && classify('¿Firmó el consentimiento informado?', 'yesno') === 'NotMapped');
test('FR-004-05', 'exclusion comes before specificity: «Dirección de residencia» is not Person',
    $row('Dirección de residencia')['reason'] === 'r_admin(direccion)');
test('FR-004-05', '«Nombre» on its own is an identifier; «Nombre del medicamento» is a drug',
    classify('Nombre') === 'NotMapped' && classify('Full name') === 'NotMapped' && classify('Nombre del medicamento') === 'Drug');

section('Health system entities');
test('FR-004-06', 'a label that starts with the entity → NotMapped (structural)',
    $row('Centro de Salud', 'dropdown', '', '1, A | 2, B')['reason'] === 'r_structural(centro de salud)'
    && classify('Clinic', 'dropdown', '', '1, A | 2, B') === 'NotMapped');
test('FR-004-06', 'the prefixes «nombre del», «tipo de» do not change that the entity is named',
    classify('Nombre del centro') === 'NotMapped' && classify('Tipo de servicio', 'dropdown', '', '1, A | 2, B') === 'NotMapped');
test('FR-004-06', 'an entity in the middle of the label is a patient fact',
    classify('Ingreso en hospital', 'yesno') === 'Visit' && classify('Admitted to hospital', 'yesno') === 'Visit');
test('FR-004-06', 'a question never names an entity', classify('¿Derivado a centro de salud?', 'yesno') === 'Visit');
test('FR-004-06', 'amendment: «Responsible rheumatologist» is an entity, not a fact',
    classify('Responsible rheumatologist', 'dropdown', '', '1, A | 2, B') === 'NotMapped');
test('FR-004-06', 'amendment: the prefixes treating and lead are ignored',
    classify('Treating physician', 'dropdown', '', '1, A | 2, B') === 'NotMapped'
    && classify('Lead surgeon', 'dropdown', '', '1, A | 2, B') === 'NotMapped');
// «attending» is both a lexicon term and a prefix: the label names the entity
// whether the prefix matches or what follows it does.
test('FR-004-06', 'amendment: the prefix attending is ignored («Attending physician», «Attending doctor»)',
    classify('Attending physician', 'dropdown', '', '1, A | 2, B') === 'NotMapped'
    && classify('Attending doctor', 'dropdown', '', '1, A | 2, B') === 'NotMapped'
    && classify('Attending', 'dropdown', '', '1, A | 2, B') === 'NotMapped');

section('Groups and domains');
test('FR-004-07', 'social determinant → Observation', $row('Educational level', 'dropdown', '', '1, A | 2, B')['reason'] === 'r_sdoh(educational level)');
test('FR-004-07', 'categorical lifestyle → Observation; quantified → Measurement',
    classify('Fumador', 'radio', '', '1, Sí | 2, No') === 'Observation' && classify('Cigarrillos al día', 'text', 'integer') === 'Measurement');
test('FR-004-07', 'an intensity rating → Measurement', $row('Intensidad del dolor', 'text', 'integer')['reason'] === 'r_severity(intensidad)');
test('FR-004-07', 'a categorical scale or questionnaire item → Observation',
    $row('NIHSS', 'radio', '', '1, Leve | 2, Moderado | 3, Grave')['reason'] === 'r_scale_item(nihss)'
    && classify('PHQ-9 item 1', 'radio', '', '0, Not at all | 1, Several days') === 'Observation');
test('FR-004-07', 'amendment: the numeric score of a scale → Measurement',
    classify('NIHSS score on admission', 'text', 'integer') === 'Measurement'
    && $row('Puntuación Barthel', 'text', 'integer')['reason'] === 'r_scale_score(puntuacion)'
    && classify('DAS28', 'text', 'number') === 'Measurement');
test('FR-004-07', 'in a numeric field the scale beats the clinical term, even if shorter',
    $row('Pain (VAS)', 'text', 'integer')['reason'] === 'r_severity(vas)'
    && classify('Pain (VAS)', 'slider') === 'Measurement' && classify('Pain score', 'text', 'integer') === 'Measurement'
    && classify('EVA', 'text', 'integer') === 'Measurement' && classify('Escala de dolor', 'text', 'integer') === 'Measurement'
    && classify('Dolor (EVA)', 'slider') === 'Measurement');
test('FR-004-07', 'in a categorical field the longest clinical term still rules',
    classify('Pain (VAS)', 'yesno') === 'Condition');
test('FR-004-07', 'the remaining terms go to their OMOP domain',
    classify('Biopsia', 'yesno') === 'Procedure' && classify('Swab sample', 'yesno') === 'Specimen'
    && classify('Pacemaker', 'yesno') === 'Device' && classify('Died', 'yesno') === 'Death'
    && classify('Age at diagnosis', 'text', 'integer') === 'Observation');

section('Rating scales');
$vas = $row('Dolor', 'radio', '', '0, 0 Sin dolor | 1, 1 | 2, 2 | 3, 3 | 4, 4 | 5, 5 Máximo');
test('FR-004-08', 'a categorical field with ≥ 5 numbered choices is a scale → Measurement, high',
    $vas['suggested_domain'] === 'Measurement' && $vas['confidence'] === 'high' && $vas['reason'] === 'r_rating_scale(6)');
test('FR-004-08', 'labels are checked, not codes: «Bajo…Extremo» is not a scale',
    classify('Nivel', 'dropdown', '', '1, Bajo | 2, Medio | 3, Alto | 4, Muy alto | 5, Extremo') === 'Observation');
test('FR-004-08', 'with fewer than 5 choices it is not a scale',
    !DictionaryProfiler::looksLikeRatingScaleStatic(['1' => '1', '2' => '2', '3' => '3', '4' => '4']));

section('Dates');
test('FR-004-09', 'date of birth, death and admission → Person, Death and Visit with high confidence',
    classify('Fecha de nacimiento', 'text', 'date_dmy') === 'Person' && classify('Exitus fecha', 'text', 'date_ymd') === 'Death'
    && $row('Fecha de ingreso', 'text', 'date_ymd')['reason'] === 'r_date_semantic(Visit|ingreso)');
test('FR-004-09', 'a blood draw date is still a date anchor', classify('Fecha de extracción', 'text', 'date_ymd') === 'DateAnchor');
test('FR-004-09', 'amendment: date of diagnosis → Condition', classify('Date of RA diagnosis', 'text', 'date_ymd') === 'Condition'
    && $row('Fecha de diagnóstico de tuberculosis', 'text', 'date_ymd')['reason'] === 'r_date_fact(tuberculosis)');
test('FR-004-09', 'amendment: date of disease onset → Condition with medium confidence',
    $row('Date of stroke onset', 'text', 'date_ymd')['confidence'] === 'medium'
    && classify('Fecha de inicio de síntomas', 'text', 'date_ymd') === 'Condition');
test('FR-004-09', 'amendment: the start date of a treatment is still an anchor',
    classify('Fecha de inicio del tratamiento', 'text', 'date_ymd') === 'DateAnchor'
    && classify('Fecha de la fiebre', 'text', 'date_ymd') === 'DateAnchor');

section('No signal and reason suffixes');
$num = $row('Algo', 'text', 'integer');
test('FR-004-10', 'numeric with no signal → Measurement with low confidence', $num['suggested_domain'] === 'Measurement'
    && $num['confidence'] === 'low' && $num['reason'] === 'r_fb_num');
test('FR-004-10', 'categorical or text with no signal → Observation with low confidence',
    $row('Algo', 'dropdown', '', '1, a | 2, b')['reason'] === 'r_fb_cat' && $row('Algo')['reason'] === 'r_fb_text'
    && $row('Algo')['confidence'] === 'low');
$txt = $row('Nombre del medicamento');
test('FR-004-10', 'free text with a signal: low confidence and a manual coding warning', $txt['confidence'] === 'low'
    && $txt['reason'] === 'r_keyword(nombre del medicamento)r_sfx_freetext');
test('FR-004-10', 'a categorical condition warns that one must decide which choices emit',
    $row('Fever', 'yesno')['reason'] === 'r_keyword(fever)r_sfx_choices');

section('Context phrases');
test('FR-004-11', '«en el momento de la visita» does not drag the field to Visit', classify('¿Refiere dolor en el momento de la visita?', 'yesno') === 'Condition');
test('FR-004-11', '«medido en la visita» does not drag the field to Visit', classify('Peso medido en la visita', 'text', 'number') === 'Measurement');
test('FR-004-11', 'amendment: «at discharge» does not drag the field to Visit', classify('Antithrombotic at discharge', 'dropdown', '', '1, Aspirin | 2, None') === 'Drug'
    && classify('Pain at discharge', 'yesno') === 'Condition');
test('FR-004-11', 'amendment: «desde la última consulta» and «al alta» do not drag the field to Visit',
    classify('Dolor desde la última consulta', 'yesno') === 'Condition' && classify('Disnea al alta', 'yesno') === 'Condition');
test('FR-004-11', 'without a context phrase, «Discharge» is Visit', classify('Discharge', 'yesno') === 'Visit');

section('Invariants and bilingualism');
$pairs = [['Fiebre', 'Fever', 'yesno', ''], ['Peso', 'Weight', 'text', 'number'], ['Tabaquismo', 'Smoking', 'radio', ''],
          ['Biopsia', 'Biopsy', 'yesno', ''], ['Fecha de nacimiento', 'Date of birth', 'text', 'date_ymd'],
          ['Hemoglobina', 'Haemoglobin', 'text', 'number']];
$ok = true;
foreach ($pairs as [$es, $en, $ty, $va]) {
    $ok = $ok && classify($es, $ty, $va, $ty === 'radio' ? '1, A | 2, B' : '') === classify($en, $ty, $va, $ty === 'radio' ? '1, A | 2, B' : '');
}
test('FR-004-12', 'the same field in Spanish and in English goes to the same domain', $ok);
reset_fixture();
$withTt = make_module()->profile();
$withoutTt = (new DictionaryProfiler())->profile(\REDCap::$dict, ['record_id_field' => 'record_id']);
test('FR-004-12', 'the domain does not depend on the interface language',
    array_column($withTt, 'suggested_domain', 'field_name') === array_column($withoutTt, 'suggested_domain', 'field_name'));
$all = $withoutTt + (new DictionaryProfiler())->profile(['a' => f('a', 'f', 'text', 'Algo'), 'b' => f('b', 'f', 'calc', 'IMC', '[p]/[t]')]);
test('FR-004-12', 'every proposal has a valid domain, high/medium/low confidence and a reason',
    array_filter($all, fn($r) => !in_array($r['suggested_domain'], CdmModel::validDomains(), true)
        || !in_array($r['confidence'], ['high', 'medium', 'low'], true) || trim($r['reason']) === '') === []);

section('Amendment: lexicon, notes and British spelling');
test('FR-004-13', 'fatiga, hipotiroidismo and anemia → Condition',
    classify('Fatiga', 'yesno') === 'Condition' && classify('Hipotiroidismo', 'yesno') === 'Condition'
    && classify('Anemia', 'yesno') === 'Condition' && classify('Fatigue', 'yesno') === 'Condition');
test('FR-004-13', 'NT-proBNP and PCR (CRP) → Measurement',
    $row('NT-proBNP (pg/mL)', 'text', 'number')['reason'] === 'r_keyword(nt probnp)r_sfx_unit(pg/mL)'
    && classify('PCR (mg/L)', 'text', 'number') === 'Measurement'
    && classify('Proteína C reactiva (mg/L)', 'text', 'number') === 'Measurement');
test('FR-004-13', 'prednisona → Drug; ventilación → Procedure',
    classify('Prednisona', 'yesno') === 'Drug' && classify('Prednisone dose (mg)', 'text', 'number') === 'Drug'
    && classify('Ventilación mecánica', 'yesno') === 'Procedure' && classify('Ventilation', 'yesno') === 'Procedure');
test('FR-004-14', 'clinical note → Note', classify('Daily progress note', 'notes') === 'Note');
test('FR-004-14', 'progress notes and discharge report → Note with medium confidence',
    $row('Notas de evolución', 'notes')['reason'] === 'r_note' && $row('Evolución', 'notes')['confidence'] === 'medium'
    && classify('Informe de alta', 'notes') === 'Note');
test('FR-004-14', 'notes fields only: a short-text «Evolución» is not Note', classify('Evolución') !== 'Note'
    && classify('Algo', 'notes') === 'Observation');
test('FR-004-15', 'British spelling is recognised',
    classify('Haemoglobin (g/dL)', 'text', 'number') === 'Measurement' && classify('Oedema', 'yesno') === 'Condition'
    && classify('Dyspnoea', 'yesno') === 'Condition' && classify('Anaemia', 'yesno') === 'Condition'
    && classify('Ischaemic heart disease', 'yesno') === 'Condition' && classify('Glycaemia (mmol/L)', 'text', 'number') === 'Measurement'
    && classify('Hypoglycaemia episodes', 'yesno') === 'Condition');
$spellingPairs = [['Leukaemia', 'Leukemia', 'yesno', ''], ['Diarrhoea', 'Diarrhea', 'yesno', ''],
            ['Haemoglobin (g/dL)', 'Hemoglobin (g/dL)', 'text', 'number'], ['Oedema', 'Edema', 'yesno', ''],
            ['Paediatric asthma', 'Pediatric asthma', 'yesno', ''], ['Health centre', 'Health center', 'dropdown', '']];
$different = [];
foreach ($spellingPairs as [$gb, $us, $ty, $va]) {
    $ch = $ty === 'dropdown' ? '1, A | 2, B' : '';
    if ($row($gb, $ty, $va, $ch)['reason'] !== $row($us, $ty, $va, $ch)['reason']) {
        $different[] = $gb;
    }
}
test('FR-004-15', 'the classifier americanises: British spelling gives the same domain and reason as American',
    $different === [], implode(',', $different));
test('FR-004-15', 'leukaemia and diarrhoea, in any spelling, are Condition',
    classify('Leukaemia', 'yesno') === 'Condition' && classify('Leucemia', 'yesno') === 'Condition'
    && classify('Diarrhoea', 'yesno') === 'Condition' && classify('Diarrea', 'yesno') === 'Condition');
test('FR-004-15', 'americanize turns British spelling into that of the OHDSI vocabularies',
    TextNormalizer::americanize('Haemoglobin, anaemia, oedema, Paediatric tumour centre, ischaemia, leukaemia, foetal, oesophagus, glycaemia, diarrhoea, dyspnoea, oestrogen')
    === 'hemoglobin, anemia, edema, pediatric tumor center, ischemia, leukemia, fetal, esophagus, glycemia, diarrhea, dyspnea, estrogen');

section('Benchmark');
$prof = new DictionaryProfiler();
$fh = fopen(dirname(__DIR__) . '/fixtures/benchmark.csv', 'r');
$h = fgetcsv($fh, 0, ',', '"', '');
$acc = ['dev' => [0, 0], 'holdout' => [0, 0]];
$langs = [];
$errors = [];
$formatOk = $h === ['lang', 'set', 'field_name', 'field_type', 'label', 'choices', 'validation', 'identifier', 'expected'];
while ($r = fgetcsv($fh, 0, ',', '"', '')) {
    $r = array_combine($h, $r);
    $formatOk = $formatOk && isset($acc[$r['set']]) && in_array($r['lang'], ['es', 'en'], true)
        && array_diff(explode('|', $r['expected']), CdmModel::validDomains()) === [];
    $langs[$r['set']][$r['lang']] = true;
    $meta = f($r['field_name'], 'f', $r['field_type'], $r['label'], $r['choices'], $r['validation'], '', $r['identifier']);
    $got = $prof->profile([$r['field_name'] => $meta], ['record_id_field' => in_array($r['field_name'], ['record_id', 'study_id']) ? $r['field_name'] : null])[$r['field_name']]['suggested_domain'];
    $hit = in_array($got, explode('|', $r['expected']), true);
    $acc[$r['set']][0] += $hit ? 1 : 0;
    $acc[$r['set']][1]++;
    if (!$hit) {
        $errors[] = sprintf('     %-7s %-4s %-28s → %s (expected %s)', $r['set'], $r['lang'], mb_substr($r['label'], 0, 28), $got, $r['expected']);
    }
}
fclose($fh);
if (!Harness::$quiet) {
    foreach ($acc as $set => [$a, $n]) {
        printf("     %s: %d/%d = %.0f%%\n", $set, $a, $n, 100 * $a / max(1, $n));
    }
    echo $errors ? implode("\n", $errors) . "\n" : '';
}
test('NFR-004-01', 'benchmark of 120 fields with valid header, languages, sets and expected domains',
    $formatOk && $acc['dev'][1] + $acc['holdout'][1] === 120);
test('NFR-004-01', 'both sets have fields in Spanish and in English',
    count($langs['dev'] ?? []) === 2 && count($langs['holdout'] ?? []) === 2);
test('NFR-004-02', 'accuracy on the development fields ≥ 95 %', $acc['dev'][0] / max(1, $acc['dev'][1]) >= 0.95);
test('NFR-004-03', 'accuracy on the holdout fields ≥ 70 %', $acc['holdout'][0] / max(1, $acc['holdout'][1]) >= 0.70);
test('NFR-004-04', 'after the amendment the holdout does not get worse (≥ 26/27)', $acc['holdout'][0] >= 26 && $acc['holdout'][1] === 27);
