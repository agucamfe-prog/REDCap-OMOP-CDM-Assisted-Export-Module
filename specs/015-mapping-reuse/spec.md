# Spec 015 — Reuse: @OMOP annotations and import

| | |
|---|---|
| **Status** | Amended |
| **Depends on** | 006, 011 |
| **Tests** | `tests/specs/015_mapping_reuse.test.php` |

## Context

The curated mapping lives in the module's settings (spec 006) and ships in the
`MAPEO.json` inside the ZIP (spec 011). But instruments get reused: one
project's PHQ-9 shows up in the next one, a copy of a project starts with no
mapping, and in a multicentre study each site has its own project with the same
case report form, sometimes translated. Mapping the same thing twice is
expensive and, above all, produces different mappings for the same data.

This spec provides two ways for the mapping to travel:

1. **With the instrument**, as `@OMOP-…` action tags in the annotation of each
   field of the data dictionary. The module never writes to the dictionary
   (principle 11): it generates an annotated dictionary that a person uploads in
   the Designer. From then on the mapping travels in the project XML, in its
   copies and in the Shared Library.
2. **Between projects**, by importing another project's `MAPEO.json`.

In both cases, what arrives is a **proposal** (principle 2): the fact that
someone else wrote it approves nothing here. And whatever is already approved
here is left untouched (principle 4).

## User stories

- **US-015-1.** As a curator, I want to download the data dictionary with the
  approved mapping written into the annotations, so that I can upload it in the
  Designer and the mapping travels with the instrument.
- **US-015-2.** As the curator of a project that reuses an annotated instrument,
  I want to see the annotation as a proposal and adopt it in one go with "Use
  @OMOP annotations", so that I review and approve instead of mapping.
- **US-015-3.** As the curator of the Madrid site of a multicentre study, I want
  to import the London site's `MAPEO.json` even though the option labels are
  translated, and to be asked to confirm that each code means the same thing.
- **US-015-4.** As the project owner, I want no annotation or import to approve
  anything on its own or to overwrite what we have already approved.

## Functional requirements

- **FR-015-01** — `MappingAnnotation::parse($annotation)` MUST read the tags `@OMOP-DOMAIN`, `-CONCEPT` (`"id | name"`), `-STRATEGY`, `-VALUES`, `-DATE`, `-ENDDATE`, `-UNIT` and `-EXCLUDE`, case-insensitively, with the value in double quotes, single quotes or unquoted, mixed with other tags or text; it MUST return `['spec' => …, 'values' => …, 'exclude' => bool]` with the domain in its canonical form, the strategy in lower case, `concept_id` as an integer or `null` if it is not a number, and `date_field = null` when `@OMOP-DATE` is empty ("use the default date"); `null` if there is no `@OMOP` tag at all.
- **FR-015-02** — `@OMOP-VALUES="code=id | name; …"` MUST give, per option, `raw_value`, `emit`, `concept_id` and `concept_name`; `code=-` means "this option generates no row" (`emit = false`); malformed pairs are ignored.
- **FR-015-03** — `MappingAnnotation::render($spec, $values, $excluded)` MUST write the tags of a mapping, one per line, so that `parse(render(x))` returns `x`: only `@OMOP-EXCLUDE` if the field is excluded; `VALUES` only if the strategy uses the options; double quotes and `;` in the text replaced by `'` and `,` so as not to break the tag.
- **FR-015-04** — `MappingAnnotation::merge($annotation, $tags)` MUST replace all the `@OMOP` tags of an annotation with the new ones while keeping everything else (`@HIDDEN`, `@DEFAULT`, team notes) and without leaving extra blank lines; it is idempotent.
- **FR-015-05** — The module's `annotation($field)` MUST return the `@OMOP` annotation read from the project's data dictionary (or `null`), without saving anything to the mapping.
- **FR-015-06** — `MappingStore::adoptAnnotation($field, $parsed, $choices, $dictionary)` MUST take the annotation as a proposal: state `proposed` (or `excluded` with `@OMOP-EXCLUDE`), `edited = true`, `source = 'annotation'`, and the options with this project's labels; it MUST discard invalid domains and strategies, dates that do not exist in this dictionary and options the field does not have; and it MUST NOT touch a field approved here (it returns `false`).
- **FR-015-07** — The editor MUST show the annotation of untouched fields as a proposal, with the `@OMOP` badge; adopt it on the first save of the field or of one of its options; count the annotated fields; and offer "Use @OMOP annotations", which after confirmation adopts in bulk the untouched annotated fields and records how many in the log. `[manual]`
- **FR-015-08** — `MappingDocument::annotatedDictionaryCsv()` MUST generate the project's data dictionary in the Designer upload format (UTF-8 BOM, the 18 REDCap headers, every field) with the `@OMOP` tags merged into the annotation of approved or excluded fields and the annotation left intact for the rest; it goes into the ZIP as `DICCIONARIO_anotado.csv` and can be downloaded from the export page ("Dictionary with @OMOP").
- **FR-015-09** — The module MUST NEVER write to the project's data dictionary: generating the annotated dictionary or the ZIP neither changes the dictionary nor copies it into the settings; it only changes if a person uploads the CSV in the Designer.
- **FR-015-10** — `MappingStore::importMapping($doc, $profile, $dictionary)` MUST import from another project's `MAPEO.json` only the fields that exist here (`missing`) with the same type (`type`) and the same option **codes** (`choices`), even if their labels differ; a field without a valid domain in the source is not imported (`no_mapping`). It copies domain, concept, strategy, unit, notes and options, and returns `['imported' => [...], 'skipped' => [field => reason]]`.
- **FR-015-11** — Nothing imported MUST end up approved: imported fields arrive as `proposed`, with `source = 'import'` and no approval signature; a field approved here is not touched (`approved_here`); start and end dates are only copied if the field exists here; the import is recorded in the log with the counts and the source project.
- **FR-015-12** — Exclusions MUST be imported too: a field excluded in the source (with the same name, type and codes) arrives excluded, with `source = 'import'`, even if it has no domain.
- **FR-015-13** — If the labels of any option differ (compared after normalisation), the field MUST store their codes in `import_diff` and the advisor MUST warn with `import_labels_differ` (`warn`, requires confirmation) until someone approves it; on approval, `import_diff` is removed and the warning disappears.
- **FR-015-14** — The editor MUST offer "Import mapping" (a `MAPEO.json`), reject a file that is not a `MAPEO.json` from the module with `ed_import_bad`, and report how many fields were imported as proposals and which were not, with their reason. `[manual]`
- **FR-015-15** — The import texts MUST describe the actual rule in both languages: `ed_import_title` says that the same name, the same type and the same answer **codes** are required (not "the same options"), and `ed_import_skipped` explains every reason `importMapping()` can return: `missing`, `type`, `choices`, `approved_here` and `no_mapping` (the field has no mapping in the source project).
- **FR-015-16** — A malformed entry in the `MAPEO.json` (a field that is not an object, or an option without a code) MUST NOT interrupt the import: it is skipped with the reason `invalid`, which `ed_import_skipped` explains in both languages, and the remaining fields are imported normally.

## Non-functional requirements

- **NFR-015-01** — Readable and stable format: the tags can be written and
  reviewed by hand in the Designer, and the order of `render()` is fixed
  (DOMAIN, CONCEPT, STRATEGY, VALUES, DATE, ENDDATE, UNIT) so that dictionary
  diffs stay small.
- **NFR-015-02** — Bilingual: the same translated instrument is imported by
  codes; no module text depends on the language of the labels.

## Acceptance criteria

```gherkin
Scenario: the mapping travels with the instrument
  Given the field "Heart rate (bpm)" with the annotation @HIDDEN and @OMOP-CONCEPT="3027018 | Heart rate"
  When the curator clicks "Use @OMOP annotations"
  Then the field is proposed with 3027018 and @HIDDEN is still in the dictionary
  And it is only exported after someone approves it

Scenario: annotated dictionary
  Given the weight approved with 3025315 "Body weight"
  When the curator downloads "Dictionary with @OMOP"
  Then the weight annotation carries @OMOP-CONCEPT="3025315 | Body weight"
  And the project's dictionary has not changed

Scenario: translated multicentre study
  Given London's MAPEO.json with "Sex: 1, Male | 2, Female" approved
  And the Madrid project with "Sexo: 1, Hombre | 2, Mujer"
  When Madrid imports the MAPEO.json
  Then sex arrives as proposed with the import_labels_differ warning
  And once it is approved, the warning disappears

Scenario: what is approved here wins
  Given the weight approved here with "Body height"
  When a MAPEO.json with the weight as "Body weight" is imported
  Then the weight is still approved with "Body height" and is listed as approved_here
```

## Out of scope

- Writing to the dictionary through the REDCap API (principle 11).
- Merging decisions field by field when they differ (a field is either imported
  or not).
- Importing between fields with different names or different option codes:
  without a correspondence between codes, copying the concept would be a lie.
- Bulk-approving what was imported: that is "Approve what is ready" (spec 007).

## Manual verification

**FR-015-07.** In the development test project, upload in the Designer a
dictionary with `@OMOP-DOMAIN=Measurement @OMOP-CONCEPT="3027018 | Heart rate"`
on one field. In the editor, the header says "1 field(s) carry their mapping in
the dictionary (@OMOP).", the row carries the `@OMOP` badge and shows 3027018 as
a proposal. "Use @OMOP annotations" asks for confirmation ("Nothing is
approved"), and afterwards the field is *proposed* with "from annotation"; the
module log has "@OMOP annotations adopted as proposal" with `count = 1`.
Changing something in an untouched annotated field adopts the annotation first.

**FR-015-14.** In the editor of a second project with the same instrument,
"Import mapping" with the first project's `MAPEO.json`: the notice says "N
field(s) imported as proposals" and lists the fields not imported with their
reason. With an arbitrary JSON (`{"a":1}`) the message "The file is not a
MAPEO.json from this module." appears.

## Amendments

| Date | Requirement | Change | Prompted by |
|---|---|---|---|
| 2026-10 | FR-015-10 | The import required the same option labels; it now requires the same **codes**. Between the London and Madrid sites of the stroke study no categorical field was imported (anomaly 2). | [018](../018-multiproject-validation/spec.md) |
| 2026-10 | FR-015-12 (new) | Exclusions are imported even if the field has no domain. | [018](../018-multiproject-validation/spec.md) |
| 2026-10 | FR-015-13 (new) | Different labels with the same codes: `import_diff` and the `import_labels_differ` warning until approval. | [018](../018-multiproject-validation/spec.md) |
