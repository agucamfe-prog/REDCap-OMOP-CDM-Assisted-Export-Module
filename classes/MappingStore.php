<?php

namespace OmopCdm\OmopCdmExportModule;

require_once __DIR__ . '/TextNormalizer.php';
require_once __DIR__ . '/DictionaryProfiler.php';
require_once __DIR__ . '/CdmModel.php';

/**
 * Persistence of a project's mapping.
 *
 * It lives in the module's project settings, which REDCap already stores:
 * installing the module creates no table. All I/O goes through here so that
 * switching storage in the future only affects this file.
 *
 * Rules the rest of the module takes for granted:
 *
 *   - Nothing is born approved. The initial state of every field is 'proposed',
 *     which is the classifier's suggestion and does not count as a decision.
 *   - Only what is 'approved' is exported. Fail closed.
 *   - Each approval stores a fingerprint of the field's metadata. If someone
 *     later edits the REDCap data dictionary, the fingerprint stops matching
 *     and the field goes back to 'review' on its own. This is the main source
 *     of silent errors in this kind of tool.
 *   - Approval is withdrawn by what CHANGES the content, not by what touches
 *     it. Leaving a box without modifying it is not a new decision.
 */
class MappingStore
{
    const SETTING_KEY    = 'omop-mapping-json';
    const BACKUP_KEY     = 'omop-mapping-backup';
    const SCHEMA_VERSION = 3;

    const S_PROPOSED = 'proposed';
    const S_APPROVED = 'approved';
    const S_EXCLUDED = 'excluded';
    const S_REVIEW   = 'review';
    /** Derived state, never stored: the field is consumed by the configuration. */
    const S_CONFIG   = 'config';

    /**
     * Value strategies of a categorical field.
     *
     *   per_choice     each option is a distinct fact with its own concept
     *                  (comorbidities, drugs in a dropdown).
     *   value_concept  a single concept for the question and one concept per
     *                  option as the VALUE: "Smear microscopy" = "Positive". It
     *                  is how OMOP models a categorical result (value_as_concept_id).
     *   single_concept a single concept and the option's number as the value;
     *                  it only makes sense for scales ("pain from 0 to 10").
     */
    const ST_PER_CHOICE    = 'per_choice';
    const ST_VALUE_CONCEPT = 'value_concept';
    const ST_SINGLE        = 'single_concept';

    public static function states(): array
    {
        return [self::S_PROPOSED, self::S_APPROVED, self::S_EXCLUDED, self::S_REVIEW];
    }

    public static function strategies(): array
    {
        return [self::ST_PER_CHOICE, self::ST_VALUE_CONCEPT, self::ST_SINGLE];
    }

    /** Does this strategy decide anything per option? */
    public static function usesOptions(?string $strategy): bool
    {
        return $strategy === self::ST_PER_CHOICE || $strategy === self::ST_VALUE_CONCEPT;
    }

    private $module;
    private $projectId;
    private $cache = null;

    public function __construct($module, int $projectId)
    {
        $this->module    = $module;
        $this->projectId = $projectId;
    }

    // ------------------------------------------------------------ Reading

    public function load(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }
        $raw = $this->module->getProjectSetting(self::SETTING_KEY, $this->projectId);
        if (empty($raw)) {
            return $this->cache = $this->emptyMapping();
        }
        // If the setting arrives already decoded (json type, project copy,
        // another framework version) it is accepted as is: a fatal error here
        // takes down every page of the module.
        $decoded = is_array($raw) ? $raw : json_decode((string) $raw, true);
        if (!is_array($decoded) || !isset($decoded['fields'])) {
            return $this->cache = $this->emptyMapping();
        }
        $decoded['meta']   = is_array($decoded['meta'] ?? null) ? $decoded['meta'] : [];
        $decoded['fields'] = is_array($decoded['fields']) ? $decoded['fields'] : [];
        $decoded['values'] = is_array($decoded['values'] ?? null) ? $decoded['values'] : [];

        // An entry that is not an object (setting edited by hand, truncated
        // copy) cannot be read as a specification: it is ignored and recorded.
        // Without this, a single broken field took down the whole editor.
        // The next save removes it from the setting.
        $dropped = [];
        foreach ($decoded['fields'] as $name => $spec) {
            if (!is_array($spec)) {
                unset($decoded['fields'][$name]);
                $dropped[] = (string) $name;
            }
        }
        foreach ($decoded['values'] as $name => $options) {
            if (!is_array($options)) {
                unset($decoded['values'][$name]);
                $dropped[] = (string) $name;
                continue;
            }
            foreach ($options as $raw => $v) {
                if (!is_array($v)) {
                    unset($decoded['values'][$name][$raw]);
                    $dropped[] = $name . '=' . $raw;
                }
            }
        }
        if ($dropped && method_exists($this->module, 'log')) {
            $this->module->log('Corrupt entries ignored when reading the mapping', [
                'entries' => implode(',', array_slice($dropped, 0, 50)),
                'count'   => count($dropped),
            ]);
        }
        return $this->cache = $decoded;
    }

    public function emptyMapping(): array
    {
        return [
            'meta' => [
                'schema_version' => self::SCHEMA_VERSION,
                'project_id'     => $this->projectId,
                'created'        => date('Y-m-d H:i:s'),
                'updated'        => null,
                'updated_by'     => null,
            ],
            'fields' => [],
            'values' => [],
        ];
    }

    /**
     * Specification of one option of a categorical field.
     *
     * 'emit' is the key decision: in a diagnostic yes/no, the "No" must NOT
     * generate a row. In OMOP absence is represented by the absence of a
     * record, not by a record saying no. Emitting the "No" would produce
     * inflated cohorts in any later analysis.
     */
    public static function blankValueSpec(string $raw, string $label, bool $negative = false): array
    {
        return [
            'raw_value'    => $raw,
            'source_value' => $label,
            // The negative option of a binary field does not emit by default:
            // that way the correct thing is what needs no touching.
            'emit'         => !$negative,
            'concept_id'   => null,
            'concept_name' => null,
        ];
    }

    /** Specification of an option, with the default value it gets. */
    public function valueSpec(string $field, string $raw, string $label, array $dictMeta): array
    {
        $values = $this->values($field);
        if (isset($values[$raw])) {
            return $values[$raw];
        }
        return self::blankValueSpec($raw, $label,
            DictionaryProfiler::isNegativeChoice($dictMeta, $raw));
    }

    /**
     * Persists the options the curator never touched, with their default
     * values. Without this, the transformer would find undecided options and
     * silently discard that data.
     */
    public function materialiseValues(string $field, array $choices, array $dictMeta): void
    {
        $mapping = $this->load();
        $changed = false;
        foreach ($choices as $raw => $label) {
            $key = (string) $raw;
            if (!isset($mapping['values'][$field][$key])) {
                $mapping['values'][$field][$key] = self::blankValueSpec(
                    $key, (string) $label, DictionaryProfiler::isNegativeChoice($dictMeta, $key));
                $changed = true;
            }
        }
        if ($changed) {
            $this->save($mapping);
        }
    }

    /**
     * Specification of a field, with the default values it takes while it has
     * not been touched yet.
     */
    public static function blankSpec(): array
    {
        return [
            'state'             => self::S_PROPOSED,
            'domain'            => null,
            'concept_id'        => null,
            'concept_name'      => null,
            'value_strategy'    => null,   // per_choice | value_concept | single_concept
            'date_field'        => null,
            // The curator deliberately chose "use the default date". Without
            // this flag, an empty date_field cannot be told apart from one
            // nobody has looked at, and the proposal refilled it on every save.
            'date_default'      => false,
            // End date of the fact (treatments, resolved diagnoses...).
            'end_date_field'    => null,
            'unit_source_value' => null,
            'notes'             => '',
            // Someone saved something in this field. From then on what is
            // stored prevails and proposals are not applied again.
            'edited'            => false,
            'dict_hash'         => null,
            'approved_by'       => null,
            'approved_at'       => null,
            'updated_by'        => null,
            'updated_at'        => null,
        ];
    }

    public function spec(string $field): array
    {
        $mapping = $this->load();
        $spec = array_merge(self::blankSpec(), $mapping['fields'][$field] ?? []);
        // Mappings saved before the flag existed: any stored decision counts
        // as an edit, or the editor would show them the proposal instead of
        // what is stored.
        if (!$spec['edited'] && ($spec['domain'] !== null || $spec['concept_id'] !== null
                || $spec['date_field'] !== null || $spec['value_strategy'] !== null)) {
            $spec['edited'] = true;
        }
        return $spec;
    }

    // ------------------------------------------------------------ Writing

    /**
     * Cleans a concept_id pasted from a web table.
     *
     * Copying from Athena drags along spaces, tabs and, very often,
     * non-breaking spaces (U+00A0) that are invisible but break any numeric
     * validation. They are all removed before checking the format; dots and
     * hyphens are left alone, because they are exactly what tells a
     * vocabulary code apart from a concept_id.
     */
    public static function cleanConceptId($value): string
    {
        $text = (string) $value;
        // Unicode spaces common when copying from HTML, plus control ones.
        $text = preg_replace('/[\x{00A0}\x{2000}-\x{200D}\x{202F}\x{205F}\x{3000}\x{FEFF}]/u', '', $text);
        return trim($text);
    }

    /**
     * Validates a concept_id typed by the curator.
     *
     * @return array{0: ?int, 1: ?string} [value, error code]
     */
    private static function parseConceptId($value): array
    {
        if ($value === null) {
            return [null, null];
        }
        $value = self::cleanConceptId($value);
        if ($value === '') {
            return [null, null];
        }
        if (preg_match('/^\d+$/', $value)) {
            return [(int) $value, null];
        }
        // A negative number is not an Athena CODE: it deserves its own message.
        return [null, preg_match('/^-\d+$/', $value) ? 'negative' : 'format'];
    }

    /**
     * Saves the changes to a field. Returns the resulting specification.
     *
     * Any content change on an approved field sends it back to 'proposed':
     * approving is a decision about a specific content, not a permanent mark
     * on the field's name.
     */
    public function updateField(string $field, array $changes, ?array $dictMeta = null,
                                ?array $choices = null): array
    {
        $mapping = $this->load();
        $before  = array_merge(self::blankSpec(), $mapping['fields'][$field] ?? []);
        $after   = $before;

        $editable = ['domain', 'concept_id', 'concept_name', 'value_strategy',
                     'date_field', 'end_date_field', 'unit_source_value', 'notes'];
        $contentChanged = false;

        foreach ($editable as $key) {
            if (!array_key_exists($key, $changes)) {
                continue;
            }
            $value = $changes[$key];
            if (is_string($value)) {
                $value = trim($value);
                if ($value === '') {
                    $value = null;
                }
            }
            if ($key === 'domain' && $value !== null
                && !in_array($value, CdmModel::validDomains(), true)) {
                continue;   // a made-up domain is not saved
            }
            if ($key === 'value_strategy' && $value !== null
                && !in_array($value, self::strategies(), true)) {
                continue;
            }
            if ($key === 'date_field') {
                // Deliberately empty = "use the default date".
                $after['date_default'] = ($value === null);
            }
            if ($key === 'concept_id') {
                [$id, $err] = self::parseConceptId($value);
                // Athena shows two similar columns: ID (the concept_id) and
                // CODE (the vocabulary code, such as '2089-1'). Pasting the
                // code is the most common mistake, so it is detected instead
                // of being turned into a meaningless integer.
                if ($err !== null) {
                    $after['_bad_concept'] = self::cleanConceptId($value);
                    $after['_bad_kind']    = $err;
                    continue;
                }
                // A valid value, or an empty one, clears the error flag:
                // otherwise the field would stay blocked after fixing the typo.
                unset($after['_bad_concept'], $after['_bad_kind']);
                $value = $id;
            }
            if ($after[$key] !== $value) {
                $after[$key] = $value;
                $contentChanged = true;
            }
        }

        if ($contentChanged && $before['state'] === self::S_APPROVED) {
            $after['state']       = self::S_PROPOSED;
            $after['approved_by'] = null;
            $after['approved_at'] = null;
        }

        if (isset($changes['state']) && in_array($changes['state'], self::states(), true)) {
            // Save before checking: readyToApprove reads from the store, so it
            // needs to see the changes of this very call.
            if ($changes['state'] === self::S_APPROVED) {
                $after['edited'] = true;
                $mapping['fields'][$field] = $after;
                $this->save($mapping);
                $check = $this->readyToApprove($field, $choices ?? [], $dictMeta ?? []);
                if ($check['ok'] && !empty($choices) && self::usesOptions($after['value_strategy'])) {
                    $this->materialiseValues($field, $choices, $dictMeta ?? []);
                }
                if (!$check['ok']) {
                    $after['_error']     = $check['reason'];
                    $after['_pending']   = $check['pending'];
                    $after['_bad_value'] = $check['value'] ?? '';
                    return $after;
                }
                $mapping = $this->load();
            }

            $after['state'] = $changes['state'];
            if ($changes['state'] === self::S_APPROVED) {
                // Whoever approves has seen and confirmed the warning about
                // differing labels in an imported mapping: it is no longer pending.
                unset($after['import_diff']);
                $after['approved_by'] = self::user();
                $after['approved_at'] = date('Y-m-d H:i:s');
                $after['dict_hash']   = $dictMeta !== null ? self::dictHash($dictMeta) : $after['dict_hash'];
            } else {
                $after['approved_by'] = null;
                $after['approved_at'] = null;
            }
        }

        $after['edited']     = true;
        $after['updated_by'] = self::user();
        $after['updated_at'] = date('Y-m-d H:i:s');

        $mapping['fields'][$field] = $after;
        $this->save($mapping);

        if ($before['state'] !== $after['state']) {
            $this->module->log('Mapping state change', [
                'field'      => $field,
                'from_state' => $before['state'],
                'to_state'   => $after['state'],
                'concept_id' => $after['concept_id'],
            ]);
        }

        return $after;
    }

    /** Options already decided for a categorical field. */
    public function values(string $field): array
    {
        $mapping = $this->load();
        return $mapping['values'][$field] ?? [];
    }

    /**
     * Saves the decision on one specific option.
     *
     * Changing an option sends the field back to 'proposed' if it was
     * approved: the approval covered a set of decisions that has just changed.
     * But only if something changes: the editor saves on blur, and entering and
     * leaving a box without touching it used to withdraw approvals unnoticed.
     *
     * @return array{value: array, changed: bool, state: string}
     */
    public function updateValue(string $field, string $rawValue, array $changes, string $label = '',
                                array $dictMeta = []): array
    {
        $mapping = $this->load();
        $current = $mapping['values'][$field][$rawValue]
            ?? self::blankValueSpec($rawValue, $label,
                   DictionaryProfiler::isNegativeChoice($dictMeta, $rawValue));
        $before  = $current;

        foreach (['emit', 'concept_id', 'concept_name', 'source_value'] as $key) {
            if (!array_key_exists($key, $changes)) {
                continue;
            }
            $value = $changes[$key];
            if ($key === 'emit') {
                $value = filter_var($value, FILTER_VALIDATE_BOOLEAN);
            } elseif ($key === 'concept_id') {
                [$id, $err] = self::parseConceptId($value);
                if ($err !== null) {
                    $current['_bad_concept'] = self::cleanConceptId($value);
                    $current['_bad_kind']    = $err;
                    continue;
                }
                unset($current['_bad_concept'], $current['_bad_kind']);
                $value = $id;
            } elseif (is_string($value)) {
                $value = trim($value);
                if ($value === '') {
                    $value = null;
                }
            }
            $current[$key] = $value;
        }

        $changed = $current != $before || !isset($mapping['values'][$field][$rawValue]);
        $spec = array_merge(self::blankSpec(), $mapping['fields'][$field] ?? []);

        if ($changed) {
            $mapping['values'][$field][$rawValue] = $current;
            $contentChanged = $current != $before;
            if ($contentChanged && $spec['state'] === self::S_APPROVED) {
                $spec['state']       = self::S_PROPOSED;
                $spec['approved_by'] = null;
                $spec['approved_at'] = null;
            }
            $spec['edited'] = true;
            $mapping['fields'][$field] = $spec;
            $this->save($mapping);
        }

        return ['value' => $current, 'changed' => $changed, 'state' => $spec['state']];
    }

    /**
     * Can this field be approved?
     *
     * A categorical field that decides per option is not ready while any
     * option that will emit a row still has no concept: approving it would
     * generate rows with a null concept_id, which the CDM does not allow.
     *
     * DEATH is the exception: its concept is the CAUSE of death, which is
     * optional in the CDM. An "Exitus yes/no" is approved without a concept;
     * what matters is which option says the person died.
     *
     * @return array{ok: bool, reason: string|null, pending: string[]}
     */
    public function readyToApprove(string $field, array $choices, array $dictMeta = []): array
    {
        $spec = $this->spec($field);

        if ($spec['domain'] === null) {
            return ['ok' => false, 'reason' => 'no_domain', 'pending' => []];
        }

        if (!empty($spec['_bad_concept'])) {
            return ['ok' => false,
                    'reason' => ($spec['_bad_kind'] ?? '') === 'negative' ? 'concept_negative' : 'bad_concept_format',
                    'pending' => [], 'value' => $spec['_bad_concept']];
        }

        $isDeath  = $spec['domain'] === 'Death';
        $strategy = $spec['value_strategy'];
        $usesChoices = !empty($choices) && self::usesOptions($strategy);

        // value_concept also needs the question's concept.
        if ($usesChoices && $strategy === self::ST_VALUE_CONCEPT && empty($spec['concept_id'])) {
            return ['ok' => false, 'reason' => 'no_concept', 'pending' => []];
        }

        if (!$usesChoices) {
            if ($isDeath) {
                return ['ok' => true, 'reason' => null, 'pending' => []];
            }
            return empty($spec['concept_id'])
                ? ['ok' => false, 'reason' => 'no_concept', 'pending' => []]
                : ['ok' => true, 'reason' => null, 'pending' => []];
        }

        $values  = $this->values($field);
        $pending = [];
        $emitting = 0;
        foreach ($choices as $raw => $label) {
            $v = $values[(string) $raw]
                 ?? self::blankValueSpec((string) $raw, (string) $label,
                        DictionaryProfiler::isNegativeChoice($dictMeta, (string) $raw));
            if (!$v['emit']) {
                continue;
            }
            $emitting++;
            if (!empty($v['_bad_concept'])) {
                return ['ok' => false,
                        'reason' => ($v['_bad_kind'] ?? '') === 'negative' ? 'concept_negative' : 'bad_concept_format',
                        'pending' => [], 'value' => $v['_bad_concept']];
            }
            if (empty($v['concept_id']) && !$isDeath) {
                $pending[] = (string) $raw;
            }
        }

        if ($emitting === 0) {
            return ['ok' => false, 'reason' => 'no_emitting_choice', 'pending' => []];
        }
        if ($pending) {
            return ['ok' => false, 'reason' => 'choices_pending', 'pending' => $pending,
                    'value' => implode(', ', $pending)];
        }
        return ['ok' => true, 'reason' => null, 'pending' => []];
    }

    /**
     * Sets a field back to what the classifier proposes today.
     *
     * The saved mapping always wins over the proposal, and so it should: if
     * the classifier overwrote curated work, an improvement of its rules would
     * erase work without warning. But then an explicit way of saying "look at
     * it again" is needed, for example when a new version classifies better or
     * when someone rewrites the field's label in the Designer.
     *
     * Everything that took work —concepts, unit, notes— is kept and the
     * domain is restored. The field goes back to 'proposed': the target
     * changed, so the previous approval no longer covers what is there now.
     *
     * @return array{spec: array, changed: bool, from: string|null, to: string|null}
     */
    public function resetToProposal(string $field, string $proposedDomain): array
    {
        $mapping = $this->load();
        $spec    = array_merge(self::blankSpec(), $mapping['fields'][$field] ?? []);
        $before  = $spec['domain'];

        if ($before === $proposedDomain) {
            return ['spec' => $spec, 'changed' => false,
                    'from' => $before, 'to' => $proposedDomain];
        }

        $spec['domain']      = $proposedDomain;
        $spec['state']       = self::S_PROPOSED;
        $spec['approved_by'] = null;
        $spec['approved_at'] = null;
        $spec['updated_by']  = self::user();
        $spec['updated_at']  = date('Y-m-d H:i:s');
        unset($spec['_bad_concept'], $spec['_bad_kind']);

        $mapping['fields'][$field] = $spec;
        $this->save($mapping);

        $this->module->log('Field reset to the classifier proposal', [
            'field' => $field, 'from_domain' => $before, 'to_domain' => $proposedDomain,
        ]);

        return ['spec' => $spec, 'changed' => true,
                'from' => $before, 'to' => $proposedDomain];
    }

    /**
     * Takes what the field's @OMOP annotation says as the proposal.
     *
     * It stays 'proposed' (or 'excluded' if the annotation excludes it): an
     * annotation was written by someone, perhaps in another project; approving
     * it here is still a decision of this project.
     *
     * @param array $parsed output of MappingAnnotation::parse()
     * @param array $choices the field's options, to discard those that do not exist
     */
    public function adoptAnnotation(string $field, array $parsed, array $choices, array $dictionary): bool
    {
        $mapping = $this->load();
        $spec = array_merge(self::blankSpec(), $mapping['fields'][$field] ?? []);
        if ($spec['state'] === self::S_APPROVED) {
            return false;
        }
        foreach ($parsed['spec'] as $k => $v) {
            if ($k === 'domain' && !in_array($v, CdmModel::validDomains(), true)) {
                continue;
            }
            if (($k === 'date_field' || $k === 'end_date_field') && $v !== null && !isset($dictionary[$v])) {
                continue;   // another project's date field does not exist here
            }
            if ($k === 'value_strategy' && !in_array($v, self::strategies(), true)) {
                continue;
            }
            $spec[$k] = $v;
        }
        if (array_key_exists('date_field', $parsed['spec']) && $parsed['spec']['date_field'] === null) {
            $spec['date_default'] = true;
        }
        $spec['state']      = $parsed['exclude'] ? self::S_EXCLUDED : self::S_PROPOSED;
        $spec['edited']     = true;
        $spec['source']     = 'annotation';
        $spec['updated_by'] = self::user();
        $spec['updated_at'] = date('Y-m-d H:i:s');
        $mapping['fields'][$field] = $spec;
        foreach ($parsed['values'] as $raw => $v) {
            if (!array_key_exists((string) $raw, $choices)) {
                continue;
            }
            $mapping['values'][$field][(string) $raw] = $v + ['source_value' => (string) $choices[$raw]];
        }
        $this->save($mapping);
        return true;
    }

    /**
     * Imports the mapping of another project (its MAPEO.json).
     *
     * Only fields that exist here with the SAME type and the SAME options: if
     * "1" means "Male" there and "Female" here, copying the concept would lie.
     * Everything arrives as a proposal; nothing approved here is touched.
     *
     * @return array{imported: string[], skipped: array<string,string>}
     */
    public function importMapping(array $doc, array $profile, array $dictionary): array
    {
        $out = ['imported' => [], 'skipped' => []];
        $mapping = $this->load();
        $norm = fn($s) => TextNormalizer::normalize((string) $s);

        foreach (($doc['fields'] ?? []) as $name => $f) {
            // A MAPEO.json edited by hand or from another tool may bring
            // entries that are not objects or options without a code: they are
            // skipped with their reason and the rest is imported.
            $malformed = !is_array($f) || !is_array($f['choices'] ?? []);
            foreach ((is_array($f) && is_array($f['choices'] ?? null)) ? $f['choices'] : [] as $o) {
                $malformed = $malformed || !is_array($o) || !isset($o['raw_value']) || !is_scalar($o['raw_value']);
            }
            if ($malformed) {
                $out['skipped'][(string) $name] = 'invalid';
                continue;
            }
            if (!isset($profile[$name])) {
                $out['skipped'][$name] = 'missing';
                continue;
            }
            $here = array_merge(self::blankSpec(), $mapping['fields'][$name] ?? []);
            if ($here['state'] === self::S_APPROVED) {
                $out['skipped'][$name] = 'approved_here';
                continue;
            }
            if (($f['field_type'] ?? '') !== ($profile[$name]['field_type'] ?? '')) {
                $out['skipped'][$name] = 'type';
                continue;
            }
            $theirs = [];
            foreach ($f['choices'] ?? [] as $o) {
                $theirs[(string) $o['raw_value']] = $norm($o['label'] ?? '');
            }
            $ours = array_map($norm, $profile[$name]['choices'] ?? []);
            // The CODES have to match: without that no correspondence is
            // possible. The LABELS may legitimately differ —the same
            // instrument translated in a multicentre study—, so that does not
            // prevent importing, but it is flagged and must be confirmed.
            $theirCodes = array_map('strval', array_keys($theirs));
            $ourCodes   = array_map('strval', array_keys($ours));
            sort($theirCodes);
            sort($ourCodes);
            if ($theirCodes !== $ourCodes) {
                $out['skipped'][$name] = 'choices';
                continue;
            }
            $differing = [];
            foreach ($ours as $code => $label) {
                if (($theirs[(string) $code] ?? null) !== $label) {
                    $differing[] = (string) $code;
                }
            }
            unset($here['import_diff']);
            if ($differing) {
                $here['import_diff'] = $differing;
            }
            // Excluding is a decision too, and it travels even without a domain.
            if (($f['state'] ?? '') === self::S_EXCLUDED) {
                $here['state']      = self::S_EXCLUDED;
                $here['edited']     = true;
                $here['source']     = 'import';
                $here['updated_by'] = self::user();
                $here['updated_at'] = date('Y-m-d H:i:s');
                $mapping['fields'][$name] = $here;
                $out['imported'][] = $name;
                continue;
            }
            if (empty($f['domain']) || !in_array($f['domain'], CdmModel::validDomains(), true)) {
                $out['skipped'][$name] = 'no_mapping';
                continue;
            }
            foreach (['domain', 'concept_id', 'concept_name', 'value_strategy', 'unit_source_value', 'notes'] as $k) {
                if (array_key_exists($k, $f)) {
                    $here[$k] = $f[$k];
                }
            }
            foreach (['date_field', 'end_date_field'] as $k) {
                $here[$k] = (!empty($f[$k]) && isset($dictionary[$f[$k]])) ? $f[$k] : null;
            }
            $here['state']      = ($f['state'] ?? '') === self::S_EXCLUDED ? self::S_EXCLUDED : self::S_PROPOSED;
            $here['edited']     = true;
            $here['source']     = 'import';
            $here['approved_by'] = null;
            $here['approved_at'] = null;
            $here['updated_by'] = self::user();
            $here['updated_at'] = date('Y-m-d H:i:s');
            $mapping['fields'][$name] = $here;
            foreach ($f['choices'] ?? [] as $o) {
                if ($o['emit'] === null) {
                    continue;
                }
                $mapping['values'][$name][(string) $o['raw_value']] = [
                    'raw_value'    => (string) $o['raw_value'],
                    'source_value' => (string) $o['label'],
                    'emit'         => (bool) $o['emit'],
                    'concept_id'   => $o['concept_id'] ?? null,
                    'concept_name' => $o['concept_name'] ?? null,
                ];
            }
            $out['imported'][] = $name;
        }
        if ($out['imported']) {
            $this->save($mapping);
            $this->module->log('Mapping imported from another project', [
                'imported' => count($out['imported']), 'skipped' => count($out['skipped']),
                'from_project' => $doc['project']['project_id'] ?? '',
            ]);
        }
        return $out;
    }

    /** Applies the same state change to many fields at once. */
    public function bulkState(array $fields, string $state, array $dictionary = []): int
    {
        if (!in_array($state, self::states(), true)) {
            return 0;
        }
        $mapping = $this->load();
        $n = 0;
        foreach ($fields as $field) {
            $spec = array_merge(self::blankSpec(), $mapping['fields'][$field] ?? []);
            if ($spec['state'] === $state) {
                continue;
            }
            $spec['state']      = $state;
            $spec['updated_by'] = self::user();
            $spec['updated_at'] = date('Y-m-d H:i:s');
            if ($state === self::S_APPROVED) {
                $spec['approved_by'] = self::user();
                $spec['approved_at'] = date('Y-m-d H:i:s');
                if (isset($dictionary[$field])) {
                    $spec['dict_hash'] = self::dictHash($dictionary[$field]);
                }
            } else {
                $spec['approved_by'] = null;
                $spec['approved_at'] = null;
            }
            $mapping['fields'][$field] = $spec;
            $n++;
        }
        $this->save($mapping);
        $this->module->log('Bulk state change', [
            'state' => $state, 'count' => $n,
            'fields' => implode(',', array_slice($fields, 0, 200)),
        ]);
        return $n;
    }

    public function save(array $mapping): void
    {
        $mapping['meta']['schema_version'] = self::SCHEMA_VERSION;
        $mapping['meta']['project_id']     = $this->projectId;
        $mapping['meta']['updated']        = date('Y-m-d H:i:s');
        $mapping['meta']['updated_by']     = self::user();

        $this->module->setProjectSetting(
            self::SETTING_KEY,
            json_encode($mapping, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $this->projectId
        );
        $this->cache = $mapping;

        // What is derived from the mapping (editor rows, warnings) is no longer valid.
        if (method_exists($this->module, 'invalidateMappingCache')) {
            $this->module->invalidateMappingCache();
        }
    }

    /**
     * Erases the whole mapping and returns the project to its initial state.
     *
     * It is the most destructive action of the module: pasted concepts,
     * per-option decisions, exclusions and approval signatures are lost.
     * Before erasing, a full copy is saved, which allows undoing the last
     * reset: an accidental click should not cost hours of curation.
     */
    public function reset(): array
    {
        $previous = $this->load();
        $summary = ['fields' => count($previous['fields'] ?? []), 'approved' => 0, 'excluded' => 0];
        foreach ($previous['fields'] ?? [] as $spec) {
            if (($spec['state'] ?? '') === self::S_APPROVED) {
                $summary['approved']++;
            }
            if (($spec['state'] ?? '') === self::S_EXCLUDED) {
                $summary['excluded']++;
            }
        }

        $this->module->setProjectSetting(self::BACKUP_KEY, json_encode([
            'saved_at' => date('Y-m-d H:i:s'),
            'saved_by' => self::user(),
            'mapping'  => $previous,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $this->projectId);

        $this->module->log('Mapping fully reset', $summary);
        $this->save($this->emptyMapping());
        return $summary;
    }

    /** Copy saved by the last reset, or null if there is none. */
    public function backupInfo(): ?array
    {
        $raw = $this->module->getProjectSetting(self::BACKUP_KEY, $this->projectId);
        $b = is_array($raw) ? $raw : json_decode((string) $raw, true);
        if (!is_array($b) || empty($b['mapping']['fields'])) {
            return null;
        }
        return ['saved_at' => $b['saved_at'] ?? '', 'saved_by' => $b['saved_by'] ?? '',
                'fields' => count($b['mapping']['fields']), 'mapping' => $b['mapping']];
    }

    /**
     * Undoes the last reset.
     *
     * Approvals are restored with their original signature; the dictionary
     * fingerprint is still in each field, so whatever has changed since then
     * will show up in 'review' as always.
     */
    public function restoreBackup(): ?int
    {
        $b = $this->backupInfo();
        if ($b === null) {
            return null;
        }
        $this->save($b['mapping']);
        $this->module->removeProjectSetting(self::BACKUP_KEY, $this->projectId);
        $this->module->log('Mapping restored from the reset backup', [
            'fields' => $b['fields'], 'backup_at' => $b['saved_at'],
        ]);
        return $b['fields'];
    }

    /** Is there no stored decision yet? */
    public function isEmpty(): bool
    {
        $mapping = $this->load();
        return empty($mapping['fields']) && empty($mapping['values']);
    }

    /** Summary of what a reset would lose, so it can be warned about beforehand. */
    public function resetImpact(): array
    {
        $mapping = $this->load();
        $r = ['fields' => 0, 'approved' => 0, 'excluded' => 0, 'concepts' => 0];
        foreach ($mapping['fields'] ?? [] as $spec) {
            $r['fields']++;
            $state = $spec['state'] ?? self::S_PROPOSED;
            if ($state === self::S_APPROVED) {
                $r['approved']++;
            } elseif ($state === self::S_EXCLUDED) {
                $r['excluded']++;
            }
            if (!empty($spec['concept_id'])) {
                $r['concepts']++;
            }
        }
        foreach ($mapping['values'] ?? [] as $options) {
            foreach ($options as $v) {
                if (!empty($v['concept_id'])) {
                    $r['concepts']++;
                }
            }
        }
        return $r;
    }

    // ------------------------------------------------------------ Fingerprint

    /**
     * Fingerprint of the field's metadata as it was when approved.
     * It changes if the type, the validation, the label or the options change.
     */
    public static function dictHash(array $dictMeta): string
    {
        return substr(md5(implode('|', [
            $dictMeta['field_type'] ?? '',
            $dictMeta['text_validation_type_or_show_slider_number'] ?? '',
            trim(strip_tags($dictMeta['field_label'] ?? '')),
            $dictMeta['select_choices_or_calculations'] ?? '',
        ])), 0, 16);
    }

    /**
     * Did the data dictionary change after this field was approved?
     *
     * It counts as a change that its metadata fingerprint no longer matches
     * and that its end-date field has disappeared from the dictionary: the
     * approval would no longer describe what is exported (the fact would be
     * exported without an end without anyone deciding it). A missing reference
     * date is checked separately by mappingRows(), because it has its own reason.
     *
     * @param array $dictionary the project's full data dictionary
     */
    public static function hasDrifted(array $spec, array $dictMeta, array $dictionary): bool
    {
        if (($spec['state'] ?? null) !== self::S_APPROVED) {
            return false;
        }
        if (!empty($spec['dict_hash']) && $spec['dict_hash'] !== self::dictHash($dictMeta)) {
            return true;
        }
        $end = $spec['end_date_field'] ?? null;
        return !empty($end) && !isset($dictionary[$end]);
    }

    private static function user(): string
    {
        return defined('USERID') ? (string) USERID : 'system';
    }
}
