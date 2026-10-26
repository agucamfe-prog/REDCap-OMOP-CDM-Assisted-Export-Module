<?php
// Spec 005 — Mapping dashboard
use OmopCdm\OmopCdmExportModule\MappingStore;

$runPage = require dirname(__DIR__) . '/support/run_page.php';
$dash = fn(array $o = []) => $runPage('pages/dashboard.php', $o + ['settings' => make_module()->settings]);
$m0 = make_module();
$tx = fn(string $k, ...$a) => $m0->tt($k, ...$a);
/** Dashboard cards: label => number. */
$cards = function (string $html): array {
    preg_match_all('/<div class="n">\s*(\d+)\s*<\/div>\s*<div class="l">([^<]*)<\/div>/', $html, $mm, PREG_SET_ORDER);
    $o = [];
    foreach ($mm as [, $n, $l]) {
        $o[trim($l)] = (int) $n;
    }
    return $o;
};
$tbody = fn(string $html) => (string) strstr((string) strstr($html, '<tbody>'), '</tbody>', true);
/** Table row of one field. */
$row = function (string $html, string $field) use ($tbody): string {
    foreach (explode('<tr>', $tbody($html)) as $tr) {
        if (strpos($tr, '<code>' . $field . '</code>') !== false) {
            return $tr;
        }
    }
    return '';
};

section('Header and cards');
$r = $dash();
$h = $r['out'];
test('FR-005-01', 'title, CDM version (5.4 by default) and introduction', strpos($h, $tx('dash_title')) !== false
    && strpos($h, 'CDM v5.4') !== false && strpos($h, $tx('dash_intro')) !== false, $r['err']);
test('FR-005-01', 'the version comes from the cdm-version setting', strpos($dash(['settings' => ['cdm-version' => '5.3']])['out'], 'CDM v5.3') !== false);
$c = $cards($h);
test('FR-005-02', 'six cards: fields, with a target, low confidence, manual coding, identifiers and single event',
    $c === ['Fields' => 18, 'With a target' => 17, 'Low confidence' => 2, 'Manual coding' => 1, 'Identifiers' => 0, 'Single event' => 1],
    json_encode($c));
\REDCap::$dict['phone'] = f('phone', 'base', 'text', 'Phone', '', '', '', 'y');
\Project::$conf['longitudinal'] = true;
\Project::$conf['eventInfo'] = [1 => ['descrip' => 'Basal'], 2 => ['descrip' => 'Follow-up'], 3 => ['descrip' => 'End']];
$c = $cards($dash()['out']);
reset_fixture();
test('FR-005-02', 'identifiers flagged in REDCap are counted, and in a longitudinal project the events are counted',
    ($c['Identifiers'] ?? null) === 1 && ($c['Events'] ?? null) === 3 && !isset($c['Single event']), json_encode($c));
test('FR-005-03', 'with repeating instruments it warns how many there are', strpos($h, $tx('repeating_note', 1)) !== false);
\Project::$conf['RepeatingFormsEvents'] = [];
test('FR-005-03', 'without them, it does not', strpos($dash()['out'], 'alert alert-info') === false);
reset_fixture();

section('Breakdown by target');
$sum = $m0->profileSummary($m0->profile());
$desc = array_values($sum);
rsort($desc);
test('FR-005-04', 'the count per proposed domain adds up to all fields and goes from highest to lowest',
    array_sum($sum) === 18 && array_key_first($sum) === 'Measurement' && $sum['Measurement'] === 4
    && array_values($sum) === $desc && count($sum) === 10);
test('FR-005-04', 'the dashboard renders one label per domain with its count',
    preg_match('/Measurement <b>4<\/b>/', $h) === 1 && preg_match('/Condition <b>2<\/b>/', $h) === 1
    && substr_count($h, 'class="badge badge-light"') === count($sum));
test('FR-005-04', 'the two module codes are translated; OMOP domains are shown as they are',
    preg_match('/Not mapped <b>1<\/b>/', $h) === 1 && preg_match('/Date anchor <b>2<\/b>/', $h) === 1
    && strpos($h, 'NotMapped <b>') === false);

section('Table');
test('FR-005-05', 'one row per field', substr_count($tbody($h), '<tr>') === 18);
$w = $row($h, 'weight');
test('FR-005-05', 'each row: field, label, instrument, type and validation, value and unit, target and table, confidence and reason',
    strpos($w, 'Weight (kg)') !== false && strpos($w, '<td>base</td>') !== false && strpos($w, 'number') !== false
    && strpos($w, 'numeric') !== false && strpos($w, 'unit: kg') !== false && strpos($w, '<b>Measurement</b>') !== false
    && strpos($w, 'measurement') !== false && strpos($w, '>' . "\n                    medium") !== false
    && strpos($w, htmlspecialchars($m0->profile()['weight']['reason'])) !== false);
test('FR-005-05', 'categorical fields say how many options they have', strpos($row($h, 'comorb'), $tx('lbl_options', 3)) !== false);
\REDCap::$dict['notes']['field_label'] = str_repeat('Long label ', 20);
$hl = $row($dash()['out'], 'notes');
reset_fixture();
test('FR-005-05', 'the label is truncated to 90 characters', strpos($hl, str_repeat('Long label ', 8) . 'L…') !== false
    && strpos($hl, str_repeat('Long label ', 9)) === false);
$manual = 'title="' . $tx('badge_manual_title') . '">' . $tx('badge_manual') . '</span>';
test('FR-005-06', 'free text carries the "manual" badge', strpos($row($h, 'glu'), $manual) !== false
    && strpos($row($h, 'weight'), $manual) === false);
$conf = fn(string $tr) => preg_match('/badge (badge-[a-z]+)">\s*(high|medium|low)\s*</', $tr, $mm) ? $mm[1] . ':' . $mm[2] : '';
test('FR-005-06', 'confidence goes in a coloured badge: high green, medium amber, low grey',
    $conf($row($h, 'bac')) === 'badge-success:high' && $conf($row($h, 'weight')) === 'badge-warning:medium'
    && $conf($row($h, 'glu')) === 'badge-secondary:low');
\REDCap::$dict['phone'] = f('phone', 'base', 'text', 'Phone', '', '', '', 'y');
$hp = $row($dash()['out'], 'phone');
reset_fixture();
test('FR-005-06', 'an identifier carries the PHI badge', strpos($hp, 'badge badge-danger') !== false && strpos($hp, $tx('badge_phi')) !== false
    && strpos($row($h, 'weight'), 'badge-danger') === false);

section('Escaping');
\REDCap::$dict['evil'] = f('evil', 'base<i>', 'text', 'Dose & "mg" <script>alert(1)</script>', '', 'x"><b>');
$he = $dash()['out'];
reset_fixture();
test('FR-005-07', 'what comes from the data dictionary is escaped before rendering', strpos($he, '<script>alert(1)</script>') === false
    && strpos($he, 'Dose &amp; &quot;mg&quot;') !== false && strpos($he, 'base&lt;i&gt;') !== false
    && strpos($he, 'x&quot;&gt;&lt;b&gt;') !== false && strpos($he, 'x"><b>') === false);

section('Proposal, not saved mapping');
$m = make_module();
curate($m);
$m->store()->updateField('weight', ['domain' => 'Observation']);
$before = $m->settings;
$r = $runPage('pages/dashboard.php', ['settings' => $before]);
test('FR-005-08', 'the dashboard shows the classifier proposal even if the saved mapping says otherwise',
    strpos($row($r['out'], 'weight'), '<b>Measurement</b>') !== false && preg_match('/Measurement <b>4<\/b>/', $r['out']) === 1);
test('FR-005-08', 'and writes nothing: neither mapping nor settings', $r['settings'] == $before && $r['logs'] === []);
$r = $dash();
test('FR-005-08', 'opening it without a mapping creates none', !isset($r['settings'][MappingStore::SETTING_KEY]));

section('Downloads');
$r = $dash(['get' => ['download' => 'csv']]);
$csv = $r['out'];
$lines = array_map('str_getcsv', array_values(array_filter(explode("\n", substr($csv, 3)), 'strlen')));
test('FR-005-09', 'CSV with UTF-8 BOM and the 14 columns in order', substr($csv, 0, 3) === "\xEF\xBB\xBF"
    && $lines[0] === ['field_name', 'form_name', 'field_type', 'validation', 'field_label', 'is_identifier', 'value_kind', 'unit_hint',
                      'mappable', 'choice_count', 'suggested_domain', 'suggested_table', 'confidence', 'reason'], $r['err']);
$byName = array_column(array_slice($lines, 1), null, 0);
test('FR-005-09', 'one row per field with the proposal', count($lines) === 19
    && $byName['weight'] === ['weight', 'base', 'text', 'number', 'Weight (kg)', '', 'numeric', 'kg', 'auto', '0', 'Measurement',
                              'measurement', 'medium', $m0->profile()['weight']['reason']]
    && $byName['glu'][8] === 'manual' && $byName['comorb'][9] === '3');
\REDCap::$dict['phone'] = f('phone', 'base', 'text', 'Phone', '', '', '', 'y');
$rows = array_map('str_getcsv', explode("\n", $dash(['get' => ['download' => 'csv']])['out']));
reset_fixture();
test('FR-005-09', 'is_identifier is "y" or empty', in_array(['phone', 'base', 'text', '', 'Phone', 'y'], array_map(fn($x) => array_slice($x, 0, 6), $rows), true));
test('FR-005-09', 'it downloads as omop_propuesta_pid<pid>_<date>.csv', in_array('Content-Type: text/csv; charset=utf-8', $r['headers'], true)
    && in_array('Content-Disposition: attachment; filename="omop_propuesta_pid1_' . date('Ymd') . '.csv"', $r['headers'], true));
test('FR-005-09', 'the dashboard links the download', strpos($h, 'pages/dashboard.php&download=csv') !== false && strpos($h, $tx('btn_csv')) !== false);
$r = $dash(['get' => ['download' => 'json']]);
$j = json_decode($r['out'], true);
test('FR-005-10', 'JSON with date, project, CDM version, structure and full proposal',
    is_array($j) && array_keys($j) === ['generated', 'project_id', 'cdm_version', 'structure', 'proposal']
    && $j['project_id'] === 1 && $j['cdm_version'] === '5.4' && $j['structure'] == $m0->getProjectStructure()
    && $j['proposal'] == $m0->profile() && strtotime($j['generated']) !== false);
test('FR-005-10', 'it downloads as omop_propuesta_pid<pid>_<date>.json',
    in_array('Content-Disposition: attachment; filename="omop_propuesta_pid1_' . date('Ymd') . '.json"', $r['headers'], true)
    && in_array('Content-Type: application/json; charset=utf-8', $r['headers'], true)
    && strpos($h, 'pages/dashboard.php&download=json') !== false);
test('FR-005-11', 'every download is recorded in the module log, with the version',
    $r['logs'] === [['Mapping proposal download (JSON)', ['module_version' => 'vtest']]]
    && $dash(['get' => ['download' => 'csv']])['logs'][0][0] === 'Mapping proposal download (CSV)');
test('FR-005-13', 'downloads are sent before rendering: no REDCap header or footer and no HTML',
    strpos($csv, '<!--rc-header-->') === false && strpos($r['out'], '<!--rc-') === false && strpos($csv, '<') === false
    && strpos($h, '<!--rc-header-->') === 0 && substr($h, -strlen('<!--rc-footer-->')) === '<!--rc-footer-->');
test('FR-005-13', 'an unknown download value renders the dashboard', strpos($dash(['get' => ['download' => 'xml']])['out'], '<!--rc-header-->') === 0);

section('Non-functional');
$src = file_get_contents(dirname(__DIR__, 2) . '/pages/dashboard.php');
preg_match_all("/tt\\('([a-z_]*[a-z])'[,)]/", $src, $mm);
$es = parse_ini_file(dirname(__DIR__, 2) . '/lang/Spanish.ini');
$en = parse_ini_file(dirname(__DIR__, 2) . '/lang/English.ini');
$keys = array_unique(array_merge($mm[1], ['conf_high', 'conf_medium', 'conf_low', 'domain_NotMapped', 'domain_DateAnchor']));
$missing = array_filter($keys, fn($k) => !isset($es[$k], $en[$k]));
test('NFR-005-01', 'every dashboard text is in Spanish and in English', count($keys) > 20 && !$missing, implode(',', $missing));
$r = $runPage('pages/dashboard.php', ['settings' => []]);
test('NFR-005-02', 'it works in an unconfigured project, with no mapping and no vocabulary', $r['err'] === ''
    && substr_count($tbody($r['out']), '<tr>') === 18, $r['err']);
