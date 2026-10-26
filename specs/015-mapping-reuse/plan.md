# Plan 015 — Reuse: @OMOP annotations and import

## Technical summary

`MappingAnnotation` is a pure static class (no state, no REDCap) with three
operations: `parse()` (annotation → mapping), `render()` (mapping → tags) and
`merge()` (replace the `@OMOP` tags of an annotation while keeping the rest).
On top of it:

- `OmopCdmExportModule::annotation()` reads and caches the annotation of each
  field.
- `MappingStore::adoptAnnotation()` stores it as a proposal.
- `MappingDocument::annotatedDictionaryCsv()` writes the annotated dictionary,
  which `CsvExporter` puts in the ZIP and `pages/export.php` offers for
  download.
- `MappingStore::importMapping()` reads the `MAPEO.json` (the output of
  `MappingDocument::build()`) of another project.
- `MappingAdvisor` warns with `import_labels_differ` while there is an
  `import_diff`.

The editor (`pages/editor.php`) shows the annotation as a proposal, adopts it
on the first save, and offers "Use @OMOP annotations" and "Import mapping".

## Files

| File | Action | Responsibility |
|---|---|---|
| `classes/MappingAnnotation.php` | Create | `parse`, `render`, `merge` |
| `OmopCdmExportModule.php` | Modify | `annotation()` with a cache |
| `classes/MappingStore.php` | Modify | `adoptAnnotation()`, `importMapping()`, clear `import_diff` on approval |
| `classes/MappingDocument.php` | Modify | `annotatedDictionaryCsv()` |
| `classes/CsvExporter.php` | Modify | `DICCIONARIO_anotado.csv` in the ZIP |
| `classes/MappingAdvisor.php` | Modify | `import_labels_differ` warning |
| `pages/editor.php` | Modify | Badge, adoption on save, bulk `adopt-annotations`, `import-mapping` action |
| `pages/export.php` | Modify | `&doc=dictionary` |
| `lang/*.ini` | Modify | `ed_badge_annotation*`, `ed_annotations_found`, `ed_bulk_adopt`, `ed_bulk_confirm_adopt`, `ed_import*`, `ex_btn_dict*`, `adv_import_labels_differ` |

## Design

### Tag format

```
@OMOP-DOMAIN=Measurement
@OMOP-CONCEPT="3016102 | Mycobacterium sp identified in Sputum by Acid fast stain"
@OMOP-STRATEGY=value_concept
@OMOP-VALUES="1=9191 | Positive; 2=9189 | Negative; 3=-"
@OMOP-DATE=fecha_analitica
@OMOP-ENDDATE=fecha_fin
@OMOP-UNIT="mg/dL"
@OMOP-EXCLUDE
```

Expression used for reading (and for removing in `merge`):

```
/@OMOP-([A-Z]+)(?:\s*=\s*(?:"([^"]*)"|'([^']*)'|([^\s"']+)))?/i
```

### Signatures and structures

```php
// classes/MappingAnnotation.php
const PREFIX = '@OMOP-';
public static function parse(string $annotation): ?array
public static function render(array $spec, array $values, bool $excluded): string
public static function merge(string $annotation, string $tags): string
```

`parse('@OMOP-DOMAIN=Measurement @OMOP-STRATEGY=value_concept @OMOP-CONCEPT="3016102 | AFB" @OMOP-VALUES="1=9191 | Positive; 2=-"')`:

```php
['spec'    => ['domain' => 'Measurement', 'concept_id' => 3016102, 'concept_name' => 'AFB',
               'value_strategy' => 'value_concept'],
 'values'  => ['1' => ['raw_value' => '1', 'emit' => true,  'concept_id' => 9191, 'concept_name' => 'Positive'],
               '2' => ['raw_value' => '2', 'emit' => false, 'concept_id' => null, 'concept_name' => null]],
 'exclude' => false]
```

```php
// OmopCdmExportModule.php
public function annotation(string $field): ?array

// classes/MappingStore.php
public function adoptAnnotation(string $field, array $parsed, array $choices, array $dictionary): bool
public function importMapping(array $doc, array $profile, array $dictionary): array
//   → ['imported' => ['sex', 'drug', …], 'skipped' => ['glu' => 'missing', 'fever' => 'type', …]]

// classes/MappingDocument.php
public function annotatedDictionaryCsv(): string
```

### Import: order of the checks

```
for each field of MAPEO.json
  does it exist here?                 no  → missing
  approved here?                      yes → approved_here
  same field_type?                    no  → type
  same option codes?                  no  → choices
  different normalised labels         → import_diff = [codes]
  excluded there?                     yes → excluded, source = import  (end)
  valid domain?                       no  → no_mapping
  copy domain, concept_id, concept_name, value_strategy, unit_source_value, notes
  copy date_field / end_date_field only if they exist here
  state = proposed, source = import, approved_by/at = null
  copy the options with a decision (emit ≠ null)
```

A field with `import_diff` has the `import_labels_differ` warning (warn) as
long as it is not approved; `updateField(..., state = approved)` does
`unset($after['import_diff'])`.

### Annotated dictionary

Designer headers in this order: `Variable / Field Name`, `Form Name`,
`Section Header`, `Field Type`, `Field Label`, `Choices, Calculations, OR Slider
Labels`, `Field Note`, `Text Validation Type OR Show Slider Number`, `Text
Validation Min`, `Text Validation Max`, `Identifier?`, `Branching Logic (Show
field only if...)`, `Required Field?`, `Custom Alignment`, `Question Number
(surveys only)`, `Matrix Group Name`, `Matrix Ranking?`, `Field Annotation`.
UTF-8 BOM and `fputcsv` with `escape = ''` (doubled quotes, as Excel and REDCap
do).

## Decisions and discarded alternatives

- **Action tags in the annotation.** They travel with the XML, the copies and
  the Shared Library without new infrastructure. *Discarded:* a separate file
  next to the project, which gets lost when copying.
- **The module does not write the dictionary.** A person uploads it, with
  REDCap's review and draft mode (principle 11). *Discarded:* writing it
  through the API, which would skip the review of production changes.
- **Import by codes, not by labels.** The same translated instrument keeps
  the codes; different labels raise a warning (amendment from 018).
  *Discarded:* requiring identical labels, which imported nothing between
  sites.
- **Everything arrives as a proposal.** *Discarded:* keeping the approved
  state of the source, which contradicts principle 2.
- **`source` (`annotation` | `import`) on the field.** For the editor's badge
  and the audit of the mapping contract.

## Test strategy

- `tests/specs/015_mapping_reuse.test.php`, with the test project: the
  fields `hr` and `smear2` already carry an `@OMOP` annotation (with `@HIDDEN`
  in `hr`).
- `parse`/`render`/`merge` with literal strings: upper case, single quotes,
  empty `DATE`, `EXCLUDE`, malformed pairs, quotes and `;` in the name.
- `adoptAnnotation` on the curated project: proposed, excluded, approved left
  intact, discards, and export after approval.
- Annotated dictionary read with `fgetcsv` and from the ZIP; the dictionary
  (`\REDCap::$dict`) serialised before and after.
- Import: the project is curated, `build()` is exported, `\REDCap::$dict` is
  modified (field removed, type changed, codes changed, labels translated, end
  date removed) and it is imported into a new module.
- Manually: the editor UI (badge, buttons, messages).

## Constitution check

- **2 (writing does not approve):** adopting and importing leave `proposed`.
- **4 (what is saved wins):** neither the annotation nor the import touch
  what is approved.
- **11 (read only):** the annotated dictionary is downloaded; it is not
  written.
- **21 (bilingual):** import by codes across languages.
- **22 (explain):** each reason for not importing has a name and the warning
  says what to check.

## Risks

- A hand-written annotation with unclosed quotes is read only partly: `parse`
  is tolerant and the editor shows what it read so it can be reviewed.
- The same code with a different meaning between sites can only be detected
  by a person: that is why the warning requires confirmation.
- The warning disappears on approval; if the field is modified later, drift
  detection and the withdrawal of the approval (spec 006) keep working, but
  `import_diff` does not come back.
