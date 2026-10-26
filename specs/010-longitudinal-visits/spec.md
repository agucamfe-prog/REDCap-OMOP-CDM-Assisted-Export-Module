# Spec 010 — Longitudinal projects, visits and entities

| | |
|---|---|
| **Status** | Implemented |
| **Depends on** | 009 |
| **Tests** | `tests/specs/010_longitudinal_visits.test.php` |

## Context

Spec 009 turns the data of a classic project into CDM rows. Clinical research
projects, however, are usually longitudinal: a baseline visit, follow-ups at
month 2 and month 6, a follow-up that repeats as many times as needed, several
arms. In OMOP that structure is not decorative: `VISIT_OCCURRENCE` groups the
facts of each contact with the patient, and the OHDSI tools (and the Data
Quality Dashboard) check that every fact linked to a visit falls within its
dates.

This spec defines the unit that governs all of that, the **collection
occasion** (an event, or each instance when the whole event repeats), how each
row is dated within its occasion, when and how each visit is generated, and
which facts are linked to it and which are not. It also adds the two
healthcare-system entities that REDCap usually captures as choices: the site
(`CARE_SITE`) and the practitioner (`PROVIDER`).

Visit, site and practitioner identifiers are stable across exports (spec 013);
here it is enough for them to be unique.

## User stories

- **US-010-1.** As the data manager of a longitudinal study, I want each event
  to generate its visit with its facts, so that I can analyse how each patient
  evolves visit by visit.
- **US-010-2.** As an analyst, I want a fact linked to a visit to have really
  happened in it, so that the Data Quality Dashboard does not flag the CDM and
  visit-based cohorts are correct.
- **US-010-3.** As a curator, I want the medication from a repeating instrument
  to inherit the date of its own visit and never that of another, so that
  month-2 medication is not dated with the baseline visit.
- **US-010-4.** As a researcher in a multicentre study, I want the site and the
  practitioner to reach `CARE_SITE` and `PROVIDER`, so that I can stratify by
  site without exporting practitioners' names.

## Functional requirements

- **FR-010-01** — The system MUST read as collection rows each event of each arm, each instance of a repeating instrument (`repeat_instances[event][form][instance]`) and each instance of a whole repeating event (empty form name in `repeat_instances`).
- **FR-010-02** — The collection occasion MUST be the event or, if the whole event repeats, each of its instances; a repeating instrument inside an event belongs to that event's occasion and does not create one of its own.
- **FR-010-03** — The same field collected on several occasions MUST generate one row per occasion, each with the date of its occasion.
- **FR-010-04** — With visit generation enabled and a visit type concept set, the system MUST generate one `VISIT_OCCURRENCE` per occasion with at least one fact, with `visit_concept_id` = the configured one, `visit_type_concept_id` = 32809 and `visit_source_value` = the event's unique name (`event_<id>` if there is none) followed by `#instance` for a repeating event; dropped persons (FR-009-04) leave no visits.
- **FR-010-05** — Without a visit type concept, or with generation disabled, the system MUST NOT generate visits, and no fact carries a `visit_occurrence_id`.
- **FR-010-06** — The visit MUST start on the default date of its occasion (read from the event's main row) and extend to the last later fact of that occasion; if the occasion has no default date, it spans from the first to the last fact of the occasion.
- **FR-010-07** — A fact of the occasion dated before the start of its visit MUST NOT stretch it backwards: it is left without `visit_occurrence_id` and counted in `stats[facts_outside_visit]`.
- **FR-010-08** — No fact MUST be linked to another person's visit, nor to a visit whose dates its start date falls outside of.
- **FR-010-09** — `DEATH` rows MUST NOT create or extend visits.
- **FR-010-10** — A repeating instrument instance without its own reference date MUST take it from the main row of its same event, never from another; if its event does not have it, the fact is dropped as `no_date|field`.
- **FR-010-11** — With visit generation enabled, a field approved in the Visit domain MUST be dropped and counted as `visit_domain_conflict|field`; with generation disabled, it generates its own `VISIT_OCCURRENCE` with its concept and its date.
- **FR-010-12** — If a site field of type radio or dropdown is declared, the system MUST generate one `CARE_SITE` per code used (`care_site_name` = the choice label, `care_site_source_value` = code) and link it from `PERSON` (first value of the record) and from each `VISIT_OCCURRENCE` (value of its occasion); a free-text field does not generate sites.
- **FR-010-13** — If a practitioner field of type radio or dropdown is declared, the system MUST generate one `PROVIDER` per code used, with `provider_source_value` = code and an empty `provider_name`, and link it from `PERSON`, from each `VISIT_OCCURRENCE` and from the facts of the occasion, including those from repeating instruments.

## Non-functional requirements

- **NFR-010-01** — The project structure (events, unique names, arms, repeating instruments and events) MUST be obtained with `$module->getProjectStructure()`, which uses `REDCap::getEventNames()` and, if that gives no name, `Project::$uniqueEventNames`, and understands the `'WHOLE'` value of `RepeatingFormsEvents`.
- **NFR-010-02** — Visits MUST be built in a single pass at the end of the transformation, without reading data from REDCap again.

## Acceptance criteria

```gherkin
Scenario: repeating medication inside an event
  Given a longitudinal project with a repeating "meds" instrument in the baseline event
  And an instance of "meds" without a start date in the event "month2_arm_1"
  When the transformation runs
  Then its DRUG_EXPOSURE is dated with the date of "month2_arm_1"
  And it is linked to the visit of "month2_arm_1"

Scenario: a visit that extends
  Given the baseline occasion dated 2024-01-10 and a medication dated 2024-01-11
  When the transformation runs
  Then the baseline visit runs from 2024-01-10 to 2024-01-11

Scenario: a fact before the visit
  Given a TB diagnosis dated 2023-12-15 collected in the baseline of 2024-01-10
  When the transformation runs
  Then the baseline visit starts on 2024-01-10
  And the diagnosis has no visit_occurrence_id
  And stats[facts_outside_visit] is 1

Scenario: death is not a visit
  Given a closure event with only the date of death and "Exitus = Sí"
  When the transformation runs
  Then there is one row in DEATH and no "closure_arm_1" visit

Scenario: no visit type
  Given an empty visit-concept-id
  When the transformation runs
  Then VISIT_OCCURRENCE is empty and no fact has a visit_occurrence_id
```

## Out of scope

- Keeping visit, site and practitioner identifiers across exports: spec 013.
- Shifting visit dates: spec 016 (visits use the already shifted dates).
- `VISIT_DETAIL`, `LOCATION` and `FACT_RELATIONSHIP`: they are not generated.
- Inferring the visit type per event: there is a single `visit_concept_id` per
  project.
- Sites or practitioners written as free text.

## Manual verification

No functional requirement is `[manual]`. With a real longitudinal project:
export, load with the load kit (spec 017) and check in the Data Quality
Dashboard that the check for facts within their visit reports no errors.

## Amendments

| Date | Requirement | Change | Motivated by |
|---|---|---|---|
| — | — | Initial version | — |
