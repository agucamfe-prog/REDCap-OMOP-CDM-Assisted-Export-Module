# Specifications

The module was built spec by spec, in this order. Each folder contains:

| File | Content | Question it answers |
|---|---|---|
| `spec.md` | User stories, `FR-NNN-XX` requirements, acceptance criteria | What and why? |
| `plan.md` | Technical design: files, data structures, decisions, test strategy | How? |
| `tasks.md` | 10–15 ordered tasks, test first, for a junior developer | In which steps? |

The tests of each spec live in `tests/specs/NNN_<name>.test.php` and cite the
requirements they check. Templates are in `_templates/`.

## Order and dependencies

Phases: 1 foundations (001–004), 2 curation (005–008), 3 transformation and
export (009–012), 4 reuse and safeguards (013–017), 5 validation (018).

| Spec | Name | Depends on | Main code | Tests |
|---|---|---|---|---|
| 001 | [Module skeleton and internationalisation](001-module-skeleton/spec.md) | — | `config.json`, `OmopCdmExportModule.php`, `lang/` | `001_module_skeleton` |
| 002 | [OMOP CDM v5.4 model](002-cdm-model/spec.md) | 001 | `classes/CdmModel.php`, `resources/` | `002_cdm_model` |
| 003 | [Data dictionary profiling](003-dictionary-profiling/spec.md) | 001 | `classes/DictionaryProfiler.php` (profile) | `003_dictionary_profiling` |
| 004 | [Bilingual domain classifier](004-domain-classifier/spec.md) | 002, 003 | `classes/DictionaryProfiler.php` (rules), `classes/TextNormalizer.php` | `004_domain_classifier` |
| 005 | [Mapping dashboard](005-mapping-dashboard/spec.md) | 004 | `pages/dashboard.php` | `005_mapping_dashboard` |
| 006 | [Mapping store and states](006-mapping-store/spec.md) | 004 | `classes/MappingStore.php` | `006_mapping_store` |
| 007 | [Mapping editor](007-mapping-editor/spec.md) | 006, 008 | `pages/editor.php` | `007_mapping_editor` |
| 008 | [Mapping advisor](008-mapping-advisor/spec.md) | 006 | `classes/MappingAdvisor.php` | `008_mapping_advisor` |
| 009 | [Fact transformation](009-fact-transformation/spec.md) | 002, 006 | `classes/Transformer.php` | `009_fact_transformation` |
| 010 | [Longitudinal projects, visits and entities](010-longitudinal-visits/spec.md) | 009 | `classes/Transformer.php` | `010_longitudinal_visits` |
| 011 | [Export, report and mapping document](011-export-documentation/spec.md) | 009 | `classes/CsvExporter.php`, `classes/MappingDocument.php`, `pages/export.php` | `011_export_documentation` |
| 012 | [Permissions and data access groups](012-permissions-dags/spec.md) | 007, 011 | `OmopCdmExportModule.php`, pages | `012_permissions_dags` |
| 013 | [Stable identifiers](013-stable-identifiers/spec.md) | 009 | `classes/IdRegistry.php` | `013_stable_identifiers` |
| 014 | [Vocabulary: validation and suggestions](014-vocabulary/spec.md) | 008 | `classes/Vocabulary.php`, `pages/vocabulary.php` | `014_vocabulary` |
| 015 | [Reuse: @OMOP annotations and import](015-mapping-reuse/spec.md) | 006, 011 | `classes/MappingAnnotation.php`, `classes/MappingStore.php` | `015_mapping_reuse` |
| 016 | [Pseudonymisation](016-pseudonymization/spec.md) | 009 | `classes/Pseudonymizer.php` | `016_pseudonymization` |
| 017 | [Load kit and quality control](017-load-kit-quality/spec.md) | 011 | `classes/CsvExporter.php`, `resources/ddl/` | `017_load_kit_quality` |
| 018 | [Validation with realistic projects](018-multiproject-validation/spec.md) | all | `tests/fixtures/`, `tests/eval/`, amendments to 004, 006, 012, 014, 015 | `018_multiproject_validation` |

```
001 ─┬─ 002 ───────────────┐
     └─ 003 ── 004 ─┬─ 005  │
                    └─ 006 ─┼─ 008 ─┬─ 014
                            │       └─ 007 ── 012   (007 depends on 006 and 008)
                            └─ 009 ─┬─ 010
                                    ├─ 011 ─┬─ 015
                                    │       └─ 017
                                    ├─ 013
                                    └─ 016
                                              018 (all)
```

## Conventions

- **Requirements:** `FR-NNN-XX` (functional), `NFR-NNN-XX` (non-functional).
  Requirements without an automated test carry `[manual]` and their procedure
  in the *Manual verification* section of the spec.
- **Tasks:** `TNNN-XX`. `[T]` writes a test; `[I]` implements; `[R]`
  refactors or documents. `[P]` = can be done in parallel with the previous one.
- **Amendments:** if a later spec changes an earlier one, the earlier spec gets
  an *Amendments* section with the date, the affected requirement and the spec
  that motivated it. History is not rewritten: it is appended.
- **Status:** each `spec.md` states `Draft`, `Approved`, `Implemented` or
  `Amended`.
