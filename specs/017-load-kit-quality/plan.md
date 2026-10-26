# Plan 017 — Load kit and quality control

## Technical summary

`CsvExporter::writeZip()` (spec 011), after writing the tables, the report and
the document, calls a private method `writeLoadKit()` that creates the
`carga_cdm/` folder in the temporary directory. The three official SQL files
are read from `resources/ddl/postgresql/` and only `@cdmDatabaseSchema` is
replaced in them; the rest of the kit is generated from the list of exported
tables and the schema of `OmopCdmExportModule::loadSchema()`. Since the ZIP is
assembled by walking the directory, the folder gets in on its own with its
relative paths.

`MappingDocument::usagiCsv()` turns the `build()` structure (spec 011) into the
Usagi CSV; it is used by the ZIP (`MAPEO_usagi.csv`) and by the export screen
(`doc=usagi`).

## Files

| File | Action | Responsibility |
|---|---|---|
| `resources/ddl/postgresql/OMOPCDM_postgresql_5.4_ddl.sql` | Add | Official OHDSI DDL, unmodified |
| `resources/ddl/postgresql/OMOPCDM_postgresql_5.4_primary_keys.sql` | Add | Official primary keys |
| `resources/ddl/postgresql/OMOPCDM_postgresql_5.4_indices.sql` | Add | Official indices |
| `resources/NOTICE.txt` | Add | Source and license (Apache 2.0) |
| `classes/CsvExporter.php` | Modify | `writeLoadKit()`; `MAPEO_usagi.csv` in the ZIP |
| `classes/MappingDocument.php` | Modify | `usagiCsv()` |
| `OmopCdmExportModule.php` | Modify | `loadSchema()` |
| `config.json` | Modify | Project setting `load-schema` |
| `pages/export.php` | Modify | `doc=usagi` and its button |
| `lang/*.ini` | Modify | `kit_ddl_header`, `kit_load_comment`, `kit_sh_comment`, `kit_sh_env`, `kit_dqd_header`, `kit_dqd_vocab`, `kit_readme`, `rep_next_kit`, `rep_next_no_kit`, `ex_btn_doc_usagi` |
| `tests/specs/017_load_kit_quality.test.php` | Create | Tests for this spec |

## Design

### Schema

```php
public function loadSchema(): string
{
    $s = strtolower((string) ($this->setting('load-schema') ?: 'cdm'));
    return preg_match('/^[a-z_][a-z0-9_]{0,62}$/', $s) ? $s : 'cdm';
}
```

Examples: `''` → `cdm`; `Omop_TB` → `omop_tb`; `omop-cdm` → `cdm`;
`cdm; DROP TABLE person` → `cdm`; 64 characters → `cdm` (PostgreSQL allows 63).

### writeLoadKit

```php
/** @return bool false if there is no kit (unreadable DDL) */
private function writeLoadKit(string $dir, array $tables): bool   // $tables = array_keys($files)
/** Folder of the official DDL; protected so that its absence can be tested. */
protected function ddlDir(): string                                 // resources/ddl/postgresql/
```

`writeZip()` calls it **before** `writeReport()` and passes it the result, so
that the report says whether the kit is there (FR-011-09, FR-017-15).

1. Reads `ddlDir() . 'OMOPCDM_postgresql_5.4_ddl.sql'` (with `is_readable`); if
   it cannot, returns `false` **without creating** the folder, and the export
   goes on.
2. `mkdir($dir . '/carga_cdm', 0700, true)`; `$schema = $module->loadSchema()`.
   Closure `$comment($prefix, $text)` that prepends `-- ` or `# ` to each line
   of a text from `lang/` (the `.ini` files contain `\n`).
3. `$sub = fn($sql) => str_replace('@cdmDatabaseSchema', $schema, $sql)`.
4. `01_ddl.sql` = `$comment('-- ', ttText('kit_ddl_header', $schema))`
   («OHDSI CommonDataModel v5.4 (Apache 2.0). Schema: cdm» / «… Esquema: cdm»)
   + `"CREATE SCHEMA IF NOT EXISTS $schema;\n"` + `$sub($ddl)`.
5. `02_carga.sql`:
   ```
   -- <kit_load_comment>
   \set ON_ERROR_STOP on
   \copy cdm.person FROM 'PERSON.csv' WITH (FORMAT csv, HEADER true, NULL '')
   \copy cdm.observation_period FROM 'OBSERVATION_PERIOD.csv' WITH (FORMAT csv, HEADER true, NULL '')
   …
   ```
   `NULL ''` because the exporter writes nulls as an empty cell (FR-011-02).
6. `03_primary_keys.sql` and `04_indices.sql` = `$sub(official)`.
7. `cargar.sh`: `#!/bin/sh`, comments `kit_sh_comment` and `kit_sh_env`, `set -e`,
   `cd "$(dirname "$0")/.."` (root of the ZIP, where the CSVs are: the `\copy`
   paths are relative to `psql`'s directory) and four
   `psql -v ON_ERROR_STOP=1 -f carga_cdm/0N_….sql`.
8. `dqd.R`: header `$comment('# ', ttText('kit_dqd_header', $schema))`
   (packages, installation and a warning that without the Athena vocabularies
   the concept checks fail), `createConnectionDetails(dbms = "postgresql", server = PGHOST/PGDATABASE, port, user, password)`,
   `executeDqChecks(cdmDatabaseSchema = "$schema", resultsDatabaseSchema = "$schema", vocabDatabaseSchema = "$schema", cdmSourceName = <name>, cdmVersion = "5.4", outputFolder = "dqd_resultados", writeToTable = FALSE)`
   and footer `kit_dqd_vocab` (TRUE/FALSE). `<name>` is `cdmSourceConfig()['name']`
   as an R string: `\` → `\\`, `"` → `\"`, line breaks → space. Example:
   `Site "North"` → `"Site \"North\""`.
9. `LEEME.txt` = `ttText('kit_readme', $schema)`. Returns `true`.

### Usagi

```php
public function usagiCsv(): string
```

Columns: `sourceCode, sourceName, sourceFrequency, sourceAutoAssignedConceptIds,
matchScore, mappingStatus, equivalence, statusSetBy, statusSetOn, conceptId,
conceptName, domainId, mappingType, comment, createdBy, createdOn, assignedReviewer`.

Rules per field of `build()['fields']`:

| Case | Rows |
|---|---|
| `excluded`, no domain, `NotMapped`, `DateAnchor` | none |
| own concept and a strategy other than `per_choice` with choices | `field` · `MAPS_TO` |
| `per_choice` with choices | `field=code` · `MAPS_TO` per choice with `emit` and a concept |
| `value_concept` with choices | `field` · `MAPS_TO` + `field=code` · `MAPS_TO_VALUE` |

Example:

```
sourceCode,sourceName,…,mappingStatus,equivalence,statusSetBy,statusSetOn,conceptId,conceptName,domainId,mappingType,…
bac,"Sputum smear",0,,0,APPROVED,UNREVIEWED,ana,1792968914000,3016102,AFB,Measurement,MAPS_TO,,ana,1792968914000,
bac=1,"Sputum smear: Positive",0,,0,APPROVED,UNREVIEWED,ana,1792968914000,9191,Positive,Measurement,MAPS_TO_VALUE,,ana,1792968914000,
hr,"Heart rate (bpm)",0,,0,UNCHECKED,UNREVIEWED,,0,3027018,"Heart rate",Measurement,MAPS_TO,,,0,
```

`statusSetOn` = `strtotime(approved_at) * 1000` (Usagi stores milliseconds);
`equivalence` is always `UNREVIEWED`: the module does not know whether the
concept is equivalent, broader or narrower. `fputcsv(…, ',', '"', '')`.

## Decisions and discarded alternatives

- **Official DDL unmodified.** It is the one the OHDSI tools use; if the CDM
  loads with it, it loads on any standard installation. *Discarded:*
  generating the DDL from `CdmModel`, which could diverge from the official one.
- **Without the constraints file.** Its foreign keys point to `CONCEPT` and the
  other vocabulary tables; without Athena loaded, the load would fail. The
  integrity between CDM tables is checked with queries (manual verification)
  and with the DQD.
- **Keys and indices after the load.** Loading without indices is faster and a
  duplicate is detected when the primary key is created.
- **`psql` and `\copy`**, not `COPY`: `\copy` reads the files on the client and
  does not require superuser permissions on the server.
- **Schema validated with an allow-list**, not escaped: a name that is not a
  simple identifier is replaced by `cdm`.
- **Usagi `UNCHECKED` for what is not approved**: the CSV reflects the real
  status, without presenting as reviewed what is not.

## Test strategy

In `tests/specs/017_load_kit_quality.test.php`, with `make_module()` and `curate()`:

- Presence of the seven `carga_cdm/` files.
- `01_ddl.sql` compared byte by byte with the header plus the official file
  with the schema replaced; count of `CREATE TABLE`.
- `02_carga.sql`: regular expression over each `\copy`; the set of copied CSVs
  equals the set of CSVs in the ZIP; there is no `OBSERVATION.csv`.
- 03 and 04 compared with the official files with the schema replaced; no
  `@cdmDatabaseSchema`.
- `cargar.sh` and `dqd.R`: key lines and order of the `psql` calls.
- `load-schema` = `Omop_TB` throughout the kit and in the report; table of
  invalid values → `cdm`.
- LEEME and comments in English and Spanish (`AbstractExternalModule::$lang`):
  in English, neither `dqd.R` nor `cargar.sh` nor the DDL header contain
  Spanish words.
- `cdmSourceName` = `"Test"` and, with `cdm-source-name` = `Site "North" \ 1`,
  the escaped R string.
- Without kit: anonymous subclass of `CsvExporter` with `ddlDir()` = `/does/not/exist/`;
  the ZIP has no `carga_cdm/` and the report says so.
- Usagi: header, rows for `weight`, `sex=1`, `bac`, `bac=1`, `bac=2`,
  absence of `comorb=3`, `exitus=*`; `hr` proposed with a concept → `UNCHECKED`;
  `notes` excluded → absent.
- `NOTICE.txt` declares the three files, the repository and the license.
- Real load and DQD: manual (they need PostgreSQL and R).

## Constitution check

- **14 (no runtime dependencies):** the kit is generated with PHP; `psql` and R
  are only needed at the destination. The vocabularies are not distributed.
- **15 (OMOP CDM v5.4):** the official DDL is the reference for the load.
- **16:** the DQD needs the Athena vocabularies; the LEEME and `dqd.R` say so.
- **21:** LEEME and comments in the project's language.

## Risks

- If the DDL is missing from `resources/`, the package comes out without the
  kit. The report says so (FR-017-15) and the FR-017-01 test detects that the
  files are missing from the module.
- A new kit comment written as a literal would break bilingualism; the
  FR-017-09 test looks for Spanish text in the kit generated in English.
- An update of the OHDSI DDL requires replacing the three files and
  `NOTICE.txt` at the same time.
