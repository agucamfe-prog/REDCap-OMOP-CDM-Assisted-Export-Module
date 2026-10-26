<?php

namespace OmopCdm\OmopCdmExportModule;

/** @var OmopCdmExportModule $module */

$structure = $module->getProjectStructure();
$profile   = $module->profile();
$summary   = $module->profileSummary($profile);
$cdmVer    = $module->setting('cdm-version') ?: '5.4';

// --------------------------------------------------------------- Downloads
// Resolved before rendering anything, so as not to corrupt the binary output.

$download = $_GET['download'] ?? '';

if ($download === 'json') {
    $payload = [
        'generated'  => date('c'),
        'project_id' => $structure['project_id'],
        'cdm_version' => $cdmVer,
        'structure'  => $structure,
        'proposal'   => $profile,
    ];
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="omop_propuesta_pid'
        . $structure['project_id'] . '_' . date('Ymd') . '.json"');
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $module->audit('Mapping proposal download (JSON)');
    exit;
}

if ($download === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="omop_propuesta_pid'
        . $structure['project_id'] . '_' . date('Ymd') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // BOM, so that Excel keeps the accents
    fputcsv($out, ['field_name', 'form_name', 'field_type', 'validation', 'field_label',
        'is_identifier', 'value_kind', 'unit_hint', 'mappable', 'choice_count', 'suggested_domain',
        'suggested_table', 'confidence', 'reason']);
    foreach ($profile as $row) {
        fputcsv($out, [
            $row['field_name'], $row['form_name'], $row['field_type'], $row['validation'],
            $row['field_label'], $row['is_identifier'] ? 'y' : '', $row['value_kind'],
            $row['unit_hint'] ?? '', $row['mappable'], $row['choice_count'], $row['suggested_domain'],
            $row['suggested_table'], $row['confidence'], $row['reason'],
        ]);
    }
    fclose($out);
    $module->audit('Mapping proposal download (CSV)');
    exit;
}

// ------------------------------------------------------------------ Rendering

require_once APP_PATH_DOCROOT . 'ProjectGeneral/header.php';

$total      = count($profile);
$phiCount   = count(array_filter($profile, fn($r) => $r['is_identifier']));
$unmapped   = $summary[DictionaryProfiler::D_SKIP] ?? 0;
$lowConf    = count(array_filter($profile, fn($r) => $r['confidence'] === 'low'));
$manual     = count(array_filter($profile, fn($r) => $r['mappable'] === 'manual'));

$badgeClass = [
    'high'   => 'badge-success',
    'medium' => 'badge-warning',
    'low'    => 'badge-secondary',
];
?>

<style>
    .omop-cards { display: flex; flex-wrap: wrap; gap: 12px; margin: 16px 0 22px; }
    .omop-card { border: 1px solid #dee2e6; border-radius: 6px; padding: 12px 18px; min-width: 130px; background: #fff; }
    .omop-card .n { font-size: 26px; font-weight: 600; line-height: 1.1; }
    .omop-card .l { font-size: 12px; color: #6c757d; text-transform: uppercase; letter-spacing: .04em; }
    .omop-domains span { display: inline-block; margin: 0 6px 6px 0; }
    #omop-table td { vertical-align: top; font-size: 12px; }
    #omop-table .reason { color: #6c757d; }
    .omop-filters { margin-bottom: 10px; }
</style>

<h3 style="margin-top:6px">
    <i class="fas fa-project-diagram"></i>
    <?= $module->tt('dash_title') ?>
    <small class="text-muted" style="font-size:14px">CDM v<?= htmlspecialchars($cdmVer) ?></small>
</h3>

<p class="text-muted" style="max-width:760px">
<?= $module->tt('dash_intro') ?>
</p>

<div class="omop-cards">
    <div class="omop-card"><div class="n"><?= $total ?></div><div class="l"><?= $module->tt('card_fields') ?></div></div>
    <div class="omop-card"><div class="n"><?= $total - $unmapped ?></div><div class="l"><?= $module->tt('card_with_target') ?></div></div>
    <div class="omop-card"><div class="n"><?= $lowConf ?></div><div class="l"><?= $module->tt('card_low_conf') ?></div></div>
    <div class="omop-card"><div class="n"><?= $manual ?></div><div class="l"><?= $module->tt('card_manual') ?></div></div>
    <div class="omop-card"><div class="n"><?= $phiCount ?></div><div class="l"><?= $module->tt('card_identifiers') ?></div></div>
    <div class="omop-card">
        <div class="n"><?= $structure['longitudinal'] ? count($structure['events']) : 1 ?></div>
        <div class="l"><?= $structure['longitudinal'] ? $module->tt('card_events') : $module->tt('card_single_event') ?></div>
    </div>
</div>

<?php if (!empty($structure['repeating'])): ?>
    <div class="alert alert-info" style="max-width:760px">
        <?= $module->tt('repeating_note', count($structure['repeating'])) ?>
    </div>
<?php endif; ?>

<h5><?= $module->tt('domain_breakdown') ?></h5>
<p class="omop-domains">
    <?php foreach ($summary as $domain => $n): ?>
        <span class="badge badge-light" style="border:1px solid #ced4da; font-size:13px; padding:6px 9px">
            <?= htmlspecialchars($module->domainLabel($domain)) ?> <b><?= $n ?></b>
        </span>
    <?php endforeach; ?>
</p>

<div class="omop-filters">
    <a class="btn btn-primaryrc btn-sm" href="<?= $module->getUrl('pages/dashboard.php') ?>&download=csv">
        <i class="fas fa-file-csv"></i> <?= $module->tt('btn_csv') ?>
    </a>
    <a class="btn btn-defaultrc btn-sm" href="<?= $module->getUrl('pages/dashboard.php') ?>&download=json">
        <i class="fas fa-file-code"></i> <?= $module->tt('btn_json') ?>
    </a>
    <input type="text" id="omop-search" class="form-control form-control-sm d-inline-block"
           style="width:240px; margin-left:12px" placeholder="<?= $module->tt('filter_placeholder') ?>">
</div>

<table class="table table-sm table-bordered" id="omop-table">
    <thead class="thead-light">
        <tr>
            <th><?= $module->tt('th_field') ?></th>
            <th><?= $module->tt('th_form') ?></th>
            <th><?= $module->tt('th_type') ?></th>
            <th><?= $module->tt('th_value') ?></th>
            <th><?= $module->tt('th_domain') ?></th>
            <th><?= $module->tt('th_confidence') ?></th>
            <th><?= $module->tt('th_reason') ?></th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($profile as $row): ?>
        <tr>
            <td>
                <code><?= htmlspecialchars($row['field_name']) ?></code>
                <?php if ($row['is_identifier']): ?>
                    <span class="badge badge-danger" title="<?= $module->tt('badge_phi') ?>"><?= $module->tt('badge_phi') ?></span>
                <?php endif; ?>
                <div class="reason"><?= htmlspecialchars(mb_strimwidth($row['field_label'], 0, 90, '…')) ?></div>
            </td>
            <td><?= htmlspecialchars($row['form_name']) ?></td>
            <td>
                <?= htmlspecialchars($row['field_type']) ?>
                <?php if ($row['validation']): ?>
                    <div class="reason"><?= htmlspecialchars($row['validation']) ?></div>
                <?php endif; ?>
            </td>
            <td>
                <?= htmlspecialchars($row['value_kind']) ?>
                <?php if (!empty($row['unit_hint'])): ?>
                    <div class="reason"><?= $module->tt('lbl_unit') ?>: <?= htmlspecialchars($row['unit_hint']) ?></div>
                <?php endif; ?>
                <?php if ($row['choice_count'] > 0): ?>
                    <div class="reason"><?= $module->tt('lbl_options', $row['choice_count']) ?></div>
                <?php endif; ?>
            </td>
            <td>
                <b><?= htmlspecialchars($module->domainLabel($row['suggested_domain'])) ?></b>
                <?php if ($row['mappable'] === 'manual'): ?>
                    <span class="badge badge-warning" title="<?= $module->tt('badge_manual_title') ?>"><?= $module->tt('badge_manual') ?></span>
                <?php endif; ?>
                <?php if ($row['suggested_table']): ?>
                    <div class="reason"><?= htmlspecialchars($row['suggested_table']) ?></div>
                <?php endif; ?>
            </td>
            <td>
                <span class="badge <?= $badgeClass[$row['confidence']] ?? 'badge-secondary' ?>">
                    <?= htmlspecialchars($module->tt('conf_' . $row['confidence'])) ?>
                </span>
            </td>
            <td class="reason"><?= htmlspecialchars($row['reason']) ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<script>
    document.getElementById('omop-search').addEventListener('input', function (e) {
        var q = e.target.value.toLowerCase();
        document.querySelectorAll('#omop-table tbody tr').forEach(function (tr) {
            tr.style.display = tr.textContent.toLowerCase().indexOf(q) === -1 ? 'none' : '';
        });
    });
</script>

<?php
require_once APP_PATH_DOCROOT . 'ProjectGeneral/footer.php';
