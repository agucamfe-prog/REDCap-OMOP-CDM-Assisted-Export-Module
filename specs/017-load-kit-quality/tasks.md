# Tasks 017 — Load kit and quality control

Before starting: read `constitution.md`, `AGENTS.md`, and this folder's
`spec.md` and `plan.md`. Do the tasks in order. Every `[T]` must end with the
test **red**; the following `[I]` turns it green.

Run only this spec's tests: `php tests/run.php 017`

| Legend | |
|---|---|
| `[T]` | Write a test (red) |
| `[I]` | Implement (green) |
| `[R]` | Refactor, document or translate |
| `[P]` | Can be done in parallel with the previous task |

---

### T017-01 [R] Official OHDSI files and their license

- **Requirements:** NFR-017-01
- **Files:** `resources/ddl/postgresql/*.sql`, `resources/NOTICE.txt`, `tests/specs/017_load_kit_quality.test.php`
- **What to do:**
  1. Download from `https://github.com/OHDSI/CommonDataModel` (branch `main`,
     `inst/ddl/5.4/postgresql/`) `OMOPCDM_postgresql_5.4_ddl.sql`,
     `…_primary_keys.sql` and `…_indices.sql`, without touching them.
  2. Write `NOTICE.txt` with the path of each file, the source, the author
     (OHDSI) and the Apache License 2.0.
  3. Test `NFR-017-01`: the three files exist and `NOTICE.txt` names them
     together with the repository and `Apache License 2.0`.
- **Hints:** do not change the line endings when saving them: the FR-017-02
  test compares them byte by byte.
- **Done when:** `php tests/run.php 017` is green for NFR-017-01.
- **Size:** S

### T017-02 [T] The kit travels in the ZIP with the official DDL

- **Requirements:** FR-017-01, FR-017-02, FR-017-05
- **Files:** `tests/specs/017_load_kit_quality.test.php`
- **What to do:** closure `$export($m)` that returns `[name => content]`.
  Check:
  1. The seven `carga_cdm/…` files of FR-017-01 exist.
  2. `01_ddl.sql === "-- OHDSI CommonDataModel v5.4 (Apache 2.0). Schema: cdm\nCREATE SCHEMA IF NOT EXISTS cdm;\n" . str_replace('@cdmDatabaseSchema', 'cdm', $official)`
     (project in English).
  3. The same number of `CREATE TABLE cdm.` as of `CREATE TABLE @cdmDatabaseSchema.` in the official file.
  4. 03 and 04 equal to the official files with the schema replaced; no file
     of `carga_cdm/` contains `@cdmDatabaseSchema`.
- **Done when:** it fails because there is no `carga_cdm/` folder in the ZIP.
- **Size:** S

### T017-03 [I] loadSchema() and the kit's SQL

- **Requirements:** FR-017-01, FR-017-02, FR-017-03, FR-017-05
- **Files:** `OmopCdmExportModule.php`, `classes/CsvExporter.php`, `config.json`
- **What to do:**
  1. Setting `load-schema` (text) in `config.json` and the plan's `loadSchema()`.
  2. `private function writeLoadKit(string $dir, array $tables): bool` and
     `protected function ddlDir(): string`, called in `writeZip()` with
     `array_keys($files)` before the report.
  3. Write `01_ddl.sql` (header with `kit_ddl_header`), `03_primary_keys.sql`
     and `04_indices.sql` (steps 1–6 of the plan).
- **Hints:** `writeZip()` walks the directory with `RecursiveDirectoryIterator`
  and deletes the subdirectories too: check that no `carga_cdm/` is left in
  the temporary directory.
- **Done when:** `php tests/run.php 017` is green for FR-017-02/05.
- **Size:** M

### T017-04 [T] Load of each CSV

- **Requirements:** FR-017-04
- **Files:** `tests/specs/017_load_kit_quality.test.php`
- **What to do:** with `preg_match_all("/^\\\\copy cdm\\.(\\w+) FROM '([A-Z_]+\\.csv)' WITH \\(FORMAT csv, HEADER true, NULL ''\\)$/m", …)`:
  there is `\set ON_ERROR_STOP on`; the copied CSVs (sorted) are the CSVs at the
  root of the ZIP (sorted); each lower-case table matches its CSV;
  `OBSERVATION.csv` does not appear.
- **Done when:** it fails because `02_carga.sql` does not exist.
- **Size:** S

### T017-05 [I] 02_carga.sql

- **Requirements:** FR-017-04, FR-017-09
- **Files:** `classes/CsvExporter.php`, `lang/*.ini`
- **What to do:** `"-- " . ttText('kit_load_comment') . "\n\\set ON_ERROR_STOP on\n"` and,
  for each `$table` of `$tables`,
  `"\\copy $schema.$table FROM '" . strtoupper($table) . ".csv' WITH (FORMAT csv, HEADER true, NULL '')\n"`.
  Key `kit_load_comment` in both languages.
- **Hints:** in PHP with double quotes, `\\copy` produces `\copy`.
- **Done when:** `php tests/run.php 017` is green for FR-017-04.
- **Size:** S

### T017-06 [T] cargar.sh and dqd.R

- **Requirements:** FR-017-06, FR-017-07
- **Files:** `tests/specs/017_load_kit_quality.test.php`
- **What to do:**
  - `cargar.sh` starts with `#!/bin/sh\n`, contains `\nset -e\n` and
    `cd "$(dirname "$0")/.."`, and the four lines
    `psql -v ON_ERROR_STOP=1 -f carga_cdm/0N_….sql` in order (compare their `strpos`).
  - `dqd.R` contains `library(DatabaseConnector)`, `library(DataQualityDashboard)`,
    `dbms     = "postgresql"`, `Sys.getenv("PGHOST"`, `Sys.getenv("PGPASSWORD")`,
    `executeDqChecks(`, `cdmDatabaseSchema      = "cdm"`, `cdmVersion             = "5.4"`
    and `outputFolder           = "dqd_resultados"`.
  - `cdmSourceName          = "Test"` (the `CDM_SOURCE` name); with
    `cdm-source-name` = `Site "North" \ 1`, `"Site \"North\" \\ 1"`.
  - In English, `dqd.R` starts with `# OHDSI Data Quality Dashboard on the CDM`,
    `cargar.sh` has `# PGHOST, … as in any PostgreSQL client.` and neither
    contains the Spanish words `Requiere`, `como en cualquier` or `Esquema:`.
- **Done when:** it fails because they do not exist.
- **Size:** S

### T017-07 [I] Load and quality scripts

- **Requirements:** FR-017-06, FR-017-07, FR-017-09
- **Files:** `classes/CsvExporter.php`, `lang/*.ini`
- **What to do:** write `cargar.sh` (step 7 of the plan, comments
  `kit_sh_comment` and `kit_sh_env`) and `dqd.R` (step 8): header
  `kit_dqd_header`, body with a `<<<R … R` heredoc that interpolates `$schema` and
  `$sourceName` (escaped for R) and footer `kit_dqd_vocab`. The keys, in both
  languages; no comment as a literal in the code.
- **Hints:** the heredoc interpolates PHP variables (`$schema`,
  `{$this->module->pid()}`): do not write in the R script any `$` that is not
  PHP's. Put a comment in `dqd.R` saying that without the Athena vocabularies
  the concept checks fail and that this is not an error of the CDM.
- **Done when:** `php tests/run.php 017` is green for FR-017-06/07.
- **Size:** M

### T017-08 [T] Configurable schema and kit language

- **Requirements:** FR-017-03, FR-017-08, FR-017-09
- **Files:** `tests/specs/017_load_kit_quality.test.php`
- **What to do:**
  1. `make_module(['load-schema' => 'Omop_TB'])`: `loadSchema() === 'omop_tb'` and
     `omop_tb` in 01 (`CREATE SCHEMA IF NOT EXISTS omop_tb;`, `CREATE TABLE omop_tb.person`),
     02, 03 and `dqd.R`; the report says `(schema omop_tb)`.
  2. `''`, `cdm; DROP TABLE person`, `1abc`, `omop-cdm` and 64 «a» → `cdm`; `_cdm2` → `_cdm2`.
  3. The English LEEME starts with `CDM LOAD KIT` and contains `schema 'cdm'` and
     `sh carga_cdm/cargar.sh`; in Spanish (`$lang` = `Spanish.ini`),
     `KIT DE CARGA DEL CDM` and `esquema «omop_tb»`; comments of 02 and
     `cargar.sh` in each language.
- **Done when:** it fails because there is no LEEME and the report does not name the schema.
- **Size:** M

### T017-09 [I] LEEME and the report's next step

- **Requirements:** FR-017-03, FR-017-08
- **Files:** `classes/CsvExporter.php`, `lang/*.ini`
- **What to do:** `file_put_contents($kit . '/LEEME.txt', ttText('kit_readme', $schema) . "\n")`.
  In `writeReport()`, the next step uses `rep_next_kit` with `loadSchema()`.
  Keys `kit_readme` (with literal `\n` in the `.ini`) and `rep_next_kit` in both languages.
- **Hints:** `ttText()` turns the literal `\n` of the `.ini` into line breaks.
- **Done when:** `php tests/run.php 017` is green.
- **Size:** S

### T017-10 [T] Mapping for Usagi

- **Requirements:** FR-017-10, FR-017-11, FR-017-12
- **Files:** `tests/specs/017_load_kit_quality.test.php`
- **What to do:** after `curate($m)`, `hr` proposed with a concept
  (`updateField('hr', ['domain' => 'Measurement', 'concept_id' => '3027018', 'concept_name' => 'Heart rate', 'date_field' => 'visit_date'], …)`)
  and `notes` excluded (`['state' => 'excluded']`); `invalidateMappingCache()`.
  Closure `$usagi($csv)` → `[$head, $rows by sourceCode]`. Check:
  17-column header; `MAPEO_usagi.csv` in the ZIP equal to `usagiCsv()`;
  `weight` MAPS_TO 3025315; `sex=1` MAPS_TO 8507 «Sex: Male» and no `sex` row;
  `bac` MAPS_TO and `bac=1`, `bac=2` MAPS_TO_VALUE; no `comorb=3`, `exitus=*`;
  `weight` APPROVED with `statusSetBy` = `approved_by` and `statusSetOn` =
  `strtotime(approved_at) * 1000`; `hr` UNCHECKED with `statusSetOn` `0`; no
  `notes`, `visit_date`, `record_id`.
- **Done when:** it fails with «Call to undefined method … usagiCsv()».
- **Size:** M

### T017-11 [I] usagiCsv() in the ZIP and on the page

- **Requirements:** FR-017-10, FR-017-11, FR-017-12
- **Files:** `classes/MappingDocument.php`, `classes/CsvExporter.php`, `pages/export.php`, `lang/*.ini`
- **What to do:**
  1. `usagiCsv(): string` with the plan's rules table, over `build()`.
  2. In `writeZip()`, inside the document's `try`,
     `file_put_contents($dir . '/MAPEO_usagi.csv', $doc->usagiCsv())`.
  3. On the page, `doc=usagi` with `Content-Type: text/csv` and
     `Content-Disposition` `…_usagi.csv`, and the `ex_btn_doc_usagi` button.
- **Hints:** with «value per choice» the question has no row of its own even if
  it has a concept: the concept is in each choice. `MappingStore::usesOptions()`
  says whether the strategy uses choices.
- **Done when:** `php tests/run.php 017` is green.
- **Size:** M

### T017-12 [T] No readable DDL, no kit

- **Requirements:** FR-017-15
- **Files:** `tests/specs/017_load_kit_quality.test.php`
- **What to do:** anonymous subclass
  `new class($m) extends \OmopCdm\OmopCdmExportModule\CsvExporter { protected function ddlDir(): string { return '/does/not/exist/'; } }`;
  `writeZip($m->transformer()->run())`. Check that there is `PERSON.csv`, that
  no name starts with `carga_cdm/` and that `INFORME.txt` contains
  `load kit could not be included` and not `cargar.sh`.
- **Done when:** it fails because the report still points to the kit.
- **Size:** S

### T017-13 [I] The report says whether the kit is there

- **Requirements:** FR-017-15, FR-011-09
- **Files:** `classes/CsvExporter.php`, `lang/*.ini`
- **What to do:** `writeLoadKit()` returns `false` before creating the folder
  if the DDL is not readable; `writeZip()` passes the result to
  `writeReport(..., $kitIncluded)`, which uses `rep_next_no_kit` (without naming
  `carga_cdm` or `cargar.sh`) instead of `rep_next_kit`. Key in both languages.
- **Hints:** creating the folder before checking the DDL leaves an empty
  directory that does not get into the ZIP but still has to be deleted.
- **Done when:** `php tests/run.php 011 017` is green.
- **Size:** S

### T017-14 [R] Real load and Data Quality Dashboard

- **Requirements:** FR-017-13, FR-017-14
- **Files:** — (verification), `README.md`
- **What to do:** follow the spec's manual verification with
  `postgres:17` in Docker: load, per-table counts against `INFORME.txt`,
  orphan queries (0), and with `load-schema` = `omop_tb`. If you have R,
  run the DQD with the Athena vocabularies. Document in the README («What is
  in the ZIP») how to use `cargar.sh` and `dqd.R`.
- **Hints:** `\copy` resolves paths from the directory where `psql` runs;
  that is why `cargar.sh` does a `cd` to the root of the ZIP.
- **Done when:** the load finishes without errors and the queries return what is expected.
- **Size:** M

---

## Definition of done for the spec

- [ ] All `017` tests green and no regressions in the rest of the suite.
- [ ] `php tests/run.php --trace 017` reports no orphan requirements.
- [ ] New texts in `lang/Spanish.ini` and `lang/English.ini` with the same keys.
- [ ] Manual verification of FR-017-13 done on PostgreSQL 17 (and FR-017-14 if R is available).
- [ ] `resources/NOTICE.txt` up to date with the OHDSI files.
- [ ] README updated («What is in the ZIP»).
- [ ] Spec status changed to `Implemented`.
