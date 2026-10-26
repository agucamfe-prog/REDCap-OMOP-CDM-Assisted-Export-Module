# Spec 016 — Pseudonymization

| | |
|---|---|
| **Status** | Implemented |
| **Depends on** | 009 |
| **Tests** | `tests/specs/016_pseudonymization.test.php` |

## Context

The transformation (spec 009) exports the data as it is in REDCap: the Record
ID in `person_source_value`, the real dates, the full date of birth and free
text. For many OHDSI networks and ethics committees that is not enough. The
Record ID is often a medical record number or a code that someone can link;
the date of birth together with the admission date and a postcode
re-identifies a large part of the population; a comments field may contain a
name, an address or anything else.

This spec adds four measures, all optional and off by default: hashing
`person_source_value`, shifting all of each person's dates by a number of days
of their own (which preserves ages and intervals), reducing the precision of
the date of birth, and suppressing free text. Everything applied is declared
in the report that travels with the export.

The result is a **pseudonymized** dataset, not an anonymous one: with the
project salt and the REDCap data it can be reversed, and shifted dates are
still personal data.

## User stories

- **US-016-1.** As the data protection officer, I want the CDM not to contain
  the Record ID, so that nobody can link it to the medical record.
- **US-016-2.** As a researcher, I want to shift dates without losing ages or
  intervals, so that I meet a network's requirements without invalidating
  survival or time-to-event analyses.
- **US-016-3.** As a data manager, I want to exclude free text from the CDM, so
  that names or addresses written in a comments field are not exported by
  mistake.
- **US-016-4.** As the recipient of the CDM, I want the report to say which
  pseudonymization was applied, so that I know what I can and cannot do with
  the dates.

## Functional requirements

- **FR-016-01** — With the `hash-person-source-value` setting, `person_source_value` MUST be the first 32 characters of the SHA-256 of `salt|Record ID`; without it, the Record ID as is. The hash does not depend on whether dates are shifted.
- **FR-016-02** — The salt MUST be a project secret: 32 random hexadecimal characters, created the first time they are needed (hash or shift) in the `person-hash-salt` setting and reused afterwards; the same salt feeds both the hash and the shift.
- **FR-016-03** — With `date-shift-days` = N > 0, each record MUST have an integer shift between −N and +N, never 0, derived from the SHA-256 of `salt|shift|Record ID`: the same in every export with the same salt, different between persons and different with another salt.
- **FR-016-04** — All of a person's dates MUST move by their shift: birth, start and end of facts, occasion date and therefore its visits, death and observation period; intervals within each person are preserved.
- **FR-016-05** — With N = 0 (or negative) nothing MUST be shifted (`shiftsDates()` is false and the shift is 0); with shifting active, a null date stays null and a text that is not a real `Y-m-d` date ("nope", "2024-13-01", "2024-02-30") returns `null`, never a normalized day.
- **FR-016-06** — `birth-precision` MUST be applied to PERSON after shifting the birth date: `full` leaves it complete, `month` empties `day_of_birth` and `birth_datetime`, `year` also empties `month_of_birth`; an unknown value is equivalent to `full`.
- **FR-016-07** — With `suppress-free-text`, an approved free-text field (`value_kind = text`) whose value is not an unambiguous number MUST emit its row without `value_as_string` or `value_source_value`, and only those rows are counted in `stats[free_text_suppressed]`; if the text was a number, the row keeps the number and the original and is not counted. In `MEASUREMENT`, a measurement that would be left without any value is not emitted: it is dropped as `free_text_no_value|field`, with its text in the report (`skip_free_text_no_value`).
- **FR-016-08** — With `suppress-free-text`, the Note domain MUST NOT generate `NOTE` rows, and each note not generated is counted in `stats[notes_suppressed]`.
- **FR-016-09** — With `suppress-free-text`, a free-text cause of death MUST NOT reach `cause_source_value`; the `DEATH` row is generated anyway.
- **FR-016-10** — The export report MUST declare, in the "Pseudonymisation applied" section (`rep_privacy_applied`), the date shift (with its N), the birth precision if it is not full, the number of free texts not exported and the hashing of `person_source_value`; if nothing was applied, the section does not appear. The transformation sets `stats[date_shift] = 1` when it shifts.
- **FR-016-11** — Without any of the settings, the export MUST be that of spec 009: Record ID, real dates, full birth date and `NOTE`, and no salt is created.
- **FR-016-12** — A standalone year of birth (a year field, with no full date) MUST NOT be shifted: it is exported as is, without month or day. Shifting days makes no sense on a value that only states the year.

## Non-functional requirements

- **NFR-016-01** — Pseudonymization MUST be deterministic and stateless beyond the salt: two exports with the same salt produce the same dates and the same hashes.
- **NFR-016-02** — Each record's shift MUST be computed once per export and reused (cached by Record ID).
- **NFR-016-03** — No text of the module MUST describe the result as anonymous.

## Acceptance criteria

```gherkin
Scenario: shifted dates preserving intervals
  Given date-shift-days = 30
  And record 1 with a weight dated 2024-03-01 and an isoniazid dated 2024-03-02
  When the project is exported
  Then the weight is not dated 2024-03-01 and is at most 30 days away
  And the isoniazid is dated exactly one day after the weight

Scenario: birth with the year only
  Given birth-precision = year
  When the project is exported
  Then PERSON has year_of_birth, and month_of_birth, day_of_birth and birth_datetime are empty

Scenario: no free text
  Given suppress-free-text is active and the field "notes" is approved as Note
  When the project is exported
  Then NOTE is empty and stats[notes_suppressed] is 1

Scenario: the report says so
  Given shifting, year precision, text suppression and hashing are active
  When the ZIP is exported
  Then INFORME.txt contains the "Pseudonymisation applied" section with the four measures
```

## Out of scope

- Anonymization (k-anonymity, postcode generalization, suppression of rare
  cases).
- Suppressing text in labels (`*_source_value` holding the question or choice
  label): they are dictionary texts, not answers.
- Rotating the salt: changing it changes every hash and shift.

## Manual verification

No functional requirement is `[manual]`. NFR-016-03 has a test over
`lang/*.ini`; in the README, check by hand that "anonymous" (and «anónimo» in
the Spanish texts) only appears to say that the result is **not** anonymous.

## Amendments

| Date | Requirement | Change | Motivated by |
|---|---|---|---|
| — | — | Initial version | — |
