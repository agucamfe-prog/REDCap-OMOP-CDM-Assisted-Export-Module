# Plan 012 — Permissions and data access groups

## Technical summary

The permissions live in four public methods of `OmopCdmExportModule`, all
built on a single read of the user's rights (`currentRights()`), and in an
override of the framework hook that decides which links are shown. The pages
query those methods on the server before acting: the editor before each POST
action, the export before previewing or downloading. The user's DAG travels to
`Transformer`, which passes it to `REDCap::getData` as `groups` and records it
in the statistics so that the report states it.

## Files

| File | Action | Responsibility |
|---|---|---|
| `OmopCdmExportModule.php` | Modify | `userCanEditMapping()`, `currentRights()`, `exportBlockedBy()`, `userDagId()`, `redcap_module_link_check_display()` |
| `classes/Transformer.php` | Modify | `$dagId` in the constructor, `dataParams()` with `groups`, `stats['dag_id']` |
| `classes/CsvExporter.php` | Modify | `rep_dag` line in the report header |
| `pages/editor.php` | Modify | 403 without design rights; `$canEdit` for read-only mode |
| `pages/export.php` | Modify | `$canRun`; blocking and DAG messages; documents for everyone |
| `pages/vocabulary.php` | Modify | `SUPER_USER` only |
| `config.json` | Modify | Vocabulary in `links.control-center` |
| `lang/*.ini` | Modify | `ed_err_rights`, `ex_no_design_rights`, `ex_no_export_rights`, `ex_dag_note`, `rep_dag`, `voc_admin_only` |
| `tests/bootstrap.php` | Modify | `REDCap::getUserRights()`, `REDCap::$userRights` and the default rule of `redcap_module_link_check_display()` in the framework double |

## Design

### User rights

```php
/** Rights of the current user in this project, or null if superuser. */
private function currentRights(): ?array
```

- `getUser()->isSuperUser()` → `null` (no restrictions).
- Otherwise → `getUser()->getRights($pid)` (REDCap array).
- If the framework throws → `$GLOBALS['user_rights']` or `[]`.

Example of rights (REDCap ≥ 12):

```php
['username' => 'data_manager', 'design' => '1', 'group_id' => '7',
 'data_export_instruments' => '[base,1][meds,2]',   // 0 no access, 1 full, 2 de-identified, 3 identifiers removed
 'data_export_tool' => '1']                          // global right (older REDCap)
```

### Editing

```php
public function userCanEditMapping(): bool
```

`getUser()->hasDesignRights()`; if it throws, `!empty($GLOBALS['user_rights']['design'])`.

In `pages/editor.php`, inside `if ($action !== '')`: first the CSRF token,
then `if (!$module->userCanEditMapping()) $respond([... 'ed_err_rights' ...], 403);`,
and only after that the actions (`save-field`, `save-value`, bulk actions…).
When rendering, `$canEdit = $module->userCanEditMapping()` adds `disabled` to
each control and hides the vocabulary search and the backup.

### Exporting

```php
/** @return string|null null if allowed; '*design' or the instrument that prevents it. */
public function exportBlockedBy(): ?string
```

1. `currentRights() === null` → `null`.
2. `empty($rights['design'])` → `'*design'`.
3. `preg_match_all('/\[([a-z0-9_]+),(\d)\]/', $rights['data_export_instruments'], …)`
   → `['base' => 1, 'meds' => 2]`. If there are none, `$legacy = (int) $rights['data_export_tool']` or `null`.
4. `$consumed = $this->exportedFields()`: the fields whose values go out in
   the export.
5. Walk `getDictionary()` **in its order**; for each field in `$consumed`, its
   `form_name`; level = `$perForm[$form] ?? 0` (or `$legacy`). If `!== 1`,
   return `$form`.
6. `null`.

```php
/** @return array<string,true> */
private function exportedFields(): array
```

Joins, without repetition:

- the fields in state `approved` of `mappingRows()`, with their `spec['date_field']`
  and `spec['end_date_field']`;
- the non-empty values of `personConfig()` (`gender_field`, `birth_date_field`,
  `birth_year_field`, `race_field`, `ethnicity_field`), `deathConfig()`
  (`date_field`), `entityConfig()` (`care_site_field`, `provider_field`) and
  `defaultDateField()`.

Example: with `dob` and `death_date` in the `demo` instrument and rights
`[base,1][meds,1][demo,0]`, it returns `'demo'` (before looking at any approved
field of `base`, because `dob` comes first in the dictionary).

In `pages/export.php`: `$blockedBy = $module->exportBlockedBy(); $canRun = $blockedBy === null;`
and the `run=download` and `run=preview` branches check `&& $canRun`. If
`!$canRun && approved > 0`, an alert with `ex_no_design_rights` (if `'*design'`)
or `ex_no_export_rights` with the instrument.

### DAG

```php
public function userDagId(): ?int   // null if superuser or no group_id
```

`Transformer::__construct()` stores `$this->dagId = $module->userDagId()`;
`dataParams(array $extra)` adds `'groups' => [$this->dagId]` to every call to
`REDCap::getData` (ids and batches); `run()` sets `stats['dag_id']`. The report
adds `rep_dag` after the module line and the page logs `dag_id`.

### Visible links (amendment from spec 018)

```php
public function redcap_module_link_check_display($project_id, $link)
{
    if (!empty($project_id) && defined('USERID')) {
        try {
            $rights = \REDCap::getUserRights(USERID);   // [user => rights] or []
            if (!empty($rights[USERID])) {
                return $link;
            }
        } catch (\Throwable $e) { /* framework rule */ }
    }
    return parent::redcap_module_link_check_display($project_id, $link);
}
```

### Documents and Control Center

The `if ($doc !== '')` branch of `pages/export.php` is resolved at the start
and does not check `$canRun` or `userCanEditMapping()`. `config.json` declares
`pages/vocabulary.php` only in `links.control-center`, and the page starts with
`$isAdmin = defined('SUPER_USER') && SUPER_USER;` and stops with `voc_admin_only`.

## Decisions and discarded alternatives

- **Use REDCap's rights, not our own.** What the team already configured in
  *User Rights* is what counts. *Discarded:* a list of users in the module
  settings, which gets out of sync.
- **Design + full export to export.** Design rights decide what goes out;
  exporting is taking away full dates and Record ID. *Discarded:* requiring
  only design rights, because it allowed bypassing de-identified export.
- **Only the instruments from which some data goes out.** An instrument from
  which nothing goes out must not prevent the export; one from which the
  birth date or a date goes out must, even if that field is not an approved
  fact. *Discarded:* requiring full export on the whole project (blocks for no
  reason) and looking only at the approved fields (lets the configuration data
  out).
- **Read access open to any member.** The pages only contain metadata.
  *Discarded:* the framework's default rule (design rights only), which left
  reviewers without access.
- **The DAG in `getData`, not filtering afterwards.** The records of other
  groups are not even read. *Discarded:* filtering the engine's result.

## Test strategy

In `tests/specs/012_permissions_dags.test.php`:

- `\TestUser::$super = false` and `\TestUser::$rights = [...]` (closure `$actAs`)
  for each combination of rights; `curate()` to have approved fields in
  `base` and `meds`; `updateField('drug', ['state' => 'proposed'], …)` to leave
  `meds` without approved fields; `\REDCap::$dict[...]['form_name']` changed to
  `demo` (`dob`, `death_date`), `site_form` (`site`) or `visit_form`
  (`visit_date`) for the fields used by the configuration.
- Anonymous subclass of the module whose `getUser()` throws, with and without
  `$GLOBALS['user_rights']`.
- DAG: `\REDCap::$dag = ['1' => 7, '2' => 8, '3' => 8]` and `group_id` 7; the
  records, `person_source_value` and `INFORME.txt` are checked.
- Links: the `USERID` constant defined in the test itself if it does not exist
  and `\REDCap::$userRights = [USERID => [...]]` (member) or `[]` (non-member).
- The page rules that cannot be run without REDCap (editor 403, `$canRun` of
  the export, documents, `SUPER_USER`) are checked by reading their source
  code: that the check exists and comes before the action.
- Look of the pages (FR-012-07, FR-012-14): manual.

## Constitution check

- **1 (fail closed):** with no user and no global rights, no editing; a
  missing instrument counts as no access.
- **12:** design rights to edit, full export on every instrument involved to
  export, DAG to limit the records.
- **9:** the report says the export is of a single group only.
- **21:** all messages in `lang/*.ini`.

## Risks

- If a future spec makes the export use another field (a new configuration
  setting), it has to be added to `exportedFields()`; otherwise its data would
  go out without a rights check. The FR-012-04 tests cover the current fields:
  birth and death, care site and reference date.
- The format of `data_export_instruments` is internal to REDCap; if it
  changes, the regular expression stops finding pairs and falls back to the
  global right (or to blocking). It fails closed.
- `REDCap::getUserRights()` can be costly in projects with many users; it is
  called once per link when rendering the menu.
