<?php

namespace OmopCdm\OmopCdmExportModule;

require_once __DIR__ . '/CdmModel.php';

/**
 * Writes the transformation result as CDM CSV files.
 *
 * Format: one header per table with the columns in the official DDL order,
 * because positional loaders require it. Everything is written to disk and
 * then compressed, instead of being built in memory, so that a large project
 * does not exhaust PHP's memory limit.
 */
class CsvExporter
{
    private $module;
    /** @var array<string,int> truncated columns and how many times */
    private $truncated = [];

    public function __construct($module)
    {
        $this->module = $module;
    }

    /**
     * @param array $result Output of Transformer::run()
     * @return array{path: string, files: array<string,int>, bytes: int}
     */
    public function writeZip(array $result): array
    {
        $dir = sys_get_temp_dir() . '/omop_export_' . $this->module->pid() . '_' . uniqid();
        if (!mkdir($dir, 0700, true)) {
            throw new \RuntimeException('Could not create the temporary directory');
        }

        $files = [];
        foreach ($result['tables'] as $table => $rows) {
            if (empty($rows)) {
                continue;
            }
            $files[$table] = $this->writeTable($dir, $table, $rows);
        }

        // The kit goes before the report: the report says whether it was included.
        $kitIncluded = $this->writeLoadKit($dir, array_keys($files));
        $this->writeReport($dir, $result, $kitIncluded);

        // A CDM without its mapping is data without provenance: they travel
        // together, in the formats each tool reads.
        try {
            $doc = $this->module->mappingDocument();
            file_put_contents($dir . '/MAPEO.html', $doc->html());
            file_put_contents($dir . '/MAPEO.json', $doc->json());
            file_put_contents($dir . '/MAPEO_usagi.csv', $doc->usagiCsv());
            file_put_contents($dir . '/DICCIONARIO_anotado.csv', $doc->annotatedDictionaryCsv());
        } catch (\Throwable $e) {
            // A failure here must not prevent the data from being exported.
        }

        // Relative paths inside the ZIP: the load kit goes in its own folder.
        $allFiles = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            $allFiles[$f->getPathname()] = str_replace('\\', '/', substr($f->getPathname(), strlen($dir) + 1));
        }
        ksort($allFiles);

        // PHP's zip extension is not guaranteed on every installation, so
        // there is a fallback writer of our own.
        $zipPath = $dir . '.zip';
        if (class_exists('\ZipArchive')) {
            $zip = new \ZipArchive();
            if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException('Could not create the ZIP');
            }
            foreach ($allFiles as $path => $name) {
                $zip->addFile($path, $name);
            }
            $zip->close();
        } else {
            MiniZip::create($zipPath, $allFiles);
        }

        // The directory is now inside the ZIP: it is deleted so that no copies
        // of clinical data are left in the server's temporary directory.
        foreach (array_keys($allFiles) as $file) {
            unlink($file);
        }
        foreach (array_reverse(glob($dir . '/*', GLOB_ONLYDIR) ?: []) as $sub) {
            @rmdir($sub);
        }
        rmdir($dir);

        return [
            'path'  => $zipPath,
            'files' => $files,
            'bytes' => filesize($zipPath),
        ];
    }

    /**
     * Kit to load the CDM into PostgreSQL and validate it with the Data
     * Quality Dashboard.
     *
     * Loading the CSVs into a real schema is the test that really counts: it
     * is what uncovered this module's most serious bugs. The kit carries the
     * official OHDSI DDL unmodified (except for the schema name), the load
     * script, the keys and an R script for DQD.
     *
     * @return bool false if the official DDL cannot be read: then there is no
     *              kit, and the report says so instead of pointing to it.
     */
    private function writeLoadKit(string $dir, array $tables): bool
    {
        $src = $this->ddlDir();
        $ddl = is_readable($src . 'OMOPCDM_postgresql_5.4_ddl.sql')
            ? @file_get_contents($src . 'OMOPCDM_postgresql_5.4_ddl.sql') : false;
        if ($ddl === false) {
            return false;
        }
        $kit = $dir . '/carga_cdm';
        @mkdir($kit, 0700, true);
        $schema = $this->module->loadSchema();
        $t = fn(string $k, ...$a) => $this->module->ttText($k, ...$a);
        // A multi-line comment, each line with its prefix.
        $comment = fn(string $prefix, string $text) =>
            implode("\n", array_map(fn($l) => rtrim($prefix . $l), explode("\n", $text))) . "\n";

        $sub = fn(string $sql) => str_replace('@cdmDatabaseSchema', $schema, $sql);
        file_put_contents($kit . '/01_ddl.sql',
            $comment('-- ', $t('kit_ddl_header', $schema))
            . "CREATE SCHEMA IF NOT EXISTS $schema;\n" . $sub($ddl));

        $loadSql = "-- " . $t('kit_load_comment') . "\n\\set ON_ERROR_STOP on\n";
        foreach ($tables as $table) {
            $loadSql .= "\\copy $schema.$table FROM '" . strtoupper($table) . ".csv' WITH (FORMAT csv, HEADER true, NULL '')\n";
        }
        file_put_contents($kit . '/02_carga.sql', $loadSql);
        foreach (['03_primary_keys.sql' => 'OMOPCDM_postgresql_5.4_primary_keys.sql',
                  '04_indices.sql'      => 'OMOPCDM_postgresql_5.4_indices.sql'] as $dst => $orig) {
            $sql = @file_get_contents($src . $orig);
            if ($sql !== false) {
                file_put_contents($kit . '/' . $dst, $sub($sql));
            }
        }

        file_put_contents($kit . '/cargar.sh',
            "#!/bin/sh\n# " . $t('kit_sh_comment') . "\n"
            . "# " . $t('kit_sh_env') . "\n"
            . "set -e\ncd \"\$(dirname \"\$0\")/..\"\n"
            . "psql -v ON_ERROR_STOP=1 -f carga_cdm/01_ddl.sql\n"
            . "psql -v ON_ERROR_STOP=1 -f carga_cdm/02_carga.sql\n"
            . "psql -v ON_ERROR_STOP=1 -f carga_cdm/03_primary_keys.sql\n"
            . "psql -v ON_ERROR_STOP=1 -f carga_cdm/04_indices.sql\n");

        $vocab = $this->module->getSystemSetting('vocab-sqlite-path') ? 'TRUE' : 'FALSE';
        $header = $comment('# ', $t('kit_dqd_header', $schema));
        $vocabFooter = $comment('# ', $t('kit_dqd_vocab', $vocab));
        // The same name as CDM_SOURCE, as an R string: unescaped, a quote in
        // the name would break the script.
        $sourceName = '"' . str_replace(['\\', '"', "\r", "\n"], ['\\\\', '\\"', ' ', ' '],
            (string) $this->module->cdmSourceConfig()['name']) . '"';
        file_put_contents($kit . '/dqd.R', $header . <<<R

library(DatabaseConnector)
library(DataQualityDashboard)

connectionDetails <- createConnectionDetails(
  dbms     = "postgresql",
  server   = paste0(Sys.getenv("PGHOST", "localhost"), "/", Sys.getenv("PGDATABASE", "cdm")),
  port     = as.integer(Sys.getenv("PGPORT", "5432")),
  user     = Sys.getenv("PGUSER", "postgres"),
  password = Sys.getenv("PGPASSWORD")
)

executeDqChecks(
  connectionDetails      = connectionDetails,
  cdmDatabaseSchema      = "$schema",
  resultsDatabaseSchema  = "$schema",
  vocabDatabaseSchema    = "$schema",
  cdmSourceName          = $sourceName,
  cdmVersion             = "5.4",
  outputFolder           = "dqd_resultados",
  writeToTable           = FALSE
)

R
        . $vocabFooter);

        file_put_contents($kit . '/LEEME.txt', $t('kit_readme', $schema) . "\n");
        return true;
    }

    /** Folder with the official OHDSI DDL for PostgreSQL. */
    protected function ddlDir(): string
    {
        return dirname(__DIR__) . '/resources/ddl/postgresql/';
    }

    private function writeTable(string $dir, string $table, array $rows): int
    {
        $columns = CdmModel::columns($table);
        $fh = fopen($dir . '/' . strtoupper($table) . '.csv', 'w');
        // Empty escape: pure RFC 4180 CSV. With PHP's default escape, a text
        // with a backslash before a quote came out corrupted.
        fputcsv($fh, $columns, ",", "\"", "");

        $n = 0;
        foreach ($rows as $row) {
            $line = [];
            foreach ($columns as $col) {
                $v = $row[$col] ?? null;
                if ($v !== null && $v !== '') {
                    $fitted = CdmModel::fit($col, $v);
                    // Compared as text: an integer 1 and a "1" are not a truncation.
                    if ((string) $fitted !== (string) $v) {
                        $this->truncated[$col] = ($this->truncated[$col] ?? 0) + 1;
                    }
                    $v = $fitted;
                }
                $line[] = $v === null ? '' : $v;
            }
            fputcsv($fh, $line, ",", "\"", "");
            $n++;
        }
        fclose($fh);
        return $n;
    }

    /**
     * Transformation report, in the project's language. It goes inside the
     * ZIP on purpose: whoever receives the CDM must be able to see what was
     * discarded and why, without going back to REDCap. The file name stays
     * the same in every language (INFORME.txt) so that processes that look
     * for it inside the ZIP keep working.
     */
    private function writeReport(string $dir, array $result, bool $kitIncluded = true): void
    {
        $t = fn(string $k, ...$a) => $this->module->ttText($k, ...$a);
        $s = $result['stats'];
        $lines = [];
        // An explanatory paragraph, wrapped at 68 columns and indented.
        $note = function (string $text) use (&$lines) {
            foreach (explode("\n", wordwrap($text, 68, "\n", true)) as $l) {
                $lines[] = '  ' . $l;
            }
        };
        $section = function (string $title) use (&$lines) {
            $lines[] = '';
            $lines[] = $title;
            $lines[] = str_repeat('-', 60);
        };

        $lines[] = $t('rep_title');
        $lines[] = str_repeat('=', 60);
        $lines[] = '';
        $lines[] = $t('rep_generated', date('Y-m-d H:i:s'));
        $lines[] = $t('rep_project', $this->module->pid());
        $lines[] = $t('rep_module', 'OMOP-CDM Export ' . $this->module->getModuleVersion());
        if (isset($s['dag_id'])) {
            $lines[] = $t('rep_dag', $s['dag_id']);
        }
        $lines[] = '';
        $lines[] = $t('rep_records', $s['records'] ?? 0);
        $lines[] = $t('rep_rows', $s['rows'] ?? 0);

        $section($t('rep_by_table'));
        foreach ($result['counts'] as $table => $n) {
            $lines[] = sprintf('  %-28s %8s', strtoupper($table), number_format($n));
        }

        if (!empty($s['not_emitted'])) {
            $section($t('rep_not_emitted', $s['not_emitted']));
            $note($t('rep_not_emitted_note'));
        }
        if (!empty($s['not_numeric']) || !empty($s['ambiguous_number'])) {
            $section($t('rep_numeric_title'));
            if (!empty($s['not_numeric'])) {
                $note($t('rep_not_numeric', number_format($s['not_numeric'])));
            }
            if (!empty($s['ambiguous_number'])) {
                $note($t('rep_ambiguous', number_format($s['ambiguous_number'])));
            }
            $note($t('rep_numeric_note'));
        }
        // Each section has its own title: with several of them titled
        // «WARNING», a specific one could not be searched for or cited.
        $demographics = array_filter(['gender_not_approved', 'race_not_approved', 'ethnicity_not_approved'],
            fn($k) => !empty($s[$k]));
        if ($demographics) {
            $section($t('rep_person_config_title'));
            foreach ($demographics as $k) {
                $note($t('rep_' . $k));
            }
        }
        if (!empty($s['persons_dropped'])) {
            $section($t('rep_dropped', $s['persons_dropped']));
            $note($t('rep_dropped_note', $s['rows_dropped_with'] ?? 0));
            if (!empty($s['dropped_record_ids'])) {
                // With hashing, the CDM does not carry the Record ID, and this
                // report travels with it: listing them in clear would undo the
                // pseudonymization. Nor their hash: it does not help to find
                // the record that has to be fixed. The list can be seen in the
                // preview, inside REDCap and under its user rights.
                $note($this->module->setting('hash-person-source-value')
                    ? $t('rep_dropped_ids_hashed')
                    : $t('rep_dropped_ids', $s['dropped_record_ids']));
            }
        }
        if (!empty($s['visits'])) {
            $section($t('rep_visits', $s['visits']));
            $note($t('rep_visits_note'));
            if (!empty($s['facts_outside_visit'])) {
                $note($t('rep_outside_visit', $s['facts_outside_visit']));
            }
        }
        $priv = [];
        if (!empty($s['date_shift'])) {
            $priv[] = $t('rep_date_shift', (int) $this->module->setting('date-shift-days'));
        }
        if ($this->module->pseudonymizer()->birthPrecision() !== 'full') {
            $priv[] = $t('rep_birth_' . $this->module->pseudonymizer()->birthPrecision());
        }
        if (!empty($s['free_text_suppressed']) || !empty($s['notes_suppressed'])) {
            $priv[] = $t('rep_free_text_suppressed',
                ($s['free_text_suppressed'] ?? 0) + ($s['notes_suppressed'] ?? 0));
        }
        if ($this->module->setting('hash-person-source-value')) {
            $priv[] = $t('rep_hashed');
        }
        if ($priv) {
            $section($t('rep_privacy_applied'));
            foreach ($priv as $p) {
                $note('· ' . $p);
            }
        }
        if (!empty($s['with_operator'])) {
            $section($t('rep_operator_title'));
            $note($t('rep_operator', $s['with_operator']));
        }
        if (!empty($s['end_before_start'])) {
            $section($t('rep_end_before_start_title'));
            $note($t('rep_end_before_start', $s['end_before_start']));
        }
        if (!empty($s['stcm_unknown_concept'])) {
            $section($t('rep_stcm_title'));
            $note($t('rep_stcm_unknown', $s['stcm_unknown_concept']));
        }
        if (!empty($s['persons_without_period'])) {
            $section($t('rep_no_period_title'));
            $note($t('rep_no_period', $s['persons_without_period']));
        }
        $section($t('rep_ids_title'));
        $note($t('rep_ids'));

        if (!empty($result['missing'])) {
            $section($t('rep_missing_title'));
            foreach ($result['missing'] as $table => $cols) {
                foreach ($cols as $col => $n) {
                    $lines[] = sprintf('  %-44s %s', strtoupper($table) . '.' . $col,
                        $t('ex_missing_rows', number_format($n)));
                }
            }
            $note($t('rep_missing_note'));
        }
        if (!empty($this->truncated)) {
            $section($t('rep_truncated_title'));
            foreach ($this->truncated as $col => $n) {
                $lines[] = sprintf('  %-34s %s', $col,
                    $t('rep_truncated_row', number_format($n), CdmModel::TEXT_LIMITS[$col]));
            }
            $config = array_intersect(array_keys($this->truncated), [
                'cdm_source_name', 'cdm_source_abbreviation', 'cdm_holder',
                'vocabulary_version', 'cdm_etl_reference',
            ]);
            if ($config) {
                $note($t('rep_truncated_config', implode(', ', $config)));
            }
            $note($t('rep_truncated_note'));
        }
        if (!empty($result['skipped'])) {
            $section($t('ex_skipped_title'));
            $total = 0;
            foreach ($result['skipped'] as $reason => $n) {
                $lines[] = sprintf('  %8s  %s', number_format($n), $this->module->skipLabel($reason));
                $total += $n;
            }
            $lines[] = '';
            $note($t('rep_skipped_total', number_format($total)));
        }

        $section($t('rep_next_title'));
        $note($kitIncluded ? $t('rep_next_kit', $this->module->loadSchema()) : $t('rep_next_no_kit'));
        $section($t('rep_privacy_title'));
        $note($t('rep_privacy'));

        file_put_contents($dir . '/INFORME.txt', implode("\n", $lines) . "\n");
    }
}


/**
 * Minimal ZIP writer, without compression (STORE method).
 *
 * It exists only so that the export works on PHP installations without the
 * zip extension. Files are stored uncompressed: the resulting archive is
 * larger, but it opens with any standard tool.
 */
class MiniZip
{
    public static function create(string $zipPath, array $files): void
    {
        $out = fopen($zipPath, 'wb');
        if ($out === false) {
            throw new \RuntimeException('Could not write the ZIP');
        }

        $central = '';
        $offset  = 0;

        // $files: path on disk => name inside the ZIP (may include folders).
        foreach ($files as $file => $name) {
            $data = file_get_contents($file);
            $crc  = crc32($data);
            $len  = strlen($data);
            $time = self::dosTime(filemtime($file) ?: time());

            $local = "\x50\x4b\x03\x04"
                   . pack('v', 20) . pack('v', 0) . pack('v', 0)
                   . pack('V', $time)
                   . pack('V', $crc) . pack('V', $len) . pack('V', $len)
                   . pack('v', strlen($name)) . pack('v', 0)
                   . $name;

            fwrite($out, $local);
            fwrite($out, $data);

            $central .= "\x50\x4b\x01\x02"
                      . pack('v', 20) . pack('v', 20) . pack('v', 0) . pack('v', 0)
                      . pack('V', $time)
                      . pack('V', $crc) . pack('V', $len) . pack('V', $len)
                      . pack('v', strlen($name)) . pack('v', 0) . pack('v', 0)
                      . pack('v', 0) . pack('v', 0) . pack('V', 0)
                      . pack('V', $offset)
                      . $name;

            $offset += strlen($local) + $len;
        }

        $centralOffset = $offset;
        fwrite($out, $central);
        fwrite($out, "\x50\x4b\x05\x06"
            . pack('v', 0) . pack('v', 0)
            . pack('v', count($files)) . pack('v', count($files))
            . pack('V', strlen($central)) . pack('V', $centralOffset)
            . pack('v', 0));
        fclose($out);
    }

    /** Date and time in the packed MS-DOS format that ZIP requires. */
    private static function dosTime(int $ts): int
    {
        $d = getdate($ts);
        if ($d['year'] < 1980) {
            return (1 << 21) | (1 << 16);
        }
        return (($d['year'] - 1980) << 25) | ($d['mon'] << 21) | ($d['mday'] << 16)
             | ($d['hours'] << 11) | ($d['minutes'] << 5) | ($d['seconds'] >> 1);
    }
}
