<?php

namespace OmopCdm\OmopCdmExportModule;

require_once __DIR__ . '/TextNormalizer.php';
require_once __DIR__ . '/MappingStore.php';

/**
 * Warnings about the mapping of a field.
 *
 * The classifier says which domain a field resembles. This class says
 * something different: whether the mapping the curator is about to approve
 * will produce misleading data.
 *
 * The difference matters. A mapping can be formally correct (valid domain,
 * existing concept, date present) and still generate rows that lie. The
 * typical case: a "was medication given?" mapped to Drug produces exposures to
 * a generic drug while the real drug stays in a text field that nobody will
 * code. That is worse than exporting nothing, because it looks like
 * information.
 *
 * Each warning has a severity:
 *   - 'block'  prevents approving. Only for what produces an invalid CDM.
 *   - 'warn'   allows approving, but requires explicit confirmation.
 *   - 'info'   is just shown.
 *
 * The bar for 'block' is deliberately high: the module assists, it does not
 * rule. A curator who knows what they are doing must be able to go ahead.
 */
class MappingAdvisor
{
    /** Domains in which a generic yes/no is almost never a good idea. */
    private const RISKY_YESNO_DOMAINS = ['Drug', 'Procedure', 'Device', 'Specimen'];

    /**
     * Words that give away a "was something done?" field that does not say
     * what. Spanish and English terms, matched against the normalised label.
     */
    private const VAGUE_TERMS = [
        'medicacion', 'medicamento', 'tratamiento', 'farmaco', 'pastilla*',
        'prueba*', 'analitica', 'intervencion*', 'procedimiento*', 'algo',
        'medication', 'treatment', 'drug*', 'test*', 'procedure*', 'something',
    ];

    private $module;

    public function __construct($module)
    {
        $this->module = $module;
    }

    /**
     * Reviews the mapping of a field.
     *
     * @return array<int,array{severity:string,code:string,text:string}>
     */
    public function check(string $field, array $spec, array $prof, array $values, array $dictMeta): array
    {
        $out = [];
        $domain = $spec['domain'] ?? null;

        if ($domain === null
            || in_array($domain, [DictionaryProfiler::D_SKIP, DictionaryProfiler::D_DATE_ANCHOR], true)) {
            return $out;
        }

        $type      = $dictMeta['field_type'] ?? '';
        $choices   = $prof['choices'] ?? [];
        $valueKind = $prof['value_kind'] ?? '';
        $haystack  = ' ' . TextNormalizer::normalize($field . ' ' . ($prof['field_label'] ?? '')) . ' ';

        // Mapping imported from a project whose options are named differently
        // (typically, the same instrument in another language).
        if (!empty($spec['import_diff']) && ($spec['state'] ?? '') !== MappingStore::S_APPROVED) {
            $out[] = $this->w('warn', 'import_labels_differ', [implode(', ', $spec['import_diff'])]);
        }
        $this->checkIdentifier($out, $prof);
        $this->checkFieldValidation($out, $prof, $dictMeta);
        $this->checkFreeText($out, $valueKind, $domain);
        $this->checkVagueYesNo($out, $type, $choices, $domain, $haystack);
        $this->checkMeasurementValue($out, $domain, $valueKind, $spec, $choices);
        $this->checkRatingScale($out, $choices, $spec);
        $this->checkScaleDomain($out, $domain, $choices);
        $this->checkDateSanity($out, $spec, $domain, $field);
        $this->checkDeath($out, $spec, $domain, $field, $values);
        $this->checkVisitConflict($out, $domain);
        $this->checkPersonDomain($out, $domain, $field);
        $this->checkNumericInValuelessDomain($out, $domain, $valueKind);
        $this->checkConceptId($out, $spec, $values);
        // With a vocabulary the concept itself is checked; without one, or if
        // it does not respond, only the shape of the pasted name.
        if (!$this->checkVocabulary($out, $field, $spec, $values, $domain, $choices)) {
            $this->checkConceptName($out, $spec, $prof, $values);
        }
        $this->checkEndDate($out, $spec, $domain);
        $this->checkNote($out, $domain, $valueKind);
        $this->checkCompleteField($out, $field);
        $this->checkEmitAll($out, $domain, $choices, $values, $spec, $dictMeta);

        return $out;
    }

    /** Vocabulary domain that the concept of a field must have. */
    private function expectedDomain(string $domain, string $field): ?string
    {
        if ($domain === 'Person') {
            $conf = $this->module->personConfig();
            if ($field === ($conf['gender_field'] ?? null)) {
                return 'Gender';
            }
            if ($field === ($conf['race_field'] ?? null)) {
                return 'Race';
            }
            if ($field === ($conf['ethnicity_field'] ?? null)) {
                return 'Ethnicity';
            }
            return null;
        }
        if ($domain === 'Death') {
            return 'Condition';     // the cause of death
        }
        if ($domain === 'Note') {
            return null;            // the note class has no fixed domain
        }
        return $domain;
    }

    /**
     * Checks the concepts against the vocabulary.
     *
     * It is the only way to catch the error nothing else sees: a concept_id
     * that exists but is not the one intended. If the pasted name does not
     * match the vocabulary's, it is almost always a typo in the number.
     *
     * @return bool true if there was a vocabulary and it responded; false if
     *              there is no vocabulary or it does not respond (the caller
     *              then applies the name-shape check)
     */
    private function checkVocabulary(array &$out, string $field, array $spec, array $values,
                                     string $domain, array $choices): bool
    {
        if (!method_exists($this->module, 'vocabulary') || $this->module->vocabulary() === null) {
            return false;
        }
        $strategy = $spec['value_strategy'] ?? null;
        $expected = $this->expectedDomain($domain, $field);

        // [concept_id, pasted name, expected domain or null]
        $toCheck = [];
        if (!empty($spec['concept_id'])
            && !(!empty($choices) && $strategy === MappingStore::ST_PER_CHOICE)) {
            $toCheck[] = [(int) $spec['concept_id'], $spec['concept_name'] ?? '', $expected];
        }
        if (!empty($choices) && MappingStore::usesOptions($strategy)) {
            foreach ($values as $v) {
                if (empty($v['emit']) || empty($v['concept_id'])) {
                    continue;
                }
                // With "value per option" the answer may come from any domain
                // (Meas Value, Observation...): none is required.
                $toCheck[] = [(int) $v['concept_id'], $v['concept_name'] ?? '',
                              $strategy === MappingStore::ST_PER_CHOICE ? $expected : null];
            }
        }

        foreach ($toCheck as [$id, $pasted, $dom]) {
            $c = $this->module->conceptInfo($id);
            if ($c === false) {
                // The vocabulary does not respond: nothing has been checked.
                // false is returned so that the name-shape check is applied, as
                // without a vocabulary; a vocabulary that is down cannot review
                // less than having no vocabulary.
                $out[] = $this->w('info', 'vocab_unavailable');
                return false;
            }
            if ($c === null) {
                // A warning, not a block: many sites download only some Athena
                // vocabularies, and a correct concept from another vocabulary
                // would not show up either. It must be confirmed and is logged.
                $out[] = $this->w('warn', 'concept_not_found', [$id]);
                continue;
            }
            if ($c['invalid'] !== '') {
                $out[] = $this->w('warn', 'concept_invalid', [$id, $c['name']]);
            } elseif ($c['standard'] !== 'S') {
                $out[] = $this->w('warn', 'concept_not_standard', [$id, $c['name'], $c['vocabulary']]);
            }
            if ($dom !== null && $c['domain'] !== $dom) {
                $out[] = $this->w('warn', 'concept_wrong_domain', [$id, $c['name'], $c['domain'], $dom]);
            }
            $p = TextNormalizer::normalize((string) $pasted);
            $r = TextNormalizer::normalize($c['name']);
            if ($p !== '' && $p !== $r && strpos($r, $p) === false && strpos($p, $r) === false) {
                $out[] = $this->w('warn', 'concept_name_mismatch', [$id, $pasted, $c['name']]);
            }
        }
        return true;
    }

    /** The end date only exists in some tables, and it has to exist. */
    private function checkEndDate(array &$out, array $spec, string $domain): void
    {
        $end = $spec['end_date_field'] ?? null;
        if (empty($end)) {
            return;
        }
        if (!in_array($domain, ['Condition', 'Drug', 'Procedure', 'Device', 'Visit'], true)) {
            $out[] = $this->w('info', 'end_date_ignored', [$domain]);
            return;
        }
        if (!isset($this->module->getDictionary()[$end])) {
            $out[] = $this->w('block', 'date_field_gone', [$end]);
        }
    }

    /** NOTE is for free text, and it goes dark if free text is suppressed. */
    private function checkNote(array &$out, string $domain, string $valueKind): void
    {
        if ($domain !== 'Note') {
            return;
        }
        if ($valueKind !== 'text') {
            $out[] = $this->w('warn', 'note_not_text');
        }
        if (method_exists($this->module, 'pseudonymizer') && $this->module->pseudonymizer()->suppressesFreeText()) {
            $out[] = $this->w('warn', 'note_suppressed');
        }
    }

    /** Is there any warning that prevents approving? */
    public static function blocks(array $warnings): bool
    {
        foreach ($warnings as $w) {
            if ($w['severity'] === 'block') {
                return true;
            }
        }
        return false;
    }

    /** Is there any warning that requires explicit confirmation? */
    public static function needsConfirm(array $warnings): bool
    {
        foreach ($warnings as $w) {
            if ($w['severity'] === 'warn') {
                return true;
            }
        }
        return false;
    }

    // ------------------------------------------------------------- Rules

    /**
     * A field flagged as an identifier in REDCap must not be exported as a
     * clinical fact: it would end up written verbatim in a CDM *_source_value.
     */
    private function checkIdentifier(array &$out, array $prof): void
    {
        if (!empty($prof['is_identifier'])) {
            $out[] = $this->w('warn', 'identifier');
        }
    }

    /**
     * Field definition errors in REDCap that are paid for at export time.
     *
     * A label that says "Start date" on a text field without date validation,
     * or a "CD4 count" on free text, are instrument design errors, not mapping
     * errors. They are warned about because they are cheap to fix in the
     * Designer and expensive later: without validation, REDCap does not
     * guarantee the format and the data can no longer be transformed.
     */
    private function checkFieldValidation(array &$out, array $prof, array $dictMeta): void
    {
        $fieldType  = $dictMeta['field_type'] ?? '';
        $validation = $dictMeta['text_validation_type_or_show_slider_number'] ?? '';
        if ($fieldType !== 'text' || $validation !== '') {
            return;
        }

        $label = ' ' . TextNormalizer::normalize($prof['field_label'] ?? '') . ' ';

        // Spanish and English terms, matched against the normalised label.
        foreach ([' fecha ', ' fecha de ', ' date ', ' date of ', ' dia de '] as $t) {
            if (strpos($label, $t) !== false) {
                $out[] = $this->w('warn', 'label_date_no_validation');
                return;
            }
        }

        $numericTerms = [' numero ', ' n ', ' cuantos ', ' cuantas ', ' recuento ',
                         ' porcentaje ', ' cantidad ', ' total de ', ' edad ',
                         ' carga viral ', ' nivel de ', ' count ', ' number ',
                         ' how many ', ' percentage ', ' age '];
        foreach ($numericTerms as $t) {
            if (strpos($label, $t) !== false) {
                $out[] = $this->w('warn', 'label_numeric_no_validation');
                return;
            }
        }

        // A unit in parentheses only makes sense on a number.
        if (preg_match('/\(\s*(kg|cm|mm|m|g|mg|ml|dl|l|%|mmhg|lpm|u\/l|mg\/dl|g\/dl|mmol|ui)\s*[^)]*\)\s*$/iu',
                       $prof['field_label'] ?? '')) {
            $out[] = $this->w('warn', 'label_unit_no_validation');
        }
    }

    /**
     * Free text cannot be transformed: each answer is different and nobody
     * will code them one by one.
     */
    private function checkFreeText(array &$out, string $valueKind, string $domain): void
    {
        // In NOTE, free text is exactly what is meant to be stored.
        if ($valueKind === 'text' && $domain !== 'Note') {
            $out[] = $this->w('warn', 'free_text');
        }
    }

    /**
     * The central case: "was medication given?" mapped to Drug.
     *
     * A yes/no that does not specify what produces exposure rows to a generic
     * concept. It looks like information and it is not, and it contaminates
     * any drug analysis done later on that CDM.
     */
    private function checkVagueYesNo(array &$out, string $type, array $choices,
                                     string $domain, string $haystack): void
    {
        $isYesNo = $type === 'yesno' || $type === 'truefalse'
                || (count($choices) === 2 && $this->looksBinary($choices));

        if (!$isYesNo || !in_array($domain, self::RISKY_YESNO_DOMAINS, true)) {
            return;
        }

        foreach (self::VAGUE_TERMS as $term) {
            $stem   = substr($term, -1) === '*';
            $needle = $stem ? rtrim($term, '*') : $term;
            $found  = $stem
                ? strpos($haystack, ' ' . $needle) !== false
                : strpos($haystack, ' ' . $needle . ' ') !== false;
            if ($found) {
                $out[] = $this->w('warn', 'vague_yesno', [$domain]);
                return;
            }
        }

        // Even if no word matches, a yes/no in these domains deserves a look:
        // it is rarely known *which* drug or procedure it was.
        $out[] = $this->w('info', 'yesno_in_domain', [$domain]);
    }

    /**
     * A MEASUREMENT without a numeric value or unit is a measurement that
     * does not measure.
     */
    private function checkMeasurementValue(array &$out, string $domain, string $valueKind,
                                           array $spec, array $choices): void
    {
        if ($domain !== 'Measurement') {
            return;
        }
        if ($valueKind === 'numeric' && empty($spec['unit_source_value'])) {
            $out[] = $this->w('warn', 'measurement_no_unit');
        }
        if ($valueKind === 'text') {
            $out[] = $this->w('warn', 'measurement_text');
        }
        // A categorical result treated option by option turns the ANSWER into
        // the measurement: measurement_concept_id = "Positive". The right
        // strategy is "measurement concept + value per option".
        if ($valueKind === 'categorical' && ($spec['value_strategy'] ?? '') === MappingStore::ST_PER_CHOICE
            && !DictionaryProfiler::looksBinaryChoices($choices)
            && !DictionaryProfiler::looksLikeRatingScaleStatic($choices)) {
            $out[] = $this->w('warn', 'categorical_per_choice');
        }
    }

    /**
     * DEATH has its own rules: one row per person, the date is the date of
     * death and the concept, if any, is the CAUSE.
     */
    private function checkDeath(array &$out, array $spec, string $domain, string $field, array $values): void
    {
        if ($domain !== 'Death') {
            return;
        }
        $conf = method_exists($this->module, 'deathConfig') ? $this->module->deathConfig() : [];
        $isDate = method_exists($this->module, 'isDateField') && $this->module->isDateField($field);
        if (empty($conf['date_field']) && !$isDate) {
            $out[] = $this->w('warn', 'death_no_date_config');
        }
        $ids = array_filter(array_merge([$spec['concept_id'] ?? null],
            array_map(fn($v) => !empty($v['emit']) ? ($v['concept_id'] ?? null) : null, $values)));
        if ($ids) {
            $out[] = $this->w('info', 'death_concept_is_cause');
        }
    }

    /**
     * A rating scale mapped option by option creates one concept per point of
     * the scale, when the right thing is a severity concept and the number in
     * value_as_number.
     */
    private function checkRatingScale(array &$out, array $choices, array $spec): void
    {
        if (count($choices) < 5 || !MappingStore::usesOptions($spec['value_strategy'] ?? null)) {
            return;
        }
        $numeric = 0;
        foreach ($choices as $raw => $label) {
            // Only the LABEL is looked at. In REDCap the raw value of any
            // categorical field is 1, 2, 3... by convention, so checking it
            // turned every five-option dropdown into a "scale".
            if (preg_match('/^\s*\d+/', (string) $label)) {
                $numeric++;
            }
        }
        if ($numeric >= count($choices) - 1) {
            $out[] = $this->w('warn', 'scale_per_choice', [count($choices)]);
        }
    }

    /**
     * A numeric scale measures intensity, not presence. It goes to MEASUREMENT
     * with the number in value_as_number; in CONDITION_OCCURRENCE the value is
     * lost and it claims that the patient "has" something that is really a
     * grade.
     */
    private function checkScaleDomain(array &$out, string $domain, array $choices): void
    {
        if ($domain === 'Measurement' || $domain === 'Observation' || count($choices) < 5) {
            return;
        }
        $numeric = 0;
        foreach ($choices as $raw => $label) {
            // Only the LABEL is looked at. In REDCap the raw value of any
            // categorical field is 1, 2, 3... by convention, so checking it
            // turned every five-option dropdown into a "scale".
            if (preg_match('/^\s*\d+/', (string) $label)) {
                $numeric++;
            }
        }
        if ($numeric >= count($choices) - 1) {
            $out[] = $this->w('warn', 'scale_wrong_domain', [$domain, count($choices)]);
        }
    }

    /**
     * Without a date there is no row. And a date from an instrument other
     * than the field's usually means the fact will be wrongly dated, which is
     * worse than going without it.
     */
    private function checkDateSanity(array &$out, array $spec, string $domain, string $field): void
    {
        if (in_array($domain, ['Person', 'Death'], true)) {
            return;
        }

        $dateField = $spec['date_field'] ?? null;
        if (empty($dateField)) {
            if ($this->module->defaultDateField() === null) {
                $out[] = $this->w('block', 'no_date_at_all');
            } else {
                $out[] = $this->w('info', 'using_default_date',
                    [$this->module->defaultDateField()]);
            }
            return;
        }

        $dict = $this->module->getDictionary();

        // The chosen date field may have disappeared from the data dictionary
        // after mapping it. Without it, the fact cannot be dated.
        if (!isset($dict[$dateField])) {
            $out[] = $this->w('block', 'date_field_gone', [$dateField]);
            return;
        }

        $ownForm  = $dict[$field]['form_name'] ?? '';
        $dateForm = $dict[$dateField]['form_name'] ?? '';
        if ($ownForm !== '' && $dateForm !== '' && $ownForm !== $dateForm) {
            $out[] = $this->w('warn', 'date_other_form', [$dateField, $dateForm]);
        }
    }

    /**
     * A field in the Visit domain with automatic generation enabled would
     * produce mixed rows in VISIT_OCCURRENCE: some derived from the collection
     * occasions and others from the field's content, with different meanings
     * and no facts referencing them.
     */
    private function checkVisitConflict(array &$out, string $domain): void
    {
        if ($domain !== 'Visit') {
            return;
        }
        if (method_exists($this->module, 'visitConfig')
            && $this->module->visitConfig()['enabled']) {
            $out[] = $this->w('block', 'visit_conflict');
        }
    }

    /**
     * A field in the Person domain generates no row.
     *
     * The PERSON table is built from the fields declared in the module
     * configuration (sex and birth date), not from the approved fields.
     * Approving any other field as Person leaves it out of the CDM without
     * anything saying so: it is a silent failure, and one of the worst,
     * because the curator believes they have mapped something.
     */
    private function checkPersonDomain(array &$out, string $domain, string $field): void
    {
        if ($domain !== 'Person') {
            return;
        }
        $conf = method_exists($this->module, 'personConfig')
            ? $this->module->personConfig() : [];
        $used = array_values(array_filter([
            $conf['gender_field'] ?? null,
            $conf['birth_date_field'] ?? null,
            $conf['birth_year_field'] ?? null,
            $conf['race_field'] ?? null,
            $conf['ethnicity_field'] ?? null,
        ]));
        $structure = method_exists($this->module, 'getProjectStructure')
            ? $this->module->getProjectStructure() : [];
        if (!empty($structure['record_id_field'])) {
            $used[] = $structure['record_id_field'];
        }

        if (in_array($field, $used, true)) {
            return;
        }

        // If the project does not declare any PERSON field yet, the problem is
        // not this field: the module still needs configuring. Saying "send
        // this to Observation" when what is needed is filling in a setting
        // sends the curator in the opposite direction.
        if (empty($used) || count($used) <= 1) {
            $out[] = $this->w('warn', 'person_not_configured');
            return;
        }

        $out[] = $this->w('warn', 'person_no_rows');
    }

    /**
     * A numeric value in a domain without a value column is lost.
     *
     * Only MEASUREMENT and OBSERVATION have value_as_number. Mapping "No. of
     * missed doses" to Drug generates an exposure row and throws away the
     * number, which was exactly the data. The row exists, so nothing seems to
     * fail.
     */
    private function checkNumericInValuelessDomain(array &$out, string $domain,
                                                   string $valueKind): void
    {
        if ($valueKind !== 'numeric') {
            return;
        }
        if (in_array($domain, ['Measurement', 'Observation', 'Person', 'Death'], true)) {
            return;
        }
        $table = CdmModel::tableForDomain($domain);
        if ($table !== null) {
            $out[] = $this->w('warn', 'numeric_lost', [$domain, strtoupper($table)]);
        }
    }

    /**
     * Cheap checks on the number: OMOP concept_ids are positive integers, and
     * 0 means "no equivalent concept".
     */
    private function checkConceptId(array &$out, array $spec, array $values): void
    {
        $ids = [];
        if (!empty($spec['concept_id'])) {
            $ids[] = (int) $spec['concept_id'];
        }
        foreach ($values as $v) {
            if (!empty($v['concept_id'])) {
                $ids[] = (int) $v['concept_id'];
            }
        }
        foreach ($ids as $id) {
            if ($id < 0) {
                $out[] = $this->w('block', 'concept_negative');
                return;
            }
            if ($id === 0) {
                $out[] = $this->w('info', 'concept_zero');
                return;
            }
        }

        // The same concept on every option of a categorical field defeats the
        // purpose of having separated them.
        $used = [];
        foreach ($values as $v) {
            if (!empty($v['emit']) && !empty($v['concept_id'])) {
                $used[] = (int) $v['concept_id'];
            }
        }
        if (count($used) > 1 && count(array_unique($used)) === 1) {
            $out[] = $this->w('warn', 'same_concept_all');
        }
    }

    /**
     * The concept name has to come from Athena, not from the project.
     *
     * Pasting the name next to the number is the only thing that allows a typo
     * in the figure to be detected later: if the number says 8508 and the name
     * says "MALE", the inconsistency shows. If the local label ("Hombre") is
     * copied instead, the name always matches and the check is lost. Without a
     * loaded vocabulary, this is the only safety net left.
     */
    private function checkConceptName(array &$out, array $spec, array $prof, array $values): void
    {
        $suspects = [];

        if (!empty($spec['concept_id'])) {
            if (empty($spec['concept_name'])) {
                $out[] = $this->w('warn', 'no_concept_name');
            } elseif ($this->isLocalLabel($spec['concept_name'], [$prof['field_label'] ?? ''])) {
                $suspects[] = $spec['concept_name'];
            }
        }

        foreach ($values as $raw => $v) {
            if (empty($v['concept_id'])) {
                continue;
            }
            if (empty($v['concept_name'])) {
                $out[] = $this->w('warn', 'no_concept_name_option', [(string) $raw]);
                continue;
            }
            $labels = [$v['source_value'] ?? '', $prof['choices'][$raw] ?? ''];
            if ($this->isLocalLabel($v['concept_name'], $labels)) {
                $suspects[] = $v['concept_name'];
            }
        }

        if ($suspects) {
            $out[] = $this->w('warn', 'concept_name_is_label',
                [implode(', ', array_slice(array_unique($suspects), 0, 3))]);
        }
    }

    /**
     * Does the pasted name look like it comes from Athena?
     *
     * Without a loaded vocabulary nothing can be verified. The only observable
     * thing is the shape of the text: the OHDSI vocabularies are in English and
     * their names are short noun phrases, so a question, an instruction, a long
     * sentence or a Spanish text cannot be the name of a concept.
     *
     * Matching the project's label is NOT used as a signal. An English
     * questionnaire with a field "Body weight" and the LOINC concept "Body
     * weight" match while being correct, because whoever writes in English
     * uses the same terminology as LOINC and SNOMED.
     *
     * The rule is deliberately conservative: when in doubt, no warning.
     * Letting a case through costs little; a repeated false warning teaches
     * people to ignore them all, and then the correct ones stop working too.
     */
    private function isLocalLabel(string $name, array $labels): bool
    {
        if (trim($name) === '') {
            return false;
        }
        return $this->looksSpanish($name)
            || $this->looksLikeSurveyWording($name);
    }

    /**
     * Marks of Spanish. The OHDSI vocabularies are in English, so a name in
     * Spanish was not copied from the Athena column.
     *
     * Only words that do not exist in English are used: a match with an
     * English word would give a false positive on a legitimate name.
     */
    private function looksSpanish(string $text): bool
    {
        if (preg_match('/[áéíóúñüÁÉÍÓÚÑÜ¿¡]/u', $text)) {
            return true;
        }
        // Spanish words (data the module must recognise, not to be translated).
        $unambiguous = [
            'el', 'la', 'los', 'las', 'del', 'de', 'con', 'sin', 'para', 'por',
            'que', 'una', 'unos', 'unas', 'si', 'ni', 'pero', 'como', 'cuando',
            'hombre', 'mujer', 'varon', 'paciente', 'fecha', 'peso', 'talla',
            'altura', 'edad', 'sexo', 'dolor', 'fiebre', 'sangre', 'muestra',
            'valor', 'resultado', 'nivel', 'grado', 'otro', 'otra', 'otros',
            'ninguno', 'ninguna', 'prefiero', 'decirlo', 'nada', 'poco',
            'mucho', 'alto', 'bajo', 'medio', 'leve', 'grave', 'ingreso',
            'visita', 'consulta', 'seguimiento', 'tratamiento', 'medicacion',
            'diagnostico', 'antecedentes', 'sintomas', 'analitica',
        ];
        foreach (explode(' ', TextNormalizer::normalize($text)) as $t) {
            if ($t !== '' && in_array($t, $unambiguous, true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * A question or an instruction is not a concept name.
     *
     * Length is NOT a signal: LOINC names are long on purpose ("Cholesterol in
     * LDL [Mass/volume] in Serum or Plasma by calculation" has nine words and
     * is correct). Counting words warned precisely about the most precise
     * names.
     */
    private function looksLikeSurveyWording(string $text): bool
    {
        if (preg_match('/[?¿]/u', $text)) {
            return true;
        }
        $openings = ['do ', 'does ', 'did ', 'is ', 'are ', 'was ', 'were ',
                     'has ', 'have ', 'how ', 'what ', 'when ', 'which ',
                     'please ', 'select ', 'enter ', 'indicate ', 'specify '];
        $t = TextNormalizer::normalize($text) . ' ';
        foreach ($openings as $opening) {
            if (strpos($t, $opening) === 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * *_complete fields belong to REDCap, not to the study. Seeing them
     * mapped is a sign that someone is approving without looking.
     */
    private function checkCompleteField(array &$out, string $field): void
    {
        if (substr($field, -9) === '_complete') {
            $out[] = $this->w('block', 'redcap_complete_field');
        }
    }

    /**
     * In a clinical yes/no, also emitting the "No" inflates cohorts: in OMOP
     * absence is represented by the absence of a row.
     */
    private function checkEmitAll(array &$out, string $domain, array $choices,
                                  array $values, array $spec, array $dictMeta = []): void
    {
        if (($spec['value_strategy'] ?? '') !== 'per_choice'
            || count($choices) !== 2
            || !in_array($domain, ['Condition', 'Procedure', 'Drug', 'Device'], true)) {
            return;
        }
        if (!$this->looksBinary($choices)) {
            return;
        }

        // An untouched option emits whatever its default value says, and the
        // default "No" does NOT emit. Counting it as "emits" warned about a
        // non-existent problem on every freshly opened yes/no.
        $emitting = 0;
        foreach ($choices as $raw => $label) {
            $v = $values[(string) $raw] ?? null;
            $emits = $v !== null
                ? !empty($v['emit'])
                : !DictionaryProfiler::isNegativeChoice($dictMeta, (string) $raw);
            if ($emits) {
                $emitting++;
            }
        }
        if ($emitting === 2) {
            $out[] = $this->w('warn', 'emits_negative');
        }
    }

    // ---------------------------------------------------------- Utilities

    /** Does the pair of options look like a yes/no? */
    private function looksBinary(array $choices): bool
    {
        $labels = array_map(
            fn($l) => TextNormalizer::normalize((string) $l),
            array_values($choices)
        );
        // Spanish and English yes/no labels, normalised.
        $yesNo = ['si', 'no', 'yes', 'true', 'false', 'verdadero', 'falso',
                  'presente', 'ausente', 'present', 'absent'];
        $hits = 0;
        foreach ($labels as $l) {
            if (in_array($l, $yesNo, true)) {
                $hits++;
            }
        }
        return $hits >= 1;
    }

    private function w(string $severity, string $code, array $args = []): array
    {
        // Plain text: whoever renders it escapes it once (PHP with
        // htmlspecialchars, JavaScript with textContent).
        return [
            'severity' => $severity,
            'code'     => $code,
            'text'     => method_exists($this->module, 'ttText')
                ? $this->module->ttText('adv_' . $code, ...$args)
                : $this->module->tt('adv_' . $code, ...$args),
        ];
    }
}
