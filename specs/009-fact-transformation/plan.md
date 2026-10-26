# Plan 009 — Fact transformation

## Technical summary

A single class, `Transformer`, walks through the project's records in batches,
and for each record builds its `PERSON` row from the configuration and, for
each collection row (the main record and each instance of a repeating
instrument), applies its mapping to every approved field. The approved mapping
comes from `MappingStore` (spec 006) through `$module->mappingRows()`; the
columns of each table and which of them are mandatory come from `CdmModel`
(spec 002).

Everything that produces no row leaves a trace: `skipped` (discards, with a
`code|argument` key translatable with `$module->skipLabel()`) and `stats`
(counters of decisions and warnings). At the end the observation periods are
computed and the NOT NULL columns are checked. The result is an array consumed
by the exporter (spec 011).

## Files

| File | Action | Responsibility |
|---|---|---|
| `classes/Transformer.php` | Create | REDCap records → CDM rows |
| `classes/CdmModel.php` | Use | `emptyRow()`, `tableForDomain()`, `missingRequired()`, `OPERATORS`, `UCUM_BUILTIN`, `ucumCode()` (spec 002) |
| `OmopCdmExportModule.php` | Modify | `personConfig()`, `deathConfig()`, `defaultDateField()`, `unitConcept()` (short table; spec 014 adds the vocabulary), `noteLanguageConcept()`, `isApproved()`. The `transformer()` factory belongs to spec 001 |
| `lang/Spanish.ini`, `lang/English.ini` | Modify | `skip_*` keys for each discard reason (translated by `skipLabel()`, spec 011) |
| `tests/specs/009_fact_transformation.test.php` | Create | Tests for the spec |

## Design

### Input and output

```php
// OmopCdmExportModule (factory from spec 001)
public function transformer(): Transformer
// Transformer
public function __construct($module)
public function run(?int $limit = null, bool $dryRun = false): array
```

`run()` returns:

```php
[
  'tables'  => ['person' => [...], 'measurement' => [...], ...], // all of CdmModel::tables(), empty ones included
  'counts'  => ['person' => 3, 'measurement' => 9, ...],          // only the non-empty ones
  'stats'   => ['records' => 3, 'rows' => 29, 'batches' => 1, 'not_emitted' => 2,
                'ambiguous_number' => 1, 'with_operator' => 1, 'end_before_start' => 1, ...],
  'skipped' => ['no_date|weight' => 1, 'choice_undecided|drug=9' => 1],
  'missing' => [],                                                 // CdmModel::missingRequired($tables)
  // 'error' => 'no_approved_fields'                               // only if nothing is approved
]
```

### Configuration read in the constructor

`personConfig()` → `gender_field`, `birth_date_field`, `birth_year_field`,
`race_field`, `ethnicity_field` (settings `gender-field`, `birth-date-field`…);
`deathConfig()` → `date_field` (`death-date-field`); `defaultDateField()`
(`default-date-field`). They are stored in properties: the loop does not query
settings (NFR-009-04).

### Flow of `run()`

1. `approvedFields()`: from `mappingRows()`, only `state === approved` and a
   domain other than `Person`, `NotMapped` and `DateAnchor`. Each spec is
   enriched with `_choices` (profile options), `_meta` (dictionary row),
   `_values` (per-option decisions), `_label`, `_kind` (the profile's
   `value_kind`) and `_unit_concept` (`$module->unitConcept(unit_source_value)`).
2. `recordIds($limit)`: `REDCap::getData` with only the Record ID field.
3. `array_chunk($ids, 200)` and, per batch, `readBatch()` → per record, a list
   of rows; each row carries `__event`, `__instance`, `__form` and
   `__repeat_event`. A repeating instrument arrives in
   `repeat_instances[event][form][instance]`.
4. Per record: `flatten()` (first non-empty value of each field, for what is
   not repeated: birth, sex, death), `buildPerson()`, and for each row and
   approved field `transformField()`. Checkboxes do not exist as a column, so
   they are processed even when `array_key_exists` fails.
5. `buildDeathFromConfig()`, `purgeDroppedPersons()`, `buildObservationPeriods()`,
   `buildCdmSource()`.

### Rules for a field (`transformField`)

```
checkbox → transformCheckbox()
empty → nothing
with options and a per-option strategy (or a yes/no binary) →
    optionSpec() null      → skip choice_undecided|field=value
    emit = false           → stats[not_emitted]++   (before looking at the date)
    no date                → skip no_date|field
    emitOption()           → per_choice | value_concept
no date → skip no_date|field
no concept (except Death) → skip field_no_concept|field
Note → emitNote()
with options (single_concept) → label as text; number if it is a scale
date field → no value (the date is the data)
rest → numericValue() → [number, operator]
```

`emit()` builds the row with `CdmModel::emptyRow($table)` and a `switch` per
table with its mandatory columns. Example of `MEASUREMENT` for «< 70»:

```php
['measurement_concept_id' => 3004501, 'measurement_date' => '2024-03-01',
 'measurement_type_concept_id' => 32809, 'value_as_number' => 70.0,
 'operator_concept_id' => 4171756, 'value_source_value' => '< 70',
 'unit_source_value' => 'mg/dL', 'unit_concept_id' => 8840,
 'measurement_source_value' => 'Glucose (mg/dL)']
```

### Dates

`realFactDate()`: in Death, the configured death date; in a date field without
`date_field`, its own value; otherwise, the mapping's `date_field` and then the
default date, looked up with `dateInOccasion()` (the row itself and, if it is a
repeating instrument, the main row of its event). `parseDate()` only accepts a
valid `Y-m-d` (`checkdate`). `endDate()` applies the same lookup to
`end_date_field` and discards an end before the start. Every emitted start and
end date (and the date of death) goes through `trackDate()`, which keeps the
minimum and maximum per person for `OBSERVATION_PERIOD`.

In DEATH, `cause_source_value` is the option label when it brings a cause
concept, unless it is binary (`DictionaryProfiler::isBinaryLabel()`): the
«Yes» of an «Exitus sí/no» names no cause.

### Numbers (`numericValue`)

- REDCap numeric validation (`integer`, `number`, `number_1dp`…, `slider`,
  `calc`) and `is_numeric` → number.
- Free text: `^-?\d+(\.\d+)?$` and not `^-?\d{1,3}\.\d{3}$` → number.
- In `measurement`, `^(<=|>=|<|>|=)\s*(\S+)$` with an unambiguous number →
  `[number, CdmModel::OPERATORS[op]]`.
- Otherwise, in `measurement` `ambiguous_number` (pattern `\d{1,3}\.\d{3}`) or
  `not_numeric` is counted.

### Units

`CdmModel::ucumCode('mg/dl')` → `mg/dL` (spec 002); `$module->unitConcept()`
translates the code with `CdmModel::UCUM_BUILTIN` (`kg` 9529, `cm`
8582, `mm[Hg]` 8876, `%` 8554, `g/dL` 8713, `mg/dL` 8840, `mmol/L` 8753,
`umol/L` 8749). Spec 014 puts the vocabulary lookup in front.

### Collaborators

The Transformer asks `$module->idRegistry($dryRun)` for identifiers and
`$module->pseudonymizer()` for dates. Their rules are those of specs 013 and
016; for this spec their neutral behaviour is enough: `id('person', $recordId)`
numbers in order of appearance and `shift($date, $recordId)` returns the date
as is. `hashPersonSourceValue()` returns the Record ID while hashing is not
enabled.

## Decisions and discarded alternatives

- **PERSON and DEATH from the configuration, not as facts.** They are
  one-row-per-person tables. *Discarded:* mapping sex as one more Person fact,
  because it would force reconciling several rows per person.
- **A person without a year of birth is discarded with their facts.**
  `year_of_birth` is NOT NULL and cannot be invented. *Discarded:* exporting
  them with a fictitious year, which falsifies age, or leaving their facts
  orphaned, which breaks referential integrity on load.
- **«Does not emit» is decided before the date.** A «No» without a date is not
  a discard. *Discarded:* requiring the date first, which filled the report
  with discards that were not discards.
- **Discards are stored as `code|argument`.** The language is applied when
  rendering (`skipLabel`). *Discarded:* storing already-translated text, which
  mixes logic and language and cannot be grouped.
- **Ambiguous text is not converted.** *Discarded:* guessing the decimal
  separator from the project language: a project in Spanish may have data
  copied from an English source.
- **Units with a short table.** *Discarded:* a long table of aliases to
  concepts: a wrong `unit_concept_id` is worse than an empty one.
- **A yes/no is always per option.** *Discarded:* honouring `single_concept`
  for binaries, which emitted a row for the «No» as well.

## Testing strategy

`tests/specs/009_fact_transformation.test.php`, with the test project from
`tests/lib/fixtures.php` (`make_module()`, `curate()`, `fixture_records()`):

- Base scenario (3 curated records): PERSON, operators, ambiguous number,
  checkboxes, «No» without a row, end date, NOTE, DEATH, periods.
- Dictionary extended inside the file (`xray`, `catheter`, `sputum_sample`,
  `smoking`, `pain`, `temp`, `crp`, `tb_dx_date`, `death_cause`) and approved
  with a helper closure: Procedure, Device, Specimen and Observation tables,
  `single_concept`, scale, unknown unit, «>=», «95,5», date as a fact.
- Variants with `reset_fixture()`: unapproved sex, record without a birth
  date, year field, `___` checkboxes, option without a decision, record without
  a date, cause of death with a concept, long note, 205 records (2 batches).
- There is no manual verification of functional requirements.

## Check against the constitution

- **I.1 Only what is approved is exported**: FR-009-01 and FR-009-03.
- **II.6 Absence by absence**: FR-009-08 and FR-009-09.
- **II.7 No interpretation**: FR-009-10 and FR-009-07 (the code is not a value).
- **II.8 No row without a date or a person**: FR-009-04 and FR-009-12.
- **II.9 Every discard is counted**: `skipped`, `stats`, NFR-009-03.
- **II.10 A wrong concept is worse than an empty one**: FR-009-11.
- **IV.15 CDM v5.4**: rows from `CdmModel::emptyRow()` and NFR-009-02.

## Risks

- **Projects with tens of thousands of records.** Mitigated with batches of
  200; the generated tables stay in memory until the ZIP is written.
- **Checkbox formats that change between REDCap versions.** Mitigated by
  reading both known formats.
- **Distant end dates.** A mistyped end (one year too many) stretches that
  person's observation period; this is accepted, because trimming it would
  leave the fact outside its period, and the Data Quality Dashboard (spec 017)
  flags anomalous durations.
