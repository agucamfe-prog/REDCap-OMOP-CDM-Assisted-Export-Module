<?php

namespace OmopCdm\OmopCdmExportModule;

require_once __DIR__ . '/MappingStore.php';
require_once __DIR__ . '/MappingAnnotation.php';
require_once __DIR__ . '/DictionaryProfiler.php';

/**
 * Mapping document of a project.
 *
 * The curated mapping is the valuable artifact: it is what gets reviewed,
 * discussed with the principal investigator, and outlives whoever made it.
 * Living only inside the module is not enough; it has to be possible to take
 * it out, read it without REDCap at hand and file it next to the study
 * protocol.
 *
 * Two formats with different purposes:
 *  - HTML to read and review with a person.
 *  - JSON to version and compare between exports.
 */
class MappingDocument
{
    private $module;

    public function __construct($module)
    {
        $this->module = $module;
    }

    // -------------------------------------------------------------- Data

    /** Full mapping structure, shared by both formats. */
    public function build(): array
    {
        $rows      = $this->module->mappingRows();
        $structure = $this->module->getProjectStructure();
        $source    = $this->module->cdmSourceConfig();
        $person    = $this->module->personConfig();
        $death     = $this->module->deathConfig();
        $visits    = $this->module->visitConfig();

        $fields = [];
        $byState = [];
        $byDomain = [];

        foreach ($rows as $name => $row) {
            $spec  = $row['spec'];
            $prof  = $row['profile'];
            $state = $row['state'];

            $byState[$state] = ($byState[$state] ?? 0) + 1;
            if ($state === MappingStore::S_APPROVED && !empty($spec['domain'])) {
                $byDomain[$spec['domain']] = ($byDomain[$spec['domain']] ?? 0) + 1;
            }

            $choices = [];
            $meta = $this->module->getDictionary()[$name] ?? [];
            foreach ($prof['choices'] ?? [] as $raw => $label) {
                $v = $row['values'][(string) $raw] ?? null;
                $choices[] = [
                    'raw_value'    => (string) $raw,
                    'label'        => (string) $label,
                    // With no saved decision, what would be exported is the
                    // default: the «No» of a yes/no does not emit.
                    'emit'         => $v === null
                        ? !DictionaryProfiler::isNegativeChoice($meta, (string) $raw)
                        : (bool) $v['emit'],
                    'concept_id'   => $v['concept_id'] ?? null,
                    'concept_name' => $v['concept_name'] ?? null,
                ];
            }

            $fields[$name] = [
                'field_name'        => $name,
                'form_name'         => $prof['form_name'],
                'field_label'       => $prof['field_label'],
                'field_type'        => $prof['field_type'],
                'is_identifier'     => $prof['is_identifier'],
                'suggested_domain'  => $prof['suggested_domain'],
                'suggested_reason'  => $prof['reason'],
                'state'             => $state,
                'domain'            => $spec['domain'],
                'cdm_table'         => $spec['domain'] ? CdmModel::tableForDomain($spec['domain']) : null,
                'value_strategy'    => $spec['value_strategy'],
                'concept_id'        => $spec['concept_id'],
                'concept_name'      => $spec['concept_name'],
                'date_field'        => $spec['date_field'],
                'end_date_field'    => $spec['end_date_field'] ?? null,
                'unit_source_value' => $spec['unit_source_value'],
                'notes'             => $spec['notes'],
                'approved_by'       => $spec['approved_by'],
                'approved_at'       => $spec['approved_at'],
                'drifted'           => $row['drifted'],
                'drift_reason'      => $row['drift_reason'] ?? null,
                'role'              => $row['role'] ?? null,
                'choices'           => $choices,
                'warnings'          => array_map(
                    fn($w) => ['severity' => $w['severity'], 'text' => $w['text']],
                    $this->module->advise($name)
                ),
            ];
        }

        return [
            'generated'   => date('c'),
            'module'      => 'OMOP-CDM Export ' . $this->module->getModuleVersion(),
            'cdm_version' => 'v5.4',
            'project'     => [
                'project_id'      => $this->module->pid(),
                'record_id_field' => $structure['record_id_field'],
                'longitudinal'    => $structure['longitudinal'],
                'events'          => array_values(array_map(
                    fn($e) => $e['unique_name'], $structure['events'] ?? [])),
                'repeating'       => $structure['repeating'] ?? [],
            ],
            'cdm_source'  => $source,
            'person'      => $person,
            'death'       => $death,
            'visits'      => $visits,
            'summary'     => [
                'total'       => count($fields),
                'by_state'    => $byState,
                'by_domain'   => $byDomain,
            ],
            'fields'      => $fields,
        ];
    }

    public function json(): string
    {
        return json_encode($this->build(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * The mapping in the format of Usagi, the OHDSI tool for reviewing
     * mappings. It opens with «File → Open» and lets you review it against
     * the vocabulary, or continue the work there.
     *
     * A field with its own concept is a MAPS_TO row; with «value per choice»,
     * each answer is a MAPS_TO_VALUE row. Only what is approved carries
     * mappingStatus APPROVED; everything else goes as UNCHECKED.
     */
    public function usagiCsv(): string
    {
        $d = $this->build();
        $fh = fopen('php://temp', 'w+');
        fputcsv($fh, ['sourceCode', 'sourceName', 'sourceFrequency', 'sourceAutoAssignedConceptIds',
                      'matchScore', 'mappingStatus', 'equivalence', 'statusSetBy', 'statusSetOn',
                      'conceptId', 'conceptName', 'domainId', 'mappingType', 'comment',
                      'createdBy', 'createdOn', 'assignedReviewer'], ',', '"', '');
        foreach ($d['fields'] as $name => $f) {
            if ($f['state'] === MappingStore::S_EXCLUDED || empty($f['domain'])
                || in_array($f['domain'], [DictionaryProfiler::D_SKIP, DictionaryProfiler::D_DATE_ANCHOR], true)) {
                continue;
            }
            $status = $f['state'] === MappingStore::S_APPROVED ? 'APPROVED' : 'UNCHECKED';
            $when = $f['approved_at'] ? strtotime($f['approved_at']) * 1000 : 0;
            $writeRow = function ($code, $label, $id, $cname, $type) use ($fh, $status, $f, $when) {
                fputcsv($fh, [$code, $label, 0, '', 0, $status, 'UNREVIEWED',
                              (string) $f['approved_by'], $when, (int) $id, (string) $cname,
                              (string) $f['domain'], $type, (string) $f['notes'],
                              (string) $f['approved_by'], $when, ''], ',', '"', '');
            };
            $perChoice = MappingStore::usesOptions($f['value_strategy']) && $f['choices'];
            if (!empty($f['concept_id']) && !($perChoice && $f['value_strategy'] === MappingStore::ST_PER_CHOICE)) {
                $writeRow($name, $f['field_label'], $f['concept_id'], $f['concept_name'], 'MAPS_TO');
            }
            if ($perChoice) {
                foreach ($f['choices'] as $o) {
                    if ($o['emit'] && !empty($o['concept_id'])) {
                        $writeRow($name . '=' . $o['raw_value'], $f['field_label'] . ': ' . $o['label'],
                              $o['concept_id'], $o['concept_name'],
                              $f['value_strategy'] === MappingStore::ST_VALUE_CONCEPT ? 'MAPS_TO_VALUE' : 'MAPS_TO');
                    }
                }
            }
        }
        rewind($fh);
        return stream_get_contents($fh);
    }

    /**
     * The project's data dictionary with the mapping written as @OMOP tags
     * in each field's annotation.
     *
     * The module never writes to the project: this CSV is uploaded by hand
     * in the Designer (Data Dictionary → Upload), with its review and its
     * draft mode. From then on the mapping travels with the instrument.
     *
     * Only fields with a firm decision (approved or excluded) are written;
     * the rest keep their annotation as it is.
     */
    public function annotatedDictionaryCsv(): string
    {
        $rows = $this->module->mappingRows();
        $cols = [
            'field_name' => 'Variable / Field Name', 'form_name' => 'Form Name',
            'section_header' => 'Section Header', 'field_type' => 'Field Type',
            'field_label' => 'Field Label',
            'select_choices_or_calculations' => 'Choices, Calculations, OR Slider Labels',
            'field_note' => 'Field Note',
            'text_validation_type_or_show_slider_number' => 'Text Validation Type OR Show Slider Number',
            'text_validation_min' => 'Text Validation Min', 'text_validation_max' => 'Text Validation Max',
            'identifier' => 'Identifier?', 'branching_logic' => 'Branching Logic (Show field only if...)',
            'required_field' => 'Required Field?', 'custom_alignment' => 'Custom Alignment',
            'question_number' => 'Question Number (surveys only)', 'matrix_group_name' => 'Matrix Group Name',
            'matrix_ranking' => 'Matrix Ranking?', 'field_annotation' => 'Field Annotation',
        ];
        $fh = fopen('php://temp', 'w+');
        fwrite($fh, "\xEF\xBB\xBF");
        fputcsv($fh, array_values($cols), ',', '"', '');
        foreach ($this->module->getDictionary() as $name => $meta) {
            $r = $rows[$name] ?? null;
            if ($r !== null && in_array($r['state'], [MappingStore::S_APPROVED, MappingStore::S_EXCLUDED], true)) {
                $tags = MappingAnnotation::render($r['spec'], $r['values'],
                    $r['state'] === MappingStore::S_EXCLUDED);
                $meta['field_annotation'] = MappingAnnotation::merge((string) ($meta['field_annotation'] ?? ''), $tags);
            }
            $line = [];
            foreach (array_keys($cols) as $k) {
                $line[] = (string) ($meta[$k] ?? '');
            }
            fputcsv($fh, $line, ',', '"', '');
        }
        rewind($fh);
        return stream_get_contents($fh);
    }

    // --------------------------------------------------------------- HTML

    /**
     * Self-contained document: no external CSS or JavaScript, so that it can
     * be archived, sent by email or printed to PDF and still read the same
     * five years from now.
     */
    public function html(): string
    {
        $d = $this->build();
        // Plain text and a single escape: with tt() + htmlspecialchars the
        // document showed «Athena&#039;s».
        $t = fn(string $k, ...$a) => $this->module->ttText($k, ...$a);
        $e = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

        $stateColors = [
            MappingStore::S_APPROVED => '#1a7f37',
            MappingStore::S_EXCLUDED => '#57606a',
            MappingStore::S_PROPOSED => '#9a6700',
            MappingStore::S_REVIEW   => '#cf222e',
        ];

        $h  = "<!DOCTYPE html>\n<html lang=\"" . $e($t('doc_lang')) . "\">\n<head>\n";
        $h .= "<meta charset=\"utf-8\">\n<title>" . $e($t('doc_title', $d['project']['project_id'])) . "</title>\n";
        $h .= "<style>
 body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;
      margin:0;padding:32px;color:#1f2328;line-height:1.5;max-width:1100px}
 h1{font-size:22px;margin:0 0 4px} h2{font-size:16px;margin:28px 0 10px;
      padding-bottom:5px;border-bottom:1px solid #d0d7de}
 .meta{color:#57606a;font-size:13px;margin-bottom:20px}
 table{border-collapse:collapse;width:100%;font-size:12.5px;margin-bottom:14px}
 th,td{border:1px solid #d0d7de;padding:6px 8px;text-align:left;vertical-align:top}
 th{background:#f6f8fa;font-weight:600}
 code{background:#f6f8fa;padding:1px 4px;border-radius:3px;font-size:12px}
 .state{font-weight:600;white-space:nowrap}
 .warn{color:#9a6700;font-size:11.5px} .block{color:#cf222e;font-size:11.5px}
 .choices{margin:4px 0 0;padding-left:16px;font-size:11.5px;color:#57606a}
 .no-row{color:#57606a;font-style:italic}
 .summary{display:flex;gap:22px;flex-wrap:wrap;margin-bottom:6px;font-size:13px}
 .footer{margin-top:32px;padding-top:12px;border-top:1px solid #d0d7de;
      color:#57606a;font-size:12px}
 @media print{body{padding:0} h2{page-break-after:avoid} tr{page-break-inside:avoid}}
</style>\n</head>\n<body>\n";

        $h .= "<h1>" . $e($t('doc_title', $d['project']['project_id'])) . "</h1>\n";
        $h .= "<div class=\"meta\">" . $e($t('doc_generated', substr($d['generated'], 0, 19), $d['module'])) . "</div>\n";

        // --- Source and configuration
        $h .= "<h2>" . $e($t('doc_sec_source')) . "</h2>\n<table>\n";
        foreach ([
            $t('cfg_source_name_short')  => $d['cdm_source']['name'],
            $t('cfg_source_abbr_short')  => $d['cdm_source']['abbreviation'],
            $t('cfg_holder')             => $d['cdm_source']['holder'],
            $t('doc_vocab')              => $d['cdm_source']['vocabulary_version'],
            $t('doc_cdm_version')        => $d['cdm_version'],
            $t('doc_longitudinal')       => $d['project']['longitudinal'] ? $t('doc_yes') : $t('doc_no'),
            $t('doc_events')             => $d['project']['events'] ? implode(', ', $d['project']['events']) : '—',
            $t('doc_visits')             => $d['visits']['enabled']
                                            ? $t('doc_visits_on', $d['visits']['concept_id'])
                                            : $t('doc_visits_off'),
            $t('doc_birth_field')        => $d['person']['birth_date_field'] ?: ($d['person']['birth_year_field'] ?: '—'),
            $t('doc_gender_field')       => $d['person']['gender_field'] ?: '—',
            $t('doc_race_field')         => $d['person']['race_field'] ?: '—',
            $t('doc_ethnicity_field')    => $d['person']['ethnicity_field'] ?: '—',
            $t('doc_death_field')        => $d['death']['date_field'] ?: '—',
        ] as $k => $v) {
            $h .= "<tr><th style=\"width:32%\">" . $e($k) . "</th><td>" . $e($v ?: '—') . "</td></tr>\n";
        }
        $h .= "</table>\n";

        // --- Summary
        $h .= "<h2>" . $e($t('doc_sec_summary')) . "</h2>\n<div class=\"summary\">";
        $h .= "<div><b>" . (int) $d['summary']['total'] . "</b> " . $e($t('card_fields')) . "</div>";
        foreach ($d['summary']['by_state'] as $state => $n) {
            $h .= "<div><b>$n</b> " . $e($t('ed_state_' . $state)) . "</div>";
        }
        $h .= "</div>\n";
        if ($d['summary']['by_domain']) {
            $h .= "<div class=\"summary\">";
            foreach ($d['summary']['by_domain'] as $dom => $n) {
                $h .= "<div>" . $e($dom) . ": <b>$n</b></div>";
            }
            $h .= "</div>\n";
        }

        // --- Exported fields
        $h .= "<h2>" . $e($t('doc_sec_mapped')) . "</h2>\n";
        $h .= $this->fieldsTable($d, [MappingStore::S_APPROVED], $stateColors, true);

        // --- Fields not exported
        $others = array_filter($d['fields'],
            fn($f) => $f['state'] !== MappingStore::S_APPROVED);
        if ($others) {
            $h .= "<h2>" . $e($t('doc_sec_unmapped')) . "</h2>\n";
            $h .= "<p style=\"font-size:12.5px;color:#57606a;margin:0 0 10px\">"
                . $e($t('doc_unmapped_note')) . "</p>\n";
            $h .= $this->fieldsTable($d, [MappingStore::S_PROPOSED,
                MappingStore::S_EXCLUDED, MappingStore::S_REVIEW, MappingStore::S_CONFIG], $stateColors, false);
        }

        $h .= "<div class=\"footer\">" . $e($t('doc_footer')) . "</div>\n</body>\n</html>";
        return $h;
    }

    /** Fields table, with or without the mapping columns depending on the block. */
    private function fieldsTable(array $d, array $states, array $colors, bool $detailed): string
    {
        $t = fn(string $k, ...$a) => $this->module->ttText($k, ...$a);
        $e = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

        $rows = array_filter($d['fields'], fn($f) => in_array($f['state'], $states, true));
        if (!$rows) {
            return "<p style=\"color:#57606a;font-size:13px\">" . $e($t('doc_none')) . "</p>\n";
        }

        $h = "<table>\n<tr>";
        $h .= "<th style=\"width:20%\">" . $e($t('th_field')) . "</th>";
        $h .= "<th style=\"width:11%\">" . $e($t('th_form')) . "</th>";
        $h .= "<th style=\"width:9%\">" . $e($t('ed_th_state')) . "</th>";
        if ($detailed) {
            $h .= "<th style=\"width:10%\">" . $e($t('th_domain')) . "</th>";
            $h .= "<th>" . $e($t('ed_th_concept')) . "</th>";
            $h .= "<th style=\"width:13%\">" . $e($t('ed_th_datefield')) . "</th>";
            $h .= "<th style=\"width:12%\">" . $e($t('doc_approved_by')) . "</th>";
        } else {
            $h .= "<th>" . $e($t('th_reason')) . "</th>";
        }
        $h .= "</tr>\n";

        foreach ($rows as $f) {
            $color = $colors[$f['state']] ?? '#57606a';
            $h .= "<tr>";
            $h .= "<td><code>" . $e($f['field_name']) . "</code>";
            if ($f['is_identifier']) {
                $h .= " <b style=\"color:#cf222e\">" . $e($t('badge_phi')) . "</b>";
            }
            $h .= "<div style=\"color:#57606a;font-size:11.5px\">"
                . $e(mb_strimwidth($f['field_label'], 0, 70, '…')) . "</div></td>";
            $h .= "<td>" . $e($f['form_name']) . "</td>";
            $h .= "<td class=\"state\" style=\"color:$color\">" . $e($t('ed_state_' . $f['state'])) . "</td>";

            if ($detailed) {
                $h .= "<td>" . $e($f['domain']) . "<div style=\"color:#57606a;font-size:11px\">"
                    . $e($f['cdm_table']) . "</div></td>";

                $h .= "<td>";
                if ($f['value_strategy'] === MappingStore::ST_VALUE_CONCEPT && $f['choices']) {
                    // The question and, below it, each answer as a value.
                    $h .= "<code>" . $e($f['concept_id'] ?: '—') . "</code> " . $e($f['concept_name'] ?: '')
                        . " <span style=\"color:#57606a;font-size:11px\">(" . $e($t('doc_value_concept')) . ")</span>";
                }
                if (MappingStore::usesOptions($f['value_strategy']) && $f['choices']) {
                    $h .= "<ul class=\"choices\">";
                    foreach ($f['choices'] as $o) {
                        if ($o['emit'] === false) {
                            $h .= "<li class=\"no-row\">" . $e($o['label']) . " — "
                                . $e($t('doc_no_row')) . "</li>";
                            continue;
                        }
                        $h .= "<li>" . $e($o['label']) . " → <code>" . $e($o['concept_id'] ?: '—')
                            . "</code> " . $e($o['concept_name'] ?: '') . "</li>";
                    }
                    $h .= "</ul>";
                } else {
                    $h .= "<code>" . $e($f['concept_id'] ?: '—') . "</code> " . $e($f['concept_name'] ?: '');
                }
                if ($f['unit_source_value']) {
                    $h .= "<div style=\"color:#57606a;font-size:11.5px\">"
                        . $e($t('lbl_unit')) . ": " . $e($f['unit_source_value']) . "</div>";
                }
                foreach ($f['warnings'] as $w) {
                    $class = $w['severity'] === 'block' ? 'block' : 'warn';
                    if ($w['severity'] === 'info') {
                        continue;
                    }
                    $h .= "<div class=\"$class\">" . $e($w['text']) . "</div>";
                }
                if ($f['notes']) {
                    $h .= "<div style=\"font-size:11.5px\"><i>" . $e($f['notes']) . "</i></div>";
                }
                $h .= "</td>";

                $h .= "<td><code>" . $e($f['date_field'] ?: '—') . "</code></td>";
                $h .= "<td style=\"font-size:11.5px\">" . $e($f['approved_by'] ?: '—');
                if ($f['approved_at']) {
                    $h .= "<div style=\"color:#57606a\">" . $e(substr($f['approved_at'], 0, 16)) . "</div>";
                }
                $h .= "</td>";
            } else {
                $reason = $f['drift_reason'] ? $t('ed_drift_' . $f['drift_reason'])
                    : ($f['role'] ? $t('ed_role_' . $f['role']) : $f['suggested_reason']);
                $h .= "<td style=\"font-size:11.5px;color:#57606a\">" . $e($reason) . "</td>";
            }
            $h .= "</tr>\n";
        }
        return $h . "</table>\n";
    }
}
