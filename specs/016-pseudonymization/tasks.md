# Tasks 016 — Pseudonymization

Before you start: read `constitution.md`, `AGENTS.md`, and the `spec.md` and
`plan.md` in this folder. Do the tasks in order. Every `[T]` must end with the
test **red**; the following `[I]` turns it green.

Run only this spec's tests: `php tests/run.php 016`

| Legend | |
|---|---|
| `[T]` | Write a test (red) |
| `[I]` | Implement (green) |
| `[R]` | Refactor, document or translate |
| `[P]` | Can be done in parallel with the previous task |

---

### T016-01 [T] Date shifting

- **Requirements:** FR-016-03, FR-016-04, FR-016-05
- **Files:** `tests/specs/016_pseudonymization.test.php`
- **What to do:** with `$ps = new Pseudonymizer(30, 'year', true, 'sal')`:
  1. `offset('7')` is stable, non-zero and `abs() <= 30`; another instance
     with the same salt gives the same result.
  2. Records `'0'`…`'39'` have more than 10 distinct shifts; with the salt
     `'otra'` more than 30 of the 40 change.
  3. With N = 1, the first 60 records only give −1 or +1.
  4. `shift('2024-03-10', '7')` and `shift('2024-03-01', '7')` remain 9 days
     apart; `shift('2024-03-01', '7')` is exactly `offset('7')` days away.
  5. With N = 0 and N = −5, `shiftsDates()` is false and the date does not
     change; `shift(null, '7') === null`; `'nope'`, `'2024-13-01'` and
     `'2024-02-30'` give `null`, and `'2024-02-29'` (leap year) does not.
- **Done when:** the tests fail because the class does not exist.
- **Size:** S

### T016-02 [I] Pseudonymizer: offset and shift

- **Requirements:** FR-016-03, FR-016-04, FR-016-05, NFR-016-02
- **Files:** `classes/Pseudonymizer.php`
- **What to do:** constructor (`max(0, $shiftDays)`), `shiftsDates()`,
  `offset()` with the formula from the plan and an `$offsets[$recordId]`
  cache, `shift()` with `createFromFormat('!Y-m-d')` and `modify()`.
- **Hints:** `createFromFormat()` returns `false` if the string is not a date,
  but "2024-13-01" does not fail: it normalizes it to 2025-01-01. Return
  `null` if it gives `false` or if `format('Y-m-d')` does not reproduce the
  input. The `!` in `'!Y-m-d'` sets the time to 00:00 so that the result does
  not depend on the time of execution.
  `modify()` needs an explicit sign: `'+12 days'`, `'-13 days'`.
- **Done when:** `php tests/run.php 016` is green.
- **Size:** S

### T016-03 [T] Birth precision

- **Requirements:** FR-016-06
- **Files:** `tests/specs/016_pseudonymization.test.php`
- **What to do:** with `$row = ['day_of_birth' => 3, 'month_of_birth' => 4,
  'birth_datetime' => 'x', 'year_of_birth' => 1990]`: `year` keeps only the
  year; `month` keeps year and month; `full` returns the row unchanged;
  `'decade'` → `birthPrecision() === 'full'`.
- **Done when:** the `birth()` tests fail.
- **Size:** S

### T016-04 [I] birth() and birthPrecision()

- **Requirements:** FR-016-06
- **Files:** `classes/Pseudonymizer.php`
- **What to do:** validate the precision against `['full', 'month', 'year']`
  (otherwise `full`); `birth(array $row): array` as in the plan;
  `suppressesFreeText()`.
- **Done when:** `php tests/run.php 016` is green.
- **Size:** S

### T016-05 [T] Salt, hash and defaults

- **Requirements:** FR-016-01, FR-016-02, FR-016-11
- **Files:** `tests/specs/016_pseudonymization.test.php`
- **What to do:**
  1. With `make_module()` + `curate()`: `person_source_value` `'1'`, weight on
     `2024-03-01`, `day_of_birth` 10, one NOTE, no `stats['date_shift']` and no
     `$m->settings['person-hash-salt']`.
  2. With `make_module(['date-shift-days' => '30', 'birth-precision' => 'year',
     'suppress-free-text' => true, 'hash-person-source-value' => true])`: the
     salt matches `/^[0-9a-f]{32}$/` and does not change in a second export;
     `person_source_value === substr(hash('sha256', $salt . '|1'), 0, 32)`.
  3. With only `hash-person-source-value`: a 32-character hash and
     `month_of_birth` 5.
- **Done when:** the hash and salt tests fail.
- **Size:** S

### T016-06 [I] Salt, hash and module settings

- **Requirements:** FR-016-01, FR-016-02, FR-016-11
- **Files:** `OmopCdmExportModule.php`, `config.json`, `lang/*.ini`
- **What to do:** `projectSalt()`, `hashPersonSourceValue()` and
  `pseudonymizer()` as in the plan; in `config.json` the settings
  `hash-person-source-value` (checkbox), `date-shift-days` (text),
  `birth-precision` (dropdown `full`/`month`/`year`) and `suppress-free-text`
  (checkbox), with their labels in both languages.
- **Hints:** do not generate the salt if it is not needed: FR-016-11 checks it.
- **Done when:** `php tests/run.php 016` is green.
- **Size:** M

### T016-07 [T] Shifted dates in the export

- **Requirements:** FR-016-04, FR-016-06, FR-016-12
- **Files:** `tests/specs/016_pseudonymization.test.php`
- **What to do:** with the four settings and `$off1 = (new Pseudonymizer(30,
  'full', false, $salt))->offset('1')`:
  1. Record 1's weight is not `2024-03-01`, is ≤ 30 days away and exactly
     `$off1`; the isoniazid is still 1 day later.
  2. A closure `$shifted($real, $exported, $record)`: it holds for the end of
     the isoniazid (`2024-03-20`), the start of record 1's visit, record 2's
     death (`2024-06-01`), the start of record 2's period (`2024-04-01`) and
     the end of record 1's period (`2024-03-20`, the end of the isoniazid).
  3. `year` precision: no month and no `birth_datetime`. With `month`
     (copying the salt): year and month are those of `1960-05-10` shifted by
     `$off1` days.
  4. Characterization: with a `yob` field (`integer`) as `birth-year-field`,
     record 3 without `dob` and `yob = '1981'`, `year_of_birth` comes out as
     1981 with no month or day even though dates are shifted.
- **Done when:** the tests fail because dates come out unshifted.
- **Size:** M

### T016-08 [I] Apply the shift in the Transformer

- **Requirements:** FR-016-04, FR-016-06, FR-016-12
- **Files:** `classes/Transformer.php`
- **What to do:** `$this->pseudo = $module->pseudonymizer()` in the
  constructor and `shift()` in `buildPerson()` (only the full date; then
  `birth()`), `factDate()`, `endDate()`, `collectOccasions()` and
  `deathDate()`; `stats['date_shift'] = 1`. The standalone year from the year
  field does not go through `shift()`.
- **Hints:** shift the end date before comparing it with the start (which is
  already shifted); otherwise `end_before_start` over- or under-counts.
- **Done when:** `php tests/run.php 016` is green.
- **Size:** M

### T016-09 [T] Free text

- **Requirements:** FR-016-07, FR-016-08, FR-016-09
- **Files:** `tests/specs/016_pseudonymization.test.php`
- **What to do:** add `symptoms` (text, Observation 4038150, value
  `'headache since Monday'`) and `death_cause` (text, Death,
  `'Respiratory failure'` in record 2). Tests: the observation exists without
  `value_as_string` or `value_source_value`; glucose "95" keeps 95 and "< 70"
  keeps 70 and its original; record 2's glucose "1.234" is not exported and
  `skipped['free_text_no_value|glu'] === 1`; `free_text_suppressed === 2`
  (symptoms and cause of death: rows with a number do not count); the
  `skip_free_text_no_value` key exists in both `.ini` files; NOTE is empty and
  `notes_suppressed === 1`; DEATH with a null `cause_source_value`.
- **Done when:** the suppression tests fail.
- **Size:** S

### T016-10 [I] Suppress free text

- **Requirements:** FR-016-07, FR-016-08, FR-016-09
- **Files:** `classes/Transformer.php`, `lang/Spanish.ini`, `lang/English.ini`
- **What to do:** in `transformField()`, free-value branch: if
  `($spec['_kind'] ?? '') === 'text'`, `suppressesFreeText()` and there is no
  number: in `measurement`, `skip('free_text_no_value', $field)` and
  `return`; elsewhere, empty `value_string` and `value_source` and count it.
  With a number, touch nothing and count nothing. In `emitNote()`, if
  `suppressesFreeText()`, count and return without emitting. The
  `skip_free_text_no_value` key (with `{0}`) in both languages: the report
  already lists drops with `skipLabel()`.
- **Hints:** a free-text cause of death reaches `emitDeath()` through
  `value_string`; emptying it here stops it being exported without touching
  `emitDeath()`.
- **Done when:** `php tests/run.php 016` is green.
- **Size:** S

### T016-11 [T] Declaration in the report

- **Requirements:** FR-016-10, NFR-016-03
- **Files:** `tests/specs/016_pseudonymization.test.php`
- **What to do:** a closure `$report($mod, $res)` that generates the ZIP with
  `$mod->exporter()->writeZip($res)`, reads `INFORME.txt` with `ZipArchive`,
  deletes the ZIP and normalizes whitespace (`preg_replace('/\s+/', ' ', …)`).
  Tests: `stats['date_shift'] === 1`; the report with the four settings
  contains `rep_privacy_applied`, "up to 30 days", `rep_birth_year`,
  "free-text answer(s) not exported" and "person_source_value is hashed"; the
  report of the default export does not contain `rep_privacy_applied`; no text
  in `lang/*.ini` contains "anonym" or "anónim".
- **Done when:** the report test fails.
- **Size:** S

### T016-12 [I] Report section

- **Requirements:** FR-016-10, NFR-016-03
- **Files:** `classes/CsvExporter.php`, `lang/Spanish.ini`, `lang/English.ini`
- **What to do:** in `writeReport()`, a `$priv` list with `rep_date_shift`
  (with `date-shift-days`), `rep_birth_month`/`rep_birth_year`,
  `rep_free_text_suppressed` (sum of both counters) and `rep_hashed`; if it is
  not empty, a `rep_privacy_applied` section with one bullet per measure.
- **Done when:** `php tests/run.php 016` is green and both `.ini` files have
  the same keys.
- **Size:** S

### T016-13 [R] README and settings help

- **Requirements:** FR-016-10
- **Files:** `README.md`, `lang/Spanish.ini`, `lang/English.ini`
- **What to do:** a "Pseudonymization" section in the README (what each
  setting does, that intervals are preserved, that the result is not
  anonymous, that losing the salt breaks the link with earlier exports) and
  help texts for the settings in `desc-privacy`.
- **Done when:** the suite stays green and the README describes the four
  settings.
- **Size:** S

---

## Definition of done for the spec

- [ ] All `016` tests green and the rest of the suite without regressions.
- [ ] `php tests/run.php --trace 016` with no orphan requirements.
- [ ] New texts in `lang/Spanish.ini` and `lang/English.ini` with the same keys.
- [ ] README updated if anything visible to the user changes.
- [ ] Spec status changed to `Implemented`.
