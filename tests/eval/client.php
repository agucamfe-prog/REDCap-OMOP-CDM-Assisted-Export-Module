<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }   // command line only: never from the browser
// Minimal client of the module's editor, for use from the container's CLI.
define('BASE', getenv('OMOP_EVAL_BASE') ?: 'http://localhost/redcap_v17.1.4/ExternalModules/?prefix=omop_cdm_export');

class Ed {
    public $pid; private $jar; public $csrf; public $rc;
    function __construct(int $pid) {
        $this->pid = $pid;
        $this->jar = (getenv('OMOP_EVAL_WORK') ?: '/tmp/omoptest') . "/cookies_$pid.txt";
        @unlink($this->jar);
        $html = $this->get('pages/editor');
        preg_match('/"csrf":"([0-9a-f]+)"/', $html, $m) || preg_match('/csrf: "([0-9a-f]+)"/', $html, $m);
        $this->csrf = $m[1] ?? '';
        preg_match('/redcap_csrf_token[^>]*value="([^"]+)"/', $html, $m2);
        if (!$m2) { preg_match("/redcap_csrf_token\s*=\s*'([^']+)'/", $html, $m2); }
        $this->rc = $m2[1] ?? '';
    }
    function curl($url, $post = null) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => 1, CURLOPT_COOKIEJAR => $this->jar,
            CURLOPT_COOKIEFILE => $this->jar, CURLOPT_HEADER => 0]);
        if ($post !== null) {
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['X-Requested-With: XMLHttpRequest']);
        }
        $r = curl_exec($ch);
        $this->lastCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $this->lastType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        return $r;
    }
    public $lastCode, $lastType;
    function get(string $page, string $extra = '') {
        return $this->curl(BASE . "&page=$page&pid={$this->pid}$extra");
    }
    function post(array $data) {
        $data['csrf'] = $this->csrf;
        if ($this->rc) { $data['redcap_csrf_token'] = $this->rc; }
        $r = $this->curl(BASE . "&page=pages/editor&pid={$this->pid}", $data);
        $j = json_decode($r, true);
        if ($j === null) { return ['_raw' => substr($r, 0, 600), '_code' => $this->lastCode]; }
        return $j;
    }
    // Shortcuts that mimic the editor's JavaScript
    function field(string $f, array $changes) { return $this->post(['action'=>'save-field','field'=>$f] + $changes); }
    function value(string $f, string $raw, array $ch) { return $this->post(['action'=>'save-value','field'=>$f,'raw_value'=>$raw] + $ch); }
    /** Approve the way omopState does: send every data-k and confirm if needed. */
    function approve(string $f, array $all, bool $confirm = true) {
        $r = $this->field($f, $all + ['state'=>'approved']);
        if (!empty($r['confirm']) && $confirm) {
            $r = $this->field($f, $all + ['state'=>'approved', 'confirmed'=>'1']);
            $r['_confirmed'] = true;
        }
        return $r;
    }
}

function show($label, $r) {
    $s = $r['spec'] ?? [];
    $w = array_map(fn($x) => $x['severity'] . ':' . $x['code'], $r['warnings'] ?? []);
    printf("%-38s ok=%s state=%s%s%s%s err=%s warn=[%s]%s\n", $label,
        json_encode($r['ok'] ?? null), $s['state'] ?? '-',
        !empty($r['blocked']) ? ' BLOCKED' : '', !empty($r['confirm']) ? ' NEEDS-CONFIRM' : '',
        !empty($r['_confirmed']) ? ' (confirmed)' : '',
        json_encode($r['error'] ?? null, JSON_UNESCAPED_UNICODE), implode(',', $w),
        isset($r['_raw']) ? ' RAW(' . $r['_code'] . '):' . $r['_raw'] : '');
}
