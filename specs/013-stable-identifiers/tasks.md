# Tasks 013 — Stable identifiers

Before you start: read `constitution.md`, `AGENTS.md`, and the `spec.md` and
`plan.md` in this folder. Do the tasks in order. Every `[T]` must end with the
test **red**; the following `[I]` turns it green.

Run only this spec's tests: `php tests/run.php 013`

| Legend | |
|---|---|
| `[T]` | Write a test (red) |
| `[I]` | Implement (green) |
| `[R]` | Refactor, document or translate |
| `[P]` | Can be done in parallel with the previous task |

---

### T013-01 [T] hash31

- **Requirements:** FR-013-08
- **Files:** `tests/specs/013_stable_identifiers.test.php`
- **What to do:** `use OmopCdm\OmopCdmExportModule\IdRegistry;`. Tests:
  `IdRegistry::hash31('a|b')` is the same on two calls and equals
  `hexdec(substr(hash('sha256', 'a|b'), 0, 8)) & 0x7FFFFFFF`; for
  `'k0'`…`'k1999'` it is always an `int` between 1 and 2147483647.
- **Done when:** the tests fail because the class has no `hash31`.
- **Size:** S

### T013-02 [I] hash31

- **Requirements:** FR-013-08
- **Files:** `classes/IdRegistry.php`
- **What to do:** `public static function hash31(string $key): int` with the
  formula from the plan; if it yields 0, return 1.
- **Hints:** `hexdec` of 8 hex digits fits in a 64-bit integer; the
  `& 0x7FFFFFFF` brings it down to 31 bits.
- **Done when:** `php tests/run.php 013` is green.
- **Size:** S

### T013-03 [T] Fact id assignment

- **Requirements:** FR-013-07, FR-013-09, FR-013-10
- **Files:** `tests/specs/013_stable_identifiers.test.php`
- **What to do:**
  1. `$rows = [['__key' => 'k1'], ['__key' => 'k2'], ['__key' => 'k3']]` and
     its `array_reverse`; after `IdRegistry::assignFactIds($rows, 'id')` the
     ids match in reverse order, `$rows[0]['id'] === hash31('k1')` and no row
     keeps `__key`.
  2. Collision: `'measurement|58494'` and `'measurement|71308'` (same hash,
     889893814) in both orders: the first one receives the hash and the
     second one the hash + 1.
- **Done when:** the tests fail for lack of `assignFactIds`.
- **Size:** S

### T013-04 [I] assignFactIds

- **Requirements:** FR-013-07, FR-013-09, FR-013-10
- **Files:** `classes/IdRegistry.php`
- **What to do:** `public static function assignFactIds(array &$rows, string $idColumn): void`
  with the algorithm from the plan (order by `strcmp`, linear probing that
  wraps around to 1, `unset($rows[$i]['__key'])`).
- **Hints:** sort a copy of the indexes (`array_keys`), not the rows: the
  order of the rows in the table must not change.
- **Done when:** `php tests/run.php 013` is green.
- **Size:** S

### T013-05 [T] Entity registry

- **Requirements:** FR-013-01, FR-013-03, FR-013-04, FR-013-05, FR-013-06, FR-013-14
- **Files:** `tests/specs/013_stable_identifiers.test.php`
- **What to do:** with `$mr = make_module(); $reg = new IdRegistry($mr);`:
  1. `id('person', 'A') === 1`, `id('person', 'B') === 2`, `id('person', 'A') === 1`.
  2. `id('visit', 'A|1|0')`, `id('care_site', 'site=1')` and
     `id('provider', 'doctor=1')` are 1 each.
  3. `has()` does not create; `counts()` gives `['person' => 2, 'visit' => 1, 'care_site' => 1, 'provider' => 1]`.
  4. Before `save()`, `$mr->settings[IdRegistry::SETTING_KEY]` does not exist;
     afterwards it is a JSON with `ids`, `next` (`person` = 3) and `updated`.
  5. Another instance reads what was saved; also as an array; `'{broken'` →
     empty.
  6. With no new numbers, `save()` does not change the setting; with
     `new IdRegistry($mr, true)`, `id()` returns a number but `save()` does not
     store it.
- **Done when:** the registry tests fail.
- **Size:** M

### T013-06 [I] IdRegistry as a persistent registry

- **Requirements:** FR-013-01, FR-013-03, FR-013-04, FR-013-05, FR-013-06, FR-013-14
- **Files:** `classes/IdRegistry.php`
- **What to do:** `const SETTING_KEY = 'omop-id-registry'`; a constructor that
  accepts an array or JSON and initialises `ids` and `next`; `id()` (sets
  `dirty`), `has()`, `counts()` (`array_map('count', ids)`), `save()` (nothing
  if `dryRun` or not `dirty`; adds `updated` and writes with
  `JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES`). Check that the module's
  `idRegistry(bool $dryRun = false)` factory (spec 001) passes `dryRun` to it.
- **Hints:** use the module's `getProjectSetting`/`setProjectSetting`, not
  `setting()`: the registry is read once per `run()` and is its own cache.
- **Done when:** `php tests/run.php 013` is green.
- **Size:** M

### T013-07 [T] Identifiers in the export

- **Requirements:** FR-013-01, FR-013-02, FR-013-03, FR-013-07, FR-013-11, FR-013-12, NFR-013-01
- **Files:** `tests/specs/013_stable_identifiers.test.php`
- **What to do:** with `make_module()` + `curate()`:
  1. `person_id` by Record ID is `['1' => 1, '2' => 2, '3' => 3]`; the saved
     registry has the visits `'1|1|0'`… and the care sites `'site=1'`,
     `'site=2'`, and no fact kind at all.
  2. Record 1's weight has `measurement_id === hash31('measurement|1|1|||weight|')`
     and the isoniazid `hash31('drug_exposure|1|1|meds|1|drug|1')`.
  3. No table has `__key`; `observation_period_id === person_id`.
  4. Second export: same `measurement_id` values.
  5. Add record 4, delete record 2 and export: 1 and 3 keep their number, 4
     receives 4, nobody has 2, record 3's facts keep their ids and the visits
     of 1, 3 and 4 are 1, 3 and 4.
- **Done when:** the fact key tests fail.
- **Size:** M

### T013-08 [I] Use the registry in the Transformer

- **Requirements:** FR-013-01, FR-013-02, FR-013-03, FR-013-07, FR-013-11, FR-013-12, NFR-013-01
- **Files:** `classes/Transformer.php`
- **What to do:**
  1. In `run()`: `$this->registry = $this->module->idRegistry($dryRun)` and
     `$personId = $this->registry->id('person', $recordId)`.
  2. `currentRowKey = record|event|form|instance` per row; `__key` in `emit()`
     and `emitNote()` as in the plan.
  3. `assignFactIds()` (private) after `buildVisits()`, with `ID_COLUMN`.
  4. Visits with `registry->id('visit', $key)`, entities with
     `registry->id($kind, "$field=$code")`, `observation_period_id = $personId`.
  5. `$this->registry->save()` at the end of `run()`.
- **Hints:** `buildVisits()` reads the provisional `visit_occurrence_id` of
  the facts; assign the fact ids afterwards, not before.
- **Done when:** `php tests/run.php 013` is green.
- **Size:** M

### T013-09 [T] Preview and identical exports

- **Requirements:** FR-013-05, FR-013-06, FR-013-13
- **Files:** `tests/specs/013_stable_identifiers.test.php`
- **What to do:**
  1. A second export does not change the registry setting.
  2. Closure `$csv($mod)`: `writeZip($mod->transformer()->run())`, opens the
     ZIP with `ZipArchive`, returns the `^[A-Z_]+\.csv$` files and deletes the
     ZIP. Two calls return the same thing (if `ZipArchive` is not available,
     compare the `json_encode` of the tables).
  3. With the new record 4, `run(null, true)` does not change the setting and
     gives it number 4, the same one it receives later when exporting.
- **Done when:** the preview test fails (if `run()` ignores `dryRun`).
- **Size:** S

### T013-10 [I] dryRun end to end

- **Requirements:** FR-013-05, FR-013-06, FR-013-13
- **Files:** `classes/Transformer.php`, `pages/export.php`
- **What to do:** `run(?int $limit = null, bool $dryRun = false)` passes
  `dryRun` to the registry; the export page previews with `run(20, true)`.
  Check that nothing in the tables depends on the time except `CDM_SOURCE`
  (today's dates) and that the order of the rows is the order of the data.
- **Done when:** `php tests/run.php 013` is green.
- **Size:** S

### T013-11 [R] Report and README

- **Requirements:** FR-013-14
- **Files:** `classes/CsvExporter.php`, `lang/Spanish.ini`, `lang/English.ini`, `README.md`
- **What to do:** the `rep_ids_title` / `rep_ids` section of the report in both
  languages; a "Stable identifiers" section in the README (what is stable,
  what is never reused, and that previewing does not consume numbers).
- **Done when:** both `.ini` files have the same keys and the suite stays green.
- **Size:** S

---

## Definition of done for the spec

- [ ] All `013` tests green and the rest of the suite without regressions.
- [ ] `php tests/run.php --trace 013` with no orphan requirements.
- [ ] New texts in `lang/Spanish.ini` and `lang/English.ini` with the same keys.
- [ ] README updated if anything visible to the user changes.
- [ ] Spec status changed to `Implemented`.
