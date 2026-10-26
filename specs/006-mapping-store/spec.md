# Spec 006 — Mapping store and states

| | |
|---|---|
| **Status** | Amended |
| **Depends on** | 004 |
| **Tests** | `tests/specs/006_mapping_store.test.php` |

## Context

Spec 004 proposes a domain for each field, but a proposal is not a decision.
To export, we need to store, field by field, what a person has decided
—domain, concept, value strategy, dates, unit, which options generate a row—
and what state that decision is in.

This spec builds `classes/MappingStore.php`, the only place through which the
mapping is read and written, and the module methods that combine what is
stored with the proposal (`mappingRows()`, `mappingCounts()`, `configRole()`).
Here principles 1 to 4 of the constitution are fixed in code: only what is
approved counts, writing does not approve, modifying withdraws the approval,
and what is stored prevails. Also the detection of **drift**: if the REDCap
data dictionary changes after a field is approved, the approval no longer
holds on its own.

The mapping lives in a module project setting: installing the module creates
no tables and the REDCap project is not touched (principle 11). The editor
(007), the advisor (008) and the transformer (009) are built on top of this
store.

## User stories

- **US-006-1.** As a curator, I want what I write to be saved without that
  approving it, so I can prepare the mapping calmly and approve only when I
  have reviewed it.
- **US-006-2.** As a principal investigator, I want every approval to carry who
  and when, and to be lost if someone changes the content, so that the
  signature always corresponds to what is exported.
- **US-006-3.** As a data manager, I want a field to go back to review if
  someone edits the REDCap data dictionary after approving it, so we do not
  export a mapping that no longer describes the field.
- **US-006-4.** As a curator, I want to be able to go back to the proposal,
  reset everything and undo the reset, so I can fix mistakes without losing
  hours of work to one click.
- **US-006-5.** As a curator, I want the fields already used by the
  configuration (Record ID, birth, death, care site, provider) not to count as
  pending, so that progress reflects the real work.

## Functional requirements

- **FR-006-01** — The mapping MUST be stored as JSON in the project setting `omop-mapping-json`, with `meta` (`schema_version` = 3, `project_id`, `created`, `updated`, `updated_by`), `fields` and `values`. An empty setting, one that is not JSON or one without `fields` is read as an empty mapping, without error; a setting that arrives already decoded is accepted as is. An entry of `fields` or `values` that is not an object is ignored without error and logged once per read (`Corrupt entries ignored when reading the mapping`, with the entries and their count).
- **FR-006-02** — The contract of a field MUST be `blankSpec()`: `state` (`proposed`), `domain`, `concept_id`, `concept_name`, `value_strategy`, `date_field`, `date_default` (false), `end_date_field`, `unit_source_value`, `notes` (''), `edited` (false), `dict_hash`, `approved_by`, `approved_at`, `updated_by`, `updated_at`. That of an option, `blankValueSpec()`: `raw_value`, `source_value`, `emit`, `concept_id`, `concept_name`, where the negative option of a binary field (`DictionaryProfiler::isNegativeChoice`) does not emit by default and any other does.
- **FR-006-03** — The storable states MUST be `proposed`, `approved`, `excluded` and `review`, and the strategies `per_choice`, `value_concept` and `single_concept` (`usesOptions()` is true for the first two). A state or strategy outside the list is ignored; `config` cannot be stored.
- **FR-006-04** — Writing MUST NOT approve: `updateField()` and `updateValue()` without `state = approved` leave the field in the state it had (`proposed` if new), without a signature, and `isApproved()` is false.
- **FR-006-05** — Approving MUST be explicit (`state = approved` in `updateField()`) and leave a signature: `approved_by` (the user, or `system`), `approved_at` (`YYYY-MM-DD hh:mm:ss`) and `dict_hash` = `dictHash()` of the field's metadata; when approving a categorical field that decides per option, the untouched options are also stored with their default value (`materialiseValues()`).
- **FR-006-06** — `readyToApprove($field, $choices, $dictMeta)` MUST prevent approving, returning the reason, if: there is no domain (`no_domain`); the `concept_id` of the field or of an emitting option is malformed (`bad_concept_format`, `concept_negative`, with the value); the field's concept is missing, except in Death (`no_concept`); with "value per option" the question's concept is missing (`no_concept`); with options, none emits (`no_emitting_choice`); or some emitting option has no concept, except in Death (`choices_pending`, with the list). If it is not ready, `updateField()` does not approve and returns `_error`, `_pending` and `_bad_value`.
- **FR-006-07** — A real change in the content of an approved field (in its editable keys or in any of its options) MUST send it back to `proposed` and clear `approved_by` and `approved_at`; saving the same values withdraws nothing and `updateValue()` returns `changed = false`.
- **FR-006-08** — The store MUST validate what is written: only the editable keys (`domain`, `concept_id`, `concept_name`, `value_strategy`, `date_field`, `end_date_field`, `unit_source_value`, `notes`); a domain outside `CdmModel::validDomains()` is ignored; texts are trimmed and an empty string is stored as `null`; the `concept_id` is cleaned of spaces (also non-breaking ones, U+00A0, U+200B, U+FEFF…) and, if it is not a positive integer, it is not stored: the previous one is kept and `_bad_concept` and `_bad_kind` (`format` or `negative`) are set, which a valid or empty value clears. The same applies to an option's `concept_id`.
- **FR-006-09** — What is stored MUST prevail: any save sets `edited`; a field stored without the flag but with a domain, concept, date or strategy counts as edited; saving an empty `date_field` sets `date_default` ("deliberately use the default date") and choosing a date clears it; and `mappingRows()` returns what is stored even if the classifier proposes otherwise.
- **FR-006-10** — `mappingRows()` MUST return `review` (with `drifted` and `drift_reason`) for an approved field whose fingerprint no longer matches the data dictionary (`dictionary`: the type, the validation, the label without HTML or the options changed; not the field note), whose end-date field no longer exists (`dictionary`, detected by `MappingStore::hasDrifted()`) or whose reference date field no longer exists (`date_field`), without rewriting the store; a field in `review` does not count as approved. Approving it again updates the fingerprint.
- **FR-006-11** — `mappingRows()` MUST return the derived state `config` for the fields consumed by the configuration that are not approved, with their role (`configRole()`: `person_id` = Record ID, `birth` = birth date or year, `death_date`, `care_site`, `provider`); `config` is never stored, `mappingCounts()` counts it separately (not as `proposed`), and sex, race and ethnicity are not config fields because their options need a concept.
- **FR-006-12** — Every state change made by `updateField()` MUST be recorded in the module log as `Mapping state change` with `field`, `from_state`, `to_state` and `concept_id`; an edit that does not change the state is not logged.
- **FR-006-13** — `resetToProposal($field, $proposedDomain)` MUST restore the proposed domain, keep concept, unit, dates and notes, return the field to `proposed` without a signature, clear the bad-concept flags and log it (`Field reset to the classifier proposal`); if the domain already matches, nothing changes and nothing is logged (`changed = false`).
- **FR-006-14** — `reset()` MUST save a full copy in `omop-mapping-backup` (`saved_at`, `saved_by`, `mapping`), log `Mapping fully reset`, empty the mapping and return the `fields`, `approved` and `excluded` erased; `resetImpact()` says beforehand what would be lost (`fields`, `approved`, `excluded`, `concepts`); `backupInfo()` describes the copy; `restoreBackup()` restores it as is (with the original signatures), deletes the copy, logs it and returns the number of fields, or `null` if there is no copy.
- **FR-006-15** — `bulkState($fields, $state, $dictionary)` MUST apply a valid state to several fields and return how many changed (those already in that state do not count; an invalid state does nothing), sign and store the fingerprint when approving, and log `Bulk state change` with state, count and fields.

## Non-functional requirements

- **NFR-006-01** — The mapping setting MUST be read only once per request (`load()` caches) and the cache is updated on save; on save the module is notified (`invalidateMappingCache()`) so that derived data (rows, warnings) is recomputed.
- **NFR-006-02** — Read-only on the project: the store lives in the module settings and never writes to the REDCap data dictionary or data. Installing the module creates no tables.

## Acceptance criteria

```gherkin
Scenario: writing does not approve
  Given the field "weight" unmapped
  When its domain, concept, unit and date are saved
  Then its state is "proposed" and it has no signature

Scenario: entering and leaving a box does not withdraw the approval
  Given "fever" approved with "Yes" → 437663
  When the option "Yes" is saved again with the same values
  Then it is still approved and updateValue returns changed = false

Scenario: data dictionary drift
  Given "weight" approved with the label "Weight (kg)"
  When someone changes the label to "Weight (lb)" in the Designer
  Then mappingRows returns "review" with drift_reason "dictionary"
  And isApproved('weight') is false
  And in the store it is still saved as "approved"

Scenario: missing date
  Given "drug" approved with the date "med_start"
  When "med_start" disappears from the data dictionary
  Then "drug" goes to "review" with drift_reason "date_field"

Scenario: missing end date
  Given "drug" approved with the end date "med_end"
  When "med_end" disappears from the data dictionary
  Then "drug" goes to "review" and does not count as approved

Scenario: corrupt entry in the setting
  Given a mapping setting in which "weight" is the text "broken"
  When the mapping is read
  Then "weight" is treated as not stored, without error
  And the log says "Corrupt entries ignored when reading the mapping"

Scenario: configuration fields
  Given the test project with birth "dob", death date
    "death_date" and care site "site" configured
  When the counts are computed
  Then record_id, dob, death_date and site are in "config"
  And mappingCounts says config 4 and proposed 14 out of 18

Scenario: reset and undo
  Given the curated project, with 9 approved fields
  When the mapping is reset
  Then no field remains stored and a copy exists
  When the reset is undone
  Then "weight" is approved again with its original signature and the copy disappears
```

## Out of scope

- The interface that writes to the store (autosave, buttons, bulk actions with
  confirmation): spec 007.
- The warnings that decide whether a mapping is misleading: spec 008.
- Adopting `@OMOP` annotations and importing another project's mapping
  (`adoptAnnotation()`, `importMapping()`, `import_diff`): spec 015.
- Who can write (design rights): spec 012.

## Manual verification

No functional requirement is `[manual]`. Complementary check in the Docker
environment: approve a field in the editor, change its label in the REDCap
Designer and reload the editor: the row appears "to review" with the drift
warning, and in *External Modules → View logs* there is the state change with
user and field.

## Amendments

| Date | Requirement | Change | Motivated by |
|---|---|---|---|
| — | — | Initial version | — |
| 2026-10 | FR-006-11 (new) | The derived state `config` is added. The fields consumed by the configuration (Record ID, birth, death, care site, provider) stayed "proposed" forever: nobody approves them because they are not mapped, and they counted as pending. Now `mappingRows()` returns them as `config` unless someone approves them on purpose; the state is never stored, `mappingCounts()` counts it separately, it does not count as pending and the editor's progress bar subtracts it from the total, so it no longer prevents reaching 100 %. | 018 (validation with 10 projects) |
