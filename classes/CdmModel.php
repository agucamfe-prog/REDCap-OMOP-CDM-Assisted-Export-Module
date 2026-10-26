<?php

namespace OmopCdm\OmopCdmExportModule;

/**
 * Definition of the subset of OMOP CDM v5.4 this module targets.
 *
 * Column order is the official DDL order, because it is used as is for the
 * header of the output CSV files (positional loaders require it).
 *
 * Source: https://ohdsi.github.io/CommonDataModel/cdm54.html
 * The specification is written here by hand and is not read at run time, so
 * the model does not depend on any file in production. The tests compare it,
 * column by column, with resources/OMOP_CDMv5.4_Field_Level.csv, so any
 * divergence is caught before release.
 */
class CdmModel
{
    /** OMOP domains this module can generate. */
    const DOMAIN_TABLE = [
        'Person'      => 'person',
        'Visit'       => 'visit_occurrence',
        'Condition'   => 'condition_occurrence',
        'Drug'        => 'drug_exposure',
        'Procedure'   => 'procedure_occurrence',
        'Measurement' => 'measurement',
        'Observation' => 'observation',
        'Device'      => 'device_exposure',
        'Specimen'    => 'specimen',
        'Death'       => 'death',
        'Note'        => 'note',
    ];

    /** Domains the editor accepts. Any other value is rejected. */
    public static function validDomains(): array
    {
        return ['Condition', 'Drug', 'Measurement', 'Observation', 'Procedure',
                'Device', 'Specimen', 'Visit', 'Person', 'Death', 'Note',
                'DateAnchor', 'NotMapped'];
    }

    /**
     * OMOP operators (Meas Value Operator domain).
     *
     * «< 50» is not the number 50: it is «less than 50». OMOP has a column for
     * exactly that, operator_concept_id, so storing the operator and the
     * number keeps the meaning instead of inventing one.
     */
    const OPERATORS = [
        '<'  => 4171756,
        '<=' => 4171754,
        '='  => 4172703,
        '>=' => 4171755,
        '>'  => 4172704,
    ];

    /**
     * UCUM concepts for the most frequent units in clinical forms.
     *
     * Only those used daily in OHDSI ETLs that leave no room for doubt. Any
     * other unit needs the vocabulary (local index or WebAPI): a wrong
     * unit_concept_id is worse than an empty one.
     */
    const UCUM_BUILTIN = [
        'kg'     => 9529,
        'cm'     => 8582,
        'mm[Hg]' => 8876,
        '%'      => 8554,
        'g/dL'   => 8713,
        'mg/dL'  => 8840,
        'mmol/L' => 8753,
        'umol/L' => 8749,
    ];

    /**
     * UCUM code of a unit as it is written in a form.
     * «mmHg» → «mm[Hg]», «µmol/l» → «umol/L», «lpm» → «{beats}/min».
     */
    public static function ucumCode(?string $unit): ?string
    {
        if ($unit === null || trim($unit) === '') {
            return null;
        }
        $u = trim($unit);
        $k = strtolower(str_replace([' ', 'µ', 'μ'], ['', 'u', 'u'], $u));
        $alias = [
            'kg' => 'kg', 'g' => 'g', 'mg' => 'mg', 'cm' => 'cm', 'm' => 'm', 'mm' => 'mm',
            'mmhg' => 'mm[Hg]', 'mm[hg]' => 'mm[Hg]', '%' => '%',
            'g/dl' => 'g/dL', 'mg/dl' => 'mg/dL', 'mg/l' => 'mg/L', 'g/l' => 'g/L',
            'mmol/l' => 'mmol/L', 'umol/l' => 'umol/L', 'nmol/l' => 'nmol/L',
            'u/l' => 'U/L', 'ui/l' => '[IU]/L', 'iu/l' => '[IU]/L', 'mui/l' => 'm[IU]/L', 'miu/l' => 'm[IU]/L',
            'ng/ml' => 'ng/mL', 'pg/ml' => 'pg/mL', 'ml' => 'mL', 'l' => 'L',
            'bpm' => '{beats}/min', 'lpm' => '{beats}/min', 'lat/min' => '{beats}/min',
            '{beats}/min' => '{beats}/min', 'rpm' => '{breaths}/min', 'resp/min' => '{breaths}/min',
            '°c' => 'Cel', 'ºc' => 'Cel', 'c' => 'Cel', 'cel' => 'Cel',
            'kg/m2' => 'kg/m2', 'kg/m²' => 'kg/m2',
            'ml/min/1.73m2' => 'mL/min/{1.73_m2}', 'ml/min/1,73m2' => 'mL/min/{1.73_m2}',
            'cel/ul' => '/uL', 'cells/ul' => '/uL', 'celulas/ul' => '/uL', '/ul' => '/uL',
            'copias/ml' => '{copies}/mL', 'copies/ml' => '{copies}/mL', '{copies}/ml' => '{copies}/mL',
            'años' => 'a', 'anos' => 'a', 'years' => 'a', 'meses' => 'mo', 'months' => 'mo',
            'dias' => 'd', 'días' => 'd', 'days' => 'd', 'h' => 'h', 'horas' => 'h', 'hours' => 'h',
            'min' => 'min',
        ];
        return $alias[$k] ?? null;
    }

    /** Gender concept IDs. They are stable and universally used. */
    const GENDER_MALE    = 8507;
    const GENDER_FEMALE  = 8532;
    const GENDER_UNKNOWN = 8551;
    const CONCEPT_NO_MATCH = 0;

    public static function tables(): array
    {
        return [
            'person' => [
                'person_id', 'gender_concept_id', 'year_of_birth', 'month_of_birth',
                'day_of_birth', 'birth_datetime', 'race_concept_id', 'ethnicity_concept_id',
                'location_id', 'provider_id', 'care_site_id', 'person_source_value',
                'gender_source_value', 'gender_source_concept_id', 'race_source_value',
                'race_source_concept_id', 'ethnicity_source_value', 'ethnicity_source_concept_id',
            ],

            'observation_period' => [
                'observation_period_id', 'person_id', 'observation_period_start_date',
                'observation_period_end_date', 'period_type_concept_id',
            ],

            'visit_occurrence' => [
                'visit_occurrence_id', 'person_id', 'visit_concept_id', 'visit_start_date',
                'visit_start_datetime', 'visit_end_date', 'visit_end_datetime',
                'visit_type_concept_id', 'provider_id', 'care_site_id', 'visit_source_value',
                'visit_source_concept_id', 'admitted_from_concept_id', 'admitted_from_source_value',
                'discharged_to_concept_id', 'discharged_to_source_value',
                'preceding_visit_occurrence_id',
            ],

            'condition_occurrence' => [
                'condition_occurrence_id', 'person_id', 'condition_concept_id',
                'condition_start_date', 'condition_start_datetime', 'condition_end_date',
                'condition_end_datetime', 'condition_type_concept_id',
                'condition_status_concept_id', 'stop_reason', 'provider_id',
                'visit_occurrence_id', 'visit_detail_id', 'condition_source_value',
                'condition_source_concept_id', 'condition_status_source_value',
            ],

            'drug_exposure' => [
                'drug_exposure_id', 'person_id', 'drug_concept_id', 'drug_exposure_start_date',
                'drug_exposure_start_datetime', 'drug_exposure_end_date',
                'drug_exposure_end_datetime', 'verbatim_end_date', 'drug_type_concept_id',
                'stop_reason', 'refills', 'quantity', 'days_supply', 'sig', 'route_concept_id',
                'lot_number', 'provider_id', 'visit_occurrence_id', 'visit_detail_id',
                'drug_source_value', 'drug_source_concept_id', 'route_source_value',
                'dose_unit_source_value',
            ],

            'procedure_occurrence' => [
                'procedure_occurrence_id', 'person_id', 'procedure_concept_id', 'procedure_date',
                'procedure_datetime', 'procedure_end_date', 'procedure_end_datetime',
                'procedure_type_concept_id', 'modifier_concept_id', 'quantity', 'provider_id',
                'visit_occurrence_id', 'visit_detail_id', 'procedure_source_value',
                'procedure_source_concept_id', 'modifier_source_value',
            ],

            'measurement' => [
                'measurement_id', 'person_id', 'measurement_concept_id', 'measurement_date',
                'measurement_datetime', 'measurement_time', 'measurement_type_concept_id',
                'operator_concept_id', 'value_as_number', 'value_as_concept_id',
                'unit_concept_id', 'range_low', 'range_high', 'provider_id',
                'visit_occurrence_id', 'visit_detail_id', 'measurement_source_value',
                'measurement_source_concept_id', 'unit_source_value', 'unit_source_concept_id',
                'value_source_value', 'measurement_event_id', 'meas_event_field_concept_id',
            ],

            'observation' => [
                'observation_id', 'person_id', 'observation_concept_id', 'observation_date',
                'observation_datetime', 'observation_type_concept_id', 'value_as_number',
                'value_as_string', 'value_as_concept_id', 'qualifier_concept_id',
                'unit_concept_id', 'provider_id', 'visit_occurrence_id', 'visit_detail_id',
                'observation_source_value', 'observation_source_concept_id', 'unit_source_value',
                'qualifier_source_value', 'value_source_value', 'observation_event_id',
                'obs_event_field_concept_id',
            ],

            'device_exposure' => [
                'device_exposure_id', 'person_id', 'device_concept_id',
                'device_exposure_start_date', 'device_exposure_start_datetime',
                'device_exposure_end_date', 'device_exposure_end_datetime',
                'device_type_concept_id', 'unique_device_id', 'production_id',
                'quantity', 'provider_id', 'visit_occurrence_id', 'visit_detail_id',
                'device_source_value', 'device_source_concept_id',
                'unit_concept_id', 'unit_source_value', 'unit_source_concept_id',
            ],

            'specimen' => [
                'specimen_id', 'person_id', 'specimen_concept_id',
                'specimen_type_concept_id', 'specimen_date', 'specimen_datetime',
                'quantity', 'unit_concept_id', 'anatomic_site_concept_id',
                'disease_status_concept_id', 'specimen_source_id',
                'specimen_source_value', 'unit_source_value',
                'anatomic_site_source_value', 'disease_status_source_value',
            ],

            'death' => [
                'person_id', 'death_date', 'death_datetime', 'death_type_concept_id',
                'cause_concept_id', 'cause_source_value', 'cause_source_concept_id',
            ],

            'note' => [
                'note_id', 'person_id', 'note_date', 'note_datetime', 'note_type_concept_id',
                'note_class_concept_id', 'note_title', 'note_text', 'encoding_concept_id',
                'language_concept_id', 'provider_id', 'visit_occurrence_id', 'visit_detail_id',
                'note_source_value', 'note_event_id', 'note_event_field_concept_id',
            ],

            'provider' => [
                'provider_id', 'provider_name', 'npi', 'dea', 'specialty_concept_id',
                'care_site_id', 'year_of_birth', 'gender_concept_id', 'provider_source_value',
                'specialty_source_value', 'specialty_source_concept_id', 'gender_source_value',
                'gender_source_concept_id',
            ],

            'source_to_concept_map' => [
                'source_code', 'source_concept_id', 'source_vocabulary_id',
                'source_code_description', 'target_concept_id', 'target_vocabulary_id',
                'valid_start_date', 'valid_end_date', 'invalid_reason',
            ],

            'location' => [
                'location_id', 'address_1', 'address_2', 'city', 'state', 'zip', 'county',
                'location_source_value', 'country_concept_id', 'country_source_value',
                'latitude', 'longitude',
            ],

            'care_site' => [
                'care_site_id', 'care_site_name', 'place_of_service_concept_id', 'location_id',
                'care_site_source_value', 'place_of_service_source_value',
            ],

            'cdm_source' => [
                'cdm_source_name', 'cdm_source_abbreviation', 'cdm_holder', 'source_description',
                'source_documentation_reference', 'cdm_etl_reference', 'source_release_date',
                'cdm_release_date', 'cdm_version', 'cdm_version_concept_id', 'vocabulary_version',
            ],
        ];
    }

    public static function columns(string $table): array
    {
        $tables = self::tables();
        return $tables[$table] ?? [];
    }

    /** Returns an empty row (every column null) ready to be filled in. */
    public static function emptyRow(string $table): array
    {
        return array_fill_keys(self::columns($table), null);
    }

    /**
     * Maximum length of the text columns in the official DDL.
     *
     * CSV files do not enforce it, but loading them into a real CDM schema
     * does: a 200-character REDCap label in measurement_source_value makes the
     * INSERT fail. Values are truncated on write so the exported package can
     * always be loaded.
     */
    const TEXT_LIMITS = [
        'person_source_value' => 50, 'gender_source_value' => 50,
        'race_source_value' => 50, 'ethnicity_source_value' => 50,
        'condition_source_value' => 50, 'condition_status_source_value' => 50,
        'drug_source_value' => 50, 'route_source_value' => 50,
        'dose_unit_source_value' => 50, 'stop_reason' => 20, 'sig' => null,
        'procedure_source_value' => 50, 'modifier_source_value' => 50,
        'measurement_source_value' => 50, 'unit_source_value' => 50,
        'value_source_value' => 50, 'observation_source_value' => 50,
        'qualifier_source_value' => 50, 'visit_source_value' => 50,
        'admitted_from_source_value' => 50, 'discharged_to_source_value' => 50,
        'cause_source_value' => 50, 'value_as_string' => 60,
        'device_source_value' => 50, 'unique_device_id' => 255,
        'production_id' => 255, 'specimen_source_id' => 50,
        'specimen_source_value' => 50, 'anatomic_site_source_value' => 50,
        'disease_status_source_value' => 50,
        'cdm_source_name' => 255, 'cdm_source_abbreviation' => 25,
        'cdm_holder' => 255, 'source_documentation_reference' => 255,
        'cdm_etl_reference' => 255, 'cdm_version' => 10,
        'vocabulary_version' => 20,
        'note_title' => 250, 'note_source_value' => 50,
        'care_site_name' => 255, 'care_site_source_value' => 50,
        'place_of_service_source_value' => 50,
        'provider_name' => 255, 'provider_source_value' => 50, 'npi' => 20, 'dea' => 20,
        'specialty_source_value' => 50,
        'source_code' => 50, 'source_vocabulary_id' => 20, 'source_code_description' => 255,
        'target_vocabulary_id' => 20, 'lot_number' => 50, 'measurement_time' => 10,
    ];

    /**
     * NOT NULL columns in the official DDL, for the 17 tables of the model.
     *
     * A row with any of these empty cannot be loaded into a real CDM schema,
     * even though the CSV is written without problems. Checking it before the
     * export avoids discovering it halfway through the load.
     */
    const REQUIRED = [
        'person' => ['person_id', 'gender_concept_id', 'year_of_birth',
                     'race_concept_id', 'ethnicity_concept_id'],
        'observation_period' => ['observation_period_id', 'person_id',
                                 'observation_period_start_date',
                                 'observation_period_end_date', 'period_type_concept_id'],
        'visit_occurrence' => ['visit_occurrence_id', 'person_id', 'visit_concept_id',
                               'visit_start_date', 'visit_end_date', 'visit_type_concept_id'],
        'condition_occurrence' => ['condition_occurrence_id', 'person_id',
                                   'condition_concept_id', 'condition_start_date',
                                   'condition_type_concept_id'],
        'drug_exposure' => ['drug_exposure_id', 'person_id', 'drug_concept_id',
                            'drug_exposure_start_date', 'drug_exposure_end_date',
                            'drug_type_concept_id'],
        'procedure_occurrence' => ['procedure_occurrence_id', 'person_id',
                                   'procedure_concept_id', 'procedure_date',
                                   'procedure_type_concept_id'],
        'measurement' => ['measurement_id', 'person_id', 'measurement_concept_id',
                          'measurement_date', 'measurement_type_concept_id'],
        'observation' => ['observation_id', 'person_id', 'observation_concept_id',
                          'observation_date', 'observation_type_concept_id'],
        'device_exposure' => ['device_exposure_id', 'person_id', 'device_concept_id',
                              'device_exposure_start_date', 'device_type_concept_id'],
        'specimen' => ['specimen_id', 'person_id', 'specimen_concept_id',
                       'specimen_type_concept_id', 'specimen_date'],
        'death' => ['person_id', 'death_date'],
        'note' => ['note_id', 'person_id', 'note_date', 'note_type_concept_id',
                   'note_class_concept_id', 'note_text', 'encoding_concept_id', 'language_concept_id'],
        'location' => ['location_id'],
        'care_site' => ['care_site_id'],
        'provider' => ['provider_id'],
        'source_to_concept_map' => ['source_code', 'source_concept_id', 'source_vocabulary_id',
                                    'target_concept_id', 'target_vocabulary_id',
                                    'valid_start_date', 'valid_end_date'],
        'cdm_source' => ['cdm_source_name', 'cdm_source_abbreviation', 'cdm_holder',
                         'source_release_date', 'cdm_release_date',
                         'cdm_version_concept_id', 'vocabulary_version'],
    ];

    /**
     * Finds which required columns are left empty.
     *
     * @return array<string,array<string,int>> table => column => number of rows
     */
    public static function missingRequired(array $tables): array
    {
        $out = [];
        foreach ($tables as $table => $rows) {
            foreach (self::REQUIRED[$table] ?? [] as $col) {
                $n = 0;
                foreach ($rows as $row) {
                    $v = $row[$col] ?? null;
                    if ($v === null || $v === '') {
                        $n++;
                    }
                }
                if ($n > 0) {
                    $out[$table][$col] = $n;
                }
            }
        }
        return $out;
    }

    /** Truncates a value to the limit of its column, if it has one. */
    public static function fit(string $column, $value)
    {
        if ($value === null || $value === '') {
            return $value;
        }
        $limit = self::TEXT_LIMITS[$column] ?? null;
        if ($limit === null) {
            return $value;
        }
        $text = (string) $value;
        return function_exists('mb_substr')
            ? mb_substr($text, 0, $limit)
            : substr($text, 0, $limit);
    }

    public static function tableForDomain(string $domain): ?string
    {
        return self::DOMAIN_TABLE[$domain] ?? null;
    }
}
