# AGENTS.md

Instructions for anyone who touches this repository: people joining the project
and AI coding agents. Read it in full before your first task.

## What this is

A REDCap external module (framework v9, PHP ≥ 7.4) that maps a project's data
dictionary to the OMOP Common Data Model v5.4 and exports the CDM tables. People
propose and approve; the module proposes, warns, transforms and documents.

## Reading order

1. [`constitution.md`](constitution.md): the non-negotiable principles.
2. [`specs/README.md`](specs/README.md): the specs, their order and dependencies.
3. The folder of the spec you are going to work on: `spec.md` → `plan.md` → `tasks.md`.
4. [`README.md`](README.md): the module's behaviour as users see it (in Spanish).

## Workflow (Spec-Driven + Test-Driven)

```
spec.md (what) ──► plan.md (how) ──► tasks.md (steps) ──► red test ──► green code ──► refactor
```

1. **No code without a spec.** If what you are asked to do is not in any spec,
   first write the spec (or an amendment to an existing one) from the templates
   in `specs/_templates/`, and ask for it to be reviewed.
2. **One task at a time**, in the order of `tasks.md`. `[T]` tasks write a test
   that must **fail** before you continue. `[I]` tasks turn it green without
   breaking any other test. `[R]` tasks refactor, document or translate.
3. **Cite the requirements.** Every test starts with the requirements it
   checks: `test('FR-009-03', 'the "No" of a diagnosis produces no row', $cond);`
4. **Close the spec** with its *Definition of done* (at the end of `tasks.md`).

## Commands

Tests do not need REDCap: `tests/bootstrap.php` replaces the framework,
`REDCap::getData` and `Project` with in-memory versions.

```bash
php tests/run.php                 # all specs
php tests/run.php 009             # only spec 009
php tests/run.php --trace         # also: requirements without tests and tests citing unknown requirements
php tests/run.php --quiet         # only failures and the summary

# In the Docker development environment:
docker exec -w /var/www/html/modules/omop_cdm_export_v1.0.0 rc1_web php tests/run.php
```

The exit code is non-zero if anything fails.

## Code map

| Path | Contents | Spec |
|---|---|---|
| `config.json` | Module declaration, settings and links | 001 |
| `OmopCdmExportModule.php` | Module class: settings, permissions, service factory | 001, 012 |
| `lang/Spanish.ini`, `lang/English.ini` | Every visible text (same keys) | 001 |
| `classes/CdmModel.php` | CDM v5.4 tables and columns | 002 |
| `classes/DictionaryProfiler.php` | Dictionary profile and classifier | 003, 004 |
| `classes/TextNormalizer.php` | Text normalisation (accents, spelling) | 004 |
| `classes/MappingStore.php` | Mapping states, approval, drift, import | 006, 015 |
| `classes/MappingAdvisor.php` | Mapping consistency warnings | 008 |
| `classes/Transformer.php` | REDCap records → CDM rows | 009, 010 |
| `classes/CsvExporter.php` | ZIP, CSV, report, load kit | 011, 017 |
| `classes/MappingDocument.php` | Mapping document (HTML, JSON, Usagi, annotated dictionary) | 011, 015 |
| `classes/IdRegistry.php` | Stable identifiers | 013 |
| `classes/Vocabulary.php` | SQLite FTS5 index / WebAPI, search and validation | 014 |
| `classes/MappingAnnotation.php` | `@OMOP` action tags | 015 |
| `classes/Pseudonymizer.php` | Date shifting and birth date precision | 016 |
| `pages/` | Dashboard, editor, export, vocabulary | 005, 007, 011, 014 |
| `resources/` | Official field specification and OHDSI DDL | 002, 017 |
| `tests/run.php` | Per-spec test runner with traceability | — |
| `tests/lib/` | `harness.php` (test, section) and `fixtures.php` (test project) | — |
| `tests/specs/NNN_*.test.php` | Tests of each spec | all |
| `tests/support/run_page.php` | Runs a page in a separate PHP process | 005, 007, 011, 012 |
| `tests/fixtures/` | Classifier benchmark, test vocabulary and evaluation corpus | 004, 014, 018 |
| `tests/eval/` | Scripts of the 10-project evaluation (command line only) | 018 |

## Code rules

- **Style:** PSR-12, `declare` not required (PHP 7.4). Class, method and
  variable names in English; comments and documentation in English, explaining
  the *why*, not the *what*.
- **No new dependencies.** No Composer or npm at runtime.
- **Texts:** never a visible literal in the code. Add the key to
  `lang/Spanish.ini` **and** `lang/English.ini`, and use `$module->tt('key')`.
  The interface, the export report and the mapping document are bilingual; the
  code and its documentation are in English.
- **Escaping:** everything printed as HTML goes through `htmlspecialchars`;
  everything that arrives by POST is validated against an allow-list and the
  CSRF token.
- **Never write to the REDCap project** (data or dictionary). The module's
  state lives in its project settings.
- **Performance:** do not call `getProjectSetting` in loops; use
  `$module->setting()`, which caches. Process records in batches.

## Test rules

- Every `tests/specs/NNN_*.test.php` file starts from a clean environment (the
  runner calls `reset_fixture()`). Do not depend on another file.
- Use the test project in `tests/lib/fixtures.php` (`make_module()`,
  `curate()`, `rec()`, `classify()`, `group_by()`); if you need another
  dictionary, change `\REDCap::$dict` inside your file.
- Do not define global functions in test files (they would clash between
  specs); use closures.
- A test checks **one** observable thing and its description states it in
  plain language.

## What you must not do

- Change `constitution.md` without an entry in its amendments table.
- Lower the benchmark thresholds to make a test pass.
- Tune the classifier while looking at the `holdout` set of the benchmark: it
  invalidates the measure.
- Mark a requirement `[manual]` to avoid writing its test when it can be
  automated.
- Commit `projects.json`, API tokens or participant data.

## If you are an AI agent

- Work task by task and run the tests after each one.
- If a task is ambiguous, do not invent: write the question in the task itself
  (`> Question: …`) and stop.
- If you find a bug outside your spec, do not fix it silently: describe it as a
  proposed amendment to the affected spec.
- Declare AI use in commit messages (`Co-Authored-By:`).
