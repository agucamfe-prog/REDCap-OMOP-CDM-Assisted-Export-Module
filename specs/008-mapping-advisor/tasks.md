# Tasks 008 — Mapping advisor

Before starting: read `constitution.md`, `AGENTS.md`, and the `spec.md` and
`plan.md` in this folder. Do the tasks in order. Each `[T]` must end with the
test **red**; the following `[I]` turns it green.

Run only this spec's tests: `php tests/run.php 008`

| Legend | |
|---|---|
| `[T]` | Write a test (red) |
| `[I]` | Implement (green) |
| `[R]` | Refactor, document or translate |
| `[P]` | Can be done in parallel with the previous one |

---

### T008-01 [T] Contract, severities and test helpers

- **Requirements:** FR-008-01, FR-008-02, FR-008-14
- **Files:** `tests/specs/008_mapping_advisor.test.php`
- **What to do:** write the `$adv` closure of the plan, which stores in
  `MappingStore::SETTING_KEY` a JSON with
  `fields => ['x' => $spec + ['state' => 'proposed', 'edited' => true]]` and
  `values => ['x' => …]` (each option completed with `raw_value`,
  `emit = true`, and null `concept_id` and `concept_name`), and `$has`/`$hasnt`.
  Cases: numeric haemoglobin (`f('x', 'base', 'text', 'Hemoglobin', '', 'number')`)
  in Measurement with concept, name and date but no unit → the first warning
  has the keys `severity, code, text` and the text is that of
  `adv_measurement_no_unit`; without a domain, NotMapped or DateAnchor → `[]`;
  `blocks()` and `needsConfirm()` with lists of one severity; after `curate()`
  and removing the unit of `weight` in the same request, `advise('weight')`
  changes; `advise('no_such_field') === []`.
- **Done when:** it fails with "Class MappingAdvisor not found" or `advise()` does not exist.
- **Size:** M

### T008-02 [I] Advisor skeleton

- **Requirements:** FR-008-01, FR-008-02, FR-008-14
- **Files:** `classes/MappingAdvisor.php`, `OmopCdmExportModule.php`
- **What to do:** a class with `check()` that returns `[]` for a `null`,
  `NotMapped` or `DateAnchor` domain; `w()` that uses
  `ttText('adv_' . $code, ...$args)`; `blocks()`, `needsConfirm()`. In the
  module, `advise()` with `$adviceCache[$field]` and `adviseAll()`; empty
  `$adviceCache` in `invalidateMappingCache()`. Implement
  `checkMeasurementValue()` for `measurement_no_unit` right away.
- **Done when:** the tests of T008-01 are green.
- **Size:** S

### T008-03 [T] Blocks

- **Requirements:** FR-008-03, NFR-008-01
- **Files:** `tests/specs/008_mapping_advisor.test.php`
- **What to do:** one case per block: field `base_complete` in Observation;
  `concept_id = -3000963`; `date_field = null` with
  `['default-date-field' => '']`; `date_field = 'gone_date'`; a Drug with
  `end_date_field = 'gone_end'`; a Visit field with concept 9202 (it blocks
  with the default configuration, not with `['generate-visits' => false]`).
  NFR-008-01: extract with `/w\('(block|warn|info)', '([a-z_]+)'/` the codes
  of `classes/MappingAdvisor.php` and check that the `block` ones are exactly
  the five.
- **Done when:** the tests fail.
- **Size:** S

### T008-04 [I] Blocks and dates

- **Requirements:** FR-008-03, FR-008-04
- **Files:** `classes/MappingAdvisor.php`
- **What to do:** `checkCompleteField()` (`substr($field, -9) === '_complete'`),
  `checkConceptId()` (negative → block and `return`), `checkDateSanity()`
  (Person/Death skipped; no date → `no_date_at_all` or `using_default_date`;
  non-existent date → `date_field_gone`; different instruments →
  `date_other_form`), `checkEndDate()`, `checkVisitConflict()` with
  `$module->visitConfig()['enabled']`.
- **Hints:** the instrument comes from `getDictionary()[$field]['form_name']`.
  Use `method_exists($this->module, …)` before calling configuration methods:
  the advisor is also used with minimal test modules.
- **Done when:** the tests of T008-03 are green.
- **Size:** M

### T008-05 [T] Dates, instrument design and content

- **Requirements:** FR-008-04, FR-008-05, FR-008-06
- **Files:** `tests/specs/008_mapping_advisor.test.php`
- **What to do:** `using_default_date` with `visit_date` in the text;
  `date_other_form` with `date_field = 'med_start'`; same-instrument date →
  `[]`; `end_date_ignored` in Measurement with `med_end`; a Death without a
  default date gives no date warnings. Design: `'Date of onset'`,
  `'Number of cigarettes'`, `'Ferritin (mg/dL)'` without validation;
  `'Date of count (kg)'` only gives the date one; with `integer` validation
  none. Content: identifier `'y'`; text in Observation (`free_text`) and in
  Note (no); number in Note (`note_not_text`); `notes` in Note with
  `['suppress-free-text' => true]` (`note_suppressed`) and without it `[]`.
- **Done when:** the tests fail.
- **Size:** M

### T008-06 [I] Instrument design and content

- **Requirements:** FR-008-05, FR-008-06
- **Files:** `classes/MappingAdvisor.php`, `lang/*.ini`
- **What to do:** `checkFieldValidation()` (only `field_type = text` without
  validation; the plan's term lists surrounded by spaces; final-unit regex
  `/\(\s*(kg|cm|mm|m|g|mg|ml|dl|l|%|mmhg|lpm|u\/l|mg\/dl|g\/dl|mmol|ui)\s*[^)]*\)\s*$/iu`;
  `return` after the first), `checkIdentifier()`, `checkFreeText()`,
  `checkNote()` (with `$module->pseudonymizer()->suppressesFreeText()`).
- **Hints:** compare against the normalised label surrounded by spaces
  (`' fecha '`, `' n '`) so as not to match word fragments.
- **Done when:** the tests of T008-05 are green.
- **Size:** M

### T008-07 [T] Yes/no, measurements and scales

- **Requirements:** FR-008-07, FR-008-08, FR-008-09
- **Files:** `tests/specs/008_mapping_advisor.test.php`
- **What to do:** `'Medication given'` (yesno) in Drug → `vague_yesno`;
  `'Isoniazid'` → `yesno_in_domain` (info); a radio `1, Yes | 2, No`
  "Procedure performed" in Procedure → `vague_yesno`; "Fever" in Condition →
  none. Measurements: without a unit, text, `Culture`
  (Positive/Negative/Contaminated) per option → `categorical_per_choice`, with
  `value_concept` not, a yes/no per option not. Scales with
  `'0, 0 - None | 1, 1 | 2, 2 | 3, 3 | 4, 4 - Worst'`: per option in
  Observation → `scale_per_choice`; single concept not; in Condition →
  `scale_wrong_domain`; in Measurement not; `North/South/East/West/Centre` is
  not a scale.
- **Done when:** the tests fail.
- **Size:** M

### T008-08 [I] Yes/no, measurements and scales

- **Requirements:** FR-008-07, FR-008-08, FR-008-09
- **Files:** `classes/MappingAdvisor.php`, `lang/*.ini`
- **What to do:** `checkVagueYesNo()` with `RISKY_YESNO_DOMAINS`, `VAGUE_TERMS`
  (stem with `*`) and `looksBinary()` (some label from the yes/no list);
  complete `checkMeasurementValue()` with
  `DictionaryProfiler::looksBinaryChoices()` and `looksLikeRatingScaleStatic()`;
  `checkRatingScale()` and `checkScaleDomain()` (≥ 5 options and at least
  `count − 1` labels with `/^\s*\d+/`).
- **Hints:** the advisor's `looksBinary()` is deliberately looser than the
  classifier's `looksBinaryChoices()`: "Presente/Ausente" counts as yes/no for
  the warning, but is exported as a result.
- **Done when:** the tests of T008-07 are green.
- **Size:** M

### T008-09 [T] Person, Death and data that is lost

- **Requirements:** FR-008-10, FR-008-11
- **Files:** `tests/specs/008_mapping_advisor.test.php`
- **What to do:** "Marital status" in Person → `person_no_rows`; with
  `['gender-field' => '', 'birth-date-field' => '']` → `person_not_configured`;
  `sex` after `curate()` → `[]`. Death: "Died" with `['death-date-field' => '']`
  → `death_no_date_config`; a `date_ymd` date field not; with a concept on
  option 1 → `death_concept_is_cause`, without a concept not. Losses: "Missed
  doses" (`integer`) in Drug → `numeric_lost` with `DRUG_EXPOSURE` in the text;
  in Observation not; "Fever" per option with both options emitting →
  `emits_negative`; with no stored options, or with 0 at `emit = false`, not.
- **Done when:** the tests fail.
- **Size:** S

### T008-10 [I] Person, Death and data that is lost

- **Requirements:** FR-008-10, FR-008-11
- **Files:** `classes/MappingAdvisor.php`, `lang/*.ini`
- **What to do:** `checkPersonDomain()` (list of used fields from
  `personConfig()` plus `record_id_field`; `count <= 1` →
  `person_not_configured`), `checkDeath()` (`deathConfig()`, `isDateField()`),
  `checkNumericInValuelessDomain()` (`CdmModel::tableForDomain()` and
  `strtoupper` of the table), `checkEmitAll()` (default value with
  `DictionaryProfiler::isNegativeChoice()`).
- **Done when:** the tests of T008-09 are green.
- **Size:** M

### T008-11 [T] Concepts without a vocabulary and extension point

- **Requirements:** FR-008-12, FR-008-13
- **Files:** `tests/specs/008_mapping_advisor.test.php`
- **What to do:** `concept_id = '00'` → `concept_zero`; `0` → not
  (characterises the proposed amendment); `comorb` with the same concept on two
  options → `same_concept_all`; without a name → `no_concept_name`; option 1
  with a concept and no name → `no_concept_name_option` with `1` in the text;
  `'Peso corporal'` and `'Do you smoke?'` → `concept_name_is_label`;
  "Body weight" on a field "Body weight" → `[]`. Extension: build the index
  with `Vocabulary::buildIndex(dirname(__DIR__) . '/fixtures/vocab/CONCEPT.csv', $tmp)`,
  pass it to `$adv` and check that a concept without a name no longer gives
  `no_concept_name`.
- **Done when:** the tests fail.
- **Size:** M

### T008-12 [I] Concepts without a vocabulary

- **Requirements:** FR-008-12, FR-008-13
- **Files:** `classes/MappingAdvisor.php`, `lang/*.ini`
- **What to do:** complete `checkConceptId()` (zero, same concept on all
  emitting options), `checkConceptName()` with `isLocalLabel()`,
  `looksSpanish()` (accents, ñ, ¿¡, unambiguous words) and
  `looksLikeSurveyWording()` (question mark or question openings); in
  `check()`, `if (!$this->checkVocabulary(...)) { $this->checkConceptName(...); }`
  with `checkVocabulary()` returning `false` while spec 014 does not exist.
- **Hints:** length is not a signal: LOINC names are long. Only words that do
  not exist in English, or it would give false positives.
- **Done when:** the tests of T008-11 are green.
- **Size:** M

### T008-13 [T] Confirmation when approving

- **Requirements:** FR-008-15
- **Files:** `tests/specs/008_mapping_advisor.test.php`
- **What to do:** with `tests/support/run_page.php` and `pages/editor.php`:
  `glu` complete (with `warn` warnings) and `state = approved` → `confirm` and
  still `proposed`; with `confirmed = 1` → `approved` and log entry
  `Approved with warnings` with `free_text` among the codes; `weight` with
  `date_field = 'gone_date'` and `confirmed` → `blocked`; `weight` without its
  own date (only `using_default_date`) is approved without confirming.
- **Hints:** if the editor (007) does not exist yet, this task and the next
  one are done after finishing T007-08.
- **Done when:** the tests pass with the editor of spec 007; if they fail, fix
  the use of `blocks()`/`needsConfirm()` in `pages/editor.php`.
- **Size:** S

### T008-14 [R] Bilingual texts and README

- **Requirements:** NFR-008-02
- **Files:** `lang/*.ini`, `tests/specs/008_mapping_advisor.test.php`, `README.md`
- **What to do:** a test that checks that the 40 codes of the source code have
  `adv_<code>` in both `.ini` files. Check that each text says what happens if
  ignored and how to fix it. Document *Design warnings* in the README.
- **Done when:** `php tests/run.php --trace 008` with no orphans.
- **Size:** S

---

## Spec definition of done

- [ ] All `008` tests green and the rest of the suite without regressions.
- [ ] `php tests/run.php --trace 008` with no orphan requirements.
- [ ] New texts in `lang/Spanish.ini` and `lang/English.ini` with the same keys.
- [ ] README updated if anything visible to the user changes.
- [ ] Spec status changed to `Implemented`.
