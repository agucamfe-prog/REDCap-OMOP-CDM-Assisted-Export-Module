<?php

namespace OmopCdm\OmopCdmExportModule;

/** @var OmopCdmExportModule $module */

$pid       = (int) $module->getProjectId();
$store     = $module->store();
$dictionary = $module->getDictionary();
$profile   = $module->profile();

// Our own CSRF token. The session may not be started depending on how REDCap
// serves the module page, so it is checked before touching it.
if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}
if (empty($_SESSION['omop_csrf'])) {
    $_SESSION['omop_csrf'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['omop_csrf'];

// ------------------------------------------------------------------ Actions
// Resolved before printing anything: they return JSON and terminate.

$action = $_POST['action'] ?? '';

if ($action !== '') {
    /**
     * Emits JSON and terminates.
     *
     * Discards any previous output (a PHP warning, a template fragment)
     * because a single byte would be enough for the browser to fail to parse
     * the response. And it ends with exit, not return: after a return the
     * module framework would keep printing and corrupt the JSON.
     */
    $respond = function (array $payload, int $status = 200) {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Omop-Response: 1');
        echo json_encode($payload);
        exit;
    };

    try {

    if (!hash_equals($csrf, (string) ($_POST['csrf'] ?? ''))) {
        $respond(['ok' => false, 'error' => $module->ttText('ed_err_csrf')], 403);
    }
    if (!$module->userCanEditMapping()) {
        $respond(['ok' => false, 'error' => $module->ttText('ed_err_rights')], 403);
    }

    if ($action === 'save-field') {
        $field = (string) ($_POST['field'] ?? '');
        if (!isset($dictionary[$field])) {
            $respond(['ok' => false, 'error' => $module->ttText('ed_err_unknown_field', $field)], 400);
        }
        if (isset($_POST['domain']) && $_POST['domain'] !== ''
            && !in_array($_POST['domain'], OmopCdmExportModule::validDomains(), true)) {
            $respond(['ok' => false, 'error' => $module->ttText('ed_err_bad_domain', (string) $_POST['domain'])], 400);
        }
        $changes = [];
        // This list must cover everything editable in MappingStore::blankSpec():
        // a field missing here is silently discarded on save.
        foreach (['domain', 'concept_id', 'concept_name', 'value_strategy',
                  'date_field', 'end_date_field', 'unit_source_value', 'notes', 'state'] as $key) {
            if (array_key_exists($key, $_POST)) {
                $changes[$key] = $_POST[$key];
            }
        }
        $choices = $profile[$field]['choices'] ?? [];
        $current = $store->spec($field);

        // A field with an @OMOP annotation that nobody has touched: the
        // annotation is the proposal, so it is adopted before applying what arrives.
        $ann = $module->annotation($field);
        if (empty($current['edited']) && $ann !== null) {
            $store->adoptAnnotation($field, $ann, $choices, $dictionary);
            $current = $store->spec($field);
        }

        // With a vocabulary, pasting just the number is enough: the name is filled in.
        $fillName = function (array &$ch, array $stored) use ($module) {
            $id = isset($ch['concept_id']) ? MappingStore::cleanConceptId($ch['concept_id']) : '';
            if ($id !== '' && preg_match('/^\d+$/', $id)
                && trim((string) ($ch['concept_name'] ?? $stored['concept_name'] ?? '')) === '') {
                $c = $module->conceptInfo((int) $id);
                if (is_array($c)) {
                    $ch['concept_name'] = $c['name'];
                }
            }
        };
        $fillName($changes, $current);

        // What is shown preselected has to be saved, or the curator would
        // approve one thing and another would be exported. But ONLY the first
        // time: once something is saved, what is stored prevails. If the
        // proposal were reapplied on every partial save, a field approved as
        // "single concept" would go back to "per option" on its own and lose
        // its approval.
        if (empty($current['edited'])) {
            $domainNow = $changes['domain'] ?? ($profile[$field]['suggested_domain'] ?? '');
            if (!array_key_exists('value_strategy', $changes)) {
                $sug = DictionaryProfiler::suggestedStrategy(
                    $profile[$field]['value_kind'] ?? '', $choices, (string) $domainNow);
                if ($sug !== null) {
                    $changes['value_strategy'] = $sug;
                }
            }
            if (!array_key_exists('date_field', $changes)) {
                $auto = $module->suggestedDateField($field, (string) $domainNow);
                if ($auto !== null) {
                    $changes['date_field'] = $auto;
                }
            }
            if (!array_key_exists('domain', $changes) && $domainNow !== '') {
                $changes['domain'] = $domainNow;
            }
        }

        $wantsApproval = ($changes['state'] ?? '') === MappingStore::S_APPROVED;
        if ($wantsApproval) {
            unset($changes['state']);
            $store->updateField($field, $changes, $dictionary[$field], $choices);

            $warnings = $module->advise($field);
            if (MappingAdvisor::blocks($warnings)) {
                $respond([
                    'ok' => true,
                    'spec' => $store->spec($field),
                    'warnings' => $warnings,
                    'blocked' => true,
                    'counts' => $module->mappingCounts(),
                ]);
            }
            if (MappingAdvisor::needsConfirm($warnings) && empty($_POST['confirmed'])) {
                $respond([
                    'ok' => true,
                    'spec' => $store->spec($field),
                    'warnings' => $warnings,
                    'confirm' => true,
                    'counts' => $module->mappingCounts(),
                ]);
            }
            $changes['state'] = MappingStore::S_APPROVED;
            if ($warnings) {
                $module->log('Approved with warnings', [
                    'field' => $field,
                    'codes' => implode(',', array_column($warnings, 'code')),
                ]);
            }
        }

        $spec = $store->updateField($field, $changes, $dictionary[$field], $choices);
        $respond([
            'ok'      => true,
            'spec'    => $spec,
            'error'   => isset($spec['_error'])
                         ? $module->ttText('ed_err_' . $spec['_error'], $spec['_bad_value'] ?? '')
                         : null,
            'warnings' => $module->advise($field),
            'counts'  => $module->mappingCounts(),
        ]);
    }

    if ($action === 'save-value') {
        $field = (string) ($_POST['field'] ?? '');
        $raw   = (string) ($_POST['raw_value'] ?? '');
        if (!isset($dictionary[$field])) {
            $respond(['ok' => false, 'error' => $module->ttText('ed_err_unknown_field', $field)], 400);
        }
        $changes = [];
        foreach (['emit', 'concept_id', 'concept_name', 'source_value'] as $k) {
            if (array_key_exists($k, $_POST)) {
                $changes[$k] = $_POST[$k];
            }
        }
        // choicesFor() covers yesno fields, whose options are not in the data dictionary.
        $label = $profile[$field]['choices'][$raw] ?? '';
        $ann = $module->annotation($field);
        if (empty($store->spec($field)['edited']) && $ann !== null) {
            $store->adoptAnnotation($field, $ann, $profile[$field]['choices'] ?? [], $dictionary);
        }
        $id = isset($changes['concept_id']) ? MappingStore::cleanConceptId($changes['concept_id']) : '';
        if ($id !== '' && preg_match('/^\d+$/', $id) && trim((string) ($changes['concept_name'] ?? '')) === '') {
            $c = $module->conceptInfo((int) $id);
            if (is_array($c)) {
                $changes['concept_name'] = $c['name'];
            }
        }
        $r = $store->updateValue($field, $raw, $changes, $label, $dictionary[$field]);
        $respond(['ok' => true, 'value' => $r['value'], 'changed' => $r['changed'],
                  'state' => $r['state'], 'counts' => $module->mappingCounts()]);
    }

    if ($action === 'reset-proposal') {
        $field = (string) ($_POST['field'] ?? '');
        if (!isset($profile[$field])) {
            $respond(['ok' => false, 'error' => $module->ttText('ed_err_unknown_field', $field)], 400);
        }
        $r = $store->resetToProposal($field, $profile[$field]['suggested_domain']);
        $respond([
            'ok'       => true,
            'spec'     => $r['spec'],
            'changed'  => $r['changed'],
            'from'     => $r['from'],
            'to'       => $r['to'],
            'warnings' => $module->advise($field),
            'counts'   => $module->mappingCounts(),
        ]);
    }

    if ($action === 'suggest') {
        // Vocabulary candidates, Usagi-style: the same search as the Athena
        // button, but resolved here and with one click to choose.
        $field = (string) ($_POST['field'] ?? '');
        $raw   = (string) ($_POST['raw_value'] ?? '');
        $vocab = $module->vocabulary();
        if ($vocab === null || !isset($profile[$field])) {
            $respond(['ok' => false, 'error' => $module->ttText('ed_vocab_none')], 400);
        }
        $spec   = $store->spec($field);
        $domain = $spec['domain'] ?? $profile[$field]['suggested_domain'];
        $label  = $raw !== '' ? (string) ($profile[$field]['choices'][$raw] ?? $raw) : $profile[$field]['field_label'];
        $strategy = $spec['value_strategy'];
        if ($raw !== '' && DictionaryProfiler::isBinaryLabel($label) && $strategy !== MappingStore::ST_VALUE_CONCEPT) {
            $label = $profile[$field]['field_label'];   // "Yes" is not searched for: the question is
        }
        $q = $module->athenaQuery($label ?: $field, $field,
            $module->athenaDomain($domain) === 'Measurement' && $raw === '' ? (string) ($spec['unit_source_value'] ?? '') : '');
        $dom = ($raw !== '' && $strategy === MappingStore::ST_VALUE_CONCEPT) ? 'Meas Value' : $module->athenaDomain($domain);
        $res = $vocab->search($q, $dom ?: null, 8);
        if ($res === null) {
            $respond(['ok' => false, 'error' => $module->ttText('ed_vocab_error', (string) $vocab->lastError())], 502);
        }
        // With no results in the domain, try without filtering: some noise is
        // better than nothing at all.
        if (!$res && $dom) {
            $res = $vocab->search($q, null, 8) ?? [];
        }
        $respond(['ok' => true, 'query' => $q, 'domain' => $dom, 'results' => $res]);
    }

    if ($action === 'import-mapping') {
        $doc = json_decode((string) ($_POST['json'] ?? ''), true);
        if (!is_array($doc) || !isset($doc['fields'])) {
            $respond(['ok' => false, 'error' => $module->ttText('ed_import_bad')], 400);
        }
        $r = $store->importMapping($doc, $profile, $dictionary);
        $respond(['ok' => true, 'imported' => $r['imported'], 'skipped' => $r['skipped'],
                  'counts' => $module->mappingCounts()]);
    }

    if ($action === 'reset-everything-preview') {
        $respond(['ok' => true, 'impact' => $store->resetImpact()]);
    }

    if ($action === 'reset-everything') {
        // Double confirmation: the first screen shows what is lost, and this
        // one demands the word. It is also checked here: a client that skips
        // the prompt() must not be able to erase the mapping with just confirmed=1.
        $word = $module->ttText('ed_reset_all_word');
        if (empty($_POST['confirmed'])
            || mb_strtoupper(trim((string) ($_POST['word'] ?? ''))) !== mb_strtoupper($word)) {
            $respond(['ok' => false, 'error' => $module->ttText('ed_reset_all_unconfirmed')], 400);
        }
        $summary = $store->reset();
        $respond(['ok' => true, 'cleared' => $summary, 'counts' => $module->mappingCounts()]);
    }

    if ($action === 'restore-backup') {
        $n = $store->restoreBackup();
        if ($n === null) {
            $respond(['ok' => false, 'error' => $module->ttText('ed_restore_none')], 400);
        }
        $respond(['ok' => true, 'restored' => $n, 'counts' => $module->mappingCounts()]);
    }

    if ($action === 'reset-all-preview' || $action === 'reset-all') {
        $rows    = $module->mappingRows();
        $changes = [];
        foreach ($profile as $name => $row) {
            // What is approved is not touched: reviewing a proposal cannot undo
            // a decision someone signed.
            if (($rows[$name]['state'] ?? '') === MappingStore::S_APPROVED) {
                continue;
            }
            $currentDomain = $rows[$name]['spec']['domain'] ?? null;
            if ($currentDomain !== null && $currentDomain !== $row['suggested_domain']) {
                $changes[$name] = $currentDomain . ' → ' . $row['suggested_domain'];
            }
        }

        if ($action === 'reset-all-preview') {
            $respond(['ok' => true, 'changes' => $changes, 'count' => count($changes)]);
        }

        foreach (array_keys($changes) as $name) {
            $store->resetToProposal($name, $profile[$name]['suggested_domain']);
        }
        $respond(['ok' => true, 'changed' => count($changes), 'counts' => $module->mappingCounts()]);
    }

    if ($action === 'bulk' || $action === 'bulk-preview') {
        $which   = (string) ($_POST['which'] ?? '');
        $rows    = $module->mappingRows();
        $targets = [];

        foreach ($profile as $name => $row) {
            $state = $rows[$name]['state'] ?? MappingStore::S_PROPOSED;

            // Bulk exclusions only act on what is pending. What is approved is
            // signed work and what is flagged for review is work someone has to
            // look at: no bulk action overwrites it.
            if ($state !== MappingStore::S_PROPOSED) {
                continue;
            }
            $hit = false;
            if ($which === 'exclude-notmapped') {
                $dom = $rows[$name]['spec']['domain'] ?? $row['suggested_domain'];
                $hit = $dom === DictionaryProfiler::D_SKIP;
            } elseif ($which === 'exclude-manual') {
                $hit = $row['mappable'] === 'manual';
            } elseif ($which === 'exclude-pending') {
                $hit = true;
            } elseif ($which === 'adopt-annotations') {
                $hit = empty($rows[$name]['spec']['edited']) && $module->annotation($name) !== null;
            } elseif ($which === 'approve-ready') {
                // Bulk-approve only what a person has already prepared (it has
                // a concept or stored decisions) and has no warning that calls
                // for a look. Writing does not approve; this is an explicit
                // approval of what is already complete and clean.
                $spec = $rows[$name]['spec'];
                if (empty($spec['edited'])
                    || in_array($spec['domain'], [null, DictionaryProfiler::D_SKIP,
                                                  DictionaryProfiler::D_DATE_ANCHOR], true)) {
                    continue;
                }
                $ok = $store->readyToApprove($name, $row['choices'] ?? [], $dictionary[$name] ?? []);
                $w  = $module->advise($name);
                $hit = $ok['ok'] && !MappingAdvisor::blocks($w) && !MappingAdvisor::needsConfirm($w);
            }

            if ($hit) {
                $targets[] = $name;
            }
        }

        if ($action === 'bulk-preview') {
            $respond(['ok' => true, 'fields' => $targets, 'count' => count($targets)]);
        }

        if ($which === 'adopt-annotations') {
            $n = 0;
            foreach ($targets as $name) {
                $n += $store->adoptAnnotation($name, $module->annotation($name),
                    $profile[$name]['choices'] ?? [], $dictionary) ? 1 : 0;
            }
            $module->log('@OMOP annotations adopted as proposal', ['count' => $n]);
            $respond(['ok' => true, 'changed' => $n, 'counts' => $module->mappingCounts()]);
        }

        if ($which === 'approve-ready') {
            foreach ($targets as $name) {
                if (MappingStore::usesOptions($rows[$name]['spec']['value_strategy'])) {
                    $store->materialiseValues($name, $profile[$name]['choices'] ?? [], $dictionary[$name] ?? []);
                }
            }
            $n = $store->bulkState($targets, MappingStore::S_APPROVED, $dictionary);
        } else {
            $n = $store->bulkState($targets, MappingStore::S_EXCLUDED);
        }
        $respond(['ok' => true, 'changed' => $n, 'counts' => $module->mappingCounts()]);
    }

        $respond(['ok' => false, 'error' => $module->ttText('ed_err_unknown_action', $action)], 400);

    } catch (\Throwable $e) {
        // A server failure has to reach the browser as JSON, not as an error
        // page: otherwise the editor can only say "network error" and there is
        // no way of knowing what happened.
        $module->log('Editor error', [
            'action' => $action,
            'error'  => $e->getMessage(),
            'file'   => basename($e->getFile()) . ':' . $e->getLine(),
        ]);
        $respond([
            'ok'    => false,
            'error' => $e->getMessage(),
            'where' => basename($e->getFile()) . ':' . $e->getLine(),
        ], 500);
    }
}

// ------------------------------------------------------------------- Rendering

require_once APP_PATH_DOCROOT . 'ProjectGeneral/header.php';

$canEdit = $module->userCanEditMapping();
$counts  = $module->mappingCounts();
$rows    = $module->mappingRows();
$dateFields = $module->dateFields();
$backup  = $canEdit ? $store->backupInfo() : null;
$problems = $module->configProblems();
$vocab   = $module->vocabulary();
$vocabOn = $vocab !== null;
$annotated = count(array_filter(array_keys($profile), fn($f) => $module->annotation($f) !== null));

$domains = OmopCdmExportModule::validDomains();

$stateBadge = [
    MappingStore::S_PROPOSED => 'badge-secondary',
    MappingStore::S_APPROVED => 'badge-success',
    MappingStore::S_EXCLUDED => 'badge-dark',
    MappingStore::S_REVIEW   => 'badge-danger',
    MappingStore::S_CONFIG   => 'badge-info',
];
$h = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$t = fn(string $k, ...$a) => $h($module->ttText($k, ...$a));
?>

<style>
    .omop-bar { height: 10px; background: #e9ecef; border-radius: 5px; overflow: hidden; max-width: 640px; }
    .omop-bar span { display: block; height: 100%; background: #28a745; transition: width .3s; }
    .omop-counts { font-size: 13px; color: #6c757d; margin: 6px 0 14px; }
    .omop-counts b { color: #212529; }
    #omop-editor td { vertical-align: top; font-size: 12px; padding: 6px; }
    #omop-editor input, #omop-editor select { font-size: 12px; padding: 2px 4px; }
    #omop-editor .in-id { width: 88px; }
    #omop-editor .in-name { width: 100%; margin-top: 3px; }
    #omop-editor .in-unit { width: 80px; }
    .omop-field code { font-size: 12px; }
    .omop-label { color: #6c757d; display: block; max-width: 260px; }
    .omop-saved { color: #28a745; font-size: 11px; margin-left: 6px; opacity: 0; transition: opacity .2s; }
    .omop-saved.on { opacity: 1; }
    .row-excluded { opacity: .5; }
    .text-warning-dark { color: #b8860b; }
    .omop-toolbar { margin: 10px 0; display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
    .omop-vc-note { font-size: 10.5px; color: #6c757d; margin: 2px 0; }
</style>

<h3 style="margin-top:6px">
    <i class="fas fa-clipboard-check"></i> <?= $t('editor_title') ?>
</h3>

<?php if (!$canEdit): ?>
    <div class="alert alert-warning" style="max-width:780px"><?= $t('ed_readonly') ?></div>
<?php endif; ?>

<?php if ($problems): ?>
    <div class="alert alert-warning" style="max-width:900px">
        <?= $t('ed_config_missing') ?>
        <ul style="margin:6px 0 0">
            <?php foreach ($problems as $p): ?><li><?= $t($p) ?></li><?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<?php if ($backup): ?>
    <div class="alert alert-info" style="max-width:900px">
        <?= $t('ed_restore_available', $backup['saved_at'], $backup['saved_by'], $backup['fields']) ?>
        <button class="btn btn-defaultrc btn-xs" onclick="omopRestore()"><i class="fas fa-undo"></i> <?= $t('ed_btn_restore') ?></button>
    </div>
<?php endif; ?>

<p class="text-muted" style="max-width:840px"><?= $t('ed_no_vocab_help') ?></p>
<p style="max-width:840px; font-size:12.5px">
    <?php if ($vocabOn): ?>
        <i class="fas fa-book text-success"></i> <?= $t('ed_vocab_on', $vocab->source()) ?>
    <?php else: ?>
        <i class="fas fa-book text-muted"></i> <span class="text-muted"><?= $t('ed_vocab_off') ?></span>
    <?php endif; ?>
    <?php if ($annotated > 0): ?>
        <br><i class="fas fa-tag text-info"></i> <?= $t('ed_annotations_found', $annotated) ?>
    <?php endif; ?>
</p>

<div class="omop-bar"><span id="omop-progress" style="width:0%"></span></div>
<div class="omop-counts" id="omop-counts"></div>

<div class="omop-toolbar">
    <button class="btn btn-success btn-sm" onclick="omopBulkAsk('approve-ready')" <?= $canEdit ? '' : 'disabled' ?>
            title="<?= $t('ed_bulk_approve_title') ?>">
        <i class="fas fa-check-double"></i> <?= $t('ed_bulk_approve') ?>
    </button>
    <?php if ($annotated > 0): ?>
    <button class="btn btn-info btn-sm" onclick="omopBulkAsk('adopt-annotations')" <?= $canEdit ? '' : 'disabled' ?>
            title="<?= $t('ed_bulk_adopt_title') ?>">
        <i class="fas fa-tag"></i> <?= $t('ed_bulk_adopt') ?>
    </button>
    <?php endif; ?>
    <label class="btn btn-defaultrc btn-sm" style="margin:0" title="<?= $t('ed_import_title') ?>"
           <?= $canEdit ? '' : 'disabled' ?>>
        <i class="fas fa-file-import"></i> <?= $t('ed_import') ?>
        <input type="file" accept=".json,application/json" style="display:none" onchange="omopImport(this)"
               <?= $canEdit ? '' : 'disabled' ?>>
    </label>
    <button class="btn btn-defaultrc btn-sm" onclick="omopBulkAsk('exclude-notmapped')" <?= $canEdit ? '' : 'disabled' ?>>
        <?= $t('ed_bulk_notmapped') ?>
    </button>
    <button class="btn btn-defaultrc btn-sm" onclick="omopBulkAsk('exclude-manual')" <?= $canEdit ? '' : 'disabled' ?>>
        <?= $t('ed_bulk_manual') ?>
    </button>
    <button class="btn btn-defaultrc btn-sm" onclick="omopBulkAsk('exclude-pending')" <?= $canEdit ? '' : 'disabled' ?>>
        <?= $t('ed_bulk_pending') ?>
    </button>
    <button class="btn btn-defaultrc btn-sm" onclick="omopResetAll()" <?= $canEdit ? '' : 'disabled' ?>>
        <?= $t('ed_bulk_reset') ?>
    </button>
    <button class="btn btn-danger btn-sm" onclick="omopResetEverything()"
            title="<?= $t('ed_reset_all_title') ?>" <?= $canEdit ? '' : 'disabled' ?>>
        <i class="fas fa-trash-alt"></i> <?= $t('ed_btn_reset_all') ?>
    </button>
    <select id="omop-filter" class="form-control form-control-sm" style="width:190px" onchange="omopFilter()">
        <option value=""><?= $t('ed_filter_all') ?></option>
        <option value="proposed"><?= $t('ed_state_proposed') ?></option>
        <option value="approved"><?= $t('ed_state_approved') ?></option>
        <option value="excluded"><?= $t('ed_state_excluded') ?></option>
        <option value="review"><?= $t('ed_state_review') ?></option>
        <option value="config"><?= $t('ed_state_config') ?></option>
    </select>
    <input type="text" id="omop-search" class="form-control form-control-sm" style="width:220px"
           placeholder="<?= $t('filter_placeholder') ?>" oninput="omopFilter()">
</div>

<table class="table table-sm table-bordered" id="omop-editor">
    <thead class="thead-light">
        <tr>
            <th style="width:22%"><?= $t('th_field') ?></th>
            <th style="width:13%"><?= $t('ed_th_domain') ?></th>
            <th style="width:30%"><?= $t('ed_th_concept') ?></th>
            <th style="width:13%"><?= $t('ed_th_datefield') ?></th>
            <th style="width:8%"><?= $t('lbl_unit') ?></th>
            <th style="width:14%"><?= $t('ed_th_state') ?></th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $name => $r):
        $spec   = $r['spec'];
        $prof   = $r['profile'];
        $state  = $r['state'];
        $edited = !empty($spec['edited']);
        // An untouched field with an @OMOP annotation shows the annotation as
        // the proposal: it is what will be saved with the first change.
        $ann = $edited ? null : $module->annotation($name);
        if ($ann !== null) {
            $spec = array_merge($spec, $ann['spec']);
            $r['values'] = $ann['values'] + $r['values'];
        }
        $shownStored = $edited || $ann !== null;
        // With nothing stored the proposal is shown; with something stored,
        // what is stored as is. That way what is seen is always what would be
        // approved.
        $cur = $spec['domain'] ?? $prof['suggested_domain'];
        // Two links: one restricted to the domain's usual vocabulary, which is
        // what works for analytes and drugs, and another unrestricted for when
        // the concept lives in another vocabulary (pain, symptoms...).
        $athDomain = $module->athenaDomain($cur);
        $athVocab  = $module->athenaVocabulary($cur);
        // The LOINC property only makes sense for measurements: it does not
        // exist for a condition or a drug, and adding it would narrow the
        // search for no reason.
        $athUnit   = ($athDomain === 'Measurement')
            ? (string) ($spec['unit_source_value'] ?? $prof['unit_hint'] ?? '')
            : '';
        $athQuery  = $module->athenaQuery($prof['field_label'] ?: $name, $name, $athUnit);

        // The main button searches WITHOUT filtering by vocabulary. The filter
        // helps when it is right, but when it is not, it leaves zero results
        // and blocks the curator; having no results is worse than some noise.
        $athena        = $module->athenaUrl($athQuery, $athDomain);
        $athenaByVocab = $module->athenaUrl($athQuery, $athDomain, $athVocab);

        // A field that does not reach the CDM needs no concept, value strategy
        // or date: asking for them invites useless work. The reason is shown
        // and that is all.
        $isConfig = $state === MappingStore::S_CONFIG;
        $notExportable = $isConfig || in_array($cur, [DictionaryProfiler::D_SKIP, DictionaryProfiler::D_DATE_ANCHOR], true);
        $hasChoices = !$notExportable && !empty($prof['choices']);
        $strategy  = ($edited || !empty($spec['value_strategy'])) ? $spec['value_strategy']
            : DictionaryProfiler::suggestedStrategy($prof['value_kind'], $prof['choices'], $cur);
        $perChoice = $hasChoices && MappingStore::usesOptions($strategy);
        $valueConc = $hasChoices && $strategy === MappingStore::ST_VALUE_CONCEPT;
        $shownDate = ($edited || ($ann !== null && array_key_exists('date_field', $ann['spec'])))
            ? (string) ($spec['date_field'] ?? '')
            : (string) ($module->suggestedDateField($name, $cur) ?? '');
        $shownUnit = ($edited || !empty($spec['unit_source_value']))
            ? (string) ($spec['unit_source_value'] ?? '') : (string) ($prof['unit_hint'] ?? '');
        $hasEnd = in_array($cur, ['Condition', 'Drug', 'Procedure', 'Device', 'Visit'], true);
    ?>
        <tr data-field="<?= $h($name) ?>" data-state="<?= $h($state) ?>"
            class="<?= $state === MappingStore::S_EXCLUDED ? 'row-excluded' : '' ?>">
            <td class="omop-field">
                <code><?= $h($name) ?></code>
                <?php if ($prof['mappable'] === 'manual'): ?>
                    <span class="badge badge-warning"><?= $t('badge_manual') ?></span>
                <?php endif; ?>
                <?php if ($prof['is_identifier']): ?>
                    <span class="badge badge-danger"><?= $t('badge_phi') ?></span>
                <?php endif; ?>
                <?php if ($ann !== null): ?>
                    <span class="badge badge-info" title="<?= $t('ed_badge_annotation_title') ?>">@OMOP</span>
                <?php elseif (in_array($spec['source'] ?? '', ['annotation', 'import'], true) && $state !== MappingStore::S_APPROVED): ?>
                    <span class="badge badge-light" style="border:1px solid #ced4da"><?= $t('ed_badge_' . $spec['source']) ?></span>
                <?php endif; ?>
                <span class="omop-label"><?= $h(mb_strimwidth($prof['field_label'], 0, 70, '…')) ?></span>
            </td>
            <td>
                <select class="form-control form-control-sm omop-domain" data-k="domain" data-cur="<?= $h($cur) ?>"
                        <?= $canEdit ? '' : 'disabled' ?>></select>
            </td>
            <td>
                <?php if ($hasChoices): ?>
                    <select class="form-control form-control-sm" data-k="value_strategy"
                            style="margin-bottom:4px" <?= $canEdit ? '' : 'disabled' ?>>
                        <option value="per_choice" <?= $strategy === 'per_choice' ? 'selected' : '' ?>>
                            <?= $t('ed_strategy_per_choice', count($prof['choices'])) ?></option>
                        <option value="value_concept" <?= $strategy === 'value_concept' ? 'selected' : '' ?>>
                            <?= $t('ed_strategy_value_concept') ?></option>
                        <option value="single_concept" <?= !$perChoice ? 'selected' : '' ?>>
                            <?= $t('ed_strategy_single') ?></option>
                    </select>
                <?php endif; ?>
                <?php if ($notExportable): ?>
                    <div class="text-muted" style="font-size:11.5px"><?= $isConfig ? $t('ed_role_' . $r['role']) : $h($prof['reason']) ?></div>
                <?php else: ?>
                <div class="omop-single" style="<?= ($perChoice && !$valueConc) ? 'display:none' : '' ?>">
                    <div class="omop-vc-note" style="<?= $valueConc ? '' : 'display:none' ?>"><?= $t('ed_value_concept_note') ?></div>
                    <input type="text" inputmode="numeric" class="form-control form-control-sm in-id"
                           data-k="concept_id"
                           placeholder="<?= $t('ed_concept_id_ph') ?>" value="<?= $h($spec['concept_id'] ?? '') ?>"
                           <?= $canEdit ? '' : 'disabled' ?>>
                    <a class="btn btn-defaultrc btn-xs" href="<?= $h($athena) ?>" target="_blank"
                       rel="noopener" title="<?= $t('ed_athena_title', $athQuery) ?>">
                        <i class="fas fa-external-link-alt"></i> Athena
                    </a>
                    <?php if ($athVocab !== ''): ?>
                        <a class="btn btn-defaultrc btn-xs" href="<?= $h($athenaByVocab) ?>" target="_blank"
                           rel="noopener" title="<?= $t('ed_athena_vocab_title', $athVocab) ?>"><?= $h($athVocab) ?></a>
                    <?php endif; ?>
                    <?php if (!empty($spec['concept_id'])): ?>
                        <a class="btn btn-defaultrc btn-xs" target="_blank" rel="noopener"
                           href="https://athena.ohdsi.org/search-terms/terms/<?= (int) $spec['concept_id'] ?>"
                           title="<?= $t('ed_verify_title') ?>"><i class="fas fa-check-double"></i></a>
                    <?php endif; ?>
                    <?php if ($vocabOn && $canEdit): ?>
                        <button type="button" class="btn btn-defaultrc btn-xs" onclick="omopSuggest(this, '')"
                                title="<?= $t('ed_suggest_title') ?>"><i class="fas fa-magic"></i></button>
                    <?php endif; ?>
                    <input type="text" class="form-control form-control-sm in-name" data-k="concept_name"
                           placeholder="<?= $t('ed_concept_name_ph') ?>"
                           value="<?= $h($spec['concept_name'] ?? '') ?>"
                           <?= $canEdit ? '' : 'disabled' ?>>
                    <div class="omop-sugg"></div>
                </div>
                <?php endif; ?>
                <?php if ($hasChoices): ?>
                <table class="table table-sm omop-choices" style="<?= $perChoice ? '' : 'display:none' ?>; margin:4px 0 0">
                    <?php foreach ($prof['choices'] as $rawV => $labelV):
                        $v = $r['values'][(string) $rawV]
                             ?? MappingStore::blankValueSpec((string) $rawV, (string) $labelV,
                                    DictionaryProfiler::isNegativeChoice($dictionary[$name] ?? [], (string) $rawV));
                        // Each option is searched for by its own label: in a
                        // comorbidities dropdown, "Previous stroke" and
                        // "Ischaemic heart disease" are different concepts.
                        // Except in binary fields: searching for "yes" leads
                        // nowhere. There the concept is defined by the question.
                        // With "value per option" the answer is searched for in
                        // the Meas Value domain, which is where Positive,
                        // Negative, Detected... live.
                        $optQuery = $module->athenaQuery(
                            DictionaryProfiler::isBinaryLabel((string) $labelV) && !$valueConc
                                ? ($prof['field_label'] ?: $name)
                                : (string) $labelV,
                            (string) $labelV, $valueConc ? '' : $athUnit);
                        $optDomain = $valueConc ? 'Meas Value' : $athDomain; ?>
                        <tr data-raw="<?= $h($rawV) ?>">
                            <td style="width:34%; border:none; padding:2px 4px">
                                <code><?= $h($rawV) ?></code>
                                <?= $h(mb_strimwidth($labelV, 0, 26, '…')) ?>
                            </td>
                            <td style="width:14%; border:none; padding:2px 4px">
                                <label style="font-weight:normal; margin:0; white-space:nowrap">
                                    <input type="checkbox" data-vk="emit" <?= $v['emit'] ? 'checked' : '' ?>
                                           <?= $canEdit ? '' : 'disabled' ?>>
                                    <?= $t('ed_emit') ?>
                                </label>
                            </td>
                            <td style="border:none; padding:2px 4px">
                                <input type="text" inputmode="numeric" class="form-control form-control-sm in-id"
                                       data-vk="concept_id"
                                       placeholder="<?= $t('ed_concept_id_ph') ?>" value="<?= $h($v['concept_id'] ?? '') ?>"
                                       <?= $canEdit ? '' : 'disabled' ?>>
                                <a class="btn btn-defaultrc btn-xs" target="_blank" rel="noopener"
                                   href="<?= $h($module->athenaUrl($optQuery, $optDomain)) ?>"
                                   title="<?= $t('ed_athena_title', $optQuery) ?>">
                                    <i class="fas fa-external-link-alt"></i> Athena
                                </a>
                                <?php if (!empty($v['concept_id'])): ?>
                                    <a class="btn btn-defaultrc btn-xs" target="_blank" rel="noopener"
                                       href="https://athena.ohdsi.org/search-terms/terms/<?= (int) $v['concept_id'] ?>"
                                       title="<?= $t('ed_verify_title') ?>"><i class="fas fa-check-double"></i></a>
                                <?php endif; ?>
                                <?php if ($vocabOn && $canEdit): ?>
                                    <button type="button" class="btn btn-defaultrc btn-xs" onclick="omopSuggest(this, <?= $h(json_encode((string) $rawV)) ?>)"
                                            title="<?= $t('ed_suggest_title') ?>"><i class="fas fa-magic"></i></button>
                                <?php endif; ?>
                                <input type="text" class="form-control form-control-sm" style="width:150px; display:inline-block"
                                       data-vk="concept_name" placeholder="<?= $t('ed_concept_name_ph') ?>"
                                       value="<?= $h($v['concept_name'] ?? '') ?>"
                                       <?= $canEdit ? '' : 'disabled' ?>>
                                <div class="omop-sugg"></div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </table>
                <?php endif; ?>
            </td>
            <td>
                <?php if ($notExportable): ?>
                    <span class="text-muted" style="font-size:11.5px">—</span>
                <?php else: ?>
                <?php // The options are filled in by the browser from OMOP.dateFields:
                      // repeating the list on every row made a 500-field project
                      // weigh 6 MB. ?>
                <select class="form-control form-control-sm omop-date" data-k="date_field"
                        data-shown="<?= $h($shownDate) ?>" data-form="<?= $h($dictionary[$name]['form_name'] ?? '') ?>"
                        <?= $canEdit ? '' : 'disabled' ?>></select>
                <?php if (!$shownStored && $shownDate !== ''): ?>
                    <div class="reason" style="font-size:10px; color:#6c757d"><?= $t('ed_date_suggested') ?></div>
                <?php endif; ?>
                <div class="omop-end" style="margin-top:4px; <?= $hasEnd ? '' : 'display:none' ?>">
                    <span style="font-size:10.5px; color:#6c757d"><?= $t('ed_end_date') ?></span>
                    <select class="form-control form-control-sm omop-date" data-k="end_date_field" data-end="1"
                            data-shown="<?= $h($spec['end_date_field'] ?? '') ?>"
                            data-form="<?= $h($dictionary[$name]['form_name'] ?? '') ?>"
                            <?= $canEdit ? '' : 'disabled' ?>></select>
                </div>
                <?php endif; ?>
            </td>
            <td>
                <?php if (!$notExportable): ?>
                <input type="text" class="form-control form-control-sm in-unit" data-k="unit_source_value"
                       value="<?= $h($shownUnit) ?>" <?= $canEdit ? '' : 'disabled' ?>>
                <?php endif; ?>
            </td>
            <td>
                <span class="badge <?= $stateBadge[$state] ?? 'badge-secondary' ?> omop-state">
                    <?= $t('ed_state_' . $state) ?>
                </span>
                <span class="omop-saved"><i class="fas fa-check"></i></span>
                <?php if ($canEdit): ?>
                    <div style="margin-top:4px">
                        <button class="btn btn-success btn-xs" onclick="omopState(this,'approved')"><?= $t('ed_btn_approve') ?></button>
                        <button class="btn btn-defaultrc btn-xs" onclick="omopState(this,'excluded')"><?= $t('ed_btn_exclude') ?></button>
                        <button class="btn btn-defaultrc btn-xs" onclick="omopState(this,'proposed')"><?= $t('ed_btn_reopen') ?></button>
                        <?php if (($spec['domain'] ?? null) !== null
                                  && $spec['domain'] !== $prof['suggested_domain']): ?>
                            <button class="btn btn-warning btn-xs" onclick="omopReset(this)"
                                    title="<?= $t('ed_reset_title', $module->domainLabel($prof['suggested_domain'])) ?>">
                                <i class="fas fa-undo"></i> <?= $t('ed_btn_reset') ?>
                            </button>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
                <div class="omop-warnings" style="font-size:11px; margin-top:4px; max-width:230px">
                    <?php foreach ($module->advise($name) as $w):
                        $cls = $w['severity'] === 'block' ? 'text-danger'
                             : ($w['severity'] === 'warn' ? 'text-warning-dark' : 'text-muted');
                        $icon = $w['severity'] === 'info' ? 'fa-info-circle' : 'fa-exclamation-triangle'; ?>
                        <div class="<?= $cls ?>"><i class="fas <?= $icon ?>"></i> <?= $h($w['text']) ?></div>
                    <?php endforeach; ?>
                </div>
                <?php if ($r['drifted']): ?>
                    <div class="text-danger" style="font-size:11px; margin-top:4px">
                        <?= $t('ed_drift_' . ($r['drift_reason'] ?? 'dictionary')) ?>
                    </div>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<script>
var OMOP = <?= json_encode([
    'url'        => $module->getUrl('pages/editor.php'),
    'csrf'       => $csrf,
    'dateFields' => array_map(fn($n) => [$n, $dictionary[$n]['form_name'] ?? ''], array_keys($dateFields)),
    'stateLabels' => [
        'proposed' => $module->ttText('ed_state_proposed'),
        'approved' => $module->ttText('ed_state_approved'),
        'excluded' => $module->ttText('ed_state_excluded'),
        'review'   => $module->ttText('ed_state_review'),
        'config'   => $module->ttText('ed_state_config'),
    ],
    'counts'          => $counts,
    'tpl'             => $module->ttText('ed_counts'),
    'resetAllNone'    => $module->ttText('ed_reset_all_none'),
    'resetAllConfirm' => $module->ttText('ed_reset_all_confirm'),
    'resetAllPrompt'  => $module->ttText('ed_reset_all_prompt'),
    'resetAllWord'    => $module->ttText('ed_reset_all_word'),
    'resetAllAborted' => $module->ttText('ed_reset_all_aborted'),
    'resetNone'       => $module->ttText('ed_reset_none'),
    'resetNoneAll'    => $module->ttText('ed_reset_none_all'),
    'resetConfirm'    => $module->ttText('ed_reset_confirm'),
    'restoreConfirm'  => $module->ttText('ed_restore_confirm'),
    'bulkConfirm'     => [
        'approve-ready'     => $module->ttText('ed_bulk_confirm_approve'),
        'exclude-notmapped' => $module->ttText('ed_bulk_confirm_notmapped'),
        'exclude-manual'    => $module->ttText('ed_bulk_confirm_manual'),
        'exclude-pending'   => $module->ttText('ed_bulk_confirm'),
        'adopt-annotations' => $module->ttText('ed_bulk_confirm_adopt'),
    ],
    'noEnd'          => $module->ttText('ed_end_none'),
    'suggestNone'    => $module->ttText('ed_suggest_none'),
    'importDone'     => $module->ttText('ed_import_done'),
    'importSkipped'  => $module->ttText('ed_import_skipped'),
    'endDomains'     => ['Condition', 'Drug', 'Procedure', 'Device', 'Visit'],
    'bulkMore'   => $module->ttText('ed_bulk_more'),
    'bulkNone'   => $module->ttText('ed_bulk_none'),
    'netMsg'     => $module->ttText('ed_err_network'),
    'sessionMsg' => $module->ttText('ed_err_session'),
    'badMsg'     => $module->ttText('ed_err_badresponse'),
    'blockedMsg' => $module->ttText('adv_blocked'),
    'confirmMsg' => $module->ttText('adv_confirm'),
    'confirmAsk' => $module->ttText('adv_confirm_ask'),
    'useDefault' => $module->ttText('ed_date_use_default'),
    'idHelp'     => $module->ttText('ed_concept_id_help'),
    'domains'    => array_map(fn($d) => [$d, $module->domainLabel($d)], $domains),
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
OMOP.badges = {proposed:'badge-secondary', approved:'badge-success', excluded:'badge-dark', review:'badge-danger', config:'badge-info'};
OMOP.redcapCsrf = (function () {
    var el = document.querySelector('input[name="redcap_csrf_token"]');
    return el ? el.value : (typeof redcap_csrf_token !== 'undefined' ? redcap_csrf_token : '');
})();

/**
 * Fills in the controls repeated on every row (domains, concept_id help)
 * from a single copy. In a 500-field project, rendering the twelve domains
 * and the help on every row amounted to half a megabyte.
 */
function omopFillShared() {
    document.querySelectorAll('select.omop-domain').forEach(function (sel) {
        OMOP.domains.forEach(function (d) {
            var opt = document.createElement('option');
            opt.value = d[0];
            opt.textContent = d[1];
            if (d[0] === sel.dataset.cur) { opt.selected = true; }
            sel.appendChild(opt);
        });
    });
    document.querySelectorAll('[data-k="concept_id"],[data-vk="concept_id"]').forEach(function (el) {
        el.title = OMOP.idHelp;
    });
}

/** Fills in the date selectors from a single shared list. */
function omopFillDates() {
    document.querySelectorAll('select.omop-date').forEach(function (sel) {
        var shown = sel.dataset.shown, form = sel.dataset.form;
        var o = document.createElement('option');
        o.value = ''; o.textContent = '— ' + (sel.dataset.end ? OMOP.noEnd : OMOP.useDefault);
        sel.appendChild(o);
        OMOP.dateFields.forEach(function (df) {
            var opt = document.createElement('option');
            opt.value = df[0];
            opt.textContent = df[0] + (df[1] === form ? ' ✓' : '');
            if (df[0] === shown) { opt.selected = true; }
            sel.appendChild(opt);
        });
    });
}

function omopRender(counts) {
    OMOP.counts = counts;
    // What is excluded and what the configuration consumes does not count as pending work.
    var pct = counts.total ? Math.round(100 * counts.approved / Math.max(1, counts.total - counts.excluded - (counts.config || 0))) : 0;
    document.getElementById('omop-progress').style.width = pct + '%';
    document.getElementById('omop-counts').innerHTML = OMOP.tpl
        .replace('{0}', '<b>' + counts.approved + '</b>')
        .replace('{1}', '<b>' + counts.total + '</b>')
        .replace('{2}', counts.proposed)
        .replace('{3}', counts.excluded)
        .replace('{4}', counts.review)
        .replace('{5}', counts.config || 0);
}

/**
 * Request queue.
 *
 * Requests are chained because order matters: clicking Approve right after
 * typing a concept fires the concept save and the approval almost at once,
 * and if the approval arrives first the server evaluates an incomplete state.
 */
var omopQueue = Promise.resolve();

function omopPost(data, done) {
    omopQueue = omopQueue.then(function () {
        return omopSend(data, done);
    });
    return omopQueue;
}

/**
 * Sends an action to the backend.
 *
 * Reads the response as text and parses it by hand, instead of using
 * r.json(): when the server returns HTML (a PHP error page, an expired
 * session notice), r.json() only says "not JSON" and the user is left not
 * knowing what happened. Here the beginning of the real response is shown.
 */
function omopSend(data, done) {
    data.csrf = OMOP.csrf;
    if (OMOP.redcapCsrf) { data.redcap_csrf_token = OMOP.redcapCsrf; }

    var body = Object.keys(data).map(function (k) {
        return encodeURIComponent(k) + '=' + encodeURIComponent(data[k] === null ? '' : data[k]);
    }).join('&');

    var status = 0;
    return fetch(OMOP.url, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: body,
        credentials: 'same-origin'
    }).then(function (r) {
        status = r.status;
        return r.text();
    }).then(function (text) {
        var j;
        try {
            j = JSON.parse(text);
        } catch (e) {
            omopReportBadResponse(status, text);
            return;
        }
        if (!j.ok) {
            alert(j.error || ('HTTP ' + status) );
            if (j.where) { console.error('OMOP backend:', j.error, 'at', j.where); }
            return;
        }
        if (j.counts) { omopRender(j.counts); }
        if (done) { done(j); }
    }).catch(function (e) {
        alert(OMOP.netMsg + '\n\n' + e);
    });
}

/** The server answered something that is not JSON: show what, without guessing. */
function omopReportBadResponse(status, text) {
    console.error('OMOP: non-JSON response, HTTP ' + status + '\n' + text);

    var clean = (text || '').replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim();
    var msg;
    if (status === 0 || text === '') {
        msg = OMOP.netMsg;
    } else if (/log ?in|sign ?in|session|sesi/i.test(clean) && clean.length < 400) {
        msg = OMOP.sessionMsg;
    } else {
        msg = OMOP.badMsg.replace('{0}', status) + '\n\n' + clean.substring(0, 300);
    }
    alert(msg);
}

function omopRow(el) { return el.closest('tr[data-field]'); }

function omopFlash(tr) {
    var s = tr.querySelector('.omop-saved');
    s.classList.add('on');
    setTimeout(function () { s.classList.remove('on'); }, 900);
}

function omopApplyState(tr, state) {
    tr.dataset.state = state;
    var b = tr.querySelector('.omop-state');
    b.className = 'badge omop-state ' + (OMOP.badges[state] || 'badge-secondary');
    b.textContent = OMOP.stateLabels[state];
    tr.classList.toggle('row-excluded', state === 'excluded');
}

/**
 * Saves a control when it changes. The first save of a field also sends what
 * is shown preselected, so that it is stored exactly as seen.
 */
function omopSave(input) {
    var tr = omopRow(input);
    var data = {action: 'save-field', field: tr.dataset.field};
    data[input.dataset.k] = input.value;
    omopPost(data, function (j) {
        omopApplyState(tr, j.spec.state);
        omopFillName(tr.querySelector('[data-k="concept_name"]'), j.spec.concept_name);
        omopShowWarnings(tr, j.warnings || []);
        omopFlash(tr);
    });
}

/** The server fills in the concept name from the vocabulary. */
function omopFillName(input, name) {
    if (input && name && !input.value) { input.value = name; }
}

/**
 * Vocabulary suggestions for a field or an option. One click copies the
 * number and the name and saves them as if they had been pasted.
 */
function omopSuggest(btn, raw) {
    var tr = omopRow(btn);
    var box = btn.parentNode.querySelector('.omop-sugg');
    box.textContent = '…';
    omopPost({action: 'suggest', field: tr.dataset.field, raw_value: raw}, function (j) {
        box.textContent = '';
        if (!j.results.length) { box.textContent = OMOP.suggestNone.replace('{0}', j.query); return; }
        j.results.forEach(function (c) {
            var a = document.createElement('a');
            a.href = '#';
            a.style.display = 'block';
            a.style.fontSize = '11px';
            a.textContent = c.id + ' — ' + c.name + ' [' + c.vocabulary + ' · ' + c.domain + ']';
            a.addEventListener('click', function (e) {
                e.preventDefault();
                var cont = raw === '' ? btn.closest('.omop-single') : btn.closest('tr[data-raw]');
                var key = raw === '' ? 'k' : 'vk';
                cont.querySelector('[data-' + key + '="concept_id"]').value = c.id;
                cont.querySelector('[data-' + key + '="concept_name"]').value = c.name;
                if (raw === '') {
                    omopSave(cont.querySelector('[data-k="concept_id"]'));
                    omopSave(cont.querySelector('[data-k="concept_name"]'));
                } else {
                    omopSaveValue(cont.querySelector('[data-vk="concept_id"]'));
                }
                box.textContent = '';
            });
            box.appendChild(a);
        });
    });
}

/** Imports another project's MAPEO.json. Everything arrives as a proposal. */
function omopImport(input) {
    var f = input.files && input.files[0];
    if (!f) { return; }
    var rd = new FileReader();
    rd.onload = function () {
        omopPost({action: 'import-mapping', json: rd.result}, function (j) {
            var skipped = Object.keys(j.skipped || {});
            alert(OMOP.importDone.replace('{0}', j.imported.length)
                  + (skipped.length ? '\n\n' + OMOP.importSkipped.replace('{0}', skipped.length) + '\n'
                     + skipped.slice(0, 15).map(function (k) { return k + ': ' + j.skipped[k]; }).join('\n') : ''));
            location.reload();
        });
    };
    rd.readAsText(f);
}

function omopSaveValue(input) {
    var tr = input.closest('tr[data-raw]');
    var host = omopRow(tr.parentNode);
    var data = {action: 'save-value', field: host.dataset.field, raw_value: tr.dataset.raw};
    tr.querySelectorAll('[data-vk]').forEach(function (i) {
        data[i.dataset.vk] = (i.type === 'checkbox') ? (i.checked ? '1' : '0') : i.value;
    });
    omopPost(data, function (j) {
        // The server reports the real state: leaving a box without changing
        // anything no longer withdraws the approval.
        omopApplyState(host, j.state);
        omopFillName(tr.querySelector('[data-vk="concept_name"]'), j.value && j.value.concept_name);
        if (j.changed) { omopFlash(host); }
    });
}

function omopStrategy(sel) {
    var tr = omopRow(sel);
    var v = sel.value;
    var box = tr.querySelector('.omop-choices');
    if (box) { box.style.display = (v === 'per_choice' || v === 'value_concept') ? '' : 'none'; }
    var single = tr.querySelector('.omop-single');
    if (single) { single.style.display = (v === 'per_choice') ? 'none' : ''; }
    var note = tr.querySelector('.omop-vc-note');
    if (note) { note.style.display = (v === 'value_concept') ? '' : 'none'; }
}

function omopState(btn, state) {
    // Force the blur of the field being edited: that way its save enters the
    // queue before the approval.
    if (document.activeElement && document.activeElement.blur) {
        document.activeElement.blur();
    }
    var tr = omopRow(btn);
    var data = {action: 'save-field', field: tr.dataset.field, state: state};
    tr.querySelectorAll('[data-k]').forEach(function (i) { data[i.dataset.k] = i.value; });
    omopPost(data, function (j) {
        if (j.error) { alert(j.error); }
        omopShowWarnings(tr, j.warnings || []);

        if (j.blocked) {
            alert(OMOP.blockedMsg + '\n\n' + omopWarnText(j.warnings, 'block'));
            omopApplyState(tr, j.spec.state);
            return;
        }
        if (j.confirm) {
            if (confirm(OMOP.confirmMsg + '\n\n' + omopWarnText(j.warnings, 'warn')
                        + '\n\n' + OMOP.confirmAsk)) {
                data.confirmed = '1';
                omopPost(data, function (j2) {
                    omopShowWarnings(tr, j2.warnings || []);
                    omopApplyState(tr, j2.spec.state);
                    omopFlash(tr);
                });
            } else {
                omopApplyState(tr, j.spec.state);
            }
            return;
        }
        omopApplyState(tr, j.spec.state);
        omopFlash(tr);
    });
}

function omopWarnText(warnings, severity) {
    return (warnings || []).filter(function (w) { return w.severity === severity; })
        .map(function (w) { return '• ' + w.text; }).join('\n');
}

/** Warnings arrive as plain text: they are rendered with textContent, without HTML. */
function omopShowWarnings(tr, warnings) {
    var box = tr.querySelector('.omop-warnings');
    if (!box) { return; }
    box.textContent = '';
    warnings.forEach(function (w) {
        var div = document.createElement('div');
        div.className = w.severity === 'block' ? 'text-danger'
                      : (w.severity === 'warn' ? 'text-warning-dark' : 'text-muted');
        var i = document.createElement('i');
        i.className = 'fas ' + (w.severity === 'info' ? 'fa-info-circle' : 'fa-exclamation-triangle');
        div.appendChild(i);
        div.appendChild(document.createTextNode(' ' + w.text));
        box.appendChild(div);
    });
}

/** Sets a field back to the domain the classifier proposes today. */
function omopReset(btn) {
    var tr = omopRow(btn);
    omopPost({action: 'reset-proposal', field: tr.dataset.field}, function (j) {
        if (!j.changed) { alert(OMOP.resetNone); return; }
        location.reload();
    });
}

/**
 * The same in bulk. It first asks what would change and shows the list:
 * reviewing proposals should not surprise anyone.
 */
function omopResetAll() {
    omopPost({action: 'reset-all-preview'}, function (j) {
        if (!j.count) { alert(OMOP.resetNoneAll); return; }
        var list = Object.keys(j.changes).slice(0, 12)
            .map(function (k) { return k + ': ' + j.changes[k]; }).join('\n');
        if (j.count > 12) { list += '\n' + OMOP.bulkMore.replace('{0}', j.count - 12); }
        if (confirm(OMOP.resetConfirm.replace('{0}', j.count) + '\n\n' + list)) {
            omopPost({action: 'reset-all'}, function () { location.reload(); });
        }
    });
}

/**
 * Erases the whole mapping and returns to the initial state.
 *
 * It asks twice on purpose: first showing what is lost with figures, and then
 * asking for a word to be typed, which the server checks again. A copy is
 * saved that allows undoing the last reset.
 */
function omopResetEverything() {
    omopPost({action: 'reset-everything-preview'}, function (j) {
        var i = j.impact;
        if (!i.fields) { alert(OMOP.resetAllNone); return; }

        var text = OMOP.resetAllConfirm
            .replace('{0}', i.fields).replace('{1}', i.approved)
            .replace('{2}', i.excluded).replace('{3}', i.concepts);

        var typed = prompt(text + '\n\n' + OMOP.resetAllPrompt.replace('{0}', OMOP.resetAllWord));
        if (typed === null) { return; }
        if (typed.trim().toUpperCase() !== OMOP.resetAllWord.toUpperCase()) {
            alert(OMOP.resetAllAborted);
            return;
        }
        omopPost({action: 'reset-everything', confirmed: '1', word: typed.trim()},
                 function () { location.reload(); });
    });
}

function omopRestore() {
    if (!confirm(OMOP.restoreConfirm)) { return; }
    omopPost({action: 'restore-backup'}, function () { location.reload(); });
}

/**
 * Every bulk action first asks the server whom it would affect and shows the
 * list. What is approved and what is flagged for review is never touched.
 */
function omopBulkAsk(which) {
    omopPost({action: 'bulk-preview', which: which}, function (j) {
        if (!j.count) { alert(OMOP.bulkNone); return; }
        var list = j.fields.slice(0, 12).join('\n');
        if (j.count > 12) { list += '\n' + OMOP.bulkMore.replace('{0}', j.count - 12); }
        if (confirm(OMOP.bulkConfirm[which].replace('{0}', j.count) + '\n\n' + list)) {
            omopPost({action: 'bulk', which: which}, function () { location.reload(); });
        }
    });
}

function omopFilter() {
    var st = document.getElementById('omop-filter').value;
    var q = document.getElementById('omop-search').value.toLowerCase();
    document.querySelectorAll('#omop-editor > tbody > tr').forEach(function (tr) {
        var okState = !st || tr.dataset.state === st;
        var okText = !q || tr.textContent.toLowerCase().indexOf(q) !== -1
                        || tr.dataset.field.toLowerCase().indexOf(q) !== -1;
        tr.style.display = (okState && okText) ? '' : 'none';
    });
}

/**
 * Cleans the concept_id field as soon as it loses focus.
 *
 * Copying from a web table drags along spaces, and some are non-breaking and
 * invisible. Cleaning them here as well as on the server lets the user see
 * the already normalised value, without having to find out why "8507 " was
 * not accepted.
 */
function omopCleanId(el) {
    if (el.dataset.k !== 'concept_id' && el.dataset.vk !== 'concept_id') { return; }
    var cleaned = el.value.replace(/[\s  -‍  　﻿]/g, '');
    if (cleaned !== el.value) { el.value = cleaned; }
}

omopFillShared();
omopFillDates();
// 'change' and not 'blur': it only saves when the value really changes.
document.querySelectorAll('#omop-editor [data-k]').forEach(function (el) {
    el.addEventListener('change', function () {
        omopCleanId(el);
        if (el.dataset.k === 'value_strategy') { omopStrategy(el); }
        if (el.dataset.k === 'domain') {
            var end = omopRow(el).querySelector('.omop-end');
            if (end) { end.style.display = OMOP.endDomains.indexOf(el.value) !== -1 ? '' : 'none'; }
        }
        omopSave(el);
    });
});
document.querySelectorAll('#omop-editor [data-vk]').forEach(function (el) {
    el.addEventListener('change', function () { omopCleanId(el); omopSaveValue(el); });
});
omopRender(OMOP.counts);
</script>

<?php
require_once APP_PATH_DOCROOT . 'ProjectGeneral/footer.php';
