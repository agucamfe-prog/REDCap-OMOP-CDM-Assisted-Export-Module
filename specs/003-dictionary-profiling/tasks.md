# Tasks 003 — Data dictionary profiling

Before starting: read `constitution.md`, `AGENTS.md`, and this folder's
`spec.md` and `plan.md`. Do the tasks in order. Every `[T]` must end with the
test **red**; the following `[I]` turns it green.

Run only this spec's tests: `php tests/run.php 003`

| Legend | |
|---|---|
| `[T]` | Write a test (red) |
| `[I]` | Implement (green) |
| `[R]` | Refactor, document or translate |
| `[P]` | Can be done in parallel with the previous task |

---

### T003-01 [T] Test project and shape of the profile

- **Requirements:** FR-003-01, FR-003-02
- **Files:** `tests/lib/fixtures.php`, `tests/specs/003_dictionary_profiling.test.php`
- **What to do:**
  1. In `tests/lib/fixtures.php` create `f($name, $form, $type, $label, $choices, $val, $ann, $ident)`,
     which returns a row with the 18 columns of `getDataDictionary('array')`,
     and `fixture_dict()` with the tuberculosis study (instruments `base` and
     `meds`: `record_id`, `dob`, `sex`, `visit_date`, `weight`, `glu`, `bac`,
     `fever`, `comorb`, `notes`, `site`, `death_date`, `exitus`, `hr`,
     `smear2`, `med_start`, `drug`, `med_end`), `reset_fixture()` and `make_module()`.
  2. In the test, `$p = make_module()->profile()`: `array_keys($p) === array_keys(\REDCap::$dict)`;
     the keys of `$p['weight']` are the 16 from the spec, in that order;
     `$p['drug']['form_name'] === 'meds'`.
  3. Define the plan's `$one` closure (it profiles a single field with
     `new DictionaryProfiler()`) and check that `'  <b>Weight</b> (kg) '`
     gives `field_label === 'Weight (kg)'`.
- **Done when:** it fails because `DictionaryProfiler` does not exist.
- **Size:** M

### T003-02 [I] Profiler skeleton and row()

- **Requirements:** FR-003-01, FR-003-02, FR-003-13
- **Files:** `classes/DictionaryProfiler.php`, `classes/TextNormalizer.php`, `OmopCdmExportModule.php`
- **What to do:**
  1. `TextNormalizer::normalize()`: `mb_strtolower`, strip accents with a
     `strtr` table, turn everything non-alphanumeric into a space, collapse
     spaces.
  2. `DictionaryProfiler` with the domain constants, the constructor with an
     optional translator, `t()` (see plan), `profile()` which walks the
     dictionary and `profileField()` which for now returns
     `row($name, $meta, 'Observation', 'low', $this->t('r_fb_text'), 'text', [], false, null)`.
  3. `row()` builds the 16 keys; the label with
     `trim(strip_tags($meta['field_label'] ?? ''))`; `suggested_table` with
     `CdmModel::tableForDomain()`.
  4. In the module, `profile()` creates the profiler with
     `fn($k, ...$a) => $this->tt($k, ...$a)` and passes it `getDictionary()` and
     `getProjectStructure()` (which for now returns only `record_id_field`).
- **Done when:** `php tests/run.php 003` is green for FR-003-01 and 02.
- **Size:** M

### T003-03 [T] Kind of value and choices

- **Requirements:** FR-003-03, FR-003-04, FR-003-05
- **Files:** `tests/specs/003_dictionary_profiling.test.php`
- **What to do:** `value_kind` of `sex`, `site`, `comorb`, `fever` and a
  `truefalse` → `categorical`; `weight`, `hr`, `number_2dp` and `slider` →
  `numeric`; `glu` (no validation), `notes` and `email` → `text`.
  `parseChoices('1, Sí | 0, No | sin coma | 2,  Tal, cual |  | 3,x')` →
  `['1' => 'Sí', '0' => 'No', '2' => 'Tal, cual', '3' => 'x']`; `''` → `[]`;
  `$p['comorb']['choices']` and `choice_count === 3`; `fever` →
  `['1' => 'Yes', '0' => 'No']`; `truefalse` → `True/False`;
  `choicesFor(['field_type' => 'yesno', 'select_choices_or_calculations' => '9, Otro'])`
  is still Yes/No; a `calc` with `round([peso]*10000/([talla]*[talla]),1)`,
  a `text`, a `slider` and a `sql` with something in the column → `[]`; a
  `radio` and a `checkbox` keep their own.
- **Done when:** it fails on the `value_kind` values and on the implicit choices.
- **Size:** S

### T003-04 [I] valueKind(), parseChoices() and choicesFor()

- **Requirements:** FR-003-03, FR-003-04, FR-003-05
- **Files:** `classes/DictionaryProfiler.php`, `OmopCdmExportModule.php`
- **What to do:** implement `valueKind()` with the plan's table;
  `parseChoices()` with `explode('|')` and `explode(',', $chunk, 2)`;
  `IMPLICIT_CHOICES`, `DECLARED_CHOICE_TYPES` and `choicesFor()` (implicit →
  declared only if the type is in the list → `[]`). In `profileField()` compute
  `$choices = self::choicesFor($meta)` and `$valueKind` and pass them to `row()`.
  In the module, `public static function parseChoices(string $raw): array`
  that delegates.
- **Hints:** the `explode` limit of `2` is what keeps «Tal, cual» whole.
- **Done when:** `php tests/run.php 003` is green.
- **Size:** S

### T003-05 [T] Unit in the label

- **Requirements:** FR-003-06
- **Files:** `tests/specs/003_dictionary_profiling.test.php`
- **What to do:** `weight` → `kg`, `hr` → `bpm`; numeric `Glucose (mmol/L)`,
  `Saturación (%)`, `Temperatura (°C)` → their unit; `LDL (LDL)` and
  `Colesterol (poner número exacto)` → `null`; `glu` and `Peso (kg)` without
  validation → `null`; `Algo (mg)` integer → the reason starts with
  `r_fb_num_unit(mg)`.
- **Done when:** it fails because `unit_hint` is always `null`.
- **Size:** S

### T003-06 [I] extractUnit()

- **Requirements:** FR-003-06
- **Files:** `classes/DictionaryProfiler.php`
- **What to do:** add `$knownUnits` (the plan's list) and `extractUnit()`;
  call it only if `$valueKind === 'numeric'`. Until the classifier exists
  (spec 004), use as fallback reason `r_fb_num_unit` / `r_fb_num` for
  numerics, `r_fb_cat` for categoricals and `r_fb_text` for text, with domain
  `Measurement` for numerics and `Observation` for the rest.
- **Hints:** the regex has `/u` so that `°` and `µ` count as one character.
  Compare the normalised unit (`TextNormalizer::normalize`), but return the
  original: «mmHg», not «mmhg».
- **Done when:** `php tests/run.php 003` is green.
- **Size:** S

### T003-07 [T] Structural rules: Record ID, types without a value and identifiers

- **Requirements:** FR-003-07, FR-003-08, FR-003-09
- **Files:** `tests/specs/003_dictionary_profiling.test.php`
- **What to do:**
  - `identifier = ' Y '` → `is_identifier === true`.
  - Flagged `Postal code` → `NotMapped`, `high`, reason `r_phi_flag`,
    `mappable === 'no'`.
  - Flagged `Date of birth` (`date_dmy`) → is not `NotMapped`.
  - Flagged `Sexo` radio → is not `NotMapped` and the reason ends in `r_sfx_phi`.
  - Flagged `study_id` with structure `['record_id_field' => 'study_id']` →
    `Person`, `high`, `r_record_id`; without a structure → `NotMapped`.
  - For `descriptive`, `file`, `signature`, `calc` with label «Peso (kg)»:
    `NotMapped`, `high`, `mappable === 'no'`, reason `r_skip_type(<type>)`,
    `unit_hint === null`.
- **Done when:** all four groups fail.
- **Size:** S

### T003-08 [I] Structural rules in profileField()

- **Requirements:** FR-003-07, FR-003-08, FR-003-09
- **Files:** `classes/DictionaryProfiler.php`, `lang/*.ini`
- **What to do:** at the start of `profileField()`, in this order: Record ID
  (`value_kind` `'identifier'`), `$skipTypes` (`value_kind` `'none'`) and the
  identifier flag (`strtolower(trim($meta['identifier'])) === 'y'`) except for
  a date (`isDateValidation`) or `isSexLabel($label)`. Append `r_sfx_phi` to
  the end of the reason when the field is flagged and not excluded. Add the
  keys `r_record_id`, `r_skip_type`, `r_phi_flag` and `r_sfx_phi` to both
  `.ini` files.
- **Hints:** `isSexLabel` looks for `sexo`, `genero`, `sex`, `gender`,
  `sex at birth`, `sexo al nacer`, `gender identity`, `identidad de genero`
  as whole words in the normalised label surrounded by spaces.
- **Done when:** `php tests/run.php 003` is green.
- **Size:** M

### T003-09 [T] Dates, mappability and strategy

- **Requirements:** FR-003-10, FR-003-11, FR-003-12
- **Files:** `tests/specs/003_dictionary_profiling.test.php`
- **What to do:** `Fecha` (`date_ymd`) → `DateAnchor`, `high`,
  `r_date_anchor(date_ymd)`, no unit; `med_start` and `med_end` →
  `DateAnchor`; `datetime_seconds_ymd` → `value_kind` `date`. `mappable`:
  `site` `no`, `glu` `manual`, `notes` (when it is `Note`) `auto`, `weight`,
  `sex`, `dob` `auto`. Strategy: `weight` `null`; a `0..5` scale with numeric
  labels → `single_concept`; `bac` and `smear2` → `value_concept`;
  `fever`, `sex`, `comorb`, `drug` → `per_choice`; a Sí/No (Yes/No) in
  `Measurement` → `per_choice`.
- **Hints:** the `sex`, `notes` (Note) and `bac` cases depend on the
  classifier from spec 004; if it does not exist yet, those tests stay red and
  turn green with it. Note it in the review.
- **Done when:** the dates and the strategy fail.
- **Size:** S

### T003-10 [I] DateAnchor, mappability() and suggestedStrategy()

- **Requirements:** FR-003-10, FR-003-11, FR-003-12
- **Files:** `classes/DictionaryProfiler.php`, `lang/*.ini`
- **What to do:** `isDateValidation($v)`: `$v !== '' && strpos($v, 'date') === 0`;
  if it is one, `row(..., 'DateAnchor', 'high', $this->t('r_date_anchor', $validation), 'date', ...)`
  (004 will add the meaningful dates before this). Implement
  `mappability()`, `looksBinaryChoices()`, `looksLikeRatingScaleStatic()`
  (≥ 5 choices and `preg_match('/^\s*\d+/', $label)` on all but one) and
  `suggestedStrategy()`; use them in `row()`.
- **Hints:** for the scale it is the **label** that is checked, never the
  code: in REDCap every categorical uses 1, 2, 3.
- **Done when:** `php tests/run.php 003` is green (except what depends on 004).
- **Size:** M

### T003-11 [T] Translator and project structure

- **Requirements:** FR-003-13, FR-003-14
- **Files:** `tests/specs/003_dictionary_profiling.test.php`
- **What to do:** without a translator, numeric `Peso (kg)` has reason
  `r_keyword(peso)r_sfx_unit(kg)` (after 004); with
  `new DictionaryProfiler(fn($k, ...$a) => strtoupper($k) . ':' . implode(',', $a))`,
  `Fecha` gives `R_DATE_ANCHOR:date_ymd`; `make_module()->profile()['weight']['reason']`
  is in English. `getProjectStructure()` of the test project is the plan's
  array; with `\Project::$conf['RepeatingFormsEvents'] = [1 => 'WHOLE']`,
  `repeating === [['event_id' => 1, 'form' => '']]` (restore the value).
- **Done when:** the structure fails (incomplete).
- **Size:** S

### T003-12 [I] getProjectStructure()

- **Requirements:** FR-003-14
- **Files:** `OmopCdmExportModule.php`
- **What to do:** implement the function as in the plan with
  `new \Project($this->pid())`: `record_id_field` = `$proj->table_pk`,
  `forms` = `array_keys($proj->forms)`, events only if it is longitudinal
  (with `\REDCap::getEventNames(true, true)` for the unique name and
  `event_<id>` if missing), and `repeating` by walking `RepeatingFormsEvents`.
  Cache the result in `$structureCache`.
- **Hints:** wrap `getEventNames` in `try/catch`: in some versions it fails
  outside a project context.
- **Done when:** `php tests/run.php 003` is green.
- **Size:** M

### T003-13 [T] Candidate dates

- **Requirements:** FR-003-15
- **Files:** `tests/specs/003_dictionary_profiling.test.php`
- **What to do:** `dateFields()` of the test project is
  `['dob' => 'Date of birth', 'visit_date' => 'Visit date', 'death_date' => 'Date of death',
  'med_start' => 'Start date', 'med_end' => 'End date']`; `isDateField('dob')`
  yes, `weight` and `nope` no; `suggestedDateField('weight') === 'visit_date'`,
  `('drug') === 'med_start'`, `('med_end') === 'med_end'`,
  `('exitus', 'Death') === 'death_date'`. Add to `\REDCap::$dict` a field
  `other_field` in the instrument `no_dates`: `suggestedDateField('other_field') === null`
  and `('does_not_exist') === null`. Call `reset_fixture()` at the end.
- **Done when:** it fails because the methods do not exist.
- **Size:** S

### T003-14 [I] dateFields(), isDateField() and suggestedDateField()

- **Requirements:** FR-003-15
- **Files:** `OmopCdmExportModule.php`
- **What to do:** implement the three methods on top of `getDictionary()`.
  `suggestedDateField($field, $domain = null)`: if `$domain === 'Death'` and
  `death-date-field` is in the dictionary, that one; if the field is a date,
  the field itself; otherwise, the dates in the same `form_name` except the
  field itself and `setting('birth-date-field')`, returning the first one or
  `null`.
- **Hints:** use `$this->setting()`, not `getProjectSetting()`: the editor
  calls this function once per row.
- **Done when:** `php tests/run.php 003` is green.
- **Size:** S

### T003-15 [R] Performance, read-only and comments

- **Requirements:** NFR-003-01, NFR-003-02
- **Files:** `tests/specs/003_dictionary_profiling.test.php`, `classes/DictionaryProfiler.php`
- **What to do:** add the tests that `profile()` does not change `\REDCap::$dict`
  and that 1,000 synthetic fields are profiled in < 2 s
  (`microtime(true)`). In `profileField()`, comment on why the structural
  rules come in that order and why there are exceptions to the identifier
  flag.
- **Done when:** `php tests/run.php 003` is green and the review raises no questions.
- **Size:** S

---

## Definition of done for the spec

- [ ] All `003` tests green and no regressions in the rest of the suite.
- [ ] `php tests/run.php --trace 003` reports no orphan requirements.
- [ ] New texts in `lang/Spanish.ini` and `lang/English.ini` with the same keys.
- [ ] README updated if anything visible to users changes.
- [ ] Spec status changed to `Implemented`.
