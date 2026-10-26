<?php
// Spec 006 — Mapping store and states
use OmopCdm\OmopCdmExportModule\{MappingStore, OmopCdmExportModule};

/** A new module (no caches) that reads the same settings: simulates the next request. */
$fresh = function (array $settings): OmopCdmExportModule {
    $x = new OmopCdmExportModule();
    $x->settings = $settings;
    return $x;
};
$logs = fn(OmopCdmExportModule $x, string $msg) => array_values(array_filter($x->logs, fn($l) => $l[0] === $msg));

section('Persistence and mapping contract');
$m = make_module();
$s = $m->store();
test('FR-006-01', 'with nothing stored, the mapping is empty and carries schema 3',
    $s->isEmpty() && $s->load()['meta']['schema_version'] === 3 && $s->load()['fields'] === []);
$s->updateField('weight', ['domain' => 'Measurement']);
$raw = $m->settings[MappingStore::SETTING_KEY] ?? null;
$j = json_decode((string) $raw, true);
test('FR-006-01', 'the mapping is stored as JSON in the project setting omop-mapping-json, with meta, fields and values',
    is_string($raw) && isset($j['meta']['updated'], $j['fields']['weight'], $j['values']) && $j['meta']['project_id'] === 1
    && $j['meta']['updated_by'] === 'system');
test('FR-006-01', 'a corrupt setting is read as an empty mapping, without error',
    $fresh([MappingStore::SETTING_KEY => '{broken'])->store()->isEmpty()
    && $fresh([MappingStore::SETTING_KEY => '{"meta":{}}'])->store()->isEmpty());
$x = $fresh([MappingStore::SETTING_KEY => ['fields' => ['weight' => ['domain' => 'Measurement']]]]);
test('FR-006-01', 'a setting that arrives already decoded is accepted as is',
    $x->store()->spec('weight')['domain'] === 'Measurement' && $x->store()->values('weight') === []);
$x = $fresh([MappingStore::SETTING_KEY => '{"fields":{"weight":"broken","glu":{"domain":"Measurement"}},'
    . '"values":{"bac":"x","fever":{"1":"y","0":{"raw_value":"0","emit":false}}}}']);
$ok = !throws(function () use ($x) {
    $x->store()->spec('weight');
    $x->store()->values('fever');
    $x->mappingRows();
});
test('FR-006-01', 'a corrupt entry inside fields or values is ignored, without breaking anything',
    $ok && $x->store()->spec('weight')['state'] === 'proposed' && $x->store()->spec('glu')['domain'] === 'Measurement'
    && $x->store()->values('bac') === [] && count($x->store()->values('fever')) === 1 && isset($x->store()->values('fever')['0']));
$l = $logs($x, 'Corrupt entries ignored when reading the mapping');
test('FR-006-01', 'and it is recorded in the log, once per request',
    count($l) === 1 && $l[0][1]['entries'] === 'weight,bac,fever=1' && $l[0][1]['count'] === 3, json_encode($x->logs));

$b = MappingStore::blankSpec();
test('FR-006-02', 'field contract: exactly these keys, in this order',
    array_keys($b) === ['state', 'domain', 'concept_id', 'concept_name', 'value_strategy', 'date_field', 'date_default',
                        'end_date_field', 'unit_source_value', 'notes', 'edited', 'dict_hash', 'approved_by',
                        'approved_at', 'updated_by', 'updated_at']);
test('FR-006-02', 'an untouched field is "proposed", with no domain, no concept and not edited',
    $b['state'] === 'proposed' && $b['domain'] === null && $b['concept_id'] === null && $b['edited'] === false
    && $b['date_default'] === false && $b['notes'] === '');
test('FR-006-02', 'option contract: raw_value, source_value, emit, concept_id, concept_name',
    array_keys(MappingStore::blankValueSpec('1', 'Yes')) === ['raw_value', 'source_value', 'emit', 'concept_id', 'concept_name']);
$d = \REDCap::$dict;
test('FR-006-02', 'the negative option of a yes/no does not generate a row by default; the positive one does',
    $s->valueSpec('fever', '0', 'No', $d['fever'])['emit'] === false && $s->valueSpec('fever', '1', 'Yes', $d['fever'])['emit'] === true);
test('FR-006-02', 'in a categorical field with more than two options all generate a row by default',
    $s->valueSpec('comorb', '3', 'None', $d['comorb'])['emit'] === true);

section('States and strategies');
test('FR-006-03', 'four storable states and three value strategies',
    MappingStore::states() === ['proposed', 'approved', 'excluded', 'review']
    && MappingStore::strategies() === ['per_choice', 'value_concept', 'single_concept']);
test('FR-006-03', 'the derived state "config" cannot be stored', $s->updateField('glu', ['state' => 'config'])['state'] === 'proposed');
test('FR-006-03', 'a made-up state is ignored', $s->updateField('glu', ['state' => 'banana'])['state'] === 'proposed');
test('FR-006-03', 'a made-up strategy is not stored', $s->updateField('bac', ['value_strategy' => 'random'])['value_strategy'] === null);
test('FR-006-03', 'per_choice and value_concept decide per option; single_concept does not',
    MappingStore::usesOptions('per_choice') && MappingStore::usesOptions('value_concept')
    && !MappingStore::usesOptions('single_concept') && !MappingStore::usesOptions(null));
$s->updateField('glu', ['state' => 'excluded']);
test('FR-006-03', 'excluding is a stored state', $fresh($m->settings)->store()->spec('glu')['state'] === 'excluded');

section('Writing does not approve');
$m = make_module();
$s = $m->store();
$r = $s->updateField('weight', ['domain' => 'Measurement', 'concept_id' => '3025315', 'concept_name' => 'Body weight',
                                'unit_source_value' => 'kg', 'date_field' => 'visit_date']);
test('FR-006-04', 'filling in domain, concept, unit and date leaves the field "proposed", without a signature',
    $r['state'] === 'proposed' && $r['approved_by'] === null && $r['approved_at'] === null);
$s->updateValue('fever', '1', ['concept_id' => '437663', 'concept_name' => 'Fever'], 'Yes', $d['fever']);
test('FR-006-04', 'deciding the concept of an option does not approve the field either', $s->spec('fever')['state'] === 'proposed');
test('FR-006-04', 'what is not approved does not count as approved for export', !$m->isApproved('weight'));

section('Approving');
$r = $s->updateField('weight', ['state' => 'approved'], $d['weight']);
test('FR-006-05', 'approving is explicit and leaves the signature: who and when',
    $r['state'] === 'approved' && $r['approved_by'] === 'system'
    && preg_match('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', (string) $r['approved_at']) === 1 && $m->isApproved('weight'));
test('FR-006-05', 'approving stores the fingerprint of the field metadata', $r['dict_hash'] === MappingStore::dictHash($d['weight']));
$s->updateField('fever', ['domain' => 'Condition', 'value_strategy' => 'per_choice', 'date_field' => 'visit_date']);
$r = $s->updateField('fever', ['state' => 'approved'], $d['fever'], ['1' => 'Yes', '0' => 'No']);
$vals = $s->values('fever');
test('FR-006-05', 'approving a categorical field also stores the untouched options, with their default value',
    $r['state'] === 'approved' && isset($vals['0']) && $vals['0']['emit'] === false && $vals['0']['source_value'] === 'No');

section('Approval requirements');
$m = make_module();
$s = $m->store();
$r = $s->updateField('glu', ['state' => 'approved'], $d['glu']);
test('FR-006-06', 'without a domain it is not approved (no_domain)', $r['state'] === 'proposed' && ($r['_error'] ?? '') === 'no_domain'
    && $s->spec('glu')['state'] === 'proposed');
$s->updateField('glu', ['domain' => 'Measurement']);
test('FR-006-06', 'without a concept_id it is not approved (no_concept)', $s->readyToApprove('glu', [])['reason'] === 'no_concept');
$s->updateField('exitus', ['domain' => 'Death', 'value_strategy' => 'per_choice']);
test('FR-006-06', 'in Death the concept is optional: an "Exitus yes/no" is approved without a concept',
    $s->readyToApprove('exitus', ['1' => 'Yes', '0' => 'No'], $d['exitus'])['ok']);
$s->updateField('bac', ['domain' => 'Measurement', 'value_strategy' => 'value_concept']);
test('FR-006-06', '"value per option" needs the concept of the question',
    $s->readyToApprove('bac', ['1' => 'Positive', '2' => 'Negative'], $d['bac'])['reason'] === 'no_concept');
$s->updateField('comorb', ['domain' => 'Condition', 'value_strategy' => 'per_choice']);
$s->updateValue('comorb', '1', ['concept_id' => '201820'], 'Diabetes', $d['comorb']);
$ch = ['1' => 'Diabetes', '2' => 'Hypertension', '3' => 'None'];
$rt = $s->readyToApprove('comorb', $ch, $d['comorb']);
test('FR-006-06', 'per option: every option that generates a row needs a concept (choices_pending, with the list)',
    !$rt['ok'] && $rt['reason'] === 'choices_pending' && $rt['pending'] === ['2', '3'] && $rt['value'] === '2, 3');
foreach (['1', '2', '3'] as $o) {
    $s->updateValue('comorb', $o, ['emit' => '0'], $ch[$o], $d['comorb']);
}
test('FR-006-06', 'if no option generates a row it is not approved (no_emitting_choice)',
    $s->readyToApprove('comorb', $ch, $d['comorb'])['reason'] === 'no_emitting_choice');
$s->updateField('glu', ['concept_id' => '2089-1']);
$rt = $s->readyToApprove('glu', []);
test('FR-006-06', 'a vocabulary code pasted as concept_id blocks approval (bad_concept_format)',
    $rt['reason'] === 'bad_concept_format' && $rt['value'] === '2089-1');
$s->updateField('glu', ['concept_id' => '-3004501']);
test('FR-006-06', 'a negative concept_id blocks approval (concept_negative)', $s->readyToApprove('glu', [])['reason'] === 'concept_negative');
$s->updateField('glu', ['concept_id' => '3004501']);
test('FR-006-06', 'with a valid domain and concept, the field is ready', $s->readyToApprove('glu', [])['ok']);

section('Modifying withdraws the approval');
$m = make_module();
curate($m);
$s = $m->store();
test('FR-006-07', 'all curated fields are approved',
    count(array_filter(['sex', 'weight', 'glu', 'bac', 'fever', 'comorb', 'notes', 'drug', 'exitus'],
        fn($f) => $s->spec($f)['state'] === 'approved')) === 9);
$r = $s->updateValue('fever', '1', ['emit' => '1', 'concept_id' => '437663', 'concept_name' => 'Fever'], 'Yes', $d['fever']);
test('FR-006-07', 'saving an option without changes does not withdraw the approval', $r['state'] === 'approved' && !$r['changed']);
$r = $s->updateField('weight', ['concept_id' => ' 3025315 ', 'unit_source_value' => 'kg', 'date_field' => 'visit_date']);
test('FR-006-07', 'saving a field with the same values does not withdraw the approval', $r['state'] === 'approved' && $r['approved_by'] === 'system');
$r = $s->updateValue('fever', '1', ['concept_name' => 'Fever!'], 'Yes', $d['fever']);
test('FR-006-07', 'a real change in an option withdraws it', $r['state'] === 'proposed' && $r['changed']
    && $s->spec('fever')['approved_by'] === null);
$r = $s->updateField('weight', ['unit_source_value' => 'g']);
test('FR-006-07', 'a real change in the field withdraws it and clears the signature',
    $r['state'] === 'proposed' && $r['approved_by'] === null && $r['approved_at'] === null);

section('Validation of what is written');
$s->updateField('glu', ['domain' => 'Banana']);
test('FR-006-08', 'a made-up domain is not stored', $s->spec('glu')['domain'] === 'Measurement');
$r = $s->updateField('notes', ['concept_id' => "\u{00A0}44814637\u{200B} "]);
test('FR-006-08', 'the concept_id is cleaned of spaces, non-breaking ones included', $r['concept_id'] === 44814637
    && MappingStore::cleanConceptId("\u{FEFF} 12\u{00A0}") === '12');
$r = $s->updateField('glu', ['concept_id' => '2089-1']);
test('FR-006-08', 'an Athena code (CODE) is not stored as concept_id: it is flagged and the previous one is kept',
    $r['concept_id'] === 3004501 && $r['_bad_concept'] === '2089-1' && $r['_bad_kind'] === 'format');
$r = $s->updateField('glu', ['concept_id' => '-5']);
test('FR-006-08', 'a negative value is flagged separately', $r['_bad_kind'] === 'negative');
$r = $s->updateField('glu', ['concept_id' => '3004501']);
test('FR-006-08', 'a valid value clears the error flag', !isset($r['_bad_concept']) && !isset($r['_bad_kind']));
$r = $s->updateField('glu', ['unit_source_value' => '   ', 'notes' => '  check  ']);
test('FR-006-08', 'texts are trimmed and an empty string is stored as null', $r['unit_source_value'] === null && $r['notes'] === 'check');
$r = $s->updateValue('bac', '1', ['concept_id' => 'abc'], 'Positive', $d['bac']);
test('FR-006-08', 'the concept_id of an option is validated too', $r['value']['concept_id'] === 9191 && $r['value']['_bad_kind'] === 'format');
$r = $s->updateField('glu', ['approved_by' => 'someone else', 'edited' => false, 'dict_hash' => 'x']);
test('FR-006-08', 'only the editable keys of the contract are written', $r['approved_by'] === null && $r['edited'] === true && $r['dict_hash'] !== 'x');

section('What is stored prevails');
$m = make_module();
$s = $m->store();
test('FR-006-09', 'an unsaved field is not edited', $s->spec('weight')['edited'] === false);
$s->updateField('weight', ['notes' => 'look at this']);
test('FR-006-09', 'any save marks the field as edited', $s->spec('weight')['edited'] === true);
$x = $fresh([MappingStore::SETTING_KEY => json_encode(['fields' => ['glu' => ['domain' => 'Measurement']], 'values' => []])]);
test('FR-006-09', 'a mapping stored without the flag but with decisions counts as edited', $x->store()->spec('glu')['edited'] === true);
$r = $s->updateField('weight', ['date_field' => '']);
test('FR-006-09', 'leaving the date empty on purpose is remembered as "use the default date"',
    $r['date_field'] === null && $r['date_default'] === true);
$r = $s->updateField('weight', ['date_field' => 'visit_date']);
test('FR-006-09', 'choosing a date clears that flag', $r['date_default'] === false);
$s->updateField('weight', ['domain' => 'Observation']);
$rows = $fresh($m->settings)->mappingRows();
test('FR-006-09', 'the stored domain wins over the classifier proposal',
    $rows['weight']['spec']['domain'] === 'Observation' && $rows['weight']['profile']['suggested_domain'] === 'Measurement');

section('Data dictionary drift');
$m = make_module();
curate($m);
$settings = $m->settings;
\REDCap::$dict['weight']['field_note'] = 'fasting';
\REDCap::$dict['notes']['field_label'] = '<b>Clinical notes</b>';
$rows = $fresh($settings)->mappingRows();
test('FR-006-10', 'a field note or an HTML label with the same text is not drift',
    $rows['weight']['state'] === 'approved' && $rows['notes']['state'] === 'approved');
\REDCap::$dict['weight']['field_label'] = 'Weight (lb)';
\REDCap::$dict['bac']['select_choices_or_calculations'] = '1, Positive | 2, Negative | 3, Contaminated';
$x = $fresh($settings);
$rows = $x->mappingRows();
test('FR-006-10', 'changing the label after approving moves the field to "review"',
    $rows['weight']['state'] === 'review' && $rows['weight']['drifted'] && $rows['weight']['drift_reason'] === 'dictionary');
test('FR-006-10', 'adding an option after approving moves the field to "review"', $rows['bac']['state'] === 'review');
test('FR-006-10', 'a field in "review" does not count as approved for export, and the store is not rewritten',
    !$x->isApproved('weight') && $x->store()->spec('weight')['state'] === 'approved' && $x->mappingCounts()['review'] === 2);
$x->store()->updateField('weight', ['state' => 'approved'], \REDCap::$dict['weight']);
test('FR-006-10', 'approving again with the new data dictionary updates the fingerprint', $x->mappingRows()['weight']['state'] === 'approved');
\REDCap::$dict = fixture_dict();
unset(\REDCap::$dict['med_start']);
$rows = $fresh($settings)->mappingRows();
test('FR-006-10', 'if the date field of an approved fact disappears, the field moves to "review"',
    $rows['drug']['state'] === 'review' && $rows['drug']['drift_reason'] === 'date_field');
\REDCap::$dict = fixture_dict();
unset(\REDCap::$dict['med_end']);
$x = $fresh($settings);
$rows = $x->mappingRows();
test('FR-006-10', 'if the end-date field disappears, it also moves to "review"',
    $rows['drug']['state'] === 'review' && $rows['drug']['drifted'] && !$x->isApproved('drug'));
test('FR-006-10', 'the end-date check also accepts the explicit data dictionary',
    MappingStore::hasDrifted($rows['drug']['spec'], fixture_dict()['drug'], \REDCap::$dict)
    && !MappingStore::hasDrifted($rows['drug']['spec'], fixture_dict()['drug'], fixture_dict()));
\REDCap::$dict = fixture_dict();
test('FR-006-10', 'the fingerprint changes with the type, the validation, the label or the options',
    count(array_unique(array_map(fn($k) => MappingStore::dictHash([$k => 'x'] + \REDCap::$dict['glu']),
        ['field_type', 'text_validation_type_or_show_slider_number', 'field_label', 'select_choices_or_calculations']))) === 4
    && MappingStore::dictHash(['field_note' => 'x'] + \REDCap::$dict['glu']) === MappingStore::dictHash(\REDCap::$dict['glu']));

section('"config" state (amendment 018)');
$m = make_module();
$rows = $m->mappingRows();
test('FR-006-11', 'the fields consumed by the configuration are not left pending',
    $rows['dob']['state'] === 'config' && $rows['record_id']['state'] === 'config' && $rows['site']['state'] === 'config'
    && $rows['death_date']['state'] === 'config' && $m->mappingCounts()['config'] === 4);
test('FR-006-11', 'each one carries its role', $rows['record_id']['role'] === 'person_id' && $rows['dob']['role'] === 'birth'
    && $rows['death_date']['role'] === 'death_date' && $rows['site']['role'] === 'care_site' && $rows['weight']['role'] === null);
test('FR-006-11', 'sex still needs curation (its meaning is decided in the editor)', $rows['sex']['state'] === 'proposed');
test('FR-006-11', '"config" does not count as pending', $m->mappingCounts()['proposed'] === 14 && $m->mappingCounts()['total'] === 18);
$x = make_module(['birth-date-field' => '', 'birth-year-field' => 'dob', 'provider-field' => 'drug']);
test('FR-006-11', 'the birth year and the provider too', $x->configRole('dob') === 'birth' && $x->configRole('drug') === 'provider');
$x = make_module(['birth-year-field' => 'visit_date']);
test('FR-006-11', 'with a birth date declared, the year field is a configuration field too', $x->configRole('visit_date') === 'birth');
$m->store()->updateField('dob', ['notes' => 'x']);
test('FR-006-11', '"config" is derived: it is never stored in the store', $m->store()->spec('dob')['state'] === 'proposed'
    && strpos($m->settings[MappingStore::SETTING_KEY], '"config"') === false);
$m->store()->updateField('death_date', ['domain' => 'Death', 'state' => 'approved'], $d['death_date']);
test('FR-006-11', 'if someone deliberately approves a configuration field, the approval prevails',
    $m->mappingRows()['death_date']['state'] === 'approved');

section('Change log');
$m = make_module();
$s = $m->store();
$s->updateField('notes', ['domain' => 'Note', 'concept_id' => '44814637']);
test('FR-006-12', 'editing without changing state leaves no log entry', $m->logs === []);
$s->updateField('notes', ['state' => 'approved'], $d['notes']);
$l = $logs($m, 'Mapping state change');
test('FR-006-12', 'every state change is logged with field, states and concept',
    count($l) === 1 && $l[0][1] === ['field' => 'notes', 'from_state' => 'proposed', 'to_state' => 'approved', 'concept_id' => 44814637]);
$s->updateField('notes', ['concept_name' => 'Discharge summary']);
$l = $logs($m, 'Mapping state change');
test('FR-006-12', 'withdrawing an approval because of a change is logged too', count($l) === 2 && $l[1][1]['to_state'] === 'proposed');

section('Back to the proposal');
$m = make_module();
curate($m);
$s = $m->store();
$s->updateField('weight', ['domain' => 'Observation']);
$s->updateField('weight', ['state' => 'approved'], $d['weight']);
$r = $s->resetToProposal('weight', 'Measurement');
test('FR-006-13', 'restores the proposed domain and returns the field to "proposed", without a signature',
    $r['changed'] && $r['from'] === 'Observation' && $r['to'] === 'Measurement'
    && $r['spec']['domain'] === 'Measurement' && $r['spec']['state'] === 'proposed' && $r['spec']['approved_by'] === null);
test('FR-006-13', 'keeps concept, unit and date', $r['spec']['concept_id'] === 3025315
    && $r['spec']['unit_source_value'] === 'kg' && $r['spec']['date_field'] === 'visit_date');
test('FR-006-13', 'it is logged', count($logs($m, 'Field reset to the classifier proposal')) === 1);
$r = $s->resetToProposal('weight', 'Measurement');
test('FR-006-13', 'if it already matches nothing changes and nothing is logged', !$r['changed']
    && count($logs($m, 'Field reset to the classifier proposal')) === 1);

section('Reset, backup and undo');
$m = make_module();
curate($m);
$s = $m->store();
$s->updateField('site', ['state' => 'excluded']);
test('FR-006-14', 'before resetting, it is possible to know what would be lost',
    $s->resetImpact() === ['fields' => 10, 'approved' => 9, 'excluded' => 1, 'concepts' => 13], json_encode($s->resetImpact()));
$weightBefore = $s->spec('weight');
$r = $s->reset();
test('FR-006-14', 'resetting erases everything and returns the summary of what was erased',
    $r === ['fields' => 10, 'approved' => 9, 'excluded' => 1] && $s->isEmpty() && $s->spec('weight')['state'] === 'proposed');
$bk = $s->backupInfo();
test('FR-006-14', 'before erasing it saves a full copy with date and author',
    $bk !== null && $bk['fields'] === 10 && $bk['saved_by'] === 'system' && isset($m->settings[MappingStore::BACKUP_KEY])
    && count($logs($m, 'Mapping fully reset')) === 1);
test('FR-006-14', 'undoing restores the approvals with their original signature and deletes the copy',
    $s->restoreBackup() === 10 && $s->spec('weight') == $weightBefore && $s->backupInfo() === null
    && !isset($m->settings[MappingStore::BACKUP_KEY]) && count($logs($m, 'Mapping restored from the reset backup')) === 1);
test('FR-006-14', 'without a copy there is nothing to undo', $s->restoreBackup() === null);

section('Bulk state changes');
$m = make_module();
$s = $m->store();
test('FR-006-15', 'applies the state to several fields and returns how many changed', $s->bulkState(['glu', 'notes'], 'excluded') === 2
    && $s->spec('glu')['state'] === 'excluded');
test('FR-006-15', 'those already in that state do not count', $s->bulkState(['glu', 'weight'], 'excluded') === 1);
test('FR-006-15', 'an invalid state does nothing', $s->bulkState(['bac'], 'config') === 0 && $s->spec('bac')['state'] === 'proposed');
$s->bulkState(['notes'], 'approved', $d);
$sp = $s->spec('notes');
test('FR-006-15', 'approving in bulk signs and stores the fingerprint', $sp['state'] === 'approved' && $sp['approved_by'] === 'system'
    && $sp['dict_hash'] === MappingStore::dictHash($d['notes']));
$l = $logs($m, 'Bulk state change');
test('FR-006-15', 'every bulk change is logged with state, count and fields',
    count($l) === 3 && $l[0][1]['state'] === 'excluded' && $l[0][1]['count'] === 2 && $l[0][1]['fields'] === 'glu,notes');

section('Non-functional');
$cnt = new class extends OmopCdmExportModule {
    public $reads = 0;
    public function getProjectSetting($k, $pid = null) { $this->reads++; return parent::getProjectSetting($k, $pid); }
};
$cnt->settings = make_module()->settings;
foreach (['weight', 'glu', 'bac', 'fever'] as $f) {
    $cnt->store()->spec($f);
    $cnt->store()->values($f);
}
test('NFR-006-01', 'the mapping is read from the setting only once per request', $cnt->reads === 1, (string) $cnt->reads);
$m = make_module();
curate($m);
$m->store()->reset();
test('NFR-006-02', 'the store never writes to the data dictionary or to the project data',
    \REDCap::$dict == fixture_dict() && \REDCap::$data == fixture_records());
