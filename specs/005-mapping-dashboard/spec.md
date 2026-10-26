# Spec 005 — Mapping dashboard

| | |
|---|---|
| **Status** | Implemented |
| **Depends on** | 004 |
| **Tests** | `tests/specs/005_mapping_dashboard.test.php` |

## Context

With spec 004 the module already classifies every field of the data
dictionary: it proposes an OMOP domain, a CDM table, a confidence and a reason,
and it knows which fields are free text or identifiers. But that proposal only
exists in memory: nobody sees it.

This spec adds the module's first page, `pages/dashboard.php`: a read-only view
of the classifier's proposal. It serves the first conversation with the study
team —how many fields there are, how many have a target, how many will need
manual coding, how many are identifiers— and lets the proposal be taken to a
spreadsheet or another tool (CSV and JSON).

The dashboard shows **the proposal**, not the decisions: mapping states,
editing and approval belong to the editor (spec 007), on top of the store of
spec 006. That is why the dashboard writes nothing.

## User stories

- **US-005-1.** As a curator, I want to see at a glance how many fields the
  project has, how many have an OMOP target and how many are doubtful, so I can
  estimate the curation work before starting.
- **US-005-2.** As a principal investigator, I want to see field by field the
  proposed target and the reason, so I can discuss the mapping with the team
  without opening the editor.
- **US-005-3.** As a data manager, I want to download the proposal as CSV and as
  JSON, so I can review it in a spreadsheet or compare it between versions of
  the data dictionary.
- **US-005-4.** As a data protection officer, I want to see which fields are
  flagged as identifiers, so I can check that they will not end up in the CDM.

## Functional requirements

- **FR-005-01** — The dashboard MUST show the title `dash_title`, the CDM version taken from the `cdm-version` setting (default `5.4`) and the introduction `dash_intro`, which warns that the proposal is a draft.
- **FR-005-02** — The dashboard MUST show six cards: *Fields* (dictionary total), *With a target* (total minus the `NotMapped` ones), *Low confidence* (`confidence = low`), *Manual coding* (`mappable = manual`), *Identifiers* (`is_identifier`) and, in a longitudinal project, *Events* with the number of events or, if it is not longitudinal, *Single event* with a 1.
- **FR-005-03** — If the project has repeating instruments or events, the dashboard MUST warn with `repeating_note` and their number; if it has none, the notice is not shown.
- **FR-005-04** — `profileSummary($profile)` MUST count the fields per proposed domain (`suggested_domain`) from highest to lowest, and the dashboard MUST render one label per domain with its count; the module's own codes `NotMapped` and `DateAnchor` are translated (`domain_NotMapped`, `domain_DateAnchor`) and OMOP domains are shown as they are.
- **FR-005-05** — The dashboard MUST show a table with one row per field: name, label (truncated to 90 characters with «…»), instrument, type and validation, value kind with the detected unit and the number of options, proposed target with its CDM table, confidence and reason.
- **FR-005-06** — The table MUST mark manual-coding fields with the badge `badge_manual` (and its explanation `badge_manual_title`), identifiers with `badge_phi`, and the confidence with a coloured badge: high `badge-success`, medium `badge-warning`, low `badge-secondary`.
- **FR-005-07** — Every value that comes from the data dictionary (name, label, instrument, type, validation, unit, reason) MUST be rendered escaped with `htmlspecialchars`.
- **FR-005-08** — The dashboard MUST show the classifier's proposal even if the saved mapping says otherwise, and MUST NOT write anything: opening it or downloading creates no mapping and changes no setting (it only leaves the log entry of FR-005-11).
- **FR-005-09** — With `download=csv`, the dashboard MUST return a CSV with a UTF-8 BOM, the 14 columns `field_name, form_name, field_type, validation, field_label, is_identifier, value_kind, unit_hint, mappable, choice_count, suggested_domain, suggested_table, confidence, reason` in that order, one row per field (`is_identifier` = `y` or empty), with `Content-Type: text/csv; charset=utf-8` and file name `omop_propuesta_pid<pid>_<YYYYMMDD>.csv`; the dashboard links the download with the `btn_csv` button.
- **FR-005-10** — With `download=json`, the dashboard MUST return readable JSON with `generated` (ISO 8601), `project_id`, `cdm_version`, `structure` (the one from `getProjectStructure()`) and `proposal` (the full profile of each field, options included), with file name `omop_propuesta_pid<pid>_<YYYYMMDD>.json`; the dashboard links the download with the `btn_json` button.
- **FR-005-11** — Every download MUST be recorded in the module log as `Mapping proposal download (CSV)` or `(JSON)`, with the module version.
- **FR-005-12** — The filter box MUST hide, in the browser and without reloading, the table rows whose text does not contain what was typed, case-insensitively. `[manual]`
- **FR-005-13** — Downloads MUST be resolved before rendering anything: the response carries neither the REDCap header nor footer nor any HTML. An unknown `download` value renders the dashboard.

## Non-functional requirements

- **NFR-005-01** — Bilingual: every text of the dashboard comes from `lang/*.ini` with the same keys in Spanish and English.
- **NFR-005-02** — The dashboard MUST work in a freshly installed project: no settings, no saved mapping and no vocabulary, with no PHP errors or warnings.

## Acceptance criteria

```gherkin
Scenario: cards of the test project
  Given the 18-field test project, not longitudinal
  When the dashboard is opened
  Then the cards say Fields 18, With a target 17, Low confidence 2,
    Manual coding 1, Identifiers 0 and Single event 1

Scenario: the dashboard shows the proposal, not the decision
  Given the field "weight" proposed as Measurement
  And saved in the editor as Observation
  When the dashboard is opened
  Then the row of "weight" says Measurement
  And the breakdown still counts 4 Measurement

Scenario: CSV download
  Given the test project
  When download=csv is requested
  Then the response starts with the UTF-8 BOM
  And the first line is the 14 columns in order
  And there are 18 data rows, with no HTML
  And the module log says "Mapping proposal download (CSV)"

Scenario: an identifier
  Given a field "phone" flagged as an identifier in REDCap
  When the dashboard is opened
  Then the Identifiers card says 1
  And its row carries the PHI badge
```

## Out of scope

- Mapping states (proposed, approved, excluded…) and their editing: specs 006 and 007.
- Mapping consistency warnings: spec 008.
- Who can see the dashboard (module links): spec 012.
- Exporting to XLSX or other formats: it would add a dependency.

## Manual verification

**FR-005-12 (filter).** In the development project, open *OMOP-CDM — mapping
dashboard*. Type `GLU` in the filter box: only the row of `glu` remains (the
comparison is case-insensitive). Type `measurement`: the rows whose target or
reason contain the word remain. Clear the text: all rows come back. The page
never reloads.

Complementary visual check: the six cards are shown in a row that wraps on
narrow screens, and the confidence badges are green, amber and grey.

## Amendments

| Date | Requirement | Change | Motivated by |
|---|---|---|---|
| — | — | Initial version | — |
