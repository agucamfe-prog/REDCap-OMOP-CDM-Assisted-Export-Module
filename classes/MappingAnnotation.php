<?php

namespace OmopCdm\OmopCdmExportModule;

require_once __DIR__ . '/CdmModel.php';
require_once __DIR__ . '/MappingStore.php';

/**
 * The mapping written into the data dictionary itself, as action tags.
 *
 * Stored only in the module settings, the mapping dies with the project. In
 * the field annotation it travels with it: in the project XML, in copies, in
 * the REDCap Shared Library and in any project that reuses the instrument. A
 * PHQ-9 mapped once serves everyone who uses it.
 *
 * Format (one tag per line or separated by spaces):
 *
 *   @OMOP-DOMAIN=Measurement
 *   @OMOP-CONCEPT="3025315 | Body weight"
 *   @OMOP-STRATEGY=value_concept
 *   @OMOP-VALUES="1=9191 | Positive; 2=9189 | Negative; 3=-"
 *   @OMOP-DATE=fecha_visita
 *   @OMOP-ENDDATE=fecha_fin
 *   @OMOP-UNIT="mg/dL"
 *   @OMOP-EXCLUDE
 *
 * In VALUES, "-" means "this option produces no row". An annotation is a
 * PROPOSAL: it reaches the editor as a proposal and someone has to approve it
 * in this project. That someone else wrote it approves nothing here.
 */
class MappingAnnotation
{
    const PREFIX = '@OMOP-';

    /**
     * @return array|null ['spec' => [...], 'values' => [raw => [...]], 'exclude' => bool]
     *                    or null if the annotation carries no @OMOP tag.
     */
    public static function parse(string $annotation): ?array
    {
        if (stripos($annotation, self::PREFIX) === false) {
            return null;
        }
        $tags = [];
        preg_match_all('/@OMOP-([A-Z]+)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\']+)))?/i',
                       $annotation, $m, PREG_SET_ORDER);
        foreach ($m as $t) {
            $val = ($t[2] ?? '') !== '' ? $t[2] : (($t[3] ?? '') !== '' ? $t[3] : ($t[4] ?? ''));
            $tags[strtoupper($t[1])] = trim($val);
        }
        if (!$tags) {
            return null;
        }

        $spec = [];
        if (!empty($tags['DOMAIN'])) {
            $spec['domain'] = self::canonicalDomain($tags['DOMAIN']);
        }
        if (!empty($tags['CONCEPT'])) {
            [$spec['concept_id'], $spec['concept_name']] = self::idAndName($tags['CONCEPT']);
        }
        if (!empty($tags['STRATEGY'])) {
            $spec['value_strategy'] = strtolower($tags['STRATEGY']);
        }
        if (array_key_exists('DATE', $tags)) {
            $spec['date_field'] = $tags['DATE'] !== '' ? $tags['DATE'] : null;
        }
        if (!empty($tags['ENDDATE'])) {
            $spec['end_date_field'] = $tags['ENDDATE'];
        }
        if (!empty($tags['UNIT'])) {
            $spec['unit_source_value'] = $tags['UNIT'];
        }

        $values = [];
        if (!empty($tags['VALUES'])) {
            foreach (preg_split('/\s*;\s*/', $tags['VALUES']) as $pair) {
                if (!preg_match('/^\s*([^=]+?)\s*=\s*(.+)$/', $pair, $pm)) {
                    continue;
                }
                $raw = $pm[1];
                if (trim($pm[2]) === '-') {
                    $values[$raw] = ['raw_value' => $raw, 'emit' => false, 'concept_id' => null, 'concept_name' => null];
                    continue;
                }
                [$id, $name] = self::idAndName($pm[2]);
                $values[$raw] = ['raw_value' => $raw, 'emit' => true, 'concept_id' => $id, 'concept_name' => $name];
            }
        }

        return ['spec' => $spec, 'values' => $values, 'exclude' => array_key_exists('EXCLUDE', $tags)];
    }

    /** Tags that describe the mapping of a field. */
    public static function render(array $spec, array $values, bool $excluded): string
    {
        if ($excluded) {
            return self::PREFIX . 'EXCLUDE';
        }
        $out = [];
        if (!empty($spec['domain'])) {
            $out[] = self::PREFIX . 'DOMAIN=' . $spec['domain'];
        }
        if (!empty($spec['concept_id'])) {
            $out[] = self::PREFIX . 'CONCEPT="' . self::clean($spec['concept_id'] . ' | ' . ($spec['concept_name'] ?? '')) . '"';
        }
        if (!empty($spec['value_strategy'])) {
            $out[] = self::PREFIX . 'STRATEGY=' . $spec['value_strategy'];
        }
        if (MappingStore::usesOptions($spec['value_strategy'] ?? null) && $values) {
            $pairs = [];
            foreach ($values as $raw => $v) {
                $pairs[] = $raw . '=' . (empty($v['emit']) ? '-'
                    : self::clean(($v['concept_id'] ?? '') . ' | ' . ($v['concept_name'] ?? '')));
            }
            $out[] = self::PREFIX . 'VALUES="' . implode('; ', $pairs) . '"';
        }
        if (!empty($spec['date_field'])) {
            $out[] = self::PREFIX . 'DATE=' . $spec['date_field'];
        }
        if (!empty($spec['end_date_field'])) {
            $out[] = self::PREFIX . 'ENDDATE=' . $spec['end_date_field'];
        }
        if (!empty($spec['unit_source_value'])) {
            $out[] = self::PREFIX . 'UNIT="' . self::clean($spec['unit_source_value']) . '"';
        }
        return implode("\n", $out);
    }

    /**
     * Replaces the @OMOP tags of an annotation with others, keeping the
     * rest (@HIDDEN, @DEFAULT, team notes...).
     */
    public static function merge(string $annotation, string $tags): string
    {
        $cleaned = preg_replace('/@OMOP-[A-Z]+(?:\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s"\']+))?/i', '', $annotation);
        $cleaned = trim(preg_replace("/[ \t]*\n[ \t]*(\n[ \t]*)+/", "\n", $cleaned));
        return trim($cleaned . ($cleaned !== '' && $tags !== '' ? "\n" : '') . $tags);
    }

    private static function idAndName(string $v): array
    {
        $parts = array_map('trim', explode('|', $v, 2));
        $id = preg_match('/^\d+$/', $parts[0]) ? (int) $parts[0] : null;
        return [$id, ($parts[1] ?? '') !== '' ? $parts[1] : null];
    }

    /** Double quotes and ';' would break the tag format. */
    private static function clean(string $s): string
    {
        return trim(str_replace(['"', ';'], ["'", ','], $s), ' |');
    }

    private static function canonicalDomain(string $d): string
    {
        foreach (CdmModel::validDomains() as $valid) {
            if (strcasecmp($valid, $d) === 0) {
                return $valid;
            }
        }
        return $d;
    }
}
