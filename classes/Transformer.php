<?php

namespace OmopCdm\OmopCdmExportModule;

require_once __DIR__ . '/CdmModel.php';
require_once __DIR__ . '/MappingStore.php';
require_once __DIR__ . '/IdRegistry.php';
require_once __DIR__ . '/Pseudonymizer.php';

/**
 * Converts the data of a REDCap project into OMOP CDM v5.4 rows.
 *
 * Principles that govern everything in here:
 *
 *  - Only what is approved gets in. A field in any other state produces no
 *    row at all, not even with a warning. That includes the PERSON gender,
 *    race and ethnicity concepts: declaring the field in the configuration
 *    says where the data is, but what each option means only counts once
 *    approved.
 *  - Absence is represented by absence. A "No" on a diagnosis does not
 *    generate a CONDITION_OCCURRENCE row; emitting it would inflate any cohort
 *    built afterwards.
 *  - No row without a date, in any clinical table. The CDM requires it; a row
 *    without one is discarded and counted as a problem.
 *  - A REDCap code is not a value. The "2" of a dropdown never reaches
 *    value_as_number: the answer goes out as a concept (value_as_concept_id)
 *    or as text, except for numeric rating scales.
 *  - Identifiers are stable: the same person, visit or fact carries the same
 *    number in every export (see IdRegistry).
 *  - Everything discarded is counted and explained. The transformation report
 *    is as important as the rows: without it, the data that is lost is lost
 *    silently.
 */
class Transformer
{
    /** Study form record: the provenance of every piece of REDCap data. */
    const TYPE_EHR_FORM = 32809;
    /** Observation period derived from the data. */
    const TYPE_OBS_PERIOD = 32817;
    /** UTF-8, for NOTE.encoding_concept_id. */
    const ENCODING_UTF8 = 32678;

    /** REDCap validations that guarantee a number with a decimal point. */
    const NUMERIC_VALIDATIONS = ['integer', 'number', 'number_1dp', 'number_2dp',
                                 'number_3dp', 'number_4dp', 'float', 'int'];

    /** Date column of each fact table, used to link it to its visit. */
    const DATE_COLUMN = [
        'condition_occurrence' => 'condition_start_date',
        'drug_exposure'        => 'drug_exposure_start_date',
        'procedure_occurrence' => 'procedure_date',
        'measurement'          => 'measurement_date',
        'observation'          => 'observation_date',
        'device_exposure'      => 'device_exposure_start_date',
        'specimen'             => 'specimen_date',
        'note'                 => 'note_date',
    ];

    /** Identifier column of each fact table. */
    const ID_COLUMN = [
        'condition_occurrence' => 'condition_occurrence_id',
        'drug_exposure'        => 'drug_exposure_id',
        'procedure_occurrence' => 'procedure_occurrence_id',
        'measurement'          => 'measurement_id',
        'observation'          => 'observation_id',
        'device_exposure'      => 'device_exposure_id',
        'specimen'             => 'specimen_id',
        'note'                 => 'note_id',
    ];

    /** End date column, for the domains that have one. */
    const END_DATE_COLUMN = [
        'condition_occurrence' => 'condition_end_date',
        'drug_exposure'        => 'drug_exposure_end_date',
        'procedure_occurrence' => 'procedure_end_date',
        'device_exposure'      => 'device_exposure_end_date',
    ];

    private $module;
    private $store;
    private $dictionary;
    private $profile;
    private $structure;

    /** Configuration read once: the fact loop must not query the database. */
    private $visitConf;
    private $defaultDate;
    private $personConf;
    private $deathConf;
    private $entityConf;
    private $dagId;
    /** @var Pseudonymizer */
    private $pseudo;
    /** @var IdRegistry */
    private $registry;

    /** @var array<string,array> generated rows per table */
    private $tables = [];
    /** @var array<string,int> skip reasons, as "code|argument" */
    private $skipped = [];
    /** @var array<string,int> */
    private $stats = [];
    /** @var array<int,array{min:string,max:string}> */
    private $personDates = [];

    /** Record, visit key and row key of the current occasion. */
    private $currentRecord = null;
    private $currentVisitKey = null;
    private $currentRowKey = null;
    /** Flattened data of the current record, for what does not repeat. */
    private $currentFlat = [];
    /** @var array<string,array> event => main row, of the current record */
    private $eventRows = [];

    /** @var array<string,array> visit key => accumulated data */
    private $visits = [];
    /** @var array<string,array> visit key => date, care site and provider */
    private $occasions = [];

    /** @var array<int,string> dropped persons => their source record_id */
    private $dropped = [];

    public function __construct($module)
    {
        $this->module      = $module;
        $this->store       = $module->store();
        $this->dictionary  = $module->getDictionary();
        $this->profile     = $module->profile();
        $this->structure   = $module->getProjectStructure();
        $this->visitConf   = $module->visitConfig();
        $this->defaultDate = $module->defaultDateField();
        $this->personConf  = $module->personConfig();
        $this->deathConf   = $module->deathConfig();
        $this->entityConf  = $module->entityConfig();
        $this->dagId       = $module->userDagId();
        $this->pseudo      = $module->pseudonymizer();
    }

    /**
     * Runs the full transformation.
     *
     * @param int|null $limit  Maximum number of records, for previews.
     * @param bool     $dryRun Preview: new identifiers are not saved in the
     *                         registry.
     */
    public function run(?int $limit = null, bool $dryRun = false): array
    {
        $this->tables = [];
        foreach (array_keys(CdmModel::tables()) as $t) {
            $this->tables[$t] = [];
        }
        $this->skipped  = [];
        $this->stats    = ['records' => 0, 'rows' => 0];
        $this->registry = $this->module->idRegistry($dryRun);

        $approved = $this->approvedFields();
        if (empty($approved)) {
            return $this->result(['error' => 'no_approved_fields']);
        }

        $ids = $this->recordIds($limit);
        $this->stats['batches'] = 0;
        if ($this->dagId !== null) {
            $this->stats['dag_id'] = $this->dagId;
        }
        if ($this->pseudo->shiftsDates()) {
            $this->stats['date_shift'] = 1;
        }

        foreach (array_chunk($ids, self::BATCH_SIZE) as $chunk) {
            $this->stats['batches']++;
            foreach ($this->readBatch($chunk) as $recordId => $rows) {
                $this->stats['records']++;
                $recordId = (string) $recordId;
                $this->currentRecord = $recordId;
                $personId = $this->registry->id('person', $recordId);
                $this->currentFlat = $this->flatten($rows);
                $this->eventRows = [];
                foreach ($rows as $r) {
                    if ($r['__form'] === null && empty($r['__repeat_event'])) {
                        $this->eventRows[$r['__event']] = $r;
                    }
                }
                $this->buildPerson($personId, $recordId);
                $this->collectOccasions($personId, $rows);

                foreach ($rows as $row) {
                    // The visit key groups everything collected on the same
                    // occasion: the event and, if the whole event repeats, the
                    // specific instance.
                    $this->currentVisitKey = $this->visitKey($row);
                    $this->currentRowKey   = $recordId . '|' . $row['__event'] . '|'
                                           . ($row['__form'] ?? '') . '|' . ($row['__instance'] ?? '');

                    foreach ($approved as $field => $spec) {
                        // Checkbox fields do not exist as a column: REDCap
                        // expands them into field___option. Checking for the
                        // plain column would always skip them.
                        $isCheckbox = ($this->dictionary[$field]['field_type'] ?? '') === 'checkbox';
                        if (!$isCheckbox && !array_key_exists($field, $row)) {
                            continue;
                        }
                        $this->transformField($personId, $field, $spec, $row);
                    }
                }
                $this->currentVisitKey = null;
                $this->buildDeathFromConfig($personId);
            }
        }

        $this->purgeDroppedPersons();
        $this->buildVisits();
        $this->assignFactIds();
        $this->buildObservationPeriods();
        $this->buildEntities();
        $this->buildSourceToConceptMap($approved);
        $this->buildCdmSource();
        $this->registry->save();

        foreach ($this->tables as $t => $rows) {
            $this->stats['rows'] += count($rows);
        }

        return $this->result();
    }

    // --------------------------------------------------------------- Input

    /** Fields in the approved state, with their specification and profile. */
    private function approvedFields(): array
    {
        $out = [];
        foreach ($this->module->mappingRows() as $field => $row) {
            if ($row['state'] !== MappingStore::S_APPROVED) {
                continue;
            }
            $spec = $row['spec'];
            // Person-domain fields are consumed when building the PERSON
            // table, not as standalone facts. The module's two own codes are
            // not OMOP domains and never produce rows.
            if (empty($spec['domain'])
                || in_array($spec['domain'], [DictionaryProfiler::D_SKIP,
                                              DictionaryProfiler::D_DATE_ANCHOR,
                                              'Person'], true)) {
                continue;
            }

            // With automatic generation on, a field mapped to Visit would
            // produce rows mixed with the derived visits: the same
            // VISIT_OCCURRENCE with two different meanings and facts that
            // point to none of them. The field is dropped and explained.
            if ($spec['domain'] === 'Visit' && $this->visitConf['enabled']) {
                $this->skip('visit_domain_conflict', $field);
                continue;
            }
            $spec['_choices'] = $row['profile']['choices'] ?? [];
            $spec['_meta']    = $this->dictionary[$field] ?? [];
            $spec['_values']  = $this->store->values($field);
            $spec['_label']   = $row['profile']['field_label'] ?? $field;
            $spec['_kind']    = $row['profile']['value_kind'] ?? '';
            $spec['_unit_concept'] = $this->module->unitConcept($spec['unit_source_value'] ?? null);
            $out[$field] = $spec;
        }
        return $out;
    }

    /** Records processed at a time. A trade-off between memory and calls. */
    const BATCH_SIZE = 200;

    /** Common getData parameters, including the user's DAG. */
    private function dataParams(array $extra): array
    {
        $p = ['project_id' => $this->module->pid(), 'return_format' => 'array'] + $extra;
        // getData returns every DAG unless told otherwise: a user restricted
        // to their own site would get everyone else's records.
        if ($this->dagId !== null) {
            $p['groups'] = [$this->dagId];
        }
        return $p;
    }

    /**
     * Identifiers of the project's records.
     *
     * They are requested separately so records can be processed in batches:
     * loading a whole project into memory works with hundreds of records and
     * fails with tens of thousands.
     */
    private function recordIds(?int $limit): array
    {
        $ids = array_keys((array) \REDCap::getData($this->dataParams([
            'fields' => [$this->structure['record_id_field']],
        ])));
        return $limit === null ? $ids : array_slice($ids, 0, $limit);
    }

    /**
     * Reads a batch of records and returns, per record, its list of rows.
     *
     * Each row is an (event, repeat instance): that is the granularity of a
     * fact in REDCap and the one that determines which visit it belongs to.
     * In repeat_instances, an empty form name means the whole event repeats,
     * not an instrument within it.
     */
    private function readBatch(array $recordIds): array
    {
        $data = \REDCap::getData($this->dataParams(['records' => $recordIds]));

        $out = [];
        foreach ((array) $data as $recordId => $eventData) {
            $rows = [];
            foreach ((array) $eventData as $eventId => $payload) {
                if ($eventId === 'repeat_instances') {
                    foreach ((array) $payload as $evId => $forms) {
                        foreach ((array) $forms as $formName => $instances) {
                            foreach ((array) $instances as $instance => $values) {
                                if (!is_array($values)) {
                                    continue;
                                }
                                $values['__event']        = $evId;
                                $values['__instance']     = $instance;
                                $values['__form']         = $formName === '' ? null : $formName;
                                $values['__repeat_event'] = $formName === '';
                                $rows[] = $values;
                            }
                        }
                    }
                    continue;
                }
                if (is_array($payload)) {
                    $payload['__event']        = $eventId;
                    $payload['__instance']     = null;
                    $payload['__form']         = null;
                    $payload['__repeat_event'] = false;
                    $rows[] = $payload;
                }
            }
            $out[$recordId] = $rows;
        }
        return $out;
    }

    // -------------------------------------------------------------- PERSON

    /**
     * Concept of an option of a PERSON field (gender, race, ethnicity).
     *
     * @return array{0: int, 1: ?string} [concept_id, source label]
     */
    private function personConcept(?string $field, string $statKey): array
    {
        if (empty($field) || !isset($this->currentFlat[$field])) {
            return [CdmModel::CONCEPT_NO_MATCH, null];
        }
        $raw   = (string) $this->currentFlat[$field];
        $label = $this->profile[$field]['choices'][$raw] ?? $raw;
        if (!$this->module->isApproved($field)) {
            $this->stats[$statKey] = 1;
            return [CdmModel::CONCEPT_NO_MATCH, $label];
        }
        $values = $this->store->values($field);
        $id = (int) ($values[$raw]['concept_id'] ?? 0);
        return [$id > 0 ? $id : CdmModel::CONCEPT_NO_MATCH, $label];
    }

    /**
     * One PERSON row per record. Without PERSON there is no valid CDM, so it
     * is generated even when demographic data is missing: unknown concepts go
     * to 0, which is the CDM convention.
     */
    private function buildPerson(int $personId, string $recordId): void
    {
        if (isset($this->tables['person'][$personId])) {
            return;
        }

        $conf = $this->personConf;
        $flat = $this->currentFlat;

        $row = CdmModel::emptyRow('person');
        $row['person_id']           = $personId;
        $row['person_source_value'] = $this->module->hashPersonSourceValue($recordId);

        [$row['gender_concept_id'], $row['gender_source_value']]
            = $this->personConcept($conf['gender_field'], 'gender_not_approved');
        [$row['race_concept_id'], $row['race_source_value']]
            = $this->personConcept($conf['race_field'] ?? null, 'race_not_approved');
        [$row['ethnicity_concept_id'], $row['ethnicity_source_value']]
            = $this->personConcept($conf['ethnicity_field'] ?? null, 'ethnicity_not_approved');

        // Date of birth: the full date if there is one, otherwise the year on
        // its own. The full date is shifted like all the person's other dates,
        // so the age at each fact does not change.
        $birth = null;
        if (!empty($conf['birth_date_field']) && !empty($flat[$conf['birth_date_field']])) {
            $birth = $this->pseudo->shift($this->parseDate((string) $flat[$conf['birth_date_field']]), $recordId);
        }
        if ($birth !== null) {
            [$y, $m, $d] = explode('-', $birth);
            $row['year_of_birth']  = (int) $y;
            $row['month_of_birth'] = (int) $m;
            $row['day_of_birth']   = (int) $d;
            $row['birth_datetime'] = $birth . ' 00:00:00';
        } elseif (!empty($conf['birth_year_field']) && !empty($flat[$conf['birth_year_field']])) {
            $row['year_of_birth'] = (int) $flat[$conf['birth_year_field']];
        }
        $row = $this->pseudo->birth($row);

        $cs = $this->entityId('care_site', $this->entityConf['care_site_field'], $flat);
        $pr = $this->entityId('provider', $this->entityConf['provider_field'], $flat);
        $row['care_site_id'] = $cs;
        $row['provider_id']  = $pr;

        // year_of_birth is NOT NULL in the CDM and cannot be made up: a person
        // without a year of birth cannot be analysed (age is the universal
        // stratification variable). The whole person is dropped, with their
        // facts, and counted: dropping them silently would be worse.
        if (empty($row['year_of_birth'])) {
            $this->dropped[$personId] = $recordId;
            return;
        }

        $this->tables['person'][$personId] = $row;
    }

    // ------------------------------------------------ CARE_SITE and PROVIDER

    /** @var array<string,array<string,string>> kind => code => label used */
    private $entitiesUsed = ['care_site' => [], 'provider' => []];

    /**
     * Stable identifier of the care site or provider a field names.
     *
     * Choice fields only: the option code is the entity. A hand-typed
     * provider name never goes out.
     */
    private function entityId(string $kind, ?string $field, array $row): ?int
    {
        if ($field === null || !isset($row[$field]) || $row[$field] === '' || is_array($row[$field])) {
            return null;
        }
        if (!in_array($this->dictionary[$field]['field_type'] ?? '', ['radio', 'dropdown'], true)) {
            return null;
        }
        $code = (string) $row[$field];
        $this->entitiesUsed[$kind][$code] = $this->profile[$field]['choices'][$code] ?? $code;
        return $this->registry->id($kind, $field . '=' . $code);
    }

    private function buildEntities(): void
    {
        foreach ($this->entitiesUsed['care_site'] as $code => $label) {
            $code = (string) $code;   // numeric array keys come back as int
            $row = CdmModel::emptyRow('care_site');
            $row['care_site_id']           = $this->registry->id('care_site', $this->entityConf['care_site_field'] . '=' . $code);
            $row['care_site_name']         = $label;
            $row['care_site_source_value'] = $code;
            $this->tables['care_site'][] = $row;
        }
        foreach ($this->entitiesUsed['provider'] as $code => $label) {
            $code = (string) $code;
            $row = CdmModel::emptyRow('provider');
            $row['provider_id']           = $this->registry->id('provider', $this->entityConf['provider_field'] . '=' . $code);
            // provider_name left empty on purpose: the label may be a personal
            // name. The option code is enough to link.
            $row['provider_source_value'] = $code;
            $this->tables['provider'][] = $row;
        }
    }

    // ------------------------------------------------------- Clinical facts

    private function transformField(int $personId, string $field, array $spec, array $row): void
    {
        // Checkboxes arrive as field___1, field___2...
        if (($this->dictionary[$field]['field_type'] ?? '') === 'checkbox') {
            $this->transformCheckbox($personId, $field, $spec, $row);
            return;
        }

        $raw = $row[$field] ?? '';
        if ($raw === '' || $raw === null) {
            return;
        }

        $table = CdmModel::tableForDomain($spec['domain']);
        if ($table === null) {
            $this->skip('no_table', $spec['domain']);
            return;
        }

        $choices  = $spec['_choices'];
        $strategy = $spec['value_strategy'];
        // A binary field approved as a single concept would also emit a row
        // for the negative answer, which is exactly what must not happen. It
        // is always treated per option.
        if (!empty($choices) && !MappingStore::usesOptions($strategy)
            && isset(DictionaryProfiler::IMPLICIT_CHOICES[$this->dictionary[$field]['field_type'] ?? ''])) {
            $strategy = MappingStore::ST_PER_CHOICE;
        }

        if (!empty($choices) && MappingStore::usesOptions($strategy)) {
            $key = (string) $raw;
            $v = $this->optionSpec($spec, $key);
            if ($v === null) {
                $this->skip('choice_undecided', $field . '=' . $key);
                return;
            }
            // Before the date: a "No" that does not emit is not a row dropped
            // for lack of a date, it is data that does not exist.
            if (empty($v['emit'])) {
                $this->stats['not_emitted'] = ($this->stats['not_emitted'] ?? 0) + 1;
                return;
            }
            $date = $this->factDate($spec, $row, $field);
            if ($date === null) {
                $this->skip('no_date', $field);
                return;
            }
            $this->emitOption($table, $personId, $field, $spec, $strategy, $key, $v, $date, $row);
            return;
        }

        $date = $this->factDate($spec, $row, $field);
        if ($date === null) {
            $this->skip('no_date', $field);
            return;
        }

        // A single concept for the whole field.
        if (empty($spec['concept_id']) && $table !== 'death') {
            $this->skip('field_no_concept', $field);
            return;
        }

        $freeText = ($spec['_kind'] ?? '') === 'text';
        if ($table === 'note') {
            $this->emitNote($personId, $field, $spec, (string) $raw, $date);
            return;
        }

        if (!empty($choices)) {
            // Categorical with one concept for the whole field: the answer is
            // the label, not the code. Only on a rating scale ("0 - Sin dolor"
            // ... "10") is the number in the label the value.
            $label = (string) ($choices[(string) $raw] ?? $raw);
            $num = (DictionaryProfiler::looksLikeRatingScaleStatic($choices)
                    && preg_match('/^\s*(-?\d+(?:\.\d+)?)/', $label, $m)) ? (float) $m[1] : null;
            $ctx = ['source_value' => $spec['_label'], 'value_source' => $label,
                    'value_number' => $num, 'value_string' => $num === null ? $label : null];
        } elseif ($this->module->isDateField($field)) {
            // A date field mapped as a fact contributes the date, not a
            // value: "Fecha de diagnóstico" has no value_as_*, and in DEATH
            // the date is not the cause.
            $ctx = ['source_value' => $spec['_label'], 'value_source' => null,
                    'value_number' => null, 'value_string' => null];
        } else {
            [$num, $op] = $this->numericValue((string) $raw, $field, $table);
            $ctx = ['source_value' => $spec['_label'], 'value_source' => (string) $raw,
                    'value_number' => $num, 'operator' => $op,
                    'value_string' => $num === null ? (string) $raw : null];
            // Free text suppressed. If the text was a number, there is nothing
            // to remove: the number and its original are kept. Otherwise the
            // row is emitted without the text (the fact happened), except in
            // MEASUREMENT: a measurement with no value says nothing, so it is
            // dropped with its own reason.
            if ($freeText && $this->pseudo->suppressesFreeText() && $num === null) {
                if ($table === 'measurement') {
                    $this->skip('free_text_no_value', $field);
                    return;
                }
                $ctx['value_source'] = null;
                $ctx['value_string'] = null;
                $this->stats['free_text_suppressed'] = ($this->stats['free_text_suppressed'] ?? 0) + 1;
            }
        }
        $ctx['option'] = '';
        $this->emit($table, $personId, (int) $spec['concept_id'], $date, $spec, $ctx, $field, $row);
    }

    /** Decision about an option, with the default if it was never touched. */
    private function optionSpec(array $spec, string $key): ?array
    {
        if (isset($spec['_values'][$key])) {
            return $spec['_values'][$key];
        }
        return DictionaryProfiler::isNegativeChoice($spec['_meta'], $key)
            ? ['emit' => false, 'concept_id' => null, 'source_value' => $key]
            : null;
    }

    /**
     * Emits the row that corresponds to a chosen option.
     *
     *  - per_choice: the option IS the fact; its concept is the fact's concept.
     *  - value_concept: the fact is the question (the field's concept) and the
     *    option is its value (value_as_concept_id).
     */
    private function emitOption(string $table, int $personId, string $field, array $spec,
                                string $strategy, string $key, array $v, string $date, array $row): void
    {
        if (empty($v['emit'])) {
            $this->stats['not_emitted'] = ($this->stats['not_emitted'] ?? 0) + 1;
            return;
        }
        $label  = (string) ($spec['_choices'][$key] ?? $v['source_value'] ?? $key);
        $isDeath = $table === 'death';

        if ($strategy === MappingStore::ST_VALUE_CONCEPT) {
            if (empty($v['concept_id']) && !$isDeath) {
                $this->skip('choice_no_concept', $field . '=' . $key);
                return;
            }
            $this->emit($table, $personId, (int) $spec['concept_id'], $date, $spec, [
                'source_value'     => $spec['_label'],
                'value_source'     => $label,
                'value_concept_id' => (int) $v['concept_id'],
                'value_number'     => null,
                'value_string'     => null,
                'option'           => $key,
            ], $field, $row);
            return;
        }

        if (empty($v['concept_id']) && !$isDeath) {
            $this->skip('choice_no_concept', $field . '=' . $key);
            return;
        }
        // In a yes/no the "Yes" says nothing: the source is the question.
        $source = DictionaryProfiler::isBinaryLabel($label) ? $spec['_label'] : $label;
        $this->emit($table, $personId, (int) ($v['concept_id'] ?? 0), $date, $spec, [
            'source_value' => $source,
            'value_source' => $label,
            'value_number' => null,
            'value_string' => null,
            'option'       => $key,
        ], $field, $row);
    }

    private function transformCheckbox(int $personId, string $field, array $spec, array $row): void
    {
        $table = CdmModel::tableForDomain($spec['domain']);
        if ($table === null) {
            return;
        }

        // Which boxes are checked. This is resolved BEFORE requiring a date: a
        // row with none checked is not discarded data, it is data that does
        // not exist, and counting it as a skip fills the report with noise.
        $checked = [];
        foreach ($spec['_choices'] as $rawValue => $label) {
            if ($this->isCheckboxChecked($row, $field, (string) $rawValue)) {
                $checked[(string) $rawValue] = $label;
            }
        }
        if (empty($checked)) {
            return;
        }

        $strategy = $spec['value_strategy'] === MappingStore::ST_VALUE_CONCEPT
            ? MappingStore::ST_VALUE_CONCEPT : MappingStore::ST_PER_CHOICE;

        $date = null;
        foreach ($checked as $rawValue => $label) {
            $v = $spec['_values'][(string) $rawValue] ?? null;
            if ($v === null) {
                $this->skip('choice_undecided', $field . '=' . $rawValue);
                continue;
            }
            // The decision not to emit comes first: an option such as
            // "Ninguna", checked on purpose so no row is generated, is not a
            // skip.
            if (empty($v['emit'])) {
                $this->stats['not_emitted'] = ($this->stats['not_emitted'] ?? 0) + 1;
                continue;
            }
            $date = $date ?? $this->factDate($spec, $row, $field);
            if ($date === null) {
                $this->skip('no_date', $field);
                return;
            }
            $this->emitOption($table, $personId, $field, $spec, $strategy, (string) $rawValue, $v, $date, $row);
        }
    }

    /**
     * Is a specific checkbox option checked?
     *
     * REDCap returns checkboxes in two shapes depending on the context. With
     * return_format=array, which is what this module uses, they arrive as a
     * nested array: $row['comorbilidades']['3'] = '1'. The underscore format
     * (comorbilidades___3) is the one used by the CSV export, not by the API.
     *
     * Both are accepted because the exact structure has varied between REDCap
     * versions and because assuming only one already caused checkboxes to be
     * lost entirely, and silently.
     */
    private function isCheckboxChecked(array $row, string $field, string $rawValue): bool
    {
        $isChecked = function ($v) {
            return $v === '1' || $v === 1 || $v === true;
        };

        // API format: a nested array under the field name.
        if (isset($row[$field]) && is_array($row[$field])) {
            return isset($row[$field][$rawValue]) && $isChecked($row[$field][$rawValue]);
        }

        // Export format: one column per option.
        $key = $field . '___' . preg_replace('/[^a-z0-9_]/i', '', $rawValue);
        return isset($row[$key]) && $isChecked($row[$key]);
    }

    /**
     * End date of the fact, if the field declares one and it is consistent.
     * Already shifted, like the start date.
     */
    private function endDate(array $spec, array $row, string $start): ?string
    {
        if (empty($spec['end_date_field'])) {
            return null;
        }
        $v = $this->dateInOccasion($row, $spec['end_date_field']);
        $end = $v !== null ? $this->pseudo->shift($this->parseDate($v), $this->currentRecord) : null;
        if ($end !== null && $end < $start) {
            // An end date before the start is a data entry error: nothing is
            // made up, the fact is left without an end and it is counted.
            $this->stats['end_before_start'] = ($this->stats['end_before_start'] ?? 0) + 1;
            return null;
        }
        return $end;
    }

    /**
     * Builds the row of the corresponding domain. Each CDM table has its own
     * required columns, hence the switch.
     *
     * $ctx: source_value, value_source, value_number, value_string and,
     * optionally, value_concept_id, operator and option.
     */
    private function emit(string $table, int $personId, int $conceptId, string $date,
                          array $spec, array $ctx, string $field, array $sourceRow): void
    {
        if ($table === 'death') {
            $this->emitDeath($personId, $conceptId, $date, $ctx);
            return;
        }

        $row = CdmModel::emptyRow($table);
        $dt  = $date . ' 00:00:00';
        $end = $this->endDate($spec, $sourceRow, $date);

        // Fact key: an identifier that does not change between exports is
        // derived from it (IdRegistry::assignFactIds).
        $row['__key'] = $table . '|' . $this->currentRowKey . '|' . $field . '|' . ($ctx['option'] ?? '');
        $row['person_id'] = $personId;
        $this->trackDate($personId, $date);
        // The observation period also covers the end of each fact: a
        // treatment that ends after the last start date was still being
        // observed until that day.
        $this->trackDate($personId, $end);
        $this->trackVisit($date);
        // Provisional key: buildVisits() replaces it with the final id.
        if (array_key_exists('visit_occurrence_id', $row)) {
            $row['visit_occurrence_id'] = $this->currentVisitKey;
        }
        if (array_key_exists('provider_id', $row)) {
            $row['provider_id'] = $this->occasions[$this->currentVisitKey]['provider'] ?? null;
        }
        $valueConcept = !empty($ctx['value_concept_id']) ? (int) $ctx['value_concept_id'] : null;
        $unitConcept  = $ctx['value_number'] !== null ? ($spec['_unit_concept'] ?? null) : null;

        switch ($table) {
            case 'condition_occurrence':
                $row['condition_concept_id']     = $conceptId;
                $row['condition_start_date']     = $date;
                $row['condition_start_datetime'] = $dt;
                $row['condition_end_date']       = $end;
                $row['condition_end_datetime']   = $end !== null ? $end . ' 00:00:00' : null;
                $row['condition_type_concept_id'] = self::TYPE_EHR_FORM;
                $row['condition_source_value']   = $ctx['source_value'];
                break;

            case 'drug_exposure':
                $row['drug_concept_id']              = $conceptId;
                $row['drug_exposure_start_date']     = $date;
                $row['drug_exposure_start_datetime'] = $dt;
                // The CDM requires an end date; without explicit data it is set to the start.
                $row['drug_exposure_end_date']       = $end ?? $date;
                $row['drug_exposure_end_datetime']   = ($end ?? $date) . ' 00:00:00';
                $row['verbatim_end_date']            = $end;
                $row['drug_type_concept_id']         = self::TYPE_EHR_FORM;
                $row['drug_source_value']            = $ctx['source_value'];
                break;

            case 'procedure_occurrence':
                $row['procedure_concept_id']     = $conceptId;
                $row['procedure_date']           = $date;
                $row['procedure_datetime']       = $dt;
                $row['procedure_end_date']       = $end;
                $row['procedure_end_datetime']   = $end !== null ? $end . ' 00:00:00' : null;
                $row['procedure_type_concept_id'] = self::TYPE_EHR_FORM;
                $row['procedure_source_value']   = $ctx['source_value'];
                break;

            case 'measurement':
                $row['measurement_concept_id']      = $conceptId;
                $row['measurement_date']            = $date;
                $row['measurement_datetime']        = $dt;
                $row['measurement_type_concept_id'] = self::TYPE_EHR_FORM;
                $row['measurement_source_value']    = $ctx['source_value'];
                $row['value_source_value']          = $ctx['value_source'];
                $row['value_as_number']             = $ctx['value_number'];
                $row['value_as_concept_id']         = $valueConcept;
                $row['operator_concept_id']         = $ctx['operator'] ?? null;
                if (!empty($spec['unit_source_value']) && $ctx['value_number'] !== null) {
                    $row['unit_source_value'] = $spec['unit_source_value'];
                    $row['unit_concept_id']   = $unitConcept;
                }
                break;

            case 'observation':
                $row['observation_concept_id']      = $conceptId;
                $row['observation_date']            = $date;
                $row['observation_datetime']        = $dt;
                $row['observation_type_concept_id'] = self::TYPE_EHR_FORM;
                $row['observation_source_value']    = $ctx['source_value'];
                $row['value_source_value']          = $ctx['value_source'];
                $row['value_as_number']             = $ctx['value_number'];
                $row['value_as_concept_id']         = $valueConcept;
                if ($ctx['value_string'] !== null && $ctx['value_string'] !== '') {
                    $row['value_as_string'] = $ctx['value_string'];
                }
                if (!empty($spec['unit_source_value']) && $ctx['value_number'] !== null) {
                    $row['unit_source_value'] = $spec['unit_source_value'];
                    $row['unit_concept_id']   = $unitConcept;
                }
                break;

            case 'visit_occurrence':
                // Only with automatic generation turned off (otherwise the
                // field is dropped in approvedFields). The id comes from the
                // registry, like that of the automatic visits.
                unset($row['__key']);
                $row['visit_occurrence_id']   = $this->registry->id('visit_field',
                    $this->currentRowKey . '|' . $field);
                $row['visit_concept_id']      = $conceptId;
                $row['visit_start_date']      = $date;
                $row['visit_start_datetime']  = $dt;
                $row['visit_end_date']        = $end ?? $date;
                $row['visit_end_datetime']    = ($end ?? $date) . ' 00:00:00';
                $row['visit_type_concept_id'] = self::TYPE_EHR_FORM;
                $row['visit_source_value']    = $ctx['source_value'];
                break;

            case 'device_exposure':
                $row['device_concept_id']              = $conceptId;
                $row['device_exposure_start_date']     = $date;
                $row['device_exposure_start_datetime'] = $dt;
                $row['device_exposure_end_date']       = $end;
                $row['device_exposure_end_datetime']   = $end !== null ? $end . ' 00:00:00' : null;
                $row['device_type_concept_id']         = self::TYPE_EHR_FORM;
                $row['device_source_value']            = $ctx['source_value'];
                break;

            case 'specimen':
                $row['specimen_concept_id']      = $conceptId;
                $row['specimen_type_concept_id'] = self::TYPE_EHR_FORM;
                $row['specimen_date']            = $date;
                $row['specimen_datetime']        = $dt;
                $row['specimen_source_value']    = $ctx['source_value'];
                break;

            default:
                $this->skip('not_implemented', $table);
                return;
        }

        $this->tables[$table][] = $row;
    }

    /**
     * Free text as NOTE: the full text, untrimmed (note_text is TEXT). The
     * field's concept is the note class.
     */
    private function emitNote(int $personId, string $field, array $spec, string $text, string $date): void
    {
        if ($this->pseudo->suppressesFreeText()) {
            $this->stats['notes_suppressed'] = ($this->stats['notes_suppressed'] ?? 0) + 1;
            return;
        }
        $row = CdmModel::emptyRow('note');
        $row['__key']                 = 'note|' . $this->currentRowKey . '|' . $field . '|';
        $row['person_id']             = $personId;
        $row['note_date']             = $date;
        $row['note_datetime']         = $date . ' 00:00:00';
        $row['note_type_concept_id']  = self::TYPE_EHR_FORM;
        $row['note_class_concept_id'] = (int) $spec['concept_id'];
        $row['note_title']            = $spec['_label'];
        $row['note_text']             = $text;
        $row['encoding_concept_id']   = self::ENCODING_UTF8;
        $row['language_concept_id']   = $this->module->noteLanguageConcept();
        $row['note_source_value']     = $field;
        $row['visit_occurrence_id']   = $this->currentVisitKey;
        $row['provider_id']           = $this->occasions[$this->currentVisitKey]['provider'] ?? null;
        $this->trackDate($personId, $date);
        $this->trackVisit($date);
        $this->tables['note'][] = $row;
    }

    // ---------------------------------------------------------------- DEATH

    /**
     * Date of death of the current person, already shifted.
     *
     * If the project declares a date-of-death field, that is the date, full
     * stop. The first date in the form of the yes/no field will not do: it is
     * usually the date the case was closed, not the date of death.
     */
    private function deathDate(): ?string
    {
        $f = $this->deathConf['date_field'] ?? null;
        if (!empty($f) && !empty($this->currentFlat[$f])) {
            return $this->pseudo->shift($this->parseDate((string) $this->currentFlat[$f]), $this->currentRecord);
        }
        return null;
    }

    /**
     * One DEATH row per person.
     *
     * The concept that arrives here is the CAUSE of death (cause_concept_id),
     * not the fact of dying: the row already says the person died. An
     * "Exitus = Sí" is approved without a concept and leaves the cause empty.
     */
    private function emitDeath(int $personId, int $conceptId, string $date, array $ctx): void
    {
        $date = $this->deathDate() ?? $date;
        $row  = $this->tables['death'][$personId] ?? null;
        if ($row === null) {
            $row = CdmModel::emptyRow('death');
            $row['person_id']             = $personId;
            $row['death_date']            = $date;
            $row['death_datetime']        = $date . ' 00:00:00';
            $row['death_type_concept_id'] = self::TYPE_EHR_FORM;
            $this->trackDate($personId, $date);
        }
        if ($conceptId > 0 && empty($row['cause_concept_id'])) {
            $row['cause_concept_id'] = $conceptId;
            // The label goes with the cause only if it names one: the "Yes"
            // of an "Exitus sí/no" is not a cause of death.
            $label = $ctx['value_source'] ?? $ctx['source_value'];
            $row['cause_source_value'] = ($label !== null && !DictionaryProfiler::isBinaryLabel((string) $label))
                ? $label : null;
        } elseif (empty($row['cause_source_value']) && ($ctx['value_string'] ?? null) !== null
                  && !DictionaryProfiler::isBinaryLabel((string) $ctx['value_string'])) {
            // Free-text cause: kept as the source value, without a concept.
            $row['cause_source_value'] = $ctx['value_string'];
        }
        $this->tables['death'][$personId] = $row;
    }

    /**
     * DEATH from the configuration, like PERSON: if the declared date-of-death
     * field has a value, the person died on that date.
     */
    private function buildDeathFromConfig(int $personId): void
    {
        $date = $this->deathDate();
        if ($date === null || isset($this->tables['death'][$personId])) {
            return;
        }
        $this->emitDeath($personId, 0, $date, ['source_value' => null]);
    }

    /**
     * Removes the facts of the persons that did not make it into PERSON.
     *
     * Without this there would be rows pointing to a non-existent person_id,
     * which is a referential integrity error and makes the load fail.
     */
    private function purgeDroppedPersons(): void
    {
        if (empty($this->dropped)) {
            return;
        }
        $ids = array_flip(array_keys($this->dropped));
        $removed = 0;

        foreach ($this->tables as $table => &$rows) {
            if ($table === 'cdm_source' || empty($rows)) {
                continue;
            }
            $before = count($rows);
            $rows = array_filter($rows, function ($r) use ($ids) {
                return !isset($r['person_id']) || !isset($ids[$r['person_id']]);
            });
            if ($table !== 'person' && $table !== 'death') {
                $rows = array_values($rows);
            }
            $removed += $before - count($rows);
        }
        unset($rows);

        // The accumulated visits are filtered too, before they are built.
        foreach (array_keys($this->visits) as $key) {
            if (isset($ids[$this->visits[$key]['person_id']])) {
                unset($this->visits[$key]);
            }
        }
        foreach (array_keys($ids) as $pid) {
            unset($this->personDates[$pid]);
        }

        $this->stats['persons_dropped']      = count($this->dropped);
        $this->stats['rows_dropped_with']    = $removed;
        $this->stats['dropped_record_ids']   = implode(', ', array_slice($this->dropped, 0, 20));
    }

    // -------------------------------------------------------------- VISITS

    /**
     * Identifies the occasion on which a row was collected.
     *
     * The natural unit is the event. A repeating instrument WITHIN an event
     * (the medication of the baseline visit) belongs to that event's visit;
     * only when the whole event repeats is each instance a separate occasion.
     */
    private function visitKey(array $row): string
    {
        $instance = !empty($row['__repeat_event']) ? ($row['__instance'] ?? '0') : '0';
        return $this->currentRecord . '|' . ($row['__event'] ?? '0') . '|' . $instance;
    }

    /**
     * Data of each occasion: its date (the project's default date in the
     * event's main row), its care site and its provider.
     */
    private function collectOccasions(int $personId, array $rows): void
    {
        foreach ($rows as $row) {
            if ($row['__form'] !== null) {
                continue;   // repeating instrument: does not define the occasion
            }
            $key = $this->visitKey($row);
            $anchor = null;
            if ($this->defaultDate !== null && !empty($row[$this->defaultDate])) {
                $anchor = $this->pseudo->shift($this->parseDate((string) $row[$this->defaultDate]), $this->currentRecord);
            }
            $this->occasions[$key] = [
                'person_id' => $personId,
                'anchor'    => $anchor,
                'care_site' => $this->entityId('care_site', $this->entityConf['care_site_field'], $row),
                'provider'  => $this->entityId('provider', $this->entityConf['provider_field'], $row),
            ];
        }
    }

    /**
     * Accumulates the date range of a visit as facts are emitted.
     *
     * With an occasion date, the visit starts on that day and is extended by
     * later facts of the same occasion (the lab work drawn the next day).
     * EARLIER facts (a diagnosis date from months ago recorded at the baseline
     * visit) do not stretch it backwards: they did not happen during that
     * visit.
     */
    private function trackVisit(?string $date): void
    {
        if ($this->currentVisitKey === null || $date === null || !$this->visitConf['enabled']) {
            return;
        }
        $key    = $this->currentVisitKey;
        $anchor = $this->occasions[$key]['anchor'] ?? null;
        if (!isset($this->visits[$key])) {
            [, $event, $instance] = explode('|', $key);
            $this->visits[$key] = [
                'person_id' => $this->occasions[$key]['person_id'] ?? null,
                'event'     => $event,
                'instance'  => $instance !== '0' ? $instance : null,
                'min'       => $anchor ?? $date,
                'max'       => $anchor !== null ? max($anchor, $date) : $date,
            ];
            return;
        }
        if ($anchor === null && $date < $this->visits[$key]['min']) {
            $this->visits[$key]['min'] = $date;
        }
        if ($date > $this->visits[$key]['max']) {
            $this->visits[$key]['max'] = $date;
        }
    }

    /**
     * Builds VISIT_OCCURRENCE and replaces the provisional key in the facts
     * with the final identifier.
     *
     * It is done at the end because a visit's date range is not known until
     * all its facts have been emitted. A fact is linked only if its date falls
     * within the visit: DQD checks this, and a fact outside its visit claims
     * it happened during a visit in which it did not.
     */
    private function buildVisits(): void
    {
        if (!$this->visitConf['enabled'] || empty($this->visits)) {
            $this->stripVisitKeys();
            return;
        }

        $names = $this->structure['events'] ?? [];
        $visitIds = [];
        $ranges   = [];

        foreach ($this->visits as $key => $v) {
            if ($v['person_id'] === null) {
                continue;
            }
            $id = $this->registry->id('visit', $key);
            $visitIds[$key] = $id;
            $ranges[$key]   = [$v['min'], $v['max']];

            // With '??' an empty string would pass as valid and the visit
            // would come out without a source; '?:' also covers that case.
            $source = ($names[$v['event']]['unique_name'] ?? '') ?: ('event_' . $v['event']);
            if ($v['instance'] !== null) {
                $source .= '#' . $v['instance'];
            }

            $row = CdmModel::emptyRow('visit_occurrence');
            $row['visit_occurrence_id']   = $id;
            $row['person_id']             = $v['person_id'];
            $row['visit_concept_id']      = $this->visitConf['concept_id'];
            $row['visit_start_date']      = $v['min'];
            $row['visit_start_datetime']  = $v['min'] . ' 00:00:00';
            $row['visit_end_date']        = $v['max'];
            $row['visit_end_datetime']    = $v['max'] . ' 00:00:00';
            $row['visit_type_concept_id'] = self::TYPE_EHR_FORM;
            $row['care_site_id']          = $this->occasions[$key]['care_site'] ?? null;
            $row['provider_id']           = $this->occasions[$key]['provider'] ?? null;
            $row['visit_source_value']    = $source;
            $this->tables['visit_occurrence'][] = $row;
        }

        // The facts carry the provisional key: it is resolved now.
        $outside = 0;
        foreach ($this->tables as $table => &$rows) {
            if ($table === 'visit_occurrence' || !isset(self::DATE_COLUMN[$table])) {
                continue;
            }
            $col = self::DATE_COLUMN[$table];
            foreach ($rows as &$row) {
                if (!array_key_exists('visit_occurrence_id', $row)) {
                    continue;
                }
                $k = $row['visit_occurrence_id'];
                $row['visit_occurrence_id'] = null;
                if ($k === null || !isset($visitIds[$k])) {
                    continue;
                }
                [$start, $end] = $ranges[$k];
                if ($row[$col] >= $start && $row[$col] <= $end) {
                    $row['visit_occurrence_id'] = $visitIds[$k];
                } else {
                    $outside++;
                }
            }
            unset($row);
        }
        unset($rows);

        $this->stats['visits'] = count($visitIds);
        if ($outside > 0) {
            $this->stats['facts_outside_visit'] = $outside;
        }
    }

    /** With visits disabled, the provisional keys must not reach the CSV. */
    private function stripVisitKeys(): void
    {
        foreach ($this->tables as $table => &$rows) {
            if ($table === 'visit_occurrence') {
                continue;
            }
            foreach ($rows as &$row) {
                if (array_key_exists('visit_occurrence_id', $row)) {
                    $row['visit_occurrence_id'] = null;
                }
            }
            unset($row);
        }
        unset($rows);
    }

    /** Stable identifiers for the facts, derived from their key. */
    private function assignFactIds(): void
    {
        foreach (self::ID_COLUMN as $table => $col) {
            if (!empty($this->tables[$table])) {
                IdRegistry::assignFactIds($this->tables[$table], $col);
            }
        }
    }

    // ---------------------------------------------- OBSERVATION_PERIOD

    private function trackDate(int $personId, ?string $date): void
    {
        if ($date === null) {
            return;
        }
        if (!isset($this->personDates[$personId])) {
            $this->personDates[$personId] = ['min' => $date, 'max' => $date];
            return;
        }
        if ($date < $this->personDates[$personId]['min']) {
            $this->personDates[$personId]['min'] = $date;
        }
        if ($date > $this->personDates[$personId]['max']) {
            $this->personDates[$personId]['max'] = $date;
        }
    }

    /**
     * One period per person, derived from the first and last dates seen.
     * Without OBSERVATION_PERIOD the OHDSI tools cannot compute anything: it
     * delimits when each subject was under observation. Its identifier is the
     * person's: there is one per person, and that keeps it stable.
     */
    private function buildObservationPeriods(): void
    {
        foreach ($this->personDates as $personId => $range) {
            if (!isset($this->tables['person'][$personId])) {
                continue;
            }
            $row = CdmModel::emptyRow('observation_period');
            $row['observation_period_id']         = $personId;
            $row['person_id']                     = $personId;
            $row['observation_period_start_date'] = $range['min'];
            $row['observation_period_end_date']   = $range['max'];
            $row['period_type_concept_id']        = self::TYPE_OBS_PERIOD;
            $this->tables['observation_period'][] = $row;
        }

        // A person without any date cannot have a period, and without a
        // period the OHDSI tools ignore them. It is counted as a problem.
        $orphans = count($this->tables['person']) - count($this->tables['observation_period']);
        if ($orphans > 0) {
            $this->stats['persons_without_period'] = $orphans;
        }

        // PERSON and DEATH have been kept indexed by person_id; they go to the
        // CSV as a list.
        $this->tables['person'] = array_values($this->tables['person']);
        $this->tables['death']  = array_values($this->tables['death']);
    }

    /**
     * SOURCE_TO_CONCEPT_MAP: the approved mapping in the standard OHDSI
     * format, so that ATLAS, Usagi or the next ETL can reuse it.
     *
     * target_vocabulary_id is required and only the vocabulary knows it:
     * without one the table is not generated (the mapping is still in
     * MAPEO.json and in the Usagi file), because a made-up vocabulary would
     * break the load.
     */
    private function buildSourceToConceptMap(array $approved): void
    {
        if ($this->module->vocabulary() === null) {
            return;
        }
        $srcVocab = substr('REDCAP_P' . $this->module->pid(), 0, 20);
        $add = function (string $code, string $desc, int $concept, string $since) use ($srcVocab) {
            $c = $this->module->conceptInfo($concept);
            if (!is_array($c)) {
                $this->stats['stcm_unknown_concept'] = ($this->stats['stcm_unknown_concept'] ?? 0) + 1;
                return;
            }
            $row = CdmModel::emptyRow('source_to_concept_map');
            $row['source_code']             = $code;
            $row['source_concept_id']       = 0;
            $row['source_vocabulary_id']    = $srcVocab;
            $row['source_code_description'] = $desc;
            $row['target_concept_id']       = $concept;
            $row['target_vocabulary_id']    = $c['vocabulary'];
            $row['valid_start_date']        = $since;
            $row['valid_end_date']          = '2099-12-31';
            $this->tables['source_to_concept_map'][] = $row;
        };
        foreach ($approved as $field => $spec) {
            $since = substr((string) ($spec['approved_at'] ?? date('Y-m-d')), 0, 10);
            if (!empty($spec['concept_id'])) {
                $add($field, $spec['_label'], (int) $spec['concept_id'], $since);
            }
            if (MappingStore::usesOptions($spec['value_strategy'] ?? null) || ($spec['_meta']['field_type'] ?? '') === 'checkbox') {
                foreach ($spec['_values'] as $raw => $v) {
                    if (!empty($v['emit']) && !empty($v['concept_id'])) {
                        $add($field . '=' . $raw, $spec['_label'] . ': ' . ($spec['_choices'][$raw] ?? $raw),
                             (int) $v['concept_id'], $since);
                    }
                }
            }
        }
    }

    private function buildCdmSource(): void
    {
        $conf = $this->module->cdmSourceConfig();
        $row = CdmModel::emptyRow('cdm_source');
        $row['cdm_source_name']         = $conf['name'];
        // Not trimmed here: the exporter trims to the DDL limit and notes it
        // in the report. Trimming earlier hid the warning.
        $row['cdm_source_abbreviation'] = $conf['abbreviation'];
        $row['cdm_holder']              = $conf['holder'];
        $row['source_description']      = $this->module->ttText('cdm_source_description', $this->module->pid());
        $row['cdm_etl_reference']       = 'OMOP-CDM Export ' . $this->module->getModuleVersion();
        $row['source_release_date']     = date('Y-m-d');
        $row['cdm_release_date']        = date('Y-m-d');
        $row['cdm_version']             = 'v5.4';
        $row['cdm_version_concept_id']  = 756265;
        $row['vocabulary_version']      = $conf['vocabulary_version'];
        $this->tables['cdm_source'][] = $row;
    }

    // ------------------------------------------------------------ Utilities

    /**
     * Date of the fact, already shifted if pseudonymization asks for it. No
     * date means no row, so this function decides what is exported and what
     * is discarded.
     *
     * A date field mapped to a clinical domain is dated with its own value:
     * "Fecha de diagnóstico" IS the date of the diagnosis.
     */
    private function factDate(array $spec, array $row, string $field): ?string
    {
        return $this->pseudo->shift($this->realFactDate($spec, $row, $field), $this->currentRecord);
    }

    private function realFactDate(array $spec, array $row, string $field): ?string
    {
        if ($spec['domain'] === 'Death') {
            $f = $this->deathConf['date_field'] ?? null;
            if (!empty($f) && !empty($this->currentFlat[$f])) {
                return $this->parseDate((string) $this->currentFlat[$f]);
            }
        }
        if ($this->module->isDateField($field) && empty($spec['date_field']) && !empty($row[$field])) {
            return $this->parseDate((string) $row[$field]);
        }
        foreach (array_filter([$spec['date_field'] ?? null, $this->defaultDate]) as $df) {
            $v = $this->dateInOccasion($row, $df);
            if ($v !== null) {
                return $this->parseDate($v);
            }
        }
        return null;
    }

    /**
     * Value of a date field on the same occasion as the row.
     *
     * An instance of a repeating instrument only carries that instrument's
     * fields. If its reference date is the visit's, it is looked up in the
     * main row of the SAME event; never in another event, which would date
     * the month-2 medication with the baseline visit.
     */
    private function dateInOccasion(array $row, string $dateField): ?string
    {
        if (!empty($row[$dateField])) {
            return (string) $row[$dateField];
        }
        if ($row['__form'] !== null) {
            $main = $this->eventRows[$row['__event']] ?? null;
            if ($main !== null && !empty($main[$dateField])) {
                return (string) $main[$dateField];
            }
        }
        return null;
    }

    /**
     * Numeric value of an answer, and its operator if it has one.
     *
     * With a REDCap numeric validation the format is guaranteed (decimal
     * point), so it is converted. In free text only what cannot be read two
     * ways is accepted: "95" or "95.5" yes; "1.234" no, because it is one
     * thousand two hundred and thirty-four in Spanish and one point two in
     * English; "95,5" no either.
     *
     * "< 50" is not turned into 50: in MEASUREMENT it is stored as operator
     * "<" (operator_concept_id) and value 50, which is exactly what it says.
     * The original value always remains in value_source_value.
     *
     * @return array{0: ?float, 1: ?int} [value, operator_concept_id]
     */
    private function numericValue(string $raw, string $field, string $table): array
    {
        $text = trim($raw);
        if ($text === '') {
            return [null, null];
        }
        $meta = $this->dictionary[$field] ?? [];
        $validation = $meta['text_validation_type_or_show_slider_number'] ?? '';
        $validated = in_array($validation, self::NUMERIC_VALIDATIONS, true)
            || in_array($meta['field_type'] ?? '', ['slider', 'calc'], true);

        if ($validated && is_numeric($text)) {
            return [(float) $text, null];
        }
        $unambiguous = fn($n) => preg_match('/^-?\d+(\.\d+)?$/', $n) && !preg_match('/^-?\d{1,3}\.\d{3}$/', $n);
        if (!$validated && $unambiguous($text)) {
            return [(float) $text, null];
        }
        if ($table === 'measurement'
            && preg_match('/^(<=|>=|<|>|=)\s*(\S+)$/', $text, $m) && $unambiguous($m[2])) {
            $this->stats['with_operator'] = ($this->stats['with_operator'] ?? 0) + 1;
            return [(float) $m[2], CdmModel::OPERATORS[$m[1]]];
        }
        // It only counts as a problem where a number was expected.
        if ($table === 'measurement') {
            $statKey = preg_match('/\d{1,3}\.\d{3}/', $text) ? 'ambiguous_number' : 'not_numeric';
            $this->stats[$statKey] = ($this->stats[$statKey] ?? 0) + 1;
        }
        return [null, null];
    }

    /** Accepts the formats REDCap may return and normalises them to Y-m-d. */
    private function parseDate(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $value, $m)) {
            return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? "$m[1]-$m[2]-$m[3]" : null;
        }
        return null;
    }

    /** Flattens a record's rows to read data that does not repeat. */
    private function flatten(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            foreach ($row as $k => $v) {
                if (($v !== '' && $v !== null) && !isset($out[$k])) {
                    $out[$k] = $v;
                }
            }
        }
        return $out;
    }

    private function skip(string $code, string $arg = ''): void
    {
        $key = $arg === '' ? $code : $code . '|' . $arg;
        $this->skipped[$key] = ($this->skipped[$key] ?? 0) + 1;
    }

    private function result(array $extra = []): array
    {
        $counts = [];
        foreach ($this->tables as $t => $rows) {
            if (!empty($rows)) {
                $counts[$t] = count($rows);
            }
        }
        arsort($this->skipped);

        return array_merge([
            'tables'   => $this->tables,
            'counts'   => $counts,
            'stats'    => $this->stats,
            'skipped'  => $this->skipped,
            'missing'  => CdmModel::missingRequired($this->tables),
        ], $extra);
    }
}
