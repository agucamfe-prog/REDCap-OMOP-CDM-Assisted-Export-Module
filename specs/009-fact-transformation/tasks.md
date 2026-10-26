# Tasks 009 — Fact transformation

Before you start: read `constitution.md`, `AGENTS.md`, and the `spec.md` and
`plan.md` in this folder. Do the tasks in order. Each `[T]` must end with the
test **red**; the following `[I]` turns it green.

Run only this spec's tests: `php tests/run.php 009`

| Legend | |
|---|---|
| `[T]` | Write a test (red) |
| `[I]` | Implement (green) |
| `[R]` | Refactor, document or translate |
| `[P]` | Can be done in parallel with the previous task |

---

### T009-01 [T] Basic PERSON and only what is approved

- **Requirements:** FR-009-01, FR-009-02
- **Files:** `tests/specs/009_fact_transformation.test.php`
- **What to do:**
  1. `$m = make_module(); curate($m); $res = $m->transformer()->run();` and
     `$P = array_column($res['tables']['person'], null, 'person_id');`.
  2. Test `FR-009-02`: there are 3 persons; `$P[1]` has `year_of_birth` 1960,
     `month_of_birth` 5, `day_of_birth` 10, `birth_datetime`
     `'1960-05-10 00:00:00'` and `person_source_value` `'1'`.
  3. Test `FR-009-01`: `hr` is `proposed` (`$m->mappingRows()['hr']['state']`)
     and there is no measurement with concept 3027018.
  4. Test `FR-009-01`: with `reset_fixture()` and an uncurated `make_module()`,
     `run()` returns `error === 'no_approved_fields'`.
- **Done when:** the tests fail because `transformer()` does not exist.
- **Size:** S

### T009-02 [I] Transformer skeleton and PERSON

- **Requirements:** FR-009-01, FR-009-02, NFR-009-04
- **Files:** `classes/Transformer.php`, `OmopCdmExportModule.php`
- **What to do:**
  1. In the module (the `transformer()` factory already exists since spec 001):
     `personConfig()` (settings `gender-field`, `birth-date-field`,
     `birth-year-field`, `race-field`, `ethnicity-field`, `?: null`),
     `deathConfig()` and `defaultDateField()`.
  2. Collaborators: the `idRegistry(bool $dryRun = false)` and
     `pseudonymizer()` factories belong to spec 001; their classes are
     completed by specs 013 and 016. Here their interface with neutral
     behaviour is enough: `IdRegistry::id(string $kind, string $key): int`
     numbers 1, 2, 3… per kind and `save()` does nothing;
     `Pseudonymizer::shift(?string, string)` returns the date and
     `birth(array $row)` returns the row.
  3. `Transformer::__construct($module)` reads the whole configuration into
     properties. `run()`: initialises `tables` with `CdmModel::tables()`,
     `approvedFields()`, `recordIds()`, `readBatch()` and `buildPerson()`.
  4. `buildPerson()`: row with `CdmModel::emptyRow('person')`; birth date with
     `parseDate()` (`/^(\d{4})-(\d{2})-(\d{2})/` + `checkdate`); if there is no
     date, `year_of_birth` from the year field. Store by `person_id`.
  5. `result()` with `tables`, `counts`, `stats`, `skipped`, `missing`.
- **Hints:** `approvedFields()` discards `Person`, `NotMapped` and `DateAnchor`
  (`DictionaryProfiler::D_SKIP`, `D_DATE_ANCHOR`). `getData` with
  `return_format = 'array'` returns `[record => [event_id => fields]]`.
- **Done when:** `php tests/run.php 009` is green.
- **Size:** L

### T009-03 [T] PERSON concepts and discard without a birth date

- **Requirements:** FR-009-03, FR-009-04
- **Files:** `tests/specs/009_fact_transformation.test.php`
- **What to do:**
  1. Test: `gender_concept_id` 8507 for record 1 and 8532 for record 2; race
     and ethnicity 0.
  2. Test: after `$m->store()->updateField('sex', ['state' => 'proposed'])`,
     the concept is 0, `gender_source_value` is `'Male'` and
     `stats['gender_not_approved'] === 1`.
  3. Test: with `\REDCap::$data['3'][1]['dob'] = ''`, PERSON has 2 rows, no
     table has rows with `person_id` 3, and `stats` carries
     `persons_dropped` 1, `rows_dropped_with` 4 and `dropped_record_ids` `'3'`.
- **Done when:** the concept-0 and discard tests fail.
- **Size:** S

### T009-04 [I] Approved concepts and person purge

- **Requirements:** FR-009-03, FR-009-04
- **Files:** `classes/Transformer.php`
- **What to do:**
  1. `personConcept(?string $field, string $statKey): array` →
     `[concept_id, label]`; uses `$module->isApproved($field)` and
     `$store->values($field)[$raw]['concept_id']`.
  2. In `buildPerson()`, if `year_of_birth` is missing, store
     `$this->dropped[$personId] = $recordId` and do not add the row.
  3. `purgeDroppedPersons()` at the end of `run()`: filters every table
     (except `cdm_source`) by `person_id`, counts the removed rows and fills in
     the three statistics (`array_slice(..., 0, 20)` for the Record IDs).
- **Hints:** the person's facts are generated before it is known that the
  person is discarded; that is why the purge goes at the end and not in
  `buildPerson()`.
- **Done when:** `php tests/run.php 009` is green.
- **Size:** M

### T009-05 [T] Facts per domain, dates and periods

- **Requirements:** FR-009-05, FR-009-06, FR-009-12
- **Files:** `tests/specs/009_fact_transformation.test.php`
- **What to do:**
  1. Extend `\REDCap::$dict` with `f('xray', 'base', 'yesno', 'Chest X-ray done')`,
     `catheter`, `sputum_sample` and `tb_dx_date` (`date_ymd`); put values in
     `\REDCap::$data['1'][1]` and approve them with a helper closure (domains
     Procedure, Device, Specimen and Condition).
  2. Tests: one row in `procedure_occurrence` (4163872, `'2024-03-01'`), in
     `device_exposure` and in `specimen`; all with type 32809; `tb_dx_date`
     dated `'2023-12-15'`.
  3. Test: with record 3 without `visit_date`, `skipped['no_date|weight'] === 1`
     and no row of `Transformer::DATE_COLUMN` with an empty date.
  4. `OBSERVATION_PERIOD` tests: person 1 starts on `'2024-03-01'`, person 2
     ends on `'2024-06-01'` (death), id = `person_id`, type 32817. End dates
     enter the period in T009-11.
- **Done when:** the tests fail because of empty tables.
- **Size:** M

### T009-06 [I] transformField, emit and dates

- **Requirements:** FR-009-05, FR-009-06, FR-009-12
- **Files:** `classes/Transformer.php`
- **What to do:**
  1. `transformField()` following the plan's tree (for now without options or
     checkboxes) and `emit()` with a `switch` per table.
  2. `factDate()` → `realFactDate()` → `dateInOccasion()`; a field with
     `$module->isDateField($field)` and no `date_field` uses its own value.
  3. `trackDate($personId, $date)` on every emitted row and
     `buildObservationPeriods()` with `TYPE_OBS_PERIOD = 32817`; count
     `persons_without_period`.
  4. Constants `TYPE_EHR_FORM = 32809`, `DATE_COLUMN`, `ID_COLUMN`.
- **Hints:** `SPECIMEN` has no `visit_occurrence_id`; use `array_key_exists`
  before filling in columns that not every table has.
- **Done when:** `php tests/run.php 009` is green.
- **Size:** L

### T009-07 [T] Strategies, «generates a row» and checkboxes

- **Requirements:** FR-009-07, FR-009-08, FR-009-09
- **Files:** `tests/specs/009_fact_transformation.test.php`
- **What to do:**
  1. Tests with the base scenario: fever with 2 rows (only «Sí»),
     `stats['not_emitted'] === 2` and `skipped === []`; `bac` with
     `value_as_concept_id` 9191/9189 and `value_as_number` null; comorbidities
     201820 and 316866 and nothing for «None».
  2. Tests with `smoking` (`single_concept`, `value_as_string` `'Current'`) and
     `pain` (scale 0–5, `value_as_number` 4).
  3. Test: `fever` approved as `single_concept` still has 2 rows.
  4. Test: a `meds` instance with `drug = '9'` →
     `skipped['choice_undecided|drug=9'] === 1`.
  5. Test: record 1 with `comorb___1 = '1'` (without the `comorb` key)
     generates 201820.
- **Done when:** the option tests fail.
- **Size:** M

### T009-08 [I] Options and checkboxes

- **Requirements:** FR-009-07, FR-009-08, FR-009-09
- **Files:** `classes/Transformer.php`
- **What to do:**
  1. `optionSpec(array $spec, string $key): ?array`: the saved decision or, if
     the option is the negative of a binary
     (`DictionaryProfiler::isNegativeChoice`), `emit = false`.
  2. `emitOption()`: `value_concept` → the field's concept and
     `value_concept_id`; `per_choice` → the option's concept; source = the
     question if the label is binary (`DictionaryProfiler::isBinaryLabel`).
  3. Force `per_choice` if the type is in `DictionaryProfiler::IMPLICIT_CHOICES`.
  4. `single_concept` with options: label as text; number only if
     `DictionaryProfiler::looksLikeRatingScaleStatic($choices)`.
  5. `transformCheckbox()` and `isCheckboxChecked()` (nested array or
     `field___option`); work out the checked options before asking for the
     date.
- **Hints:** `not_emitted` is counted before looking at the date.
- **Done when:** `php tests/run.php 009` is green.
- **Size:** M

### T009-09 [T] Numbers, operators and units

- **Requirements:** FR-009-10, FR-009-11
- **Files:** `tests/specs/009_fact_transformation.test.php`
- **What to do:**
  1. `glu` tests: «< 70» → operator 4171756 and 70; «1.234» →
     `value_as_number` null, `value_source_value` `'1.234'`,
     `stats['ambiguous_number'] === 1`; «95» → 95.
  2. With `crp` (free text, `mg/L`): «>= 10», «95,5» (`not_numeric`) and «<=3»;
     `stats['with_operator'] === 3`.
  3. Units: weight → 9529, glucose → 8840, `temp` with `'°C'` →
     `unit_concept_id` null and `unit_source_value` `'°C'`; «1.234» without a
     unit.
- **Done when:** the operator and unit tests fail.
- **Size:** S

### T009-10 [I] Numeric value and UCUM

- **Requirements:** FR-009-10, FR-009-11
- **Files:** `classes/Transformer.php`, `OmopCdmExportModule.php`
- **What to do:**
  1. Use what spec 002 already provides: `CdmModel::OPERATORS` (`'<' => 4171756,
     '<=' => 4171754, '=' => 4172703, '>=' => 4171755, '>' => 4172704`),
     `UCUM_BUILTIN` and `ucumCode()` (`mmhg` → `mm[Hg]`, `µmol/l` → `umol/L`…).
  2. `$module->unitConcept(?string $unit): ?int`: `ucumCode()` and then
     `UCUM_BUILTIN[$code] ?? null` (spec 014 will consult the vocabulary first).
  3. `Transformer::numericValue(string $raw, string $field, string $table): array`
     with `NUMERIC_VALIDATIONS` and the plan's expressions.
  4. In `emit()`, a unit only if `value_number !== null`.
- **Hints:** `«1.234»` matches the number expression; discard it first with
  `^-?\d{1,3}\.\d{3}$`.
- **Done when:** `php tests/run.php 009` is green.
- **Size:** M

### T009-11 [T] End dates, DEATH and NOTE

- **Requirements:** FR-009-13, FR-009-14, FR-009-15
- **Files:** `tests/specs/009_fact_transformation.test.php`
- **What to do:**
  1. Isoniazid with end `'2024-03-20'`; rifampicin (end before start) with end
     `'2024-03-05'`, `verbatim_end_date` null and
     `stats['end_before_start'] === 1`. Person 1's period reaches
     `'2024-03-20'`, and walking through `DATE_COLUMN` and `END_DATE_COLUMN` no
     fact or visit starts or ends outside its person's period.
  2. DEATH: one row, `'2024-06-01'`, with no cause; with `death_cause`
     approved, still one row and `cause_source_value` `'Respiratory failure'`;
     with the «Sí» option of `exitus` → 4306655, `cause_concept_id` 4306655 and
     `cause_source_value` null («Yes» is not a cause); with `exitus` in
     `proposed`, DEATH still comes from the configured date. With a
     `death_cat` dropdown (`1, Tuberculosis | 2, Cancer | 3, Other`, option 3
     without a concept): «Tuberculosis» accompanies 434557 and «Other» leaves
     no label.
  3. NOTE: full text, class 44814637, `encoding_concept_id` 32678, language
     4180186; a text of more than 10,000 characters intact and
     `note-language-concept-id` = 4182511 honoured.
- **Done when:** the DEATH and NOTE tests fail.
- **Size:** M

### T009-12 [I] End, DEATH and NOTE

- **Requirements:** FR-009-13, FR-009-14, FR-009-15
- **Files:** `classes/Transformer.php`, `OmopCdmExportModule.php`
- **What to do:**
  1. `endDate(array $spec, array $row, string $start): ?string` and its use in
     the `switch` of `emit()` (`END_DATE_COLUMN`); in `drug_exposure`,
     `end ?? date` and `verbatim_end_date = end`; `trackDate($personId, $end)`
     so that the period covers the end.
  2. `deathDate()`, `emitDeath()` (one row per `person_id`, the cause only if
     there is none yet; `cause_source_value` = label only if it is not binary,
     `DictionaryProfiler::isBinaryLabel()`) and `buildDeathFromConfig()` after
     processing each record.
  3. `emitNote()` and `$module->noteLanguageConcept()` (setting
     `note-language-concept-id`; otherwise `doc_lang` = `es` → 4182511,
     otherwise 4180186).
- **Hints:** in DEATH a binary label («Yes») is not a free-text cause.
- **Done when:** `php tests/run.php 009` is green.
- **Size:** M

### T009-13 [T] Batches, mandatory columns and counters

- **Requirements:** NFR-009-01, NFR-009-02, NFR-009-03
- **Files:** `tests/specs/009_fact_transformation.test.php`
- **What to do:**
  1. Add 202 records with `rec()` (ids 4 to 205): `stats['batches'] === 2`,
     `records === 205`; `run(10, true)` processes 10.
  2. `missing === []` and `=== CdmModel::missingRequired($res['tables'])`.
  3. `array_keys($res) === ['tables', 'counts', 'stats', 'skipped', 'missing']`
     and `stats['rows'] === array_sum($res['counts'])`.
- **Done when:** the batch test fails.
- **Size:** S

### T009-14 [I] Batches and result

- **Requirements:** NFR-009-01, NFR-009-02, NFR-009-03
- **Files:** `classes/Transformer.php`
- **What to do:** `const BATCH_SIZE = 200`; `array_chunk($ids, self::BATCH_SIZE)`
  with `stats['batches']++`; `$limit` trims the ids; `skip(string $code,
  string $arg = '')` with a `code|argument` key; `stats['rows']` at the end.
- **Done when:** `php tests/run.php 009` is green.
- **Size:** S

### T009-15 [R] Discard texts and README

- **Requirements:** NFR-009-03
- **Files:** `lang/Spanish.ini`, `lang/English.ini`, `README.md`
- **What to do:** keys `skip_no_date`, `skip_no_table`, `skip_choice_undecided`,
  `skip_choice_no_concept`, `skip_field_no_concept`, `skip_not_implemented`
  with `{0}` for the argument, in both languages (they will be used by
  `skipLabel()` from spec 011: `skip_` + code, with the argument in `{0}`); a
  README section on transformation and export with the rules; comments
  explaining the why of each rule in `Transformer.php`.
- **Done when:** both `.ini` files have the same keys and the suite is still
  green.
- **Size:** S

---

## Definition of done for the spec

- [ ] All `009` tests green and the rest of the suite without regressions.
- [ ] `php tests/run.php --trace 009` with no orphan requirements.
- [ ] New texts in `lang/Spanish.ini` and `lang/English.ini` with the same keys.
- [ ] README updated if anything visible to the user changes.
- [ ] Spec status changed to `Implemented`.
