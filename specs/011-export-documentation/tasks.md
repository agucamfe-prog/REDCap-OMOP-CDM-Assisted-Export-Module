# Tasks 011 — Export, report and mapping document

Before starting: read `constitution.md`, `AGENTS.md`, and this folder's
`spec.md` and `plan.md`. Do the tasks in order. Every `[T]` must end with the
test **red**; the following `[I]` turns it green.

Run only this spec's tests: `php tests/run.php 011`

| Legend | |
|---|---|
| `[T]` | Write a test (red) |
| `[I]` | Implement (green) |
| `[R]` | Refactor, document or translate |
| `[P]` | Can be done in parallel with the previous task |

---

### T011-01 [T] One CSV per table with rows, official columns

- **Requirements:** FR-011-01, FR-011-02, NFR-011-01
- **Files:** `tests/specs/011_export_documentation.test.php`
- **What to do:**
  1. Create the closures `$openZip(array $zip): array` (opens with `ZipArchive`,
     returns `[name => content]` and deletes the ZIP) and `$csv(string): array`
     (reads with `fgetcsv($fh, 0, ',', '"', '')` from `php://temp`).
  2. `$m = make_module(); curate($m); $res = $m->transformer()->run(); $zip = $m->exporter()->writeZip($res);`
  3. Check: `strtoupper($t) . '.csv'` exists for each table of
     `array_filter($res['tables'])`; `OBSERVATION.csv` does not exist;
     `$zip['files'] === array_map('count', array_filter($res['tables']))`.
  4. For each CSV, the first row `=== CdmModel::columns($t)` and
     `count($rows) - 1 === count($res['tables'][$t])`. In `PERSON.csv`,
     `location_id` is `''`.
  5. `!is_dir(substr($zip['path'], 0, -4))`.
- **Done when:** the tests fail with «Call to undefined method … exporter()».
- **Size:** M

### T011-02 [I] CsvExporter: temporary directory, tables and ZIP

- **Requirements:** FR-011-01, FR-011-02, NFR-011-01, NFR-011-03
- **Files:** `classes/CsvExporter.php`, `OmopCdmExportModule.php`
- **What to do:**
  1. Class `CsvExporter` with `__construct($module)` and `writeZip(array $result): array`.
  2. Directory `sys_get_temp_dir() . '/omop_export_' . $pid . '_' . uniqid()` with `mkdir(…, 0700, true)`.
  3. `writeTable(string $dir, string $table, array $rows): int`: header
     `CdmModel::columns($table)`; per row, `$row[$col] ?? null`, `null` → `''`.
  4. Compress with `ZipArchive` (relative paths), delete files and directory and
     return `['path' => …, 'files' => [table => n], 'bytes' => filesize()]`.
  5. In the module: `public function exporter(): CsvExporter { return new CsvExporter($this); }`
     and the class's `require_once`.
- **Hints:** skip empty tables with `if (empty($rows)) continue;`. Delete each
  file with `unlink` before `rmdir`: `rmdir` does not delete non-empty directories.
- **Done when:** `php tests/run.php 011` is green for FR-011-01/02 and NFR-011-01.
- **Size:** M

### T011-03 [T] RFC 4180, truncation and CDM_SOURCE

- **Requirements:** FR-011-03, FR-011-04, FR-011-05
- **Files:** `tests/specs/011_export_documentation.test.php`
- **What to do:**
  1. `\REDCap::$data['1'][1]['notes'] = 'He said "hi" C:\\dir\\" end';` and
     `make_module(['cdm-source-abbreviation' => str_repeat('A', 30)])`. Export and
     check that `note_text` in `NOTE.csv` is identical and that
     `cdm_source_abbreviation` is 25 characters long.
  2. The report contains `VALUES TRUNCATED TO THE CDM LIMIT`,
     `cdm_source_abbreviation   1 value(s), max 25 characters` (use a
     regular expression with `\s+`) and `comes from the module configuration`.
  3. With the default configuration, the `CDM_SOURCE.csv` row has
     `Test`, `Test`, `v5`, `v5.4`, `756265`, `OMOP-CDM Export vtest` and
     today's date. With an empty `cdm-source-name`, `cdmSourceConfig()['name']`
     is `REDCap project 1` and the abbreviation `RC1`.
- **Done when:** the tests fail (with the default escape, the text comes out
  broken; without `fit()`, the abbreviation is 30 characters long).
- **Size:** S

### T011-04 [I] Escape, truncation and source row

- **Requirements:** FR-011-03, FR-011-04, FR-011-05
- **Files:** `classes/CsvExporter.php`, `classes/Transformer.php`, `OmopCdmExportModule.php`, `lang/*.ini`
- **What to do:**
  1. `fputcsv($fh, $line, ",", "\"", "")` for the header and the rows.
  2. In `writeTable()`, `CdmModel::fit($col, $v)`; if `(string) $fitted !== (string) $v`,
     `$this->truncated[$col]++`.
  3. `cdmSourceConfig()` in the module (see plan) and a private `buildCdmSource()`
     in `Transformer`, called at the end of `run()`.
  4. Keys `cdm_default_name = "REDCap project {0}"` / `"Proyecto REDCap {0}"` and
     `cdm_source_description` in both `.ini` files.
- **Hints:** compare as text: the integer `1` and the string `"1"` are not a
  truncation. Do not truncate in `buildCdmSource()`: the exporter does it and counts it.
- **Done when:** `php tests/run.php 011` is green.
- **Size:** M

### T011-05 [T] The report: header, sections and closing

- **Requirements:** FR-011-06, FR-011-07, FR-011-08, FR-011-09
- **Files:** `tests/specs/011_export_documentation.test.php`
- **What to do:** with `$export($m)['INFORME.txt']` check:
  1. Test data: starts with `OMOP CDM v5.4 TRANSFORMATION REPORT`;
     `RECORDS PROCESSED: 3`; line `/^\s+PERSON\s+3$/m`;
     `VALUES NOT EMITTED BY DESIGN: 2`; `1 answer(s) with an ambiguous format`;
     `VALUES WITH AN OPERATOR`; `1 end date(s) earlier than their start`;
     `IDENTIFIERS`; `(schema cdm)`; ends with `PERSONAL DATA NOTICE` and its paragraph.
  2. Record 2 without `dob`, glucose of record 3 `pending` and `sex` returned to
     `proposed` with `store()->updateField('sex', ['state' => 'proposed'], …)` +
     `invalidateMappingCache()`: `PERSONS DROPPED: 1`, `Affected records: 2`,
     `1 measurement answer(s) with no numeric value`, `The sex field is not approved`.
  3. Empty `cdm-holder` and record 3 without `visit_date`:
     `CDM_SOURCE.cdm_holder   1 empty row(s)`, `no reference date (weight)`,
     `TOTAL DISCARDED: 4 values`.
  4. With `make_module(['hash-person-source-value' => true])` and record 2
     without `dob`: `Affected records` does not appear and `The affected Record IDs are not listed` does.
  5. Sex proposed and record 3 without a date: with
     `preg_match_all('/^(\S[^\n]*)\n-{60}$/m', $inf, $tt)` no title is
     repeated, and `PERSONS WITHOUT AN OBSERVATION PERIOD` comes before `IDENTIFIERS`.
  6. Neither the English nor the Spanish `rep_not_numeric` contains `<`.
  7. Every `->skip('code'` in `classes/Transformer.php` has `skip_code`
     in both `.ini` files.
- **Done when:** the tests fail because there is no `INFORME.txt`.
- **Size:** M

### T011-06 [I] writeReport()

- **Requirements:** FR-011-06, FR-011-07, FR-011-08, FR-011-09, NFR-011-04
- **Files:** `classes/CsvExporter.php`, `lang/English.ini`, `lang/Spanish.ini`
- **What to do:**
  1. `private function writeReport(string $dir, array $result): void`, called
     after writing the tables.
  2. Closures `$section($title)` (empty line, title, 60 dashes) and
     `$note($text)` (`wordwrap($text, 68, "\n", true)`, 2-space indent).
  3. Follow the plan's section table, in that order and with a different
     title per section. Discards are translated with
     `$module->skipLabel($key)`. With `setting('hash-person-source-value')`,
     `rep_dropped_ids_hashed` instead of the list of Record IDs.
     `writeReport($dir, $result, bool $kitIncluded = true)`: without a kit,
     `rep_next_no_kit` instead of `rep_next_kit`.
  4. Use `$module->ttText()` (plain text), never `tt()`.
  5. Add all the `rep_*` keys to both `.ini` files, with the same keys.
- **Hints:** the conditional sections check `!empty($s['key'])`. The personal
  data notice goes last, unconditionally. The example in `rep_not_numeric`
  cannot be `< 50`: that is a value with an operator and it is converted
  (section `rep_operator_title`).
- **Done when:** `php tests/run.php 011` is green.
- **Size:** L

### T011-07 [T] The report in Spanish

- **Requirements:** FR-011-06, FR-011-07, FR-011-09
- **Files:** `tests/specs/011_export_documentation.test.php`
- **What to do:** `\ExternalModules\AbstractExternalModule::$lang = parse_ini_file(dirname(__DIR__, 2) . '/lang/Spanish.ini');`
  and `make_module(['reserved-language-project' => 'Spanish'])`. Check
  `INFORME DE TRANSFORMACIÓN A OMOP CDM v5.4`, `REGISTROS PROCESADOS: 3`,
  `VALORES NO EMITIDOS A PROPÓSITO: 2`, `IDENTIFICADORES`,
  `AVISO SOBRE DATOS PERSONALES`. Restore English when done.
- **Done when:** the test fails if any key is missing from `Spanish.ini`
  (`[rep_…]` comes out) and passes when they are all there.
- **Size:** S

### T011-08 [T] Mapping document: structure and JSON

- **Requirements:** FR-011-11
- **Files:** `tests/specs/011_export_documentation.test.php`
- **What to do:** `$d = $m->mappingDocument()->build();` and check:
  `$d['cdm_source']['name'] === 'Test'`, `$d['person']['birth_date_field'] === 'dob'`,
  `$d['summary']['by_state']['approved'] === 9`,
  `$d['summary']['by_domain']['Measurement'] === 3`; `weight` with concept
  3025315, `cdm_table` `measurement`, unit `kg`, date `visit_date`,
  `approved_by` and `approved_at`; choices of `comorb` `['1'=>true,'2'=>true,'3'=>false]`
  and of `exitus` `['1'=>true,'0'=>false]`. With the label of `notes` changed to
  `Notas clínicas`, `json()` contains it without `\u00ed` and decodes the same as `build()`.
- **Done when:** it fails with «Call to undefined method … mappingDocument()».
- **Size:** M

### T011-09 [I] MappingDocument::build() and json()

- **Requirements:** FR-011-11
- **Files:** `classes/MappingDocument.php`, `OmopCdmExportModule.php`
- **What to do:** walk `$module->mappingRows()`; per field, copy the plan's
  data from `spec` and `profile`; `cdm_table` with `CdmModel::tableForDomain()`;
  `warnings` with `$module->advise($name)` (only `severity` and `text`); choices
  from `profile['choices']` and `$row['values']`. `json()` with
  `JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES`.
  Factory `mappingDocument(): MappingDocument` in the module.
- **Hints:** `by_domain` only counts `state === MappingStore::S_APPROVED`.
  A choice with no decision: `!DictionaryProfiler::isNegativeChoice($meta, $raw)`.
- **Done when:** `php tests/run.php 011` is green.
- **Size:** M

### T011-10 [T] Self-contained HTML document

- **Requirements:** FR-011-12, FR-011-13
- **Files:** `tests/specs/011_export_documentation.test.php`
- **What to do:** label of `weight` = `Body <b>weight</b> & "net"` and note
  `<i>review</i> & check` (with `'state' => 'approved'` in the same
  `updateField`). Check: starts with `<!DOCTYPE html>`, has `<style>`, does not
  contain `<script`, `<link`, `src=`, `url(`, `http://`, `https://`, `@import`;
  contains `&amp; &quot;net&quot;` and `&lt;i&gt;review`; `<html lang="en">`;
  `<code>3025315</code> Body weight`; `<li class="no-row">None — emits no row</li>`;
  `Fields not exported` with `<code>hr</code>` and the reason for `record_id`. In
  Spanish, `<html lang="es">`.
- **Done when:** it fails because `html()` does not exist.
- **Size:** S

### T011-11 [I] MappingDocument::html()

- **Requirements:** FR-011-12, FR-011-13
- **Files:** `classes/MappingDocument.php`, `lang/*.ini`
- **What to do:** build the HTML from `build()` with `$t = ttText` and
  `$e = htmlspecialchars(…, ENT_QUOTES, 'UTF-8')`. Sections `doc_sec_source`,
  `doc_sec_summary`, `doc_sec_mapped`, `doc_sec_unmapped` and footer `doc_footer`.
  `fieldsTable()` with `$detailed = true` (domain, concept or choices, unit,
  `warn`/`block` warnings, notes, date, approver) or `false` (reason).
- **Hints:** do not escape twice: `ttText()` already returns plain text. `info`
  warnings are not rendered. Styles only in the `<style>` of the header.
- **Done when:** `php tests/run.php 011` is green.
- **Size:** L

### T011-12 [T] The mapping travels in the ZIP, the fallback without the zip extension and the report without a kit

- **Requirements:** FR-011-10, FR-011-09, NFR-011-02
- **Files:** `tests/specs/011_export_documentation.test.php`
- **What to do:**
  1. The ZIP has `MAPEO.html` (starting with `<!DOCTYPE html>`) and `MAPEO.json`.
  2. Anonymous subclass: `new class extends \OmopCdm\OmopCdmExportModule\OmopCdmExportModule { public function mappingDocument(): \OmopCdm\OmopCdmExportModule\MappingDocument { throw new \RuntimeException('broken'); } }`;
     copy `settings` from `make_module()`, `curate()`, export: there is `PERSON.csv` and
     `INFORME.txt` and there is no `MAPEO.html`.
  3. `MiniZip::create($tmp . '.zip', [$tmp . '/A.csv' => 'A.csv', $tmp . '/sub/x.sql' => 'sub/x.sql'])`
     and read it with `ZipArchive`.
  4. Anonymous subclass of `CsvExporter` with `protected function ddlDir(): string { return '/does/not/exist/'; }`:
     the report says `load kit could not be included` and does not contain `carga_cdm`
     or `cargar.sh` (FR-011-09).
- **Done when:** all four fail (there is no document, no `try`, no
  `MiniZip`, the report always points to the kit).
- **Size:** S

### T011-13 [I] Document in the ZIP and MiniZip

- **Requirements:** FR-011-10, NFR-011-02
- **Files:** `classes/CsvExporter.php`
- **What to do:** in `writeZip()`, after the report, `try { $doc = $module->mappingDocument(); file_put_contents(…'/MAPEO.html', $doc->html()); … } catch (\Throwable $e) {}`.
  Class `MiniZip` with `create(string $zipPath, array $files): void`: per
  file, local header (`PK\x03\x04`, method 0, CRC32, sizes) + data;
  central directory (`PK\x01\x02`) and end record (`PK\x05\x06`); `dosTime()` for the
  date. Use it if `!class_exists('\ZipArchive')`. Call `writeLoadKit()`
  before `writeReport()` and pass it its result (`$kitIncluded`).
- **Hints:** the name inside the ZIP uses `/` on Windows too
  (`str_replace('\\', '/', …)`).
- **Done when:** `php tests/run.php 011` is green.
- **Size:** M

### T011-14 [T] Preview without consuming identifiers

- **Requirements:** FR-011-14
- **Files:** `tests/specs/011_export_documentation.test.php`
- **What to do:** add records 4 to 25 with `rec()`. `run(20, true)`:
  `stats['records'] === 20` and `getProjectSetting('omop-id-registry') === null`.
  Then `run()`: 25 records and the setting is no longer null.
- **Hints:** the `$dryRun` parameter reaches `idRegistry($dryRun)` (spec 013);
  here you only check the effect. If it is already implemented, the test passes
  the first time: note it in the task.
- **Done when:** the test is green with `run(?int $limit, bool $dryRun)`.
- **Size:** S

### T011-15 [R] Export screen

- **Requirements:** FR-011-15, NFR-011-01
- **Files:** `pages/export.php`, `config.json` (link), `lang/*.ini`
- **What to do:** before the REDCap header, resolve `doc=html|json|usagi|dictionary`
  and `run=download` (with `Content-Disposition`, `readfile` and `unlink`); after it,
  the plan's view: cards with `mappingCounts()`, warnings, buttons,
  preview with `run(20, true)` and its warnings, links to the documents.
  Verify by hand with the spec's procedure.
- **Hints:** catch the engine's `\Throwable` and show the message with file and
  line; log it with `$module->log()`. Escape everything with `htmlspecialchars`.
- **Done when:** the manual procedure of FR-011-15 passes in English and Spanish.
- **Size:** L

---

## Definition of done for the spec

- [ ] All `011` tests green and no regressions in the rest of the suite.
- [ ] `php tests/run.php --trace 011` reports no orphan requirements.
- [ ] New texts in `lang/Spanish.ini` and `lang/English.ini` with the same keys.
- [ ] Manual verification of FR-011-15 done in both languages.
- [ ] README updated («What is in the ZIP», «Mapping document»).
- [ ] Spec status changed to `Implemented`.
