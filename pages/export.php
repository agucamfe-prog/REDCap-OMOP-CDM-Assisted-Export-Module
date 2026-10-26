<?php

namespace OmopCdm\OmopCdmExportModule;

/** @var OmopCdmExportModule $module */

$counts = $module->mappingCounts();
// Exporting requires design rights (deciding what goes out) AND full data export
// rights on the affected instruments (taking it away). The former did not grant the latter.
$blockedBy = $module->exportBlockedBy();
$canRun    = $blockedBy === null;
$h = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

// --------------------------------------------- Mapping document

$doc = $_GET['doc'] ?? '';
if ($doc !== '') {
    // The document carries no participant data: it is the structure of the
    // study (which any member sees in the Codebook) and the mapping
    // decisions. Reviewing them is exactly what non-editors need: the
    // principal investigator, the monitor. It is served to any member.
    $document = $module->mappingDocument();
    $baseName = 'omop_mapeo_pid' . $module->pid() . '_' . date('Ymd_His');

    if ($doc === 'json') {
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $baseName . '.json"');
        echo $document->json();
    } elseif ($doc === 'usagi') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $baseName . '_usagi.csv"');
        echo $document->usagiCsv();
    } elseif ($doc === 'dictionary') {
        // Dictionary with the @OMOP tags, to upload it in the Designer.
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="diccionario_omop_pid' . $module->pid() . '_' . date('Ymd') . '.csv"');
        echo $document->annotatedDictionaryCsv();
    } else {
        // It opens in the browser instead of downloading: almost always the goal
        // is to read it or print it to PDF, not to keep the file.
        header('Content-Type: text/html; charset=utf-8');
        echo $document->html();
    }
    $module->log('Mapping document generated', ['format' => $doc]);
    exit;
}

// ------------------------------------------------------------------ Download

if (($_GET['run'] ?? '') === 'download' && $canRun) {
    try {
        $result = $module->transformer()->run();
    } catch (\Throwable $e) {
        $module->log('Transformation error', [
            'error' => $e->getMessage(),
            'file'  => basename($e->getFile()) . ':' . $e->getLine(),
        ]);
        $downloadError = $e->getMessage() . ' (' . basename($e->getFile())
                       . ':' . $e->getLine() . ')';
        $result = ['error' => null];
    }

    if (isset($downloadError)) {
        // shown below, in the normal view
    } elseif (isset($result['error'])) {
        // With nothing approved no export is possible: fall back to the normal view.
        $downloadError = $module->ttText('ex_err_' . $result['error']);
    } else {
        $zip = $module->exporter()->writeZip($result);
        $module->log('OMOP export generated', [
            'records' => $result['stats']['records'] ?? 0,
            'rows'    => $result['stats']['rows'] ?? 0,
            'tables'  => implode(',', array_keys($result['counts'])),
            'dag_id'  => $result['stats']['dag_id'] ?? '',
            'hashed'  => $module->setting('hash-person-source-value') ? '1' : '0',
        ]);

        $name = 'omop_cdm_pid' . $module->pid() . '_' . date('Ymd_His') . '.zip';
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('Content-Length: ' . $zip['bytes']);
        readfile($zip['path']);
        unlink($zip['path']);   // leave no clinical data in the temporary directory
        exit;
    }
}

// --------------------------------------------------------------------- View

require_once APP_PATH_DOCROOT . 'ProjectGeneral/header.php';

$preview = null;
if (($_GET['run'] ?? '') === 'preview' && $canRun) {
    try {
        $preview = $module->transformer()->run(20, true);
    } catch (\Throwable $e) {
        // The preview exists to uncover these failures before generating
        // anything: the error is shown, not hidden.
        $module->log('Preview error', [
            'error' => $e->getMessage(),
            'file'  => basename($e->getFile()) . ':' . $e->getLine(),
        ]);
        $previewError = $e->getMessage() . "\n" . basename($e->getFile())
                      . ':' . $e->getLine();
    }
}
?>

<style>
    .ex-cards { display:flex; flex-wrap:wrap; gap:12px; margin:14px 0 18px; }
    .ex-card { border:1px solid #dee2e6; border-radius:6px; padding:10px 16px; min-width:120px; background:#fff; }
    .ex-card .n { font-size:24px; font-weight:600; }
    .ex-card .l { font-size:11px; color:#6c757d; text-transform:uppercase; letter-spacing:.04em; }
    .ex-skip td { font-size:12px; }
</style>

<h3 style="margin-top:6px"><i class="fas fa-file-export"></i> <?= $module->tt('ex_title') ?></h3>

<?php if (isset($downloadError)): ?>
    <div class="alert alert-danger" style="max-width:820px"><?= htmlspecialchars($downloadError) ?></div>
<?php endif; ?>

<p class="text-muted" style="max-width:820px"><?= $module->tt('ex_intro') ?></p>

<div class="ex-cards">
    <div class="ex-card"><div class="n"><?= $counts['approved'] ?></div><div class="l"><?= $module->tt('ed_state_approved') ?></div></div>
    <div class="ex-card"><div class="n"><?= $counts['proposed'] ?></div><div class="l"><?= $module->tt('ed_state_proposed') ?></div></div>
    <div class="ex-card"><div class="n"><?= $counts['excluded'] ?></div><div class="l"><?= $module->tt('ed_state_excluded') ?></div></div>
    <div class="ex-card"><div class="n"><?= $counts['review'] ?></div><div class="l"><?= $module->tt('ed_state_review') ?></div></div>
</div>

<?php if ($counts['review'] > 0): ?>
    <div class="alert alert-danger" style="max-width:820px"><?= $module->tt('ex_warn_review', $counts['review']) ?></div>
<?php endif; ?>

<?php if ($counts['proposed'] > 0): ?>
    <div class="alert alert-warning" style="max-width:820px"><?= $module->tt('ex_warn_pending', $counts['proposed']) ?></div>
<?php endif; ?>

<?php
// Without a birth field there is no year_of_birth, and without year_of_birth there
// is no PERSON: the export would come out empty. Warn before it happens.
$pc = $module->personConfig();
if (empty($pc['birth_date_field']) && empty($pc['birth_year_field'])): ?>
    <div class="alert alert-danger" style="max-width:900px">
        <?= $module->tt('ex_no_birth_field') ?>
    </div>
<?php endif; ?>

<?php
// The other configuration problems: each one produces a CDM that does not
// load (empty cdm_holder) or that lies without warning (sex not approved).
$problems = array_diff($module->configProblems(), ['cfgp_no_birth']);
if ($problems): ?>
    <div class="alert alert-warning" style="max-width:900px">
        <b><?= $h($module->ttText('ex_config_title')) ?></b>
        <ul style="margin:6px 0 0">
            <?php foreach ($problems as $p): ?><li><?= $h($module->ttText($p)) ?></li><?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<?php if (!$canRun && $counts['approved'] > 0): ?>
    <div class="alert alert-danger" style="max-width:900px">
        <?= $h($blockedBy === '*design'
            ? $module->ttText('ex_no_design_rights')
            : $module->ttText('ex_no_export_rights', $blockedBy)) ?>
    </div>
<?php endif; ?>

<?php if ($module->userDagId() !== null): ?>
    <div class="alert alert-info" style="max-width:900px"><?= $h($module->ttText('ex_dag_note')) ?></div>
<?php endif; ?>

<?php if ($counts['approved'] === 0): ?>
    <div class="alert alert-secondary" style="max-width:820px"><?= $module->tt('ex_err_no_approved_fields') ?></div>
<?php else: ?>
    <?php if ($canRun): ?>
    <p>
        <a class="btn btn-defaultrc" href="<?= $module->getUrl('pages/export.php') ?>&run=preview">
            <i class="fas fa-eye"></i> <?= $module->tt('ex_btn_preview') ?>
        </a>
        <a class="btn btn-primaryrc" href="<?= $module->getUrl('pages/export.php') ?>&run=download">
            <i class="fas fa-download"></i> <?= $module->tt('ex_btn_download') ?>
        </a>
    </p>
    <?php endif; ?>
    <p>
        <a class="btn btn-defaultrc btn-sm" href="<?= $module->getUrl('pages/export.php') ?>&doc=html"
           target="_blank" rel="noopener">
            <i class="fas fa-file-alt"></i> <?= $module->tt('ex_btn_doc_html') ?>
        </a>
        <a class="btn btn-defaultrc btn-sm" href="<?= $module->getUrl('pages/export.php') ?>&doc=json">
            <i class="fas fa-file-code"></i> <?= $module->tt('ex_btn_doc_json') ?>
        </a>
        <a class="btn btn-defaultrc btn-sm" href="<?= $module->getUrl('pages/export.php') ?>&doc=usagi">
            <i class="fas fa-file-csv"></i> <?= $module->tt('ex_btn_doc_usagi') ?>
        </a>
        <a class="btn btn-defaultrc btn-sm" href="<?= $module->getUrl('pages/export.php') ?>&doc=dictionary"
           title="<?= $h($module->ttText('ex_btn_dict_title')) ?>">
            <i class="fas fa-tags"></i> <?= $module->tt('ex_btn_dict') ?>
        </a>
        <span class="text-muted" style="font-size:12px; margin-left:8px"><?= $module->tt('ex_doc_note') ?></span>
    </p>
<?php endif; ?>

<?php if (isset($previewError)): ?>
    <div class="alert alert-danger" style="max-width:900px">
        <b><?= $module->tt('ex_err_crash') ?></b>
        <pre style="white-space:pre-wrap; margin:8px 0 0; font-size:12px"><?= htmlspecialchars($previewError) ?></pre>
    </div>
<?php endif; ?>

<?php if ($preview !== null && !isset($preview['error'])): ?>
    <hr>
    <h5><?= $module->tt('ex_preview_title') ?></h5>
    <p class="text-muted"><?= $module->tt('ex_preview_note', $preview['stats']['records'] ?? 0) ?></p>

    <table class="table table-sm table-bordered" style="max-width:520px">
        <thead class="thead-light"><tr><th><?= $module->tt('ex_th_table') ?></th><th><?= $module->tt('ex_th_rows') ?></th></tr></thead>
        <tbody>
        <?php foreach ($preview['counts'] as $table => $n): ?>
            <tr><td><code><?= strtoupper($table) ?></code></td><td><?= number_format($n) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <?php if (!empty($preview['stats']['not_emitted'])): ?>
        <p class="text-muted"><?= $module->tt('ex_not_emitted', $preview['stats']['not_emitted']) ?></p>
    <?php endif; ?>

    <?php if (!empty($preview['stats']['persons_dropped'])): ?>
        <div class="alert alert-danger" style="max-width:900px">
            <?= $module->tt('ex_dropped', $preview['stats']['persons_dropped'],
                            $preview['stats']['rows_dropped_with'] ?? 0) ?>
            <?php if (!empty($preview['stats']['dropped_record_ids'])): ?>
                <div style="margin-top:6px"><code><?= htmlspecialchars($preview['stats']['dropped_record_ids']) ?></code></div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($preview['stats']['visits'])): ?>
        <p class="text-muted"><?= $module->tt('ex_visits', $preview['stats']['visits']) ?>
        <?php if (!empty($preview['stats']['facts_outside_visit'])): ?>
            <?= $module->tt('ex_outside_visit', $preview['stats']['facts_outside_visit']) ?>
        <?php endif; ?></p>
    <?php endif; ?>

    <?php foreach (['gender_not_approved', 'race_not_approved', 'ethnicity_not_approved'] as $k):
        if (!empty($preview['stats'][$k])): ?>
        <div class="alert alert-warning" style="max-width:900px"><?= $h($module->ttText('rep_' . $k)) ?></div>
    <?php endif; endforeach; ?>

    <?php if (!empty($preview['stats']['not_numeric']) || !empty($preview['stats']['ambiguous_number'])): ?>
        <div class="alert alert-warning" style="max-width:900px">
            <?php if (!empty($preview['stats']['not_numeric'])): ?>
                <div><?= $h($module->ttText('rep_not_numeric', $preview['stats']['not_numeric'])) ?></div>
            <?php endif; ?>
            <?php if (!empty($preview['stats']['ambiguous_number'])): ?>
                <div><?= $h($module->ttText('rep_ambiguous', $preview['stats']['ambiguous_number'])) ?></div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($preview['stats']['persons_without_period'])): ?>
        <div class="alert alert-warning" style="max-width:820px">
            <?= $module->tt('ex_no_period', $preview['stats']['persons_without_period']) ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($preview['missing'])): ?>
        <div class="alert alert-danger" style="max-width:900px">
            <b><?= $module->tt('ex_missing_title') ?></b>
            <ul style="margin:8px 0 0">
            <?php foreach ($preview['missing'] as $table => $cols): ?>
                <?php foreach ($cols as $col => $n): ?>
                    <li><code><?= strtoupper($table) ?>.<?= htmlspecialchars($col) ?></code>
                        &mdash; <?= $module->tt('ex_missing_rows', $n) ?></li>
                <?php endforeach; ?>
            <?php endforeach; ?>
            </ul>
            <div style="margin-top:8px"><?= $module->tt('ex_missing_note') ?></div>
        </div>
    <?php endif; ?>

    <?php if (!empty($preview['skipped'])): ?>
        <h5><?= $module->tt('ex_skipped_title') ?></h5>
        <table class="table table-sm table-bordered ex-skip" style="max-width:700px">
            <tbody>
            <?php foreach ($preview['skipped'] as $reason => $n): ?>
                <tr><td style="width:80px"><?= number_format($n) ?></td><td><?= $h($module->skipLabel($reason)) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <p class="text-muted" style="max-width:820px"><?= $module->tt('ex_skipped_note') ?></p>
    <?php endif; ?>
<?php endif; ?>

<?php
require_once APP_PATH_DOCROOT . 'ProjectGeneral/footer.php';
