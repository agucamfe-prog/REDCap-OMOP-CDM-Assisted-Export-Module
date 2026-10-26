<?php
// Spec 007 — Mapping editor
use OmopCdm\OmopCdmExportModule\{MappingStore, OmopCdmExportModule};

$runPage = require dirname(__DIR__) . '/support/run_page.php';
$tok = ['csrf' => 'tok'];
$post = fn(array $settings, array $p, array $o = []) => $runPage('pages/editor.php', ['settings' => $settings, 'post' => $p + $tok] + $o);
$render = fn(array $settings, array $o = []) => $runPage('pages/editor.php', ['settings' => $settings] + $o);
/** HTML of a field's row (up to the next row). */
$row = function (string $html, string $field): string {
    $p = strpos($html, '<tr data-field="' . $field . '"');
    if ($p === false) {
        return '';
    }
    $q = strpos($html, '<tr data-field="', $p + 10);
    return substr($html, $p, ($q === false ? strpos($html, '</tbody>', $p) : $q) - $p);
};
/** OMOP object that the page hands to the JavaScript. */
$omop = function (string $html): array {
    return preg_match('/var OMOP = (\{.*?\});\s*OMOP\.badges/s', $html, $mm) ? (json_decode($mm[1], true) ?? []) : [];
};
$fresh = function (array $settings): OmopCdmExportModule {
    $x = new OmopCdmExportModule();
    $x->settings = $settings;
    return $x;
};
$spec = fn(array $settings, string $f) => $fresh($settings)->store()->spec($f);
$m0 = make_module();
$tx = fn(string $k, ...$a) => htmlspecialchars($m0->ttText($k, ...$a), ENT_QUOTES, 'UTF-8');

section('Access and read-only mode');
\TestUser::$super = false;
\TestUser::$rights = [];
$r = $render(make_module()->settings);
$h = $r['out'];
test('FR-007-01', 'without design rights the editor opens read-only and says so', strpos($h, $tx('ed_readonly')) !== false
    && substr_count($h, '<tr data-field="') === 18, $r['err']);
test('FR-007-01', 'in read-only mode every control is disabled and there are no state buttons',
    strpos($row($h, 'weight'), 'data-k="concept_id"') !== false && preg_match('/data-k="concept_id"[^>]*\n?[^>]*disabled/', $row($h, 'weight')) === 1
    && strpos($h, "omopState(this,'approved')") === false);
$r = $post(make_module()->settings, ['action' => 'save-field', 'field' => 'weight', 'notes' => 'x']);
test('FR-007-01', 'and the server rejects any change (403)', $r['status'] === 403 && $r['json']['error'] === $m0->ttText('ed_err_rights')
    && !isset($r['settings'][MappingStore::SETTING_KEY]));
\TestUser::$rights = ['design' => 1];
$r = $render(make_module()->settings);
test('FR-007-01', 'with design rights editing is possible', strpos($r['out'], $tx('ed_readonly')) === false
    && strpos($r['out'], "omopState(this,'approved')") !== false);
\TestUser::$super = true;
\TestUser::$rights = [];

section('Action protection');
$r = $runPage('pages/editor.php', ['settings' => make_module()->settings, 'post' => ['action' => 'save-field', 'field' => 'weight', 'notes' => 'x', 'csrf' => 'other']]);
test('FR-007-02', 'without the session CSRF token it is rejected (403) and nothing is saved',
    $r['status'] === 403 && $r['json']['ok'] === false && $r['json']['error'] === $m0->ttText('ed_err_csrf')
    && !isset($r['settings'][MappingStore::SETTING_KEY]));
$r = $post(make_module()->settings, ['action' => 'drop-tables']);
test('FR-007-02', 'an action outside the whitelist is rejected (400)', $r['status'] === 400
    && $r['json']['error'] === $m0->ttText('ed_err_unknown_action', 'drop-tables'));
$r = $post(make_module()->settings, ['action' => 'save-field', 'field' => 'nope']);
test('FR-007-02', 'a field that is not in the data dictionary is rejected (400)', $r['status'] === 400
    && $r['json']['error'] === $m0->ttText('ed_err_unknown_field', 'nope'));
$r = $post(make_module()->settings, ['action' => 'save-field', 'field' => 'weight', 'domain' => 'Banana']);
test('FR-007-02', 'a domain that is not in the list is rejected (400)', $r['status'] === 400
    && $r['json']['error'] === $m0->ttText('ed_err_bad_domain', 'Banana'));
test('FR-007-02', 'responses are always JSON', in_array('Content-Type: application/json; charset=utf-8', $r['headers'], true)
    && in_array('X-Omop-Response: 1', $r['headers'], true));
$r = $post([MappingStore::SETTING_KEY => '{"fields":{"weight":"broken"},"values":{}}'] + make_module()->settings,
    ['action' => 'save-field', 'field' => 'weight', 'notes' => 'x']);
test('FR-007-02', 'a corrupt entry in the stored mapping does not break the editor', $r['status'] === 200
    && $r['json']['ok'] === true && $r['json']['spec']['notes'] === 'x', substr($r['out'], 0, 200));
// Simulated failure: the mapping count throws an exception in the middle of the action.
$r = $post(make_module()->settings, ['action' => 'save-field', 'field' => 'weight', 'notes' => 'x'], ['fail' => true]);
test('FR-007-02', 'a server failure arrives as JSON (500) with the location of the error, and is logged',
    $r['status'] === 500 && $r['json']['ok'] === false && strpos((string) ($r['json']['where'] ?? ''), '.php:') !== false
    && in_array('Editor error', array_column($r['logs'], 0), true), substr($r['out'], 0, 200));

section('Rows');
$m = make_module();
curate($m);
$cur = $m->settings;
\REDCap::$dict['notes']['field_label'] = 'Clinical notes (free)';
$r = $render($cur);
$h = $r['out'];
\REDCap::$dict = fixture_dict();
test('FR-007-03', 'one row per data dictionary field, with its state', substr_count($h, '<tr data-field="') === 18
    && strpos($h, '<tr data-field="weight" data-state="approved"') !== false && strpos($h, '<tr data-field="dob" data-state="config"') !== false
    && strpos($h, '<tr data-field="notes" data-state="review"') !== false, $r['err']);
test('FR-007-03', 'the domain selector carries the stored domain', strpos($row($h, 'weight'), 'data-k="domain" data-cur="Measurement"') !== false);
test('FR-007-03', 'the row shows the advisor warnings', strpos($row($h, 'glu'), $tx('adv_free_text')) !== false);
test('FR-007-03', 'and the data dictionary drift', strpos($row($h, 'notes'), $tx('ed_drift_dictionary')) !== false);
\REDCap::$dict['phone'] = f('phone', 'base', 'text', 'Phone', '', '', '', 'y');
$hp = $row($render($cur)['out'], 'phone');
\REDCap::$dict = fixture_dict();
test('FR-007-03', 'badges: manual coding and identifier',
    strpos($row($h, 'glu'), 'badge badge-warning">' . $tx('badge_manual')) !== false
    && strpos($hp, 'badge badge-danger">' . $tx('badge_phi')) !== false && strpos($row($h, 'weight'), 'badge-danger') === false);
test('FR-007-03', 'every editable row has Approve, Exclude and Reopen', substr_count($row($h, 'weight'), 'omopState(this,') === 3);

section('Saving');
$r = $post(make_module()->settings, ['action' => 'save-field', 'field' => 'weight', 'unit_source_value' => 'kg', 'dict_hash' => 'x', 'approved_by' => 'me']);
test('FR-007-04', 'saves the change and responds with the specification, the warnings and the counts',
    $r['json']['ok'] === true && $r['json']['spec']['unit_source_value'] === 'kg'
    && array_keys($r['json']) === ['ok', 'spec', 'error', 'warnings', 'counts'] && $r['json']['counts']['total'] === 18
    && $spec($r['settings'], 'weight')['unit_source_value'] === 'kg');
test('FR-007-04', 'only the whitelist keys are accepted', $r['json']['spec']['dict_hash'] === null && $r['json']['spec']['approved_by'] === null);
$r = $post($r['settings'], ['action' => 'save-field', 'field' => 'weight', 'concept_id' => '2089-1']);
test('FR-007-04', 'what the store rejects is not saved, and the response shows it', $r['json']['spec']['concept_id'] === null
    && $r['json']['spec']['_bad_concept'] === '2089-1');

section('What you see is what is saved (only the first time)');
$r = $post(make_module()->settings, ['action' => 'save-field', 'field' => 'fever', 'notes' => 'x']);
$s1 = $r['json']['spec'];
test('FR-007-05', 'the first save also stores what is preselected: domain, strategy and date',
    $s1['domain'] === 'Condition' && $s1['value_strategy'] === 'per_choice' && $s1['date_field'] === 'visit_date' && $s1['state'] === 'proposed');
$r = $post($r['settings'], ['action' => 'save-field', 'field' => 'fever', 'value_strategy' => 'single_concept']);
$r = $post($r['settings'], ['action' => 'save-field', 'field' => 'fever', 'notes' => 'y']);
test('FR-007-05', 'afterwards what is stored prevails: the proposal is not applied again', $r['json']['spec']['value_strategy'] === 'single_concept');
$r = $post(make_module()->settings, ['action' => 'save-field', 'field' => 'drug', 'date_field' => '']);
test('FR-007-05', 'choosing "default date" on the first save is respected', $r['json']['spec']['date_field'] === null
    && $r['json']['spec']['date_default'] === true && $r['json']['spec']['domain'] === 'Drug');
$r = $post($r['settings'], ['action' => 'save-field', 'field' => 'drug', 'notes' => 'x']);
test('FR-007-05', 'and it is not refilled on the following saves', $r['json']['spec']['date_field'] === null);
$r = $post(make_module()->settings, ['action' => 'save-field', 'field' => 'bac', 'domain' => 'Observation']);
test('FR-007-05', 'the suggested strategy and date are computed with the incoming domain, not the proposed one',
    $r['json']['spec']['domain'] === 'Observation' && $r['json']['spec']['value_strategy'] === 'value_concept'
    && $r['json']['spec']['date_field'] === 'visit_date');
$r = $post(make_module()->settings, ['action' => 'save-field', 'field' => 'weight', 'notes' => 'x']);
test('FR-007-05', 'the suggested unit is not saved on its own: only domain, strategy and date', $r['json']['spec']['unit_source_value'] === null
    && $r['json']['spec']['value_strategy'] === null);

section('Approving from the editor');
$p = ['action' => 'save-field', 'field' => 'weight', 'domain' => 'Measurement', 'concept_id' => '3025315',
      'concept_name' => 'Body weight', 'unit_source_value' => 'kg', 'date_field' => 'visit_date', 'state' => 'approved'];
$r = $post(make_module()->settings, $p);
test('FR-007-06', 'a complete field without warnings is approved with one click', $r['json']['spec']['state'] === 'approved'
    && $r['json']['counts']['approved'] === 1 && $r['json']['error'] === null);
$r = $post(make_module()->settings, ['concept_id' => '', 'concept_name' => ''] + $p);
test('FR-007-06', 'if something is missing, it is not approved and it explains what (ed_err_<reason>)', $r['json']['spec']['state'] === 'proposed'
    && $r['json']['error'] === $m0->ttText('ed_err_no_concept'));
$r = $post(make_module()->settings, ['unit_source_value' => ''] + $p);
test('FR-007-06', 'with warnings that require confirmation it responds "confirm" with the warnings, without approving',
    ($r['json']['confirm'] ?? false) === true && in_array('measurement_no_unit', array_column($r['json']['warnings'], 'code'), true)
    && $r['json']['spec']['state'] === 'proposed' && isset($r['json']['counts']));
$r2 = $post(make_module()->settings, ['unit_source_value' => '', 'confirmed' => '1'] + $p);
test('FR-007-06', 'once confirmed, it is approved and logged', $r2['json']['spec']['state'] === 'approved'
    && in_array('Approved with warnings', array_column($r2['logs'], 0), true));
$r = $post(make_module()->settings, ['date_field' => 'nope_date', 'confirmed' => '1'] + $p);
test('FR-007-06', 'with a blocking warning it responds "blocked" and does not approve', ($r['json']['blocked'] ?? false) === true
    && $r['json']['spec']['state'] === 'proposed');

section('Options and strategies');
$r = $post(make_module()->settings, ['action' => 'save-value', 'field' => 'fever', 'raw_value' => '1', 'emit' => '1',
                                'concept_id' => '437663', 'concept_name' => 'Fever']);
test('FR-007-07', 'save-value stores the option and says whether it changed and what state the field is in',
    $r['json']['value']['concept_id'] === 437663 && $r['json']['changed'] === true && $r['json']['state'] === 'proposed');
$r = $post($r['settings'], ['action' => 'save-value', 'field' => 'fever', 'raw_value' => '1', 'emit' => '1',
                            'concept_id' => '437663', 'concept_name' => 'Fever']);
test('FR-007-07', 'resending the same does not count as a change', $r['json']['changed'] === false);
$r = $post($r['settings'], ['action' => 'save-value', 'field' => 'fever', 'raw_value' => '0', 'emit' => '0']);
test('FR-007-07', 'the "generates a row" mark is stored per option', $r['json']['value']['emit'] === false);
$h = $render(make_module()->settings)['out'];
$fev = $row($h, 'fever');
test('FR-007-07', 'a freshly opened yes/no: "Yes" generates a row and "No" does not',
    preg_match('/<tr data-raw="1">.*?data-vk="emit" checked/s', $fev) === 1 && preg_match('/<tr data-raw="0">.*?data-vk="emit"  /s', $fev) === 1);
test('FR-007-07', 'the preselected strategy is the suggested one: per option on a yes/no, value per option on a result',
    preg_match('/value="per_choice" selected/', $fev) === 1 && preg_match('/value="value_concept" selected/', $row($h, 'bac')) === 1);
test('FR-007-07', 'with "one concept per option" the single concept is hidden and the options are shown',
    preg_match('/class="omop-single" style="display:none"/', $fev) === 1 && preg_match('/omop-choices" style="; margin/', $fev) === 1);
test('FR-007-07', 'with "value per option" both are shown, with their note', preg_match('/class="omop-single" style=""/', $row($h, 'bac')) === 1
    && preg_match('/omop-vc-note" style=""/', $row($h, 'bac')) === 1);

section('Dates, unit and default values');
$o = $omop($h);
test('FR-007-08', 'the list of candidate dates (fields with date validation) is sent once, with its instrument',
    ($o['dateFields'] ?? null) === [['dob', 'base'], ['visit_date', 'base'], ['death_date', 'base'], ['med_start', 'meds'], ['med_end', 'meds']],
    json_encode($o['dateFields'] ?? null));
test('FR-007-08', 'with nothing stored the date of the field\'s own instrument is proposed, marked as suggested',
    strpos($row($h, 'weight'), 'data-shown="visit_date"') !== false && strpos($row($h, 'weight'), $tx('ed_date_suggested')) !== false
    && strpos($row($h, 'drug'), 'data-k="date_field"' . "\n" . '                        data-shown="med_start"') !== false);
test('FR-007-08', 'the end date is only offered in Condition, Drug, Procedure, Device and Visit',
    preg_match('/class="omop-end" style="margin-top:4px; "/', $row($h, 'drug')) === 1
    && preg_match('/class="omop-end" style="margin-top:4px; display:none"/', $row($h, 'weight')) === 1
    && ($o['endDomains'] ?? null) === ['Condition', 'Drug', 'Procedure', 'Device', 'Visit']);
test('FR-007-08', 'the unit is preloaded from the label\'s parenthesis', preg_match('/data-k="unit_source_value"\s+value="kg"/', $row($h, 'weight')) === 1
    && preg_match('/data-k="unit_source_value"\s+value=""/', $row($h, 'glu')) === 1);
$m = make_module();
$m->store()->updateField('weight', ['date_field' => '']);
$hw = $row($render($m->settings)['out'], 'weight');
test('FR-007-08', 'with something stored, what is stored is shown, without the suggestion note', strpos($hw, 'data-shown=""') !== false
    && strpos($hw, $tx('ed_date_suggested')) === false);

section('What the editor does not ask');
\REDCap::$dict['info_box'] = f('info_box', 'base', 'descriptive', 'Read this first');
$h = $render(make_module()->settings)['out'];
\REDCap::$dict = fixture_dict();
$noControls = fn(string $tr) => $tr !== '' && strpos($tr, 'data-k="concept_id"') === false && strpos($tr, 'data-k="date_field"') === false
    && strpos($tr, 'data-k="unit_source_value"') === false && strpos($tr, 'data-k="value_strategy"') === false;
test('FR-007-09', 'a configuration field shows its role and no mapping control',
    $noControls($row($h, 'dob')) && strpos($row($h, 'dob'), $tx('ed_role_birth')) !== false
    && $noControls($row($h, 'site')) && strpos($row($h, 'site'), $tx('ed_role_care_site')) !== false);
test('FR-007-09', 'a reference date or a field without a target only show the reason',
    $noControls($row($h, 'med_start')) && $noControls($row($h, 'info_box')) && strpos($row($h, 'info_box'), 'descriptive') !== false);
test('FR-007-09', 'the domain selector remains available to change one\'s mind', strpos($row($h, 'med_start'), 'data-k="domain"') !== false);

section('Athena buttons');
parse_str(parse_url($m0->athenaUrl('body weight', 'Measurement', 'LOINC'), PHP_URL_QUERY), $q);
test('FR-007-10', 'the Athena URL carries query, standard, valid, page, size, domain and vocabulary',
    $q === ['query' => 'body weight', 'standardConcept' => 'Standard', 'invalidReason' => 'Valid', 'page' => '1', 'pageSize' => '15',
            'domain' => 'Measurement', 'vocabulary' => 'LOINC']
    && strpos($m0->athenaUrl('x', ''), 'domain=') === false && strpos($m0->athenaUrl('x', 'Drug'), 'vocabulary=') === false);
test('FR-007-10', 'the Athena domain: Person is searched in Gender, Death in Condition, and the module codes without a filter',
    $m0->athenaDomain('Person') === 'Gender' && $m0->athenaDomain('Death') === 'Condition' && $m0->athenaDomain('NotMapped') === ''
    && $m0->athenaDomain('DateAnchor') === '' && $m0->athenaDomain(null) === '' && $m0->athenaDomain('Measurement') === 'Measurement');
test('FR-007-10', 'the vocabulary per domain: LOINC, RxNorm, Gender and SNOMED',
    $m0->athenaVocabulary('Measurement') === 'LOINC' && $m0->athenaVocabulary('Drug') === 'RxNorm' && $m0->athenaVocabulary('Person') === 'Gender'
    && $m0->athenaVocabulary('Condition') === 'SNOMED' && $m0->athenaVocabulary('Visit') === 'SNOMED' && $m0->athenaVocabulary('Note') === '');
$m = make_module();
curate($m);
$h = $render($m->settings)['out'];
$hw = $row($h, 'weight');
test('FR-007-10', 'the row carries the button without a vocabulary filter and another with the domain vocabulary',
    $m0->athenaQuery('Weight (kg)', 'weight', 'kg') === 'weight'
    && strpos($hw, htmlspecialchars($m0->athenaUrl('weight', 'Measurement'))) !== false
    && strpos($hw, htmlspecialchars($m0->athenaUrl('weight', 'Measurement', 'LOINC'))) !== false);
test('FR-007-10', 'with a concept, a link to verify it in Athena', strpos($hw, 'https://athena.ohdsi.org/search-terms/terms/3025315') !== false);
test('FR-007-10', 'the option of a yes/no searches for the question, not "Yes"',
    strpos($row($h, 'fever'), htmlspecialchars($m0->athenaUrl('fever', 'Condition'))) !== false);
test('FR-007-10', 'with "value per option" the answer is searched in Meas Value',
    strpos($row($h, 'bac'), htmlspecialchars($m0->athenaUrl('positive', 'Meas Value'))) !== false);

section('Search query');
test('FR-007-11', 'the unit in parentheses is removed and replaced by the LOINC property', $m0->athenaQuery('Hemoglobina (g/dL)', 'hb', 'g/dL') === 'hemoglobin mass volume');
test('FR-007-11', 'protocol qualifiers and the weight unit are not searched for', $m0->athenaQuery('Peso basal (kg)', 'peso') === 'body weight');
test('FR-007-11', 'long glossary phrases win over short ones', $m0->athenaQuery('Sudoración nocturna', 'x') === 'night sweats');
test('FR-007-11', 'qualifiers are removed after translating', $m0->athenaQuery('No realizada', 'x') === 'not performed');
test('FR-007-11', 'question wording is removed', $m0->athenaQuery('¿Presentó fiebre?', 'x') === 'fever');
test('FR-007-11', 'American spelling', $m0->athenaQuery('Haemoglobin', 'x') === 'hemoglobin');
test('FR-007-11', 'five words at most', count(explode(' ', $m0->athenaQuery('diabetes hipertension asma epoc ictus cancer', 'x'))) === 5);
test('FR-007-11', 'if nothing is left, the field name is searched for', $m0->athenaQuery('Por favor, indique la fecha', 'campo_x') === 'campo_x');
test('FR-007-11', 'LOINC property according to the unit',
    $m0->loincProperty('mmol/L') === 'moles volume' && $m0->loincProperty('U/L') === 'enzymatic activity volume'
    && $m0->loincProperty('mUI/L') === 'units volume' && $m0->loincProperty('%') === 'fraction'
    && $m0->loincProperty('x10^3/uL') === 'number volume' && $m0->loincProperty('kg') === '' && $m0->loincProperty(null) === '');

section('Bulk actions');
$m = make_module();
$set = $m->settings;
$r = $post($set, ['action' => 'bulk-preview', 'which' => 'exclude-manual']);
test('FR-007-12', 'the preview says whom it would affect, without changing anything', $r['json']['fields'] === ['glu'] && $r['json']['count'] === 1
    && !isset($r['settings'][MappingStore::SETTING_KEY]));
$r = $post($set, ['action' => 'bulk', 'which' => 'exclude-manual']);
test('FR-007-12', 'exclude free text', $r['json']['changed'] === 1 && $spec($r['settings'], 'glu')['state'] === 'excluded');
\REDCap::$dict['info_box'] = f('info_box', 'base', 'descriptive', 'Read this first');
$r = $post($set, ['action' => 'bulk-preview', 'which' => 'exclude-notmapped']);
test('FR-007-12', 'exclude what has no target', $r['json']['fields'] === ['info_box']);
\REDCap::$dict = fixture_dict();
$m = make_module();
$s = $m->store();
$d = \REDCap::$dict;
$s->updateField('weight', ['domain' => 'Measurement', 'concept_id' => '3025315', 'concept_name' => 'Body weight',
                           'unit_source_value' => 'kg', 'date_field' => 'visit_date']);
$s->updateField('glu', ['domain' => 'Measurement', 'concept_id' => '3004501', 'concept_name' => 'Glucose', 'date_field' => 'visit_date']);
$s->updateField('fever', ['domain' => 'Condition', 'value_strategy' => 'per_choice', 'date_field' => 'visit_date']);
$s->updateValue('fever', '1', ['concept_id' => '437663', 'concept_name' => 'Fever'], 'Yes', $d['fever']);
$s->updateField('notes', ['domain' => 'Note']);
$r = $post($m->settings, ['action' => 'bulk-preview', 'which' => 'approve-ready']);
test('FR-007-12', 'approve what is ready: only what a person has prepared, complete and without warnings', $r['json']['fields'] === ['weight', 'fever'],
    json_encode($r['json']['fields']));
$r = $post($m->settings, ['action' => 'bulk', 'which' => 'approve-ready']);
$sm = $fresh($r['settings'])->store();
test('FR-007-12', 'approving in bulk signs, stores the fingerprint and materialises the options', $r['json']['changed'] === 2
    && $sm->spec('weight')['state'] === 'approved' && $sm->spec('weight')['dict_hash'] !== null
    && ($sm->values('fever')['0']['emit'] ?? null) === false && $sm->spec('glu')['state'] === 'proposed');
$m = make_module();
curate($m);
$r = $post($m->settings, ['action' => 'bulk', 'which' => 'exclude-pending']);
$x = $fresh($r['settings']);
test('FR-007-12', 'no bulk action touches what is approved or what the configuration consumes',
    $r['json']['changed'] === 5 && $x->mappingCounts()['approved'] === 9 && $x->mappingCounts()['config'] === 4
    && $x->mappingCounts()['excluded'] === 5);
\REDCap::$dict['weight']['field_label'] = 'Weight (lb)';
$r = $post($m->settings, ['action' => 'bulk-preview', 'which' => 'exclude-pending']);
test('FR-007-12', 'nor what is flagged for review', !in_array('weight', $r['json']['fields'], true) && $r['json']['count'] === 5);
\REDCap::$dict = fixture_dict();
$m = make_module();
$m->store()->updateField('weight', ['domain' => 'Observation']);
$m->store()->updateField('glu', ['domain' => 'Observation', 'concept_id' => '4000000', 'state' => 'approved'], \REDCap::$dict['glu']);
$r = $post($m->settings, ['action' => 'reset-all-preview']);
test('FR-007-12', 'reviewing proposals in bulk shows "current → proposed" and respects what is approved',
    $r['json']['changes'] === ['weight' => 'Observation → Measurement'] && $r['json']['count'] === 1);
$r = $post($m->settings, ['action' => 'reset-all']);
test('FR-007-12', 'and applies it', $r['json']['changed'] === 1 && $spec($r['settings'], 'weight')['domain'] === 'Measurement'
    && $spec($r['settings'], 'glu')['domain'] === 'Observation');
$r = $post($m->settings, ['action' => 'reset-proposal', 'field' => 'glu']);
test('FR-007-12', 'per field, the Proposal button restores the proposed domain', $r['json']['changed'] === true
    && $r['json']['from'] === 'Observation' && $r['json']['to'] === 'Measurement' && $r['json']['spec']['state'] === 'proposed');
$hg = $row($render($m->settings)['out'], 'glu');
test('FR-007-12', 'the Proposal button only appears if the stored domain differs from the proposed one',
    strpos($hg, 'omopReset(this)') !== false && strpos($row($render($m->settings)['out'], 'notes'), 'omopReset(this)') === false);

section('Resetting the whole mapping');
$m = make_module();
curate($m);
$set = $m->settings;
$r = $post($set, ['action' => 'reset-everything-preview']);
test('FR-007-13', 'first it shows what would be lost', $r['json']['impact'] === ['fields' => 9, 'approved' => 9, 'excluded' => 0, 'concepts' => 13]);
$r = $post($set, ['action' => 'reset-everything', 'word' => 'DELETE']);
test('FR-007-13', 'without the confirmation nothing is erased', $r['status'] === 400 && $spec($r['settings'], 'weight')['state'] === 'approved');
$r = $post($set, ['action' => 'reset-everything', 'confirmed' => '1', 'word' => 'BORRAR']);
test('FR-007-13', 'the server requires the word of the project language', $r['status'] === 400
    && $r['json']['error'] === $m0->ttText('ed_reset_all_unconfirmed'));
$r = $post($set, ['action' => 'reset-everything', 'confirmed' => '1', 'word' => ' delete ']);
$reset = $r['settings'];
test('FR-007-13', 'with the word (case-insensitive) everything is erased', $r['json']['ok'] === true
    && $r['json']['cleared'] === ['fields' => 9, 'approved' => 9, 'excluded' => 0] && $fresh($reset)->store()->isEmpty());
$es = parse_ini_file(dirname(__DIR__, 2) . '/lang/Spanish.ini');
$r = $post($set, ['action' => 'reset-everything', 'confirmed' => '1', 'word' => 'borrar'], ['lang' => $es]);
test('FR-007-13', 'in Spanish the word is BORRAR', $r['json']['ok'] === true);
$h = $render($reset)['out'];
test('FR-007-13', 'after resetting, the editor offers to undo', strpos($h, 'omopRestore()') !== false);
$r = $post($reset, ['action' => 'restore-backup']);
test('FR-007-13', 'undoing restores the mapping', $r['json']['restored'] === 9 && $spec($r['settings'], 'weight')['state'] === 'approved');
$r = $post($r['settings'], ['action' => 'restore-backup']);
test('FR-007-13', 'without a copy there is nothing to undo', $r['status'] === 400 && $r['json']['error'] === $m0->ttText('ed_restore_none'));

section('Count');
$h = $render(make_module()->settings)['out'];
$o = $omop($h);
$es = parse_ini_file(dirname(__DIR__, 2) . '/lang/Spanish.ini');
$src = file_get_contents(dirname(__DIR__, 2) . '/pages/editor.php');
test('FR-007-15', 'the bar count includes the configuration fields, in both languages',
    strpos($o['tpl'] ?? '', '{5}') !== false && strpos($es['ed_counts'], '{5}') !== false && ($o['counts']['config'] ?? null) === 4
    && preg_match("/\\.replace\\('\\{5\\}', counts\\.config/", $src) === 1);

section('Non-functional');
test('NFR-007-01', 'the common lists (domains, dates) are sent once and not repeated per row',
    strpos($h, '<option value="Condition"') === false && count($o['domains'] ?? []) === 13 && substr_count($h, '<option') < 60,
    (string) substr_count($h, '<option'));
$src = file_get_contents(dirname(__DIR__, 2) . '/pages/editor.php');
preg_match_all("/(?:ttText|\\\$t)\\('([a-z_]*[a-z])'[,)]/", $src, $mm);
$en = parse_ini_file(dirname(__DIR__, 2) . '/lang/English.ini');
$keys = array_unique(array_merge($mm[1], array_map(fn($s) => 'ed_state_' . $s, ['proposed', 'approved', 'excluded', 'review', 'config']),
    array_map(fn($s) => 'ed_role_' . $s, ['person_id', 'birth', 'death_date', 'care_site', 'provider'])));
$missing = array_filter($keys, fn($k) => !isset($es[$k], $en[$k]));
test('NFR-007-02', 'every editor text is in Spanish and in English', count($keys) > 60 && !$missing, implode(',', $missing));
