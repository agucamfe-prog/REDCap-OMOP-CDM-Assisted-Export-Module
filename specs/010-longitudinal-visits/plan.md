# Plan 010 — Longitudinal projects, visits and entities

## Technical summary

`Transformer::readBatch()` already turns each record into a list of rows with
`__event`, `__instance`, `__form` and `__repeat_event` (spec 009). This spec
adds three things on top of that list: a **visit key** per row
(`record|event|instance`), a table of **occasions** with their date, site and
practitioner, and an accumulator of **visits** that is fed every time a fact
is emitted. At the end, `buildVisits()` creates `VISIT_OCCURRENCE` and replaces,
in each fact, the provisional key with the final identifier, only if the fact's
date falls within the visit.

`CARE_SITE` and `PROVIDER` come from the choice fields declared in the
configuration, with one identifier per code used.

## Files

| File | Action | Responsibility |
|---|---|---|
| `classes/Transformer.php` | Modify | Occasions, visits, fact linking, entities |
| `OmopCdmExportModule.php` | Modify | `visitConfig()`, `entityConfig()`; uses `getProjectStructure()` (spec 003) |
| `lang/Spanish.ini`, `lang/English.ini` | Modify | `skip_visit_domain_conflict`, `rep_visits`, `rep_visits_note`, `rep_outside_visit` |
| `classes/CsvExporter.php` | Modify | Visits section of the report |
| `tests/specs/010_longitudinal_visits.test.php` | Create | Tests for the spec |

## Design

### Project structure

It is provided by `getProjectStructure()` (spec 003); this spec reads `events`
for the visit name. In a longitudinal project:

```php
public function getProjectStructure(): array
// ['record_id_field' => 'record_id', 'longitudinal' => true,
//  'events' => [10 => ['event_id' => 10, 'unique_name' => 'baseline_arm_1',
//                      'descrip' => 'Baseline', 'arm_num' => 1, 'day_offset' => 0], ...],
//  'repeating' => [['event_id' => 10, 'form' => 'meds'], ['event_id' => 30, 'form' => '']],
//  'forms' => ['base', 'meds'], ...]
```

`RepeatingFormsEvents[event] === 'WHOLE'` (not an array) means a whole
repeating event → `form = ''`. The unique name is requested from
`REDCap::getEventNames(true, true)` and, if it does not provide one, from
`Project::$uniqueEventNames`.

### Configuration

```php
public function visitConfig(): array   // ['enabled' => generate-visits && visit-concept-id > 0, 'concept_id' => 9202]
public function entityConfig(): array  // ['care_site_field' => 'site', 'provider_field' => 'doctor']
```

### Data format of a longitudinal record

```php
'L1' => [
  10 => ['visit_date' => '2024-01-10', 'weight' => '70', 'site' => '1', ...],   // main row of event 10
  20 => ['visit_date' => '2024-03-10', ...],
  'repeat_instances' => [
     10 => ['meds' => [1 => ['med_start' => '2024-01-11', 'drug' => '1'], 2 => [...]]],  // repeating instrument
     30 => ['' => [1 => ['visit_date' => '2024-04-10', ...], 2 => [...]]],              // whole repeating event
  ],
]
```

### Keys and internal structures

```php
private function visitKey(array $row): string
// 'L1|10|0'  main row or repeating instrument of event 10
// 'L1|30|2'  instance 2 of repeating event 30

private $eventRows = [];   // event => main row of the current record (for dateInOccasion)
private $occasions = [];   // 'L1|10|0' => ['person_id' => 1, 'anchor' => '2024-01-10', 'care_site' => 1, 'provider' => 1]
private $visits = [];      // 'L1|10|0' => ['person_id' => 1, 'event' => '10', 'instance' => null,
                           //               'min' => '2024-01-10', 'max' => '2024-01-11']
```

### Flow

1. Per record: `eventRows` (rows with `__form === null` that are not a
   repeating event) and `collectOccasions()` (rows with `__form === null`: the
   main row or an instance of a whole event). The occasion date (`anchor`) is
   the default date of that row, already passed through
   `Pseudonymizer::shift()`.
2. Per row: `currentVisitKey = visitKey($row)`; every emitted fact carries
   `visit_occurrence_id = currentVisitKey` (provisional) and
   `provider_id = occasions[key]['provider']`, and calls `trackVisit($date)`.
3. `trackVisit()`: creates the visit with `min = anchor ?? date` and
   `max = max(anchor, date)`; afterwards it only lowers `min` if there is no
   `anchor`, and raises `max` with each later date.
4. `emitDeath()` does not call `trackVisit()` (FR-010-09).
5. `purgeDroppedPersons()` also deletes the accumulated visits of dropped
   persons.
6. `buildVisits()`: per visit, `registry->id('visit', $key)`, the row with
   `visit_source_value = unique_name` (+ `#instance`), and the site and
   practitioner of the occasion. Then it walks the tables in `DATE_COLUMN`: if
   the date is within `[min, max]` it sets the id; otherwise `null` and
   `facts_outside_visit++`. Without visits enabled, `stripVisitKeys()` sets
   `null` everywhere.

### Entities

```php
private function entityId(string $kind, ?string $field, array $row): ?int
// radio/dropdown only; records the code in $entitiesUsed[$kind][$code] = label
// and returns $this->registry->id($kind, "$field=$code")
private function buildEntities(): void
// CARE_SITE: care_site_id, care_site_name = label, care_site_source_value = code
// PROVIDER:  provider_id, provider_source_value = code, provider_name = null
```

`PERSON` uses `entityId()` on the flattened data (first value of the record);
each occasion uses it on its own row.

### Visit field

In `approvedFields()`, with `visitConf['enabled']`, a Visit-domain field is
dropped with `skip('visit_domain_conflict', $field)`. Without automatic visits,
`emit()` has a `visit_occurrence` case with
`registry->id('visit_field', rowKey|field)` and its own date.

## Decisions and rejected alternatives

- **The occasion is the event, not the instrument instance.** Repeating
  medication is collected *at* a visit. *Rejected:* one visit per instance,
  which multiplied visits without there being more contacts.
- **Linking is decided at the end.** The visit's range is not known until all
  its facts have been emitted. *Rejected:* linking on emission, which left
  facts outside the final visit.
- **An earlier fact does not stretch the visit.** A diagnosis from months ago
  collected at baseline did not happen at baseline. *Rejected:* a min–max range
  over all facts, which turned the baseline into a months-long visit.
- **No concept, no visits.** *Rejected:* `visit_concept_id = 0`, which adds
  nothing and pollutes the CDM.
- **An instance's date comes only from its own event.** *Rejected:* looking in
  any event of the record (the flattened data), which dated month-2
  medication with the baseline.
- **Only choice fields for entities; for the practitioner, only the code.**
  *Rejected:* free text, which yields one site per way of spelling it, and the
  practitioner's name, which is personal data.

## Testing strategy

`tests/specs/010_longitudinal_visits.test.php`:

- Classic fixture project: CARE_SITE, one visit per record, linked medication,
  dropped person without a visit.
- Longitudinal project built inside the file: `\Project::$conf` with
  `longitudinal`, `eventInfo` (events 10, 20, 30, 40 of arm 1 and 50 of arm 2),
  `uniqueEventNames`, `RepeatingFormsEvents` (`meds` in 10 and 20, `'WHOLE'` in
  30); `\REDCap::$data` with three records (`L1` with every case, `L2` in arm
  2, `L3` with a baseline without a date). Fields added to the dictionary:
  `doctor` (practitioner), `tb_dx_date` (earlier fact) and `clinic_txt`
  (free-text site). Event ids 10–50 are not returned by the
  `REDCap::getEventNames` stub, so `uniqueEventNames` is used.
- Variants copying `$m->settings` into a new module: without
  `visit-concept-id`, `generate-visits = false`, an approved Visit field.

## Constitution check

- **II.8 No row without a date**: FR-010-10 (no event date, dropped).
- **II.9 Every drop is counted**: `facts_outside_visit`, `visit_domain_conflict`.
- **II.10 A wrong concept is worse than an empty one**: FR-010-05.
- **III.13 Personal data**: FR-010-13 (no practitioner name).

## Risks

- **Projects with visits without a default date.** The range comes from the
  facts; if they have no date either, there is no visit (and the facts are
  dropped, spec 009).
- **A single visit type per project.** A study with admissions and outpatient
  visits will need an amendment for a per-event type.
- **Linking uses only the fact's start date.** A long exposure may end after
  its visit; that is expected in OMOP.
