<?php
/**
 * Runs a module page (pages/*.php) in a separate PHP process and returns what
 * the browser would have received.
 *
 *     $runPage = require dirname(__DIR__) . '/support/run_page.php';
 *     $r = $runPage('pages/editor.php', ['settings' => $m->settings,
 *                   'post' => ['action' => 'save-field', 'csrf' => 'tok', 'field' => 'weight']]);
 *     $r['json'], $r['out'], $r['headers'], $r['status'], $r['settings'], $r['logs'], $r['err']
 *
 * With 'fail' => true, the mapping count (mappingCounts) throws an exception:
 * it is used to check how a page responds to an unexpected server failure
 * without depending on any real defect.
 *
 * Why a separate process: pages respond with exit() (downloads, the editor's
 * JSON responses), and an exit() inside the test runner would terminate it.
 * The child process starts with the same bootstrap and the same simulated
 * project as the parent (data dictionary, data, DAG, user, language and module
 * settings), and when it finishes it returns the resulting settings and log.
 *
 * In the child, header(), http_response_code() and session_status() are
 * replaced by functions in the module's namespace, which PHP resolves before
 * the global ones: that way headers can be read in CLI and the session's CSRF
 * token is always 'tok'. ProjectGeneral/header.php and footer.php are empty
 * files that only leave a marker in the output.
 *
 * Returns a closure (it defines no global functions, to avoid clashes between specs).
 */
return function (string $page, array $o = []): array {
    $root = dirname(__DIR__, 2);
    $dir  = sys_get_temp_dir() . '/omop_pages_' . getmypid();
    if (!is_dir($dir . '/ProjectGeneral')) {
        mkdir($dir . '/ProjectGeneral', 0777, true);
    }
    file_put_contents($dir . '/ProjectGeneral/header.php', '<?php echo "<!--rc-header-->";');
    file_put_contents($dir . '/ProjectGeneral/footer.php', '<?php echo "<!--rc-footer-->";');

    $child = <<<'PHP'
<?php
namespace OmopCdm\OmopCdmExportModule {
    function header($h, $replace = true, $code = 0) { $GLOBALS['__io']['headers'][] = $h; }
    function http_response_code($c = 0)
    {
        if ($c) {
            $GLOBALS['__io']['status'] = $c;
        }
        return $GLOBALS['__io']['status'];
    }
    function session_status() { return \PHP_SESSION_ACTIVE; }
}
namespace {
    $in = unserialize(file_get_contents($argv[1]));
    $GLOBALS['__io'] = ['headers' => [], 'status' => 200];
    define('APP_PATH_DOCROOT', $in['docroot']);
    $lvl = error_reporting(E_ALL & ~E_WARNING);   // the bootstrap defines APP_PATH_DOCROOT again
    require $in['root'] . '/tests/bootstrap.php';
    error_reporting($lvl);
    \REDCap::$dict = $in['dict'];
    \REDCap::$data = $in['data'];
    \REDCap::$dag  = $in['dag'];
    \Project::$conf = $in['conf'];
    \TestUser::$super  = $in['super'];
    \TestUser::$rights = $in['rights'];
    \ExternalModules\AbstractExternalModule::$lang = $in['lang'];
    $module = empty($in['fail']) ? new \OmopCdm\OmopCdmExportModule\OmopCdmExportModule()
        : new class extends \OmopCdm\OmopCdmExportModule\OmopCdmExportModule {
            public function mappingCounts(): array
            {
                throw new \RuntimeException('simulated failure');
            }
        };
    $module->settings = $in['settings'];
    $module->system   = $in['system'];
    $_SESSION = ['omop_csrf' => 'tok'];
    $_POST = $in['post'];
    $_GET  = $in['get'];
    register_shutdown_function(function () use ($module, $in) {
        file_put_contents($in['out'], serialize([
            'headers'  => $GLOBALS['__io']['headers'],
            'status'   => $GLOBALS['__io']['status'],
            'settings' => $module->settings,
            'logs'     => $module->logs,
        ]));
    });
    (function () use ($module, $in) {
        require $in['root'] . '/' . $in['page'];
    })();
}
PHP;
    file_put_contents($dir . '/child.php', $child);

    $id = uniqid('', true);
    $in = [
        'root' => $root, 'docroot' => $dir . '/', 'page' => $page, 'out' => "$dir/out_$id.ser",
        'dict' => \REDCap::$dict, 'data' => \REDCap::$data, 'dag' => \REDCap::$dag,
        'conf' => \Project::$conf, 'super' => \TestUser::$super, 'rights' => \TestUser::$rights,
        'lang' => $o['lang'] ?? \ExternalModules\AbstractExternalModule::$lang,
        'settings' => $o['settings'] ?? [], 'system' => $o['system'] ?? [],
        'post' => $o['post'] ?? [], 'get' => $o['get'] ?? [], 'fail' => !empty($o['fail']),
    ];
    file_put_contents("$dir/in_$id.ser", serialize($in));

    $p = proc_open([PHP_BINARY, '-d', 'xdebug.mode=off', '-d', 'display_errors=stderr',
                    $dir . '/child.php', "$dir/in_$id.ser"],
                   [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($p);

    $st = is_file($in['out']) ? unserialize(file_get_contents($in['out'])) : [];
    @unlink($in['out']);
    @unlink("$dir/in_$id.ser");
    return ['out' => $out, 'err' => $err, 'json' => json_decode($out, true)]
         + $st + ['headers' => [], 'status' => 0, 'settings' => [], 'logs' => []];
};
