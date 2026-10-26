<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }   // command line only: never from the browser
// projects.json (with the API tokens) and the results live OUTSIDE the module.
$WORK = getenv('OMOP_EVAL_WORK') ?: '/tmp/omoptest';
/**
 * Creates the 10 evaluation projects in REDCap and fills them with data.
 *   php generate.php SUPERTOKEN   → writes projects.json with the pid and token of each one
 */
$MOD = dirname(__DIR__, 2) . '/classes/';
require_once $MOD . 'TextNormalizer.php';
require_once $MOD . 'DictionaryProfiler.php';
require_once $MOD . 'CdmModel.php';
require_once $MOD . 'MappingStore.php';
require_once $MOD . 'MappingAnnotation.php';
use OmopCdm\OmopCdmExportModule\MappingAnnotation;

$super = $argv[1];
$P = require __DIR__ . '/specs.php';
mt_srand(20261025);

function api(string $t, array $p) {
    $p['token'] = $t;
    $ch = curl_init('http://localhost/api/');
    curl_setopt_array($ch, [CURLOPT_POST => 1, CURLOPT_POSTFIELDS => http_build_query($p), CURLOPT_RETURNTRANSFER => 1]);
    $r = curl_exec($ch);
    if (curl_getinfo($ch, CURLINFO_HTTP_CODE) >= 400) {
        fwrite(STDERR, "API ERROR $r\n");
        exit(1);
    }
    return $r;
}
function slug(string $s): string { return trim(preg_replace('/[^a-z0-9]+/', '_', strtolower(iconv('UTF-8', 'ASCII//TRANSLIT', $s))), '_'); }
function d(string $base, int $days): string { return date('Y-m-d', strtotime("$base $days days")); }
function pick(array $w) { $t = array_sum($w); $r = mt_rand(1, $t); foreach ($w as $k => $v) { if (($r -= $v) <= 0) return (string) $k; } return (string) array_key_first($w); }

/** @OMOP annotation of a field built from its reference mapping (P2 only). */
function annotation(array $f): string
{
    $m = $f['m'];
    if (!$m) return '';
    if (!empty($m['x'])) return '@OMOP-EXCLUDE';
    $spec = ['domain' => $m['d'], 'concept_id' => is_int($m['c'] ?? null) ? $m['c'] : null, 'concept_name' => $m['cn'] ?? null,
             'value_strategy' => $m['s'] ?? null, 'date_field' => $m['date'] ?? null, 'end_date_field' => $m['end'] ?? null,
             'unit_source_value' => $m['u'] ?? null];
    $values = [];
    foreach ($m['v'] ?? [] as $code => $v) {
        $values[$code] = $v === '-' ? ['emit' => false] : ['emit' => true, 'concept_id' => $v[0], 'concept_name' => $v[1]];
    }
    return MappingAnnotation::render($spec, $values, false);
}

/** Value of a field according to its generator. $st keeps the record's state (death...). */
function gen(array $g, string $evDate, int $inst, array &$st, string $base)
{
    switch ($g[0]) {
        case 'num': return (string) round($g[1] + mt_rand() / mt_getrandmax() * ($g[2] - $g[1]), $g[3]);
        case 'int': return (string) mt_rand($g[1], $g[2]);
        case 'cat': return pick($g[1]);
        case 'yn': return mt_rand() / mt_getrandmax() < $g[1] ? '1' : '0';
        case 'chk': $o = []; foreach ($g[1] as $c => $p) { $o[$c] = mt_rand() / mt_getrandmax() < $p ? '1' : '0'; } return $o;
        case 'date':
            if (isset($g['optional']) && mt_rand() / mt_getrandmax() > $g['optional']) return '';
            return d($evDate, $g[1] + ($inst - 1) * ($g['step'] ?? 30) + mt_rand(0, 2));
        case 'dob': return d($base, -mt_rand($g[1] * 365, $g[2] * 365));
        case 'death': return $st['dead'] ? '1' : '0';
        case 'death_choice': return $st['dead'] ? '2' : '1';
        case 'deathdate': return $st['dead'] ? d($evDate, mt_rand(3, 40)) : '';
        case 'text': return $g[1][array_rand($g[1])];
        case 'dirty': return mt_rand() / mt_getrandmax() < $g[3] ? $g[2][array_rand($g[2])] : gen($g[1], $evDate, $inst, $st, $base);
        case 'initials': return chr(mt_rand(65, 90)) . chr(mt_rand(65, 90)) . chr(mt_rand(65, 90));
        case 'name': return ['Oliver', 'Amelia', 'Noah', 'Isla', 'Leo', 'Ava', 'Arthur', 'Mia'][mt_rand(0, 7)];
        case 'nhc': return (string) mt_rand(100000, 999999);
        case 'dni': return mt_rand(10000000, 99999999) . 'TRWAGMYFPDXBNJZSQVHLCKE'[mt_rand(0, 22)];
        case 'cp': return '28' . str_pad((string) mt_rand(1, 999), 3, '0', STR_PAD_LEFT);
    }
    return '';
}

$out = [];
$only = isset($argv[2]) ? explode(",", $argv[2]) : null;
$prev = is_file($WORK . '/projects.json') ? json_decode(file_get_contents($WORK . '/projects.json'), true) : [];
foreach ($P as $key => $p) {
    if ($only && !in_array($key, $only, true)) { if (isset($prev[$key])) $out[$key] = $prev[$key]; continue; }
    $long = !empty($p['events']);
    $tok = trim(api($super, ['content' => 'project', 'format' => 'json', 'data' => json_encode([[
        'project_title' => "$key · " . $p['title'], 'purpose' => 2, 'purpose_other' => '', 'is_longitudinal' => $long ? 1 : 0,
        'surveys_enabled' => 0, 'record_autonumbering_enabled' => 0, 'project_language' => 'English']])]));

    // Dictionary
    $meta = [];
    foreach ($p['fields'] as $f) {
        // REDCap reads the dictionary by position: the order of the keys matters.
        $row = [];
        foreach (['field_name', 'form_name', 'section_header', 'field_type', 'field_label', 'select_choices_or_calculations',
                  'field_note', 'text_validation_type_or_show_slider_number', 'text_validation_min', 'text_validation_max',
                  'identifier', 'branching_logic', 'required_field', 'custom_alignment', 'question_number',
                  'matrix_group_name', 'matrix_ranking', 'field_annotation'] as $k) {
            $row[$k] = $f[$k] ?? '';
        }
        if ($p['mode'] === 'annotation') {
            $row['field_annotation'] = annotation($f);
        }
        $meta[] = $row;
    }
    api($tok, ['content' => 'metadata', 'format' => 'json', 'data' => json_encode($meta)]);

    // Events and repeating instruments
    $evNames = [];
    if ($long) {
        api($tok, ['content' => 'arm', 'action' => 'import', 'format' => 'json', 'override' => 1, 'data' => json_encode([['arm_num' => 1, 'name' => 'Arm 1']])]);
        $evs = [];
        foreach ($p['events'] as [$name, $off]) {
            $evs[] = ['event_name' => $name, 'arm_num' => 1, 'day_offset' => $off];
            $evNames[slug($name)] = [slug($name) . '_arm_1', $off];
        }
        api($tok, ['content' => 'event', 'action' => 'import', 'format' => 'json', 'override' => 1, 'data' => json_encode($evs)]);
        $map = [];
        foreach ($p['forms'] as $form => $evList) {
            foreach ($evList as $ev) {
                $map[] = ['arm_num' => 1, 'unique_event_name' => $evNames[$ev][0], 'form' => $form];
            }
        }
        api($tok, ['content' => 'formEventMapping', 'format' => 'json', 'data' => json_encode($map)]);
    }
    if ($p['repeat']) {
        $rep = [];
        foreach ($p['repeat'] as $form => $max) {
            if ($long) {
                foreach ($p['forms'][$form] as $ev) {
                    $rep[] = ['event_name' => $evNames[$ev][0], 'form_name' => $form, 'custom_form_label' => ''];
                }
            } else {
                $rep[] = ['form_name' => $form, 'custom_form_label' => ''];
            }
        }
        api($tok, ['content' => 'repeatingFormsEvents', 'format' => 'json', 'data' => json_encode($rep)]);
    }
    if (!empty($p['dags'])) {
        api($tok, ['content' => 'dag', 'action' => 'import', 'format' => 'json', 'data' => json_encode(array_map(
            fn($g) => ['data_access_group_name' => ucwords(str_replace('_', ' ', $g)), 'unique_group_name' => ''], $p['dags']))]);
    }

    // Data
    $byForm = [];
    foreach ($p['fields'] as $f) {
        $byForm[$f['form_name']][] = $f;
    }
    $recs = [];
    $deadForm = null;
    for ($i = 1; $i <= $p['n']; $i++) {
        $id = (string) $i;
        $base = d('2023-01-01', mt_rand(0, 540));
        $st = ['dead' => false];
        foreach ($p['fields'] as $f) {
            if (($f['g'][0] ?? '') === 'death' || ($f['g'][0] ?? '') === 'death_choice') {
                $st['dead'] = mt_rand() / mt_getrandmax() < $f['g'][1];
            }
        }
        $events = $long ? $evNames : ['_' => [null, 0]];
        foreach ($events as $slugEv => [$unique, $off]) {
            $evDate = d($base, $off + mt_rand(0, 6));
            $forms = $long ? array_keys(array_filter($p['forms'], fn($evs) => in_array($slugEv, $evs, true))) : array_keys($byForm);
            $main = ['record_id' => $id];
            if ($long) { $main['redcap_event_name'] = $unique; }
            foreach ($forms as $form) {
                $instances = isset($p['repeat'][$form]) ? mt_rand(1, $p['repeat'][$form]) : 0;
                for ($k = 1; $k <= max(1, $instances); $k++) {
                    $row = $instances ? ['record_id' => $id, 'redcap_repeat_instrument' => $form, 'redcap_repeat_instance' => $k] : [];
                    if ($instances && $long) { $row['redcap_event_name'] = $unique; }
                    foreach ($byForm[$form] as $f) {
                        if ($f['field_name'] === 'record_id' || !$f['g']) continue;
                        $v = gen($f['g'], $evDate, $k, $st, $base);
                        // Missing data: 3 % of the non-essential values, and one record without a birth date.
                        if (!in_array($f['g'][0], ['dob', 'date', 'death', 'deathdate', 'death_choice'], true) && mt_rand(1, 100) <= 3) {
                            $v = is_array($v) ? array_map(fn() => '0', $v) : '';
                        }
                        if ($f['g'][0] === 'dob' && $i === 7) { $v = ''; }
                        if (is_array($v)) {
                            foreach ($v as $c => $x) { $row[$f['field_name'] . '___' . $c] = $x; }
                        } else {
                            $row[$f['field_name']] = $v;
                        }
                    }
                    if ($instances) { $recs[] = $row; } else { $main += $row; }
                }
            }
            if (!empty($p['dags']) && ($slugEv === '_' || $off === 0)) {
                $main['redcap_data_access_group'] = $p['dags'][$i % 2];
            }
            if ($long && $p['repeat']) { $main['redcap_repeat_instrument'] = ''; $main['redcap_repeat_instance'] = ''; }
            $recs[] = $main;
        }
    }
    $r = api($tok, ['content' => 'record', 'format' => 'json', 'type' => 'flat', 'data' => json_encode($recs)]);
    $out[$key] = ['token' => $tok, 'title' => $p['title'], 'lang' => $p['lang'], 'records' => $r];
    echo "$key: $r  ({$p['title']})\n";
}
file_put_contents($WORK . '/projects.json', json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
