# Tasks 007 — Mapping editor

Before starting: read `constitution.md`, `AGENTS.md`, and the `spec.md` and
`plan.md` in this folder. Do the tasks in order. Each `[T]` must end with the
test **red**; the following `[I]` turns it green.

Run only this spec's tests: `php tests/run.php 007`

| Legend | |
|---|---|
| `[T]` | Write a test (red) |
| `[I]` | Implement (green) |
| `[R]` | Refactor, document or translate |
| `[P]` | Can be done in parallel with the previous one |

---

### T007-01 [T] Athena search

- **Requirements:** FR-007-10, FR-007-11
- **Files:** `tests/specs/007_mapping_editor.test.php`
- **What to do:** with `$m0 = make_module();`:
  `parse_str(parse_url($m0->athenaUrl('body weight', 'Measurement', 'LOINC'), PHP_URL_QUERY), $q)`
  gives exactly `query, standardConcept=Standard, invalidReason=Valid, page=1, pageSize=15, domain, vocabulary`;
  without a domain there is no `domain=`; `athenaDomain()` for Person, Death,
  NotMapped, DateAnchor, `null` and Measurement; `athenaVocabulary()` for
  Measurement, Drug, Person, Condition, Visit and Note. Queries:
  `('Hemoglobina (g/dL)', 'hb', 'g/dL') → 'hemoglobin mass volume'`,
  `('Peso basal (kg)', 'peso') → 'body weight'`,
  `'Sudoración nocturna' → 'night sweats'`, `'No realizada' → 'not performed'`,
  `'¿Presentó fiebre?' → 'fever'`, `'Haemoglobin' → 'hemoglobin'`, five words
  at most, `('Por favor, indique la fecha', 'campo_x') → 'campo_x'`;
  `loincProperty()` for `mmol/L`, `U/L`, `mUI/L`, `%`, `x10^3/uL`, `kg`, `null`.
- **Done when:** it fails with "Call to undefined method athenaUrl()".
- **Size:** M

### T007-02 [I] Athena methods

- **Requirements:** FR-007-10, FR-007-11
- **Files:** `OmopCdmExportModule.php`
- **What to do:** implement the plan's five methods. `athenaQuery()`: remove
  `/\([^()]{1,20}\)\s*$/`; `TextNormalizer::americanize()` and `normalize()`;
  surround with spaces; remove the noise; walk the glossary sorted by key
  length (`uksort`, longest first) adding the English words and removing the
  Spanish one; remove `PROTOCOL_WORDS`; add what is left except stopwords,
  `UNIT_WORDS`, numbers and one-letter words; `array_slice(…, 0, 5)`; append
  `loincProperty($unit)`.
- **Hints:** qualifiers are removed **after** translating: otherwise "No
  realizada" would be left as a loose "no". An empty translation in the
  glossary (`'persistente' => ''`) deletes the word without adding anything.
- **Done when:** the tests of T007-01 are green.
- **Size:** M

### T007-03 [T] Action protection and read-only mode

- **Requirements:** FR-007-01, FR-007-02
- **Files:** `tests/specs/007_mapping_editor.test.php`
- **What to do:** `$runPage = require dirname(__DIR__) . '/support/run_page.php';`
  and `$post = fn($settings, $p) => $runPage('pages/editor.php', ['settings' => $settings, 'post' => $p + ['csrf' => 'tok']]);`.
  Cases: `csrf = 'other'` → 403 `ed_err_csrf` and no `omop-mapping-json`;
  `action = 'drop-tables'` → 400 `ed_err_unknown_action`; `field = 'nope'` →
  400; `domain = 'Banana'` → 400 and JSON headers; a mapping with
  `{"fields":{"weight":"broken"}}` does not break `save-field` (200, thanks to
  FR-006-01); a simulated failure in the middle of `save-field` (the `fail`
  option of `tests/support/run_page.php`) → 500 with `where` and log entry
  `Editor error`. With `\TestUser::$super = false` and no rights: the page says
  `ed_readonly`, the controls carry `disabled`, there is no
  `omopState(this,'approved')` and `save-field` → 403 `ed_err_rights`; with
  `['design' => 1]` editing is possible. Restore `$super = true` at the end.
- **Done when:** the tests fail because `pages/editor.php` does not exist.
- **Size:** M

### T007-04 [I] Actions skeleton

- **Requirements:** FR-007-01, FR-007-02
- **Files:** `pages/editor.php`, `lang/*.ini`
- **What to do:** token in `$_SESSION['omop_csrf']` (creating it with
  `bin2hex(random_bytes(16))` if missing, after `@session_start()` if the
  session is not active); a closure `$respond(array $payload, int $status = 200)`
  that empties the buffers, sets code and headers, writes the JSON and calls
  `exit`; a `try/catch(\Throwable)` block with `hash_equals`,
  `userCanEditMapping()` and the `ed_err_unknown_action` response at the end.
  If there is no action, render a minimal table with one row per field and the
  `ed_readonly` notice when editing is not allowed.
- **Hints:** the catch must log with `$module->log('Editor error', ['action', 'error', 'file'])`.
- **Done when:** the tests of T007-03 are green.
- **Size:** M

### T007-05 [T] Saving, and what you see is what is saved

- **Requirements:** FR-007-04, FR-007-05
- **Files:** `tests/specs/007_mapping_editor.test.php`
- **What to do:** `save-field` of `weight` with `unit_source_value = kg`,
  `dict_hash = x` and `approved_by = me`: responds `ok, spec, error, warnings, counts`,
  stores `kg` and does not write the other two; with `concept_id = '2089-1'`
  the `spec` carries `_bad_concept`. First time on `fever` with only `notes`:
  stores `Condition`, `per_choice` and `visit_date`; after changing to
  `single_concept`, another save does not revert it; `drug` with
  `date_field = ''` respects the default date on that save and the next one;
  `bac` with `domain = Observation` stores `value_concept`; `weight` does not
  store the suggested unit.
- **Done when:** the tests fail.
- **Size:** M

### T007-06 [I] `save-field`

- **Requirements:** FR-007-04, FR-007-05
- **Files:** `pages/editor.php`, `classes/DictionaryProfiler.php`, `OmopCdmExportModule.php`
- **What to do:** steps 1, 4 and 6 of the plan. Add
  `DictionaryProfiler::suggestedStrategy()` (scale → `single_concept`;
  non-binary Measurement/Observation → `value_concept`; the rest →
  `per_choice`; without options → `null`) and `suggestedDateField()` in the
  module (Death with a configured death date → that one; a date field → itself;
  otherwise, the first date of its instrument that is not the birth date).
- **Hints:** check with `array_key_exists`, not `isset`: an empty date is a
  decision. Compare with `empty($current['edited'])` read **before** saving.
- **Done when:** the tests of T007-05 are green.
- **Size:** M

### T007-07 [T] Approving from the editor

> Note: this task and the next one need the advisor of spec 008
> (`$module->advise()`, `MappingAdvisor::blocks()` and `needsConfirm()`). If 008
> is not done, do it first or leave T007-07 and T007-08 for later.

- **Requirements:** FR-007-06
- **Files:** `tests/specs/007_mapping_editor.test.php`
- **What to do:** complete `weight` (`Measurement`, `3025315`, `Body weight`,
  `kg`, `visit_date`) with `state = approved` → `approved`,
  `counts.approved = 1`, `error = null`; without a concept → `proposed` and
  `error = ttText('ed_err_no_concept')`; without a unit → `confirm = true` with
  `measurement_no_unit`; with `confirmed = 1` → `approved` and log entry
  `Approved with warnings`; with `date_field = 'nope_date'` and `confirmed` →
  `blocked = true` and still `proposed`.
- **Done when:** the tests fail.
- **Size:** S

### T007-08 [I] Approval with the advisor

- **Requirements:** FR-007-06
- **Files:** `pages/editor.php`
- **What to do:** step 5 of the plan, with `MappingAdvisor::blocks()` and
  `needsConfirm()` (spec 008) and `$module->mappingCounts()` in every response.
- **Hints:** save the changes **before** asking for the warnings: the advisor
  reads from the store.
- **Done when:** the tests of T007-07 are green.
- **Size:** S

### T007-09 [T] Rendering rows, options, dates and what is not asked

- **Requirements:** FR-007-03, FR-007-07, FR-007-08, FR-007-09, FR-007-10, NFR-007-01
- **Files:** `tests/specs/007_mapping_editor.test.php`
- **What to do:** the plan's `$row` and `$omop` closures. After `curate()` and
  with the label of `notes` changed: 18 rows; `data-state` of `weight`, `dob`
  and `notes` (`approved`, `config`, `review`); `data-cur="Measurement"`;
  warning `adv_free_text` on `glu`; `ed_drift_dictionary` on `notes`; manual
  and PHI badges; three state buttons. Options: `save-value` of `fever`
  (change, repetition without change, `emit = 0`); on a page without a
  mapping, "Yes" ticked and "No" not, `per_choice` on `fever` and
  `value_concept` on `bac`, visibility of `.omop-single`, `.omop-choices` and
  `.omop-vc-note`. Dates: exact `OMOP.dateFields`, `data-shown="visit_date"`
  with `ed_date_suggested`, end date visible on `drug` and hidden on `weight`,
  unit `kg`. No questions: `dob`, `site`, `med_start` and a `descriptive` field
  without `data-k="concept_id"`, date, unit or strategy, with `ed_role_birth`
  and `ed_role_care_site`. Athena: on `weight` both URLs, the `terms/3025315`
  link; on `fever` the question's URL; on `bac` the `Meas Value` one.
  NFR-007-01: no `<option value="Condition"` in the HTML and `OMOP.domains`
  with 13.
- **Done when:** the tests fail.
- **Size:** L

### T007-10 [I] Rendering and `save-value`

- **Requirements:** FR-007-03, FR-007-07, FR-007-08, FR-007-09, FR-007-10, NFR-007-01
- **Files:** `pages/editor.php`, `lang/*.ini`
- **What to do:** the `save-value` action (keys `emit`, `concept_id`,
  `concept_name`, `source_value`; `updateValue()`). Rendering according to the
  plan's "Rendering a row" block; the `OMOP` object with `json_encode(...,
  JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP)`; the functions
  `omopFillShared()` and `omopFillDates()` that fill in domains and dates.
- **Hints:** `$h = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8')`
  and `$t = fn($k, ...$a) => $h($module->ttText($k, ...$a))` to avoid escaping
  twice. The advisor's warnings already come as plain text.
- **Done when:** the tests of T007-09 are green.
- **Size:** L

### T007-11 [T] Bulk actions and reset

- **Requirements:** FR-007-12, FR-007-13
- **Files:** `tests/specs/007_mapping_editor.test.php`
- **What to do:** `bulk-preview` of `exclude-manual` = `['glu']` without
  writing; `bulk` excludes it; `exclude-notmapped` with a `descriptive` field;
  `approve-ready` with complete `weight`, `glu` with warnings and `fever` with
  the "Yes" option → `['weight', 'fever']`, and when applied `fever` has option
  `0` materialised; after `curate()`, `exclude-pending` changes 5 and leaves 9
  approved and 4 configuration; a field in `review` is not included.
  Proposals: `reset-all-preview` = `{weight: 'Observation → Measurement'}` and
  respects the approved `glu`; `reset-all` applies it; `reset-proposal` of
  `glu`; the `omopReset(this)` button only on `glu`. Reset: the `impact` of
  9/9/0/13; without `confirmed` → 400; `BORRAR` in English → 400
  `ed_reset_all_unconfirmed`; `' delete '` erases; with the Spanish language,
  `borrar`; afterwards the page offers `omopRestore()`; `restore-backup`
  restores 9 and a second time → 400 `ed_restore_none`.
- **Done when:** the tests fail.
- **Size:** M

### T007-12 [I] Bulk actions and reset

- **Requirements:** FR-007-12, FR-007-13
- **Files:** `pages/editor.php`, `lang/*.ini`
- **What to do:** the plan's table actions on `MappingStore` (`bulkState()`,
  `materialiseValues()`, `resetToProposal()`, `resetImpact()`, `reset()`,
  `restoreBackup()`), filtering by `mappingRows()[...]['state'] === 'proposed'`.
  Notice `ed_restore_available` with a button if `backupInfo()` is not `null`
  (only to those who can edit).
- **Hints:** compare the word with `mb_strtoupper(trim(...))` on both sides.
  `approve-ready` excludes `null`, `NotMapped` and `DateAnchor`.
- **Done when:** the tests of T007-11 are green.
- **Size:** M

### T007-13 [T] Count with the configuration fields

- **Requirements:** FR-007-15
- **Files:** `tests/specs/007_mapping_editor.test.php`
- **What to do:** render the editor without a mapping and, with
  `$omop($html)`, check that `OMOP.tpl` contains `{5}`, that the Spanish
  `ed_counts` does too, that `OMOP.counts.config === 4`, and that
  `pages/editor.php` contains `.replace('{5}', counts.config` (what can be
  checked without a browser of a `[manual]` requirement).
- **Done when:** the test fails because the template has no `{5}`.
- **Size:** S

### T007-14 [I] Browser interaction

- **Requirements:** FR-007-14, FR-007-15
- **Files:** `pages/editor.php`, `lang/*.ini`
- **What to do:** the plan's JavaScript functions: the `omopQueue` queue,
  `omopSend()` with manual parsing and `omopReportBadResponse()`, saving on
  `change`, `omopState()` with forced `blur` and confirmation, bulk and reset
  dialogs, `omopFilter()` and `omopRender()` with the bar formula. Add `{5}`
  (configuration fields) to `ed_counts` in both `.ini` files and
  `.replace('{5}', counts.config || 0)` in `omopRender()`.
- **Hints:** render warnings with `textContent`, never with `innerHTML`.
  `omopRender()` must subtract `counts.config` from the bar and show it in the
  count.
- **Done when:** the manual verification of FR-007-14 and FR-007-15 in the
  spec passes in the Docker environment.
- **Size:** L

### T007-15 [R] Bilingual and documentation

- **Requirements:** NFR-007-02
- **Files:** `tests/specs/007_mapping_editor.test.php`, `lang/*.ini`, `README.md`
- **What to do:** a test that extracts with `/(?:ttText|\$t)\('([a-z_]*[a-z])'[,)]/`
  the keys of `pages/editor.php`, plus `ed_state_*` and `ed_role_*`, and checks
  that they are in both `.ini` files. Document in the README *Athena search*,
  *What the editor does not ask*, *Default values*, *Bulk actions* and
  *Resetting the mapping*.
- **Done when:** `php tests/run.php --trace 007` with no orphans.
- **Size:** S

---

## Spec definition of done

- [ ] All `007` tests green and the rest of the suite without regressions.
- [ ] `php tests/run.php --trace 007` with no orphan requirements.
- [ ] New texts in `lang/Spanish.ini` and `lang/English.ini` with the same keys.
- [ ] Manual verification of FR-007-14 and FR-007-15 done in the Docker environment.
- [ ] README updated if anything visible to the user changes.
- [ ] Spec status changed to `Implemented`.
