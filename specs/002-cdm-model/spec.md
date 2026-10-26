# Spec 002 — OMOP CDM v5.4 model

| | |
|---|---|
| **Status** | Implemented |
| **Depends on** | 001 |
| **Tests** | `tests/specs/002_cdm_model.test.php` |

## Context

Everything the module generates ends up in OMOP CDM v5.4 tables that someone
will load into a database with the official OHDSI DDL. If a column is
missing, extra or in a different order, a positional load fails or, worse,
puts the values in the wrong column without warning. If a required column
arrives empty, the `INSERT` is rejected. If a text exceeds the `varchar`
length, the load stops. That is why the constitution (principle 15) requires
the columns in the order, and with the required flags, of the official file
`OMOP_CDMv5.4_Field_Level.csv`.

This spec defines `CdmModel`: the subset of the CDM the module generates (17
tables), the columns of each one in the official order, those that are NOT
NULL, the maximum text lengths, the table that corresponds to each domain and
the few concepts the module can use without a vocabulary because they are
stable and leave no room for doubt: the comparison operators, eight UCUM units
and the genders. Everything else requires a vocabulary (spec 014).

The official file is shipped unmodified in `resources/`, together with its
provenance and licence (`NOTICE.txt`). It is not read at run time: the tests
use it to check that `CdmModel` matches it, so a discrepancy is caught before
release, not at load time.

## User stories

- **US-002-1.** As a data engineer, I want every exported CSV to have its
  columns in the order of the official DDL so I can load it with `COPY`
  without mapping columns by hand.
- **US-002-2.** As the person responsible for the export, I want to know
  before exporting which rows would leave a required column empty, so I do
  not find out at load time.
- **US-002-3.** As a developer, I want a single source of truth for tables,
  columns, domains and fixed concepts, so that the transformer, the exporter
  and the editor do not each maintain their own list.

## Functional requirements

- **FR-002-01** — `CdmModel::tables()` MUST define exactly 17 tables, all present in the official specification: `person`, `observation_period`, `visit_occurrence`, `condition_occurrence`, `drug_exposure`, `procedure_occurrence`, `measurement`, `observation`, `device_exposure`, `specimen`, `death`, `note`, `provider`, `source_to_concept_map`, `location`, `care_site`, `cdm_source`.
- **FR-002-02** — The columns of each table MUST be those of the official file `OMOP_CDMv5.4_Field_Level.csv`, in the same order, in lower case.
- **FR-002-03** — `CdmModel::REQUIRED` MUST list, for each of the 17 tables of the model (including `location`, even though the module exports it with its header only), exactly the columns with `isRequired = TRUE` in the official file.
- **FR-002-04** — `columns($table)` MUST return the columns of the table (or `[]` if it does not exist) and `emptyRow($table)` a row with all its columns in order and set to `null`.
- **FR-002-05** — `tableForDomain($domain)` MUST return the table of each of the 11 OMOP domains (`Person` → `person`, `Visit` → `visit_occurrence`, `Condition` → `condition_occurrence`, `Drug` → `drug_exposure`, `Procedure` → `procedure_occurrence`, `Measurement` → `measurement`, `Observation` → `observation`, `Device` → `device_exposure`, `Specimen` → `specimen`, `Death` → `death`, `Note` → `note`) and `null` for `DateAnchor`, `NotMapped` and any other value.
- **FR-002-06** — `validDomains()` MUST return the 11 OMOP domains plus the module's own codes `DateAnchor` and `NotMapped` (13 values); the module accepts exactly that list.
- **FR-002-07** — `missingRequired($tables)` MUST count, per table and required column, the rows whose value is `null` or an empty string (`0` is a value), and omit complete columns and tables without `REQUIRED`.
- **FR-002-08** — `CdmModel::TEXT_LIMITS` MUST give, for each bounded text column, the length `n` of its official `varchar(n)` in the model's tables; `sig` is listed without a limit (`varchar(MAX)`).
- **FR-002-09** — `fit($column, $value)` MUST truncate the value to the limit of its column counting characters (not bytes) and leave `null`, the empty string and values of columns without a limit untouched.
- **FR-002-10** — `CdmModel::OPERATORS` MUST give the OMOP operator concept: `<` 4171756, `<=` 4171754, `=` 4172703, `>=` 4171755, `>` 4172704.
- **FR-002-11** — `CdmModel::UCUM_BUILTIN` MUST contain only eight safe units with their concept: `kg` 9529, `cm` 8582, `mm[Hg]` 8876, `%` 8554, `g/dL` 8713, `mg/dL` 8840, `mmol/L` 8753, `umol/L` 8749.
- **FR-002-12** — `ucumCode($unit)` MUST translate the spelling used in a form into its UCUM code, ignoring case and spaces and treating `µ`/`μ` as `u` (`mmHg` → `mm[Hg]`, `µmol/l` → `umol/L`, `lpm`/`bpm` → `{beats}/min`, `°C` → `Cel`, `kg/m²` → `kg/m2`, `copias/mL` → `{copies}/mL`), and return `null` for an unknown, empty or `null` unit.
- **FR-002-13** — `CdmModel` MUST fix the gender concepts `GENDER_MALE` 8507, `GENDER_FEMALE` 8532, `GENDER_UNKNOWN` 8551 and `CONCEPT_NO_MATCH` = 0.
- **FR-002-14** — `resources/` MUST include the official file `OMOP_CDMv5.4_Field_Level.csv` (header `cdmTableName, cdmFieldName, isRequired, cdmDatatype…`, 39 tables) and `NOTICE.txt` with the origin (`OHDSI/CommonDataModel`), author (OHDSI) and Apache 2.0 licence of each third-party file; every file listed in `NOTICE.txt` exists.

## Non-functional requirements

- **NFR-002-01** — `CdmModel` is a static class with no dependency on REDCap or the framework: it is used without instantiating the module.
- **NFR-002-02** — The official file is neither read at run time nor modified; conformance is guaranteed by the tests.

## Acceptance criteria

```gherkin
Scenario: official column order
  Given the file OMOP_CDMv5.4_Field_Level.csv
  When each table of CdmModel::tables() is compared with its rows
  Then the column list is identical and in the same order

Scenario: empty required columns
  Given two DEATH rows, one without death_date and one without person_id, and a third with death_date null
  When missingRequired is called
  Then it returns death => [person_id => 1, death_date => 2]

Scenario: truncation to the official length
  Given a stop_reason of 30 accented characters
  When fit('stop_reason', ...) is called
  Then it returns the first 20 characters, without breaking any of them

Scenario: form unit to UCUM
  When ucumCode('mmHg') is called
  Then it returns 'mm[Hg]'
  And ucumCode('xyz') returns null
```

## Out of scope

- CDM tables the module does not generate (`visit_detail`, `note_nlp`,
  `fact_relationship`, eras, costs, vocabulary…).
- Looking up concepts or units in a vocabulary (spec 014).
- Using the model to build rows (009, 010) and to write the CSV files (011).
- The PostgreSQL load DDL and the load kit (spec 017), even though their
  files are already declared in `NOTICE.txt`.

## Manual verification

There are no `[manual]` requirements.

## Amendments

| Date | Requirement | Change | Prompted by |
|---|---|---|---|
| — | — | Initial version | — |
