# Tasks 015 — Reuse: @OMOP annotations and import

Before you start: read `constitution.md`, `AGENTS.md`, and the `spec.md` and
`plan.md` in this folder. Do the tasks in order. Each `[T]` must end with the
test **red**; the following `[I]` turns it green.

Run only this spec's tests: `php tests/run.php 015`

| Legend | |
|---|---|
| `[T]` | Write a test (red) |
| `[I]` | Implement (green) |
| `[R]` | Refactor, document or translate |
| `[P]` | Can be done in parallel with the previous task |

---

### T015-01 [T] Read @OMOP tags

- **Requirements:** FR-015-01, FR-015-02
- **Files:** `tests/specs/015_mapping_reuse.test.php`, `tests/lib/fixtures.php`
- **What to do:**
  1. Check that the test project has `hr` with
     `"@HIDDEN\n@OMOP-DOMAIN=Measurement @OMOP-CONCEPT=\"3027018 | Heart rate\" @OMOP-UNIT=\"{beats}/min\" @OMOP-DATE=visit_date"`
     and `smear2` with `@OMOP-VALUES="1=9191 | Positive; 2=-"`.
  2. `MappingAnnotation::parse()` of `hr` gives the domain, 3027018, "Heart
     rate", the unit and the date; of `smear2`, option 1 with 9191 and option 2
     with `emit = false`.
  3. `parse('@HIDDEN @DEFAULT="1"') === null`.
  4. `"@omop-domain=condition @OMOP-STRATEGY=PER_CHOICE @OMOP-CONCEPT='201820 | Diabetes mellitus'"`
     gives `Condition`, `per_choice`, 201820; `@OMOP-DATE=""` gives
     `date_field = null` with the key present; `@OMOP-EXCLUDE` gives
     `exclude = true`; `@OMOP-CONCEPT="2345-7 | Glucose"` gives
     `concept_id = null`.
  5. `@OMOP-VALUES="1=9191 | Positive; garbage; 2=-"` gives only the keys 1
     and 2.
- **Done when:** the test fails with `Class "…\MappingAnnotation" not found`.
- **Size:** S

### T015-02 [I] `MappingAnnotation::parse`

- **Requirements:** FR-015-01, FR-015-02
- **Files:** `classes/MappingAnnotation.php`
- **What to do:** the expression from the plan with
  `preg_match_all(..., PREG_SET_ORDER)`; the value is the first non-empty
  group (double quotes, single quotes, unquoted). `idAndName()` splits on the
  first `|`; `canonicalDomain()` compares case-insensitively with
  `CdmModel::validDomains()` (if there is no match, it leaves the text as it
  is so that `adoptAnnotation` discards it). VALUES: `preg_split('/\s*;\s*/')`
  and `^\s*([^=]+?)\s*=\s*(.+)$`.
- **Hints:** `array_key_exists('DATE', $tags)` distinguishes "no date" from
  "default date".
- **Done when:** `php tests/run.php 015` is green.
- **Size:** M

### T015-03 [T] Write and merge tags

- **Requirements:** FR-015-03, FR-015-04
- **Files:** `tests/specs/015_mapping_reuse.test.php`
- **What to do:** `parse(render($p['spec'], $p['values'], false))` equals the
  original; `render(['domain' => 'Drug'], [], true) === '@OMOP-EXCLUDE'`; with
  `single_concept` there is no `VALUES`;
  `render(['concept_id' => 5, 'concept_name' => 'Peso "real"; basal'], [], false)`
  gives `@OMOP-CONCEPT="5 | Peso 'real', basal"`.
  `merge("@HIDDEN\n@OMOP-DOMAIN=Drug", $tags)` starts with `@HIDDEN` and does
  not contain `Drug`; it is idempotent; with `$tags = ''` it leaves
  `"@HIDDEN\nteam note"`.
- **Done when:** the new tests fail.
- **Size:** S

### T015-04 [I] `render` and `merge`

- **Requirements:** FR-015-03, FR-015-04
- **Files:** `classes/MappingAnnotation.php`
- **What to do:** `render()` in the fixed order of NFR-015-01, `VALUES` only
  if `MappingStore::usesOptions($strategy)`, and `clean()` (`"` → `'`,
  `;` → `,`, trims `' |'`). `merge()` removes with the same expression,
  collapses empty lines with `/[ \t]*\n[ \t]*(\n[ \t]*)+/` and appends the
  tags on a new line.
- **Done when:** `php tests/run.php 015` is green.
- **Size:** S

### T015-05 [T] Proposal from the annotation and adoption

- **Requirements:** FR-015-05, FR-015-06
- **Files:** `tests/specs/015_mapping_reuse.test.php`
- **What to do:** `make_module()->annotation('hr')` equals `parse()` and
  `annotation('weight') === null`, with nothing saved in
  `store()->load()['fields']['hr']`. With `curate()`:
  `adoptAnnotation('hr', …)` returns `true` and leaves `proposed`, `edited`,
  `source = 'annotation'`; `smear2` adopts the options with
  `source_value = 'Positive'`; an annotation with domain `Invented`, strategy
  `magic`, a non-existent date and option `9` changes none of those;
  `@OMOP-EXCLUDE` on `visit_date` excludes it; on `weight` (approved) it
  returns `false`. After approving `hr`, `measurement` has rows with 3027018.
- **Done when:** the new tests fail.
- **Size:** M

### T015-06 [I] `annotation()` and `adoptAnnotation()`

- **Requirements:** FR-015-05, FR-015-06
- **Files:** `OmopCdmExportModule.php`, `classes/MappingStore.php`
- **What to do:** `annotation()` with `$annotationCache`. `adoptAnnotation()`:
  if the field is approved, `false`; it walks `$parsed['spec']` discarding
  whatever is not valid; `date_default = true` if the annotation has an empty
  `DATE`; state `excluded` or `proposed`; `edited`, `source`,
  `updated_by/at`; options only if they exist in `$choices`, with
  `source_value` = the label here; `save()`.
- **Hints:** do not call `updateField()`: it would withdraw approvals and log
  state changes that were not made by a person.
- **Done when:** `php tests/run.php 015` is green.
- **Size:** M

### T015-07 [T] Annotated dictionary without writing to the project

- **Requirements:** FR-015-08, FR-015-09
- **Files:** `tests/specs/015_mapping_reuse.test.php`
- **What to do:** with `curate()` and `notes` excluded, keep
  `serialize(\REDCap::$dict)`, generate `annotatedDictionaryCsv()` and read it
  with `fgetcsv` (removing the BOM): 18 headers, the first
  `Variable / Field Name`, the last `Field Annotation`; every field; `weight`
  with `@OMOP-CONCEPT="3025315 | Body weight"`; `notes` with `@OMOP-EXCLUDE`;
  `smear2` and `site` with the original annotation. The ZIP from `writeZip()`
  contains `DICCIONARIO_anotado.csv` and keeps `@HIDDEN`. The serialised
  dictionary has not changed and no module setting contains `dictionary`.
- **Done when:** the new tests fail.
- **Size:** S

### T015-08 [I] `annotatedDictionaryCsv()` and the ZIP

- **Requirements:** FR-015-08, FR-015-09
- **Files:** `classes/MappingDocument.php`, `classes/CsvExporter.php`, `pages/export.php`, `lang/*.ini`
- **What to do:** walk `getDictionary()`; if `mappingRows()[$field]['state']`
  is `approved` or `excluded`, `merge(annotation, render(spec, values, excluded))`.
  Write the BOM and the 18 columns from the plan. `CsvExporter` saves it as
  `DICCIONARIO_anotado.csv`; the export page offers the `ex_btn_dict` button
  with `&doc=dictionary`.
- **Hints:** work on a copy of `$meta`: never modify the cached dictionary
  array.
- **Done when:** `php tests/run.php 015` is green.
- **Size:** S

### T015-09 [T] Import MAPEO.json

- **Requirements:** FR-015-10, FR-015-11, FR-015-12, FR-015-13
- **Files:** `tests/specs/015_mapping_reuse.test.php`
- **What to do:** curate the project, exclude `notes`,
  `$doc = mappingDocument()->build()`. In the target:
  `unset(\REDCap::$dict['glu'], \REDCap::$dict['med_end'])`; `fever` becomes
  `radio` with `1, Yes | 0, No`; `bac` with codes `1 | 3`; `sex` with
  `1, Hombre | 2, Mujer`; `weight` approved here with 3036277. Import with a
  new `make_module()` and check: `glu` → `missing`, `fever` → `type`, `bac` →
  `choices`, `visit_date` → `no_mapping`, `weight` → `approved_here` (and it
  still has 3036277); `sex` and `drug` imported; nothing approved; `drug` with
  `source = import`, `date_field = med_start`, `end_date_field = null`; the
  log entry "Mapping imported from another project"; `notes` excluded; `sex`
  with `import_diff = ['1', '2']` and the `import_labels_differ` warning;
  `drug` without a warning; after approving `sex`, no `import_diff` and no
  warning.
- **Done when:** the new tests fail.
- **Size:** M

### T015-10 [I] `importMapping()` and the warning

- **Requirements:** FR-015-10, FR-015-11, FR-015-12, FR-015-13
- **Files:** `classes/MappingStore.php`, `classes/MappingAdvisor.php`, `lang/*.ini`
- **What to do:** the algorithm from the plan, in that order. Compare codes
  with `sort()` of the keys as strings and labels with
  `TextNormalizer::normalize()`. In `updateField()`, on approval,
  `unset($after['import_diff'])`. In the advisor, at the start of `check()`:
  `if (!empty($spec['import_diff']) && $spec['state'] !== approved)` →
  `warn import_labels_differ` with the codes. Log with `imported`, `skipped`
  and `from_project`.
- **Hints:** PHP numeric keys turn `'1'` into `1`: always compare
  `array_map('strval', …)`.
- **Done when:** `php tests/run.php 015` is green.
- **Size:** M

### T015-11 [I] Editor: annotations and import

- **Requirements:** FR-015-07, FR-015-14
- **Files:** `pages/editor.php`, `lang/*.ini`
- **What to do:**
  1. When rendering the rows, if the field is not `edited` and has an
     annotation, `array_merge($spec, $ann['spec'])` and the `@OMOP` badge
     (`ed_badge_annotation_title`); header `ed_annotations_found`.
  2. In `save-field` and `save-value`, if the field is not `edited` and there
     is an annotation, `adoptAnnotation()` before applying the change.
  3. Bulk action `adopt-annotations` (preview with a count,
     `ed_bulk_confirm_adopt`, and a module log entry with the number of
     annotations adopted as proposals).
  4. "Import mapping" button with `<input type="file" accept=".json">`, and an
     `import-mapping` action that validates `isset($doc['fields'])` (otherwise
     400 `ed_import_bad`) and shows `ed_import_done` / `ed_import_skipped`.
- **Done when:** the manual verification of FR-015-07 and FR-015-14 passes.
- **Size:** L

### T015-12 [T] Import texts

- **Requirements:** FR-015-15
- **Files:** `tests/specs/015_mapping_reuse.test.php`
- **What to do:** at the end of the file, for `Spanish` and `English`, read
  the `.ini` with `parse_ini_file()` and check that `ed_import_title` contains
  «códigos»/"codes" and not «opciones»/"options", and that
  `ed_import_skipped` contains every reason in `$r['skipped']` from T015-09
  plus `missing`, `type`, `choices`, `approved_here` and `no_mapping`.
- **Done when:** the test fails if `no_mapping` is missing or the title talks
  about "options".
- **Size:** S

### T015-13 [R] README and translations

- **Requirements:** FR-015-15, NFR-015-01, NFR-015-02
- **Files:** `README.md`, `lang/Spanish.ini`, `lang/English.ini`
- **What to do:** a README section on how the mapping travels with the
  instrument, with the tag format, the annotated dictionary workflow
  (download → Designer) and the import rules (name, type and codes;
  exclusions; what is approved here wins). Write `ed_import_title` ("same
  name, same type and same answer codes") and `ed_import_skipped` with the
  five reasons, in both languages.
- **Done when:** `php tests/run.php 015` is green and the keys of the two
  `.ini` files match.
- **Size:** S

---

## Definition of done for the spec

- [ ] All `015` tests green and the rest of the suite without regressions.
- [ ] `php tests/run.php --trace 015` with no orphan requirements.
- [ ] Manual verification of FR-015-07 and FR-015-14 done and recorded.
- [ ] New texts in `lang/Spanish.ini` and `lang/English.ini` with the same keys.
- [ ] README updated (section on the mapping travelling with the instrument).
- [ ] Spec status changed to `Implemented` (`Amended` after 018).
