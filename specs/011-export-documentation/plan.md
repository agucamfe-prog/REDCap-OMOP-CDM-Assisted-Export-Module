# Plan 011 — Export, report and mapping document

## Technical summary

`CsvExporter` receives the result of `Transformer::run()`, writes each table
with rows as a CSV in its own temporary directory, adds `INFORME.txt`, the
mapping document and the files of the specs that extend it, compresses
everything into a ZIP (with `ZipArchive` or, if the extension is missing, with
`MiniZip`) and deletes the directory. `MappingDocument` builds a single mapping
structure (`build()`) from which both the JSON and the self-contained HTML are
produced. The page `pages/export.php` orchestrates: status, warnings, preview
(`run(20, true)`), download and documents.

The `CDM_SOURCE` row is built by the engine (`Transformer::buildCdmSource()`)
from `OmopCdmExportModule::cdmSourceConfig()`, so that it goes through the same
truncation and the same required-field check as the other tables.

## Files

| File | Action | Responsibility |
|---|---|---|
| `classes/CsvExporter.php` | Create | ZIP, one CSV per table, `INFORME.txt`; `MiniZip` class |
| `classes/MappingDocument.php` | Create | `build()`, `json()`, `html()` |
| `classes/Transformer.php` | Modify | `buildCdmSource()`; `run(?int $limit, bool $dryRun)` |
| `OmopCdmExportModule.php` | Modify | `exporter()`, `mappingDocument()`, `cdmSourceConfig()`, `skipLabel()`, `ttText()` |
| `pages/export.php` | Create | Export screen and document service |
| `lang/English.ini`, `lang/Spanish.ini` | Modify | Keys `rep_*`, `doc_*`, `ex_*`, `cdm_default_name`, `cdm_source_description` |
| `tests/specs/011_export_documentation.test.php` | Create | Tests for this spec |

## Design

### Input: the engine's result

```php
// Transformer::run(?int $limit = null, bool $dryRun = false): array
[
  'tables'  => ['person' => [[...row...], ...], 'observation' => [], 'cdm_source' => [[...]], ...],
  'counts'  => ['person' => 3, 'measurement' => 9, 'cdm_source' => 1],   // only tables with rows
  'stats'   => ['records' => 3, 'rows' => 29, 'not_emitted' => 2, 'ambiguous_number' => 1,
                'with_operator' => 1, 'end_before_start' => 1, 'visits' => 3,
                'persons_dropped' => 1, 'rows_dropped_with' => 4, 'dropped_record_ids' => '2'],
  'skipped' => ['no_date|weight' => 1],             // code|argument → skipLabel()
  'missing' => ['cdm_source' => ['cdm_holder' => 1]], // empty NOT NULL columns
]
```

With no approved fields it returns `['error' => 'no_approved_fields', ...]` and
the page shows `ex_err_no_approved_fields`.

### CsvExporter

```php
public function __construct($module)
/** @return array{path: string, files: array<string,int>, bytes: int} */
public function writeZip(array $result): array
private function writeTable(string $dir, string $table, array $rows): int
private function writeReport(string $dir, array $result): void
private function writeLoadKit(string $dir, array $tables): void   // spec 017
```

`writeZip()` flow:

1. `mkdir(sys_get_temp_dir() . '/omop_export_<pid>_<uniqid>', 0700)`.
2. For each table with rows, `writeTable()`: header `CdmModel::columns($t)`,
   each non-empty value goes through `CdmModel::fit($col, $v)`; if the result
   differs (compared as text), it is added to `$this->truncated[$col]`.
   `fputcsv($fh, $line, ",", "\"", "")`: empty escape = pure RFC 4180.
3. `writeLoadKit()` (spec 017), which returns `false` if there is no kit.
4. `writeReport($dir, $result, $kitIncluded)` (after the tables, because it
   needs the truncations, and after the kit, because it says whether the kit
   was included).
5. Document: `MAPEO.html`, `MAPEO.json` and those of 015/017, inside a
   `try/catch (\Throwable)` that ignores the failure.
6. Walks the directory with `RecursiveDirectoryIterator`, sorts by path and
   compresses with relative paths (`carga_cdm/01_ddl.sql`).
7. Deletes files, subdirectories and the directory.

### INFORME.txt

Plain-text lines; each section is a title, a line of 60 dashes and paragraphs
wrapped at 68 columns with a two-space indent (closures `$section` and
`$note`). Order:

| Section | Title key | When |
|---|---|---|
| Header | `rep_title`, `rep_generated`, `rep_project`, `rep_module`, (`rep_dag`, 012), `rep_records`, `rep_rows` | always |
| Rows per table | `rep_by_table` | always |
| Not emitted | `rep_not_emitted` | `not_emitted > 0` |
| Not numeric | `rep_numeric_title` | `not_numeric` or `ambiguous_number` |
| Sex/race/ethnicity not approved (one section, one note per field) | `rep_person_config_title` | any `*_not_approved` |
| Dropped persons | `rep_dropped` (+ `rep_dropped_ids` or `rep_dropped_ids_hashed`) | `persons_dropped > 0` |
| Visits | `rep_visits` | `visits > 0` (spec 010) |
| Pseudonymization | `rep_privacy_applied` | spec 016 |
| Operators | `rep_operator_title` | `with_operator > 0` |
| End before start | `rep_end_before_start_title` | `end_before_start > 0` |
| Concepts outside the vocabulary | `rep_stcm_title` | `stcm_unknown_concept > 0` |
| No period | `rep_no_period_title` | `persons_without_period > 0` |
| Identifiers | `rep_ids_title` | always (spec 013 explains why) |
| Empty required fields | `rep_missing_title` | `missing` not empty |
| Truncations | `rep_truncated_title` | any truncation |
| Discards | `ex_skipped_title` + `rep_skipped_total` | `skipped` not empty |
| Next step | `rep_next_title` + `rep_next_kit` (schema) or `rep_next_no_kit` | always |
| Personal data | `rep_privacy_title` | always, at the end |

No title is repeated, so that each section can be searched for and cited.
Example of a per-table line: `sprintf('  %-28s %8s', 'PERSON', number_format(3))`.

**Record ID with hashing.** `dropped_record_ids` carries up to 20 Record IDs in
clear. If `hash-person-source-value` is enabled, the report does not list them:
it says (`rep_dropped_ids_hashed`) that they are not listed because the CDM
does not carry the Record ID and the report travels with it, and that they can
be seen in the preview, inside REDCap and under its user rights. Writing the
hash is discarded: the CDM does not contain those persons, so the hash links to
nothing and does not help to find the record that has to be fixed either
(constitution 9 and 13: the discard is counted and explained without undoing
the pseudonymization).

### CDM_SOURCE

```php
// OmopCdmExportModule::cdmSourceConfig(): array
['name' => 'Test', 'abbreviation' => 'RC1', 'holder' => 'Test', 'vocabulary_version' => 'v5']
```

`Transformer::buildCdmSource()` adds `source_description`
(`cdm_source_description`), `cdm_etl_reference` = `'OMOP-CDM Export ' .
getModuleVersion()`, `source_release_date` and `cdm_release_date` = today,
`cdm_version` = `v5.4`, `cdm_version_concept_id` = 756265. It does not
truncate: the exporter truncates and counts it.

### MappingDocument

```php
public function __construct($module)
public function build(): array
public function json(): string      // JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
public function html(): string
private function fieldsTable(array $d, array $states, array $colors, bool $detailed): string
// spec 017: usagiCsv(); spec 015: annotatedDictionaryCsv()
```

Structure of `build()`:

```php
[
  'generated' => '2026-10-25T15:58:59+00:00', 'module' => 'OMOP-CDM Export 1.0.0', 'cdm_version' => 'v5.4',
  'project'   => ['project_id' => 1, 'record_id_field' => 'record_id', 'longitudinal' => false,
                  'events' => ['basal_arm_1'], 'repeating' => [...]],
  'cdm_source'=> cdmSourceConfig(), 'person' => personConfig(), 'death' => deathConfig(), 'visits' => visitConfig(),
  'summary'   => ['total' => 18, 'by_state' => ['approved' => 9, 'proposed' => 5, 'config' => 4],
                  'by_domain' => ['Measurement' => 3, 'Condition' => 2, ...]],   // approved only
  'fields'    => ['weight' => ['field_name' => 'weight', 'form_name' => 'base', 'state' => 'approved',
                  'domain' => 'Measurement', 'cdm_table' => 'measurement', 'concept_id' => 3025315,
                  'concept_name' => 'Body weight', 'unit_source_value' => 'kg', 'date_field' => 'visit_date',
                  'approved_by' => 'ana', 'approved_at' => '2026-10-25 15:55:44', 'drifted' => false,
                  'drift_reason' => null, 'role' => null, 'warnings' => [['severity' => 'warn', 'text' => '…']],
                  'choices' => [['raw_value' => '1', 'label' => 'Yes', 'emit' => true,
                                 'concept_id' => null, 'concept_name' => null]], ...]],
]
```

Choice with no saved decision: `emit = !DictionaryProfiler::isNegativeChoice($meta, $raw)`.

HTML: `<!DOCTYPE html>`, `<html lang="ttText('doc_lang')">`, inline `<style>`
(including `@media print`), sections *Source and configuration*, *Summary*,
*Exported fields* (`fieldsTable(..., [approved], ..., true)`) and *Fields not
exported* (`fieldsTable(..., [proposed, excluded, review, config], ..., false)`,
with a reason: `ed_drift_<reason>` › `ed_role_<role>` › classifier reason).
Everything goes through `$e = htmlspecialchars(…, ENT_QUOTES, 'UTF-8')` over
text from `ttText()`, never from `tt()` (avoids double escaping).

### Export page

```
GET pages/export.php                → view
GET pages/export.php&doc=html|json|usagi|dictionary → document and exit
GET pages/export.php&run=preview    → view with preview (run(20, true))
GET pages/export.php&run=download   → writeZip(run()), headers, readfile, unlink, exit
```

The documents and the download are resolved **before** including the REDCap
header, so as not to corrupt the output. Engine failures are logged with
`$module->log()` and shown as they are (file:line) so that they can be
diagnosed.

## Decisions and discarded alternatives

- **Write to disk and compress.** A project with tens of thousands of records
  does not fit in memory as a string. *Discarded:* generating the ZIP in
  memory, because it exhausts `memory_limit`.
- **`MiniZip` as a fallback.** The `zip` extension is not present on every
  REDCap installation; without compression the ZIP is bigger but opens the
  same. *Discarded:* requiring the extension (constitution 14).
- **Empty escape in `fputcsv`.** With the default escape (`\`) a text with
  `\"` comes out corrupted for any RFC 4180 reader. *Discarded:* PHP's default
  value.
- **The report inside the ZIP**, with the fixed name `INFORME.txt` in every
  language, so that the processes that look for it find it. *Discarded:*
  showing it only on screen: whoever receives the CDM has no access to REDCap.
- **One structure, two formats.** `html()` and `json()` come from `build()`, so
  they cannot tell different stories. *Discarded:* separate templates.
- **HTML without external resources**, to archive it, send it or print it to
  PDF years later. *Discarded:* REDCap's own Bootstrap.
- **A document failure does not block the data.** The document is a
  complement; losing the export because of it would be worse.
- **CDM_SOURCE in the engine**, not in the exporter, so that it goes through
  truncation and empty-required-field detection like any other table.

## Test strategy

In `tests/specs/011_export_documentation.test.php`, with the test project
(`make_module()`, `curate()`) and the closures `$openZip`, `$export`, `$csv`:

- ZIP: CSV names against the tables with rows; `files` against the `count()`
  of each table; temporary directory deleted.
- Headers equal to `CdmModel::columns()`; number of lines; empty cell.
- RFC 4180: note with `He said "hi" C:\dir\" end` recovered with `fgetcsv(..., '')`.
- Truncation: `cdm-source-abbreviation` of 30 «A» → 25, and its section in the report.
- CDM_SOURCE: values from the configuration and defaults (empty `cdm-source-name`).
- Report in English and in Spanish (`AbstractExternalModule::$lang` with
  `parse_ini_file(lang/Spanish.ini)` and `reserved-language-project`), with
  scenarios: test data, record 2 without birth date (with and without hash),
  glucose `pending`, sex returned to proposed, empty `cdm-holder`, record 3
  without date; unique section titles (regular expression over title + 60
  dashes); without kit, with an anonymous subclass of `CsvExporter` whose
  `ddlDir()` points to a non-existent folder; every `->skip('…')` of the
  engine with its key.
- Document: anonymous subclass of the module whose `mappingDocument()` throws
  an exception → the ZIP still has the CSVs.
- `MiniZip::create()` with a subfolder, read with `ZipArchive`.
- `build()`, `json()`, `html()`: keys, summary, `weight` field, choices of
  `comorb` and `exitus`, absence of `<script`, `<link`, `src=`, `url(`, URLs;
  escaping of a label with `&` and quotes and of a note with `<i>`.
- Preview with 25 records: 20 processed, `omop-id-registry` null.
- The screen (FR-011-15) is verified by hand.

## Constitution check

- **1 (only what is approved):** the exporter writes what the engine gives;
  the document separates the approved fields from the rest.
- **9 (every discard is counted and explained):** sections for not emitted,
  discarded, persons, empty required fields and truncations, in the project's
  language.
- **11 (read only):** nothing is written to the project; the temporary
  directory is deleted.
- **13:** the personal data notice always closes the report.
- **14:** no dependencies; `MiniZip` if there is no `zip` extension.
- **15:** columns and limits from `CdmModel`, which reproduces the official file.
- **21:** all texts in `lang/*.ini`.

## Risks

- If the server goes down between creating the ZIP and sending it, the ZIP
  stays in the temporary directory. Mitigation: unique names in
  `sys_get_temp_dir()`, permissions 0700.
- A new discard reason in the engine without its `skip_*` key would come out
  as `[skip_…]`. Mitigation: a test walks the `->skip('…')` calls of
  `Transformer.php` and requires the text in both languages.
