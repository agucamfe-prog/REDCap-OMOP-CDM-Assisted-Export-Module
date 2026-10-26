# Tasks 002 — OMOP CDM v5.4 model

Before starting: read `constitution.md`, `AGENTS.md`, and this folder's
`spec.md` and `plan.md`. Do the tasks in order. Every `[T]` must end with the
test **red**; the following `[I]` turns it green.

Run only this spec's tests: `php tests/run.php 002`

| Legend | |
|---|---|
| `[T]` | Write a test (red) |
| `[I]` | Implement (green) |
| `[R]` | Refactor, document or translate |
| `[P]` | Can be done in parallel with the previous task |

---

### T002-01 [I] OHDSI reference files

- **Requirements:** FR-002-14
- **Files:** `resources/OMOP_CDMv5.4_Field_Level.csv`, `resources/NOTICE.txt`
- **What to do:** download `OMOP_CDMv5.4_Field_Level.csv` from
  `github.com/OHDSI/CommonDataModel` (folder `inst/csv`) and copy it
  unmodified. Write `NOTICE.txt` listing each third-party file with its path
  starting with `resources/` (one per line), the origin
  (`OHDSI/CommonDataModel`), the author (Observational Health Data Sciences
  and Informatics) and the licence (Apache License 2.0).
- **Hints:** this is the project's only download and it is done by a person
  during development, not by the module when installed. Do not open the CSV
  in a spreadsheet: it changes quotes and line endings.
- **Done when:** the file has 551 lines and the header starts with
  `cdmTableName,cdmFieldName,isRequired,cdmDatatype`.
- **Size:** S

### T002-02 [T] Tables and columns against the official file

- **Requirements:** FR-002-01, FR-002-02
- **Files:** `tests/specs/002_cdm_model.test.php`
- **What to do:** read the CSV with `fgetcsv($fh, 0, ',', '"', '')` and group
  the rows by `strtolower(cdmTableName)`. Test 1: `array_keys(CdmModel::tables())`
  is the list of 17 tables in the spec, in that order. Test 2: they all exist
  in the CSV. Then one test per table: the CSV columns
  (`strtolower(cdmFieldName)`) `===` `CdmModel::tables()[$t]`.
- **Hints:** the fifth argument `''` of `fgetcsv` disables the escape
  character; without it, some usage guidelines containing `\` break the rows.
- **Done when:** it fails because `CdmModel` does not exist.
- **Size:** S

### T002-03 [I] tables(), columns() and emptyRow()

- **Requirements:** FR-002-01, FR-002-02, FR-002-04
- **Files:** `classes/CdmModel.php`
- **What to do:** create the class in the module namespace. Copy the columns
  of each table from the CSV in the order in which they appear. `columns($t)`
  returns `self::tables()[$t] ?? []`; `emptyRow($t)` returns
  `array_fill_keys(self::columns($t), null)`.
- **Hints:** watch out for `measurement`, which in v5.4 ends with
  `measurement_event_id, meas_event_field_concept_id`, and for `note`, which
  ends with `note_event_id, note_event_field_concept_id`.
- **Done when:** `php tests/run.php 002` is green for FR-002-01 and 02.
- **Size:** M

### T002-04 [T] Required columns and missingRequired

- **Requirements:** FR-002-03, FR-002-07
- **Files:** `tests/specs/002_cdm_model.test.php`
- **What to do:** check that `CdmModel::REQUIRED` has the 17 tables of the
  model (including `location`); for each one, that its columns match the
  `isRequired = TRUE` ones in the CSV (`array_diff` empty both ways).
  Call `missingRequired` with three `death` rows (`death_date` `''`,
  `person_id` `null`, `death_date` `null`), one `location` row and a complete
  `person` with `race_concept_id = 0`: the result is exactly
  `['death' => ['person_id' => 1, 'death_date' => 2], 'location' => ['location_id' => 1]]`.
  An unknown table (`visit_detail`) is not checked.
- **Done when:** it fails because `REQUIRED` does not exist.
- **Size:** S

### T002-05 [I] REQUIRED and missingRequired

- **Requirements:** FR-002-03, FR-002-07
- **Files:** `classes/CdmModel.php`
- **What to do:** add the `REQUIRED` constant and the method: walk
  `REQUIRED[$table] ?? []`, count the rows with `$v === null || $v === ''`
  and only add the column if the count is greater than 0.
- **Hints:** do not use `empty()`: `0` is a valid concept («no matching
  concept») and counts as a value.
- **Done when:** `php tests/run.php 002` is green.
- **Size:** S

### T002-06 [T] Domains and tables

- **Requirements:** FR-002-05, FR-002-06
- **Files:** `tests/specs/002_cdm_model.test.php`
- **What to do:** `tableForDomain('Measurement') === 'measurement'`, `'Drug'`
  → `drug_exposure`, `'Visit'` → `visit_occurrence`, `'Note'` → `note`;
  `count(DOMAIN_TABLE) === 11`; `DateAnchor`, `NotMapped` and `'Episode'` →
  `null`; every table in `DOMAIN_TABLE` is in `tables()`; `validDomains()`
  has 13 values and includes the 11 domains, `DateAnchor` and `NotMapped`;
  `OmopCdmExportModule::validDomains() === CdmModel::validDomains()`.
- **Done when:** it fails because `tableForDomain` does not exist.
- **Size:** S

### T002-07 [I] DOMAIN_TABLE, tableForDomain and validDomains

- **Requirements:** FR-002-05, FR-002-06
- **Files:** `classes/CdmModel.php`, `OmopCdmExportModule.php`
- **What to do:** add the constant and the two methods; in the module,
  `public static function validDomains(): array { return CdmModel::validDomains(); }`.
- **Done when:** `php tests/run.php 002` is green.
- **Size:** S

### T002-08 [T] Text lengths and truncation

- **Requirements:** FR-002-08, FR-002-09
- **Files:** `tests/specs/002_cdm_model.test.php`
- **What to do:** build `$types[table][column] = strtolower(cdmDatatype)`.
  For each `TEXT_LIMITS[$col] = $lim` and each table of the model that has
  that column, the type must be `varchar($lim)` (or `varchar(max)` if `$lim`
  is `null`). Cases for `fit`: `fit('stop_reason', str_repeat('á', 30)) === str_repeat('á', 20)`;
  `null`, `''`, a 300-character `sig` and a short value stay the same.
- **Hints:** `value_as_string` is `varchar(60)` in `observation` and
  `varchar(250)` in `note_nlp`; only the model's table counts.
- **Done when:** it fails because `TEXT_LIMITS` does not exist.
- **Size:** S

### T002-09 [I] TEXT_LIMITS and fit()

- **Requirements:** FR-002-08, FR-002-09
- **Files:** `classes/CdmModel.php`
- **What to do:** add the constant with the `*_source_value` columns (50),
  `stop_reason` (20), `sig` (`null`), `value_as_string` (60), those of
  `cdm_source`, `care_site`, `provider`, `source_to_concept_map`,
  `note_title` (250), `lot_number` (50), `measurement_time` (10)… and `fit()`
  as in the plan.
- **Done when:** `php tests/run.php 002` is green.
- **Size:** S

### T002-10 [T] Operators, units and genders

- **Requirements:** FR-002-10, FR-002-11, FR-002-12, FR-002-13
- **Files:** `tests/specs/002_cdm_model.test.php`
- **What to do:** compare `OPERATORS` and `UCUM_BUILTIN` with the exact
  arrays in the spec (`===`, order included). `ucumCode`: `'mmHg'` →
  `'mm[Hg]'`, `'µmol/l'` → `'umol/L'`, `'lpm'` and `'bpm'` → `'{beats}/min'`,
  `'°C'` → `'Cel'`, `'kg/m²'` → `'kg/m2'`, `'mg/dl'` → `'mg/dL'`,
  `'copias/mL'` → `'{copies}/mL'`, `' KG '` → `'kg'`, `'mg / dL'` →
  `'mg/dL'`; `'xyz'`, `''` and `null` → `null`. Genders 8507, 8532, 8551 and
  `CONCEPT_NO_MATCH === 0`.
- **Done when:** it fails because the constants do not exist.
- **Size:** S

### T002-11 [I] Concept constants and ucumCode()

- **Requirements:** FR-002-10, FR-002-11, FR-002-12, FR-002-13
- **Files:** `classes/CdmModel.php`
- **What to do:** add the constants and `ucumCode()` with the plan's alias
  table. Explain in a comment on `UCUM_BUILTIN` why there are only eight.
- **Hints:** normalise the key by removing spaces and turning `µ` (U+00B5)
  and `μ` (U+03BC) into `u` *before* `strtolower`; they are two different
  characters and forms use both.
- **Done when:** `php tests/run.php 002` is green.
- **Size:** S

### T002-12 [T] NOTICE and independence from REDCap

- **Requirements:** FR-002-14, NFR-002-01
- **Files:** `tests/specs/002_cdm_model.test.php`
- **What to do:** read `NOTICE.txt`: it contains `OHDSI/CommonDataModel`,
  the name of OHDSI and `Apache License 2.0`; with
  `preg_match_all('#^resources/\S+#m')` get the listed files (≥ 4) and check
  `is_file` for each one. The CSV header starts with the four columns in the
  spec and there are ≥ 39 tables. The code of `CdmModel.php` does not contain
  `\REDCap`, `ExternalModules`, `$this` or `getProjectSetting`.
- **Done when:** the test runs; if a listed file does not exist yet (the DDL
  of spec 017), it stays red until it is added.
- **Size:** S

### T002-13 [R] Document the model

- **Requirements:** NFR-002-02
- **Files:** `classes/CdmModel.php`, `README.md`
- **What to do:** explain in the class comment that the column order is that
  of the official DDL, and why; in `TEXT_LIMITS` and `REQUIRED`, which load
  failure they prevent. Add to the README the line about the tests («The 17
  tables against the official specification») and the «CDM v5.4» decision.
- **Done when:** someone else reviews it without questions about the why.
- **Size:** S

---

## Definition of done for the spec

- [ ] All `002` tests green and the rest of the suite without regressions.
- [ ] `php tests/run.php --trace 002` with no orphan requirements.
- [ ] New texts in `lang/Spanish.ini` and `lang/English.ini` with the same keys.
- [ ] README updated if anything visible to the user changes.
- [ ] Spec status changed to `Implemented`.
