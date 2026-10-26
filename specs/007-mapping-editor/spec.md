# Spec 007 — Mapping editor

| | |
|---|---|
| **Status** | Implemented |
| **Depends on** | 006, 008 (warnings and approval with confirmation: FR-007-03, FR-007-06, FR-007-12) |
| **Tests** | `tests/specs/007_mapping_editor.test.php` |

## Context

Spec 006 stores the mapping and sets its rules (writing does not approve,
modifying withdraws the approval, what is stored prevails, drift). What is
missing is the screen where a person does that work field by field:
`pages/editor.php`.

The editor shows one row per field with what the module proposes already in
place —domain, value strategy, reference date, unit— so that curating means
confirming rather than filling in. Every change is saved on its own, via AJAX,
against a whitelist of actions and with a CSRF token. Approving consults the
advisor (spec 008): what blocks is not approved and what warns requires
confirmation. Each row carries a button that opens Athena with the search
already built from the label, because the OHDSI vocabulary is in English and
most projects are not.

Bulk actions, going back to the proposal and resetting the whole mapping with
a backup complete the flow. What the editor does not ask is as important as
what it asks: a field that does not reach the CDM is not asked for a concept.

## User stories

- **US-007-1.** As a curator, I want to see the proposal already preselected
  and have what I see be saved, so I confirm instead of filling in.
- **US-007-2.** As a curator, I want to search for the concept in Athena with
  one click, with the search translated and filtered by domain, so I do not
  have to write it myself in English.
- **US-007-3.** As a curator, I want to approve with one click what is fine
  and be asked for confirmation when something is doubtful, so I do not
  approve a misleading mapping without noticing.
- **US-007-4.** As a data manager, I want to exclude in one go what has no
  target and approve in one go what is already ready, without touching what is
  signed, so I finish in hours rather than days.
- **US-007-5.** As a principal investigator without design rights, I want to
  see the mapping without being able to change it, so I can review it.
- **US-007-6.** As a curator, I want to be able to reset the whole mapping and
  undo it if I make a mistake, so I can start from scratch without fear.

## Functional requirements

- **FR-007-01** — If `userCanEditMapping()` is false, the editor MUST be shown read-only (notice `ed_readonly`, every control `disabled`, no state buttons) and the server MUST reject any action with HTTP 403 and `ed_err_rights`.
- **FR-007-02** — Every POST action MUST carry the session's CSRF token (`$_SESSION['omop_csrf']`, compared with `hash_equals`) or it is rejected with 403 and `ed_err_csrf`; an action outside the whitelist is rejected with 400 and `ed_err_unknown_action`, a field that is not in the data dictionary with 400 and `ed_err_unknown_field`, and a domain outside `validDomains()` with 400 and `ed_err_bad_domain`. Responses are always JSON (`Content-Type: application/json`, `X-Omop-Response: 1`, discarding any previous output) and a server failure responds 500 with `error` and `where` (file:line) and is logged as `Editor error`.
- **FR-007-03** — The editor MUST render one row per data dictionary field with `data-field` and `data-state` (the effective state from `mappingRows()`, including `review` and `config`), the name and the label, the manual-coding and identifier badges, the domain selector with the stored or proposed domain, the Approve, Exclude and Reopen buttons, the advisor's warnings for that field and, if there is drift, its reason (`ed_drift_dictionary` or `ed_drift_date_field`).
- **FR-007-04** — The `save-field` action MUST pass to `MappingStore::updateField()` only the keys of its whitelist (`domain`, `concept_id`, `concept_name`, `value_strategy`, `date_field`, `end_date_field`, `unit_source_value`, `notes`, `state`) and respond with `ok`, `spec` (with the store's error flags, such as `_bad_concept`), `error`, `warnings` and `counts`.
- **FR-007-05** — On the first save of a non-edited field, `save-field` MUST also store what the editor shows preselected and does not arrive in the request: the (proposed) domain, the strategy (`DictionaryProfiler::suggestedStrategy()` with the incoming or the proposed domain) and the date (`suggestedDateField()`); an empty date sent on purpose is respected. Once edited, the proposal is not applied again.
- **FR-007-06** — Approving (`save-field` with `state = approved`) MUST first save the changes and consult the advisor: with a `block` warning it responds `blocked = true` and does not approve; with `warn` warnings and without `confirmed` it responds `confirm = true` with the warnings and does not approve; with `confirmed = 1` it approves and logs `Approved with warnings` with the codes; if the store does not see it as ready it responds `error` with the text `ed_err_<reason>`. Every response carries `counts`.
- **FR-007-07** — For an exportable categorical field the editor MUST offer the three strategies, preselecting the stored or the suggested one; with "one concept per option" it hides the single concept and shows the options, with "value per option" it shows both and the note `ed_value_concept_note`. Each option carries its "generates a row" mark (the stored one or that of `blankValueSpec()`: the "No" of a yes/no unticked), its concept and its name, and the `save-value` action stores `emit`, `concept_id`, `concept_name` and `source_value` of one option and responds with `value`, `changed`, `state` and `counts`.
- **FR-007-08** — The editor MUST send the list of candidate dates only once (the fields with date validation, with their instrument), show on each row the stored date or, if the field is not edited, the suggested one with the note `ed_date_suggested`, offer the end date only in Condition, Drug, Procedure, Device and Visit, and preload the stored unit or the one detected in the label (`unit_hint`).
- **FR-007-09** — A field that does not reach the CDM —in state `config` (with its role, `ed_role_<role>`) or with domain `NotMapped` or `DateAnchor` (with the classifier's reason)— MUST NOT show concept, strategy, date or unit controls; the domain selector remains available.
- **FR-007-10** — Each field and each option MUST carry a button that opens Athena with `athenaUrl($query, athenaDomain($domain))` (`standardConcept=Standard`, `invalidReason=Valid`, `page=1`, `pageSize=15`), and the field a second button filtered with `athenaVocabulary($domain)` (LOINC in Measurement, RxNorm in Drug, Gender in Person, SNOMED in Condition, Procedure, Observation, Device, Specimen, Death and Visit). Person is searched in the Gender domain, Death in Condition and the module's own codes without a domain; the option of a yes/no searches for the question; with "value per option" the answer is searched in `Meas Value`; with a concept, a link opens it in Athena to verify it.
- **FR-007-11** — `athenaQuery($label, $fieldName, $unit)` MUST build the search by removing the final parenthesis (the unit), converting to American spelling, removing questionnaire wording, translating with the glossary (long phrases before short ones), then removing protocol qualifiers, discarding stopwords, loose units and numbers, limiting itself to five words and appending at the end the LOINC property from `loincProperty($unit)` (`mass volume`, `moles volume`, `units volume`, `enzymatic activity volume`, `number volume`, `fraction`); if nothing is left, it searches for the field name.
- **FR-007-12** — `bulk-preview` MUST return, without changing anything, the fields a bulk action would affect and `bulk` apply it, only on fields in `proposed`: `exclude-notmapped` (domain NotMapped), `exclude-manual` (free text), `exclude-pending` (everything pending) and `approve-ready` (edited, with an exportable domain, ready according to `readyToApprove()` and without `block` or `warn` warnings: their options are materialised and they are approved with a fingerprint). `reset-all-preview` and `reset-all` MUST send back to the proposal the non-approved fields whose stored domain differs from the proposed one ("current → proposed"), and `reset-proposal` a single one; the Proposal button only appears if the stored domain differs.
- **FR-007-13** — `reset-everything-preview` MUST return what would be lost (`resetImpact()`), and `reset-everything` MUST require on the server `confirmed = 1` and the word `ed_reset_all_word` of the current language ("BORRAR", "DELETE"), ignoring case and spaces, or it responds 400 with `ed_reset_all_unconfirmed`. After resetting, the editor offers to undo (`ed_restore_available`) and `restore-backup` restores the copy, or responds 400 with `ed_restore_none` if there is none.
- **FR-007-14** — In the browser, the editor MUST save each control on change (`change` event, not `blur`) in a queue that keeps the order; when approving, force the `blur` of the active control and send all the row's controls; ask for confirmation with the `warn` warnings and resend with `confirmed`; explain blocks; ask before each bulk action showing the list (12 fields and "N more"); reset everything with two confirmations (figures and word); and explain a response that is not JSON (expired session, HTTP error). `[manual]`
- **FR-007-15** — The editor MUST filter the rows by state (all, proposed, approved, excluded, to review, configuration) and by text, and show a progress bar = approved / (total − excluded − configuration) with the count `ed_counts` (approved, total, pending, excluded, to review and from configuration), updated after each response. `[manual]`

## Non-functional requirements

- **NFR-007-01** — Page size: the common controls (the 13 domains, the candidate dates, the `concept_id` help) MUST be sent only once in the `OMOP` object and filled in in the browser, not repeated on every row.
- **NFR-007-02** — Bilingual: every text of the editor comes from `lang/*.ini` with the same keys in Spanish and English; messages for JavaScript are passed as plain text (`ttText`) and rendered with `textContent`.

## Acceptance criteria

```gherkin
Scenario: what you see is what is saved
  Given "fever", an untouched yes/no, proposed as Condition
  When the curator writes a note in the row
  Then it is saved with domain Condition, per-option strategy and date visit_date
  And the field is still "proposed"

Scenario: approving with warnings
  Given "weight" with concept 3025315 and no unit
  When the curator clicks Approve
  Then the server responds confirm with the warning measurement_no_unit
  And the field is still "proposed"
  When she confirms
  Then the field is "approved" and the log says "Approved with warnings"

Scenario: bulk action that does not touch what is signed
  Given the curated project with 9 approved fields and 4 configuration fields
  When everything pending is excluded
  Then 5 fields are excluded and 9 remain approved and 4 configuration fields

Scenario: resetting requires the word on the server too
  Given the curated project
  When reset-everything arrives with confirmed=1 and the word "BORRAR" in English
  Then it responds 400 and nothing is erased
  When it arrives with " delete "
  Then everything is erased and the editor offers to undo

Scenario: read-only
  Given a user without design rights
  When they open the editor
  Then they see the read-only notice and the controls disabled
  And any save-field they send gets a 403
```

## Out of scope

- Who can edit (`userCanEditMapping()`, module links) and the details of the
  read-only mode from the point of view of permissions: spec 012.
- The advisor's logic and the list of warnings: spec 008.
- Vocabulary suggestions (`suggest` action, magic wand) and the concept name
  filled in when pasting the number: spec 014.
- `@OMOP` annotations (badge, adoption on first save, bulk action
  `adopt-annotations`) and importing a `MAPEO.json` (`import-mapping` action):
  spec 015. Both actions are already in this spec's whitelist.

## Manual verification

**FR-007-14 (interaction).** In the development project, with design rights,
open the editor:

1. Type `3025315` in the concept of `weight` and leave the field: the green
   saved mark appears. Enter and leave without changing anything: it does not
   appear.
2. Type a concept and immediately click Approve: in the browser's Network tab
   the concept's `save-field` goes out before the approval's.
3. On `weight` without a unit, click Approve: a `confirm()` shows the unit
   warning; Cancel leaves it "proposed"; OK leaves it "approved".
4. Set as date a field that does not exist (editing the HTML) and approve: an
   `alert()` explains the block.
5. Click *Exclude everything pending*: a `confirm()` shows up to 12 fields and
   "...and N more". Cancel changes nothing.
6. *Reset the whole mapping*: first the figures, then a `prompt()` that asks
   for `DELETE` (or `BORRAR` in Spanish); typing something else warns and
   erases nothing.
7. Log out of REDCap in another tab and change a control: the notice says the
   session has expired.

**FR-007-15 (filters and progress).** Choose "configuration" in the state
filter: only `record_id`, `dob`, `death_date` and `site` remain. Type `glu`:
one row remains. Approve `weight`: the bar advances and the count says
"1 of 18 fields approved · 13 pending · 0 excluded · 0 to review · 4 from
configuration". (The count template and its `{5}` substitution also have an
automatic test.) With 14 proposed, 4 configuration and none excluded, the bar
reaches 100 % when the 14 are approved.

## Amendments

| Date | Requirement | Change | Motivated by |
|---|---|---|---|
| — | — | Initial version | — |
