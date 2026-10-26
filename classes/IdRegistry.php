<?php

namespace OmopCdm\OmopCdmExportModule;

/**
 * Identifiers that stay stable across exports.
 *
 * An identifier that depended on the order of the records would change
 * whenever one was deleted or added, and a CDM loaded yesterday would no
 * longer line up with today's. Without stable identifiers there are no
 * incremental loads, no linkage with other sources, and no way to say
 * "patient 41 from the March export".
 *
 * Two mechanisms, depending on what is being identified:
 *
 *  - Entities (persons, visits, care sites, providers): a persistent registry
 *    in the project settings. Each key gets the next free number the first
 *    time it is seen and keeps it forever. A number is never reused, even if
 *    its record disappears.
 *  - Facts (measurements, diagnoses...): there can be hundreds of thousands of
 *    them, so they are not stored. Their identifier is derived from a key that
 *    describes them (record, event, instance, field, option) with a 31-bit
 *    hash; collisions, which are very rare, are resolved in key order so the
 *    result is the same on every export.
 */
class IdRegistry
{
    const SETTING_KEY = 'omop-id-registry';

    private $module;
    private $dryRun;
    private $data;
    private $dirty = false;

    /**
     * @param bool $dryRun In preview mode provisional numbers are assigned but
     *                     not saved: previewing must not consume ids.
     */
    public function __construct($module, bool $dryRun = false)
    {
        $this->module = $module;
        $this->dryRun = $dryRun;
        $raw = $module->getProjectSetting(self::SETTING_KEY);
        $data = is_array($raw) ? $raw : json_decode((string) $raw, true);
        $this->data = is_array($data) ? $data : [];
        $this->data['ids']  = $this->data['ids'] ?? [];
        $this->data['next'] = $this->data['next'] ?? [];
    }

    /** Stable identifier of an entity; creates it the first time. */
    public function id(string $kind, string $key): int
    {
        if (isset($this->data['ids'][$kind][$key])) {
            return (int) $this->data['ids'][$kind][$key];
        }
        $n = (int) ($this->data['next'][$kind] ?? 1);
        $this->data['ids'][$kind][$key] = $n;
        $this->data['next'][$kind] = $n + 1;
        $this->dirty = true;
        return $n;
    }

    /** Does it already have a number? Without creating one. */
    public function has(string $kind, string $key): bool
    {
        return isset($this->data['ids'][$kind][$key]);
    }

    /** Saves the new numbers. Does nothing in preview mode. */
    public function save(): void
    {
        if ($this->dryRun || !$this->dirty) {
            return;
        }
        $this->data['updated'] = date('Y-m-d H:i:s');
        $this->module->setProjectSetting(self::SETTING_KEY,
            json_encode($this->data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $this->dirty = false;
    }

    /** Number of registered entities per kind, for the report. */
    public function counts(): array
    {
        return array_map('count', $this->data['ids']);
    }

    /**
     * Assigns deterministic identifiers to the rows of a fact table.
     *
     * Each row arrives with '__key'. The result depends only on the set of
     * keys, not on the order in which the rows were generated.
     *
     * @param array $rows rows with '__key'
     * @param string $idColumn column that receives the identifier
     */
    public static function assignFactIds(array &$rows, string $idColumn): void
    {
        $order = array_keys($rows);
        usort($order, fn($a, $b) => strcmp($rows[$a]['__key'], $rows[$b]['__key']));
        $used = [];
        foreach ($order as $i) {
            $id = self::hash31($rows[$i]['__key']);
            // Linear probing: the next free one. It is deterministic because
            // the keys are always walked in the same order.
            while (isset($used[$id])) {
                $id = $id >= 2147483647 ? 1 : $id + 1;
            }
            $used[$id] = true;
            $rows[$i][$idColumn] = $id;
            unset($rows[$i]['__key']);
        }
    }

    /** Positive 31-bit integer (fits in the integer type of the CDM DDL). */
    public static function hash31(string $key): int
    {
        $n = hexdec(substr(hash('sha256', $key), 0, 8)) & 0x7FFFFFFF;
        return $n === 0 ? 1 : $n;
    }
}
