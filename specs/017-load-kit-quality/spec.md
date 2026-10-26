# Spec 017 — Load kit and quality control

| | |
|---|---|
| **Status** | Implemented |
| **Depends on** | 011 |
| **Tests** | `tests/specs/017_load_kit_quality.test.php` |

## Context

Spec 011 delivers a ZIP with one CSV per CDM table, in the official column
order. But CSVs that «look fine» are not a CDM: the test that really counts is
loading them into a real OMOP schema and running the OHDSI Data Quality
Dashboard (DQD) on them. That is where mismatched types, empty required
fields, duplicate keys and inconsistencies between tables show up.

Doing that load by hand means finding the official DDL for the right version,
replacing the schema name, writing a `\copy` per table in the right order,
adding keys and indices and preparing the DQD R script. It is repetitive,
error-prone work, and that is why it is often not done. This spec puts a
ready-to-use **load kit** in the ZIP: the official OHDSI DDL for PostgreSQL
(CDM v5.4, Apache 2.0, distributed unmodified except for the schema), the load
of each exported CSV, the primary keys, the indices, a `cargar.sh` that runs
everything and a `dqd.R` for the DQD.

In addition, the mapping travels in the format of **Usagi**, the OHDSI tool for
reviewing mappings with the vocabulary at hand: `MAPEO_usagi.csv`, which opens
with *File → Open* and lets someone review the chosen concepts or continue the
work there.

## User stories

- **US-017-1.** As a data manager, I want to load the export into PostgreSQL
  with a single command so that I can check it is a valid CDM.
- **US-017-2.** As a quality officer, I want a ready-made script to run the
  Data Quality Dashboard on what was loaded.
- **US-017-3.** As a database administrator, I want to choose the schema in
  which the tables are created so that they do not get mixed with others.
- **US-017-4.** As a vocabulary expert, I want the mapping in Usagi format so
  that I can review it with the tool I already use.

## Functional requirements

- **FR-017-01** — The export ZIP MUST include a `carga_cdm/` folder with `01_ddl.sql`, `02_carga.sql`, `03_primary_keys.sql`, `04_indices.sql`, `cargar.sh`, `dqd.R` and `LEEME.txt`.
- **FR-017-02** — `01_ddl.sql` MUST be the official OHDSI DDL for PostgreSQL, CDM v5.4 (`resources/ddl/postgresql/OMOPCDM_postgresql_5.4_ddl.sql`), with `@cdmDatabaseSchema` replaced by the load schema and no other change, preceded by a line with provenance, license and schema in the project's language (`kit_ddl_header`) and by `CREATE SCHEMA IF NOT EXISTS <schema>;`.
- **FR-017-03** — The load schema MUST come from the `load-schema` setting, in lower case; if it is empty or not a valid PostgreSQL identifier (`^[a-z_][a-z0-9_]{0,62}$`), `cdm` MUST be used. The same schema is used throughout the kit and the report names it.
- **FR-017-04** — `02_carga.sql` MUST start with `\set ON_ERROR_STOP on` and have exactly one `\copy <schema>.<table> FROM 'TABLE.csv' WITH (FORMAT csv, HEADER true, NULL '')` per exported CSV, in table order, and none for tables without rows.
- **FR-017-05** — `03_primary_keys.sql` and `04_indices.sql` MUST be the official OHDSI files with the schema replaced; no file of the kit MUST keep `@cdmDatabaseSchema`.
- **FR-017-06** — `cargar.sh` MUST be an `sh` script that stops on any error (`set -e`), moves to the root of the ZIP and runs files 01, 02, 03 and 04 with `psql -v ON_ERROR_STOP=1`, in that order, with the connection taken from the standard `PG*` variables.
- **FR-017-07** — `dqd.R` MUST run `DataQualityDashboard::executeDqChecks` on PostgreSQL, with the connection taken from the `PG*` variables, the load schema as CDM, results and vocabulary schema, `cdmSourceName` equal to the name configured for `CDM_SOURCE` (escaped as an R string), `cdmVersion` 5.4 and the results in the `dqd_resultados` folder.
- **FR-017-08** — `LEEME.txt` MUST explain, in the project's language and naming the schema, the steps: requirements and connection variables, `sh carga_cdm/cargar.sh`, loading the Athena vocabularies and running `dqd.R`, and the provenance of the OHDSI files.
- **FR-017-09** — All the comments of the kit —the headers of `01_ddl.sql` and `02_carga.sql`, the two lines of `cargar.sh` and those of `dqd.R`— MUST be in the project's language, with no hard-coded texts in the code.
- **FR-017-10** — The ZIP MUST include `MAPEO_usagi.csv`, identical to the one downloaded from the export screen (`doc=usagi`), with the 17 columns of the Usagi format in their order (`sourceCode` … `assignedReviewer`).
- **FR-017-11** — In the Usagi CSV, a field with its own concept MUST be a `MAPS_TO` row with `sourceCode` = field name; with the «value per choice» strategy, each choice that produces a row and has a concept MUST be a `MAPS_TO` row with `sourceCode` = `field=code` and `sourceName` = «label: choice»; with «concept + value», the question is `MAPS_TO` and each answer `MAPS_TO_VALUE`. Choices that produce no row or have no concept do not appear.
- **FR-017-12** — Approved fields MUST go with `mappingStatus` `APPROVED`, `statusSetBy` = who approved and `statusSetOn` = approval date in milliseconds; the rest with `UNCHECKED` and `statusSetOn` 0. Excluded fields, fields without a domain, «not mappable» fields and date anchors do not appear.
- **FR-017-13** — With the export of the test project, `sh carga_cdm/cargar.sh` MUST load all the tables into an empty PostgreSQL without errors, with the same number of rows per table as the report states and with no facts pointing to non-existent persons or visits. `[manual]`
- **FR-017-14** — With the previous load and the Athena vocabularies in the same schema, `Rscript carga_cdm/dqd.R` MUST finish and leave the DQD results in `dqd_resultados`. `[manual]`
- **FR-017-15** — If the official DDL cannot be read on the server, the export MUST complete without the `carga_cdm/` folder and the report MUST say that the load kit was not included (FR-011-09), without pointing to `carga_cdm` or `cargar.sh`.

## Non-functional requirements

- **NFR-017-01** — The OHDSI files are distributed unmodified in `resources/`, with their source and license (Apache 2.0) declared in `resources/NOTICE.txt`.
- **NFR-017-02** — The kit adds no dependencies to the REDCap server: it only runs at the destination, with `psql` and, for the DQD, R.
- **NFR-017-03** — The schema name is never interpolated into SQL without validation (prevents injection through the setting).

## Acceptance criteria

```gherkin
Scenario: the kit travels with the data
  Given the test project with the curated mapping
  When I generate the export
  Then the ZIP contains carga_cdm/01_ddl.sql … carga_cdm/LEEME.txt
  And 02_carga.sql has one \copy per CSV and none for OBSERVATION

Scenario: configurable and safe schema
  Given the load-schema setting «Omop_TB»
  When I generate the export
  Then the DDL creates «omop_tb.person» and the load copies into «omop_tb.person»
  And with load-schema «cdm; DROP TABLE person» the schema is «cdm»

Scenario: Usagi distinguishes question and answer
  Given the field «bac» with the «concept + value» strategy
  When I open MAPEO_usagi.csv
  Then there is a «bac» MAPS_TO 3016102 row
  And two rows «bac=1» and «bac=2» MAPS_TO_VALUE

Scenario: without a readable DDL there is no kit, and the report says so
  Given that resources/ddl/postgresql/ cannot be read
  When I generate the export
  Then the ZIP carries the CSVs but not carga_cdm/
  And the report says that the load kit could not be included

Scenario: real load
  Given an empty PostgreSQL 17
  When I run sh carga_cdm/cargar.sh from the root of the ZIP
  Then it finishes without errors
  And SELECT count(*) FROM cdm.person returns 3
```

## Out of scope

- DDL for other engines (SQL Server, Oracle, BigQuery…) and other CDM versions.
- The OHDSI constraints file (foreign keys): its FKs point to the vocabulary
  tables, which the module does not distribute.
- Downloading or loading the Athena vocabularies (their own licenses).
- Importing a mapping edited in Usagi into the module.
- Running the load or the DQD from REDCap.

## Manual verification

**FR-017-13 — load into PostgreSQL.** With Docker and the export of the test
project (`omop_cdm_pid<N>_<date>.zip`):

```bash
docker run -d --name cdm_pg -e POSTGRES_PASSWORD=cdm -e POSTGRES_DB=cdm postgres:17
mkdir omop && cd omop && unzip ../omop_cdm_pid*.zip && cd ..
docker cp omop cdm_pg:/omop
docker exec -w /omop -e PGUSER=postgres -e PGDATABASE=cdm cdm_pg sh carga_cdm/cargar.sh
```

It must finish without `ERROR`. Check:

```bash
docker exec cdm_pg psql -U postgres -d cdm -c "SELECT count(*) FROM cdm.person"
docker exec cdm_pg psql -U postgres -d cdm -c \
  "SELECT count(*) FROM cdm.measurement m LEFT JOIN cdm.person p USING (person_id) WHERE p.person_id IS NULL"
docker exec cdm_pg psql -U postgres -d cdm -c \
  "SELECT count(*) FROM cdm.measurement m LEFT JOIN cdm.visit_occurrence v USING (visit_occurrence_id)
   WHERE m.visit_occurrence_id IS NOT NULL AND v.visit_occurrence_id IS NULL"
```

The first query gives the same as `PERSON` in `INFORME.txt`; the other two,
0. Repeat the count for each table in the report. Run again with
`load-schema` = `omop_tb` and check that the tables are in `omop_tb`.
When done: `docker rm -f cdm_pg`.

**FR-017-14 — Data Quality Dashboard.** Load into the same schema the
vocabularies downloaded from athena.ohdsi.org (`CONCEPT.csv`,
`CONCEPT_RELATIONSHIP.csv`, `CONCEPT_ANCESTOR.csv`…) with `\copy … DELIMITER E'\t'`.
On a machine with R, `DatabaseConnector`, `DataQualityDashboard` and the
PostgreSQL JDBC driver (`DatabaseConnector::downloadJdbcDrivers("postgresql")`,
with `DATABASECONNECTOR_JAR_FOLDER` set), and the variables `PGHOST`, `PGPORT`,
`PGUSER`, `PGDATABASE` and `PGPASSWORD` pointing to the container (publish the
port with `-p 5433:5432`), run `Rscript carga_cdm/dqd.R` from the root of the
ZIP. It must finish and create `dqd_resultados/` with the results JSON.

**FR-017-10 — Usagi.** Open `MAPEO_usagi.csv` in Usagi (*File → Open*): all
the rows load, with the approved ones in state *Approved*.

## Amendments

| Date | Requirement | Change | Motivated by |
|---|---|---|---|
| — | — | Initial version | — |
