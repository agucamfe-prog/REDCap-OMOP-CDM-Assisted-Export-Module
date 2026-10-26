# Tasks 012 — Permissions and data access groups

Before starting: read `constitution.md`, `AGENTS.md`, and this folder's
`spec.md` and `plan.md`. Do the tasks in order. Every `[T]` must end with the
test **red**; the following `[I]` turns it green.

Run only this spec's tests: `php tests/run.php 012`

| Legend | |
|---|---|
| `[T]` | Write a test (red) |
| `[I]` | Implement (green) |
| `[R]` | Refactor, document or translate |
| `[P]` | Can be done in parallel with the previous task |

---

### T012-01 [T] Editing requires design rights

- **Requirements:** FR-012-01
- **Files:** `tests/specs/012_permissions_dags.test.php`
- **What to do:**
  1. Closure `$actAs = function (array $rights) { \TestUser::$super = false; \TestUser::$rights = $rights; };`
  2. Administrator → `userCanEditMapping() === true`; `$actAs(['design' => '0', 'data_export_instruments' => '[base,1][meds,1]'])` → `false`; `$actAs(['design' => '1'])` → `true`.
  3. Anonymous subclass with `public function getUser() { throw new \RuntimeException('x'); }`:
     without `$GLOBALS['user_rights']` → `false`; with `['design' => '1']` → `true`.
     Save and restore the previous value of `$GLOBALS['user_rights']`.
- **Done when:** it fails with «Call to undefined method … userCanEditMapping()».
- **Size:** S

### T012-02 [I] userCanEditMapping() and the editor's 403

- **Requirements:** FR-012-01, FR-012-02
- **Files:** `OmopCdmExportModule.php`, `pages/editor.php`, `lang/*.ini`
- **What to do:**
  1. `userCanEditMapping(): bool` as in the plan (`try` around `getUser()`; if
     it throws, `$GLOBALS`).
  2. In `pages/editor.php`, inside `if ($action !== '')`, after the CSRF check:
     `if (!$module->userCanEditMapping()) { $respond(['ok' => false, 'error' => $module->ttText('ed_err_rights')], 403); }`
  3. Key `ed_err_rights` in both `.ini` files.
- **Hints:** the order matters: CSRF → rights → action. `$respond` ends
  with `exit`, so nothing runs after it.
- **Done when:** `php tests/run.php 012` is green for FR-012-01.
- **Size:** S

### T012-03 [T] The editor rejects on the server

- **Requirements:** FR-012-02
- **Files:** `tests/specs/012_permissions_dags.test.php`
- **What to do:** read `pages/editor.php` with `file_get_contents` and check
  with `strpos` that these appear, in this order: `if ($action !== '')`,
  `if (!$module->userCanEditMapping())`, `ed_err_rights')], 403)` and
  `$action === 'save-field'`.
- **Hints:** the page cannot be run without REDCap (it includes its header and
  ends with `exit`); reading the code is the automatic way to pin the order.
- **Done when:** the test is green with T012-02 done and red if you move the
  check after `save-field`.
- **Size:** S

### T012-04 [T] Exporting requires design rights and full export

- **Requirements:** FR-012-03, FR-012-04, FR-012-05
- **Files:** `tests/specs/012_permissions_dags.test.php`
- **What to do:** with `curate($m)`:
  - Administrator → `null`; no design rights and `[base,1][meds,1]` → `'*design'`.
  - Design rights and `[base,1][meds,1]` → `null`; `[base,2][meds,1]` → `'base'`;
    `[base,1][meds,3]` → `'meds'`; `[base,1]` → `'meds'`.
  - `[base,1][meds,0]` with `drug` returned to `proposed`
    (`store()->updateField('drug', ['state' => 'proposed'], \REDCap::$dict['drug'], $m->profile()['drug']['choices'])` and `invalidateMappingCache()`) → `null`.
  - Without `data_export_instruments`: `data_export_tool` `'1'` → `null`, `'2'` → `'base'`, missing → `'base'`.
  - Configuration fields: `\REDCap::$dict['dob']['form_name'] = 'demo'`
    and the same for `death_date`; with `[base,1][meds,1][demo,0]` → `'demo'`,
    with `[demo,1]` → `null`. `site` in `site_form` with `[site_form,2]` → `'site_form'`.
    `visit_date` (reference and default date) in `visit_form` with
    `[visit_form,2]` → `'visit_form'`.
- **Done when:** it fails with «Call to undefined method … exportBlockedBy()».
- **Size:** M

### T012-05 [I] currentRights() and exportBlockedBy()

- **Requirements:** FR-012-03, FR-012-04, FR-012-05
- **Files:** `OmopCdmExportModule.php`
- **What to do:** `private function currentRights(): ?array`,
  `private function exportedFields(): array` and
  `public function exportBlockedBy(): ?string` following the plan's steps.
  Walk `getDictionary()` in order and look at the `form_name` of each field in
  `exportedFields()`.
- **Hints:** compare with `!== 1` after `(int)`: the rights arrive as text.
  An instrument that is not in the list is worth `0`, not «unrestricted». The
  configuration fields have state `config`, not `approved`: if you only look
  at the approved ones, the `demo` test fails.
- **Done when:** `php tests/run.php 012` is green.
- **Size:** M

### T012-06 [T] [P] The export screen only acts with rights

- **Requirements:** FR-012-06
- **Files:** `tests/specs/012_permissions_dags.test.php`
- **What to do:** read `pages/export.php` and check that it contains
  `$canRun    = $blockedBy === null;`,
  `if (($_GET['run'] ?? '') === 'download' && $canRun)` and
  `if (($_GET['run'] ?? '') === 'preview' && $canRun)`.
- **Done when:** the test fails while the page does not check `$canRun`.
- **Size:** S

### T012-07 [I] Guards and messages of the export screen

- **Requirements:** FR-012-06, FR-012-07
- **Files:** `pages/export.php`, `lang/*.ini`
- **What to do:** compute `$blockedBy` and `$canRun` at the start; add `&& $canRun`
  to both branches; if `!$canRun && $counts['approved'] > 0`, an alert with
  `ex_no_design_rights` or `ex_no_export_rights` (with `$blockedBy`); the preview
  and download buttons inside `if ($canRun)`; if
  `userDagId() !== null`, the `ex_dag_note` alert.
- **Hints:** the message is rendered with `htmlspecialchars($module->ttText(...))`.
- **Done when:** the FR-012-06 test is green and the manual verification of
  FR-012-07 (steps 1–3) passes.
- **Size:** S

### T012-08 [T] A DAG user exports only their group

- **Requirements:** FR-012-08, FR-012-09, FR-012-10
- **Files:** `tests/specs/012_permissions_dags.test.php`
- **What to do:**
  1. `userDagId()`: administrator → `null`; `group_id` `''` → `null`; `'7'` → `7`.
  2. `\REDCap::$dag = ['1' => 7, '2' => 8, '3' => 8]`, a user of DAG 7 with
     full export: `run()` gives `stats['records'] === 1`, `dag_id === 7` and
     `person_source_value` `['1']`; the report contains
     `Group (DAG):     7. The export only contains this group's records.`
  3. As administrator: 3 records, no `dag_id`, and the report without `Group (DAG)`.
- **Done when:** it fails because `userDagId()` does not exist.
- **Size:** M

### T012-09 [I] userDagId() down to getData and the report

- **Requirements:** FR-012-08, FR-012-09, FR-012-10
- **Files:** `OmopCdmExportModule.php`, `classes/Transformer.php`, `classes/CsvExporter.php`, `lang/*.ini`
- **What to do:**
  1. `userDagId(): ?int` from `currentRights()['group_id']`.
  2. In `Transformer`: `$this->dagId = $module->userDagId()`; `dataParams()`
     adds `'groups' => [$this->dagId]`; `run()` sets `stats['dag_id']`.
  3. In `writeReport()`: `if (isset($s['dag_id'])) $lines[] = $t('rep_dag', $s['dag_id']);`
  4. Key `rep_dag` in both languages.
- **Hints:** every call to `REDCap::getData` must go through `dataParams()`,
  including the one that only requests the Record IDs.
- **Done when:** `php tests/run.php 012` is green.
- **Size:** M

### T012-10 [T] Links for any member (amendment 018)

- **Requirements:** FR-012-11, FR-012-12
- **Files:** `tests/specs/012_permissions_dags.test.php`, `tests/bootstrap.php`
- **What to do:**
  1. In `tests/bootstrap.php`, additively: `REDCap::$userRights = []` and
     `REDCap::getUserRights($u = null)` returning it; in the
     `AbstractExternalModule` double, `redcap_module_link_check_display()` with the
     framework's rule (link only for a superuser or with design rights, otherwise `null`).
  2. In the test: `if (!defined('USERID')) define('USERID', 'monitor');`
  3. Member without design rights (`\REDCap::$userRights = [USERID => ['design' => '0']]`):
     returns `$link`. Non-member (`[]`): `null`. No project and with design rights: `$link`.
  4. Leave `\REDCap::$userRights = []` when done.
- **Done when:** the first case fails (the framework's rule returns `null`).
- **Size:** S

### T012-11 [I] redcap_module_link_check_display()

- **Requirements:** FR-012-11, FR-012-12
- **Files:** `OmopCdmExportModule.php`
- **What to do:** override the method as in the plan.
- **Hints:** `getUserRights()` returns `[user => rights]`: look at
  `$rights[USERID]`, not the whole array. Any exception falls back to `parent::`.
- **Done when:** `php tests/run.php 012` is green.
- **Size:** S

### T012-12 [T] Documents for everyone and vocabulary for administrators

- **Requirements:** FR-012-13, FR-012-15
- **Files:** `tests/specs/012_permissions_dags.test.php`
- **What to do:**
  1. User without design or export rights: the document's `html()`, `json()`
     and `usagiCsv()` work and `exportBlockedBy() === '*design'`.
  2. In `pages/export.php`, the block between `if ($doc !== '') {` and its `exit;`
     does not contain `canRun`, `blockedBy` or `userCanEditMapping` and comes before
     the download branch.
  3. `config.json`: `pages/vocabulary.php` is in `links.control-center` and not
     in `links.project`. In `pages/vocabulary.php`, `$isAdmin = defined('SUPER_USER') && SUPER_USER;`
     and `if (!$isAdmin) {` come before `$_POST['build']`.
- **Done when:** the tests reflect the described behaviour and fail if an
  export check is added to the documents block.
- **Size:** S

### T012-13 [R] Read-only editor

- **Requirements:** FR-012-14
- **Files:** `pages/editor.php`, `lang/*.ini`
- **What to do:** `$canEdit = $module->userCanEditMapping();` when rendering; a
  read-only notice if `!$canEdit`; `disabled` on every selector, input and
  bulk-action button; vocabulary search only if `$vocabOn && $canEdit`;
  `backupInfo()` only if `$canEdit`.
- **Done when:** step 1 of the manual verification passes.
- **Size:** M

### T012-14 [R] README and manual verification

- **Requirements:** FR-012-07, FR-012-14
- **Files:** `README.md`
- **What to do:** a «Rights and data access groups» section with the who/can
  table and the reason for each rule. Run the spec's full manual verification.
- **Done when:** the README is updated and the four manual steps pass.
- **Size:** S

---

## Definition of done for the spec

- [ ] All `012` tests green and no regressions in the rest of the suite.
- [ ] `php tests/run.php --trace 012` reports no orphan requirements.
- [ ] New texts in `lang/Spanish.ini` and `lang/English.ini` with the same keys.
- [ ] Manual verification done with the users monitor, data_manager and site_north.
- [ ] README updated («Rights and data access groups»).
- [ ] Spec status changed to `Implemented` (or `Amended` after the 018 amendment).
