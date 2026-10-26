# Spec 011 — Export, report and mapping document

| | |
|---|---|
| **Status** | Implemented |
| **Depends on** | 009 (and 010 for visits and entities) |
| **Tests** | `tests/specs/011_export_documentation.test.php` |

## Context

With spec 009 (and 010) the `Transformer::run()` engine already converts
REDCap records into rows of the CDM tables, in memory, with its statistics of
discards and warnings. What is missing is what the user takes away: a file
that can be loaded into an OMOP database and that explains, without going back
to REDCap, what was exported, what was discarded and why.

This spec defines the **export package**: a ZIP with one CSV per CDM table
(columns in the official order, because positional loaders require it), an
`INFORME.txt` in the project's language, the `CDM_SOURCE` table filled in from
the configuration and the **mapping document** in HTML and JSON. A CDM without
its mapping is data without provenance: they travel together.

The mapping document is also the only place where a person can see the error
that no automatic check detects: a valid but wrong `concept_id`. That is why
it can also be generated from the export screen, to review it before
exporting anything, and why it can be archived or printed without depending
on anything external.

Finally, the export screen (`pages/export.php`) brings together the mapping
status, the prior warnings, a preview over 20 records that consumes no
identifiers, the download and the links to the documents. Other specs extend
this package: 012 (data access groups and rights), 013 (stable identifiers),
015 (annotated dictionary), 016 (pseudonymization declared in the report) and
017 (load kit and Usagi file). They are cited here as extension points, not
specified.

## User stories

- **US-011-1.** As a data manager, I want to download a ZIP with one CSV per
  CDM table so that I can load it into my site's OMOP database without
  touching it up.
- **US-011-2.** As the person receiving the CDM, I want a report inside the ZIP
  that tells me how many rows there are per table and what was discarded and
  why, in the project's language, so that I can decide whether to accept the
  export.
- **US-011-3.** As a principal investigator, I want a readable mapping document
  (concept, unit, date, who approved each field) so that I can review it and
  file it next to the protocol.
- **US-011-4.** As a curator, I want to preview the export with a few records
  without leaving any trace (identifiers consumed) so that I can discover
  problems before generating the final package.
- **US-011-5.** As an analyst, I want the mapping in JSON so that I can version
  it and compare it between exports.

## Functional requirements

- **FR-011-01** — `CsvExporter::writeZip()` MUST produce a ZIP with a `TABLE.csv` file (table name in upper case) for each table of the result that has at least one row; empty tables produce no file. It returns the path of the ZIP, the rows written per table and its size in bytes.
- **FR-011-02** — Each CSV MUST have a header with all the columns of `CdmModel::columns($table)` in the official DDL order, and one line per row; a null or missing value comes out as an empty cell.
- **FR-011-03** — The CSVs MUST be pure RFC 4180 (comma separator, doubled double quotes, no escape character): a text with quotes and backslashes is recovered intact.
- **FR-011-04** — A value longer than its column's limit in the DDL (`CdmModel::TEXT_LIMITS`) MUST be truncated to that limit, and the report MUST list how many values were truncated per column and, if the column comes from the module configuration (`cdm_source_*`, `cdm_holder`, `vocabulary_version`, `cdm_etl_reference`), say that it should be fixed there.
- **FR-011-05** — `CDM_SOURCE` MUST have a single row built from the configuration: name (by default «REDCap project N» in the project's language), abbreviation (by default `RCN`), holder, vocabulary version, `cdm_version` `v5.4` with `cdm_version_concept_id` 756265, `cdm_etl_reference` with the module's name and version, and the release dates set to the day of the export.
- **FR-011-06** — The ZIP MUST include an `INFORME.txt` in the project's language with title, generation date, project, module version, records processed, rows generated and a rows-per-table section.
- **FR-011-07** — The report MUST include, only when they occur and each with its own title (none repeated), the sections for values not emitted by design, values that are not a number (no numeric value —the example in the text is not a value with an operator, which is converted— and ambiguous format), sex, race or ethnicity not approved, dropped persons (how many, how many rows they dragged along and which records; with `hash-person-source-value` enabled the Record IDs are not listed and the report explains why and where to see them), values with an operator, end dates earlier than the start, concepts missing from the vocabulary and persons without an observation period, the latter before the identifiers section.
- **FR-011-08** — The report MUST list the required (NOT NULL) columns that were left empty, with their number of rows, and every discard reason of the engine translated into the project's language (every reason has its text in both languages), with the total of discarded values.
- **FR-011-09** — The report MUST always end with the identifiers, recommended next step and personal data notice sections, in this order at the end of the file. The next step names the load kit and its schema (spec 017) or, if the kit could not be included, says so and refers to the official OHDSI DDL, without naming `carga_cdm` or `cargar.sh`.
- **FR-011-10** — The ZIP MUST include the mapping document as `MAPEO.html` and `MAPEO.json` (and the files of specs 015 and 017); if generating the document fails, the data export MUST still complete without it.
- **FR-011-11** — `MappingDocument::build()` MUST return the configuration (source, PERSON, DEATH, visits, project structure), a summary per state and per domain (the latter only with approved fields) and, for each field, its domain, table, concept, unit, reference date, who approved it and when, the advisor's current warnings and its choices with the flag of whether they produce a row (with no saved decision, the default: the «No» does not emit); `json()` MUST serialize that structure readably and without escaping Unicode characters.
- **FR-011-12** — `MappingDocument::html()` MUST produce a self-contained HTML document (inline styles; no external scripts, stylesheets, images or fonts), with every project text escaped and the `lang` attribute of the project's language.
- **FR-011-13** — The HTML document MUST show the configuration, the summary per state and domain, each exported field with its concept, unit, reference date, current warnings, approver and date, flag the choices that produce no row, and list the fields not exported with their reason (drift, role in the configuration or classifier reason).
- **FR-011-14** — The preview MUST run the transformation over at most 20 records without saving the new identifiers (`run(20, true)`); the full export processes all records and does save them.
- **FR-011-15** — The export screen MUST show cards with the number of approved, proposed, excluded and to-review fields; warn about the fields not approved and to review, about the missing birth field and about the other configuration problems; offer «Preview with 20 records» and «Generate and download» when there are approved fields, and the links to the HTML document (in a new tab), the JSON, the Usagi file and the annotated dictionary. `[manual]`

## Non-functional requirements

- **NFR-011-01** — No copies of clinical data are left on the server: the temporary directory with the CSVs is deleted when the ZIP is created and the page deletes the ZIP after sending it.
- **NFR-011-02** — The export works without PHP's `zip` extension: a built-in writer (`MiniZip`, without compression) produces a standard ZIP with folders.
- **NFR-011-03** — The CSVs are written to disk table by table, not built in memory, so that a large project does not exhaust PHP's memory limit.
- **NFR-011-04** — Every text of the report and of the document comes from `lang/*.ini` through `ttText()` (plain text, escaped only once where it is rendered).

## Acceptance criteria

```gherkin
Scenario: one CSV per table, with the official columns
  Given the test project with the curated mapping
  When I generate the export
  Then the ZIP contains PERSON.csv, MEASUREMENT.csv, NOTE.csv and CDM_SOURCE.csv
  And it does not contain OBSERVATION.csv, because that table has no rows
  And the header of each CSV is CdmModel::columns() of its table

Scenario: the report speaks the project's language
  Given the project configured in Spanish
  When I generate the export
  Then INFORME.txt starts with «INFORME DE TRANSFORMACIÓN A OMOP CDM v5.4»
  And it contains «VALORES NO EMITIDOS A PROPÓSITO: 2»
  And it ends with «AVISO SOBRE DATOS PERSONALES»

Scenario: persons without a birth date are counted
  Given that record 2 has no birth date
  When I generate the export
  Then the report says «PERSONS DROPPED: 1» and «Affected records: 2»

Scenario: with hashing, the report does not reveal the Record ID
  Given that record 2 has no birth date
  And person_source_value is hashed
  When I generate the export
  Then the report says «PERSONS DROPPED: 1»
  And it does not list record 2, but explains that it can be seen in the preview

Scenario: without a load kit, the report does not point to it
  Given that the OHDSI DDL cannot be read on the server
  When I generate the export
  Then the next step says that the kit could not be included
  And it does not mention carga_cdm or cargar.sh

Scenario: the mapping document is self-contained
  When I generate the HTML document
  Then it contains no <script, <link, src= or any URL
  And the «None» choice of comorbidities appears as «emits no row»

Scenario: previewing leaves no trace
  Given a project with 25 records
  When I preview
  Then 20 records are processed
  And the omop-id-registry setting is still empty
```

## Out of scope

- Rights to export, DAG filter and its line in the report: spec 012.
- Identifiers being stable between exports: spec 013. Here it is only
  required that the preview does not consume them.
- Annotated dictionary with `@OMOP` tags and import of the JSON into another
  project: spec 015.
- Section on the pseudonymization applied in the report: spec 016.
- PostgreSQL load kit and `MAPEO_usagi.csv`: spec 017.
- Exporting to other formats (Parquet, direct SQL into a database).

## Manual verification

**FR-011-15 — export screen.** In the Docker REDCap, with the test project and
an administrator user:

1. Open *External Modules → OMOP-CDM: export* with nothing approved: the four
   cards are shown (approved 0) and the «no approved fields» message; there
   are no preview or download buttons.
2. Approve two fields in the editor and leave another one proposed. Come back:
   the approved card says 2 and the yellow warning about non-approved fields
   appears.
3. Clear the birth field in *Configure*: the red warning that PERSON will come
   out empty appears. Restore it.
4. Click «Preview with 20 records»: the rows-per-table table and the preview
   warnings appear. Check in *Configure* that no identifier has changed (or,
   in the database, the `omop-id-registry` setting).
5. Click «Generate and download»: `omop_cdm_pid<N>_<date>.zip` is downloaded.
6. Click «Mapping document»: it opens in a new tab. «Mapping as JSON»,
   «Mapping for Usagi» and «Dictionary with @OMOP» each download their file.
7. Repeat with the project language set to Spanish: all texts change.

## Amendments

| Date | Requirement | Change | Motivated by |
|---|---|---|---|
| — | — | Initial version | — |
