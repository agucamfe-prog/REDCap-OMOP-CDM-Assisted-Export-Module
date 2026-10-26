# Plan 013 — Stable identifiers

## Technical summary

A single class, `IdRegistry`, with two faces. As an object, it is the
persistent registry of entities: it loads the `omop-id-registry` setting, gives
the number for a key (or the next free one) and saves it at the end if there
were new numbers. As static functions, it assigns deterministic identifiers to
fact rows from a key that the `Transformer` puts on each row (`__key`).

The `Transformer` creates the registry at the start of each `run()`
(`$module->idRegistry($dryRun)`), uses it for persons, visits, care sites and
providers, assigns the fact ids after building the visits and calls `save()`
when it finishes.

## Files

| File | Action | Responsibility |
|---|---|---|
| `classes/IdRegistry.php` | Create | Entity registry and fact ids |
| `classes/Transformer.php` | Modify | Uses the registry; `__key` on every fact; `assignFactIds()` |
| `OmopCdmExportModule.php` | Use | `idRegistry(bool $dryRun = false)`, factory from spec 001 that builds `new IdRegistry($this, $dryRun)` |
| `classes/CsvExporter.php` | Modify | Identifiers section of the report |
| `lang/Spanish.ini`, `lang/English.ini` | Modify | `rep_ids_title`, `rep_ids` |
| `tests/specs/013_stable_identifiers.test.php` | Create | Tests for the spec |

## Design

### API

```php
namespace OmopCdm\OmopCdmExportModule;

class IdRegistry
{
    const SETTING_KEY = 'omop-id-registry';

    public function __construct($module, bool $dryRun = false);
    public function id(string $kind, string $key): int;     // existing or next free
    public function has(string $kind, string $key): bool;   // without creating
    public function save(): void;                           // only if dirty and not dryRun
    public function counts(): array;                        // ['person' => 3, 'visit' => 3, ...]

    public static function assignFactIds(array &$rows, string $idColumn): void;
    public static function hash31(string $key): int;
}
```

### Stored setting

```json
{
  "ids":  {"person": {"1": 1, "2": 2, "3": 3},
           "visit": {"1|1|0": 1, "2|1|0": 2, "3|1|0": 3},
           "care_site": {"site=1": 1, "site=2": 2}},
  "next": {"person": 4, "visit": 4, "care_site": 3},
  "updated": "2026-10-25 15:58:42"
}
```

The constructor accepts the value as a JSON string or as an array (some
framework versions return settings already decoded). If it is neither, it
starts empty.

### Entity kinds and keys

| Kind | Key | Who asks for it |
|---|---|---|
| `person` | Record ID | `Transformer::run()` |
| `visit` | `record\|event\|instance` | `Transformer::buildVisits()` |
| `visit_field` | `record\|event\|form\|instance\|field` | `emit()` (Visit field, spec 010) |
| `care_site` | `field=code` | `Transformer::entityId()` |
| `provider` | `field=code` | `Transformer::entityId()` |

### Key of a fact

`emit()` and `emitNote()` add:

```php
$row['__key'] = $table . '|' . $this->currentRowKey . '|' . $field . '|' . $option;
// currentRowKey = record|event|form|instance
// 'measurement|1|1|||weight|'          weight of record 1, main row
// 'drug_exposure|1|1|meds|1|drug|1'    drug, instance 1 of meds, option 1
```

`Transformer::assignFactIds()` calls `IdRegistry::assignFactIds()` for each
table in `ID_COLUMN` after `purgeDroppedPersons()` and `buildVisits()`.

### `assignFactIds` algorithm

1. Sort the row indexes by `strcmp($a['__key'], $b['__key'])` (into `$order`).
2. For each row in that order: `id = hash31(key)`; while `id` is already used
   (in `$used`), `id = id >= 2147483647 ? 1 : id + 1`.
3. Store `id` in `$idColumn` and remove `__key`.

`hash31`: `hexdec(substr(hash('sha256', $key), 0, 8)) & 0x7FFFFFFF`, and `1` if
it comes out as `0`.

### Other identifiers

- `observation_period_id = person_id` (`buildObservationPeriods()`).
- `DEATH` rows have no id of their own (their key is `person_id`).

## Decisions and discarded alternatives

- **Registry only for entities.** *Discarded:* also storing fact ids, which
  would make the setting grow with every row (hundreds of thousands) and slow
  down every load of the module.
- **Hash instead of a counter for facts.** A counter would depend on the
  emission order. *Discarded:* concatenating numbers (`person_id * 10^6 + n`),
  which overflows the DDL's `integer`.
- **31 bits.** The official DDL defines ids as `integer` (32-bit signed).
  *Discarded:* 63 bits, which do not fit.
- **Collisions by key order.** It is the only way for the result not to depend
  on the order of the rows. *Discarded:* resolving by order of arrival.
- **Do not reuse numbers.** *Discarded:* compacting gaps, which would make an
  old `person_id` point to another person.
- **The preview does not save.** *Discarded:* always saving, which used up
  numbers on every glance and left meaningless gaps.

## Testing strategy

`tests/specs/013_stable_identifiers.test.php`:

- Unit tests of `hash31` (formula, range over 2000 keys) and `assignFactIds`
  (order, `__key`, a pair of colliding keys found by brute force:
  `measurement|58494` and `measurement|71308` → 889893814).
- Unit tests of the registry on an empty `make_module()`: counters per kind,
  saved JSON, array, unreadable setting, `save()` with no changes, `dryRun`.
- Integration with the test project: real fact keys, registry after export,
  two exports (ids and `writeZip()` CSVs compared), a new record previewed and
  another deleted.
- The wrap from 2,147,483,647 back to 1 and the `0 → 1` of `hash31` cannot be
  triggered with real keys in reasonable time; they are reviewed in the code.

## Check against the constitution

- **III.11 Read-only on the project**: the registry lives in the module
  settings, not in the data.
- **IV.15 CDM v5.4**: ids within the DDL's `integer`.
- **V.20 Traceability**: every FR has a test.

## Risks

- **Deleting the module settings restarts the numbering.** Documented; the
  report says the ids are stable as long as the registry exists.
- **Chained collisions.** With millions of facts per table linear probing gets
  longer; at REDCap volumes it is irrelevant.
- **Concurrent writes from two exports.** Both load the same `next`; if both
  give numbers to different new keys, the same number may end up on two
  persons in two different ZIPs, and the last one to save wins (the other
  assignment is lost). This is accepted because exporting is a manual and
  infrequent action; the mitigation, if it were needed, is a lock (MySQL
  `GET_LOCK`) around `run()` and `save()`, with an amendment to this spec.
