<?php

namespace OmopCdm\OmopCdmExportModule;

/** @var OmopCdmExportModule $module */

/*
 * The server's OMOP vocabulary (Control Center).
 *
 * The administrator downloads the vocabularies from athena.ohdsi.org, under the
 * licence accepted there. This page builds the local index and lets you try
 * the search and the lookup of a concept, with the index or with the ATLAS
 * WebAPI.
 */

$isAdmin = defined('SUPER_USER') && SUPER_USER;
$h = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$t = fn(string $k, ...$a) => $h($module->ttText($k, ...$a));

require_once APP_PATH_DOCROOT . 'ControlCenter/header.php';

if (!$isAdmin) {
    echo '<div class="alert alert-danger">' . $t('voc_admin_only') . '</div>';
    require_once APP_PATH_DOCROOT . 'ControlCenter/footer.php';
    return;
}

$csvPath = (string) $module->getSystemSetting('vocab-concept-csv');
$dbPath  = (string) $module->getSystemSetting('vocab-sqlite-path');
$webapi  = (string) $module->getSystemSetting('webapi-url');
$msg = null;

if (($_POST['build'] ?? '') === '1') {
    @set_time_limit(0);
    @ignore_user_abort(true);
    try {
        $t0 = microtime(true);
        $n = Vocabulary::buildIndex($csvPath, $dbPath);
        $msg = ['success', $module->ttText('voc_built', number_format($n), round(microtime(true) - $t0, 1))];
        $module->log('Vocabulary index built', ['concepts' => $n, 'path' => $dbPath]);
    } catch (\Throwable $e) {
        $msg = ['danger', $e->getMessage()];
    }
}

$vocab = $module->vocabulary();
$probe = trim((string) ($_POST['probe'] ?? ''));
$results = null;
if ($vocab !== null && $probe !== '') {
    $results = preg_match('/^\d+$/', $probe)
        ? array_values($vocab->lookup([(int) $probe]) ?? [])
        : ($vocab->search($probe, ($_POST['domain'] ?? '') ?: null, 15) ?? []);
}
// The framework exposes it through __call: method_exists() does not see it.
try {
    $token = (string) $module->getCSRFToken();
} catch (\Throwable $e) {
    $token = '';
}
?>
<h4><i class="fas fa-book"></i> <?= $t('voc_title') ?></h4>
<p style="max-width:820px"><?= $t('voc_intro') ?></p>

<?php if ($msg): ?><div class="alert alert-<?= $msg[0] ?>" style="max-width:820px"><?= $h($msg[1]) ?></div><?php endif; ?>

<table class="table table-sm table-bordered" style="max-width:820px">
    <tr><th style="width:35%"><?= $t('voc_csv') ?></th><td><code><?= $h($csvPath ?: '—') ?></code>
        <?= $csvPath && is_readable($csvPath) ? '✓' : ($csvPath ? '✗' : '') ?></td></tr>
    <tr><th><?= $t('voc_index') ?></th><td><code><?= $h($dbPath ?: '—') ?></code>
        <?= $dbPath && is_file($dbPath) ? '✓ ' . $h(number_format(filesize($dbPath) / 1048576, 1)) . ' MB' : '' ?></td></tr>
    <tr><th>WebAPI</th><td><code><?= $h($webapi ?: '—') ?></code></td></tr>
    <tr><th><?= $t('voc_active') ?></th><td><?= $vocab ? $h($vocab->source()) : $t('voc_none') ?></td></tr>
</table>

<?php if ($csvPath && $dbPath): ?>
<form method="post" style="margin-bottom:18px">
    <input type="hidden" name="redcap_csrf_token" value="<?= $h($token) ?>">
    <input type="hidden" name="build" value="1">
    <button class="btn btn-primaryrc btn-sm" onclick="return confirm(<?= $h(json_encode($module->ttText('voc_build_confirm'))) ?>)">
        <?= $t('voc_build') ?></button>
    <span class="text-muted" style="font-size:12px"><?= $t('voc_build_cli', dirname(__DIR__) . '/resources/build_vocab_index.php') ?></span>
</form>
<?php endif; ?>

<?php if ($vocab): ?>
<form method="post" class="form-inline" style="gap:6px">
    <input type="hidden" name="redcap_csrf_token" value="<?= $h($token) ?>">
    <input type="text" name="probe" class="form-control form-control-sm" style="width:280px"
           placeholder="<?= $t('voc_probe_ph') ?>" value="<?= $h($probe) ?>">
    <input type="text" name="domain" class="form-control form-control-sm" style="width:140px"
           placeholder="Measurement" value="<?= $h($_POST['domain'] ?? '') ?>">
    <button class="btn btn-defaultrc btn-sm"><?= $t('voc_probe') ?></button>
</form>
<?php if ($results !== null): ?>
    <table class="table table-sm table-bordered" style="max-width:900px; margin-top:10px; font-size:12px">
        <tr><th>concept_id</th><th>concept_name</th><th>domain</th><th>vocabulary</th><th>class</th><th>S</th><th>code</th></tr>
        <?php foreach ($results as $c): ?>
            <tr><td><?= (int) $c['id'] ?></td><td><?= $h($c['name']) ?></td><td><?= $h($c['domain']) ?></td>
                <td><?= $h($c['vocabulary']) ?></td><td><?= $h($c['class']) ?></td><td><?= $h($c['standard']) ?></td>
                <td><?= $h($c['code']) ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$results): ?><tr><td colspan="7"><?= $t('voc_no_results') ?></td></tr><?php endif; ?>
    </table>
<?php endif; ?>
<?php endif; ?>

<?php
require_once APP_PATH_DOCROOT . 'ControlCenter/footer.php';
