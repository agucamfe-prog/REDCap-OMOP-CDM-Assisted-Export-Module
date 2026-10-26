# Spec 013 — Stable identifiers

| | |
|---|---|
| **Status** | Implemented |
| **Depends on** | 009 |
| **Tests** | `tests/specs/013_stable_identifiers.test.php` |

## Context

The transformation (specs 009 and 010) produces rows with identifiers:
`person_id`, `visit_occurrence_id`, `care_site_id`, `provider_id` and the
identifier of each fact. A CDM is not loaded once: it is exported again as the
study progresses, loaded incrementally, linked to other sources by
`person_id`, and specific cases are cited («patient 41 of the March export»).
None of that works if the numbers depend on the order in which records appear:
deleting a record would renumber all the following ones.

This spec sets two mechanisms. **Entities** (persons, visits, care sites,
providers) receive a number from a persistent registry stored in the project
settings: the next free one the first time, the same one forever, and never
reused. **Facts**, which can number hundreds of thousands, are not stored:
their identifier is derived from what they describe with a 31-bit hash, and
collisions are resolved deterministically. The result: two exports of the same
data are byte-for-byte identical, and the preview does not consume numbers.

## User stories

- **US-013-1.** As the CDM manager, I want a person to have the same
  `person_id` in every export, so that I can do incremental loads and link with
  other sources.
- **US-013-2.** As the data steward, I want deleting a record neither to
  renumber the others nor to pass its number on to another person, so that a
  reference to an old `person_id` does not point to the wrong person.
- **US-013-3.** As an analyst, I want two exports of the same data to be
  identical, so that I can compare versions with a `diff` and detect real
  changes.
- **US-013-4.** As a curator, I want to preview the export as many times as
  needed without using up identifiers.

## Functional requirements

- **FR-013-01** — `person_id` MUST come from a persistent registry: the first time a Record ID appears it receives the next free number of its kind (starting at 1) and keeps it in every subsequent export.
- **FR-013-02** — An assigned number MUST NOT be reused or changed: deleting a record does not renumber the others, and a new record receives the next number, even if there are gaps.
- **FR-013-03** — `visit_occurrence_id`, `care_site_id` and `provider_id` MUST come from the same registry, with an independent counter per kind and the keys `record|event|instance` (visit) and `field=code` (care site and provider).
- **FR-013-04** — The registry MUST be stored in the project setting `omop-id-registry` as JSON with `ids` (kind → key → number), `next` (kind → next number) and `updated`; it is also accepted already decoded as an array, and an unreadable value is treated as an empty registry.
- **FR-013-05** — The registry MUST be written only when `save()` is called and only if some new number was assigned; an export with no new entities does not modify the setting.
- **FR-013-06** — In the preview (`dryRun`) the system MUST assign provisional numbers without saving them, so that previewing does not consume numbers and the next export assigns the same ones.
- **FR-013-07** — The identifier of each fact MUST be derived from its key `table|record|event|form|instance|field|option`: with no collision, it is `hash31(key)`.
- **FR-013-08** — `hash31` MUST return the low 31 bits of the first 8 hexadecimal characters of the key's SHA-256, as an integer between 1 and 2,147,483,647 (0 becomes 1), so that it fits in the DDL's `integer`.
- **FR-013-09** — Collisions MUST be resolved by walking the keys in order (`strcmp`): the smallest keeps its hash and the next one takes the next free number (linear probing, which wraps back to 1 after 2,147,483,647); the result does not depend on the order of the rows.
- **FR-013-10** — The internal `__key` key MUST NOT appear in any exported row.
- **FR-013-11** — `observation_period_id` MUST be the `person_id`: there is one period per person, so it is stable without being registered.
- **FR-013-12** — Fact identifiers MUST be the same in two exports of the same data, and must not change when other records are added or deleted.
- **FR-013-13** — Two exports of the same data MUST produce byte-for-byte identical table CSVs.
- **FR-013-14** — The registry MUST offer `has(kind, key)`, which asks without creating, and `counts()`, the number of entities per kind for the report.

## Non-functional requirements

- **NFR-013-01** — The registry MUST store only entities, never facts: its size grows with persons and visits, not with the number of rows.
- **NFR-013-02** — Assigning fact identifiers MUST be O(n log n) (one sort of the keys) and without database access.

## Acceptance criteria

```gherkin
Scenario: a number is not reused
  Given an export with records 1, 2 and 3 (person_id 1, 2 and 3)
  And a new record 4 and record 2 deleted
  When it is exported again
  Then records 1 and 3 keep person_id 1 and 3
  And record 4 receives person_id 4 and no person has 2

Scenario: the preview does not consume numbers
  Given the registry saved after an export
  And a new record
  When the export is previewed
  Then the omop-id-registry setting does not change
  And the next export gives the new record the number it had in the preview

Scenario: hash collision
  Given the keys «measurement|58494» and «measurement|71308», with the same hash31 (889893814)
  When the identifiers are assigned in any order
  Then «measurement|58494» receives 889893814 and «measurement|71308» receives 889893815

Scenario: identical exports
  Given the curated test project
  When it is exported twice without changing the data
  Then the table CSVs of the two ZIPs are byte-for-byte identical
```

## Out of scope

- Merging or reassigning identifiers by hand.
- Global identifiers across projects: each project has its own registry.
- Rebuilding the registry if the module settings are deleted: numbering starts
  again at 1 (it is the only way to reuse numbers, and it requires an explicit
  action by an administrator).
- Detecting that a fact «changed» value: with the same key it keeps its id.
- Simultaneous exports of the same project: the registry is not locked. Two
  exports at the same time may read the same «next number» and the last one to
  save wins; this is an accepted risk, documented in the plan.

## Manual verification

No functional requirement is `[manual]`. Recommended check on a real project:
export twice, unzip and run `diff -r` on the CSVs (only `INFORME.txt`, which
carries the time, and `CDM_SOURCE.csv` if the two exports are from different
days, should differ).

## Amendments

| Date | Requirement | Change | Prompted by |
|---|---|---|---|
| — | — | Initial version | — |
