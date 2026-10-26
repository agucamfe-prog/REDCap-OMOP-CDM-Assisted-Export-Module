# Tasks 010 — Longitudinal projects, visits and entities

Before you start: read `constitution.md`, `AGENTS.md`, and the `spec.md` and
`plan.md` in this folder. Do the tasks in order. Every `[T]` must end with the
test **red**; the following `[I]` turns it green.

Run only this spec's tests: `php tests/run.php 010`

| Legend | |
|---|---|
| `[T]` | Write a test (red) |
| `[I]` | Implement (green) |
| `[R]` | Refactor, document or translate |
| `[P]` | Can be done in parallel with the previous task |

---

### T010-01 [T] Longitudinal test project and event reading

- **Requirements:** FR-010-01, FR-010-03, NFR-010-01
- **Files:** `tests/specs/010_longitudinal_visits.test.php`
- **What to do:**
  1. After `reset_fixture()`, add `doctor` (dropdown `1, Dr Alvarez | 2,
     Dr Brown`) and `tb_dx_date` (`date_ymd`) to `\REDCap::$dict`.
  2. Configure `\Project::$conf` as in the plan: `longitudinal => true`,
     events 10, 20, 30, 40 (arm 1) and 50 (arm 2), `uniqueEventNames`
     (`baseline_arm_1`, `month2_arm_1`, `followup_arm_1`, `closure_arm_1`,
     `baseline_arm_2`) and `RepeatingFormsEvents` with `30 => 'WHOLE'`.
  3. A closure `$makeRow($id, $values)` that fills every dictionary field with
     an empty value (checkboxes as `['1' => '0', ...]`), and three records:
     `L1` (events 10, 20, 40, `meds` in 10 and 20, two instances of event 30),
     `L2` (event 50) and `L3` (event 10 without `visit_date`, 20 with a date,
     three instances of `meds` in 10, the last one without a date).
  4. Tests: `getProjectStructure()['events'][10]['unique_name']` is
     `'baseline_arm_1'` and `repeating` includes `['event_id' => 30, 'form' => '']`;
     there are 3 persons; L1 has 4 weights dated `2024-01-10`, `2024-03-10`,
     `2024-04-10`, `2024-05-10` and 4 drugs.
- **Done when:** the weight tests for the instances of event 30 fail.
- **Size:** M

### T010-02 [I] Reading events and repeat_instances

- **Requirements:** FR-010-01, FR-010-03, NFR-010-01
- **Files:** `classes/Transformer.php`
- **What to do:**
  1. Check that `getProjectStructure()` (spec 003) already provides `events`
     with `unique_name` and `repeating` with `form ''` for `'WHOLE'`; if the
     unique name does not come from `REDCap::getEventNames(true, true)`, it
     must be taken from `$proj->uniqueEventNames` before falling back to
     `event_<id>`.
  2. `readBatch()`: besides the `[event_id => fields]` rows, in
     `repeat_instances[event][form][instance]` each instance is a row with
     `__event`, `__instance`, `__form` (`null` if the form is `''`) and
     `__repeat_event` (`true` if `''`).
- **Hints:** the `REDCap::getEventNames` stub only knows event 1; the test's
  event ids (10–50) do not collide with it, so the name comes from
  `uniqueEventNames`.
- **Done when:** `php tests/run.php 010` is green.
- **Size:** M

### T010-03 [T] Occasions and VISIT_OCCURRENCE

- **Requirements:** FR-010-02, FR-010-04, FR-010-05
- **Files:** `tests/specs/010_longitudinal_visits.test.php`
- **What to do:**
  1. A closure `$visit('L1', 'baseline_arm_1')` that finds the visit by person
     and `visit_source_value`.
  2. Tests: 7 visits (`stats['visits'] === 7`); the baseline with
     `visit_concept_id` 9202 and type 32809; `followup_arm_1#1` and `#2` exist;
     the isoniazid of `2024-01-11` points to the baseline and the rifampicin of
     `2024-03-12` to `month2_arm_1`.
  3. Tests with a new module and `$mv->settings = ['visit-concept-id' => ''] +
     $m->settings`: no visits and no `visit_occurrence_id`; the same with
     `generate-visits = false`.
  4. In the classic project: 3 `event_1` visits; with record 3 lacking `dob`,
     2 visits and none for person 3.
- **Done when:** the visit tests fail.
- **Size:** M

### T010-04 [I] Visit key, occasions and building visits

- **Requirements:** FR-010-02, FR-010-04, FR-010-05
- **Files:** `classes/Transformer.php`, `OmopCdmExportModule.php`
- **What to do:**
  1. `visitConfig()` in the module.
  2. `visitKey(array $row): string` (`record|event|instance`, instance `'0'`
     except in a whole repeating event).
  3. `collectOccasions(int $personId, array $rows)` for the rows with
     `__form === null`.
  4. In `run()`: `currentVisitKey` per row; in `emit()` and `emitNote()`,
     `visit_occurrence_id = currentVisitKey` and `trackVisit($date)`.
  5. `buildVisits()` and `stripVisitKeys()`; `purgeDroppedPersons()` removes
     the accumulated visits of dropped persons.
- **Hints:** `buildVisits()` goes after `purgeDroppedPersons()` and before
  assigning fact ids.
- **Done when:** `php tests/run.php 010` is green.
- **Size:** L

### T010-05 [T] Visit range and facts outside it

- **Requirements:** FR-010-06, FR-010-07, FR-010-08, FR-010-09
- **Files:** `tests/specs/010_longitudinal_visits.test.php`
- **What to do:**
  1. L1's baseline from `2024-01-10` to `2024-01-11`; `month2_arm_1` from
     `2024-03-10` to `2024-03-12`; L3's baseline (no date) from `2024-02-05` to
     `2024-02-07`.
  2. `tb_dx_date` (`2023-12-15`) approved as Condition: no
     `visit_occurrence_id` and `stats['facts_outside_visit'] === 1`.
  3. Walk `Transformer::DATE_COLUMN`: every fact with a visit belongs to the
     same person and its date falls between `visit_start_date` and
     `visit_end_date`.
  4. One row in DEATH (`2024-06-01`) and no `closure_arm_1` visit.
- **Done when:** the range and earlier-fact tests fail.
- **Size:** M

### T010-06 [I] Occasion date and linking by range

- **Requirements:** FR-010-06, FR-010-07, FR-010-08, FR-010-09
- **Files:** `classes/Transformer.php`
- **What to do:**
  1. The occasion's `anchor`: the row's default date, through `parseDate()`
     and `pseudo->shift()`.
  2. `trackVisit()` with the rules in the plan (with an `anchor`, `min` does
     not go down).
  3. In `buildVisits()`, link only if `$row[$col] >= $start && $row[$col] <= $end`;
     otherwise `null` and `facts_outside_visit++`.
  4. Check that `emitDeath()` does not call `trackVisit()`.
- **Done when:** `php tests/run.php 010` is green.
- **Size:** M

### T010-07 [T] Date of a repeating instance

- **Requirements:** FR-010-10
- **Files:** `tests/specs/010_longitudinal_visits.test.php`
- **What to do:** tests: the `meds` instance without `med_start` in L1's event
  20 comes out on `2024-03-10` and linked to `month2_arm_1`; the one in event
  10 comes out on `2024-01-10`; L3's third instance (its event 10 has no
  `visit_date`, although event 20 does) does not come out: L3 has 2 drugs and
  `skipped['no_date|drug'] === 1`.
- **Done when:** the L3 test fails (if the flattened data is searched, it comes
  out dated with event 20).
- **Size:** S

### T010-08 [I] Date within the same occasion

- **Requirements:** FR-010-10
- **Files:** `classes/Transformer.php`
- **What to do:** `$this->eventRows[event] = main row` per record and
  `dateInOccasion(array $row, string $dateField): ?string`, which looks at the
  row and, if `__form !== null`, at the main row of `__event`. Use it in
  `realFactDate()` and `endDate()`.
- **Hints:** do not use `$this->currentFlat` for dates: it mixes events.
- **Done when:** `php tests/run.php 010` is green.
- **Size:** S

### T010-09 [T] Field approved in the Visit domain

- **Requirements:** FR-010-11
- **Files:** `tests/specs/010_longitudinal_visits.test.php`
- **What to do:** with the classic fixture, approve `visit_date` as Visit
  (concept 9202). With visits: `skipped['visit_domain_conflict|visit_date'] === 1`
  and 3 visits. With `generate-visits = false`: 3 visits from the field, the
  first with concept 9202 and date `2024-03-01`.
- **Done when:** both tests fail.
- **Size:** S

### T010-10 [I] Conflict with the Visit domain

- **Requirements:** FR-010-11
- **Files:** `classes/Transformer.php`, `lang/*.ini`
- **What to do:** in `approvedFields()`, `skip('visit_domain_conflict', $field)`
  if `visitConf['enabled']`; a `visit_occurrence` case in `emit()` with
  `registry->id('visit_field', currentRowKey . '|' . $field)`; the
  `skip_visit_domain_conflict` key in both languages.
- **Done when:** `php tests/run.php 010` is green.
- **Size:** S

### T010-11 [T] CARE_SITE and PROVIDER

- **Requirements:** FR-010-12, FR-010-13
- **Files:** `tests/specs/010_longitudinal_visits.test.php`
- **What to do:**
  1. Classic: 2 sites (`North`, `South`), PERSON 1 and 3 with the same site
     and 2 with the other; visits carry a site.
  2. Longitudinal with `provider-field = doctor`: L1's PERSON with the
     baseline's site, `month2_arm_1` with the other; 2 PROVIDER rows without a
     name; the baseline with practitioner 1 and month 2 with practitioner 2;
     the month-2 weight and the baseline's repeating medication with the
     practitioner of their occasion.
  3. With `care-site-field = clinic_txt` (free text): no CARE_SITE and PERSON
     without `care_site_id`.
- **Done when:** the PROVIDER tests fail.
- **Size:** M

### T010-12 [I] Entities

- **Requirements:** FR-010-12, FR-010-13
- **Files:** `classes/Transformer.php`, `OmopCdmExportModule.php`
- **What to do:** `entityConfig()`; `entityId()` (only `radio` and
  `dropdown`); in `buildPerson()` with the flattened data and in
  `collectOccasions()` with the row; `provider_id` in `emit()` and
  `emitNote()` from the occasion; `buildEntities()` after
  `buildObservationPeriods()`.
- **Hints:** the numeric keys of `$entitiesUsed` arrive as `int`: convert them
  to `string` before building the `field=code` key.
- **Done when:** `php tests/run.php 010` is green.
- **Size:** M

### T010-13 [R] Report and README

- **Requirements:** FR-010-04, FR-010-07
- **Files:** `classes/CsvExporter.php`, `lang/Spanish.ini`, `lang/English.ini`, `README.md`
- **What to do:** a report section with `rep_visits` ({0} visits),
  `rep_visits_note` and `rep_outside_visit` (if `facts_outside_visit`); the
  "Longitudinal projects" and "Site and practitioner" sections of the README.
- **Done when:** both `.ini` files have the same keys and the suite stays green.
- **Size:** S

---

## Definition of done for the spec

- [ ] All `010` tests green and the rest of the suite without regressions.
- [ ] `php tests/run.php --trace 010` with no orphan requirements.
- [ ] New texts in `lang/Spanish.ini` and `lang/English.ini` with the same keys.
- [ ] README updated if anything visible to the user changes.
- [ ] Spec status changed to `Implemented`.
