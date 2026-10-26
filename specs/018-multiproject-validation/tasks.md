# Tasks 018 — Validation with realistic projects

Before you start: read `constitution.md`, `AGENTS.md`, and the `spec.md` and
`plan.md` in this folder. Do the tasks in order. Each `[T]` must end with the
test **red**; the following `[I]` turns it green.

This spec does not change production code: whatever you find is fixed as an
amendment in the affected spec (T018-11).

Run only this spec's tests: `php tests/run.php 018`

| Legend | |
|---|---|
| `[T]` | Write a test (red) |
| `[I]` | Implement (green) |
| `[R]` | Refactor, document or translate |
| `[P]` | Can be done in parallel with the previous task |

---

### T018-01 [I] The 10 projects and their reference mapping

- **Requirements:** FR-018-08, NFR-018-04
- **Files:** `tests/eval/specs.php`
- **What to do:** a file that returns `$P['P1'] … $P['P10']`, each one with
  `title`, `lang`, `mode` (`manual` | `annotation` | `import` | `suggest`),
  `n` (records), `events`, `forms`, `repeat`, `dags`, `settings` (module
  settings) and `fields`. Each field with `F($name, $form, $type, $label, $o)`,
  where `$o` carries `ch`, `v`, `id`, `g` (generator), `m` (the curator's
  mapping) or `t` (acceptable domains, `'Visit|DateAnchor'`). P5 and P10 share
  the stroke instrument through a closure `$stroke('es' | 'en')`. The titles,
  labels and choices of the Spanish projects are written in Spanish, as those
  teams would.
- **Hints:** the 9 areas and the designs are in the table in the spec.
  Everything is invented: no real name, DNI or NHC (the generators make them
  up).
- **Done when:** `php -r '$P = require "tests/eval/specs.php"; echo array_sum(array_map(fn($p) => count($p["fields"]), $P));'` prints 186.
- **Size:** L

### T018-02 [T] Regression corpus

- **Requirements:** FR-018-01, FR-018-02
- **Files:** `tests/specs/018_multiproject_validation.test.php`
- **What to do:** read `tests/fixtures/eval10/fields.csv` with
  `fgetcsv(..., ',', '"', '')` and check: the exact header (the 10 columns
  from the plan); 186 rows; projects `P1`…`P10` in that order; 96 `es` in
  P1–P5 and 90 `en` in P6–P10; every `expected` (separated by `|`) in
  `CdmModel::validDomains()`; no repeated names per project; the 10
  `record_id` fields expect `Person`; `dni`, `nhc`, `iniciales` expect
  `NotMapped`. Run `shell_exec(PHP_BINARY . ' tests/eval/build_corpus.php -')`
  and compare it with the file.
- **Hints:** do not `require` `specs.php` in the test: its function `F()`
  clashes with `f()` from `tests/lib/fixtures.php` (PHP function names are
  case-insensitive).
- **Done when:** the test fails because `fields.csv` does not exist.
- **Size:** S

### T018-03 [I] `build_corpus.php`

- **Requirements:** FR-018-02
- **Files:** `tests/eval/build_corpus.php`, `tests/fixtures/eval10/fields.csv`
- **What to do:** CLI guard; `require specs.php`; walk P1…P10 (fixed order:
  in `specs.php` P10 comes before P5); `expected` = `NotMapped` if `m.x`,
  otherwise `m.d`, otherwise `t`; `record_id` → `Person`; if it ends up empty,
  an error and `exit(1)`. With `-` it writes to `php://stdout`; otherwise, to
  the file. Generate the corpus.
- **Done when:** `php tests/run.php 018` is green (corpus section).
- **Size:** S

### T018-04 [T] Classifier measurement

- **Requirements:** FR-018-03, NFR-018-01, NFR-018-02, NFR-018-03
- **Files:** `tests/specs/018_multiproject_validation.test.php`
- **What to do:** group by project, build the dictionary with
  `f(field_name, form_name, field_type, label, choices, validation, '', identifier)`
  and call `(new DictionaryProfiler())->profile($dict, ['record_id_field' => first field])`.
  Accumulate hits per project, per language and in total; keep one line per
  miss (`P2 es hipoglucemias … → Condition (expected Observation|Measurement)`)
  and, if `!Harness::$quiet`, print the summary and the misses. Tests: 186
  measured with a valid domain; misses = total − hits; overall ≥ 0.97; each
  language ≥ 0.95; the `holdout` set of `tests/fixtures/benchmark.csv` ≥ 0.70
  (as in 004).
- **Done when:** with the round 1 classifier (154/186) the NFR-018-01 test
  fails.
- **Size:** M

### T018-05 [I] Round 2: general fixes in spec 004

- **Requirements:** NFR-018-01, NFR-018-02, NFR-018-03, FR-018-12
- **Files:** `specs/004-domain-classifier/spec.md`, `tests/specs/004_domain_classifier.test.php`, `classes/DictionaryProfiler.php`
- **What to do:** group the 32 misses of round 1 by cause (the *Results*
  table in the spec) and, in spec 004, add for each cause a requirement with
  its test and its *Amendments* row; then fix the general rule. Measure
  again.
- **Hints:** never a rule for a single field; do not look at the `holdout`
  set while fixing (AGENTS.md). If a miss would need a single-field rule,
  leave it and explain it in the spec (as with `hipoglucemias`).
- **Done when:** `php tests/run.php 004 018` is green with 184/186 and the
  holdout does not drop below 26/27.
- **Size:** L

### T018-06 [T] Multicentre scenario P10 → P5

- **Requirements:** FR-018-04
- **Files:** `tests/specs/018_multiproject_validation.test.php`
- **What to do:** `\REDCap::$dict` = the P10 dictionary from the corpus,
  `\REDCap::$data = []`, `\Project::$conf = ['RepeatingFormsEvents' => [], 'forms' => ['baseline' => [], 'followup' => []]]`,
  `make_module(['birth-date-field' => 'dob', 'gender-field' => 'sex', 'default-date-field' => 'admission_date', 'death-date-field' => 'death_date', 'care-site-field' => ''])`.
  Approve `sex` (8507/8532), `discharge_med` (1112807, 1322184, 1310149; "4"
  does not emit) and `glucose` (3004501, mg/dL); exclude `ct_result`. Take
  `json_decode(mappingDocument()->json(), true)`, switch to the P5 dictionary
  and import with a new `make_module()`. Check what FR-018-04 says and finish
  with `reset_fixture()`.
- **Done when:** with the import by identical labels the test fails (`sex` in
  `skipped` with `choices`).
- **Size:** M

### T018-07 [I] Import by codes (amendment to 015)

- **Requirements:** FR-018-04, FR-018-12
- **Files:** `specs/015-mapping-reuse/spec.md`, `classes/MappingStore.php`, `classes/MappingAdvisor.php`
- **What to do:** amend FR-015-10 and add FR-015-12 and FR-015-13 with their
  *Amendments* row; implement following tasks T015-09/T015-10 (equal codes,
  `import_diff`, warning, exclusions).
- **Done when:** `php tests/run.php 015 018` is green.
- **Size:** M

### T018-08 [T] Evaluation material without secrets

- **Requirements:** FR-018-05, FR-018-06, FR-018-07
- **Files:** `tests/specs/018_multiproject_validation.test.php`
- **What to do:** check that `tests/eval/` contains `specs.php`,
  `generate.php`, `configure.php`, `curate.php`, `measure.php`, `summary.php`,
  `client.php`, `build_corpus.php`, `export_all.sh`, `checks.sh`,
  `checks.sql`, `integrity.sql` and `README.md`; that every `.php` contains
  `if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }`; that there is
  no `projects.json` and no match for `/\b[0-9A-F]{32}\b|redcap123/`; that no
  script writes `__DIR__ . '/projects…'` or results next to itself. That
  `results/results_round1.json` adds up to ES 82/96 and EN 72/90,
  `classifier_round2.json` says 184/186 and `checks.txt` has 10 schemas × 10
  checks at 0. That `integrity.sql` has the 10 `select :'s', '<name>'` in the
  order of the spec and `checks.sh` names all of them.
- **Done when:** the test fails (scripts and guards are missing).
- **Size:** S

### T018-09 [I] Evaluation scripts

- **Requirements:** FR-018-05, FR-018-06, FR-018-07, FR-018-08, FR-018-09
- **Files:** `tests/eval/*.php`, `tests/eval/*.sh`, `tests/eval/*.sql`, `tests/eval/results/`, `tests/eval/README.md`
- **What to do:**
  1. `client.php`: class `Ed` with a cookie session in `$OMOP_EVAL_WORK`, the
     CSRF token read from the editor and the shortcuts `field()`, `value()`,
     `approve()`.
  2. `generate.php` (API, `mt_srand(20261025)`, `@OMOP` annotations in P2 with
     `MappingAnnotation::render`, 3 % of gaps, record 7 without a birth date),
     `configure.php` (settings in `redcap_external_module_settings`,
     credentials through `OMOP_EVAL_DB_*`), `curate.php` (the four modes),
     `measure.php` and `summary.php`.
  3. `export_all.sh`, `checks.sh` and `integrity.sql` (`psql -v s=p1`).
  4. Copy round 1, round 2 and `checks.txt` to `results/`.
  5. README with the table of files, the rules and the order.
- **Hints:** the web server publishes the module folder: without the CLI
  guard, opening `configure.php` in the browser would write settings.
- **Done when:** `php tests/run.php 018` is green.
- **Size:** L

### T018-10 [I] Full evaluation in REDCap and PostgreSQL

- **Requirements:** FR-018-08, FR-018-09, FR-018-10, FR-018-11
- **Files:** none in the module (results in `$OMOP_EVAL_WORK`)
- **What to do:** follow the spec's *Manual verification* in the development
  Docker environment: generate, configure, curate, measure, export, load into
  PostgreSQL 17, `checks.sh`, and the three team scenarios in P4. Record every
  unexpected behaviour (unjustified block, field that is not imported, field
  that is always pending, inaccessible page, search without candidates).
- **Done when:** the 10 checks give 0 in the 10 schemas and each anomaly is
  recorded with the spec it affects.
- **Size:** L

### T018-11 [R] Anomalies as amendments

- **Requirements:** FR-018-12
- **Files:** `specs/004-…`, `specs/006-…`, `specs/012-…`, `specs/014-…`, `specs/015-…` (`spec.md`, `tasks.md`)
- **What to do:** for each anomaly, in its spec: a new or modified
  requirement in the list, a row `2026-10 | FR | change | 018` in
  *Amendments*, a test that cites it and a task in its `tasks.md`. In this
  spec, the *Anomalies* table with the link. Status of each amended spec:
  `Amended`.
- **Done when:** `php tests/run.php --trace` with no orphans and the manual
  verification of FR-018-12 done.
- **Size:** M

### T018-12 [R] Results in the spec

- **Requirements:** FR-018-06
- **Files:** `specs/018-multiproject-validation/spec.md`, `tests/eval/results/`, `README.md`
- **What to do:** *Results* tables (rounds, holdout, remaining misses and
  why), curation and export (395 persons, 6,751 rows, reuse modes). In the
  module README, the summary of the validation.
- **Done when:** the figures in the spec match `tests/eval/results/` (the
  FR-018-06 test watches this).
- **Size:** S

---

## Definition of done for the spec

- [ ] All `018` tests green and the rest of the suite without regressions.
- [ ] `php tests/run.php --trace 018` with no orphan requirements.
- [ ] Manual verification of FR-018-08 to FR-018-12 done and recorded.
- [ ] No anomaly fixed outside its spec; the amendments to 004, 006, 012, 014
      and 015 linked.
- [ ] No `projects.json` or tokens in the repository.
- [ ] Spec status changed to `Implemented`.
