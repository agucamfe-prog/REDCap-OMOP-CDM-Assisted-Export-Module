<?php
// Spec 016 — Pseudonymization
use OmopCdm\OmopCdmExportModule\Pseudonymizer;

section('Pseudonymizer');
$ps = new Pseudonymizer(30, 'year', true, 'salt');
$o = $ps->offset('7');
test('FR-016-03', 'stable, non-zero shift within ±30', $o === $ps->offset('7') && $o !== 0 && abs($o) <= 30, (string) $o);
test('FR-016-03', 'another instance with the same salt gives the same shift', (new Pseudonymizer(30, 'full', false, 'salt'))->offset('7') === $o);
$offs = [];
for ($i = 0; $i < 40; $i++) {
    $offs[] = $ps->offset((string) $i);
}
test('FR-016-03', 'each person has their own: 40 records do not share a single shift', count(array_unique($offs)) > 10);
$otherSalt = new Pseudonymizer(30, 'full', false, 'other');
$different = 0;
for ($i = 0; $i < 40; $i++) {
    $different += $otherSalt->offset((string) $i) !== $offs[$i] ? 1 : 0;
}
test('FR-016-03', 'with another salt the shifts change', $different > 30);
$p1 = new Pseudonymizer(1, 'full', false, 's');
$neverZero = true;
for ($i = 0; $i < 60; $i++) {
    $neverZero = $neverZero && in_array($p1->offset((string) $i), [-1, 1], true);
}
test('FR-016-03', 'never zero, not even with a 1-day margin', $neverZero);
test('FR-016-04', 'shifting preserves intervals', (new DateTime($ps->shift('2024-03-10', '7')))->diff(new DateTime($ps->shift('2024-03-01', '7')))->days === 9);
test('FR-016-04', 'the date moves by exactly the person\'s shift',
    (int) (new DateTime('2024-03-01'))->diff(new DateTime($ps->shift('2024-03-01', '7')))->format('%r%a') === $o);
$p0 = new Pseudonymizer(0, 'full', false, 's');
$pneg = new Pseudonymizer(-5, 'full', false, 's');
test('FR-016-05', 'with 0 days (or negative) there is no shift',
    !$p0->shiftsDates() && !$pneg->shiftsDates() && $p0->offset('1') === 0 && $p0->shift('2024-01-01', '1') === '2024-01-01');
test('FR-016-05', 'null stays null and a text that is not a date gives null',
    $ps->shift(null, '7') === null && $ps->shift('nope', '7') === null);
test('FR-016-05', 'an impossible date is not normalised: "2024-13-01" and "2024-02-30" give null',
    $ps->shift('2024-13-01', '7') === null && $ps->shift('2024-02-30', '7') === null && $ps->shift('2024-02-29', '7') !== null);
$row = ['day_of_birth' => 3, 'month_of_birth' => 4, 'birth_datetime' => 'x', 'year_of_birth' => 1990];
$b = $ps->birth($row);
test('FR-016-06', '"year" precision removes month and day', $b['month_of_birth'] === null && $b['day_of_birth'] === null && $b['year_of_birth'] === 1990
    && $b['birth_datetime'] === null);
$bm = (new Pseudonymizer(0, 'month', false, ''))->birth($row);
test('FR-016-06', '"year and month" precision removes only the day and the time', $bm['month_of_birth'] === 4 && $bm['day_of_birth'] === null && $bm['birth_datetime'] === null);
test('FR-016-06', 'full (or unknown) precision touches nothing',
    (new Pseudonymizer(0, 'full', false, ''))->birth($row) === $row && (new Pseudonymizer(0, 'decade', false, ''))->birthPrecision() === 'full');

section('Nothing is applied by default');
$m0 = make_module();
curate($m0);
$r0 = $m0->transformer()->run();
$P0 = array_column($r0['tables']['person'], null, 'person_id');
$w0 = array_values(array_filter($r0['tables']['measurement'], fn($r) => $r['person_id'] === 1 && $r['measurement_concept_id'] === 3025315))[0];
test('FR-016-11', 'without settings: Record ID, real dates, full birth date and NOTE',
    $P0[1]['person_source_value'] === '1' && $w0['measurement_date'] === '2024-03-01' && $P0[1]['day_of_birth'] === 10
    && count($r0['tables']['note']) === 1 && !isset($r0['stats']['date_shift']));
test('FR-016-11', 'without shifting or hashing no salt is created', !isset($m0->settings['person-hash-salt']));

section('In the export');
reset_fixture();
\REDCap::$dict['symptoms'] = f('symptoms', 'base', 'text', 'Other symptoms');
\REDCap::$dict['death_cause'] = f('death_cause', 'base', 'text', 'Cause of death');
\REDCap::$data['1'][1]['symptoms'] = 'headache since Monday';
\REDCap::$data['2'][1]['death_cause'] = 'Respiratory failure';
$settings = ['date-shift-days' => '30', 'birth-precision' => 'year', 'suppress-free-text' => true, 'hash-person-source-value' => true];
$m = make_module($settings);
curate($m);
$m->store()->updateField('symptoms', ['domain' => 'Observation', 'concept_id' => '4038150', 'state' => 'approved'], \REDCap::$dict['symptoms'], []);
$m->store()->updateField('death_cause', ['domain' => 'Death', 'state' => 'approved'], \REDCap::$dict['death_cause'], []);
$res = $m->transformer()->run();
$T = $res['tables'];
$P = array_column($T['person'], null, 'person_id');
$salt = $m->settings['person-hash-salt'] ?? '';
test('FR-016-02', 'the project salt is created once: 32 hexadecimal characters in person-hash-salt',
    (bool) preg_match('/^[0-9a-f]{32}$/', $salt));
$res2 = $m->transformer()->run();
test('FR-016-02', 'and it is reused in the following exports', $m->settings['person-hash-salt'] === $salt);
test('NFR-016-01', 'with the same salt, two exports give the same dates and the same hashes',
    $res2['tables']['person'] === $T['person'] && $res2['tables']['measurement'] === $T['measurement']);
test('FR-016-01', 'hashed person_source_value', strlen($P[1]['person_source_value']) === 32);
test('FR-016-01', 'the hash is SHA-256 of salt|Record ID, truncated to 32',
    $P[1]['person_source_value'] === substr(hash('sha256', $salt . '|1'), 0, 32) && $P[2]['person_source_value'] !== $P[1]['person_source_value']);
$mh = make_module(['hash-person-source-value' => true]);
curate($mh);
$rh = $mh->transformer()->run();
test('FR-016-01', 'the hash does not depend on shifting dates',
    strlen($rh['tables']['person'][0]['person_source_value']) === 32 && $rh['tables']['person'][0]['month_of_birth'] === 5);
$off1 = (new Pseudonymizer(30, 'full', false, $salt))->offset('1');
$w = array_values(array_filter($T['measurement'], fn($r) => $r['person_id'] === 1 && $r['measurement_concept_id'] === 3025315))[0];
$d = (new DateTime('2024-03-01'))->diff(new DateTime($w['measurement_date']));
test('FR-016-04', 'dates shifted, by 30 days at most', $w['measurement_date'] !== '2024-03-01' && $d->days <= 30, $w['measurement_date']);
test('FR-016-02', 'the shift comes from the same salt as the hash', (int) $d->format('%r%a') === $off1);
$drug = array_values(array_filter($T['drug_exposure'], fn($r) => $r['person_id'] === 1));
$interval = (new DateTime($drug[0]['drug_exposure_start_date']))->diff(new DateTime($w['measurement_date']))->days;
test('FR-016-04', 'each person\'s intervals are preserved', $interval === 1);
$shifted = fn(string $real, ?string $exp, string $rec) => $exp !== null
    && (int) (new DateTime($real))->diff(new DateTime($exp))->format('%r%a') === (new Pseudonymizer(30, 'full', false, $salt))->offset($rec);
$iso = array_column($drug, null, 'drug_concept_id')[1782521];
$V = array_column($T['visit_occurrence'], null, 'person_id');
$OP = array_column($T['observation_period'], null, 'person_id');
test('FR-016-04', 'the end date, the visit, the death and the observation period move as well',
    $shifted('2024-03-20', $iso['drug_exposure_end_date'], '1')
    && $shifted('2024-03-01', $V[1]['visit_start_date'], '1')
    && $shifted('2024-06-01', $T['death'][0]['death_date'], '2')
    && $shifted('2024-04-01', $OP[2]['observation_period_start_date'], '2')
    && $shifted('2024-03-20', $OP[1]['observation_period_end_date'], '1'));
test('FR-016-06', 'birth with the year only', $P[1]['month_of_birth'] === null && $P[1]['birth_datetime'] === null);
$mMonth = make_module(['date-shift-days' => '30', 'birth-precision' => 'month']);
$mMonth->settings = ['person-hash-salt' => $salt] + $mMonth->settings + $m->settings;
$Pm = array_column($mMonth->transformer()->run()['tables']['person'], null, 'person_id');
$birth = (new DateTime('1960-05-10'))->modify(($off1 >= 0 ? '+' : '') . $off1 . ' days');
test('FR-016-06', 'with "year and month", year and month are those of the already shifted date',
    $Pm[1]['year_of_birth'] === (int) $birth->format('Y') && $Pm[1]['month_of_birth'] === (int) $birth->format('n')
    && $Pm[1]['day_of_birth'] === null);

section('Free text');
$sym = array_values(array_filter($T['observation'], fn($r) => $r['observation_concept_id'] === 4038150));
test('FR-016-07', 'the free-text field emits its row without the text',
    count($sym) === 1 && $sym[0]['value_as_string'] === null && $sym[0]['value_source_value'] === null);
$glu = array_column(array_values(array_filter($T['measurement'], fn($r) => $r['measurement_concept_id'] === 3004501)), null, 'person_id');
test('FR-016-07', 'if the text was a number, the number and its original are kept',
    $glu[3]['value_as_number'] == 95 && $glu[1]['value_as_number'] == 70 && $glu[1]['value_source_value'] === '< 70');
test('FR-016-07', 'only the answers whose text was removed are counted (symptoms and cause of death)',
    ($res['stats']['free_text_suppressed'] ?? 0) === 2, (string) ($res['stats']['free_text_suppressed'] ?? 0));
test('FR-016-07', 'a measurement left with no value at all is not emitted: it is skipped with its reason',
    !isset($glu[2]) && ($res['skipped']['free_text_no_value|glu'] ?? 0) === 1);
$lang = \ExternalModules\AbstractExternalModule::$lang;
$es = parse_ini_file(dirname(__DIR__, 2) . '/lang/Spanish.ini');
test('FR-016-07', 'the report explains the reason in both languages',
    isset($lang['skip_free_text_no_value'], $es['skip_free_text_no_value'])
    && strpos($m->skipLabel('free_text_no_value|glu'), 'glu') !== false);
test('FR-016-08', 'no free text: no NOTE, and it is counted', empty($T['note']) && ($res['stats']['notes_suppressed'] ?? 0) === 1);
test('FR-016-09', 'a hand-written cause of death does not go out', count($T['death']) === 1 && $T['death'][0]['cause_source_value'] === null);

section('Statement in the report');
$report = function ($mod, array $r) {
    if (!class_exists('\ZipArchive')) {
        return null;
    }
    $z = $mod->exporter()->writeZip($r);
    $za = new \ZipArchive();
    $za->open($z['path']);
    $txt = (string) $za->getFromName('INFORME.txt');
    $za->close();
    unlink($z['path']);
    return preg_replace('/\s+/', ' ', $txt);
};
test('FR-016-10', 'the export flags that dates were shifted', ($res['stats']['date_shift'] ?? 0) === 1);
$txt = $report($m, $res);
if ($txt !== null) {
    $lang = \ExternalModules\AbstractExternalModule::$lang;
    test('FR-016-10', 'the report states each measure applied', strpos($txt, $lang['rep_privacy_applied']) !== false
        && strpos($txt, 'up to 30 days') !== false && strpos($txt, $lang['rep_birth_year']) !== false
        && strpos($txt, 'free-text answer(s) not exported') !== false && strpos($txt, 'person_source_value is hashed') !== false);
    $txt0 = $report($m0, $r0);
    test('FR-016-10', 'without pseudonymization, the report does not state it', strpos($txt0, $lang['rep_privacy_applied']) === false);
}
// Both languages are checked: the Spanish pattern looks for «anónimo».
$anonymous = false;
foreach (glob(dirname(__DIR__, 2) . '/lang/*.ini') as $ini) {
    foreach (parse_ini_file($ini) as $text) {
        $anonymous = $anonymous || preg_match('/anonym|anónim|anonim/iu', (string) $text);
    }
}
test('NFR-016-03', 'no module text calls the result anonymous', !$anonymous);

section('Standalone year of birth');
\REDCap::$dict['yob'] = f('yob', 'base', 'text', 'Year of birth', '', 'integer');
foreach (array_keys(\REDCap::$data) as $i) {
    \REDCap::$data[$i][1]['yob'] = '';
}
\REDCap::$data['3'][1]['dob'] = '';
\REDCap::$data['3'][1]['yob'] = '1981';
$my = make_module();
$my->settings = ['date-shift-days' => '30', 'birth-year-field' => 'yob', 'birth-precision' => 'full'] + $m->settings;
$Py = array_column($my->transformer()->run()['tables']['person'], null, 'person_id');
test('FR-016-12', 'a standalone year of birth is not shifted: it goes out as is, without month or day',
    $Py[3]['year_of_birth'] === 1981 && $Py[3]['month_of_birth'] === null && $Py[3]['day_of_birth'] === null
    && $Py[1]['day_of_birth'] !== 10);
