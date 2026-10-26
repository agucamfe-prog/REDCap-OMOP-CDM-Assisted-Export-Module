# Spec 009 — Fact transformation

| | |
|---|---|
| **Status** | Implemented |
| **Depends on** | 002, 006 |
| **Tests** | `tests/specs/009_fact_transformation.test.php` |

## Context

With the previous specs the module already knows which tables and columns the
CDM v5.4 has (002) and stores, field by field, the mapping that a person has
proposed and approved (006). What is missing is what gives meaning to all of
that: converting REDCap records into CDM rows.

This spec covers the transformation of a **classic** project (a single event,
with repeating instruments): the `PERSON` table built from the configuration,
`OBSERVATION_PERIOD` and the fact tables of each domain. The rules are those of
the constitution taken down to the detail: only what is approved goes in,
absence is represented by absence, a choice code is not a number, no row goes
out without a date or without a person, and everything discarded is counted.

Visits, events and entities (`CARE_SITE`, `PROVIDER`) are spec 010; identifier
stability is 013; pseudonymization is 016. The output of this spec is the input
of the exporter (011).

## User stories

- **US-009-1.** As a curator, I want only what I have approved to reach the
  CDM, so that an automatic proposal never becomes data without anyone having
  reviewed it.
- **US-009-2.** As an OHDSI analyst, I want each answer to reach the table and
  column it belongs to (concept, value, unit, operator), so that I can build
  cohorts without cleaning the CDM by hand.
- **US-009-3.** As a principal investigator, I want a «No» not to generate a
  diagnosis and a «1.234» not to become a dubious number, so that the CDM does
  not say things the data do not say.
- **US-009-4.** As the person responsible for quality, I want to know how many
  rows were discarded and why, so that I can decide whether the export is
  usable.

## Functional requirements

- **FR-009-01** — The system MUST generate rows only from fields in the `approved` state; a field in any other state (including one with unapproved `@OMOP` annotations) produces none. If there is no approved field, the transformation returns `error = no_approved_fields` and no rows. The `Person`, `NotMapped` and `DateAnchor` domains never produce facts.
- **FR-009-02** — The system MUST generate one `PERSON` row per record with `person_id`, `person_source_value` and, from the configured birth date field, `year_of_birth`, `month_of_birth`, `day_of_birth` and `birth_datetime`; if there is no full date, only `year_of_birth` from the configured year field.
- **FR-009-03** — `gender_concept_id`, `race_concept_id` and `ethnicity_concept_id` MUST take the concept of the chosen option only if the field declared in the configuration is approved; if it is not, the concept is 0, the option label goes to `*_source_value` and `stats[gender_not_approved]` (or `race_…`, `ethnicity_…`) is set. With no declared field, the concept is 0.
- **FR-009-04** — A person without a year of birth MUST be discarded together with all of their facts, and the discard is counted in `stats`: `persons_dropped`, `rows_dropped_with` (rows dragged along) and `dropped_record_ids` (up to 20 Record IDs).
- **FR-009-05** — The system MUST generate one `OBSERVATION_PERIOD` per person with at least one date, from the first to the last date of their facts (start and end dates, including the date of death), so that no fact starts or ends outside its person's period, with `observation_period_id` = `person_id` and `period_type_concept_id` = 32817; persons without any date are counted in `stats[persons_without_period]`.
- **FR-009-06** — Each approved domain MUST go to its table (Condition → `CONDITION_OCCURRENCE`, Drug → `DRUG_EXPOSURE`, Procedure → `PROCEDURE_OCCURRENCE`, Measurement → `MEASUREMENT`, Observation → `OBSERVATION`, Device → `DEVICE_EXPOSURE`, Specimen → `SPECIMEN`, Note → `NOTE`, Death → `DEATH`) with `*_type_concept_id` = 32809 and the label in `*_source_value`: the option's label, or the question's label if the option is a yes/no. `SOURCE_TO_CONCEPT_MAP` is only generated with a vocabulary.
- **FR-009-07** — The system MUST apply the approved value strategy: `per_choice` (each option is the fact, with its concept), `value_concept` (the question's concept and the option in `value_as_concept_id`) and `single_concept` (one concept for the field and the option label as text, or its number if the options are a scale). A choice code never reaches `value_as_number`, and a yes/no approved as a single concept is always treated per option.
- **FR-009-08** — Each option MUST respect its «generates a row» flag: an option that does not emit produces no row and is counted in `stats[not_emitted]`, not as a discard; a value with no saved decision is not invented: it is discarded and counted as `choice_undecided|field=value`.
- **FR-009-09** — Checkboxes MUST be read in both formats (a nested array under the field name and `field___option` columns), generate one row per checked option that emits, and not count a row with no checked box as a discard.
- **FR-009-10** — A value MUST be converted to `value_as_number` only if it cannot be read two ways: always with REDCap numeric validation; in free text, «95» or «95.5» yes, «1.234» (`ambiguous_number`) and «95,5» (`not_numeric`) no, and in both cases the original stays in `value_source_value`. In `MEASUREMENT`, a value with an operator (`<`, `<=`, `=`, `>=`, `>`) stores the operator in `operator_concept_id` and the number in `value_as_number`, and is counted in `stats[with_operator]`.
- **FR-009-11** — The approved unit MUST be translated to its UCUM code and from there to `unit_concept_id` (with the vocabulary or, without it, with the short table of safe units: kg, cm, mm[Hg], %, g/dL, mg/dL, mmol/L, µmol/L); an unrecognised unit keeps `unit_source_value` and leaves `unit_concept_id` empty, and a row without a number carries no unit.
- **FR-009-12** — Each fact MUST be dated with the mapping's own date field or, if it has none, with the project's default date; a date field mapped as a fact is dated with its own value. A fact without a date generates no row and is counted as `no_date|field`: no row of any clinical table goes out without a date.
- **FR-009-13** — If the mapping declares an end date field, the system MUST carry it to `*_end_date`; an end date before the start is discarded (in `DRUG_EXPOSURE` the mandatory end is set equal to the start and `verbatim_end_date` stays empty) and counted in `stats[end_before_start]`. Without an end field none is invented, except the mandatory end of `DRUG_EXPOSURE`.
- **FR-009-14** — The system MUST generate a single `DEATH` row per person, dated with the configured death date field (it is enough for it to have a value, even if no Death field is approved); the approved concept is the cause (`cause_concept_id`), and `cause_source_value` carries the option label only when that option has a cause concept and is not a yes/no («Yes» is not a cause); a free-text cause goes to `cause_source_value` without a concept, and with neither concept nor text the cause stays empty.
- **FR-009-15** — A text field approved in the Note domain MUST generate a `NOTE` row with the full text, untrimmed, `note_class_concept_id` = the field's concept, `note_title` = label, `note_source_value` = field name, `encoding_concept_id` = 32678 (UTF-8) and `language_concept_id` = the configured one or, otherwise, that of the module language (4180186 English, 4182511 Spanish).

## Non-functional requirements

- **NFR-009-01** — Records MUST be processed in batches of 200 (`Transformer::BATCH_SIZE`), first requesting the identifiers and then each batch from `REDCap::getData`, so that memory does not grow with the size of the project; the preview processes only the requested records.
- **NFR-009-02** — The result MUST include in `missing` the empty NOT NULL columns per table, computed with `CdmModel::missingRequired()` over the generated rows.
- **NFR-009-03** — The result MUST always have the same shape: `tables`, `counts`, `stats` (with `records` and `rows`), `skipped` (keys `code|argument`, language-independent) and `missing`.
- **NFR-009-04** — The configuration (PERSON and DEATH fields, default date, visits, entities) MUST be read only once when the `Transformer` is constructed, never inside the record loop.

## Acceptance criteria

```gherkin
Scenario: only what is approved
  Given the test project with «hr» proposed by its @OMOP annotations
  When the transformation runs
  Then MEASUREMENT has no row with concept 3027018

Scenario: the «No» is not a diagnosis
  Given «fever» approved per option, with «Sí» → 437663 and «No» without a row
  And records 1 and 3 with «Sí» and record 2 with «No»
  When the transformation runs
  Then CONDITION_OCCURRENCE has 2 fever rows
  And stats[not_emitted] is 2 and skipped is empty

Scenario: ambiguous number
  Given «glu» as free text with the value «1.234» in record 2
  When the transformation runs
  Then its MEASUREMENT has an empty value_as_number and value_source_value «1.234»
  And stats[ambiguous_number] is 1

Scenario: person without a birth date
  Given record 3 without a birth date
  When the transformation runs
  Then PERSON has 2 rows and no row of any table has person_id 3
  And stats[persons_dropped] is 1 and stats[dropped_record_ids] is «3»

Scenario: end before start
  Given a rifampicin that starts on 2024-03-05 and ends on 2024-03-01
  When the transformation runs
  Then drug_exposure_end_date is 2024-03-05 and verbatim_end_date is empty
  And stats[end_before_start] is 1
```

## Out of scope

- Events, visits, `CARE_SITE` and `PROVIDER`: spec 010.
- Stability of `person_id` and of fact identifiers: spec 013.
- Date shifting, birth date precision and free-text suppression: spec 016.
- Writing the CSVs, the ZIP and the report: spec 011.
- Validating concepts against the vocabulary and building `SOURCE_TO_CONCEPT_MAP`: spec 014.
- Coding free text: a text field in a domain other than Note goes out as text; it is not interpreted.

## Manual verification

No functional requirement is `[manual]`. For NFR-009-01 with a real project:
export a project with more than 200 records and check in the report that the
number of processed records matches that of the project.

## Amendments

| Date | Requirement | Change | Prompted by |
|---|---|---|---|
| — | — | Initial version | — |
