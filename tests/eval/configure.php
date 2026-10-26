<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }   // command line only: never from the browser
// projects.json (with the API tokens) and the results live OUTSIDE the module.
$WORK = getenv('OMOP_EVAL_WORK') ?: '/tmp/omoptest';
// Enables the module and applies each project's configuration (like the "Configure" dialog).
$P   = require __DIR__ . '/specs.php';
$ids = json_decode(file_get_contents($WORK . '/projects.json'), true);
$db  = new mysqli(getenv('OMOP_EVAL_DB_HOST') ?: 'db', getenv('OMOP_EVAL_DB_USER') ?: 'redcap', getenv('OMOP_EVAL_DB_PASS') ?: '', getenv('OMOP_EVAL_DB_NAME') ?: 'redcap');
$db->set_charset('utf8mb4');
$em  = (int) $db->query("select external_module_id from redcap_external_modules where directory_prefix='omop_cdm_export'")->fetch_row()[0];

$set = function ($pid, $k, $v) use ($db, $em) {
    $type = is_bool($v) ? 'boolean' : 'string';
    $v = is_bool($v) ? ($v ? 'true' : 'false') : (string) $v;
    $pidSql = $pid === null ? 'is null' : '= ' . (int) $pid;
    $db->query("delete from redcap_external_module_settings where external_module_id=$em and project_id $pidSql and `key`='" . $db->real_escape_string($k) . "'");
    $st = $db->prepare('insert into redcap_external_module_settings (external_module_id, project_id, `key`, type, value) values (?,?,?,?,?)');
    $st->bind_param('iisss', $em, $pid, $k, $type, $v);
    $st->execute();
};

foreach ($P as $key => $p) {
    $pid = (int) $ids[$key]['pid'];
    $set($pid, 'enabled', true);
    $set($pid, 'reserved-language-project', $p['lang'] === 'es' ? 'Spanish' : 'English');
    foreach ($p['settings'] as $k => $v) {
        $set($pid, $k, $v);
    }
    echo "$key (pid $pid): ", count($p['settings']) + 2, " settings\n";
}
// System vocabulary: the real Eunomia subset
$set(null, 'vocab-concept-csv', '/tmp/eun/CONCEPT.csv');
$set(null, 'vocab-sqlite-path', '/var/omop/eunomia.sqlite');
echo "vocabulary: /var/omop/eunomia.sqlite\n";
