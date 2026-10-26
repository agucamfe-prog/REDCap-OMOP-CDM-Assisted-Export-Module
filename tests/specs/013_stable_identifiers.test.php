<?php
// Spec 013 — Stable identifiers
use OmopCdm\OmopCdmExportModule\IdRegistry;
use OmopCdm\OmopCdmExportModule\Transformer;

section('hash31');
$h = IdRegistry::hash31('a|b');
test('FR-013-08', 'hash31 is deterministic and positive', $h === IdRegistry::hash31('a|b') && IdRegistry::hash31('x') > 0);
test('FR-013-08', 'it is the low 31 bits of the first 8 hex digits of SHA-256',
    $h === (hexdec(substr(hash('sha256', 'a|b'), 0, 8)) & 0x7FFFFFFF));
$inRange = true;
for ($i = 0; $i < 2000; $i++) {
    $n = IdRegistry::hash31('k' . $i);
    $inRange = $inRange && is_int($n) && $n >= 1 && $n <= 2147483647;
}
test('FR-013-08', 'always between 1 and 2^31-1: it fits in the DDL integer', $inRange);

section('Fact id assignment');
$rows = [['__key' => 'k1'], ['__key' => 'k2'], ['__key' => 'k3']];
$rows2 = array_reverse($rows);
IdRegistry::assignFactIds($rows, 'id');
IdRegistry::assignFactIds($rows2, 'id');
test('FR-013-09', 'the ids do not depend on the order of the rows', array_column($rows, 'id') == array_reverse(array_column($rows2, 'id')));
test('FR-013-07', 'without a collision, the id is the hash31 of the key', $rows[0]['id'] === IdRegistry::hash31('k1'));
test('FR-013-10', 'the internal __key does not reach the rows', !array_filter($rows, fn($r) => array_key_exists('__key', $r)));
// A pair of keys with the same hash31 (889893814), found by brute force.
$a = 'measurement|58494';
$b = 'measurement|71308';
$col1 = [['__key' => $b], ['__key' => $a]];
$col2 = [['__key' => $a], ['__key' => $b]];
IdRegistry::assignFactIds($col1, 'measurement_id');
IdRegistry::assignFactIds($col2, 'measurement_id');
test('FR-013-09', 'collision: the smaller key keeps the hash and the other gets the next free one',
    IdRegistry::hash31($a) === IdRegistry::hash31($b)
    && $col1[1]['measurement_id'] === IdRegistry::hash31($a) && $col1[0]['measurement_id'] === IdRegistry::hash31($a) + 1);
test('FR-013-09', 'collision resolution does not depend on the arrival order',
    $col2[0]['measurement_id'] === $col1[1]['measurement_id'] && $col2[1]['measurement_id'] === $col1[0]['measurement_id']);

section('Entity registry');
$mr = make_module();
$reg = new IdRegistry($mr);
test('FR-013-01', 'the first key gets 1, the next one 2, and a known key keeps its number',
    $reg->id('person', 'A') === 1 && $reg->id('person', 'B') === 2 && $reg->id('person', 'A') === 1);
test('FR-013-03', 'each entity kind has its own counter',
    $reg->id('visit', 'A|1|0') === 1 && $reg->id('care_site', 'site=1') === 1 && $reg->id('provider', 'doctor=1') === 1);
test('FR-013-14', 'has() asks without creating', $reg->has('person', 'A') && !$reg->has('person', 'Z') && !$reg->has('person', 'Z'));
test('FR-013-14', 'counts() gives the number of entities per kind',
    $reg->counts() === ['person' => 2, 'visit' => 1, 'care_site' => 1, 'provider' => 1]);
test('FR-013-05', 'nothing is saved until save()', !isset($mr->settings[IdRegistry::SETTING_KEY]));
$reg->save();
$saved = json_decode($mr->settings[IdRegistry::SETTING_KEY], true);
test('FR-013-04', 'it is saved as JSON in the omop-id-registry setting, with ids, next number and date',
    $saved['ids']['person'] === ['A' => 1, 'B' => 2] && $saved['next']['person'] === 3 && !empty($saved['updated']));
$reg2 = new IdRegistry($mr);
test('FR-013-01', 'another instance reads the saved registry', $reg2->id('person', 'B') === 2 && $reg2->id('person', 'C') === 3);
$mr->settings[IdRegistry::SETTING_KEY] = $saved;
test('FR-013-04', 'it also accepts the registry already decoded as an array', (new IdRegistry($mr))->id('person', 'B') === 2);
$mr->settings[IdRegistry::SETTING_KEY] = '{broken';
test('FR-013-04', 'an unreadable registry is treated as empty', (new IdRegistry($mr))->id('person', 'X') === 1);
$mr->settings[IdRegistry::SETTING_KEY] = json_encode($saved);
$reg3 = new IdRegistry($mr);
$reg3->id('person', 'A');
$reg3->save();
test('FR-013-05', 'with no new numbers, save() does not rewrite the setting', $mr->settings[IdRegistry::SETTING_KEY] === json_encode($saved));
$prev = new IdRegistry($mr, true);
$nPrev = $prev->id('person', 'NEW');
$prev->save();
test('FR-013-06', 'in preview a provisional number is assigned but not saved',
    $nPrev === 3 && $mr->settings[IdRegistry::SETTING_KEY] === json_encode($saved));

section('Across exports');
$m = make_module();
curate($m);
$res1 = $m->transformer()->run();
$T = $res1['tables'];
$personIds = array_column($T['person'], 'person_id', 'person_source_value');
$factIds = array_column($T['measurement'], 'measurement_id');
test('FR-013-01', 'person_id comes from the registry: 1, 2 and 3 in order of appearance', $personIds === ['1' => 1, '2' => 2, '3' => 3]);
$regJson = json_decode($m->settings[IdRegistry::SETTING_KEY], true);
test('FR-013-03', 'visits and care sites are registered too, with their key', $regJson['ids']['visit'] === ['1|1|0' => 1, '2|1|0' => 2, '3|1|0' => 3]
    && $regJson['ids']['care_site'] === ['site=1' => 1, 'site=2' => 2]);
test('NFR-013-01', 'the registry stores only entities, no facts', array_keys($regJson['ids']) === ['person', 'care_site', 'visit']);
$w1 = array_values(array_filter($T['measurement'], fn($r) => $r['person_id'] === 1 && $r['measurement_concept_id'] === 3025315))[0];
test('FR-013-07', 'the key of a fact: table|record|event|form|instance|field|option',
    $w1['measurement_id'] === IdRegistry::hash31('measurement|1|1|||weight|'));
$d1 = array_column($T['drug_exposure'], null, 'drug_concept_id')[1782521];
test('FR-013-07', 'in a repeating instrument the key includes the form and the instance',
    $d1['drug_exposure_id'] === IdRegistry::hash31('drug_exposure|1|1|meds|1|drug|1'));
$noKey = true;
foreach ($T as $rows) {
    foreach ($rows as $r) {
        $noKey = $noKey && !array_key_exists('__key', $r);
    }
}
test('FR-013-10', 'no exported table carries __key', $noKey);
test('FR-013-11', 'observation_period_id is the person_id',
    array_column($T['observation_period'], 'observation_period_id') === array_column($T['observation_period'], 'person_id'));
$regBefore = $m->settings[IdRegistry::SETTING_KEY];
$res2 = $m->transformer()->run();
test('FR-013-12', 'same export → same fact ids', array_column($res2['tables']['measurement'], 'measurement_id') == $factIds);
test('FR-013-05', 'a second export with no new entities does not touch the registry', $m->settings[IdRegistry::SETTING_KEY] === $regBefore);
$csv = function ($mod) {
    $z = $mod->exporter()->writeZip($mod->transformer()->run());
    $za = new \ZipArchive();
    $za->open($z['path']);
    $out = [];
    for ($i = 0; $i < $za->numFiles; $i++) {
        $n = $za->getNameIndex($i);
        if (preg_match('/^[A-Z_]+\.csv$/', $n)) {
            $out[$n] = $za->getFromIndex($i);
        }
    }
    $za->close();
    unlink($z['path']);
    return $out;
};
if (class_exists('\ZipArchive')) {
    $c1 = $csv($m);
    $c2 = $csv($m);
    test('FR-013-13', 'two exports of the same data give byte-for-byte identical CSVs',
        count($c1) >= 8 && $c1 === $c2, implode(',', array_keys(array_diff_assoc($c1, $c2))));
} else {
    test('FR-013-13', 'two exports of the same data give identical tables',
        json_encode($m->transformer()->run()['tables']) === json_encode($m->transformer()->run()['tables']));
}

\REDCap::$data['4'] = rec('4', ['dob' => '1990-01-01', 'sex' => '2', 'visit_date' => '2024-07-01', 'weight' => '60']);
$reg = $m->settings[IdRegistry::SETTING_KEY];
$prevRes = $m->transformer()->run(null, true);
test('FR-013-06', 'the preview does not use up ids', $m->settings[IdRegistry::SETTING_KEY] === $reg);
$p4prev = array_column($prevRes['tables']['person'], 'person_id', 'person_source_value')['4'];
unset(\REDCap::$data['2']);
$res3 = $m->transformer()->run();
$P3 = array_column($res3['tables']['person'], 'person_id', 'person_source_value');
test('FR-013-02', 'deleting a record does not renumber the others and a number is never reused',
    $P3['1'] === $personIds['1'] && $P3['3'] === $personIds['3'] && $P3['4'] === 4 && !in_array(2, $P3, true));
test('FR-013-06', 'the provisional number from the preview is the one the export assigns later', $p4prev === 4);
$w3 = array_values(array_filter($res3['tables']['measurement'], fn($r) => $r['person_id'] === $personIds['3']));
$w1 = array_values(array_filter($T['measurement'], fn($r) => $r['person_id'] === $personIds['3']));
test('FR-013-12', 'nor do their facts change id', array_column($w3, 'measurement_id') == array_column($w1, 'measurement_id'));
$v3 = array_column($res3['tables']['visit_occurrence'], 'visit_occurrence_id', 'person_id');
test('FR-013-03', 'each person\'s visit keeps its number and the new one receives the next',
    $v3[1] === 1 && $v3[3] === 3 && $v3[4] === 4);
