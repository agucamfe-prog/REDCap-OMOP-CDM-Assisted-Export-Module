<?php
// Spec 015 — Reuse: @OMOP annotations and import
use OmopCdm\OmopCdmExportModule\{MappingAnnotation, MappingStore};

$dict = \REDCap::$dict;

section('@OMOP annotations: reading');
$p = MappingAnnotation::parse($dict['hr']['field_annotation']);
test('FR-015-01', 'reads domain, concept, unit and date', $p['spec']['domain'] === 'Measurement' && $p['spec']['concept_id'] === 3027018
    && $p['spec']['concept_name'] === 'Heart rate' && $p['spec']['unit_source_value'] === '{beats}/min'
    && $p['spec']['date_field'] === 'visit_date');
test('FR-015-01', 'without @OMOP tags it returns null', MappingAnnotation::parse('@HIDDEN @DEFAULT="1"') === null);
$p3 = MappingAnnotation::parse("@omop-domain=condition @OMOP-STRATEGY=PER_CHOICE @OMOP-ENDDATE=med_end @OMOP-CONCEPT='201820 | Diabetes mellitus'");
test('FR-015-01', 'case-insensitive tags, canonical domain, lower-case strategy and single quotes',
    $p3['spec']['domain'] === 'Condition' && $p3['spec']['value_strategy'] === 'per_choice'
    && $p3['spec']['end_date_field'] === 'med_end' && $p3['spec']['concept_id'] === 201820, json_encode($p3['spec']));
$p4 = MappingAnnotation::parse('@OMOP-DOMAIN=Measurement @OMOP-DATE=""');
test('FR-015-01', 'an empty @OMOP-DATE means "use the default date" (date_field = null)',
    array_key_exists('date_field', $p4['spec']) && $p4['spec']['date_field'] === null);
$p5 = MappingAnnotation::parse('@OMOP-EXCLUDE');
test('FR-015-01', '@OMOP-EXCLUDE marks the field as excluded', $p5['exclude'] === true && $p5['spec'] === []);
test('FR-015-01', 'a concept without a number leaves concept_id null', MappingAnnotation::parse('@OMOP-CONCEPT="2345-7 | Glucose"')['spec']['concept_id'] === null);

$p2 = MappingAnnotation::parse($dict['smear2']['field_annotation']);
test('FR-015-02', 'reads per-option values and "-" = does not emit', $p2['values']['1']['concept_id'] === 9191 && $p2['values']['2']['emit'] === false);
test('FR-015-02', 'each option carries raw_value, emit, concept_id and concept_name',
    $p2['values']['1'] === ['raw_value' => '1', 'emit' => true, 'concept_id' => 9191, 'concept_name' => 'Positive']);
test('FR-015-02', 'a malformed pair in VALUES is ignored',
    array_keys(MappingAnnotation::parse('@OMOP-VALUES="1=9191 | Positive; garbage; 2=-"')['values']) === [1, 2]);

section('@OMOP annotations: writing');
$tags = MappingAnnotation::render($p2['spec'], $p2['values'], false);
$rt = MappingAnnotation::parse($tags);
test('FR-015-03', 'render and parse are inverses', $rt['spec'] == $p2['spec'] && $rt['values'] == $p2['values'], $tags);
test('FR-015-03', 'an excluded field is written only as @OMOP-EXCLUDE', MappingAnnotation::render(['domain' => 'Drug'], [], true) === '@OMOP-EXCLUDE');
test('FR-015-03', 'VALUES is only written if the strategy uses the options',
    strpos(MappingAnnotation::render(['domain' => 'Measurement', 'value_strategy' => 'single_concept', 'concept_id' => 1, 'concept_name' => 'X'],
        ['1' => ['emit' => true, 'concept_id' => 2, 'concept_name' => 'Y']], false), 'VALUES') === false);
$dirty = MappingAnnotation::render(['concept_id' => 5, 'concept_name' => 'Peso "real"; basal'], [], false);
test('FR-015-03', 'double quotes and ";" in the name do not break the tag',
    $dirty === '@OMOP-CONCEPT="5 | Peso \'real\', basal"' && MappingAnnotation::parse($dirty)['spec']['concept_name'] === "Peso 'real', basal", $dirty);

$merged = MappingAnnotation::merge("@HIDDEN\n@OMOP-DOMAIN=Drug", $tags);
test('FR-015-04', 'merge replaces the @OMOP tags and keeps the rest', strpos($merged, '@HIDDEN') === 0 && strpos($merged, 'Drug') === false);
test('FR-015-04', 'merge is idempotent', MappingAnnotation::merge($merged, $tags) === $merged);
test('FR-015-04', 'merge with empty tags removes the @OMOP tags and leaves the rest',
    MappingAnnotation::merge("@HIDDEN\n@OMOP-DOMAIN=Drug\nteam note", '') === "@HIDDEN\nteam note");
test('FR-015-04', 'on an empty annotation, merge leaves only the tags', MappingAnnotation::merge('', '@OMOP-EXCLUDE') === '@OMOP-EXCLUDE');

section('Proposal from the annotation');
$m = make_module();
test('FR-015-05', 'the module reads the @OMOP annotation of each dictionary field',
    $m->annotation('hr') == MappingAnnotation::parse($dict['hr']['field_annotation']) && $m->annotation('weight') === null);
test('FR-015-05', 'reading the annotation saves nothing in the mapping', !isset($m->store()->load()['fields']['hr']));

section('Adopting annotations');
$m = make_module();
curate($m);
$s = $m->store();
test('FR-015-06', 'adoptAnnotation returns true when it adopts', $s->adoptAnnotation('hr', $m->annotation('hr'), [], $dict) === true);
test('FR-015-06', 'adopting an annotation leaves it as a proposal', $s->spec('hr')['state'] === 'proposed' && $s->spec('hr')['concept_id'] === 3027018);
test('FR-015-06', 'it is marked as edited and with source "annotation"', $s->spec('hr')['edited'] === true && $s->spec('hr')['source'] === 'annotation');
$s->adoptAnnotation('smear2', $m->annotation('smear2'), $m->profile()['smear2']['choices'], $dict);
test('FR-015-06', 'it also adopts the options, with this project\'s label',
    ($s->values('smear2')['1']['concept_id'] ?? null) === 9191 && $s->values('smear2')['1']['source_value'] === 'Positive'
    && $s->values('smear2')['2']['emit'] === false);
$s->adoptAnnotation('glu', MappingAnnotation::parse('@OMOP-DOMAIN=Invented @OMOP-STRATEGY=magic @OMOP-DATE=date_from_other_project @OMOP-VALUES="9=-"'),
    [], $dict);
$g = $s->spec('glu');
test('FR-015-06', 'discards invalid domain and strategy, dates that do not exist here and options the field does not have',
    $g['domain'] === 'Measurement' && $g['value_strategy'] === null && $g['date_field'] === 'visit_date' && !isset($s->values('glu')['9']),
    json_encode([$g['domain'], $g['value_strategy'], $g['date_field']]));
$s->adoptAnnotation('visit_date', MappingAnnotation::parse('@OMOP-EXCLUDE'), [], $dict);
test('FR-015-06', 'an @OMOP-EXCLUDE annotation leaves the field excluded', $s->spec('visit_date')['state'] === MappingStore::S_EXCLUDED);
test('FR-015-06', 'a field approved here is not touched',
    $s->adoptAnnotation('weight', MappingAnnotation::parse('@OMOP-CONCEPT="1 | Other"'), [], $dict) === false
    && $s->spec('weight')['concept_id'] === 3025315 && $s->spec('weight')['state'] === 'approved');
$s->updateField('hr', ['state' => 'approved'], $dict['hr'], []);
$meas = group_by($m->transformer()->run()['tables']['measurement'], 'measurement_concept_id');
test('FR-015-06', 'once approved, the heart rate from the annotation is exported', isset($meas[3027018]));

section('Annotated dictionary');
$m = make_module();
curate($m);
$m->store()->updateField('notes', ['state' => 'excluded'], $dict['notes'], []);
$before = serialize(\REDCap::$dict);
$csv = $m->mappingDocument()->annotatedDictionaryCsv();
$fh = fopen('php://temp', 'w+');
fwrite($fh, substr($csv, 3));
rewind($fh);
$header = fgetcsv($fh, 0, ',', '"', '');
$byField = [];
while ($f = fgetcsv($fh, 0, ',', '"', '')) {
    $byField[$f[0]] = array_combine($header, $f);
}
fclose($fh);
test('FR-015-08', 'it is a REDCap dictionary CSV (BOM and Designer headers)',
    substr($csv, 0, 3) === "\xEF\xBB\xBF" && $header[0] === 'Variable / Field Name' && end($header) === 'Field Annotation' && count($header) === 18);
test('FR-015-08', 'it carries every field of the dictionary', array_keys($byField) === array_keys($dict));
test('FR-015-08', 'approved fields carry their @OMOP tags',
    strpos($byField['weight']['Field Annotation'], '@OMOP-CONCEPT="3025315 | Body weight"') !== false
    && strpos($byField['weight']['Field Annotation'], '@OMOP-DATE=visit_date') !== false);
test('FR-015-08', 'excluded fields carry @OMOP-EXCLUDE', $byField['notes']['Field Annotation'] === '@OMOP-EXCLUDE');
test('FR-015-08', 'fields without a firm decision keep their annotation as it was',
    $byField['smear2']['Field Annotation'] === $dict['smear2']['field_annotation'] && $byField['site']['Field Annotation'] === '');
$zip = $m->exporter()->writeZip($m->transformer()->run());
$z = new ZipArchive();
$z->open($zip['path']);
$annotatedCsv = $z->getFromName('DICCIONARIO_anotado.csv');
test('FR-015-08', 'the ZIP carries DICCIONARIO_anotado.csv with @OMOP on the approved fields', strpos($annotatedCsv, '@OMOP-CONCEPT=""3025315 | Body weight""') !== false);
test('FR-015-08', 'and keeps the annotations that were there', strpos($annotatedCsv, '@HIDDEN') !== false);
$z->close();
unlink($zip['path']);
test('FR-015-09', 'generating the annotated dictionary and the ZIP does not change the project dictionary', serialize(\REDCap::$dict) === $before);
// 'diccionario' too: a Spanish-named setting key would be just as wrong.
test('FR-015-09', 'the module does not store the dictionary in its settings',
    !array_filter(array_keys($m->settings), fn($k) => stripos($k, 'dictionary') !== false || stripos($k, 'diccionario') !== false));

section('Importing MAPEO.json: which fields are imported');
reset_fixture();
$m = make_module();
curate($m);
$m->store()->updateField('notes', ['state' => 'excluded'], \REDCap::$dict['notes'], []);
$doc = $m->mappingDocument()->build();
// The target project: the same instrument with changes.
unset(\REDCap::$dict['glu'], \REDCap::$dict['med_end']);
\REDCap::$dict['fever']['field_type'] = 'radio';
\REDCap::$dict['fever']['select_choices_or_calculations'] = '1, Yes | 0, No';
\REDCap::$dict['bac']['select_choices_or_calculations'] = '1, Positive | 3, Negative';
\REDCap::$dict['sex']['select_choices_or_calculations'] = '1, Hombre | 2, Mujer';
$m2 = make_module();
$m2->store()->updateField('weight', ['domain' => 'Measurement', 'concept_id' => '3036277', 'concept_name' => 'Body height',
    'date_field' => 'visit_date', 'state' => 'approved'], \REDCap::$dict['weight'], []);
$r = $m2->store()->importMapping($doc, $m2->profile(), \REDCap::$dict);
test('FR-015-10', 'a field that does not exist here is not imported (missing)', ($r['skipped']['glu'] ?? '') === 'missing', json_encode($r['skipped']));
test('FR-015-10', 'a field of another type is not imported (type)', ($r['skipped']['fever'] ?? '') === 'type');
test('FR-015-10', 'a field with other option codes is not imported (choices)', ($r['skipped']['bac'] ?? '') === 'choices');
test('FR-015-10', 'a field without a mapping in the source is not imported (no_mapping)', ($r['skipped']['visit_date'] ?? '') === 'no_mapping');
test('FR-015-10', 'imports with the same codes even if the labels are translated', in_array('sex', $r['imported'], true));
test('FR-015-10', 'imports domain, concept, strategy, unit and options',
    $m2->store()->spec('drug')['domain'] === 'Drug' && $m2->store()->spec('drug')['value_strategy'] === 'per_choice'
    && (int) ($m2->store()->values('drug')['1']['concept_id'] ?? 0) === 1782521);

section('Importing MAPEO.json: nothing ends up approved');
test('FR-015-11', 'what is approved here is not touched (approved_here)', ($r['skipped']['weight'] ?? '') === 'approved_here'
    && $m2->store()->spec('weight')['concept_id'] === 3036277 && $m2->store()->spec('weight')['state'] === 'approved');
$imported = array_map(fn($f) => $m2->store()->spec($f), $r['imported']);
test('FR-015-11', 'nothing imported ends up approved', !in_array('approved', array_column($imported, 'state'), true)
    && count($imported) > 3, json_encode(array_column($imported, 'state')));
test('FR-015-11', 'imported fields have source "import" and no approval signature',
    $m2->store()->spec('drug')['source'] === 'import' && $m2->store()->spec('drug')['approved_by'] === null);
test('FR-015-11', 'a date that does not exist here is not imported',
    $m2->store()->spec('drug')['date_field'] === 'med_start' && $m2->store()->spec('drug')['end_date_field'] === null);
test('FR-015-11', 'the import is recorded in the module log',
    in_array('Mapping imported from another project', array_column($m2->logs, 0), true));

section('Importing MAPEO.json: exclusions');
test('FR-015-12', 'exclusions are imported too', in_array('notes', $r['imported'], true)
    && $m2->store()->spec('notes')['state'] === MappingStore::S_EXCLUDED && $m2->store()->spec('notes')['source'] === 'import');

section('Importing MAPEO.json: different labels');
test('FR-015-13', 'flags the codes whose labels differ', $m2->store()->spec('sex')['import_diff'] === ['1', '2']);
test('FR-015-13', 'and warns so that someone confirms each code means the same', in_array('import_labels_differ', array_column($m2->advise('sex'), 'code'), true));
test('FR-015-13', 'with the same labels there is no warning', empty($m2->store()->spec('drug')['import_diff'])
    && !in_array('import_labels_differ', array_column($m2->advise('drug'), 'code'), true));
$m2->store()->updateField('sex', ['state' => 'approved'], \REDCap::$dict['sex'], $m2->profile()['sex']['choices']);
test('FR-015-13', 'approving removes the warning', !in_array('import_labels_differ', array_column($m2->advise('sex'), 'code'), true)
    && !isset($m2->store()->spec('sex')['import_diff']));

section('Import texts');
// The reasons returned by the import above, plus "already approved here".
$reasons = array_values(array_unique(array_merge(array_values($r['skipped']), ['missing', 'approved_here', 'type', 'choices', 'no_mapping'])));
// The words are UI text: the Spanish ones are checked against Spanish.ini.
foreach (['Spanish' => ['códigos', 'opciones'], 'English' => ['codes', 'options']] as $lang => [$codesWord, $optionsWord]) {
    $ini = parse_ini_file(dirname(__DIR__, 2) . "/lang/$lang.ini");
    test('FR-015-15', "$lang: ed_import_title says the same option codes are needed, not the same options",
        mb_stripos($ini['ed_import_title'], $codesWord) !== false && mb_stripos($ini['ed_import_title'], $optionsWord) === false,
        $ini['ed_import_title']);
    $missing = array_filter($reasons, fn($mo) => strpos($ini['ed_import_skipped'], $mo) === false);
    test('FR-015-15', "$lang: ed_import_skipped explains every reason for not importing, including no_mapping",
        !$missing, implode(',', $missing));
}

section('Import is robust to a malformed MAPEO.json');
reset_fixture();
$m = make_module();
$broken = ['fields' => [
    'bac'    => ['field_type' => 'radio', 'domain' => 'Measurement', 'choices' => ['x']],
    'fever'  => 'not an object',
    'weight' => ['field_type' => 'text', 'domain' => 'Measurement', 'concept_id' => 3025315, 'concept_name' => 'Body weight', 'state' => 'approved', 'choices' => []],
]];
$r = null;
$threw = throws(function () use ($m, $broken, &$r) {
    $r = $m->store()->importMapping($broken, $m->profile(), \REDCap::$dict);
});
test('FR-015-16', 'a malformed entry does not interrupt the import', !$threw && is_array($r));
test('FR-015-16', 'malformed entries are skipped with the reason "invalid"',
    is_array($r) && ($r['skipped']['bac'] ?? null) === 'invalid' && ($r['skipped']['fever'] ?? null) === 'invalid');
test('FR-015-16', 'and the rest is imported', is_array($r) && in_array('weight', $r['imported'], true));
foreach (['Spanish' => parse_ini_file(dirname(__DIR__, 2) . '/lang/Spanish.ini'), 'English' => parse_ini_file(dirname(__DIR__, 2) . '/lang/English.ini')] as $lang => $ini) {
    test('FR-015-16', "$lang: ed_import_skipped explains the reason invalid", strpos($ini['ed_import_skipped'], 'invalid') !== false);
}
