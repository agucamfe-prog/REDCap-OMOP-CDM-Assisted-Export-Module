# Tasks 006 — Mapping store and states

Before starting: read `constitution.md`, `AGENTS.md`, and the `spec.md` and
`plan.md` in this folder. Do the tasks in order. Each `[T]` must end with the
test **red**; the following `[I]` turns it green.

Run only this spec's tests: `php tests/run.php 006`

| Legend | |
|---|---|
| `[T]` | Write a test (red) |
| `[I]` | Implement (green) |
| `[R]` | Refactor, document or translate |
| `[P]` | Can be done in parallel with the previous one |

---

### T006-01 [T] Persistence and contract

- **Requirements:** FR-006-01, FR-006-02, FR-006-03, NFR-006-01
- **Files:** `tests/specs/006_mapping_store.test.php`
- **What to do:** create the file with `$m = make_module(); $s = $m->store();`
  and a closure `$fresh = function (array $settings) { $x = new OmopCdmExportModule(); $x->settings = $settings; return $x; };`.
  Check: empty mapping with `schema_version` 3; after
  `$s->updateField('weight', ['domain' => 'Measurement'])`, the setting
  `omop-mapping-json` is a JSON with `meta.updated`, `fields.weight` and
  `values`; `'{broken'` and `'{"meta":{}}'` are read as empty; an already decoded
  array is accepted. A setting with `"weight": "broken"` in `fields`,
  `"bac": "x"` in `values` and the option `"1": "y"` in `fever`: `spec()`,
  `values()` and `mappingRows()` throw nothing (`throws()`), `weight` comes out
  unsaved, `glu` keeps its domain, and the log has a single entry
  `Corrupt entries ignored when reading the mapping` with
  `entries = 'weight,bac,fever=1'` and `count = 3`.
  `array_keys(MappingStore::blankSpec())` in the order of the plan;
  `blankValueSpec('1', 'Yes')` with its five keys;
  `valueSpec('fever', '0', 'No', $dict['fever'])['emit'] === false`;
  `states()` and `strategies()`; `state = 'config'` or `'banana'` are not stored.
  NFR-006-01 with an anonymous class that counts `getProjectSetting()`: four
  `spec()` and `values()` calls read the setting once.
- **Done when:** it fails with "Class MappingStore not found" (or `store()` does not exist).
- **Size:** M

### T006-02 [I] `MappingStore`: reading, contract and saving

- **Requirements:** FR-006-01, FR-006-02, FR-006-03, NFR-006-01
- **Files:** `classes/MappingStore.php`, `OmopCdmExportModule.php`
- **What to do:** the plan's constants; `states()`, `strategies()`,
  `usesOptions()`; `load()` with a cache (`$this->cache`), tolerant
  (`is_array($raw) ? $raw : json_decode(...)`; without `fields` →
  `emptyMapping()`); `save()` that completes `meta` and writes with
  `JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES`; `blankSpec()`,
  `blankValueSpec()`, `spec()`, `values()`, `valueSpec()`, `isEmpty()`. In the
  module, `store()` with a single instance per request.
  In `load()`, remove the entries of `fields` and `values` (and the options)
  that are not arrays and log them with `$this->module->log(...)`.
- **Hints:** a fatal error in `load()` takes down every page of the module:
  never throw an exception because of an odd setting. An `array_merge()` with a
  string throws `TypeError` in PHP 8: that is why filtering is needed before
  `spec()`. The user comes from the `USERID` constant if it exists; otherwise
  `system`.
- **Done when:** the tests of T006-01 that do not depend on `updateField()` are green.
- **Size:** M

### T006-03 [T] Write, approve and withdraw the approval

- **Requirements:** FR-006-04, FR-006-05, FR-006-07, FR-006-12
- **Files:** `tests/specs/006_mapping_store.test.php`
- **What to do:** saving domain, concept `'3025315'`, name, unit `kg` and date
  `visit_date` in `weight` leaves it `proposed` without a signature; approving
  with `updateField('weight', ['state' => 'approved'], $dict['weight'])` signs
  (`approved_by === 'system'`, `approved_at` in date-time format) and stores
  `dict_hash === MappingStore::dictHash($dict['weight'])`. After `curate($m)`:
  resending the "Yes" option of `fever` unchanged → still approved and
  `changed === false`; resending `weight` with `' 3025315 '`, `kg` and
  `visit_date` → still approved; changing the option's name to `Fever!` or the
  unit to `g` → `proposed`, without a signature. Log: editing without changing
  state adds nothing to `$m->logs`; approving `notes` adds
  `['field' => 'notes', 'from_state' => 'proposed', 'to_state' => 'approved', 'concept_id' => 44814637]`.
- **Done when:** the tests fail because `updateField()` is missing.
- **Size:** M

### T006-04 [I] `updateField()` and `updateValue()`

- **Requirements:** FR-006-04, FR-006-05, FR-006-07, FR-006-12
- **Files:** `classes/MappingStore.php`
- **What to do:** implement both methods according to the plan (steps 1–5). In
  `updateValue()`, `emit` with `filter_var(..., FILTER_VALIDATE_BOOLEAN)`,
  `changed = $current != $before || !isset(stored)`, and only a content change
  withdraws the approval. Add `materialiseValues()` and call it when approving
  a field with options and a per-option strategy. Leave `readyToApprove()`
  returning `ok` if there is a domain and a concept (you complete it in
  T006-06).
- **Hints:** compare with `!==` for fields (the `concept_id` already converted
  to an integer) and with `!=` for options (arrays). Save **before** calling
  `readyToApprove()`, which reads from the store.
- **Done when:** the tests of T006-03 are green.
- **Size:** L

### T006-05 [T] Approval requirements and input validation

- **Requirements:** FR-006-06, FR-006-08
- **Files:** `tests/specs/006_mapping_store.test.php`
- **What to do:** cases of `readyToApprove()`: `glu` without a domain →
  `no_domain` (and `updateField` with `state = approved` returns
  `_error = no_domain`); Measurement without a concept → `no_concept`;
  `exitus` in Death per option without a concept → `ok`; `bac` with
  `value_concept` without a concept → `no_concept`; `comorb` with only option 1
  → `choices_pending` with `['2', '3']` and `value = '2, 3'`; no option emits →
  `no_emitting_choice`; `'2089-1'` → `bad_concept_format`; `'-3004501'` →
  `concept_negative`. Validation: `domain = 'Banana'` is ignored;
  `"\u{00A0}44814637\u{200B} "` → 44814637; `'2089-1'` keeps the previous one
  and sets `_bad_kind = format`; `'-5'` → `negative`; a valid value clears the
  flags; `'   '` → `null`; a concept `'abc'` in an option; `approved_by`,
  `edited` or `dict_hash` in `$changes` are not written.
- **Done when:** the tests fail.
- **Size:** M

### T006-06 [I] `readyToApprove()` and `parseConceptId()`

- **Requirements:** FR-006-06, FR-006-08
- **Files:** `classes/MappingStore.php`
- **What to do:** `cleanConceptId()` (removes `[\x{00A0}\x{2000}-\x{200D}\x{202F}\x{205F}\x{3000}\x{FEFF}]` and trims);
  a private `parseConceptId()` that returns `[value, error]`; flags
  `_bad_concept`/`_bad_kind` on field and option; `readyToApprove()` with the
  order of checks of FR-006-06 (domain → malformed concept → value_concept
  without question → without options → emitting options).
- **Hints:** in Death the concept is the cause of death and is optional, in the
  field and in the options. Do not turn `'2089-1'` into `2089`: it is the
  Athena CODE, not the ID.
- **Done when:** the tests of T006-05 are green.
- **Size:** M

### T006-07 [T] What is stored prevails, and drift

- **Requirements:** FR-006-09, FR-006-10
- **Files:** `tests/specs/006_mapping_store.test.php`
- **What to do:** `edited` false at first and true after saving a note; a JSON
  with `glu` that has a domain but no `edited` counts as edited;
  `date_field = ''` → `date_default` true; `visit_date` clears it;
  `mappingRows()['weight']['spec']['domain'] === 'Observation'` even though the
  proposal is Measurement. Drift after `curate()`: `field_note` and
  `<b>Clinical notes</b>` do not drift; `Weight (lb)` and a new option in `bac`
  → `review` with `drift_reason = dictionary`; `isApproved('weight')` false and
  the store still `approved`; re-approving with the new data dictionary fixes
  it; without `med_start` in the data dictionary, `drug` → `review` with
  `date_field`; without `med_end` (its end date), also `review`, and
  `MappingStore::hasDrifted($spec, $meta, $dictionary)` is true with the data
  dictionary without `med_end` and false with the full one.
  `dictHash()` changes with each of the four metadata items and not with
  `field_note`.
- **Hints:** to read after changing the data dictionary use
  `$fresh($m->settings)`: the module caches the data dictionary. End with
  `\REDCap::$dict = fixture_dict();`.
- **Done when:** the tests fail because `mappingRows()` is missing.
- **Size:** M

### T006-08 [I] `mappingRows()`, `isApproved()` and fingerprint

- **Requirements:** FR-006-09, FR-006-10
- **Files:** `OmopCdmExportModule.php`, `classes/MappingStore.php`
- **What to do:** `dictHash()` (md5 of `type|validation|trim(strip_tags(label))|options`,
  16 characters) and `hasDrifted($spec, $dictMeta, ?array $dictionary = null)`
  (approved and different fingerprint, or `end_date_field` missing from the
  data dictionary; without a dictionary, the one of the store's module, which
  the constructor keeps in a static property); `mappingRows()` with a cache
  (`$mappingRowsCache`) that, for each field of the profile, returns
  `profile`, `spec`, `values`, `state`, `drifted`, `drift_reason`;
  `isApproved()`; `invalidateMappingCache()`, which `save()` calls if it exists.
- **Hints:** drift is derived on read: do not write `review` to the store.
- **Done when:** the tests of T006-07 are green.
- **Size:** M

### T006-09 [T] "config" state (amendment 018)

- **Requirements:** FR-006-11
- **Files:** `tests/specs/006_mapping_store.test.php`
- **What to do:** with `make_module()`: `dob`, `record_id`, `site` and
  `death_date` in `config` with their roles (`birth`, `person_id`,
  `care_site`, `death_date`), `weight` without a role; `sex` stays `proposed`;
  `mappingCounts()` gives `config = 4`, `proposed = 14`, `total = 18`. With
  `make_module(['birth-date-field' => '', 'birth-year-field' => 'dob', 'provider-field' => 'drug'])`
  the roles `birth` and `provider`. Saving a note in `dob` does not write
  `"config"` to the JSON. Approving `death_date` as Death leaves it `approved`.
- **Done when:** the tests fail.
- **Size:** S

### T006-10 [I] `configRole()` and `mappingCounts()`

- **Requirements:** FR-006-11
- **Files:** `OmopCdmExportModule.php`
- **What to do:** `personConfig()`, `deathConfig()`, `entityConfig()` reading
  the settings with `setting()` (per-request cache); `configRole()` according
  to the plan; in `mappingRows()`, after drift,
  `if ($role !== null && $state !== S_APPROVED) $state = S_CONFIG;` and the
  `role` key; `mappingCounts()` with the six keys.
- **Hints:** sex, race and ethnicity do NOT have a role: their options need a
  concept and approval in the editor.
- **Done when:** the tests of T006-09 are green.
- **Size:** S

### T006-11 [T] Back to the proposal, reset, undo and bulk

- **Requirements:** FR-006-13, FR-006-14, FR-006-15, NFR-006-02
- **Files:** `tests/specs/006_mapping_store.test.php`
- **What to do:** after `curate()`, `weight` to Observation and re-approved;
  `resetToProposal('weight', 'Measurement')` → `changed`, `from`/`to`,
  `proposed` without a signature, keeps `3025315`, `kg` and `visit_date`, one
  log entry; repeating it neither changes nor logs. With `site` excluded:
  `resetImpact() === ['fields' => 10, 'approved' => 9, 'excluded' => 1, 'concepts' => 13]`;
  `reset()` returns `['fields' => 10, 'approved' => 9, 'excluded' => 1]` and
  leaves the mapping empty; `backupInfo()['fields'] === 10`;
  `restoreBackup() === 10` and `spec('weight')` the same as before; second call
  `null`. Bulk: `bulkState(['glu', 'notes'], 'excluded') === 2`, repeating with
  `weight` → 1, `'config'` → 0, approving `notes` in bulk signs and stores the
  fingerprint, and the log carries `state`, `count` and `fields`. NFR-006-02:
  the data dictionary and the data do not change.
- **Done when:** the tests fail.
- **Size:** M

### T006-12 [I] `resetToProposal()`, `reset()`, backup and `bulkState()`

- **Requirements:** FR-006-13, FR-006-14, FR-006-15
- **Files:** `classes/MappingStore.php`
- **What to do:** implement the methods with the plan's signatures. The backup
  goes in `omop-mapping-backup` as `{saved_at, saved_by, mapping}`;
  `restoreBackup()` calls `save($b['mapping'])` and
  `removeProjectSetting(BACKUP_KEY)`. Exact log messages:
  `Field reset to the classifier proposal`, `Mapping fully reset`,
  `Mapping restored from the reset backup`, `Bulk state change`.
- **Hints:** `resetImpact()` counts concepts of fields and of options.
  Restoring keeps the signatures: if the data dictionary changed in the
  meantime, the fingerprint will make the field come out in `review`, as
  always.
- **Done when:** `php tests/run.php 006` is green.
- **Size:** M

### T006-13 [R] Documentation of the contract and of the "config" state

- **Requirements:** FR-006-02, FR-006-11
- **Files:** `README.md`
- **What to do:** document in *Mapping states* the table of states (including
  `config`, derived and never stored) and the rules, and in *The mapping
  contract* the field and option keys and schema 3.
- **Done when:** `php tests/run.php --trace 006` with no orphans and the README
  matches `blankSpec()`.
- **Size:** S

---

## Spec definition of done

- [ ] All `006` tests green and the rest of the suite without regressions.
- [ ] `php tests/run.php --trace 006` with no orphan requirements.
- [ ] New texts in `lang/Spanish.ini` and `lang/English.ini` with the same keys.
- [ ] README updated if anything visible to the user changes.
- [ ] Spec status changed to `Implemented` (or `Amended` after an amendment).
