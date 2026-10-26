<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }   // command line only: never from the browser
/**
 * Generates the spec 018 regression corpus from specs.php.
 *
 *   php tests/eval/build_corpus.php            → writes tests/fixtures/eval10/fields.csv
 *   php tests/eval/build_corpus.php -          → writes it to standard output
 *
 * One row per field of the 10 projects (186), with the domain the classifier
 * should propose. The rule is the same one measure.php used in the
 * evaluation:
 *
 *   - 'm' with 'x' (the curator excludes it) → NotMapped
 *   - 'm' with 'd' (the curator maps it)    → that domain
 *   - no 'm'                                → 't' (one or more acceptable domains, separated by '|')
 *   - record_id                              → Person
 *
 * The file is versioned: if specs.php changes, it is regenerated and the diff
 * shows what changed in the reference.
 */
$P = require __DIR__ . '/specs.php';

$out = ($argv[1] ?? '') === '-' ? fopen('php://stdout', 'w')
     : fopen(dirname(__DIR__) . '/fixtures/eval10/fields.csv', 'w');
fputcsv($out, ['lang', 'project', 'field_name', 'form_name', 'field_type', 'label', 'choices',
               'validation', 'identifier', 'expected'], ',', '"', '');
$n = 0;
// Fixed order P1…P10 (in specs.php P10 is defined before P5, because P5 imports from P10).
foreach (['P1', 'P2', 'P3', 'P4', 'P5', 'P6', 'P7', 'P8', 'P9', 'P10'] as $key) {
    $p = $P[$key];
    foreach ($p['fields'] as $f) {
        $m = $f['m'];
        if (!empty($m['x'])) {
            $expected = 'NotMapped';
        } elseif (isset($m['d'])) {
            $expected = $m['d'];
        } else {
            $expected = (string) $f['t'];
        }
        if ($f['field_name'] === 'record_id') {
            $expected = 'Person';
        }
        if ($expected === '') {
            fwrite(STDERR, "$key.{$f['field_name']}: no expected domain\n");
            exit(1);
        }
        fputcsv($out, [$p['lang'], $key, $f['field_name'], $f['form_name'], $f['field_type'], $f['field_label'],
                       $f['select_choices_or_calculations'], $f['text_validation_type_or_show_slider_number'],
                       $f['identifier'], $expected], ',', '"', '');
        $n++;
    }
}
fclose($out);
fwrite(STDERR, "$n fields\n");
