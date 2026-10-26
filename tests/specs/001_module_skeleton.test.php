<?php
// Spec 001 — Module skeleton and internationalisation
use OmopCdm\OmopCdmExportModule\{OmopCdmExportModule, MappingStore, Transformer, CsvExporter,
    MappingDocument, IdRegistry, Pseudonymizer, CdmModel};

$root = dirname(__DIR__, 2);
$cfg  = json_decode((string) file_get_contents($root . '/config.json'), true);
$en   = parse_ini_file($root . '/lang/English.ini');
$es   = parse_ini_file($root . '/lang/Spanish.ini');

section('config.json');
test('FR-001-01', 'config.json is valid JSON', is_array($cfg), json_last_error_msg());
$cfg = is_array($cfg) ? $cfg : [];
test('FR-001-01', 'framework-version is 9', ($cfg['framework-version'] ?? null) === 9);
test('FR-001-01', 'the declared namespace is that of the module class',
    ($cfg['namespace'] ?? '') . '\\OmopCdmExportModule' === OmopCdmExportModule::class);
test('FR-001-01', 'compatibility: PHP ≥ 7.4.0 and REDCap ≥ 12.0.0',
    ($cfg['compatibility']['php-version-min'] ?? '') === '7.4.0'
    && ($cfg['compatibility']['redcap-version-min'] ?? '') === '12.0.0');
test('FR-001-01', 'name, authors and documentation are declared',
    ($cfg['name'] ?? '') === 'OMOP-CDM Export' && !empty($cfg['authors'])
    && ($cfg['documentation'] ?? '') === 'README.md' && is_file($root . '/README.md'));

$proj = array_column($cfg['links']['project'] ?? [], null, 'url');
$cc   = array_column($cfg['links']['control-center'] ?? [], null, 'url');
test('FR-001-02', 'three project links: dashboard, editor and export',
    array_keys($proj) === ['pages/dashboard.php', 'pages/editor.php', 'pages/export.php']);
test('FR-001-02', 'one Control Center link: vocabulary', array_keys($cc) === ['pages/vocabulary.php']);
test('FR-001-02', 'project links do not use the framework\'s automatic header',
    array_filter($proj, fn($l) => ($l['show-header-and-footer'] ?? null) !== false) === []);
$links = array_merge(array_values($proj), array_values($cc));
test('FR-001-02', 'every link has a tt_name, an icon and a page that exists',
    count($links) === 4 && array_filter($links, fn($l) => empty($l['tt_name']) || empty($l['icon'])
        || !is_file($root . '/' . $l['url'])) === []);

$pkeys = array_column($cfg['project-settings'] ?? [], 'key');
$skeys = array_column($cfg['system-settings'] ?? [], 'key');
test('FR-001-03', 'project settings: source, PERSON, DEATH, dates, visits, privacy, entities and load',
    array_diff(['cdm-version', 'cdm-source-name', 'cdm-source-abbreviation', 'cdm-holder', 'vocabulary-version',
        'birth-date-field', 'birth-year-field', 'gender-field', 'race-field', 'ethnicity-field',
        'death-date-field', 'default-date-field', 'generate-visits', 'visit-concept-id',
        'hash-person-source-value', 'date-shift-days', 'birth-precision', 'suppress-free-text',
        'care-site-field', 'provider-field', 'note-language-concept-id', 'load-schema'], $pkeys) === []);
test('FR-001-03', 'six numbered descriptive sections in the project settings',
    count(array_filter($cfg['project-settings'] ?? [], fn($s) => ($s['type'] ?? '') === 'descriptive'
        && preg_match('/^<b>\d\./', $s['name']))) === 6);
test('FR-001-03', 'system settings: local vocabulary and WebAPI, with the token as a password',
    $skeys === ['desc-vocab', 'vocab-concept-csv', 'vocab-sqlite-path', 'webapi-url', 'webapi-token']
    && array_column($cfg['system-settings'], 'type', 'key')['webapi-token'] === 'password');
test('FR-001-03', 'no module setting uses the framework\'s reserved prefix «reserved-»',
    array_filter(array_merge($pkeys, $skeys), fn($k) => strpos((string) $k, 'reserved-') === 0) === []);
test('FR-001-03', 'setting keys are not repeated', count(array_unique(array_merge($pkeys, $skeys))) === count($pkeys) + count($skeys));

$tts = [];
array_walk_recursive($cfg, function ($v, $k) use (&$tts) {
    if ($k === 'tt_name' || $k === 'tt_description') {
        $tts[] = $v;
    }
});
test('FR-001-04', 'config.json translates the module, links, settings and choices (≥ 40 tt_ keys)', count($tts) >= 40);
test('FR-001-04', 'every tt_ key in config.json exists in English.ini and in Spanish.ini',
    array_filter($tts, fn($k) => !isset($en[$k], $es[$k])) === [],
    implode(',', array_filter($tts, fn($k) => !isset($en[$k], $es[$k]))));

section('Language files');
test('FR-001-05', 'lang/ contains English.ini and Spanish.ini',
    array_map('basename', glob($root . '/lang/*.ini')) === ['English.ini', 'Spanish.ini']);
test('FR-001-05', 'both .ini files have exactly the same keys',
    array_keys(array_diff_key($en, $es)) === [] && array_keys(array_diff_key($es, $en)) === [],
    implode(',', array_keys(array_diff_key($en, $es) + array_diff_key($es, $en))));
test('FR-001-05', 'no text is empty',
    array_filter($en + $es, fn($v) => trim((string) $v) === '') === []
    && array_filter($es, fn($v) => trim((string) $v) === '') === []);
$placeholders = function (string $s): array {
    preg_match_all('/\{\d+\}/', $s, $m);
    $u = array_values(array_unique($m[0]));
    sort($u);
    return $u;
};
$mismatched = array_keys(array_filter($en, fn($v, $k) => $placeholders((string) $v) !== $placeholders((string) ($es[$k] ?? '')),
    ARRAY_FILTER_USE_BOTH));
test('FR-001-05', 'every key has the same {n} placeholders in both languages', $mismatched === [], implode(',', $mismatched));

$used = [];
foreach (array_merge([$root . '/OmopCdmExportModule.php'], glob($root . '/classes/*.php'), glob($root . '/pages/*.php')) as $php) {
    preg_match_all('/(?:\btt|\bttText|->t|\$t|\$tt)\(\s*\'([A-Za-z0-9_]+)\'\s*[,)]/', (string) file_get_contents($php), $m);
    foreach ($m[1] as $k) {
        $used[$k] = basename($php);
    }
}
$missing = array_keys(array_filter($used, fn($f, $k) => !isset($en[$k], $es[$k]), ARRAY_FILTER_USE_BOTH));
test('FR-001-06', 'the code uses literal text keys (≥ 200)', count($used) >= 200, (string) count($used));
test('FR-001-06', 'every literal key used in the code exists in both .ini files', $missing === [], implode(',', $missing));

section('Per-project language');
$m = make_module();
test('FR-001-07', 'each .ini declares its language code in doc_lang', ($en['doc_lang'] ?? '') === 'en' && ($es['doc_lang'] ?? '') === 'es');
test('FR-001-07', 'with the project language set to English, texts come out in English', $m->ttText('domain_NotMapped') === 'Not mapped');
$before = \ExternalModules\AbstractExternalModule::$lang;
\ExternalModules\AbstractExternalModule::$lang = $es;   // what the framework does with reserved-language-project = Spanish
$mEs = make_module(['reserved-language-project' => 'Spanish']);
test('FR-001-07', 'with the project language set to Spanish, texts come out in Spanish',
    $mEs->ttText('domain_NotMapped') === 'Sin mapear' && $mEs->ttText('doc_lang') === 'es');
\ExternalModules\AbstractExternalModule::$lang = $before;

section('Settings cache');
$counter = new class extends OmopCdmExportModule {
    public $reads = [];
    public function getProjectSetting($k, $pid = null)
    {
        $this->reads[$k] = ($this->reads[$k] ?? 0) + 1;
        return parent::getProjectSetting($k, $pid);
    }
};
$counter->settings = ['cdm-holder' => 'Hospital'];
$a = $counter->setting('cdm-holder');
$b = $counter->setting('cdm-holder');
test('FR-001-08', 'a setting read twice is only queried once', $a === 'Hospital' && $b === 'Hospital'
    && $counter->reads['cdm-holder'] === 1);
$counter->setting('does-not-exist');
$counter->setting('does-not-exist');
test('FR-001-08', 'a setting that does not exist (null) is cached too', $counter->reads['does-not-exist'] === 1);
for ($i = 0; $i < 1000; $i++) {
    $counter->setting('default-date-field');
}
test('NFR-001-02', 'a thousand reads of the same setting in one request = one query', $counter->reads['default-date-field'] === 1);

$counter->writeSetting('cdm-holder', 'Other');
test('FR-001-09', 'writeSetting saves the project setting', $counter->settings['cdm-holder'] === 'Other');
test('FR-001-09', 'after writeSetting, setting() returns the new value without querying again',
    $counter->setting('cdm-holder') === 'Other' && $counter->reads['cdm-holder'] === 1);

section('Texts');
test('FR-001-10', 'tt() escapes interpolated values', $m->tt('r_keyword', "O'Brien <b>") === "Matches 'O&#039;Brien &lt;b&gt;' in the field name or label");
test('FR-001-10', 'ttText() interpolates and returns plain text, with no entities',
    $m->ttText('r_keyword', "O'Brien <b>") === "Matches 'O'Brien <b>' in the field name or label");
$conf = $m->ttText('ed_reset_all_confirm', 1, 2, 3, 4);
test('FR-001-10', 'ttText() turns the literal «\n» of the .ini into a line break',
    strpos($conf, "\n\n") !== false && strpos($conf, '\n') === false && strpos($conf, '· 1 field(s)') !== false);
test('FR-001-11', 'domainLabel translates NotMapped and DateAnchor', $m->domainLabel('NotMapped') === 'Not mapped'
    && $m->domainLabel('DateAnchor') === 'Date anchor');
test('FR-001-11', 'domainLabel leaves OMOP domains as they are', $m->domainLabel('Measurement') === 'Measurement'
    && $m->domainLabel('Condition') === 'Condition');

section('Services');
$m = make_module();
test('FR-001-12', 'store() always returns the same MappingStore instance',
    $m->store() instanceof MappingStore && $m->store() === $m->store());
test('FR-001-12', 'transformer(), exporter() and mappingDocument() create new instances of their class',
    $m->transformer() instanceof Transformer && $m->exporter() instanceof CsvExporter
    && $m->mappingDocument() instanceof MappingDocument && $m->exporter() !== $m->exporter());
test('FR-001-12', 'idRegistry() and pseudonymizer() are built from the settings',
    $m->idRegistry(true) instanceof IdRegistry && $m->pseudonymizer() instanceof Pseudonymizer);
test('FR-001-12', 'with no index or WebAPI configured, vocabulary() is null', $m->vocabulary() === null);
test('FR-001-12', 'the module\'s validDomains() is the model\'s list', OmopCdmExportModule::validDomains() === CdmModel::validDomains());
$m->audit('Test', ['x' => 1]);
$last = end($m->logs);
test('FR-001-12', 'audit() logs the message with the module version',
    $last[0] === 'Test' && $last[1]['x'] === 1 && $last[1]['module_version'] === 'vtest');

$m = make_module();
test('FR-001-13', 'pid() returns the project id as an integer', $m->pid() === 1);
$d1 = $m->getDictionary();
$saved = \REDCap::$dict;
\REDCap::$dict = [];
test('FR-001-13', 'getDictionary() reads the dictionary only once per request', $m->getDictionary() === $d1 && count($d1) > 0);
\REDCap::$dict = $saved;
$p1 = $m->profile();
test('FR-001-13', 'profile() is computed once and reused', $m->profile() === $p1 && isset($p1['weight']));

section('Pages');
$page = fn($f) => (string) file_get_contents($root . '/pages/' . $f);
$ok = true;
foreach (['dashboard.php', 'editor.php', 'export.php'] as $f) {
    $src = $page($f);
    $ok = $ok && strpos($src, "namespace OmopCdm\\OmopCdmExportModule;") !== false
        && strpos($src, "ProjectGeneral/header.php") !== false && strpos($src, "ProjectGeneral/footer.php") !== false;
}
test('FR-001-14', 'every project page renders the REDCap header and footer', $ok);
$v = $page('vocabulary.php');
test('FR-001-14', 'the Control Center page uses the Control Center header',
    strpos($v, 'ControlCenter/header.php') !== false && strpos($v, 'ControlCenter/footer.php') !== false);
$dash = $page('dashboard.php');
test('FR-001-14', 'the dashboard handles downloads before rendering the header',
    strpos($dash, "\$_GET['download']") !== false
    && strpos($dash, "\$_GET['download']") < strpos($dash, 'ProjectGeneral/header.php'));

section('Non-functional requirements');
test('NFR-001-01', 'no Composer dependencies and no vendor folder', !is_file($root . '/composer.json') && !is_dir($root . '/vendor'));
test('NFR-001-01', 'installing or enabling the module runs nothing (no enable hooks)',
    !method_exists(OmopCdmExportModule::class, 'redcap_module_system_enable')
    && !method_exists(OmopCdmExportModule::class, 'redcap_module_project_enable'));
$php8 = [];
foreach (array_merge([$root . '/OmopCdmExportModule.php'], glob($root . '/classes/*.php'), glob($root . '/pages/*.php')) as $php) {
    if (preg_match('/\bmatch\s*\(|\?->|\bstr_(contains|starts_with|ends_with)\(|^\s*#\[|^\s*enum\s|\breadonly\s/m',
        (string) file_get_contents($php))) {
        $php8[] = basename($php);
    }
}
test('NFR-001-03', 'the code uses no PHP 8-only syntax (match, ?->, str_contains, attributes, enum)', $php8 === [], implode(',', $php8));
