<?php
/**
 * Minimal environment to test the module without REDCap.
 *
 * Replaces the three REDCap pieces the module uses —the module framework base
 * class, REDCap::getData/getDataDictionary and Project— with in-memory
 * versions. This way the tests exercise the module's REAL code
 * (OmopCdmExportModule, MappingStore, Transformer...), not a copy.
 */

namespace ExternalModules {
    class AbstractExternalModule
    {
        public $settings = [];
        public $system   = [];
        public $logs     = [];
        public static $lang = [];

        public function getProjectId() { return 1; }
        public function getProjectSetting($k, $pid = null) { return $this->settings[$k] ?? null; }
        public function setProjectSetting($k, $v, $pid = null) { $this->settings[$k] = $v; }
        public function removeProjectSetting($k, $pid = null) { unset($this->settings[$k]); }
        public function getSystemSetting($k) { return $this->system[$k] ?? null; }
        public function log($m, $p = []) { $this->logs[] = [$m, $p]; }
        public function getModuleVersion() { return 'vtest'; }
        public function getUrl($p) { return $p; }
        public function getUser() { return new \TestUser(); }

        /** The framework's default rule: links only for users with design rights. */
        public function redcap_module_link_check_display($project_id, $link)
        {
            return (\TestUser::$super || !empty(\TestUser::$rights['design'])) ? $link : null;
        }

        /** Like the framework's: interpolates {0}, {1}... and escapes the values. */
        public function tt($key, ...$args)
        {
            $s = self::$lang[$key] ?? "[$key]";
            foreach ($args as $i => $a) {
                $s = str_replace('{' . $i . '}', htmlspecialchars((string) $a, ENT_QUOTES), $s);
            }
            return $s;
        }
    }
}

namespace {
    class TestUser
    {
        public static $super = true;
        public static $rights = [];
        public function isSuperUser() { return self::$super; }
        public function hasDesignRights() { return self::$super || !empty(self::$rights['design']); }
        public function getRights($pid = null) { return self::$rights; }
    }

    /** Test project: dictionary, data (getData array format) and DAGs. */
    class REDCap
    {
        public static $dict = [];
        public static $data = [];
        public static $dag  = [];

        public static function getDataDictionary($pid, $fmt) { return self::$dict; }
        public static function getEventNames($u = true, $a = true) { return [1 => 'basal_arm_1']; }

        /** Rights per user ([user => rights]); empty = not a member of the project. */
        public static $userRights = [];
        public static function getUserRights($u = null) { return self::$userRights; }

        public static function getData($p)
        {
            $out = self::$data;
            if (!empty($p['groups'])) {
                $out = array_filter($out, fn($r) => in_array(self::$dag[$r] ?? null, $p['groups'], true),
                                    ARRAY_FILTER_USE_KEY);
            }
            if (!empty($p['records'])) {
                $out = array_intersect_key($out, array_flip(array_map('strval', $p['records'])));
            }
            return $out;
        }
    }

    class Project
    {
        public static $conf = [];
        public $longitudinal = false;
        public $eventInfo = [1 => ['descrip' => 'Basal', 'arm_num' => 1, 'day_offset' => 0]];
        public $uniqueEventNames = [1 => 'basal_arm_1'];
        public $RepeatingFormsEvents = [];
        public $table_pk = 'record_id';
        public $forms = [];
        public $project = ['surveys_enabled' => 0];

        public function __construct($pid)
        {
            foreach (self::$conf as $k => $v) {
                $this->$k = $v;
            }
        }
    }

    define('APP_PATH_DOCROOT', __DIR__ . '/');
    \ExternalModules\AbstractExternalModule::$lang = parse_ini_file(dirname(__DIR__) . '/lang/English.ini');
    require_once dirname(__DIR__) . '/OmopCdmExportModule.php';
}
