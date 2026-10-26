<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }   // command line only: never from the browser
/**
 * Specification of the 10 evaluation projects.
 *
 * Each field carries:
 *   g  data generator
 *   m  the mapping an expert curator would make (the "ground truth"):
 *        d domain, c concept_id, cn name, s strategy, v per-option values
 *        (code => [id, name] or '-' = does not emit), u unit, date, end,
 *        x = exclude, sug = look the concept up with the suggestions button
 *   t  correct domain if the field is not mapped (to measure the classifier)
 */

function F(string $n, string $form, string $type, string $label, array $o = []): array
{
    return ['field_name' => $n, 'form_name' => $form, 'field_type' => $type, 'field_label' => $label,
            'select_choices_or_calculations' => $o['ch'] ?? '', 'text_validation_type_or_show_slider_number' => $o['v'] ?? '',
            'identifier' => $o['id'] ?? '', 'g' => $o['g'] ?? null, 'm' => $o['m'] ?? null, 't' => $o['t'] ?? null];
}
// Mapping shortcuts
function MX() { return ['x' => true]; }
function MM($c, $n, $u = null, $date = null) { return ['d' => 'Measurement', 'c' => $c, 'cn' => $n, 'u' => $u, 'date' => $date]; }
function MC1($v, $date = null) { return ['d' => 'Condition', 's' => 'per_choice', 'v' => $v, 'date' => $date]; }

$YN_ES = ['1' => 'Sí', '0' => 'No'];

$P = [];

// ============================================================ P1 ES
$P['P1'] = [
 'title' => 'Registro de insuficiencia cardiaca (RICA-Madrid)', 'lang' => 'es', 'mode' => 'manual', 'n' => 40,
 'events' => [['Basal', 0], ['Mes 6', 180], ['Mes 12', 365]],
 'forms' => ['demografia' => ['basal'], 'exploracion' => ['basal', 'mes_6', 'mes_12'], 'analitica' => ['basal', 'mes_6', 'mes_12'],
             'tratamiento' => ['basal', 'mes_6'], 'desenlace' => ['mes_12']],
 'repeat' => ['tratamiento' => 3],
 'settings' => ['birth-date-field' => 'fecha_nacimiento', 'gender-field' => 'sexo', 'default-date-field' => 'fecha_visita',
                'generate-visits' => true, 'visit-concept-id' => '9202', 'death-date-field' => 'fecha_fallecimiento',
                'care-site-field' => 'hospital', 'cdm-holder' => 'Servicio de Cardiología', 'vocabulary-version' => 'Eunomia 2024',
                'cdm-source-name' => 'Registro RICA-Madrid', 'cdm-source-abbreviation' => 'RICA'],
 'fields' => [
  F('record_id', 'demografia', 'text', 'Código del paciente', ['t' => 'Person']),
  F('iniciales', 'demografia', 'text', 'Iniciales', ['id' => 'y', 'g' => ['initials'], 'm' => MX()]),
  F('fecha_nacimiento', 'demografia', 'text', 'Fecha de nacimiento', ['v' => 'date_ymd', 'id' => 'y', 'g' => ['dob', 55, 90], 't' => 'Person']),
  F('sexo', 'demografia', 'radio', 'Sexo', ['ch' => '1, Hombre | 2, Mujer', 'g' => ['cat', ['1' => 60, '2' => 40]],
     'm' => ['d' => 'Person', 's' => 'per_choice', 'v' => ['1' => [8507, 'MALE'], '2' => [8532, 'FEMALE']]]]),
  F('hospital', 'demografia', 'dropdown', 'Hospital', ['ch' => '1, H. Clínico San Carlos | 2, H. La Paz', 'g' => ['cat', ['1' => 1, '2' => 1]], 'm' => MX()]),
  F('fecha_inclusion', 'demografia', 'text', 'Fecha de inclusión', ['v' => 'date_ymd', 'g' => ['date', 0], 't' => 'DateAnchor']),
  F('antecedentes', 'demografia', 'checkbox', 'Antecedentes personales', ['ch' => '1, Hipertensión arterial | 2, Diabetes tipo 2 | 3, Fibrilación auricular | 4, Ninguno',
     'g' => ['chk', ['1' => .7, '2' => .4, '3' => .35]],
     'm' => MC1(['1' => [316866, 'Hypertensive disorder'], '2' => [201826, 'Type 2 diabetes mellitus'], '3' => [313217, 'Atrial fibrillation'], '4' => '-'], 'fecha_inclusion')]),
  F('tabaquismo', 'demografia', 'radio', 'Tabaquismo', ['ch' => '1, Fumador | 2, Exfumador | 3, Nunca fumador', 'g' => ['cat', ['1' => 2, '2' => 4, '3' => 4]],
     'm' => ['d' => 'Observation', 's' => 'per_choice', 'v' => ['1' => [4298794, 'Smoker'], '2' => [4310250, 'Ex-smoker'], '3' => [4144272, 'Never smoked tobacco']], 'date' => 'fecha_inclusion']]),
  F('fecha_visita', 'exploracion', 'text', 'Fecha de la visita', ['v' => 'date_ymd', 'g' => ['date', 0], 't' => 'Visit|DateAnchor']),
  F('peso', 'exploracion', 'text', 'Peso (kg)', ['v' => 'number', 'g' => ['num', 55, 110, 1], 'm' => MM(3025315, 'Body weight', 'kg', 'fecha_visita')]),
  F('talla', 'exploracion', 'text', 'Talla (cm)', ['v' => 'integer', 'g' => ['int', 150, 190], 'm' => MM(3036277, 'Body height', 'cm', 'fecha_visita')]),
  F('tas', 'exploracion', 'text', 'Tensión arterial sistólica (mmHg)', ['v' => 'integer', 'g' => ['int', 95, 170], 'm' => MM(3004249, 'Systolic blood pressure', 'mmHg', 'fecha_visita')]),
  F('tad', 'exploracion', 'text', 'Tensión arterial diastólica (mmHg)', ['v' => 'integer', 'g' => ['int', 55, 100], 'm' => MM(3012888, 'Diastolic blood pressure', 'mmHg', 'fecha_visita')]),
  F('fc', 'exploracion', 'text', 'Frecuencia cardiaca (lpm)', ['v' => 'integer', 'g' => ['int', 50, 120], 'm' => MM(3027018, 'Heart rate', 'lpm', 'fecha_visita')]),
  F('nyha', 'exploracion', 'radio', 'Clase funcional NYHA', ['ch' => '1, I | 2, II | 3, III | 4, IV', 'g' => ['cat', ['1' => 2, '2' => 5, '3' => 3, '4' => 1]], 't' => 'Observation|Measurement']),
  F('disnea', 'exploracion', 'yesno', '¿Presenta disnea?', ['g' => ['yn', .5], 'm' => MC1(['1' => [312437, 'Dyspnea']], 'fecha_visita')]),
  F('fatiga', 'exploracion', 'yesno', 'Fatiga', ['g' => ['yn', .4], 'm' => MC1(['1' => [4223659, 'Fatigue']], 'fecha_visita')]),
  F('fecha_analitica', 'analitica', 'text', 'Fecha de extracción', ['v' => 'date_ymd', 'g' => ['date', 1], 't' => 'DateAnchor']),
  F('hemoglobina', 'analitica', 'text', 'Hemoglobina (g/dL)', ['v' => 'number', 'g' => ['num', 9, 16, 1], 'm' => MM(3000963, 'Hemoglobin', 'g/dL', 'fecha_analitica')]),
  F('creatinina', 'analitica', 'text', 'Creatinina (mg/dL)', ['v' => 'number', 'g' => ['num', 0.6, 2.8, 2], 'm' => MM(3016723, 'Creatinine serum/plasma', 'mg/dL', 'fecha_analitica')]),
  F('sodio', 'analitica', 'text', 'Sodio (mmol/L)', ['v' => 'integer', 'g' => ['int', 128, 146], 'm' => MM(3019550, 'Sodium serum/plasma', 'mmol/L', 'fecha_analitica')]),
  F('potasio', 'analitica', 'text', 'Potasio (mmol/L)', ['v' => 'number', 'g' => ['num', 3.2, 5.8, 1], 'm' => MM(3023103, 'Potassium serum/plasma', 'mmol/L', 'fecha_analitica')]),
  F('ntprobnp', 'analitica', 'text', 'NT-proBNP (pg/mL)', ['g' => ['dirty', ['int', 300, 9000], ['> 35000', '1.250', 'hemolizada'], .12], 't' => 'Measurement']),
  F('fecha_inicio_tto', 'tratamiento', 'text', 'Fecha de inicio', ['v' => 'date_ymd', 'g' => ['date', 2], 't' => 'DateAnchor|Drug']),
  F('farmaco', 'tratamiento', 'dropdown', 'Fármaco', ['ch' => '1, Enalapril | 2, Atorvastatina | 3, Ácido acetilsalicílico | 4, Warfarina',
     'g' => ['cat', ['1' => 3, '2' => 3, '3' => 2, '4' => 1]],
     'm' => ['d' => 'Drug', 's' => 'per_choice', 'v' => ['1' => [1341927, 'enalapril'], '2' => [1545958, 'atorvastatin'], '3' => [1112807, 'Aspirin'], '4' => [1310149, 'Warfarin']],
             'date' => 'fecha_inicio_tto', 'end' => 'fecha_fin_tto']]),
  F('fecha_fin_tto', 'tratamiento', 'text', 'Fecha de fin', ['v' => 'date_ymd', 'g' => ['date', 90, 'optional' => .5], 't' => 'DateAnchor|Drug']),
  F('reingreso', 'desenlace', 'yesno', 'Reingreso por insuficiencia cardiaca', ['g' => ['yn', .3], 't' => 'Visit|Condition|Observation']),
  F('fallecido', 'desenlace', 'yesno', 'Fallecido', ['g' => ['death', .15], 'm' => ['d' => 'Death', 's' => 'per_choice', 'v' => ['1' => [null, null], '0' => '-']]]),
  F('fecha_fallecimiento', 'desenlace', 'text', 'Fecha de fallecimiento', ['v' => 'date_ymd', 'g' => ['deathdate'], 't' => 'Death']),
 ],
];

// ============================================================ P2 ES (annotations)
$P['P2'] = [
 'title' => 'Cohorte de diabetes tipo 2 en Atención Primaria (DIABAP)', 'lang' => 'es', 'mode' => 'annotation', 'n' => 45,
 'events' => null, 'repeat' => ['seguimiento' => 4],
 'settings' => ['birth-date-field' => 'fnac', 'gender-field' => 'sexo', 'default-date-field' => 'fecha_seg',
                'cdm-holder' => 'Gerencia de Atención Primaria', 'vocabulary-version' => 'Eunomia 2024', 'cdm-source-name' => 'DIABAP'],
 'fields' => [
  F('record_id', 'basal', 'text', 'ID', ['t' => 'Person']),
  F('fnac', 'basal', 'text', 'Fecha de nacimiento', ['v' => 'date_ymd', 'id' => 'y', 'g' => ['dob', 40, 80], 't' => 'Person']),
  F('sexo', 'basal', 'radio', 'Sexo', ['ch' => '1, Hombre | 2, Mujer', 'g' => ['cat', ['1' => 1, '2' => 1]],
     'm' => ['d' => 'Person', 's' => 'per_choice', 'v' => ['1' => [8507, 'MALE'], '2' => [8532, 'FEMALE']]]]),
  F('centro', 'basal', 'dropdown', 'Centro de salud', ['ch' => '1, CS Las Rozas | 2, CS Majadahonda | 3, CS Pozuelo', 'g' => ['cat', ['1' => 1, '2' => 1, '3' => 1]], 'm' => MX()]),
  F('fecha_dx', 'basal', 'text', 'Fecha de diagnóstico de diabetes', ['v' => 'date_ymd', 'g' => ['date', -900], 'm' => ['d' => 'Condition', 'c' => 201826, 'cn' => 'Type 2 diabetes mellitus', 'date' => 'fecha_dx']]),
  F('hta', 'basal', 'yesno', 'Hipertensión arterial', ['g' => ['yn', .55], 'm' => MC1(['1' => [316866, 'Hypertensive disorder']], 'fecha_dx')]),
  F('dislipemia', 'basal', 'yesno', 'Dislipemia', ['g' => ['yn', .6], 'm' => MC1(['1' => [432867, 'Hyperlipidemia']], 'fecha_dx')]),
  F('fecha_seg', 'seguimiento', 'text', 'Fecha de la consulta', ['v' => 'date_ymd', 'g' => ['date', 0, 'step' => 120], 't' => 'Visit|DateAnchor']),
  F('peso', 'seguimiento', 'text', 'Peso (kg)', ['v' => 'number', 'g' => ['num', 60, 120, 1], 'm' => MM(3025315, 'Body weight', 'kg', 'fecha_seg')]),
  F('imc', 'seguimiento', 'text', 'IMC (kg/m2)', ['v' => 'number', 'g' => ['num', 22, 41, 1], 'm' => MM(3038553, 'Body mass index (BMI) [Ratio]', 'kg/m2', 'fecha_seg')]),
  F('tas', 'seguimiento', 'text', 'TAS (mmHg)', ['v' => 'integer', 'g' => ['int', 110, 170], 'm' => MM(3004249, 'Systolic blood pressure', 'mmHg', 'fecha_seg')]),
  F('hba1c', 'seguimiento', 'text', 'HbA1c (%)', ['v' => 'number', 'g' => ['num', 5.8, 11.5, 1], 'm' => MM(3004410, 'Hemoglobin A1c/Hemoglobin.total in Blood', '%', 'fecha_seg')]),
  F('glucemia', 'seguimiento', 'text', 'Glucemia basal (mg/dL)', ['v' => 'integer', 'g' => ['int', 90, 260], 'm' => MM(3004501, 'Glucose lab', 'mg/dL', 'fecha_seg')]),
  F('col_total', 'seguimiento', 'text', 'Colesterol total (mg/dL)', ['v' => 'integer', 'g' => ['int', 140, 290], 'm' => MM(3027114, 'Cholesterol [Mass/volume] in Serum or Plasma', 'mg/dL', 'fecha_seg')]),
  F('hdl', 'seguimiento', 'text', 'Colesterol HDL (mg/dL)', ['v' => 'integer', 'g' => ['int', 30, 80], 'm' => MM(3007070, 'Cholesterol in HDL [Mass/volume] in Serum or Plasma', 'mg/dL', 'fecha_seg')]),
  F('trigliceridos', 'seguimiento', 'text', 'Triglicéridos (mg/dL)', ['v' => 'integer', 'g' => ['int', 70, 400], 'm' => MM(3022192, 'Triglyceride [Mass/volume] in Serum or Plasma', 'mg/dL', 'fecha_seg')]),
  F('tratamiento', 'seguimiento', 'checkbox', 'Tratamiento actual', ['ch' => '1, Metformina | 2, Insulina | 3, Atorvastatina | 4, Enalapril',
     'g' => ['chk', ['1' => .8, '2' => .2, '3' => .5, '4' => .4]],
     'm' => ['d' => 'Drug', 's' => 'per_choice', 'v' => ['1' => [1503297, 'metformin'], '2' => [1502905, 'insulin glargine'], '3' => [1545958, 'atorvastatin'], '4' => [1341927, 'enalapril']], 'date' => 'fecha_seg']]),
  F('hipoglucemias', 'seguimiento', 'text', 'Número de hipoglucemias desde la última consulta', ['v' => 'integer', 'g' => ['int', 0, 4], 't' => 'Observation|Measurement']),
  F('comentarios', 'seguimiento', 'notes', 'Comentarios', ['g' => ['text', ['Buen control', 'Refiere poliuria', 'Pendiente de fondo de ojo', '']], 'm' => MX()]),
 ],
];

// ============================================================ P3 ES (privacy, NOTE)
$P['P3'] = [
 'title' => 'COVID-19 en pacientes hospitalizados (COVID-HOSP)', 'lang' => 'es', 'mode' => 'manual', 'n' => 45,
 'events' => null, 'repeat' => [],
 'settings' => ['birth-date-field' => 'fecha_nac', 'gender-field' => 'sexo', 'default-date-field' => 'fecha_ingreso',
                'death-date-field' => 'fecha_exitus', 'cdm-holder' => 'Servicio de Medicina Interna', 'vocabulary-version' => 'Eunomia 2024',
                'cdm-source-name' => 'COVID-HOSP', 'date-shift-days' => '30', 'birth-precision' => 'year', 'hash-person-source-value' => true],
 'fields' => [
  F('record_id', 'ingreso', 'text', 'Número de caso', ['t' => 'Person']),
  F('nhc', 'ingreso', 'text', 'Número de historia clínica', ['id' => 'y', 'g' => ['nhc'], 'm' => MX()]),
  F('fecha_nac', 'ingreso', 'text', 'Fecha de nacimiento', ['v' => 'date_ymd', 'id' => 'y', 'g' => ['dob', 30, 95], 't' => 'Person']),
  F('sexo', 'ingreso', 'radio', 'Sexo', ['ch' => '1, Varón | 2, Mujer', 'g' => ['cat', ['1' => 55, '2' => 45]],
     'm' => ['d' => 'Person', 's' => 'per_choice', 'v' => ['1' => [8507, 'MALE'], '2' => [8532, 'FEMALE']]]]),
  F('fecha_ingreso', 'ingreso', 'text', 'Fecha de ingreso hospitalario', ['v' => 'date_ymd', 'g' => ['date', 0], 't' => 'Visit']),
  F('fecha_alta', 'ingreso', 'text', 'Fecha de alta', ['v' => 'date_ymd', 'g' => ['date', 9, 'optional' => .85], 't' => 'Visit|DateAnchor']),
  F('pcr', 'ingreso', 'radio', 'PCR de SARS-CoV-2', ['ch' => '1, Positiva | 2, Negativa', 'g' => ['cat', ['1' => 9, '2' => 1]],
     'm' => ['d' => 'Measurement', 's' => 'value_concept', 'c' => 'sug:sars-cov-2', 'cn' => null, 'v' => ['1' => [9191, 'Positive'], '2' => [9189, 'Negative']], 'date' => 'fecha_ingreso']]),
  F('sintomas', 'ingreso', 'checkbox', 'Síntomas al ingreso', ['ch' => '1, Fiebre | 2, Tos | 3, Disnea | 4, Cefalea | 5, Náuseas',
     'g' => ['chk', ['1' => .8, '2' => .7, '3' => .5, '4' => .3, '5' => .2]],
     'm' => MC1(['1' => [437663, 'Fever'], '2' => [254761, 'Cough'], '3' => [312437, 'Dyspnea'], '4' => [378253, 'Headache'], '5' => [31967, 'Nausea']], 'fecha_ingreso')]),
  F('neumonia', 'ingreso', 'yesno', 'Neumonía en la radiografía', ['g' => ['yn', .7], 'm' => MC1(['1' => [255848, 'Pneumonia']], 'fecha_ingreso')]),
  F('sato2', 'ingreso', 'text', 'Saturación de oxígeno (%)', ['v' => 'integer', 'g' => ['int', 82, 99], 'm' => MM(3016502, 'Oxygen saturation in Arterial blood', '%', 'fecha_ingreso')]),
  F('temp', 'ingreso', 'text', 'Temperatura (°C)', ['v' => 'number', 'g' => ['num', 36, 39.8, 1], 'm' => MM(3020891, 'Body temperature', '°C', 'fecha_ingreso')]),
  F('pcr_mg', 'ingreso', 'text', 'Proteína C reactiva (mg/L)', ['g' => ['dirty', ['num', 2, 250, 1], ['< 5', '>300', '12,5'], .15], 'm' => MM(3020460, 'C reactive protein [Mass/volume] in Serum or Plasma', 'mg/L', 'fecha_ingreso')]),
  F('ferritina', 'ingreso', 'text', 'Ferritina (ng/mL)', ['v' => 'integer', 'g' => ['int', 150, 2500], 'm' => MM(3001122, 'Ferritin [Mass/volume] in Serum or Plasma', 'ng/mL', 'fecha_ingreso')]),
  F('dexametasona', 'ingreso', 'yesno', 'Tratamiento con dexametasona', ['g' => ['yn', .6], 'm' => ['d' => 'Drug', 's' => 'per_choice', 'v' => ['1' => [1518254, 'dexamethasone']], 'date' => 'fecha_ingreso']]),
  F('uci', 'ingreso', 'yesno', 'Ingreso en UCI', ['g' => ['yn', .2], 't' => 'Visit|Observation']),
  F('evolucion', 'ingreso', 'notes', 'Evolución clínica', ['g' => ['text', ['Evolución favorable, alta a domicilio', 'Empeoramiento respiratorio al 3.er día', 'Precisó oxigenoterapia de alto flujo']],
     'm' => ['d' => 'Note', 'c' => 44814637, 'cn' => 'Discharge summary', 'date' => 'fecha_ingreso']]),
  F('exitus', 'ingreso', 'yesno', 'Exitus', ['g' => ['death', .12], 'm' => ['d' => 'Death', 's' => 'per_choice', 'v' => ['1' => [null, null], '0' => '-']]]),
  F('fecha_exitus', 'ingreso', 'text', 'Fecha de exitus', ['v' => 'date_ymd', 'g' => ['deathdate'], 't' => 'Death']),
 ],
];

// ============================================================ P4 ES (DAG)
$P['P4'] = [
 'title' => 'Programa de cribado de cáncer de mama (CRIBAMA)', 'lang' => 'es', 'mode' => 'manual', 'n' => 40,
 'events' => null, 'repeat' => [], 'dags' => ['hospital_norte', 'hospital_sur'],
 'settings' => ['birth-date-field' => 'fecha_nacimiento', 'gender-field' => 'sexo', 'default-date-field' => 'fecha_mamografia',
                'cdm-holder' => 'Unidad de Cribado', 'vocabulary-version' => 'Eunomia 2024', 'cdm-source-name' => 'CRIBAMA',
                'hash-person-source-value' => true],
 'fields' => [
  F('record_id', 'cribado', 'text', 'ID de participante', ['t' => 'Person']),
  F('dni', 'cribado', 'text', 'DNI', ['id' => 'y', 'g' => ['dni'], 'm' => MX()]),
  F('fecha_nacimiento', 'cribado', 'text', 'Fecha de nacimiento', ['v' => 'date_ymd', 'id' => 'y', 'g' => ['dob', 50, 69], 't' => 'Person']),
  F('sexo', 'cribado', 'radio', 'Sexo', ['ch' => '2, Mujer', 'g' => ['cat', ['2' => 1]], 'm' => ['d' => 'Person', 's' => 'per_choice', 'v' => ['2' => [8532, 'FEMALE']]]]),
  F('codigo_postal', 'cribado', 'text', 'Código postal', ['v' => 'zipcode', 'id' => 'y', 'g' => ['cp'], 'm' => MX()]),
  F('ant_familiares', 'cribado', 'yesno', 'Antecedentes familiares de cáncer de mama', ['g' => ['yn', .15], 't' => 'Observation|Condition']),
  F('fecha_mamografia', 'cribado', 'text', 'Fecha de la mamografía', ['v' => 'date_ymd', 'g' => ['date', 0], 't' => 'Procedure|DateAnchor']),
  F('birads', 'cribado', 'radio', 'Resultado BI-RADS', ['ch' => '0, 0 Incompleto | 1, 1 Negativo | 2, 2 Benigno | 3, 3 Probablemente benigno | 4, 4 Sospechoso | 5, 5 Muy sugestivo de malignidad',
     'g' => ['cat', ['0' => 1, '1' => 50, '2' => 25, '3' => 8, '4' => 4, '5' => 2]], 't' => 'Measurement']),
  F('biopsia', 'cribado', 'yesno', 'Biopsia realizada', ['g' => ['yn', .1], 't' => 'Procedure']),
  F('cancer', 'cribado', 'yesno', 'Diagnóstico de cáncer de mama', ['g' => ['yn', .05], 'm' => MC1(['1' => [4112853, 'Malignant tumor of breast']], 'fecha_mamografia')]),
  F('peso', 'cribado', 'text', 'Peso (kg)', ['v' => 'number', 'g' => ['num', 48, 95, 1], 'm' => MM(3025315, 'Body weight', 'kg', 'fecha_mamografia')]),
  F('talla', 'cribado', 'text', 'Talla (cm)', ['v' => 'integer', 'g' => ['int', 148, 178], 'm' => MM(3036277, 'Body height', 'cm', 'fecha_mamografia')]),
  F('tratamiento_hormonal', 'cribado', 'yesno', 'Terapia hormonal sustitutiva', ['g' => ['yn', .12], 't' => 'Drug']),
 ],
];

// ============================================================ P10 EN (source of the multicentre scenario)
$stroke = function (string $lang): array {
    $es = $lang === 'es';
    $L = fn($e, $s) => $es ? $s : $e;
    return [
  F('record_id', 'baseline', 'text', $L('Patient ID', 'ID del paciente'), ['t' => 'Person']),
  F('dob', 'baseline', 'text', $L('Date of birth', 'Fecha de nacimiento'), ['v' => 'date_ymd', 'id' => 'y', 'g' => ['dob', 45, 92], 't' => 'Person']),
  F('sex', 'baseline', 'radio', $L('Sex', 'Sexo'), ['ch' => $L('1, Male | 2, Female', '1, Hombre | 2, Mujer'), 'g' => ['cat', ['1' => 1, '2' => 1]],
     'm' => ['d' => 'Person', 's' => 'per_choice', 'v' => ['1' => [8507, 'MALE'], '2' => [8532, 'FEMALE']]]]),
  F('onset_date', 'baseline', 'text', $L('Date of stroke onset', 'Fecha de inicio del ictus'), ['v' => 'date_ymd', 'g' => ['date', 0],
     'm' => ['d' => 'Condition', 'c' => 381316, 'cn' => 'Cerebrovascular accident', 'date' => 'onset_date']]),
  F('admission_date', 'baseline', 'text', $L('Date of hospital admission', 'Fecha de ingreso hospitalario'), ['v' => 'date_ymd', 'g' => ['date', 0], 't' => 'Visit']),
  F('nihss', 'baseline', 'text', $L('NIHSS score on admission', 'Puntuación NIHSS al ingreso'), ['v' => 'integer', 'g' => ['int', 0, 25], 't' => 'Measurement']),
  F('af', 'baseline', 'yesno', $L('Atrial fibrillation', 'Fibrilación auricular'), ['g' => ['yn', .3], 'm' => MC1(['1' => [313217, 'Atrial fibrillation']], 'admission_date')]),
  F('hypertension', 'baseline', 'yesno', $L('Hypertension', 'Hipertensión arterial'), ['g' => ['yn', .6], 'm' => MC1(['1' => [316866, 'Hypertensive disorder']], 'admission_date')]),
  F('sbp', 'baseline', 'text', $L('Systolic blood pressure (mmHg)', 'Tensión arterial sistólica (mmHg)'), ['v' => 'integer', 'g' => ['int', 110, 210], 'm' => MM(3004249, 'Systolic blood pressure', 'mmHg', 'admission_date')]),
  F('glucose', 'baseline', 'text', $L('Glucose (mg/dL)', 'Glucosa (mg/dL)'), ['v' => 'integer', 'g' => ['int', 80, 300], 'm' => MM(3004501, 'Glucose lab', 'mg/dL', 'admission_date')]),
  F('inr', 'baseline', 'text', $L('INR', 'INR'), ['v' => 'number', 'g' => ['num', 0.9, 3.5, 1], 'm' => MM(3022217, 'INR in Platelet poor plasma by Coagulation assay', null, 'admission_date')]),
  F('ct_result', 'baseline', 'radio', $L('Brain CT result', 'Resultado de la TC craneal'), ['ch' => $L('1, Ischaemic | 2, Haemorrhagic | 3, Normal', '1, Isquémico | 2, Hemorrágico | 3, Normal'),
     'g' => ['cat', ['1' => 7, '2' => 2, '3' => 1]], 't' => 'Procedure|Measurement|Observation']),
  F('discharge_med', 'baseline', 'dropdown', $L('Antithrombotic at discharge', 'Antitrombótico al alta'),
     ['ch' => $L('1, Aspirin | 2, Clopidogrel | 3, Warfarin | 4, None', '1, Ácido acetilsalicílico | 2, Clopidogrel | 3, Warfarina | 4, Ninguno'),
      'g' => ['cat', ['1' => 4, '2' => 2, '3' => 2, '4' => 1]],
      'm' => ['d' => 'Drug', 's' => 'per_choice', 'v' => ['1' => [1112807, 'Aspirin'], '2' => [1322184, 'clopidogrel'], '3' => [1310149, 'Warfarin'], '4' => '-'], 'date' => 'admission_date']]),
  F('fu_date', 'followup', 'text', $L('Date of 90-day follow-up', 'Fecha del seguimiento a 90 días'), ['v' => 'date_ymd', 'g' => ['date', 90], 't' => 'Visit|DateAnchor']),
  F('mrs', 'followup', 'radio', $L('Modified Rankin Scale at 90 days', 'Escala de Rankin modificada a los 90 días'),
     ['ch' => '0, 0 | 1, 1 | 2, 2 | 3, 3 | 4, 4 | 5, 5 | 6, 6', 'g' => ['cat', ['0' => 2, '1' => 3, '2' => 3, '3' => 2, '4' => 1, '5' => 1, '6' => 1]], 't' => 'Measurement|Observation']),
  F('died', 'followup', 'yesno', $L('Died before follow-up', 'Fallecido antes del seguimiento'), ['g' => ['death', .1], 'm' => ['d' => 'Death', 's' => 'per_choice', 'v' => ['1' => [null, null], '0' => '-']]]),
  F('death_date', 'followup', 'text', $L('Date of death', 'Fecha de fallecimiento'), ['v' => 'date_ymd', 'g' => ['deathdate'], 't' => 'Death']),
    ];
};
$P['P10'] = [
 'title' => 'Multicentre stroke outcomes study — London site (MSOS)', 'lang' => 'en', 'mode' => 'manual', 'n' => 40,
 'events' => [['Baseline', 0], ['Follow up', 90]], 'forms' => ['baseline' => ['baseline'], 'followup' => ['follow_up']], 'repeat' => [],
 'settings' => ['birth-date-field' => 'dob', 'gender-field' => 'sex', 'default-date-field' => 'admission_date', 'death-date-field' => 'death_date',
                'generate-visits' => true, 'visit-concept-id' => '9201', 'cdm-holder' => 'Stroke Research Unit', 'vocabulary-version' => 'Eunomia 2024',
                'cdm-source-name' => 'MSOS London'],
 'fields' => $stroke('en'),
];
// ============================================================ P5 ES (same instrument, translated)
$P['P5'] = [
 'title' => 'Estudio multicéntrico de resultados en ictus — centro de Madrid (MSOS)', 'lang' => 'es', 'mode' => 'import', 'import_from' => 'P10', 'n' => 35,
 'events' => [['Basal', 0], ['Seguimiento', 90]], 'forms' => ['baseline' => ['basal'], 'followup' => ['seguimiento']], 'repeat' => [],
 'settings' => ['birth-date-field' => 'dob', 'gender-field' => 'sex', 'default-date-field' => 'admission_date', 'death-date-field' => 'death_date',
                'generate-visits' => true, 'visit-concept-id' => '9201', 'cdm-holder' => 'Unidad de Ictus', 'vocabulary-version' => 'Eunomia 2024',
                'cdm-source-name' => 'MSOS Madrid'],
 'fields' => $stroke('es'),
];

// ============================================================ P6 EN
$P['P6'] = [
 'title' => 'Paediatric asthma registry (PAR)', 'lang' => 'en', 'mode' => 'manual', 'n' => 40,
 'events' => [['Enrolment', 0], ['Month 6', 180], ['Month 12', 365]],
 'forms' => ['demographics' => ['enrolment'], 'clinic_visit' => ['enrolment', 'month_6', 'month_12'], 'medication' => ['enrolment', 'month_6', 'month_12']],
 'repeat' => ['medication' => 2],
 'settings' => ['birth-date-field' => 'dob', 'gender-field' => 'sex', 'race-field' => 'race', 'ethnicity-field' => 'ethnicity',
                'default-date-field' => 'visit_date', 'generate-visits' => true, 'visit-concept-id' => '9202',
                'cdm-holder' => 'Children\'s Respiratory Network', 'vocabulary-version' => 'Eunomia 2024', 'cdm-source-name' => 'PAR'],
 'fields' => [
  F('record_id', 'demographics', 'text', 'Participant ID', ['t' => 'Person']),
  F('first_name', 'demographics', 'text', 'First name', ['id' => 'y', 'g' => ['name'], 'm' => MX()]),
  F('dob', 'demographics', 'text', 'Date of birth', ['v' => 'date_ymd', 'id' => 'y', 'g' => ['dob', 5, 15], 't' => 'Person']),
  F('sex', 'demographics', 'radio', 'Sex', ['ch' => '1, Male | 2, Female', 'g' => ['cat', ['1' => 1, '2' => 1]],
     'm' => ['d' => 'Person', 's' => 'per_choice', 'v' => ['1' => [8507, 'MALE'], '2' => [8532, 'FEMALE']]]]),
  F('race', 'demographics', 'dropdown', 'Race', ['ch' => '1, White | 2, Black or African American | 3, Asian', 'g' => ['cat', ['1' => 6, '2' => 2, '3' => 2]],
     'm' => ['d' => 'Person', 's' => 'per_choice', 'v' => ['1' => [8527, 'White'], '2' => [8516, 'Black or African American'], '3' => [8515, 'Asian']]]]),
  F('ethnicity', 'demographics', 'radio', 'Ethnicity', ['ch' => '1, Hispanic or Latino | 2, Not Hispanic or Latino', 'g' => ['cat', ['1' => 2, '2' => 8]],
     'm' => ['d' => 'Person', 's' => 'per_choice', 'v' => ['1' => [38003563, 'Hispanic or Latino'], '2' => [38003564, 'Not Hispanic or Latino']]]]),
  F('dx_asthma', 'demographics', 'text', 'Date of asthma diagnosis', ['v' => 'date_ymd', 'g' => ['date', -700], 'm' => ['d' => 'Condition', 'c' => 317009, 'cn' => 'Asthma', 'date' => 'dx_asthma']]),
  F('visit_date', 'clinic_visit', 'text', 'Clinic visit date', ['v' => 'date_ymd', 'g' => ['date', 0], 't' => 'Visit|DateAnchor']),
  F('weight', 'clinic_visit', 'text', 'Weight (kg)', ['v' => 'number', 'g' => ['num', 18, 60, 1], 'm' => MM(3025315, 'Body weight', 'kg', 'visit_date')]),
  F('height', 'clinic_visit', 'text', 'Height (cm)', ['v' => 'integer', 'g' => ['int', 105, 175], 'm' => MM(3036277, 'Body height', 'cm', 'visit_date')]),
  F('resp_rate', 'clinic_visit', 'text', 'Respiratory rate (breaths/min)', ['v' => 'integer', 'g' => ['int', 14, 32], 'm' => MM(3024171, 'Respiratory rate', '/min', 'visit_date')]),
  F('spo2', 'clinic_visit', 'text', 'Oxygen saturation (%)', ['v' => 'integer', 'g' => ['int', 91, 100], 'm' => MM(3016502, 'Oxygen saturation in Arterial blood', '%', 'visit_date')]),
  F('act_score', 'clinic_visit', 'text', 'Childhood Asthma Control Test score', ['v' => 'integer', 'g' => ['int', 8, 27], 't' => 'Measurement|Observation']),
  F('exacerbation', 'clinic_visit', 'yesno', 'Asthma exacerbation since last visit', ['g' => ['yn', .3], 't' => 'Condition|Observation']),
  F('ed_visit', 'clinic_visit', 'yesno', 'Emergency department visit since last visit', ['g' => ['yn', .2], 't' => 'Visit|Observation']),
  F('med_start', 'medication', 'text', 'Medication start date', ['v' => 'date_ymd', 'g' => ['date', 0], 't' => 'DateAnchor|Drug']),
  F('medication', 'medication', 'dropdown', 'Medication', ['ch' => '1, Salbutamol (albuterol) inhaler | 2, Prednisone | 3, Amoxicillin', 'g' => ['cat', ['1' => 6, '2' => 2, '3' => 2]],
     'm' => ['d' => 'Drug', 's' => 'per_choice', 'v' => ['1' => [1154343, 'Albuterol'], '2' => [1551099, 'Prednisone'], '3' => [1713332, 'Amoxicillin']], 'date' => 'med_start', 'end' => 'med_end']]),
  F('med_end', 'medication', 'text', 'Medication end date', ['v' => 'date_ymd', 'g' => ['date', 7, 'optional' => .7], 't' => 'DateAnchor|Drug']),
 ],
];

// ============================================================ P7 EN
$P['P7'] = [
 'title' => 'ICU sepsis cohort (SEPSIS-ICU)', 'lang' => 'en', 'mode' => 'manual', 'n' => 40,
 'events' => null, 'repeat' => ['daily_assessment' => 4],
 'settings' => ['birth-date-field' => 'dob', 'gender-field' => 'sex', 'default-date-field' => 'icu_admission',
                'death-date-field' => 'death_date', 'cdm-holder' => 'Critical Care Research Group', 'vocabulary-version' => 'Eunomia 2024',
                'cdm-source-name' => 'SEPSIS-ICU', 'suppress-free-text' => false],
 'fields' => [
  F('record_id', 'admission', 'text', 'Study number', ['t' => 'Person']),
  F('mrn', 'admission', 'text', 'Medical record number', ['id' => 'y', 'g' => ['nhc'], 'm' => MX()]),
  F('dob', 'admission', 'text', 'Date of birth', ['v' => 'date_ymd', 'id' => 'y', 'g' => ['dob', 25, 90], 't' => 'Person']),
  F('sex', 'admission', 'radio', 'Sex', ['ch' => '1, Male | 2, Female', 'g' => ['cat', ['1' => 6, '2' => 4]],
     'm' => ['d' => 'Person', 's' => 'per_choice', 'v' => ['1' => [8507, 'MALE'], '2' => [8532, 'FEMALE']]]]),
  F('icu_admission', 'admission', 'text', 'Date of ICU admission', ['v' => 'date_ymd', 'g' => ['date', 0], 't' => 'Visit']),
  F('sepsis_source', 'admission', 'radio', 'Source of infection', ['ch' => '1, Pneumonia | 2, Urinary tract | 3, Abdominal | 4, Unknown', 'g' => ['cat', ['1' => 4, '2' => 3, '3' => 2, '4' => 1]],
     'm' => MC1(['1' => [255848, 'Pneumonia'], '2' => '-', '3' => '-', '4' => '-'], 'icu_admission')]),
  F('sepsis', 'admission', 'yesno', 'Sepsis diagnosis confirmed', ['g' => ['yn', .95], 'm' => MC1(['1' => [132797, 'Sepsis']], 'icu_admission')]),
  F('ventilation', 'admission', 'yesno', 'Invasive mechanical ventilation', ['g' => ['yn', .4], 't' => 'Procedure']),
  F('antibiotic', 'admission', 'checkbox', 'Empirical antibiotics', ['ch' => '1, Ceftriaxone | 2, Amoxicillin | 3, Other', 'g' => ['chk', ['1' => .6, '2' => .3, '3' => .2]],
     'm' => ['d' => 'Drug', 's' => 'per_choice', 'v' => ['1' => [1777806, 'ceftriaxone'], '2' => [1713332, 'Amoxicillin'], '3' => '-'], 'date' => 'icu_admission']]),
  F('assess_date', 'daily_assessment', 'text', 'Assessment date', ['v' => 'date_ymd', 'g' => ['date', 0, 'step' => 1], 't' => 'DateAnchor']),
  F('temperature', 'daily_assessment', 'text', 'Temperature (°C)', ['v' => 'number', 'g' => ['num', 35.5, 40.2, 1], 'm' => MM(3020891, 'Body temperature', '°C', 'assess_date')]),
  F('heart_rate', 'daily_assessment', 'text', 'Heart rate (bpm)', ['v' => 'integer', 'g' => ['int', 60, 140], 'm' => MM(3027018, 'Heart rate', 'bpm', 'assess_date')]),
  F('wbc', 'daily_assessment', 'text', 'White blood cell count (x10^9/L)', ['v' => 'number', 'g' => ['num', 2, 28, 1], 'm' => MM(3000905, 'Leukocytes [#/volume] in Blood by Automated count', '10*9/L', 'assess_date')]),
  F('platelets', 'daily_assessment', 'text', 'Platelets (x10^9/L)', ['v' => 'integer', 'g' => ['int', 40, 420], 'm' => MM(3024929, 'Platelets [#/volume] in Blood by Automated count', '10*9/L', 'assess_date')]),
  F('crp', 'daily_assessment', 'text', 'CRP (mg/L)', ['g' => ['dirty', ['num', 5, 320, 1], ['>320', '<5'], .1], 'm' => MM(3020460, 'C reactive protein [Mass/volume] in Serum or Plasma', 'mg/L', 'assess_date')]),
  F('bilirubin', 'daily_assessment', 'text', 'Total bilirubin (mg/dL)', ['v' => 'number', 'g' => ['num', 0.3, 6, 1], 'm' => MM(3024128, 'Bilirubin.total [Mass/volume] in Serum or Plasma', 'mg/dL', 'assess_date')]),
  F('progress_note', 'daily_assessment', 'notes', 'Daily progress note', ['g' => ['text', ['Haemodynamically stable', 'Started noradrenaline', 'Weaning from ventilator', 'Fever spike overnight']],
     'm' => ['d' => 'Note', 'c' => 44814637, 'cn' => 'Discharge summary', 'date' => 'assess_date']]),
  F('outcome', 'icu_outcome', 'radio', 'ICU outcome', ['ch' => '1, Discharged alive | 2, Died in ICU', 'g' => ['death_choice', .25], 't' => 'Death|Observation']),
  F('death_date', 'icu_outcome', 'text', 'Date of death', ['v' => 'date_ymd', 'g' => ['deathdate'], 't' => 'Death']),
 ],
];

// ============================================================ P8 EN
$P['P8'] = [
 'title' => 'Rheumatoid arthritis biologics registry (RABR)', 'lang' => 'en', 'mode' => 'manual', 'n' => 40,
 'events' => null, 'repeat' => ['adverse_event' => 2],
 'settings' => ['birth-date-field' => 'dob', 'gender-field' => 'sex', 'default-date-field' => 'baseline_date',
                'provider-field' => 'rheumatologist', 'care-site-field' => 'centre',
                'cdm-holder' => 'National RA Registry', 'vocabulary-version' => 'Eunomia 2024', 'cdm-source-name' => 'RABR'],
 'fields' => [
  F('record_id', 'baseline', 'text', 'Registry ID', ['t' => 'Person']),
  F('dob', 'baseline', 'text', 'Date of birth', ['v' => 'date_ymd', 'id' => 'y', 'g' => ['dob', 25, 80], 't' => 'Person']),
  F('sex', 'baseline', 'radio', 'Sex', ['ch' => '1, Male | 2, Female', 'g' => ['cat', ['1' => 3, '2' => 7]],
     'm' => ['d' => 'Person', 's' => 'per_choice', 'v' => ['1' => [8507, 'MALE'], '2' => [8532, 'FEMALE']]]]),
  F('centre', 'baseline', 'dropdown', 'Centre', ['ch' => '1, Leeds | 2, Manchester | 3, Bristol', 'g' => ['cat', ['1' => 1, '2' => 1, '3' => 1]], 'm' => MX()]),
  F('rheumatologist', 'baseline', 'dropdown', 'Responsible rheumatologist', ['ch' => '11, Rheumatologist A | 12, Rheumatologist B | 13, Rheumatologist C | 14, Rheumatologist D',
     'g' => ['cat', ['11' => 1, '12' => 1, '13' => 1, '14' => 1]], 'm' => MX()]),
  F('baseline_date', 'baseline', 'text', 'Baseline assessment date', ['v' => 'date_ymd', 'g' => ['date', 0], 't' => 'Visit|DateAnchor']),
  F('ra_dx', 'baseline', 'text', 'Date of RA diagnosis', ['v' => 'date_ymd', 'g' => ['date', -1500], 'm' => ['d' => 'Condition', 'c' => 80809, 'cn' => 'Rheumatoid arthritis', 'date' => 'ra_dx']]),
  F('das28', 'baseline', 'text', 'DAS28-CRP score', ['v' => 'number', 'g' => ['num', 1.8, 7.2, 2], 't' => 'Measurement']),
  F('crp', 'baseline', 'text', 'C-reactive protein (mg/L)', ['v' => 'number', 'g' => ['num', 1, 80, 1], 'm' => MM(3020460, 'C reactive protein [Mass/volume] in Serum or Plasma', 'mg/L', 'baseline_date')]),
  F('alt', 'baseline', 'text', 'ALT (U/L)', ['v' => 'integer', 'g' => ['int', 8, 90], 'm' => MM(3006923, 'Alanine aminotransferase serum/plasma', 'U/L', 'baseline_date')]),
  F('ast', 'baseline', 'text', 'AST (U/L)', ['v' => 'integer', 'g' => ['int', 10, 80], 'm' => MM(3013721, 'Aspartate aminotransferase serum/plasma', 'U/L', 'baseline_date')]),
  F('hb', 'baseline', 'text', 'Haemoglobin (g/dL)', ['v' => 'number', 'g' => ['num', 9.5, 15.5, 1], 'm' => MM(3000963, 'Hemoglobin', 'g/dL', 'baseline_date')]),
  F('prednisone', 'baseline', 'yesno', 'Current oral prednisone', ['g' => ['yn', .35], 'm' => ['d' => 'Drug', 's' => 'per_choice', 'v' => ['1' => [1551099, 'Prednisone']], 'date' => 'baseline_date']]),
  F('osteoarthritis', 'baseline', 'yesno', 'Concomitant osteoarthritis', ['g' => ['yn', .3], 'm' => MC1(['1' => [80180, 'Osteoarthritis']], 'baseline_date')]),
  F('ae_date', 'adverse_event', 'text', 'Adverse event onset date', ['v' => 'date_ymd', 'g' => ['date', 60, 'step' => 45], 't' => 'DateAnchor|Condition']),
  F('ae_type', 'adverse_event', 'dropdown', 'Adverse event', ['ch' => '1, Pneumonia | 2, Anaemia | 3, Headache | 4, Other', 'g' => ['cat', ['1' => 2, '2' => 2, '3' => 3, '4' => 2]],
     'm' => MC1(['1' => [255848, 'Pneumonia'], '2' => [439777, 'Anemia'], '3' => [378253, 'Headache'], '4' => '-'], 'ae_date')]),
  F('ae_serious', 'adverse_event', 'yesno', 'Serious adverse event?', ['g' => ['yn', .2], 't' => 'Condition|Observation']),
 ],
];

// ============================================================ P9 EN (suggestions)
$P['P9'] = [
 'title' => 'Pregnancy and gestational diabetes cohort (PREG-GDM)', 'lang' => 'en', 'mode' => 'suggest', 'n' => 40,
 'events' => [['First trimester', 0], ['Second trimester', 90], ['Delivery', 190]],
 'forms' => ['booking' => ['first_trimester'], 'antenatal' => ['first_trimester', 'second_trimester'], 'delivery' => ['delivery']], 'repeat' => [],
 'settings' => ['birth-date-field' => 'dob', 'gender-field' => 'sex', 'default-date-field' => 'visit_date',
                'generate-visits' => true, 'visit-concept-id' => '9202', 'cdm-holder' => 'Maternity Research Unit',
                'vocabulary-version' => 'Eunomia 2024', 'cdm-source-name' => 'PREG-GDM', 'date-shift-days' => '15'],
 'fields' => [
  F('record_id', 'booking', 'text', 'Participant ID', ['t' => 'Person']),
  F('dob', 'booking', 'text', 'Date of birth', ['v' => 'date_ymd', 'id' => 'y', 'g' => ['dob', 19, 44], 't' => 'Person']),
  F('sex', 'booking', 'radio', 'Sex', ['ch' => '2, Female', 'g' => ['cat', ['2' => 1]], 'm' => ['d' => 'Person', 's' => 'per_choice', 'v' => ['2' => [8532, 'FEMALE']]]]),
  F('lmp', 'booking', 'text', 'Date of last menstrual period', ['v' => 'date_ymd', 'g' => ['date', -60], 't' => 'Observation|DateAnchor']),
  F('parity', 'booking', 'text', 'Number of previous births', ['v' => 'integer', 'g' => ['int', 0, 4], 't' => 'Observation|Measurement']),
  F('smoking', 'booking', 'radio', 'Smoking status', ['ch' => '1, Current smoker | 2, Former smoker | 3, Never smoked', 'g' => ['cat', ['1' => 1, '2' => 2, '3' => 7]],
     'm' => ['d' => 'Observation', 's' => 'per_choice', 'v' => ['1' => [4298794, 'Smoker'], '2' => [4310250, 'Ex-smoker'], '3' => [4144272, 'Never smoked tobacco']], 'date' => 'visit_date']]),
  F('hypothyroidism', 'booking', 'yesno', 'Hypothyroidism', ['g' => ['yn', .08], 'm' => MC1(['1' => [140673, 'Hypothyroidism']], 'visit_date')]),
  F('levothyroxine', 'booking', 'yesno', 'Taking levothyroxine', ['g' => ['yn', .08], 'm' => ['d' => 'Drug', 's' => 'per_choice', 'sug' => 'levothyroxine', 'v' => ['1' => [1501700, 'levothyroxine']], 'date' => 'visit_date']]),
  F('visit_date', 'antenatal', 'text', 'Antenatal visit date', ['v' => 'date_ymd', 'g' => ['date', 0], 't' => 'Visit|DateAnchor']),
  F('weight', 'antenatal', 'text', 'Weight (kg)', ['v' => 'number', 'g' => ['num', 50, 105, 1], 'm' => MM('sug:body weight', 'Body weight', 'kg', 'visit_date')]),
  F('sbp', 'antenatal', 'text', 'Systolic blood pressure (mmHg)', ['v' => 'integer', 'g' => ['int', 95, 150], 'm' => MM('sug:systolic', 'Systolic blood pressure', 'mmHg', 'visit_date')]),
  F('dbp', 'antenatal', 'text', 'Diastolic blood pressure (mmHg)', ['v' => 'integer', 'g' => ['int', 55, 100], 'm' => MM('sug:diastolic', 'Diastolic blood pressure', 'mmHg', 'visit_date')]),
  F('ogtt', 'antenatal', 'text', 'Fasting glucose (mg/dL)', ['v' => 'integer', 'g' => ['int', 70, 140], 'm' => MM('sug:glucose', 'Glucose lab', 'mg/dL', 'visit_date')]),
  F('hb', 'antenatal', 'text', 'Haemoglobin (g/dL)', ['v' => 'number', 'g' => ['num', 9, 14, 1], 'm' => MM('sug:hemoglobin', 'Hemoglobin', 'g/dL', 'visit_date')]),
  F('tsh', 'antenatal', 'text', 'TSH (mU/L)', ['v' => 'number', 'g' => ['num', 0.4, 5, 2], 'm' => MM('sug:thyrotropin', 'Thyrotropin [Units/volume] in Serum or Plasma', 'm[IU]/L', 'visit_date')]),
  F('gdm', 'antenatal', 'yesno', 'Gestational diabetes diagnosed', ['g' => ['yn', .15], 't' => 'Condition']),
  F('delivery_date', 'delivery', 'text', 'Date of delivery', ['v' => 'date_ymd', 'g' => ['date', 0], 't' => 'Procedure|Observation|DateAnchor']),
  F('birth_weight', 'delivery', 'text', 'Birth weight (g)', ['v' => 'integer', 'g' => ['int', 2300, 4300], 't' => 'Measurement']),
  F('anaemia', 'delivery', 'yesno', 'Postpartum anaemia', ['g' => ['yn', .2], 'm' => MC1(['1' => [439777, 'Anemia']], 'delivery_date')]),
 ],
];

return $P;
