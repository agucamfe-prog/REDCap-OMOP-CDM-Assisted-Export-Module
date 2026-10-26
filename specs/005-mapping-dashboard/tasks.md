# Tasks 005 — Mapping dashboard

Before starting: read `constitution.md`, `AGENTS.md`, and the `spec.md` and
`plan.md` in this folder. Do the tasks in order. Each `[T]` must end with the
test **red**; the following `[I]` turns it green.

Run only this spec's tests: `php tests/run.php 005`

| Legend | |
|---|---|
| `[T]` | Write a test (red) |
| `[I]` | Implement (green) |
| `[R]` | Refactor, document or translate |
| `[P]` | Can be done in parallel with the previous one |

---

### T005-01 [T] Count per domain

- **Requirements:** FR-005-04
- **Files:** `tests/specs/005_mapping_dashboard.test.php`
- **What to do:** create the file with `$m0 = make_module();` and
  `$sum = $m0->profileSummary($m0->profile());`. Check that
  `array_sum($sum) === 18`, that the first key is `Measurement` with 4, that
  there are 10 domains and that the values go from highest to lowest (compare
  them with a copy sorted with `rsort`).
- **Done when:** `php tests/run.php 005` fails with "Call to undefined method
  profileSummary()".
- **Size:** S

### T005-02 [I] `profileSummary()`

- **Requirements:** FR-005-04
- **Files:** `OmopCdmExportModule.php`
- **What to do:** add `public function profileSummary(array $profile): array`
  that accumulates `$summary[$row['suggested_domain']]++` and ends with
  `arsort()`.
- **Hints:** do not call `profile()` inside: it receives the profile, so it can
  be tested with a made-up profile.
- **Done when:** the test of T005-01 is green.
- **Size:** S

### T005-03 [T] Page runner, header and cards

- **Requirements:** FR-005-01, FR-005-02, FR-005-03
- **Files:** `tests/support/run_page.php`, `tests/specs/005_mapping_dashboard.test.php`
- **What to do:**
  1. Write `tests/support/run_page.php` as the plan describes: it returns a
     closure `fn(string $page, array $o): array` that serialises the simulated
     state (`\REDCap::$dict`, `$data`, `$dag`, `\Project::$conf`, user,
     language, `settings`, `post`, `get`) to a temporary file, launches
     `PHP_BINARY` with a child script and returns `out`, `err`, `json`,
     `headers`, `status`, `settings` and `logs`. In the child, define
     `header()`, `http_response_code()` and `session_status()` in the
     `OmopCdm\OmopCdmExportModule` namespace, and create
     `ProjectGeneral/header.php` and `footer.php` that only write
     `<!--rc-header-->` and `<!--rc-footer-->`.
  2. In the test, `$runPage = require dirname(__DIR__) . '/support/run_page.php';`
     and a closure `$cards` that extracts with
     `/<div class="n">\s*(\d+)\s*<\/div>\s*<div class="l">([^<]*)<\/div>/` the
     label → number pairs.
  3. Check the title, `CDM v5.4` and `CDM v5.3` with
     `['cdm-version' => '5.3']`, the six expected cards (`Fields 18`,
     `With a target 17`, `Low confidence 2`, `Manual coding 1`,
     `Identifiers 0`, `Single event 1`), and the longitudinal case: with
     `\Project::$conf['longitudinal'] = true` and three events in `eventInfo`,
     the `Events` card is 3. Repeating notice with `tt('repeating_note', 1)`;
     without `RepeatingFormsEvents`, no `alert-info`.
- **Hints:** restore the environment with `reset_fixture()` after each change
  to the data dictionary or to `Project::$conf`. Pages end in `exit`: that is
  why they run in another process.
- **Done when:** the tests fail because `pages/dashboard.php` does not exist.
- **Size:** M

### T005-04 [I] Header and cards

- **Requirements:** FR-005-01, FR-005-02, FR-005-03
- **Files:** `pages/dashboard.php`, `config.json`, `lang/*.ini`
- **What to do:** create the page in the module's namespace. Compute
  `$structure`, `$profile`, `$summary` and `$cdmVer` (with `$module->setting()`,
  not `getProjectSetting()`); include `ProjectGeneral/header.php`; render
  `<h3>` with `dash_title` and `CDM v<?= htmlspecialchars($cdmVer) ?>`, the
  `dash_intro` paragraph, the six `.omop-card` cards (`.n` number, `.l` label)
  and the `repeating_note` notice if `$structure['repeating']` is not empty;
  end with `footer.php`. Add the link in `config.json` and the keys `dash_*`,
  `card_*` and `repeating_note` to both `.ini` files.
- **Done when:** the tests of T005-03 are green.
- **Size:** M

### T005-05 [T] Breakdown, table, badges and escaping

- **Requirements:** FR-005-04, FR-005-05, FR-005-06, FR-005-07, FR-005-08
- **Files:** `tests/specs/005_mapping_dashboard.test.php`
- **What to do:** with closures `$tbody` (what is between `<tbody>` and
  `</tbody>`) and `$row($html, 'weight')` (the `<tr>` containing
  `<code>weight</code>`), check: `Measurement <b>4</b>`, `Not mapped <b>1</b>`,
  `Date anchor <b>2</b>`; 18 `<tr>` in the body; in the row of `weight` the
  label, `base`, `number`, `numeric`, `unit: kg`, `<b>Measurement</b>`,
  `measurement`, `medium` and the reason; `3 options` in `comorb`; label
  truncated to 90 characters; manual badge on `glu` and not on `weight`;
  confidence colours of `bac`, `weight` and `glu`; PHI on a `phone` field
  flagged `'y'`. Escaping with an `evil` field with instrument `base<i>`,
  label `Dose & "mg" <script>alert(1)</script>` and validation `x"><b>`.
  Proposal and not mapping: after `curate()` and saving `weight` as
  Observation, the row still says Measurement and `settings` does not change.
- **Done when:** the tests fail because the table is missing.
- **Size:** M

### T005-06 [I] Breakdown and table

- **Requirements:** FR-005-04 to FR-005-08
- **Files:** `pages/dashboard.php`, `lang/*.ini`
- **What to do:** render `domain_breakdown` and one `<span class="badge badge-light">`
  per domain of `$summary` with `$module->domainLabel($domain)` (it translates
  `NotMapped` and `DateAnchor`; do not repeat that logic in the page). Then,
  the table `#omop-table` with the seven `th_*` columns and one row per field
  according to FR-005-05, with `mb_strimwidth($label, 0, 90, '…')`, the badges
  of FR-005-06 and `htmlspecialchars` on every value.
- **Hints:** the classifier already strips HTML tags from `field_label`
  (`strip_tags`); escaping is still mandatory for `&` and quotes. Do not use
  the store (`store()`) in this page.
- **Done when:** the tests of T005-05 are green.
- **Size:** M

### T005-07 [T] CSV download

- **Requirements:** FR-005-09, FR-005-13
- **Files:** `tests/specs/005_mapping_dashboard.test.php`
- **What to do:** `$runPage(..., ['get' => ['download' => 'csv']])`. Check the
  BOM, the 14-column header (`str_getcsv` of each line), 19 lines, the full row
  of `weight`
  (`weight, base, text, number, Weight (kg), '', numeric, kg, auto, 0, Measurement, measurement, medium, <reason>`),
  `is_identifier = y` on `phone`, the `Content-Type` and `Content-Disposition`
  headers with `omop_propuesta_pid1_<date('Ymd')>.csv`, the link
  `pages/dashboard.php&download=csv` in the dashboard, and that the output
  contains neither `<!--rc-header-->` nor `<`.
- **Done when:** the tests fail (the page renders the HTML).
- **Size:** S

### T005-08 [I] CSV download

- **Requirements:** FR-005-09, FR-005-13
- **Files:** `pages/dashboard.php`, `lang/*.ini`
- **What to do:** at the top of the file, before the `require` of the header,
  `if ($download === 'csv') { header(...); $out = fopen('php://output', 'w'); fwrite($out, "\xEF\xBB\xBF"); fputcsv(...); ...; exit; }`.
  Add the `btn_csv` button.
- **Hints:** `exit` and not `return`: after a `return` the framework would keep
  printing. No `echo` before the block.
- **Done when:** the tests of T005-07 are green.
- **Size:** S

### T005-09 [T] JSON download and log

- **Requirements:** FR-005-10, FR-005-11, FR-005-13
- **Files:** `tests/specs/005_mapping_dashboard.test.php`
- **What to do:** with `download=json`: `json_decode` of the output; keys
  exactly `generated, project_id, cdm_version, structure, proposal`;
  `structure == getProjectStructure()`, `proposal == profile()`; `.json` file
  name; `download=json` link. Log: `logs` is
  `[['Mapping proposal download (JSON)', ['module_version' => 'vtest']]]`,
  and the CSV one says `(CSV)`. A `download=xml` renders the dashboard.
- **Done when:** the tests fail.
- **Size:** S

### T005-10 [I] JSON download and `audit()`

- **Requirements:** FR-005-10, FR-005-11
- **Files:** `pages/dashboard.php`, `OmopCdmExportModule.php`
- **What to do:** a `download === 'json'` block like the CSV one, with
  `JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES`. Add
  `audit(string $message, array $params = [])` to the module, which adds
  `module_version` and calls `log()`. Call `audit()` after writing each
  download.
- **Done when:** the tests of T005-09 are green.
- **Size:** S

### T005-11 [I] Table filter

- **Requirements:** FR-005-12
- **Files:** `pages/dashboard.php`, `lang/*.ini`
- **What to do:** an `<input id="omop-search">` with `filter_placeholder` and a
  `<script>` that on `input` walks `#omop-table tbody tr` and sets
  `style.display = 'none'` on the rows whose `textContent.toLowerCase()` does
  not contain the lower-cased text.
- **Done when:** the manual verification of FR-005-12 in the spec works in the
  Docker environment.
- **Size:** S

### T005-12 [R] Bilingual and unconfigured project

- **Requirements:** NFR-005-01, NFR-005-02
- **Files:** `tests/specs/005_mapping_dashboard.test.php`, `lang/*.ini`, `README.md`
- **What to do:** a test that extracts with `preg_match_all("/tt\\('([a-z_]*[a-z])'[,)]/")`
  the keys used in `pages/dashboard.php` (plus `conf_*` and `domain_*`, which
  are built dynamically) and checks that they are in both `.ini` files; a test
  that opens the dashboard with `settings => []` and writes nothing to `err`.
  Add any missing keys and document the dashboard in the README.
- **Done when:** `php tests/run.php --trace 005` with no orphans.
- **Size:** S

---

## Spec definition of done

- [ ] All `005` tests green and the rest of the suite without regressions.
- [ ] `php tests/run.php --trace 005` with no orphan requirements.
- [ ] New texts in `lang/Spanish.ini` and `lang/English.ini` with the same keys.
- [ ] Manual verification of FR-005-12 done in the Docker environment.
- [ ] README updated if anything visible to the user changes.
- [ ] Spec status changed to `Implemented`.
