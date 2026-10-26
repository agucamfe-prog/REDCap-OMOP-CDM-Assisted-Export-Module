<?php

namespace OmopCdm\OmopCdmExportModule;

use ExternalModules\AbstractExternalModule;

require_once __DIR__ . '/classes/CdmModel.php';
require_once __DIR__ . '/classes/TextNormalizer.php';
require_once __DIR__ . '/classes/DictionaryProfiler.php';
require_once __DIR__ . '/classes/MappingStore.php';
require_once __DIR__ . '/classes/MappingAdvisor.php';
require_once __DIR__ . '/classes/MappingDocument.php';
require_once __DIR__ . '/classes/Transformer.php';
require_once __DIR__ . '/classes/CsvExporter.php';
require_once __DIR__ . '/classes/IdRegistry.php';
require_once __DIR__ . '/classes/Pseudonymizer.php';
require_once __DIR__ . '/classes/Vocabulary.php';
require_once __DIR__ . '/classes/MappingAnnotation.php';

/**
 * OMOP-CDM Export — main class.
 *
 * Phase 1: extraction layer (metadata + project structure) and heuristic
 * profiling of the data dictionary to propose an OMOP domain for each field.
 *
 * Nothing in here writes to the REDCap project: the module is read-only with
 * respect to the clinical data.
 */
class OmopCdmExportModule extends AbstractExternalModule
{
    /** @var MappingStore|null */
    private $store = null;

    /** @var array|null Dictionary cache for the current request. */
    private $dictionaryCache = null;

    /**
     * Caches for the current request.
     *
     * Without them, rendering the editor is quadratic: every row calls
     * advise(), advise() rebuilds mappingRows(), and mappingRows() classifies
     * the whole project again. With 200 fields that is 40,000 classifications
     * to render one table. They are invalidated when the mapping changes
     * within the same request, which is what happens when saving and then
     * re-evaluating warnings.
     */
    private $profileCache = null;
    private $mappingRowsCache = null;
    private $adviceCache = [];
    private $structureCache = null;

    /**
     * Project settings already read in this request.
     *
     * The framework does not cache getProjectSetting(): every call is a query,
     * and two if the setting does not exist (it also looks up the system
     * setting). The transformation engine asks for the default date and for
     * the visits on every fact it emits, so without a cache an export of
     * 15,000 rows fired some 60,000 queries and took minutes.
     */
    private $settingCache = [];

    /**
     * Installing the module creates no table and downloads nothing. The
     * mapping lives in the project settings, which REDCap already manages, and
     * concepts are looked up on athena.ohdsi.org from the editor itself.
     */

    // ----------------------------------------------------------------- Context

    public function pid(): int
    {
        return (int) $this->getProjectId();
    }

    /** Project setting, read only once per request. */
    public function setting(string $key)
    {
        if (!array_key_exists($key, $this->settingCache)) {
            $this->settingCache[$key] = $this->getProjectSetting($key);
        }
        return $this->settingCache[$key];
    }

    /** Writes a setting and keeps the cache consistent. */
    public function writeSetting(string $key, $value): void
    {
        $this->setProjectSetting($key, $value);
        $this->settingCache[$key] = $value;
    }

    /**
     * Translation as plain text.
     *
     * tt() escapes the interpolated values, and some texts already arrive with
     * entities. If that output is escaped again when rendered, «Athena&#039;s»
     * shows up on screen, and in a JavaScript alert() the entities are shown
     * verbatim. We always work with plain text and escape exactly once, where
     * it is rendered.
     */
    public function ttText(string $key, ...$args): string
    {
        // The .ini files store line breaks as a literal «\n»; in a JavaScript
        // confirm() they were shown verbatim.
        return str_replace('\n', "\n",
            html_entity_decode($this->tt($key, ...$args), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /** Domains the editor accepts. Any other value is rejected. */
    public static function validDomains(): array
    {
        return CdmModel::validDomains();
    }

    // -------------------------------------------------------------- Vocabulary

    /** @var Vocabulary|null|false */
    private $vocabulary = false;

    /**
     * Vocabulary configured by the administrator, or null.
     *
     * It is a system setting: vocabularies belong to the server, not to each
     * project, and the local index takes gigabytes.
     */
    public function vocabulary(): ?Vocabulary
    {
        if ($this->vocabulary === false) {
            $v = new Vocabulary(
                $this->getSystemSetting('vocab-sqlite-path') ?: null,
                $this->getSystemSetting('webapi-url') ?: null,
                $this->getSystemSetting('webapi-token') ?: null
            );
            $this->vocabulary = $v->configured() ? $v : null;
        }
        return $this->vocabulary;
    }

    /** Tests only: injects a vocabulary. */
    public function setVocabulary(?Vocabulary $v): void
    {
        $this->vocabulary = $v;
    }

    /**
     * Data of a concept according to the vocabulary.
     *
     * The first call looks up every concept in the mapping at once: the
     * editor asks for hundreds and one query per concept would be slow.
     *
     * @return array|null|false the concept; null if it does not exist; false
     *                          if there is no vocabulary or it does not respond.
     */
    private $conceptCache = null;

    public function conceptInfo(int $id)
    {
        $vocab = $this->vocabulary();
        if ($vocab === null || $id <= 0) {
            return false;
        }
        if ($this->conceptCache === null || !array_key_exists($id, $this->conceptCache)) {
            $ids = [$id];
            if ($this->conceptCache === null) {
                $map = $this->store()->load();
                foreach ($map['fields'] as $f) {
                    if (!empty($f['concept_id'])) {
                        $ids[] = (int) $f['concept_id'];
                    }
                }
                foreach ($map['values'] as $opts) {
                    foreach ($opts as $v) {
                        if (!empty($v['concept_id'])) {
                            $ids[] = (int) $v['concept_id'];
                        }
                    }
                }
            }
            $found = $vocab->lookup($ids);
            if ($found === null) {
                return false;
            }
            $this->conceptCache = $this->conceptCache ?? [];
            foreach (array_unique($ids) as $i) {
                $this->conceptCache[$i] = $found[$i] ?? null;
            }
        }
        return $this->conceptCache[$id];
    }

    /**
     * UCUM concept of a unit: the vocabulary first, otherwise the short table
     * of safe units.
     */
    public function unitConcept(?string $unit): ?int
    {
        $code = CdmModel::ucumCode($unit);
        if ($code === null) {
            return null;
        }
        $vocab = $this->vocabulary();
        if ($vocab !== null) {
            $c = $vocab->ucum($code);
            if ($c !== null) {
                return $c['id'];
            }
        }
        return CdmModel::UCUM_BUILTIN[$code] ?? null;
    }

    // -------------------------------------------------- Identifiers and privacy

    public function idRegistry(bool $dryRun = false): IdRegistry
    {
        return new IdRegistry($this, $dryRun);
    }

    /** Secret project salt, shared by the hash and the date shift. */
    public function projectSalt(): string
    {
        $salt = $this->setting('person-hash-salt');
        if (empty($salt)) {
            $salt = bin2hex(random_bytes(16));
            $this->writeSetting('person-hash-salt', $salt);
        }
        return (string) $salt;
    }

    public function pseudonymizer(): Pseudonymizer
    {
        return new Pseudonymizer(
            (int) $this->setting('date-shift-days'),
            (string) ($this->setting('birth-precision') ?: 'full'),
            (bool) $this->setting('suppress-free-text'),
            (int) $this->setting('date-shift-days') > 0 ? $this->projectSalt() : ''
        );
    }

    /** Fields that feed CARE_SITE and PROVIDER. */
    public function entityConfig(): array
    {
        return [
            'care_site_field' => $this->setting('care-site-field') ?: null,
            'provider_field'  => $this->setting('provider-field') ?: null,
        ];
    }

    /**
     * language_concept_id for NOTE. If not configured, the one for the module
     * language: 4180186 (English language) or 4182511 (Spanish language).
     */
    public function noteLanguageConcept(): int
    {
        $c = (int) $this->setting('note-language-concept-id');
        if ($c > 0) {
            return $c;
        }
        return $this->ttText('doc_lang') === 'es' ? 4182511 : 4180186;
    }

    /** Schema in which the load kit creates the tables. */
    public function loadSchema(): string
    {
        $s = strtolower((string) ($this->setting('load-schema') ?: 'cdm'));
        return preg_match('/^[a-z_][a-z0-9_]{0,62}$/', $s) ? $s : 'cdm';
    }

    /** Mapping proposed by the field's @OMOP annotation, or null. */
    private $annotationCache = [];

    public function annotation(string $field): ?array
    {
        if (!array_key_exists($field, $this->annotationCache)) {
            $a = (string) ($this->getDictionary()[$field]['field_annotation'] ?? '');
            $this->annotationCache[$field] = MappingAnnotation::parse($a);
        }
        return $this->annotationCache[$field];
    }

    public function store(): MappingStore
    {
        if ($this->store === null) {
            $this->store = new MappingStore($this, $this->pid());
        }
        return $this->store;
    }

    // -------------------------------------------------------------- Extraction

    /**
     * The project's data dictionary, exactly as REDCap returns it.
     *
     * Keys per field: field_name, form_name, section_header, field_type,
     * field_label, select_choices_or_calculations, field_note,
     * text_validation_type_or_show_slider_number, text_validation_min,
     * text_validation_max, identifier, branching_logic, required_field,
     * custom_alignment, question_number, matrix_group_name, matrix_ranking,
     * field_annotation.
     */
    public function getDictionary(): array
    {
        if ($this->dictionaryCache === null) {
            $this->dictionaryCache = \REDCap::getDataDictionary($this->pid(), 'array');
        }
        return $this->dictionaryCache;
    }

    /**
     * Project structure: what determines the granularity of the OMOP rows (a
     * longitudinal project with repeating instruments generates many more
     * fact rows than a flat project).
     */
    public function getProjectStructure(): array
    {
        if ($this->structureCache !== null) {
            return $this->structureCache;
        }
        $proj = new \Project($this->pid());

        $events = [];
        if ($proj->longitudinal) {
            // The unique event name is requested through the public API,
            // which is stable across versions; the internal properties of
            // Project are not always populated and would return empty strings.
            $uniqueNames = [];
            try {
                $uniqueNames = (array) \REDCap::getEventNames(true, true);
            } catch (\Throwable $e) {
                $uniqueNames = (array) ($proj->uniqueEventNames ?? []);
            }

            foreach ((array) $proj->eventInfo as $eventId => $info) {
                $unique = $uniqueNames[$eventId] ?? '';
                if ($unique === '') {
                    $unique = $proj->uniqueEventNames[$eventId] ?? '';
                }
                if ($unique === '') {
                    $unique = 'event_' . $eventId;
                }
                $events[$eventId] = [
                    'event_id'    => (int) $eventId,
                    'unique_name' => $unique,
                    'descrip'     => $info['descrip'] ?? '',
                    'arm_num'     => $info['arm_num'] ?? 1,
                    'day_offset'  => $info['day_offset'] ?? null,
                ];
            }
        }

        // REDCap stores a whole repeating event as the string 'WHOLE' instead
        // of an array of forms.
        $repeatingSource = $proj->RepeatingFormsEvents ?? [];
        $repeating = [];
        foreach ((array) $repeatingSource as $eventId => $forms) {
            if (!is_array($forms)) {
                $repeating[] = ['event_id' => (int) $eventId, 'form' => ''];
                continue;
            }
            foreach (array_keys($forms) as $formName) {
                $repeating[] = ['event_id' => (int) $eventId, 'form' => $formName];
            }
        }

        return $this->structureCache = [
            'project_id'      => $this->pid(),
            'record_id_field' => $proj->table_pk,
            'longitudinal'    => (bool) $proj->longitudinal,
            'surveys_enabled' => (bool) $proj->project['surveys_enabled'],
            'forms'           => array_keys((array) $proj->forms),
            'events'          => $events,
            'repeating'       => $repeating,
        ];
    }

    // --------------------------------------------------------------- Profiling

    /**
     * Automatic OMOP domain proposal for every field in the project.
     * It is a starting point for the human curator, never the final result.
     */
    public function profile(): array
    {
        if ($this->profileCache !== null) {
            return $this->profileCache;
        }
        // The framework's translator: the profiler returns text already in
        // REDCap's active language without knowing anything about REDCap.
        $profiler = new DictionaryProfiler(function (string $key, ...$args) {
            return $this->tt($key, ...$args);
        });
        return $this->profileCache =
            $profiler->profile($this->getDictionary(), $this->getProjectStructure());
    }

    /**
     * The mapping has changed in this request: anything derived from it is
     * no longer valid. MappingStore calls this when saving.
     */
    public function invalidateMappingCache(): void
    {
        $this->mappingRowsCache = null;
        $this->adviceCache = [];
    }

    /**
     * Field count per proposed domain.
     */
    public function profileSummary(array $profile): array
    {
        $summary = [];
        foreach ($profile as $row) {
            $domain = $row['suggested_domain'];
            $summary[$domain] = ($summary[$domain] ?? 0) + 1;
        }
        arsort($summary);
        return $summary;
    }

    // ----------------------------------------------------------------- Mapping

    /**
     * Combined view: classifier proposal + saved decision.
     *
     * If the dictionary changed after a field was approved, the state returned
     * is 'review' even though it is still approved in the store. That way the
     * curator sees the problem without anything being rewritten on the fly.
     */
    public function mappingRows(): array
    {
        if ($this->mappingRowsCache !== null) {
            return $this->mappingRowsCache;
        }
        $profile    = $this->profile();
        $dictionary = $this->getDictionary();
        $store      = $this->store();
        $out = [];

        foreach ($profile as $name => $prof) {
            $spec    = $store->spec($name);
            $meta    = $dictionary[$name] ?? [];
            $drifted = $meta ? MappingStore::hasDrifted($spec, $meta, $dictionary) : false;
            $reason  = $drifted ? 'dictionary' : null;

            // If the date field that dated an approved fact disappears from
            // the dictionary, the approval no longer describes what would be
            // exported: the fact would go out with the default date, or with
            // no date, without anyone having decided so. It counts as drift.
            if (!$drifted && $spec['state'] === MappingStore::S_APPROVED
                && !empty($spec['date_field']) && !isset($dictionary[$spec['date_field']])) {
                $drifted = true;
                $reason  = 'date_field';
            }

            $state = $drifted ? MappingStore::S_REVIEW : $spec['state'];
            // A field the configuration already consumes (Record ID, birth,
            // date of death, care site, provider) is not mapped: it feeds its
            // table directly. Asking for a concept confused the curator and
            // left it «pending» forever. If someone approved it on purpose
            // (e.g. the date of death as a Death fact), that wins.
            $role = $this->configRole($name);
            if ($role !== null && $state !== MappingStore::S_APPROVED) {
                $state = MappingStore::S_CONFIG;
            }

            $out[$name] = [
                'profile' => $prof,
                'spec'    => $spec,
                'values'  => $store->values($name),
                'state'   => $state,
                'drifted' => $drifted,
                'drift_reason' => $reason,
                'role'    => $role,
            ];
        }
        return $this->mappingRowsCache = $out;
    }

    /**
     * Role the module configuration gives to a field, or null.
     *
     * Sex, race and ethnicity are NOT here: their choices need a concept and
     * approval in the editor.
     */
    public function configRole(string $field): ?string
    {
        $pc = $this->personConfig();
        $ec = $this->entityConfig();
        $roles = [
            'person_id'  => $this->getProjectStructure()['record_id_field'] ?? null,
            'birth'      => $pc['birth_date_field'] ?: $pc['birth_year_field'],
            'birth_year' => $pc['birth_date_field'] ? $pc['birth_year_field'] : null,
            'death_date' => $this->deathConfig()['date_field'],
            'care_site'  => $ec['care_site_field'],
            'provider'   => $ec['provider_field'],
        ];
        foreach ($roles as $role => $f) {
            if ($f !== null && $f === $field) {
                return $role === 'birth_year' ? 'birth' : $role;
            }
        }
        return null;
    }

    /** Is this field approved, with no drift? That is what counts for export. */
    public function isApproved(string $field): bool
    {
        $rows = $this->mappingRows();
        return isset($rows[$field]) && $rows[$field]['state'] === MappingStore::S_APPROVED;
    }

    public function mappingCounts(): array
    {
        $counts = ['total' => 0, 'proposed' => 0, 'approved' => 0,
                   'excluded' => 0, 'review' => 0, 'config' => 0];
        foreach ($this->mappingRows() as $row) {
            $counts['total']++;
            $counts[$row['state']] = ($counts[$row['state']] ?? 0) + 1;
        }
        return $counts;
    }

    /** Does the field have date validation? */
    public function isDateField(string $field): bool
    {
        $v = $this->getDictionary()[$field]['text_validation_type_or_show_slider_number'] ?? '';
        return $v !== '' && strpos($v, 'date') === 0;
    }

    /** Fields with date validation: the candidates for reference date. */
    public function dateFields(): array
    {
        $out = [];
        foreach ($this->getDictionary() as $name => $meta) {
            $v = $meta['text_validation_type_or_show_slider_number'] ?? '';
            if ($v !== '' && strpos($v, 'date') === 0) {
                $out[$name] = trim(strip_tags($meta['field_label'] ?? $name));
            }
        }
        return $out;
    }

    /**
     * Date that belongs to a field because of its instrument.
     *
     * A value collected in a form is almost always dated by the date of that
     * same form: a lab test is dated by the blood draw, a physical exam by the
     * visit. Proposing it avoids the easiest mistake to make, which is
     * inheriting the date of another instrument without noticing.
     *
     * Returns null if the instrument has no date, or if it has several and
     * there is no way to choose without guessing.
     */
    public function suggestedDateField(string $field, ?string $domain = null): ?string
    {
        $dict = $this->getDictionary();
        if (!isset($dict[$field])) {
            return null;
        }

        // An «Exitus sí/no» (death yes/no) is dated with the date of death
        // declared in the configuration, not with the first date in its form.
        $deathDate = $this->setting('death-date-field');
        if ($domain === 'Death' && !empty($deathDate) && isset($dict[$deathDate])) {
            return $deathDate;
        }

        // A date field mapped to a clinical domain IS the fact: «Fecha de
        // diagnóstico de TB» or «Fecha de fallecimiento» are dated with their
        // own value. Proposing another date from the form dated the death
        // with the case closing date.
        if ($this->isDateField($field)) {
            return $field;
        }

        $form = $dict[$field]['form_name'] ?? '';
        if ($form === '') {
            return null;
        }

        // The date of birth never dates a clinical fact: we know which one it
        // is because the project declares it in the module configuration.
        $birth = $this->setting('birth-date-field');

        $candidates = [];
        foreach ($dict as $name => $meta) {
            if (($meta['form_name'] ?? '') !== $form || $name === $field
                || ($birth !== null && $name === $birth)) {
                continue;
            }
            $v = $meta['text_validation_type_or_show_slider_number'] ?? '';
            if ($v !== '' && strpos($v, 'date') === 0) {
                $candidates[] = $name;
            }
        }

        if (count($candidates) === 1) {
            return $candidates[0];
        }
        if (count($candidates) > 1) {
            // With several dates in the same form, the first one in the
            // dictionary is usually the date of the encounter itself; the
            // following ones are dates of specific events. The first is proposed.
            return $candidates[0];
        }
        return null;
    }

    /** Visible label of a domain: the module's own codes are translated. */
    public function domainLabel(string $domain): string
    {
        return in_array($domain, [DictionaryProfiler::D_SKIP, DictionaryProfiler::D_DATE_ANCHOR], true)
            ? $this->tt('domain_' . $domain)
            : $domain;
    }

    /**
     * Athena search URL.
     *
     * The full set of parameters is sent, including page and pageSize: Athena
     * is a single-page application and the URL filters are not always applied
     * if they arrive on their own.
     */
    public function athenaUrl(string $query, string $domain, string $vocabulary = ''): string
    {
        $params = [
            'query'           => $query,
            'standardConcept' => 'Standard',
            'invalidReason'   => 'Valid',
            'page'            => '1',
            'pageSize'        => '15',
        ];
        if ($domain !== '') {
            $params['domain'] = $domain;
        }
        if ($vocabulary !== '') {
            $params['vocabulary'] = $vocabulary;
        }
        return 'https://athena.ohdsi.org/search-terms/terms?' . http_build_query($params);
    }

    /**
     * Vocabulary worth filtering on in Athena for each domain.
     *
     * Without this filter, searching for a lab analyte returns mostly SNOMED
     * concepts of class Procedure, which represent the act of measuring and
     * not the measured analyte. The OHDSI convention is LOINC for measurements
     * and RxNorm for drugs.
     */
    public function athenaVocabulary(?string $domain): string
    {
        switch ($domain) {
            case 'Measurement': return 'LOINC';
            case 'Drug':        return 'RxNorm';
            case 'Person':      return 'Gender';
            case 'Condition':
            case 'Procedure':
            case 'Observation':
            case 'Device':
            case 'Specimen':
            case 'Death':
            case 'Visit':       return 'SNOMED';
            default:            return '';
        }
    }

    /**
     * LOINC property deduced from a unit of measure.
     *
     * The unit does not appear in the name of a LOINC concept, but the
     * PROPERTY does: "Hemoglobin [Mass/volume] in Blood". And the property
     * follows from the unit unambiguously, because g/dL is mass per volume and
     * U/L is enzymatic activity per volume.
     *
     * Adding it narrows the search a lot: "hemoglobin" returns glycated
     * hemoglobin, fetal hemoglobin and the fractions; "hemoglobin mass volume"
     * returns the one measured in g/dL, which is the one the field holds.
     */
    public function loincProperty(?string $unit): string
    {
        if ($unit === null || trim($unit) === '') {
            return '';
        }
        $u = strtolower(trim($unit));
        $u = str_replace([' ', 'µ', 'μ'], ['', 'u', 'u'], $u);

        // Mass per volume: g/dL, mg/dL, ng/mL...
        if (preg_match('#^(k|m|u|n|p)?g/(d|m|u|c)?l$#', $u)) {
            return 'mass volume';
        }
        // Substance per volume: mmol/L, umol/L...
        if (preg_match('#^(m|u|n|p)?mol/(d|m|u|c)?l$#', $u)) {
            return 'moles volume';
        }
        // U/L is enzymatic activity; UI/L and mUI/L are international units,
        // which LOINC names differently because they usually measure hormones
        // rather than enzymes.
        if (preg_match('#^(m|u|k)?(ui|iu)/(d|m|u|c)?l$#', $u)) {
            return 'units volume';
        }
        if (preg_match('#^(m|u|k)?u/(d|m|u|c)?l$#', $u)) {
            return 'enzymatic activity volume';
        }
        // Count per volume: x10^3/uL, 10*6/uL, cel/uL...
        if (preg_match('#(10|cel|cells|k)[\^\*]?\d*/(d|m|u|c)?l$#', $u)) {
            return 'number volume';
        }
        if ($u === '%') {
            return 'fraction';
        }
        // Weight, height and blood pressure do not carry the property in the
        // LOINC concept name (just "Body weight"), so adding it would only
        // narrow the search without bringing it closer to the right concept.
        return '';
    }

    /**
     * Athena search terms built from the field label.
     *
     * The OHDSI vocabulary is in English, so sending a Spanish label as is
     * returns results by chance or none at all. Frequent clinical terms are
     * translated and the wording noise typical of questionnaires is removed.
     */
    public function athenaQuery(string $label, string $fieldName, string $unit = ''): string
    {
        $text = (string) $label;

        // The trailing parenthesis of a clinical label is almost always the
        // unit: "Peso (kg)", "Creatinina (mg/dL)". Athena does not search by
        // unit (LOINC describes the property, not the unit), so sending it
        // only adds noise and narrows the results.
        $text = preg_replace('/\([^()]{1,20}\)\s*$/u', ' ', $text);

        // Athena uses American spelling: «haemoglobin» does not find hemoglobin.
        $t = ' ' . TextNormalizer::normalize(TextNormalizer::americanize($text)) . ' ';

        // Questionnaire wording noise.
        foreach ([' por favor ', ' indique ', ' escriba ', ' anote ', ' seleccione ',
                  ' describa ', ' detalle ', ' del paciente ', ' el paciente ',
                  ' cual es ', ' cuanto ', ' cuantos ', ' cuantas ', ' tiene ',
                  ' presento ', ' refiere ', ' resultado de ', ' resultado del ',
                  ' resultado ', ' valor de ', ' poner numero exacto ',
                  ' numero exacto ', ' en su caso ', ' si procede ',
                  ' especificar ', ' exactamente ', ' del 1 al 10 ',
                  ' del 0 al 10 ', ' aprox ', ' aproximado ', ' estimado ',
                  ' presenta ', ' recibe ', ' ha recibido ', ' ha tenido ',
                  ' en el momento de la visita ', ' en el momento ', ' momento ',
                  ' desde la ultima visita ', ' en los ultimos ', ' alguna vez ',
                  ' is the patient on ', ' does the patient have ', ' has the patient ',
                  ' did the patient ', ' in the last ', ' months ', ' ever '] as $noise) {
            $t = str_replace($noise, ' ', $t);
        }

        // Translation. Longest phrases first, so that "sudoracion nocturna"
        // beats "sudoracion" and returns the right clinical term.
        $glossary = self::ATHENA_GLOSSARY;
        uksort($glossary, fn($a, $b) => strlen($b) <=> strlen($a));

        $terms = [];
        foreach ($glossary as $es => $en) {
            if (strpos($t, ' ' . $es . ' ') !== false) {
                // An empty translation means "this word adds nothing to the
                // search": it is removed without adding a term.
                foreach (explode(' ', $en) as $w) {
                    if ($w !== '') {
                        $terms[$w] = true;
                    }
                }
                $t = str_replace(' ' . $es . ' ', ' ', $t);
            }
        }

        // Protocol qualifiers are removed AFTER translating: if they were
        // removed before, "No realizada" would lose "realizada" and be left
        // as a bare "no" instead of being translated as "not performed".
        //
        // Athena does not distinguish a baseline measurement from a follow-up
        // one: they are the same concept measured at different times, and
        // that distinction lives in the date of the fact, not in the concept_id.
        foreach (self::PROTOCOL_WORDS as $w) {
            $t = str_replace(' ' . $w . ' ', ' ', $t);
        }

        // What is left: acronyms and words that already work in English (LDL,
        // HbA1c, TSH, COVID). Bare units and stopwords are dropped, and any
        // untranslated Spanish left over is let through: an imperfect search
        // is better than none.
        foreach (preg_split('/\s+/', trim($t)) as $w) {
            if ($w === '' || strlen($w) < 2) {
                continue;
            }
            if (in_array($w, self::ATHENA_STOPWORDS, true)
                || in_array($w, self::UNIT_WORDS, true)
                || preg_match('/^\d+$/', $w)) {
                continue;
            }
            $terms[$w] = true;
        }

        $query = trim(implode(' ', array_slice(array_keys($terms), 0, 5)));

        // The property goes last: if the search is already long, Athena uses
        // it as reinforcement rather than as an exclusive filter.
        $prop = $this->loincProperty($unit);
        if ($prop !== '' && $query !== '') {
            $query .= ' ' . $prop;
        }

        return $query !== '' ? $query : $fieldName;
    }

    /**
     * Study protocol qualifiers.
     *
     * "Peso basal" and "Peso en esta visita" (baseline weight, weight at this
     * visit) are the same OMOP concept; what tells them apart is the date of
     * the fact, not the concept. Sending them to Athena narrows the search for
     * no reason.
     */
    private const PROTOCOL_WORDS = [
        'basal', 'control', 'actual', 'previo', 'previa', 'inicial', 'final',
        'realizada', 'realizado', 'hecho', 'hecha', 'seguimiento', 'nueva',
        'nuevo', 'ultimo', 'ultima', 'primer', 'primera', 'segundo', 'segunda',
        'baseline', 'follow up', 'current', 'previous', 'initial',
        'done', 'performed',
    ];

    /**
     * Units of measure. They are removed from the search: in LOINC the unit
     * is not part of the concept name, the measured property is (Mass, Volume,
     * Mass/volume), so searching for "kg" or "dl" does not get any closer to
     * the right concept.
     */
    private const UNIT_WORDS = [
        'kg', 'g', 'mg', 'mcg', 'ug', 'ng', 'pg', 'lb', 'l', 'ml', 'dl', 'cl',
        'ul', 'cm', 'mm', 'm', 'mmhg', 'mmol', 'mol', 'umol', 'nmol', 'pmol',
        'meq', 'ui', 'iu', 'mui', 'u', 'kcal', 'cal', 'lpm', 'rpm', 'bpm',
        'x10', 'x106', 'x103', 'seg', 'min', 'ano', 'anos', 'dia', 'dias',
        'mes', 'meses', 'semana', 'semanas', 'unidades',
    ];

    /** Frequent Spanish terms in clinical questionnaires, to English. */
    private const ATHENA_GLOSSARY = [
        'colesterol' => 'cholesterol', 'trigliceridos' => 'triglyceride',
        'glucemia' => 'glucose', 'glucosa' => 'glucose',
        'hemoglobina' => 'hemoglobin', 'creatinina' => 'creatinine',
        'globulos rojos' => 'erythrocytes', 'hematies' => 'erythrocytes',
        'globulos blancos' => 'leukocytes', 'leucocitos' => 'leukocytes',
        'plaquetas' => 'platelets', 'linfocitos' => 'lymphocytes',
        'sodio' => 'sodium', 'potasio' => 'potassium',
        'albumina' => 'albumin', 'bilirrubina' => 'bilirubin',
        'presion arterial' => 'blood pressure', 'tension arterial' => 'blood pressure',
        'sistolica' => 'systolic', 'diastolica' => 'diastolic',
        'frecuencia cardiaca' => 'heart rate', 'temperatura' => 'body temperature',
        'saturacion' => 'oxygen saturation', 'peso' => 'body weight',
        'talla' => 'body height', 'altura' => 'body height',
        'perimetro abdominal' => 'waist circumference',
        'dolor' => 'pain', 'duele' => 'pain', 'fiebre' => 'fever',
        'tos' => 'cough', 'disnea' => 'dyspnea', 'edema' => 'edema',
        'cefalea' => 'headache', 'nauseas' => 'nausea', 'vomitos' => 'vomiting',
        'diabetes' => 'diabetes mellitus', 'hipertension' => 'hypertension',
        'dislipemia' => 'dyslipidemia', 'asma' => 'asthma', 'epoc' => 'copd',
        'ictus' => 'stroke', 'infarto' => 'myocardial infarction',
        'insuficiencia cardiaca' => 'heart failure', 'cancer' => 'malignant neoplasm',
        'cardiopatia isquemica' => 'ischemic heart disease',
        'enfermedad renal cronica' => 'chronic kidney disease',
        'enfermedad hepatica' => 'liver disease', 'apnea del sueno' => 'sleep apnea',
        'artrosis' => 'osteoarthritis', 'osteoporosis' => 'osteoporosis',
        'demencia' => 'dementia', 'depresion' => 'depressive disorder',
        'ansiedad' => 'anxiety disorder', 'obesidad' => 'obesity',
        'anemia' => 'anemia', 'arritmia' => 'cardiac arrhythmia',
        'fibrilacion auricular' => 'atrial fibrillation',
        'apendicectomia' => 'appendectomy', 'colecistectomia' => 'cholecystectomy',
        'artroplastia' => 'joint replacement', 'neumonia' => 'pneumonia',
        'neoplasia' => 'neoplasm', 'infeccion' => 'infection',
        'ingreso' => 'hospital admission', 'alta' => 'discharge',
        'urgencias' => 'emergency department visit', 'visita' => 'visit',
        'consulta' => 'outpatient visit', 'seguimiento' => 'follow-up',
        'vacuna' => 'vaccine', 'vacunacion' => 'vaccination',
        'medicacion' => 'drug', 'medicamento' => 'drug', 'farmaco' => 'drug',
        'pastillas' => 'drug', 'insulina' => 'insulin', 'metformina' => 'metformin',
        'estatina' => 'statin', 'antibiotico' => 'antibiotic',
        'cirugia' => 'surgery', 'biopsia' => 'biopsy', 'dialisis' => 'dialysis',
        'transfusion' => 'transfusion', 'sexo' => 'gender', 'genero' => 'gender',
        'nacimiento' => 'birth', 'fallecimiento' => 'death', 'exitus' => 'death',
        'defuncion' => 'death', 'tabaco' => 'tobacco', 'fumador' => 'smoker',
        'alcohol' => 'alcohol', 'gravedad' => 'severity', 'intensidad' => 'severity',

        // Lab tests and microbiology
        'acido urico' => 'urate', 'urico' => 'urate',
        'bilirrubina total' => 'bilirubin total',
        'fosfatasa alcalina' => 'alkaline phosphatase',
        'transaminasas' => 'aminotransferase',
        'got' => 'aspartate aminotransferase', 'ast' => 'aspartate aminotransferase',
        'gpt' => 'alanine aminotransferase', 'alt' => 'alanine aminotransferase',
        'ggt' => 'gamma glutamyl transferase',
        'hematocrito' => 'hematocrit', 'hemograma' => 'blood count',
        'baciloscopia de esputo' => 'acid fast bacilli sputum smear',
        'baciloscopia' => 'acid fast bacilli smear', 'esputo' => 'sputum',
        'cultivo' => 'culture', 'genexpert' => 'mycobacterium tuberculosis dna',
        'tuberculina' => 'tuberculin', 'induracion' => 'induration',
        'recuento' => 'count', 'carga viral' => 'viral load',
        'ayunas' => 'fasting', 'analitica' => 'laboratory',

        // Conditions and symptoms
        'sudoracion nocturna' => 'night sweats', 'sudoracion' => 'sweating',
        'hemoptisis' => 'hemoptysis', 'ictericia' => 'jaundice',
        'artralgia' => 'arthralgia', 'artralgias' => 'arthralgia',
        'mialgia' => 'myalgia', 'mialgias' => 'myalgia',
        'prurito' => 'pruritus',
        'neuropatia periferica' => 'peripheral neuropathy',
        'neuropatia' => 'neuropathy', 'hepatopatia' => 'liver disease',
        'nefropatia' => 'kidney disease', 'cardiopatia' => 'heart disease',
        'alergia' => 'allergy', 'alergias' => 'allergy',
        'tuberculosis' => 'tuberculosis', 'tb' => 'tuberculosis',
        'pulmonar' => 'pulmonary', 'extrapulmonar' => 'extrapulmonary',
        'perdida de peso' => 'weight loss', 'perdido peso' => 'weight loss',
        'efectos adversos' => 'adverse reaction', 'efecto adverso' => 'adverse reaction',
        'alteraciones visuales' => 'visual disturbance',
        'persistente' => '', 'arterial' => '',

        // Procedures and imaging
        'radiografia de torax' => 'chest radiography', 'radiografia' => 'radiography',
        'torax' => 'chest', 'cavitacion' => 'cavitation',
        'ecografia' => 'ultrasonography', 'tomografia' => 'tomography',

        // Drugs
        'antirretroviral' => 'antiretroviral', 'arv' => 'antiretroviral',
        'tarv' => 'antiretroviral', 'isoniazida' => 'isoniazid',
        'rifampicina' => 'rifampin', 'pirazinamida' => 'pyrazinamide',
        'etambutol' => 'ethambutol', 'adherencia' => 'medication adherence',
        'dosis' => 'dose', 'olvidadas' => 'missed',

        // Choice labels: those of a dropdown, not those of the field
        'hombre' => 'male', 'varon' => 'male', 'mujer' => 'female',
        'masculino' => 'male', 'femenino' => 'female',
        'positivo' => 'positive', 'positiva' => 'positive',
        'negativo' => 'negative', 'negativa' => 'negative',
        'indeterminado' => 'indeterminate', 'desconocido' => 'unknown',
        'pendiente' => 'pending', 'normal' => 'normal',
        'patologica' => 'abnormal', 'patologico' => 'abnormal',
        'detectado' => 'detected', 'no detectado' => 'not detected',
        'contaminado' => 'contaminated', 'no valorable' => 'not evaluable',
        'no realizada' => 'not performed', 'no realizado' => 'not performed',
        'no aplica' => 'not applicable',
        'prefiero no decirlo' => 'unknown', 'no consta' => 'unknown',
        'ninguno' => 'none', 'ninguna' => 'none', 'otro' => 'other', 'otra' => 'other',
        'sin estudios' => 'no formal education',
        'primaria' => 'primary education', 'secundaria' => 'secondary education',
        'superior' => 'higher education', 'universitario' => 'higher education',
        'empleado' => 'employed', 'desempleado' => 'unemployed',
        'jubilado' => 'retired', 'estudiante' => 'student',
        'casa independiente' => 'house', 'piso' => 'apartment',
        'apartamento' => 'apartment', 'albergue' => 'shelter',
        'infravivienda' => 'inadequate housing',
        'cannabis' => 'cannabis', 'cocaina' => 'cocaine', 'heroina' => 'heroin',
        'pareja' => 'partner', 'hijo' => 'child', 'padre' => 'parent',
        'madre' => 'parent', 'hermano' => 'sibling',
        'diario' => 'daily', 'ocasional' => 'occasional',
        'abandono' => 'treatment discontinued', 'curacion' => 'cured',
        'fracaso terapeutico' => 'treatment failure',
        'sensible' => 'susceptible', 'resistente' => 'resistant',

        // Gaps found while testing with real projects
        'tabaquismo' => 'tobacco smoking', 'exfumador' => 'ex-smoker',
        'nunca fumador' => 'never smoked tobacco',
        'vih' => 'hiv', 'sida' => 'aids', 'hepatitis' => 'hepatitis',
        'nivel de estudios' => 'educational achievement', 'estudios' => 'education',
        'situacion laboral' => 'employment status', 'estado civil' => 'marital status',
        'convivientes' => 'household', 'domicilio' => 'household',
        'tratamiento quirurgico' => 'surgical procedure', 'quirurgico' => 'surgical',
        'quirurgica' => 'surgical', 'tratamiento' => 'treatment',
        'resultado del tratamiento' => 'treatment outcome',
        'causa de muerte' => 'cause of death', 'causa de la muerte' => 'cause of death',
        'inicio de sintomas' => 'symptom onset', 'sintomas' => 'symptom',
        'comorbilidades' => 'comorbidity', 'antecedentes' => 'history of',
        'antituberculoso' => 'antituberculosis', 'antituberculosos' => 'antituberculosis',
        'fisioterapia' => 'physiotherapy', 'rehabilitacion' => 'rehabilitation',
        'radioterapia' => 'radiotherapy', 'quimioterapia' => 'chemotherapy',
        'embarazo' => 'pregnancy', 'gestacion' => 'pregnancy', 'parto' => 'delivery',
        'intensidad del dolor' => 'pain severity', 'eva' => 'visual analog scale',
        'edad al diagnostico' => 'age at diagnosis', 'edad' => 'age',
        'frecuencia respiratoria' => 'respiratory rate', 'pulso' => 'heart rate',
        'imc' => 'body mass index', 'indice de masa corporal' => 'body mass index',
        'carga viral vih' => 'hiv 1 rna', 'cd4' => 'cd4',
        'proteina c reactiva' => 'c reactive protein', 'ferritina' => 'ferritin',
        'urea' => 'urea', 'cloro' => 'chloride', 'calcio' => 'calcium',
        'magnesio' => 'magnesium', 'fosforo' => 'phosphate', 'hierro' => 'iron',
        'tsh' => 'thyrotropin', 'vitamina d' => 'vitamin d', 'inr' => 'inr',
        'dimero d' => 'fibrin d-dimer', 'troponina' => 'troponin', 'lactato' => 'lactate',
    ];

    /** Words with no search value that are left after translating. */
    private const ATHENA_STOPWORDS = [
        'de', 'del', 'la', 'el', 'los', 'las', 'un', 'una', 'y', 'o', 'en',
        'al', 'se', 'su', 'que', 'con', 'para', 'por', 'es', 'ha', 'le', 'lo',
        'malo', 'bueno', 'alto', 'bajo', 'total', 'previo', 'previa', 'hoy',
        'mas', 'algo', 'raro', 'debamos', 'saber', 'dio', 'dado', 'viene',
        'hay', 'sn', 'aqui', 'sobre', 'tiene', 'este', 'esta',
        'fecha', 'dia', 'hora', 'ano', 'mes', 'analitica', 'laboratorio',
        'sangre', 'extraccion', 'anotar', 'nota', 'notas', 'comentario',
    ];

    /**
     * Domain as Athena's filter understands it. The module's two own codes do
     * not exist there, so no domain filter is applied for them.
     */
    public function athenaDomain(?string $domain): string
    {
        if ($domain === null
            || in_array($domain, [DictionaryProfiler::D_SKIP, DictionaryProfiler::D_DATE_ANCHOR], true)) {
            return '';
        }
        // 'Person' is the name of a CDM TABLE, not of a domain: filtering by
        // domain=Person in Athena returns nothing because that domain does
        // not exist. The concepts that go to PERSON live in Gender, Race and
        // Ethnicity, and by far the most frequent one is sex.
        if ($domain === 'Person') {
            return 'Gender';
        }
        // The same goes for Death: it is a table, not a concept domain. What
        // is searched for a DEATH field is the cause of death, and causes are
        // conditions.
        if ($domain === 'Death') {
            return 'Condition';
        }
        return $domain;
    }

    /**
     * Editing the mapping requires project design rights: it decides what
     * goes out to the CDM, so it is not a data entry action.
     */
    public function userCanEditMapping(): bool
    {
        try {
            $user = $this->getUser();
            if ($user !== null && method_exists($user, 'hasDesignRights')) {
                return (bool) $user->hasDesignRights();
            }
        } catch (\Throwable $e) {
            // If the framework does not expose it, fall back to REDCap's rule.
        }
        // Fail closed: if it cannot be determined, editing is not allowed.
        return !empty($GLOBALS['user_rights']['design']);
    }

    /**
     * Who can open the module pages.
     *
     * By default the framework only lets in users with design rights, which
     * left out precisely those who most need to review the mapping without
     * touching it: the principal investigator, the monitor, the statistician.
     * The dashboard, the read-only editor and the document contain only
     * metadata, so any project member can see them. Editing still requires
     * design rights and exporting additionally requires full export rights:
     * the pages themselves check that.
     */
    public function redcap_module_link_check_display($project_id, $link)
    {
        if (!empty($project_id) && defined('USERID')) {
            try {
                $rights = \REDCap::getUserRights(USERID);
                if (!empty($rights[USERID])) {
                    return $link;
                }
            } catch (\Throwable $e) {
                // fall back to the framework's rule
            }
        }
        return parent::redcap_module_link_check_display($project_id, $link);
    }

    /** Rights of the current user in this project, or null for a superuser. */
    private function currentRights(): ?array
    {
        try {
            $user = $this->getUser();
            if ($user === null) {
                return [];
            }
            if ($user->isSuperUser()) {
                return null;
            }
            $rights = $user->getRights($this->pid());
            return is_array($rights) ? $rights : [];
        } catch (\Throwable $e) {
            return is_array($GLOBALS['user_rights'] ?? null) ? $GLOBALS['user_rights'] : [];
        }
    }

    /**
     * May this user take the project data out?
     *
     * Generating the CDM is an export of participant data, with full dates
     * and, without hashing, the Record ID. Having design rights (deciding
     * WHAT is mapped) does not authorise taking the data away. Full export
     * access (level 1, «Full data set») is additionally required on every
     * instrument any data comes from: approved fields, their reference dates
     * and the configuration fields (exportedFields()).
     *
     * @return string|null null if allowed; otherwise, the blocking instrument.
     */
    public function exportBlockedBy(): ?string
    {
        $rights = $this->currentRights();
        if ($rights === null) {
            return null;
        }
        if (empty($rights['design'])) {
            return '*design';
        }

        // REDCap ≥ 12: export rights per instrument, "[form,1][form2,0]".
        $perForm = [];
        if (!empty($rights['data_export_instruments'])
            && preg_match_all('/\[([a-z0-9_]+),(\d)\]/', (string) $rights['data_export_instruments'], $m, PREG_SET_ORDER)) {
            foreach ($m as $pair) {
                $perForm[$pair[1]] = (int) $pair[2];
            }
        }
        $legacy = isset($rights['data_export_tool']) ? (int) $rights['data_export_tool'] : null;

        // The dictionary is walked in its own order so that the first failing
        // instrument is always named, in a stable way.
        $consumed = $this->exportedFields();
        foreach ($this->getDictionary() as $field => $meta) {
            if (!isset($consumed[$field])) {
                continue;
            }
            $form = $meta['form_name'] ?? '';
            $level = $perForm ? ($perForm[$form] ?? 0) : $legacy;
            if ($level !== 1) {
                return $form;
            }
        }
        return null;
    }

    /**
     * Fields whose values go out in the export.
     *
     * Not only the approved ones: PERSON, DEATH, CARE_SITE and PROVIDER are
     * built from the configuration fields (birth, sex, race, ethnicity, date
     * of death, care site, provider), and every fact carries the date of its
     * reference field. All of them are participant data the user takes away,
     * so all of them count for the rights check.
     *
     * @return array<string,true>
     */
    private function exportedFields(): array
    {
        $out = [];
        foreach ($this->mappingRows() as $field => $row) {
            if ($row['state'] !== MappingStore::S_APPROVED) {
                continue;
            }
            $out[$field] = true;
            foreach (['date_field', 'end_date_field'] as $k) {
                if (!empty($row['spec'][$k])) {
                    $out[(string) $row['spec'][$k]] = true;
                }
            }
        }
        $config = array_merge(array_values($this->personConfig()), array_values($this->deathConfig()),
            array_values($this->entityConfig()), [$this->defaultDateField()]);
        foreach ($config as $field) {
            if (!empty($field)) {
                $out[(string) $field] = true;
            }
        }
        return $out;
    }

    /**
     * DAG of the current user, or null if they see the whole project.
     *
     * REDCap::getData returns the records of every DAG unless asked otherwise.
     * Without this, a user restricted to their own site would export the
     * participants of all the others.
     */
    public function userDagId(): ?int
    {
        $rights = $this->currentRights();
        if ($rights === null || empty($rights['group_id'])) {
            return null;
        }
        return (int) $rights['group_id'];
    }

    // --------------------------------------------------------- Export settings

    /**
     * PERSON fields declared in the project settings.
     *
     * Declaring the field says WHERE the data is; what each choice means is
     * decided in the editor and, like everything else, only counts if it is
     * approved.
     */
    public function personConfig(): array
    {
        return [
            'gender_field'     => $this->setting('gender-field') ?: null,
            'birth_date_field' => $this->setting('birth-date-field') ?: null,
            'birth_year_field' => $this->setting('birth-year-field') ?: null,
            'race_field'       => $this->setting('race-field') ?: null,
            'ethnicity_field'  => $this->setting('ethnicity-field') ?: null,
        ];
    }

    /**
     * DEATH fields declared in the settings.
     *
     * Like PERSON, DEATH is a one-row-per-person table and is built from the
     * configuration: the date of death is a specific project field, not «the
     * first date in the form» of a yes/no field.
     */
    public function deathConfig(): array
    {
        return [
            'date_field' => $this->setting('death-date-field') ?: null,
        ];
    }

    public function cdmSourceConfig(): array
    {
        return [
            'name'               => $this->setting('cdm-source-name')
                                    ?: $this->ttText('cdm_default_name', $this->pid()),
            'abbreviation'       => $this->setting('cdm-source-abbreviation')
                                    ?: ('RC' . $this->pid()),
            'holder'             => $this->setting('cdm-holder') ?: '',
            'vocabulary_version' => $this->setting('vocabulary-version') ?: '',
        ];
    }

    /**
     * Configuration problems that would make the CDM invalid or empty.
     *
     * @return string[] language keys
     */
    public function configProblems(): array
    {
        $p = [];
        $pc = $this->personConfig();
        if (empty($pc['birth_date_field']) && empty($pc['birth_year_field'])) {
            $p[] = 'cfgp_no_birth';
        }
        if (empty($pc['gender_field'])) {
            $p[] = 'cfgp_no_gender';
        } elseif (!$this->isApproved($pc['gender_field'])) {
            $p[] = 'cfgp_gender_not_approved';
        }
        foreach (['race_field' => 'cfgp_race_not_approved',
                  'ethnicity_field' => 'cfgp_ethnicity_not_approved'] as $k => $msg) {
            if (!empty($pc[$k]) && !$this->isApproved($pc[$k])) {
                $p[] = $msg;
            }
        }
        $src = $this->cdmSourceConfig();
        if ($src['holder'] === '') {
            $p[] = 'cfgp_no_holder';
        }
        if ($src['vocabulary_version'] === '') {
            $p[] = 'cfgp_no_vocab';
        }
        if (mb_strlen($src['abbreviation']) > 25) {
            $p[] = 'cfgp_abbr_long';
        }
        if ($this->setting('generate-visits') && !$this->visitConfig()['enabled']) {
            $p[] = 'cfgp_visit_no_concept';
        }
        // CARE_SITE and PROVIDER are built from the field's choices: free
        // text would give one care site per way of spelling it and, for
        // providers, their names.
        $dict = $this->getDictionary();
        foreach ($this->entityConfig() as $k => $f) {
            if ($f !== null && !in_array($dict[$f]['field_type'] ?? '', ['radio', 'dropdown'], true)) {
                $p[] = $k === 'care_site_field' ? 'cfgp_care_site_not_categorical' : 'cfgp_provider_not_categorical';
            }
        }
        if ($this->setting('date-shift-days') !== null && $this->setting('date-shift-days') !== ''
            && !preg_match('/^\d{1,4}$/', (string) $this->setting('date-shift-days'))) {
            $p[] = 'cfgp_shift_invalid';
        }
        return $p;
    }

    /**
     * Default reference date when a field does not declare its own.
     * Without it, every fact from an instrument with no date of its own would
     * be discarded.
     */
    public function defaultDateField(): ?string
    {
        return $this->setting('default-date-field') ?: null;
    }

    /**
     * person_source_value as it goes out in the export. With hashing enabled
     * the CDM no longer contains the project's original identifier.
     */
    public function hashPersonSourceValue(string $recordId): string
    {
        if (!$this->setting('hash-person-source-value')) {
            return $recordId;
        }
        return substr(hash('sha256', $this->projectSalt() . '|' . $recordId), 0, 32);
    }

    /** Warnings about the mapping of one specific field. */
    public function advise(string $field): array
    {
        if (isset($this->adviceCache[$field])) {
            return $this->adviceCache[$field];
        }
        $rows = $this->mappingRows();
        if (!isset($rows[$field])) {
            return [];
        }
        $dict = $this->getDictionary();
        return $this->adviceCache[$field] = (new MappingAdvisor($this))->check(
            $field, $rows[$field]['spec'], $rows[$field]['profile'],
            $rows[$field]['values'], $dict[$field] ?? []
        );
    }

    /** Warnings for the whole project, for the pre-export summary. */
    public function adviseAll(): array
    {
        $advisor = new MappingAdvisor($this);
        $dict = $this->getDictionary();
        $out = [];
        foreach ($this->mappingRows() as $field => $row) {
            if ($row['state'] === MappingStore::S_EXCLUDED) {
                continue;
            }
            $w = $advisor->check($field, $row['spec'], $row['profile'],
                                 $row['values'], $dict[$field] ?? []);
            if ($w) {
                $out[$field] = $w;
            }
        }
        return $out;
    }

    /**
     * Visit configuration.
     *
     * Without a concept_id no visits are generated: a VISIT_OCCURRENCE with
     * visit_concept_id 0 adds nothing and pollutes the CDM. The curator has to
     * choose which visit type their project represents.
     */
    public function visitConfig(): array
    {
        $conceptId = (int) $this->setting('visit-concept-id');
        return [
            'enabled'    => $this->setting('generate-visits') && $conceptId > 0,
            'concept_id' => $conceptId,
        ];
    }

    /**
     * Text of a skip reason.
     *
     * The engine stores skips as «code|argument» so as not to mix language
     * and logic; here they are translated into the project language.
     */
    public function skipLabel(string $key): string
    {
        [$code, $arg] = array_pad(explode('|', $key, 2), 2, '');
        return $this->ttText('skip_' . $code, $arg);
    }

    public function mappingDocument(): MappingDocument
    {
        return new MappingDocument($this);
    }

    public function transformer(): Transformer
    {
        return new Transformer($this);
    }

    public function exporter(): CsvExporter
    {
        return new CsvExporter($this);
    }

    // --------------------------------------------------------------- Utilities

    /**
     * Turns "1, Sí | 0, No" into ['1' => 'Sí', '0' => 'No'].
     * The implementation lives in DictionaryProfiler, which does not depend on
     * REDCap.
     */
    public static function parseChoices(string $raw): array
    {
        return DictionaryProfiler::parseChoices($raw);
    }

    /**
     * Log of module actions, with project context.
     */
    public function audit(string $message, array $params = []): void
    {
        $params['module_version'] = $this->getModuleVersion();
        $this->log($message, $params);
    }
}
