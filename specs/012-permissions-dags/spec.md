# Spec 012 — Permissions and data access groups

| | |
|---|---|
| **Status** | Amended |
| **Depends on** | 007, 011 |
| **Tests** | `tests/specs/012_permissions_dags.test.php` |

## Context

With specs 007 and 011 the module already edits the mapping and generates the
export, but it does not distinguish who does it. In a real study the team is
varied: the curator decides the mapping, the data manager exports, the
principal investigator and the monitor review, and in a multicentre study each
site must only see its own participants.

REDCap already solves those permissions: project **design** rights, per-
instrument **data export** rights («Full data set», «De-identified», «Remove
identifiers», «No access») and **data access groups** (DAG). This spec makes
the module respect them instead of inventing its own (constitution, principle
12): editing the mapping decides *what* leaves the project and requires design
rights; generating the CDM is *taking away* participant data, with full dates
and Record ID, and additionally requires full export on every instrument from
which some approved field is taken.

The module's pages only contain metadata (the dictionary, which any member
already sees in the *Codebook*, and the mapping decisions), except for the
export. That is why any project member can see the dashboard, the editor in
read-only mode and the mapping document. The checks are always done on the
server: the interface only reflects what the server allows.

## User stories

- **US-012-1.** As a principal investigator without design rights, I want to
  open the dashboard, the editor in read-only mode and the mapping document so
  that I can review the decisions without being able to change them.
- **US-012-2.** As a data protection officer, I want only those who have full
  export on the instruments involved to be able to generate the CDM, so that
  the module is not a way around REDCap's rights.
- **US-012-3.** As a site coordinator in a multicentre study, I want to export
  only the participants of my DAG, and have the report say so, so that I do
  not take away the data of other sites.
- **US-012-4.** As a data manager without permission, I want the screen to tell
  me which right I am missing and on which instrument, so that I can request it.
- **US-012-5.** As a REDCap administrator, I want the management of the
  server's vocabulary to live only in the Control Center.

## Functional requirements

- **FR-012-01** — `userCanEditMapping()` MUST return true only if the user has design rights (the framework's `hasDesignRights()`); if the user cannot be obtained, it MUST use REDCap's global rights (`$GLOBALS['user_rights']['design']`) and, without them, deny.
- **FR-012-02** — The editor MUST reject on the server, with HTTP 403 and the message `ed_err_rights`, any POST action from a user without design rights, after validating the CSRF token and before running the action.
- **FR-012-03** — `exportBlockedBy()` MUST return `null` (can export) for a REDCap administrator and `'*design'` for a user without design rights, even with full export.
- **FR-012-04** — With design rights, `exportBlockedBy()` MUST require level 1 («Full data set») in the per-instrument rights (`data_export_instruments`, format `[form,level]`) of every instrument that contains some field whose values go out in the export —approved fields, their reference and end dates, the default date and the configuration fields (birth date or year, sex, race, ethnicity, death date, care site, provider)— and return the name of the first one that does not have it, in dictionary order; an instrument missing from the list counts as no access and one from which no data goes out is not required. *(Amendment 2026-10.)*
- **FR-012-05** — If the user has no per-instrument rights, `exportBlockedBy()` MUST use the global export right (`data_export_tool`) with the same rule: only level 1 allows exporting.
- **FR-012-06** — The export screen MUST run the preview and the download only if `exportBlockedBy()` is `null`; a `run=download` or `run=preview` request without rights is ignored on the server.
- **FR-012-07** — Without rights to export and with approved fields, the screen MUST explain the reason (`ex_no_design_rights`, or `ex_no_export_rights` with the instrument) and not show the preview or download buttons; a DAG user MUST be warned that they will only export their group (`ex_dag_note`). `[manual]`
- **FR-012-08** — `userDagId()` MUST return the user's group (DAG) as an integer, or `null` if they are an administrator or do not belong to any group.
- **FR-012-09** — The transformation MUST request from `REDCap::getData` only the records of the user's DAG (`groups`) and record the group in `stats['dag_id']`; without a DAG the records of all groups are exported.
- **FR-012-10** — With a DAG, the report MUST state in the header the group and that the export only contains its records (`rep_dag`); without a DAG it mentions no group.
- **FR-012-11** — `redcap_module_link_check_display()` MUST show the module's links to any project member with rights in it (non-empty `REDCap::getUserRights(USERID)`), whether or not they have design rights, while keeping each page's permissions on the server. *(Amendment 2026-10, spec 018.)*
- **FR-012-12** — Outside a project, with no identified user, if the user is not a member or if the rights lookup fails, `redcap_module_link_check_display()` MUST apply the framework's default rule.
- **FR-012-13** — The mapping documents (`doc=html`, `json`, `usagi`, `dictionary` on the export screen) MUST be served to any member, without requiring design or export rights, because they only contain metadata.
- **FR-012-14** — Without design rights, the editor MUST be shown read-only: read-only notice, controls and bulk actions disabled, no vocabulary search and no backup information. `[manual]`
- **FR-012-15** — The vocabulary page MUST be linked only from the Control Center and show its content and accept actions only for administrators (`SUPER_USER`); anyone else is shown `voc_admin_only` and the page ends.

## Non-functional requirements

- **NFR-012-01** — Fail closed: if a right cannot be determined, access is denied (constitution, principle 1).
- **NFR-012-02** — All permissions are checked on the server; disabling a button is only a convenience.
- **NFR-012-03** — Each export is recorded in the module log with the number of records, rows, tables, DAG and whether hashing was applied.

## Acceptance criteria

```gherkin
Scenario: design rights are not enough to export
  Given a user with design rights and de-identified export on «base»
  When they open the export screen
  Then exportBlockedBy() returns «base»
  And the screen tells them they are missing full export on «base»
  And they do not see the preview or download buttons

Scenario: the configuration fields count too
  Given that dob and death_date are in the «demo» instrument
  And a user with design rights and [base,1][meds,1][demo,0]
  Then exportBlockedBy() returns «demo»

Scenario: an instrument without approved fields does not block
  Given a user with [base,1][meds,0]
  And no field of «meds» approved or used as a date or in the configuration
  Then exportBlockedBy() returns null

Scenario: a site exports only its own records
  Given a user of DAG 7 and records 1 (DAG 7), 2 and 3 (DAG 8)
  When they generate the export
  Then PERSON only contains record 1
  And the report says «Group (DAG): 7. The export only contains this group's records.»

Scenario: the monitor reviews without editing
  Given a project member without design rights
  When REDCap renders the project menu
  Then they see the links to the dashboard, the editor and the export
  And the editor opens in read-only mode
  And if they send a save by hand they get a 403

Scenario: a non-member sees nothing
  Given a user without rights in the project
  Then redcap_module_link_check_display() applies the framework's rule
```

## Out of scope

- Module-specific roles (curator, reviewer) different from REDCap's.
- Approving with an electronic signature or double approval.
- Restricting which fields each user sees in the editor according to their
  form rights: the editor only shows metadata.
- The detailed audit log of each mapping change (spec 006).

## Manual verification

In the Docker REDCap, create three users and two DAGs in the test project
(«North», with record 1; «South», with records 2 and 3):

1. **monitor**: no design rights, export «No access». Open the project: the
   module's links appear in the menu (FR-012-11). The editor shows the
   read-only notice, the selectors and bulk buttons disabled, with no
   vocabulary search (FR-012-14). On the export screen they see the
   `ex_no_design_rights` message and no download buttons (FR-012-07), but
   «Mapping document» opens the HTML (FR-012-13). Send from the browser
   console a POST `fetch` with `action=save-field` and the token: it responds
   403. Open `pages/export.php&run=download` by hand: the view is shown,
   nothing is downloaded (FR-012-06).
2. **data_manager**: design rights, «De-identified» export on `base`. The
   export screen says full export is missing on `base` (FR-012-07).
3. **site_north**: design rights, «Full data set» on everything, DAG «North».
   The export screen shows `ex_dag_note`; the ZIP only contains record 1 and
   the report says so (FR-012-09, FR-012-10).
4. With a non-administrator user, open the Control Center URL of
   `pages/vocabulary.php`: they only see `voc_admin_only` (FR-012-15).

## Amendments

| Date | Requirement | Change | Motivated by |
|---|---|---|---|
| — | — | Initial version | — |
| 2026-10 | FR-012-11, FR-012-12 | A member without design rights could not open any page of the module, because the framework only shows the links to those with design rights; the editor's read-only mode and the mapping document were unreachable. `redcap_module_link_check_display()` is overridden to show the links to any member with rights in the project (FR-012-11) and keep the framework's rule in the other cases (FR-012-12). Each page's permissions are still checked on the server (FR-012-02, FR-012-06). | 018 (validation with 10 projects) |
| 2026-10 | FR-012-04 | The check only looked at the instruments with **approved** fields. The fields used by the configuration (birth, sex, race, ethnicity, death, care site, provider) and the reference dates are not approved as facts, but their values go out in PERSON, DEATH, CARE_SITE, PROVIDER and in the date of each fact: a user without full export on their instrument took away full birth dates. Now every field whose values are exported counts (`exportedFields()`). | Specification review |
