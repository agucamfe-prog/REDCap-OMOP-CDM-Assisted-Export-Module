# Plan 006 — Mapping store and states

## Technical summary

`classes/MappingStore.php` encapsulates the mapping JSON stored in the project
setting `omop-mapping-json`: tolerant reading, validated writing, state and
signature rules, metadata fingerprint, reset to the proposal, reset with a
backup and bulk changes. All mapping I/O goes through here: changing storage in
the future would affect this file and no other.

The module adds the combined view `mappingRows()` (classifier proposal + stored
decision + effective state), `mappingCounts()`, `isApproved()` and
`configRole()`. The states `review` (drift) and `config` (consumed by the
configuration) are **derived** on read; they are never written, so fixing the
data dictionary or the configuration resolves them without touching the store.

## Files

| File | Action | Responsibility |
|---|---|---|
| `classes/MappingStore.php` | Create | Persistence, contract, states, fingerprint, reset, bulk |
| `OmopCdmExportModule.php` | Modify | `store()`, `mappingRows()`, `mappingCounts()`, `isApproved()`, `configRole()`, `personConfig()`, `deathConfig()`, `entityConfig()`, `invalidateMappingCache()`, `setting()` |
| `tests/specs/006_mapping_store.test.php` | Create | Tests for this spec |

## Design

### Stored JSON (schema 3)

```json
{
  "meta":   {"schema_version": 3, "project_id": 1, "created": "2026-03-01 10:00:00",
             "updated": "2026-03-02 12:30:00", "updated_by": "ana"},
  "fields": {
    "weight": {"state": "approved", "domain": "Measurement", "concept_id": 3025315,
               "concept_name": "Body weight", "value_strategy": null,
               "date_field": "visit_date", "date_default": false, "end_date_field": null,
               "unit_source_value": "kg", "notes": "", "edited": true,
               "dict_hash": "3f2a9c0d1e2b4a5c", "approved_by": "ana",
               "approved_at": "2026-03-02 12:30:00", "updated_by": "ana",
               "updated_at": "2026-03-02 12:30:00"}
  },
  "values": {
    "fever": {"1": {"raw_value": "1", "source_value": "Yes", "emit": true,  "concept_id": 437663, "concept_name": "Fever"},
              "0": {"raw_value": "0", "source_value": "No",  "emit": false, "concept_id": null,   "concept_name": null}}
  }
}
```

Transient flags that may appear in a field or an option:
`_bad_concept` / `_bad_kind` (malformed concept). `updateField()` also returns
`_error`, `_pending`, `_bad_value` when it cannot approve (they are not stored).

### Public API of `MappingStore`

```php
const SETTING_KEY = 'omop-mapping-json'; const BACKUP_KEY = 'omop-mapping-backup';
const SCHEMA_VERSION = 3;
const S_PROPOSED = 'proposed', S_APPROVED = 'approved', S_EXCLUDED = 'excluded',
      S_REVIEW = 'review', S_CONFIG = 'config';
const ST_PER_CHOICE = 'per_choice', ST_VALUE_CONCEPT = 'value_concept', ST_SINGLE = 'single_concept';

public function __construct($module, int $projectId)
public static function states(): array            // without 'config'
public static function strategies(): array
public static function usesOptions(?string $strategy): bool
public function load(): array                      // cached
public function emptyMapping(): array
public function save(array $mapping): void         // + invalidateMappingCache()
public static function blankSpec(): array
public static function blankValueSpec(string $raw, string $label, bool $negative = false): array
public function spec(string $field): array         // blankSpec + stored
public function values(string $field): array
public function valueSpec(string $field, string $raw, string $label, array $dictMeta): array
public function materialiseValues(string $field, array $choices, array $dictMeta): void
public static function cleanConceptId($value): string
public function updateField(string $field, array $changes, ?array $dictMeta = null, ?array $choices = null): array
public function updateValue(string $field, string $raw, array $changes, string $label = '', array $dictMeta = []): array
    // ['value' => array, 'changed' => bool, 'state' => string]
public function readyToApprove(string $field, array $choices, array $dictMeta = []): array
    // ['ok' => bool, 'reason' => ?string, 'pending' => string[], 'value' => string]
public function resetToProposal(string $field, string $proposedDomain): array
    // ['spec' => array, 'changed' => bool, 'from' => ?string, 'to' => ?string]
public function bulkState(array $fields, string $state, array $dictionary = []): int
public function reset(): array                     // ['fields', 'approved', 'excluded']
public function resetImpact(): array               // ['fields', 'approved', 'excluded', 'concepts']
public function backupInfo(): ?array               // ['saved_at', 'saved_by', 'fields', 'mapping']
public function restoreBackup(): ?int
public function isEmpty(): bool
public static function dictHash(array $dictMeta): string    // md5 of type|validation|label without HTML|options, 16 hex
public static function hasDrifted(array $spec, array $dictMeta, array $dictionary): bool
    // approved and (different fingerprint or end_date_field missing from the
    // data dictionary); without $dictionary it uses the module's from the last
    // store created in the request
```

`load()` ignores and logs (`Corrupt entries ignored when reading the mapping`)
the entries of `fields` or `values` that are not objects; since reading is
cached, the log entry is written once per request, and the next `save()` no
longer contains them.

### `updateField()` step by step

1. `$before = blankSpec() + stored`; `$after = $before`.
2. For each editable key present: trim; `''` → `null`; domain outside the list
   or unknown strategy → `continue`; `date_field` sets
   `date_default = ($value === null)`; `concept_id` goes through
   `parseConceptId()` (clean, `^\d+$` → int; `^-\d+$` → `negative`; other →
   `format`) and, if it is wrong, sets `_bad_*` and `continue`. If
   `$after[$key] !== $value`, assign and `contentChanged = true`.
3. If `contentChanged` and it was approved → `proposed`, signature to `null`.
4. If a valid `state` arrives: if it is `approved`, save first (because
   `readyToApprove()` reads from the store), check, materialise options and,
   if it is not ready, return with `_error`; if it is, sign with user, date
   and `dictHash($dictMeta)`. Any other state clears the signature.
5. `edited = true`, `updated_by`, `updated_at`; save; if the state changed,
   `log('Mapping state change', [...])`.

`updateValue()` compares the option before and after with `!=` and only saves
if something changed or the option did not exist; only a content change
withdraws the approval.

### Combined view in the module

```php
public function mappingRows(): array
// 'weight' => ['profile' => [...], 'spec' => [...], 'values' => [...],
//              'state' => 'review', 'drifted' => true, 'drift_reason' => 'dictionary', 'role' => null]
public function mappingCounts(): array
// ['total' => 18, 'proposed' => 14, 'approved' => 0, 'excluded' => 0, 'review' => 0, 'config' => 4]
public function isApproved(string $field): bool        // effective state === 'approved'
public function configRole(string $field): ?string     // person_id | birth | death_date | care_site | provider
```

Order in `mappingRows()`: drift by fingerprint or by missing end date
(`hasDrifted()`, reason `dictionary`) → drift by missing reference date →
`config` if `configRole()` is not `null` and the effective state is not
`approved`. `configRole()` takes `record_id_field` from
`getProjectStructure()`, birth from `birth-date-field` (or `birth-year-field`,
which is also `birth` when there is a date), `death-date-field`,
`care-site-field` and `provider-field`.

## Decisions and discarded alternatives

- **Project setting, not own tables.** The framework already stores them and
  copies them with the project. *Discarded:* own tables, which force migrating
  the REDCap schema on install.
- **"Real change", not "touched".** The editor saves when a control changes;
  comparing before and after avoids withdrawing approvals for entering and
  leaving a box. *Discarded:* withdrawing the approval on every save.
- **Derived `review` and `config`.** Storing them would force rewriting the
  mapping on read and would leave stale states if the data dictionary matches
  again.
- **Fingerprint of four metadata items** (type, validation, label without HTML,
  options). The field note, the branching logic or whether it is required do
  not change the meaning of the data. *Discarded:* fingerprint of the whole
  dictionary row, which would give false drifts.
- **A single backup.** Undoing the last reset covers the accidental click; a
  full version history is another product.
- **A malformed concept_id is flagged, not stored.** That way the previous
  concept is not lost and the reason can be explained (the Athena CODE).

## Test strategy

- `tests/specs/006_mapping_store.test.php`, with `make_module()` and `curate()`
  from `fixtures.php`. A closure `$fresh($settings)` creates a new module with
  the same settings: it simulates the next request (no caches).
- Drift: `\REDCap::$dict` is changed after `curate()` and read with `$fresh`;
  it is set back to `fixture_dict()` at the end.
- Log: `$m->logs` is filtered by message.
- NFR-006-01 with an anonymous subclass of the module that counts the calls to
  `getProjectSetting()`.
- Nothing to verify by hand in this spec.

## Constitution check

- **Principle 1:** `isApproved()` uses the effective state: `review` and `config` do not export.
- **Principle 2:** no method approves without `state = approved` (or `bulkState(approved)`, explicit).
- **Principle 3:** before/after comparison in `updateField()` and `updateValue()`.
- **Principle 4:** `edited` flag; `resetToProposal()` is the only way back to the proposal, and it is explicit.
- **Principle 11:** module settings only; test NFR-006-02.
- **Principle 22:** each obstacle to approving returns a translatable reason (`ed_err_<reason>`).

## Risks

- **Large settings:** a 2,000-field project generates a JSON of hundreds of
  kilobytes in a single setting. It is read once per request.
- **Concurrent writes:** two curators at the same time can overwrite each other
  (last to save wins). Acceptable for the size of the teams; every state change
  is logged.
- **`hasDrifted()` is static** and needs the full data dictionary to know
  whether the end date still exists: `mappingRows()` always passes it
  explicitly, with no state shared between stores.
