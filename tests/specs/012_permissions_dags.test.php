<?php
// Spec 012 — Permissions and data access groups

// REDCap user of the request. In REDCap the framework defines it.
if (!defined('USERID')) {
    define('USERID', 'monitor');
}
$root = dirname(__DIR__, 2);
$editorSrc = file_get_contents($root . '/pages/editor.php');
$exportSrc = file_get_contents($root . '/pages/export.php');
$vocabSrc  = file_get_contents($root . '/pages/vocabulary.php');
$config    = json_decode(file_get_contents($root . '/config.json'), true);

// A regular (non-administrator) user with the given rights.
$actAs = function (array $rights) {
    \TestUser::$super = false;
    \TestUser::$rights = $rights;
};

// ---------------------------------------------------------------------------
section('Editing the mapping');

$m = make_module();
test('FR-012-01', 'an administrator can edit the mapping', $m->userCanEditMapping() === true);
$actAs(['design' => '0', 'data_export_instruments' => '[base,1][meds,1]']);
test('FR-012-01', 'without design rights the mapping cannot be edited, even if export is allowed', $m->userCanEditMapping() === false);
$actAs(['design' => '1']);
test('FR-012-01', 'with design rights the mapping can be edited', $m->userCanEditMapping() === true);

$noUser = new class extends \OmopCdm\OmopCdmExportModule\OmopCdmExportModule {
    public function getUser() { throw new \RuntimeException('no framework'); }
};
$before = $GLOBALS['user_rights'] ?? null;
unset($GLOBALS['user_rights']);
test('FR-012-01', 'if the user cannot be determined, editing is not allowed (fail closed)',
    $noUser->userCanEditMapping() === false);
$GLOBALS['user_rights'] = ['design' => '1'];
test('FR-012-01', 'without the framework user, the global REDCap rights are used',
    $noUser->userCanEditMapping() === true);
if ($before === null) {
    unset($GLOBALS['user_rights']);
} else {
    $GLOBALS['user_rights'] = $before;
}

$pActions = strpos($editorSrc, "if (\$action !== '')");
$pRights = strpos($editorSrc, 'if (!$module->userCanEditMapping())');
$pReject  = strpos($editorSrc, "ed_err_rights')], 403)");
$pSave  = strpos($editorSrc, "\$action === 'save-field'");
test('FR-012-02', 'the editor rejects on the server (403) any action by a user without design rights, before running it',
    $pActions !== false && $pRights !== false && $pReject !== false && $pSave !== false
    && $pActions < $pRights && $pRights < $pReject && $pReject < $pSave);

// ---------------------------------------------------------------------------
section('Exporting the CDM');

reset_fixture();
$m = make_module();
curate($m);
test('FR-012-03', 'an administrator can always export', $m->exportBlockedBy() === null);
$actAs(['design' => '0', 'data_export_instruments' => '[base,1][meds,1]']);
test('FR-012-03', 'without design rights there is no export, even with full export rights', $m->exportBlockedBy() === '*design');

$actAs(['design' => '1', 'data_export_instruments' => '[base,1][meds,1]']);
test('FR-012-04', 'with design rights and full export on every instrument it is not blocked', $m->exportBlockedBy() === null);
\TestUser::$rights['data_export_instruments'] = '[base,2][meds,1]';
test('FR-012-04', 'with de-identified export it is blocked and says on which instrument', $m->exportBlockedBy() === 'base');
\TestUser::$rights['data_export_instruments'] = '[base,1][meds,3]';
test('FR-012-04', 'with export without identifiers on an instrument with approved fields it is blocked', $m->exportBlockedBy() === 'meds');
\TestUser::$rights['data_export_instruments'] = '[base,1]';
test('FR-012-04', 'an instrument missing from the rights counts as no access', $m->exportBlockedBy() === 'meds');

\TestUser::$rights['data_export_instruments'] = '[base,1][meds,0]';
$m->store()->updateField('drug', ['state' => 'proposed'], \REDCap::$dict['drug'], $m->profile()['drug']['choices']);
$m->invalidateMappingCache();
test('FR-012-04', 'an instrument without approved fields does not require export rights', $m->exportBlockedBy() === null);

// The fields used by the configuration are exported data too.
reset_fixture();
\REDCap::$dict['dob']['form_name'] = 'demo';
\REDCap::$dict['death_date']['form_name'] = 'demo';
$m = make_module();
curate($m);
$actAs(['design' => '1', 'data_export_instruments' => '[base,1][meds,1][demo,0]']);
test('FR-012-04', 'birth and death on an instrument without full export block, and the instrument is named',
    $m->exportBlockedBy() === 'demo');
\TestUser::$rights['data_export_instruments'] = '[base,1][meds,1][demo,1]';
test('FR-012-04', 'with full export on the configuration instrument it is not blocked', $m->exportBlockedBy() === null);

reset_fixture();
\REDCap::$dict['site']['form_name'] = 'site_form';
$m = make_module();
curate($m);
$actAs(['design' => '1', 'data_export_instruments' => '[base,1][meds,1][site_form,2]']);
test('FR-012-04', 'the site field (CARE_SITE) on a de-identified instrument blocks', $m->exportBlockedBy() === 'site_form');

reset_fixture();
\REDCap::$dict['visit_date']['form_name'] = 'visit_form';
$m = make_module();
curate($m);
$actAs(['design' => '1', 'data_export_instruments' => '[base,1][meds,1][visit_form,2]']);
test('FR-012-04', 'the reference date of the approved facts on a de-identified instrument blocks',
    $m->exportBlockedBy() === 'visit_form');

reset_fixture();
$m = make_module();
curate($m);
$actAs(['design' => '1', 'data_export_tool' => '1']);
test('FR-012-05', 'without per-instrument rights the global export right is used: full, not blocked',
    $m->exportBlockedBy() === null);
\TestUser::$rights['data_export_tool'] = '2';
test('FR-012-05', 'with a de-identified global right it is blocked on the first instrument with approved fields',
    $m->exportBlockedBy() === 'base');
unset(\TestUser::$rights['data_export_tool']);
test('FR-012-05', 'without any export right it is blocked', $m->exportBlockedBy() === 'base');

test('FR-012-06', 'the download and the preview only run if the rights check allows it',
    strpos($exportSrc, '$canRun    = $blockedBy === null;') !== false
    && strpos($exportSrc, "if ((\$_GET['run'] ?? '') === 'download' && \$canRun)") !== false
    && strpos($exportSrc, "if ((\$_GET['run'] ?? '') === 'preview' && \$canRun)") !== false);

// ---------------------------------------------------------------------------
section('Data access groups (DAG)');

reset_fixture();
$m = make_module();
test('FR-012-08', 'an administrator is not limited to any group', $m->userDagId() === null);
$actAs(['design' => '1', 'group_id' => '']);
test('FR-012-08', 'a user without a group sees the whole project', $m->userDagId() === null);
$actAs(['design' => '1', 'group_id' => '7']);
test('FR-012-08', 'a DAG user is limited to their group', $m->userDagId() === 7);

reset_fixture();
$actAs(['design' => '1', 'group_id' => '7', 'data_export_instruments' => '[base,1][meds,1]']);
\REDCap::$dag = ['1' => 7, '2' => 8, '3' => 8];
$m = make_module();
curate($m);
$res = $m->transformer()->run();
test('FR-012-09', 'a DAG user exports only their group',
    $res['stats']['records'] === 1 && ($res['stats']['dag_id'] ?? null) === 7
    && array_column($res['tables']['person'], 'person_source_value') === ['1']);
$zip = $m->exporter()->writeZip($res);
$z = new \ZipArchive();
$z->open($zip['path']);
$inf = $z->getFromName('INFORME.txt');
$z->close();
unlink($zip['path']);
test('FR-012-10', 'the report names the group and says it only contains its records',
    strpos($inf, "Group (DAG):     7. The export only contains this group's records.") !== false);

reset_fixture();
\REDCap::$dag = ['1' => 7, '2' => 8, '3' => 8];
$m = make_module();
curate($m);
$res = $m->transformer()->run();
$zip = $m->exporter()->writeZip($res);
$z = new \ZipArchive();
$z->open($zip['path']);
$inf = $z->getFromName('INFORME.txt');
$z->close();
unlink($zip['path']);
test('FR-012-09', 'without a group the records of all DAGs are exported', $res['stats']['records'] === 3 && !isset($res['stats']['dag_id']));
test('FR-012-10', 'without a group the report mentions no DAG', strpos($inf, 'Group (DAG)') === false);

// ---------------------------------------------------------------------------
section('Viewing without editing');

reset_fixture();
$link = ['name' => 'OMOP-CDM: mapping dashboard', 'url' => 'pages/dashboard.php'];
$m = make_module();
$actAs(['design' => '0', 'data_export_instruments' => '[base,0][meds,0]']);
\REDCap::$userRights = [USERID => ['username' => USERID, 'design' => '0']];
test('FR-012-11', 'a project member without design rights sees the module links',
    $m->redcap_module_link_check_display(1, $link) === $link);
\REDCap::$userRights = [];
test('FR-012-12', 'a non-member of the project gets the framework rule (no design rights, no link)',
    $m->redcap_module_link_check_display(1, $link) === null);
$actAs(['design' => '1']);
test('FR-012-12', 'outside a project the framework rule is used',
    $m->redcap_module_link_check_display(null, $link) === $link);

reset_fixture();
$m = make_module();
curate($m);
$actAs(['design' => '0', 'data_export_instruments' => '[base,0][meds,0]']);
$doc = $m->mappingDocument();
test('FR-012-13', 'a member without design or export rights gets the mapping document (HTML, JSON and Usagi)',
    strpos($doc->html(), '<!DOCTYPE html>') === 0 && isset(json_decode($doc->json(), true)['fields']['weight'])
    && strpos($doc->usagiCsv(), 'sourceCode,') === 0 && $m->exportBlockedBy() === '*design');
$i = strpos($exportSrc, "if (\$doc !== '') {");
$block = substr($exportSrc, $i, strpos($exportSrc, 'exit;', $i) - $i);
test('FR-012-13', 'the mapping documents are served before, and independently of, the export check',
    $i !== false && $i < strpos($exportSrc, "=== 'download' && \$canRun")
    && strpos($block, 'canRun') === false && strpos($block, 'blockedBy') === false
    && strpos($block, 'userCanEditMapping') === false);

// ---------------------------------------------------------------------------
section('Control Center');

$cc = array_column($config['links']['control-center'] ?? [], 'url');
$pr = array_column($config['links']['project'] ?? [], 'url');
test('FR-012-15', 'the vocabulary page is only linked from the Control Center',
    in_array('pages/vocabulary.php', $cc, true) && !in_array('pages/vocabulary.php', $pr, true));
$pAdmin = strpos($vocabSrc, "\$isAdmin = defined('SUPER_USER') && SUPER_USER;");
$pCutoff = strpos($vocabSrc, 'if (!$isAdmin) {');
$pBuild = strpos($vocabSrc, "\$_POST['build']");
test('FR-012-15', 'the vocabulary page stops non-administrators before any action',
    $pAdmin !== false && $pCutoff !== false && $pBuild !== false && $pAdmin < $pCutoff && $pCutoff < $pBuild);

\REDCap::$userRights = [];
