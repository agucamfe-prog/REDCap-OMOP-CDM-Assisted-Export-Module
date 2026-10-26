<?php

namespace OmopCdm\OmopCdmExportModule;

require_once __DIR__ . '/TextNormalizer.php';
require_once __DIR__ . '/CdmModel.php';

/**
 * Profiling of the REDCap data dictionary.
 *
 * For each field it proposes an OMOP domain by combining two signals:
 *   1. The field type and its validation (structural, reliable).
 *   2. Keywords in the name and the label (semantic, indicative).
 *
 * WORD MATCHING, NOT SUBSTRING MATCHING. Searching for substrings would cause
 * errors such as: «lab» matching inside «laboral», «clinica» inside
 * «historia clínica», «nivel» inside «nivel de estudios». A term only matches
 * at a word boundary, and those that must work as a stem are marked with an
 * asterisk: 'diagnos*' matches «diagnóstico» and «diagnosticado», but 'lab'
 * does not match «laboral».
 */
class DictionaryProfiler
{
    const D_SKIP        = 'NotMapped';
    const D_PERSON      = 'Person';
    const D_VISIT       = 'Visit';
    const D_CONDITION   = 'Condition';
    const D_DRUG        = 'Drug';
    const D_PROCEDURE   = 'Procedure';
    const D_MEASUREMENT = 'Measurement';
    const D_OBSERVATION = 'Observation';
    const D_DEATH       = 'Death';
    const D_DEVICE      = 'Device';
    const D_SPECIMEN    = 'Specimen';
    const D_DATE_ANCHOR = 'DateAnchor';

    /** Internal groups that are not OMOP domains but routes towards them. */
    const G_ADMIN     = '_admin';
    const G_SDOH      = '_sdoh';
    const G_LIFESTYLE = '_lifestyle';
    const G_SCALE     = '_scale';
    const G_SEVERITY  = '_severity';
    const G_STRUCTURAL = '_structural';

    /**
     * Evaluation order. The first one that matches wins, so the specific comes
     * before the generic: Condition goes last because its stems
     * ('enfermedad*', 'dolor') are the most likely to over-capture.
     */
    private $order = [
        self::G_ADMIN, self::G_STRUCTURAL, self::D_PERSON, self::D_DEATH, self::D_VISIT,
        self::G_SDOH, self::G_LIFESTYLE, self::G_SEVERITY, self::G_SCALE,
        self::D_DRUG, self::D_PROCEDURE, self::D_SPECIMEN, self::D_DEVICE,
        self::D_MEASUREMENT, self::D_OBSERVATION, self::D_CONDITION,
    ];

    /**
     * Labels that, on their own, are an identifying data item.
     *
     * «Nombre» (name) on its own is the patient's name; «Nombre del
     * medicamento» (drug name) is the most important data item of a drug.
     * That is why 'nombre' cannot be a keyword: it matched any label that
     * contained it.
     */
    private $adminLabels = ['nombre', 'name', 'apellido', 'apellidos', 'nombre completo',
                            'full name', 'surname', 'nombre y apellidos'];

    /** Suffix '*' = stem (matches the rest of the word). No suffix = whole word. */
    private $keywords = [
        // Administrative and identifying fields: never clinical facts.
        self::G_ADMIN => [
            'historia clinica', 'numero de historia', 'nhc', 'identificador',
            'codigo de paciente', 'codigo de participante', 'iniciales',
            'nombre del paciente', 'nombre del participante', 'nombre y apellidos',
            'nombre completo', 'apellidos', 'telefono', 'movil', 'email',
            'correo electronico', 'dni', 'nie', 'pasaporte',
            'numero de seguridad social', 'consentimiento', 'firma',
            'direccion', 'domicilio completo', 'calle', 'address',
            'revisado por', 'nombre del investigador',
            'record id', 'study id', 'subject id', 'patient id', 'participant id',
            'medical record number', 'mrn', 'record number', 'chart number',
            'hospital number', 'patient name', 'first name', 'last name', 'surname',
            'initials', 'phone number', 'e mail', 'nhs number', 'ssn',
            'signature', 'reviewed by', 'investigator name', 'screening number',
        ],

        /**
         * Entities of the health system, not patient facts.
         *
         * A health centre is a CARE_SITE in the CDM and a clinician is a
         * PROVIDER: facts reference them, they are not facts. The link between
         * two people is FACT_RELATIONSHIP. This module generates none of those
         * tables from these fields, and their values are local entities with
         * no standard concept: there is no concept_id for "Centro Norte".
         *
         * Without this group they fell into the catch-all (categorical with no
         * signal, to Observation) and the editor invited the curator to paste
         * concepts for something that must not be exported. Proposing useless
         * work is worse than proposing nothing.
         */
        self::G_STRUCTURAL => [
            'centro de salud', 'centro sanitario', 'centro hospitalario',
            'centro', 'hospital', 'ambulatorio', 'servicio',
            'la clinica', 'clinica de', 'policlinica',
            'unidad', 'planta', 'sala', 'consulta de', 'departamento',
            'medico responsable', 'medico que atiende', 'profesional',
            'facultativo', 'enfermera', 'enfermero', 'investigador',
            'relacion con', 'parentesco', 'vinculo', 'caso indice',
            'health center', 'hospital', 'ward', 'clinic',
            'department', 'service', 'attending', 'provider', 'physician',
            'relationship with', 'kinship', 'center', 'consultant',
            'clinician', 'doctor', 'rheumatologist', 'cardiologist', 'neurologist',
            'oncologist', 'surgeon', 'general practitioner', 'especialista', 'cirujano',
        ],

        self::D_PERSON => [
            'sexo', 'genero', 'fecha de nacimiento', 'fecha nacimiento', 'nacimiento',
            'fnac', 'edad', 'raza', 'etnia', 'pais de nacimiento', 'nacionalidad',
            'provincia', 'municipio', 'codigo postal', 'cp', 'residencia',
            'sex', 'gender', 'birth', 'dob', 'race', 'ethnic*', 'age', 'zip', 'postal',
            'date of birth', 'birth date', 'year of birth', 'sex at birth',
            'gender identity', 'ethnicity', 'ethnic group', 'country of birth',
            'nationality', 'town', 'city', 'residence', 'postcode', 'post code',
            'zip code', 'county', 'district', 'region',
        ],

        self::D_DEATH => [
            'fallec*', 'defuncion', 'exitus', 'muerte', 'mortalidad',
            'causa de muerte', 'deceso',
            'death', 'deceased', 'mortality', 'date of death', 'cause of death',
            'died', 'fatal', 'vital status',
        ],

        self::D_VISIT => [
            'visita', 'consulta', 'ingreso', 'ingreso hospitalario', 'ingresado',
            'alta hospitalaria', 'hospitaliza*', 'derivado a', 'derivacion',
            'urgencias', 'episodio', 'estancia', 'seguimiento', 'reingreso',
            'visit', 'admission', 'discharge', 'encounter', 'inpatient', 'outpatient',
            'admitted', 'discharged', 'hospital stay', 'length of stay',
            'emergency department', 'ed visit', 'er visit', 'follow up', 'followup',
            'attendance', 'readmission', 'ward round',
        ],

        // Social determinants of health: central in public health and
        // systematically misclassified without a group of their own.
        self::G_SDOH => [
            'nivel de estudios', 'estudios finalizados', 'nivel educativo', 'educacion',
            'situacion laboral', 'ocupacion', 'profesion', 'empleo', 'desempleo',
            'renta', 'ingresos', 'nivel socioeconomico', 'clase social',
            'vivienda', 'hogar', 'convivientes', 'estado civil',
            'apoyo social', 'soledad', 'pobreza', 'privacion',
            'conviven', 'convivencia', 'convive', 'mismo domicilio', 'domicilio',
            'agua corriente', 'agua potable', 'agua de red', 'saneamiento',
            'electricidad', 'luz electrica', 'hacinamiento', 'habitaciones',
            'personas viven', 'animales de compania', 'mascota*',
            'running water', 'electricity', 'overcrowding',
            'ingresos mensuales', 'nivel de ingresos',
            'education', 'employment', 'income', 'occupation', 'marital',
            'educational level', 'level of education', 'schooling',
            'employment status', 'household', 'people living', 'marital status',
            'housing', 'deprivation', 'social support', 'loneliness', 'poverty',
            'socioeconomic',
        ],

        self::G_LIFESTYLE => [
            'tabaco', 'tabaquismo', 'fumador', 'fuma', 'paquetes ano', 'cigarrillo*',
            'alcohol', 'drogas', 'actividad fisica', 'ejercicio', 'sedentar*',
            'dieta', 'alimentacion', 'sueno',
            'smoking', 'tobacco', 'physical activity', 'exercise', 'diet', 'sleep',
            'smoker', 'pack year*', 'cigarette*', 'drinking', 'drug use',
            'sedentary', 'dietary', 'nutrition', 'sleeping',
        ],

        // Validated scales and questionnaires. Many exist as panels in LOINC.
        self::G_SCALE => [
            'eq5d', 'eq 5d', 'phq', 'gad', 'audit', 'sf 36', 'barthel', 'lawton',
            'pfeiffer', 'minimental', 'escala', 'cuestionario', 'puntuacion',
            'satisfaccion', 'calidad de vida', 'score',
            'mmse', 'moca', 'questionnaire', 'scale', 'satisfaction',
            'quality of life', 'patient reported', 'control test', 'nihss', 'das28', 'rankin',
            'glasgow', 'apgar', 'sofa', 'apache', 'nyha', 'bi rads', 'birads', 'ecog', 'karnofsky',
        ],

        // Numeric intensity ratings. In OMOP they are measurements, not
        // conditions: «cuánto duele del 1 al 10» (how much does it hurt, 1 to
        // 10) is pain severity, not the fact of having pain.
        self::G_SEVERITY => [
            'gravedad', 'severidad', 'intensidad', 'del 1 al 10', 'del 0 al 10',
            'grado de', 'eva', 'valoracion del dolor', 'nivel de dolor',
            'severity', 'grade', 'rating', 'intensity', 'from 1 to 10', 'from 0 to 10',
            'how severe', 'how much pain', 'vas', 'numeric rating',
        ],

        self::D_DRUG => [
            'farmaco*', 'medicament*', 'medicacion', 'tratamiento', 'dosis',
            'posologia', 'principio activo', 'vacuna*', 'vacunacion', 'inmuniza*',
            'antibiotic*', 'estatina*', 'insulina', 'antihipertensiv*',
            'antidiabetic*', 'anticoagulant*', 'analgesic*', 'atc',
            'antirretroviral*', 'antiviral*', 'arv', 'tarv', 'isoniazida',
            'rifampicina', 'pirazinamida', 'etambutol', 'tuberculostatico*',
            'posologia', 'prescrito', 'prescripcion', 'nombre del medicamento',
            'nombre del farmaco', 'quimioterapia', 'terapia hormonal sustitutiva',
            'tratamiento hormonal sustitutivo', 'hormone replacement therapy',
            'chemotherapy', 'drug name', 'medication name',
            'drug*', 'medication*', 'medicine*', 'dose', 'dosage', 'vaccin*',
            'vaccination', 'immuniz*', 'immunis*', 'therapy', 'prescription',
            'prescribed', 'antibiotic*', 'statin*', 'insulin',
            'antihypertensive*', 'antidiabetic*', 'anticoagulant*', 'analgesic*',
            'metformin', 'opioid*', 'inhaler',
            'antithrombotic*', 'antitrombotico*', 'antiplatelet*', 'antiagregante*',
            'prednisone', 'prednisona', 'levothyroxine', 'levotiroxina', 'dexamethasone', 'dexametasona',
            'salbutamol', 'albuterol', 'warfarin', 'warfarina', 'aspirin', 'acido acetilsalicilico',
            'clopidogrel', 'enalapril', 'atorvastatin', 'atorvastatina', 'amoxicillin', 'amoxicilina',
            'ceftriaxone', 'ceftriaxona', 'ibuprofen', 'ibuprofeno', 'paracetamol', 'acetaminophen',
            'methotrexate', 'metotrexato', 'biologic*', 'biologico*', 'metformina',
        ],

        self::D_PROCEDURE => [
            'procedimiento*', 'cirugia*', 'quirurgic*', 'intervencion*', 'operacion',
            'radiografia', 'rx torax', 'rx de torax', 'ecografia', 'ecocardio*',
            'tac', 'tomografia', 'resonancia', 'gammagrafia', 'mamografia',
            'radiography', 'chest x ray', 'ultrasound', 'ct scan',
            'biopsia', 'endoscop*', 'dialisis', 'transfusion', 'cateterismo',
            'artroplastia', 'trasplante', 'tratamiento quirurgico', 'intervencion quirurgica',
            'quirurgico', 'quirurgica', 'fisioterapia', 'rehabilitacion', 'radioterapia',
            'physical therapy', 'physiotherapy', 'occupational therapy', 'rehabilitation',
            'radiotherapy', 'radiation therapy', 'surgical treatment',
            'procedure*', 'surger*', 'surgic*', 'operation*', 'operative', 'biops*',
            'endoscop*', 'dialysis', 'transfusion', 'catheteris*', 'catheteriz*',
            'replacement', 'transplant*', 'resection', 'implantation',
            'ventilation', 'ventilacion', 'intubation', 'intubacion', 'mammography', 'mamografia',
        ],

        self::D_SPECIMEN => [
            'muestra', 'especimen', 'biobanco', 'alicuota', 'extraccion de muestra',
            'specimen', 'sample', 'aliquot', 'swab', 'biobank', 'venepuncture',
        ],

        self::D_DEVICE => [
            'dispositivo', 'implante', 'protesis', 'cateter', 'sonda', 'marcapasos',
            'device', 'implant*', 'catheter', 'prosthesis', 'prosthetic', 'pacemaker', 'stent',
        ],

        self::D_MEASUREMENT => [
            'analitica', 'laboratorio', 'lab', 'hemograma', 'bioquimica',
            'resultado', 'concentracion',
            'tension arterial', 'presion arterial', 'tas', 'tad', 'pas', 'pad',
            'frecuencia cardiaca', 'peso', 'talla', 'altura', 'imc', 'bmi',
            'perimetro', 'temperatura', 'saturacion',
            'glucemia', 'glucosa', 'hemoglobina', 'hba1c', 'colesterol',
            'trigliceridos', 'creatinina', 'filtrado glomerular', 'leucocito*',
            'linfocito*', 'plaqueta*', 'urea', 'sodio', 'potasio', 'albumina',
            'transaminasa*', 'carga viral', 'serolog*', 'anticuerpo*',
            'baciloscopia', 'cultivo', 'genexpert', 'tincion', 'esputo',
            'antibiograma', 'recuento', 'cd4', 'cd8', 'tuberculina', 'ppd',
            'igra', 'quantiferon', 'induracion', 'hematocrito', 'hemograma',
            'bilirrubina', 'fosfatasa', 'ggt', 'got', 'gpt', 'ast', 'alt',
            'acido urico', 'tsh', 'adherencia', 'porcentaje de adherencia',
            'sputum smear', 'culture', 'tuberculin', 'blood count',
            'result*', 'weight', 'height', 'pressure', 'heart rate', 'loinc', 'titer',
            'titre', 'value', 'laboratory', 'labs', 'blood test', 'blood count',
            'full blood count', 'complete blood count', 'cbc', 'fbc',
            'biochemistry', 'chemistry', 'concentration',
            'blood pressure', 'systolic', 'diastolic', 'sbp', 'dbp', 'bp',
            'pulse', 'hr', 'respiratory rate', 'oxygen saturation', 'spo2', 'sats',
            'wt', 'ht', 'body mass index', 'waist', 'circumference',
            'glucose', 'glycemia', 'glycated',
            'hemoglobin', 'hgb', 'hb', 'cholesterol', 'tchol', 'ldl', 'hdl',
            'triglyceride*', 'tg', 'creatinine', 'creat', 'egfr',
            'glomerular filtration', 'urea', 'sodium', 'potassium', 'albumin',
            'bilirubin', 'transaminase*', 'alt', 'ast', 'crp', 'esr',
            'white blood cell', 'wbc', 'red blood cell', 'rbc', 'platelet*',
            'lymphocyte*', 'neutrophil*', 'leukocyte*', 'leucocyte*',
            'viral load', 'antibod*', 'probnp', 'nt probnp', 'bnp', 'troponin*', 'troponina',
            'proteina c reactiva', 'c reactive protein', 'pcr', 'sars cov 2', 'ferritin*', 'inr', 'lactate',
            'lactato', 'procalcitonin*', 'procalcitonina', 'dimero d', 'd dimer',
        ],

        // Facts that are not a condition even though they mention a diagnosis
        // or a treatment: the age at which something happened is a number that
        // Condition would throw away, and a treatment result is an outcome.
        self::D_OBSERVATION => [
            'edad al diagnostico', 'edad de diagnostico', 'edad en el diagnostico',
            'edad de inicio', 'edad al inicio', 'age at diagnosis', 'age at onset',
            'age of onset', 'resultado del tratamiento', 'resultado final del tratamiento',
            'desenlace del tratamiento', 'treatment outcome', 'outcome of treatment',
        ],

        self::D_CONDITION => [
            'diagnos*', 'dx', 'enfermedad*', 'patolog*', 'comorbilidad*',
            'antecedente*', 'complicacion*', 'sintoma*',
            'fiebre', 'tos', 'disnea', 'dolor', 'duele', 'doler*', 'edema*', 'cefalea',
            'hipertension', 'hipotension', 'dislipemia', 'epoc', 'asma',
            'hepatopatia', 'nefropatia', 'cardiopatia', 'neuropatia*',
            'tuberculosis', 'tuberculos*', 'pulmonar', 'extrapulmonar',
            'sudoracion', 'hemoptisis', 'astenia', 'anorexia', 'adenopatia*',
            'rash', 'prurito', 'ictericia', 'artralgia*', 'mialgia*',
            'alergia*', 'alergico*', 'reaccion adversa', 'efecto adverso', 'efectos adversos',
            'alteracion visual', 'alteraciones visuales', 'toxicidad',
            'perdida de peso', 'perdida ponderal', 'perdido peso',
            'weight loss', 'night sweats', 'adverse event*', 'adverse effect*', 'nauseas',
            'vomitos', 'astenia', 'ictus', 'infarto', 'neoplasia', 'infeccion',
            'cie10', 'cie 10', 'icd*',
            'disease*', 'condition*', 'disorder*', 'comorbid*', 'symptom*',
            'history of', 'medical history', 'family history', 'hx', 'pmh',
            'pain', 'ache', 'fever', 'cough', 'dyspnea',
            'shortness of breath', 'breathlessness', 'swelling', 'edema',
            'headache', 'nausea', 'vomiting', 'fatigue', 'stroke', 'infarction',
            'myocardial', 'cancer', 'malignancy', 'neoplasm', 'infection',
            'ischemic', 'hypertension', 'diabetes', 'copd', 'asthma',
            'dyslipidaemia', 'dyslipidemia', 'kidney disease', 'liver disease',
            'fatiga', 'cansancio', 'neumonia', 'pneumonia', 'fibrilacion auricular', 'atrial fibrillation',
            'arritmia', 'arrhythmia', 'insuficiencia cardiaca', 'heart failure', 'hipotiroidismo',
            'hypothyroidism', 'hipertiroidismo', 'hyperthyroidism', 'anemia', 'leukemia', 'leucemia', 'diarrhea', 'diarrea', 'artrosis',
            'osteoarthritis', 'artritis', 'arthritis', 'sepsis', 'covid', 'covid 19', 'ictus', 'migrana',
            'migraine', 'demencia', 'dementia', 'depresion', 'depression', 'obesidad', 'obesity',
            'hiperlipidemia', 'hyperlipidaemia', 'hyperlipidemia', 'gestational diabetes',
            'diabetes gestacional', 'preeclampsia', 'pre eclampsia', 'exacerbacion', 'exacerbation',
            'hipoglucemia*', 'hypoglycemi*', 'reaccion adversa', 'adverse reaction',
        ],
    ];

    /**
     * Phrases that place a data item in time rather than describe it.
     *
     * "¿Refiere dolor en el momento de la visita?" (does the patient report
     * pain at the time of the visit?) is about pain, not about the visit.
     * Without removing these phrases before classifying, the bare word drags
     * the field into the wrong domain, and since Visit is evaluated before
     * Condition, the context beats the subject.
     */
    private $contextPhrases = [
        'en el momento de la visita', 'durante la visita', 'en esta visita',
        'el dia de la visita', 'en la visita', 'de la visita', 'en la consulta',
        'durante la consulta', 'en el momento de la consulta',
        'en el ingreso', 'durante el ingreso', 'al ingreso',
        'en el seguimiento', 'durante el seguimiento',
        'en la ultima visita', 'desde la ultima visita',
        'medido en la visita', 'recogido en la visita',
        'at the time of the visit', 'during the visit', 'at this visit',
        'on the day of the visit', 'at the visit', 'during admission',
        'on admission', 'at follow up', 'during follow up',
        'since the last visit', 'measured at the visit',
        'desde la ultima consulta', 'en la ultima consulta', 'at discharge', 'on discharge',
        'al alta', 'en el alta', 'before follow up', 'antes del seguimiento', 'at follow up visit',
        'en la radiografia', 'en la radiografia de torax', 'on chest x ray', 'on admission',
        'since last visit', 'at the 90 day follow up', 'a los 90 dias', 'at 90 days',
    ];

    /**
     * Recognised units. Extraction by shape is not enough: in real projects
     * the trailing parenthesis contains all sorts of things («(LDL)», «(poner
     * número exacto)»). A unit is accepted only if it is in this list or if it
     * contains '/', '%', '^' or '°', which no analyte abbreviation carries.
     */
    private $knownUnits = [
        'kg','g','mg','mcg','ug','ng','pg','lb','t',
        'm','cm','mm','km','in','ft',
        'l','ml','dl','cl','ul',
        'mmhg','cmh2o','bar','pa','kpa','atm',
        'lpm','rpm','bpm','spm',
        's','seg','min','h','hr','hora','horas','dia','dias','sem','semana','semanas',
        'mes','meses','ano','anos',
        'u','ui','iu','mu','mui',
        'meq','mmol','mol','umol','nmol','pmol',
        'kcal','cal','kj',
        'puntos','unidades','copias','celulas',
    ];

    private $skipTypes = ['descriptive', 'file', 'signature', 'calc'];

    /** @var callable|null Injected translator, usually [$module, 'tt']. */
    private $translator;

    /**
     * @param callable|null $translator function(string $key, ...$args): string
     *        Injected so the profiler does not depend on REDCap and can be
     *        tested in isolation. Without a translator it returns the key and
     *        the arguments.
     */
    public function __construct(?callable $translator = null)
    {
        $this->translator = $translator;
    }

    private function t(string $key, ...$args): string
    {
        if ($this->translator !== null) {
            return call_user_func($this->translator, $key, ...$args);
        }
        return $args ? $key . '(' . implode('|', $args) . ')' : $key;
    }

    public function profile(array $dictionary, array $structure = []): array
    {
        $recordIdField = $structure['record_id_field'] ?? null;
        $out = [];
        foreach ($dictionary as $fieldName => $meta) {
            $out[$fieldName] = $this->profileField($fieldName, $meta, $recordIdField);
        }
        return $out;
    }

    private function profileField(string $fieldName, array $meta, ?string $recordIdField): array
    {
        $type       = $meta['field_type'] ?? 'text';
        $validation = $meta['text_validation_type_or_show_slider_number'] ?? '';
        $label      = trim(strip_tags($meta['field_label'] ?? ''));
        $choices    = self::choicesFor($meta);
        $isPhi      = strtolower(trim((string) ($meta['identifier'] ?? ''))) === 'y';

        // The label describes the field; the variable name is an abbreviation
        // that carries noise: "peso_visita" contains "visita" and would send
        // the field to the Visit domain even though the label says "Peso".
        // That is why the label alone is classified first, and the name is
        // only used when the label gives no signal.
        //
        // British spelling is turned into American before normalising, so
        // «Leukaemia» or «Haemoglobin» match the same term as «Leukemia» or
        // «Hemoglobin», and the lexicon only needs one spelling per word.
        $haystackLabel = $this->stripContextPhrases(
            ' ' . TextNormalizer::normalize(TextNormalizer::americanize($label)) . ' ');
        $haystackFull  = $this->stripContextPhrases(
            ' ' . TextNormalizer::normalize(TextNormalizer::americanize($fieldName . ' ' . $label)) . ' ');

        // --- 1. Structural rules, which override the semantic ones ------------

        if ($fieldName === $recordIdField) {
            return $this->row($fieldName, $meta, self::D_PERSON, 'high',
                $this->t('r_record_id'), 'identifier', $choices, $isPhi, null);
        }

        if (in_array($type, $this->skipTypes, true)) {
            return $this->row($fieldName, $meta, self::D_SKIP, 'high',
                $this->t('r_skip_type', $type), 'none', $choices, $isPhi, null);
        }

        // REDCap already knows which fields are identifiers: someone flagged
        // them when designing the project. That flag is an explicit statement
        // and is worth more than any heuristic on the name. An identifier must
        // not end up written into a *_source_value of the CDM.
        //
        // The exceptions are those the CDM needs for PERSON: the date of birth
        // gives year_of_birth and sex gives gender_concept_id, and both are
        // often flagged for good reason. Only those: previously any Person
        // word qualified, and a postcode flagged as an identifier ended up
        // proposed as PERSON data.
        if ($isPhi && $fieldName !== $recordIdField
            && !$this->isDateValidation($validation)
            && !$this->isSexLabel($label)) {
            return $this->row($fieldName, $meta, self::D_SKIP, 'high',
                $this->t('r_phi_flag'), $this->valueKind($type, $validation),
                $choices, $isPhi, null);
        }

        if ($this->isDateValidation($validation)) {
            $hit = $this->matchKeywords($haystackLabel, [self::D_PERSON, self::D_DEATH, self::D_VISIT])
                ?? $this->matchKeywords($haystackFull, [self::D_PERSON, self::D_DEATH, self::D_VISIT]);
            if ($hit) {
                return $this->row($fieldName, $meta, $hit['domain'], 'high',
                    $this->t('r_date_semantic', $hit['domain'], $hit['term']),
                    'date', $choices, $isPhi, null);
            }
            // «Fecha de diagnóstico de artritis», «Date of stroke onset»: the
            // date IS the clinical fact, and it is exported as such with its
            // own value as the date. Only diagnoses and disease onset: a
            // «fecha de inicio del tratamiento» (treatment start date) usually
            // serves as an anchor for other fields and is better left as one.
            $clin = $this->matchKeywords($haystackLabel, [self::D_CONDITION]);
            if ($clin !== null && preg_match('/\b(diagnos|onset|inicio de|inicio del)/', $haystackLabel)) {
                return $this->row($fieldName, $meta, self::D_CONDITION, 'medium',
                    $this->t('r_date_fact', $clin['term']), 'date', $choices, $isPhi, null);
            }
            return $this->row($fieldName, $meta, self::D_DATE_ANCHOR, 'high',
                $this->t('r_date_anchor', $validation),
                'date', $choices, $isPhi, null);
        }

        // --- 2. Nature of the value ------------------------------------------

        $valueKind = $this->valueKind($type, $validation);
        $unit      = $valueKind === 'numeric' ? $this->extractUnit($label) : null;

        // --- 3. Semantic signal ----------------------------------------------

        // Structural signal: it beats keywords because it does not depend on language.
        if ($valueKind === 'categorical' && $this->looksLikeRatingScale($choices)) {
            return $this->row($fieldName, $meta, self::D_MEASUREMENT, 'high',
                $this->t('r_rating_scale', count($choices)),
                $valueKind, $choices, $isPhi, $unit);
        }

        // Administrative fields are checked separately and first.
        //
        // The other domains compete on specificity: the longest term wins,
        // because "pérdida de peso" (weight loss) describes better than
        // "peso" (weight). But that rule is for choosing BETWEEN clinical
        // targets. Applied to exclusions it gave an unacceptable result: in
        // "Dirección de residencia" (home address) 'residencia' (10 letters)
        // beat 'direccion' (9), and a postal address ended up proposed as
        // Person data.
        //
        // Recognising an identifier does not compete with classifying it: if
        // the field must not leave the project, that decision comes first.
        $excluded = [self::G_ADMIN, self::G_STRUCTURAL];
        $exclusion = $this->matchKeywords($haystackLabel, $excluded)
            ?? $this->matchKeywords($haystackFull, $excluded);

        if ($exclusion === null && in_array(trim($haystackLabel), $this->adminLabels, true)) {
            $exclusion = ['domain' => self::G_ADMIN, 'term' => trim($haystackLabel)];
        }

        // A structural field NAMES an entity, and entities are labelled as a
        // noun phrase that starts with the entity: "Centro de Salud",
        // "Médico responsable", "Servicio de ingreso", "Clinic". When the
        // entity appears in the middle of the label, the label is about
        // something else that happened to the patient: "Ingreso en hospital",
        // "Admitted to hospital", "Number of hospital visits" or "¿Derivado a
        // centro de salud?" are facts, not CARE_SITE.
        //
        // When in doubt, do not exclude: a structural field ending up proposed
        // as Observation costs the curator one click; a real fact marked as
        // not exportable hides a data item from them.
        if ($exclusion !== null && $exclusion['domain'] === self::G_STRUCTURAL
            && !$this->namesEntity($haystackLabel, $exclusion['term'], $label)) {
            $exclusion = null;
        }

        if ($exclusion !== null) {
            $key = $exclusion['domain'] === self::G_ADMIN ? 'r_admin' : 'r_structural';
            return $this->row($fieldName, $meta, self::D_SKIP, 'high',
                $this->t($key, $exclusion['term']), $valueKind, $choices, $isPhi, $unit);
        }

        $clinical = array_values(array_filter($this->order,
            fn($d) => !in_array($d, $excluded, true)));

        // The label alone first; the name only if there was no signal.
        $hit = $this->matchKeywords($haystackLabel, $clinical);
        $haystack = $haystackLabel;
        if ($hit === null) {
            $hit = $this->matchKeywords($haystackFull, $clinical);
            $haystack = $haystackFull;
        }

        // In a numeric field, a scale or an intensity rating says what is
        // measured even if the clinical term is longer: «Pain (VAS)» with a
        // number is pain intensity (MEASUREMENT with the number as the value),
        // not the fact of having pain, which would throw the number away.
        if ($hit !== null && $valueKind === 'numeric') {
            $scale = $this->matchKeywords($haystack, [self::G_SEVERITY, self::G_SCALE]);
            if ($scale !== null) {
                $hit = $scale;
            }
        }

        // A long-text clinical note (progress notes, report, daily note) is a
        // document: it goes whole to NOTE, not to a 60-character observation.
        if ($type === 'notes' && preg_match('/\b(evolucion|nota|notas|informe|progress note|clinical note|clinical notes|discharge summary|clinical course|curso clinico)\b/', $haystackLabel)) {
            return $this->row($fieldName, $meta, 'Note', 'medium', $this->t('r_note'), $valueKind, $choices, $isPhi, null);
        }

        if ($hit === null) {
            return $this->row($fieldName, $meta, $this->fallbackDomain($valueKind),
                'low', $this->fallbackReason($valueKind, $unit), $valueKind, $choices, $isPhi, $unit);
        }

        [$domain, $reason] = $this->resolveGroup($hit, $valueKind);

        // A multi-word term is far more specific than a single word.
        $confidence = strpos($hit['term'], ' ') !== false ? 'high' : 'medium';

        // Excluding an administrative field is a firm decision, not a hint.
        if ($domain === self::D_SKIP) {
            $confidence = 'high';
        }

        // Free text cannot be transformed automatically: the domain is at most
        // a hint about what the text talks about. It does not apply to fields
        // already excluded, whose exclusion is firm.
        if ($valueKind === 'text' && $domain !== self::D_SKIP) {
            $confidence = 'low';
            $reason .= $this->t('r_sfx_freetext');
        }

        if ($domain === self::D_CONDITION && $valueKind === 'categorical') {
            $reason .= $this->t('r_sfx_choices');
        }
        if ($unit !== null) {
            $reason .= $this->t('r_sfx_unit', $unit);
        }
        if ($isPhi && $domain !== self::D_SKIP) {
            $reason .= $this->t('r_sfx_phi');
        }

        return $this->row($fieldName, $meta, $domain, $confidence, $reason, $valueKind, $choices, $isPhi, $unit);
    }

    // --------------------------------------------------------------- Decisions

    /** Translates an internal group into the OMOP domain it maps to. */
    private function resolveGroup(array $hit, string $valueKind): array
    {
        $term = $hit['term'];

        switch ($hit['domain']) {
            case self::G_ADMIN:
                return [self::D_SKIP,
                    $this->t('r_admin', $term)];

            case self::G_STRUCTURAL:
                return [self::D_SKIP, $this->t('r_structural', $term)];

            case self::G_SDOH:
                return [self::D_OBSERVATION,
                    $this->t('r_sdoh', $term)];

            case self::G_LIFESTYLE:
                // A quantified habit is a measurement; a categorised one, an observation.
                return $valueKind === 'numeric'
                    ? [self::D_MEASUREMENT, $this->t('r_lifestyle_num', $term)]
                    : [self::D_OBSERVATION, $this->t('r_lifestyle_cat', $term)];

            case self::G_SEVERITY:
                return [self::D_MEASUREMENT,
                    $this->t('r_severity', $term)];

            case self::G_SCALE:
                // The total score of a scale (NIHSS, DAS28, ACT) is a measured
                // number: in LOINC it is a measurement. A categorical item of
                // the questionnaire is an observation.
                return $valueKind === 'numeric'
                    ? [self::D_MEASUREMENT, $this->t('r_scale_score', $term)]
                    : [self::D_OBSERVATION, $this->t('r_scale_item', $term)];

            default:
                return [$hit['domain'], $this->t('r_keyword', $term)];
        }
    }

    private function valueKind(string $type, string $validation): string
    {
        if (in_array($type, ['radio', 'dropdown', 'checkbox', 'yesno', 'truefalse'], true)) {
            return 'categorical';
        }
        if ($type === 'slider') {
            return 'numeric';
        }
        if ($type === 'notes') {
            return 'text';
        }
        if (in_array($validation, ['integer', 'number', 'number_1dp', 'number_2dp',
                                   'number_3dp', 'number_4dp'], true)) {
            return 'numeric';
        }
        return 'text';
    }

    private function fallbackDomain(string $valueKind): string
    {
        return $valueKind === 'numeric' ? self::D_MEASUREMENT : self::D_OBSERVATION;
    }

    private function fallbackReason(string $valueKind, ?string $unit): string
    {
        if ($valueKind === 'numeric') {
            return $unit !== null
                ? $this->t('r_fb_num_unit', $unit)
                : $this->t('r_fb_num');
        }
        if ($valueKind === 'categorical') {
            return $this->t('r_fb_cat');
        }
        return $this->t('r_fb_text');
    }

    /**
     * Extracts the unit from the label's trailing parenthesis: "Peso (kg)" → "kg".
     * It is a widespread REDCap convention and pre-fills unit_source_value.
     */
    private function extractUnit(string $label): ?string
    {
        if (!preg_match('/\(([^()]{1,20})\)\s*$/u', $label, $m)) {
            return null;
        }
        $candidate = trim($m[1]);
        if ($candidate === '') {
            return null;
        }

        // A compound symbol cannot be anything other than a unit.
        if (preg_match('#[/%^°]#u', $candidate)) {
            return $candidate;
        }

        // Otherwise it has to be in the list. We prefer losing a unit to
        // inventing one: the curator fixes the former in a second, the latter
        // propagates all the way to unit_source_value.
        return in_array(TextNormalizer::normalize($candidate), $this->knownUnits, true)
            ? $candidate
            : null;
    }

    /**
     * A categorical field with many numbered choices is a rating scale, not a
     * list of clinical categories.
     *
     * One single rule for the whole module: with two different rules (one
     * requiring digit-only labels and another accepting «0 - Sin dolor») the
     * same scale would come out as Measurement in Spanish and Observation in
     * English.
     */
    private function looksLikeRatingScale(array $choices): bool
    {
        return self::looksLikeRatingScaleStatic($choices);
    }

    /** Is the label that of a sex or gender field? */
    private function isSexLabel(string $label): bool
    {
        $t = ' ' . TextNormalizer::normalize($label) . ' ';
        foreach (['sexo', 'genero', 'sex', 'gender', 'sex at birth', 'sexo al nacer',
                  'gender identity', 'identidad de genero'] as $w) {
            if (strpos($t, ' ' . $w . ' ') !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * Does the label name the entity, rather than talk about something that
     * happened to the patient there?
     */
    private function namesEntity(string $haystack, string $term, string $rawLabel): bool
    {
        if (preg_match('/[?¿]/u', $rawLabel)) {
            return false;
        }
        $whole = trim($haystack);
        // "Nombre del centro", "Tipo de servicio": the prefix does not change
        // the fact that the entity is being named.
        // The same with «Responsible rheumatologist» or «Treating physician».
        $withoutPrefix = preg_replace('/^(nombre de la|nombre del|nombre de|name of the|name of|tipo de|type of|responsible|treating|attending|lead)\s+/', '', $whole);
        $needle = rtrim($term, '*');
        $startsWith = function (string $t) use ($term, $needle): bool {
            return strpos($t . ' ', $needle . ' ') === 0
                || (substr($term, -1) === '*' && strpos($t, $needle) === 0);
        };
        // Checked with and without the prefix: a prefix can itself be the
        // term that matched («Attending physician» matches 'attending').
        return $startsWith($withoutPrefix) || $startsWith($whole);
    }

    // ----------------------------------------------------------------- Helpers

    /**
     * Implicit choices of the types that do not declare them in the dictionary.
     *
     * REDCap leaves select_choices_or_calculations empty for yesno and
     * truefalse fields because their choices are fixed. Without synthesising
     * them here, the rest of the module would treat them as single-value
     * fields and would end up emitting a row for the negative answer too.
     */
    const IMPLICIT_CHOICES = [
        'yesno'     => ['1' => 'Yes', '0' => 'No'],
        'truefalse' => ['1' => 'True', '0' => 'False'],
    ];

    /** Types whose choices are declared in select_choices_or_calculations. */
    const DECLARED_CHOICE_TYPES = ['radio', 'dropdown', 'checkbox'];

    /**
     * Choices of a field, declared or implicit.
     *
     * Only categorical fields have choices. In a calculated field that same
     * column holds the formula, and in an sql field a query: splitting them on
     * commas would fabricate choices such as «round([peso]*10000/([talla]*[talla])» = «1)».
     */
    public static function choicesFor(array $meta): array
    {
        $type = $meta['field_type'] ?? '';
        if (isset(self::IMPLICIT_CHOICES[$type])) {
            return self::IMPLICIT_CHOICES[$type];
        }
        if (!in_array($type, self::DECLARED_CHOICE_TYPES, true)) {
            return [];
        }
        return self::parseChoices((string) ($meta['select_choices_or_calculations'] ?? ''));
    }

    /**
     * Is a choice label a plain yes/no?
     *
     * In those cases the choice adds no clinical meaning: the concept is
     * defined by the question, not by the answer.
     */
    public static function isBinaryLabel(string $label): bool
    {
        return in_array(TextNormalizer::normalize($label), [
            'si', 'no', 'yes', 'true', 'false', 'verdadero', 'falso',
            'presente', 'ausente', 'present', 'absent', 'positivo', 'negativo',
            'positive', 'negative', 'no consta', 'desconocido',
            'unknown', 'ns nc',
        ], true);
    }

    /**
     * Is it the negative choice of a binary field?
     *
     * In OMOP absence is represented by the absence of a row, so the "No" of
     * a clinical yes/no must not emit. It is used as the default so that the
     * correct behaviour is the one that needs no change.
     */
    public static function isNegativeChoice(array $meta, string $rawValue): bool
    {
        $type = $meta['field_type'] ?? '';
        if (isset(self::IMPLICIT_CHOICES[$type])) {
            return $rawValue === '0';
        }
        $choices = self::choicesFor($meta);
        if (count($choices) !== 2 || !isset($choices[$rawValue])) {
            return false;
        }
        $label = TextNormalizer::normalize((string) $choices[$rawValue]);
        return in_array($label, ['no', 'false', 'falso', 'ausente', 'absent',
                                 'negativo', 'negative', 'ninguno', 'none'], true);
    }

    /**
     * Turns "1, Sí | 0, No" into ['1' => 'Sí', '0' => 'No'].
     * It lives here so the profiler can be tested without booting REDCap.
     */
    public static function parseChoices(string $raw): array
    {
        $choices = [];
        foreach (explode('|', $raw) as $chunk) {
            $chunk = trim($chunk);
            if ($chunk === '') {
                continue;
            }
            $parts = explode(',', $chunk, 2);
            if (count($parts) !== 2) {
                continue;
            }
            $choices[trim($parts[0])] = trim($parts[1]);
        }
        return $choices;
    }

    /**
     * Value strategy that suits a categorical field.
     *
     * A dropdown for sex, for comorbidities or a yes/no needs one concept per
     * choice: each answer means something different. Starting from "one
     * concept for the whole field" forces a manual change in most of the
     * project's categorical fields, which is work the module can avoid.
     *
     * The exception is rating scales: a pain score from 1 to 10 takes a single
     * severity concept and the number in value_as_number. There, "per choice"
     * would create ten concepts where there should be one.
     */
    public static function suggestedStrategy(string $valueKind, array $choices,
                                             string $domain): ?string
    {
        if ($valueKind !== 'categorical' || empty($choices)) {
            return null;
        }
        if (self::looksLikeRatingScaleStatic($choices)) {
            return 'single_concept';
        }
        // A categorical lab or questionnaire result is ONE question with
        // several answers: «Baciloscopia» = «Positiva» (sputum smear =
        // positive). In OMOP that is a measurement concept with the answer in
        // value_as_concept_id. Treating each answer as a separate fact
        // produced rows whose measurement_concept_id was «Positive», which is
        // not a measurement. Yes/no fields stay per choice: the «Sí» IS the
        // fact and the «No» does not emit.
        if (in_array($domain, [self::D_MEASUREMENT, self::D_OBSERVATION], true)
            && !self::looksBinaryChoices($choices)) {
            return 'value_concept';
        }
        return 'per_choice';
    }

    /**
     * Are the choices a yes/no?
     *
     * Strict on purpose: «Positivo/Negativo» is not a yes/no but a result, and
     * as a result it goes in value_as_concept_id.
     */
    public static function looksBinaryChoices(array $choices): bool
    {
        if (count($choices) !== 2) {
            return false;
        }
        foreach ($choices as $label) {
            if (in_array(TextNormalizer::normalize((string) $label),
                         ['si', 'no', 'yes', 'true', 'false', 'verdadero', 'falso'], true)) {
                return true;
            }
        }
        return false;
    }

    /** @see looksLikeRatingScale, as a static version so it can be reused. */
    public static function looksLikeRatingScaleStatic(array $choices): bool
    {
        if (count($choices) < 5) {
            return false;
        }
        $numeric = 0;
        foreach ($choices as $raw => $label) {
            // Only the LABEL is checked. In REDCap the raw value of any
            // categorical field is 1, 2, 3... by convention, so checking it
            // turned every five-choice dropdown into a «scale».
            if (preg_match('/^\s*\d+/', (string) $label)) {
                $numeric++;
            }
        }
        return $numeric >= count($choices) - 1;
    }

    /**
     * What can be done with the field in the transformation:
     *   no     - excluded, generates nothing
     *   manual - free text: it has to be coded person by person
     *   auto   - transformable as soon as the concept is chosen
     */
    private static function mappability(string $domain, string $valueKind): string
    {
        if ($domain === self::D_SKIP) {
            return 'no';
        }
        // In NOTE the text goes as is: nothing needs coding.
        if ($valueKind === 'text' && $domain !== 'Note') {
            return 'manual';
        }
        return 'auto';
    }

    private function row(string $fieldName, array $meta, string $domain, string $confidence,
                         string $reason, string $valueKind, array $choices, bool $isPhi,
                         ?string $unit): array
    {
        return [
            'field_name'       => $fieldName,
            'form_name'        => $meta['form_name'] ?? '',
            'field_type'       => $meta['field_type'] ?? '',
            'validation'       => $meta['text_validation_type_or_show_slider_number'] ?? '',
            'field_label'      => trim(strip_tags($meta['field_label'] ?? '')),
            'is_identifier'    => $isPhi,
            'value_kind'       => $valueKind,
            'unit_hint'        => $unit,
            'suggested_strategy' => self::suggestedStrategy($valueKind, $choices, $domain),
            'mappable'         => self::mappability($domain, $valueKind),
            'choices'          => $choices,
            'choice_count'     => count($choices),
            'suggested_domain' => $domain,
            'suggested_table'  => CdmModel::tableForDomain($domain),
            'confidence'       => $confidence,
            'reason'           => $reason,
        ];
    }

    /**
     * Searches every domain and returns the MOST SPECIFIC match.
     *
     * Matching is at word boundaries. The '*' suffix turns the term into a
     * stem: it matches the start of a word and accepts any continuation. The
     * text arrives already normalised and surrounded by spaces.
     *
     * If the first domain in the evaluation order won, "pérdida de peso"
     * (weight loss) would fall into Measurement because of the word "peso"
     * instead of into Condition because of the whole phrase. A three-word term
     * describes the field much better than a single word, so length decides
     * and the domain order only breaks ties.
     */
    private function matchKeywords(string $haystack, array $domains): ?array
    {
        $best = null;
        $bestLength = -1;

        foreach ($domains as $priority => $domain) {
            foreach ($this->keywords[$domain] ?? [] as $term) {
                $isStem = substr($term, -1) === '*';
                $needle = $isStem ? rtrim($term, '*') : $term;
                $pattern = $isStem ? ' ' . $needle : ' ' . $needle . ' ';
                if (strpos($haystack, $pattern) === false) {
                    continue;
                }
                $length = strlen($needle);
                if ($length > $bestLength) {
                    $bestLength = $length;
                    $best = ['domain' => $domain, 'term' => $term, 'priority' => $priority];
                }
            }
        }
        return $best;
    }

    /** Removes the phrases that place a data item in time without describing it. */
    private function stripContextPhrases(string $haystack): string
    {
        foreach ($this->contextPhrases as $phrase) {
            $haystack = str_replace(' ' . $phrase . ' ', ' ', $haystack);
        }
        return $haystack;
    }

    private function isDateValidation(string $validation): bool
    {
        return $validation !== '' && strpos($validation, 'date') === 0;
    }
}
