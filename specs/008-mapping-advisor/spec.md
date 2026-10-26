# Spec 008 — Mapping advisor

| | |
|---|---|
| **Status** | Implemented |
| **Depends on** | 006 |
| **Tests** | `tests/specs/008_mapping_advisor.test.php` |

## Context

The classifier (004) says what a field resembles and the store (006) prevents
approving what is incomplete. But a mapping can be complete and still produce
rows that **lie**: a "was medication given?" mapped to Drug generates
exposures to a generic drug while the real drug stays in a text that nobody
will code; a pain scale mapped option by option creates ten concepts where
there is one; a yes/no that also emits the "No" inflates any cohort. That is
worse than exporting nothing, because it looks like information.

This spec builds `classes/MappingAdvisor.php`: a battery of checks on the
stored mapping of a field, each with a severity (`block`, `warn`, `info`), a
stable code and a text that explains what happens if it is ignored and how to
fix it (principle 22). The bar for blocking is deliberately high (principle 5):
it only blocks what would produce impossible data; everything else warns and
requires a confirmation that is logged. Some warnings are not about the
mapping but about the **instrument design**: they are cheap to fix in the
Designer and expensive later.

Checking concepts against a real vocabulary (spec 014) and the warning about
an imported mapping with different labels (spec 015) plug into the advisor as
extension points.

## User stories

- **US-008-1.** As a curator, I want the module to prevent me from approving
  what would produce impossible data, so we do not export rows without a date
  or with a negative concept.
- **US-008-2.** As a curator, I want to be warned about mappings that look
  correct but mislead (a vague yes/no in Drug, a scale per option, the "No"
  that emits), with the reason, so I can decide knowingly.
- **US-008-3.** As a quality officer, I want approving despite a warning to
  require confirmation and to be logged with the codes, so it can be audited.
- **US-008-4.** As the instrument designer, I want to know which fields have a
  label that calls for a validation they do not have, so I can fix it in the
  Designer before collecting more data.

## Functional requirements

- **FR-008-01** — `MappingAdvisor::check($field, $spec, $prof, $values, $dictMeta)` MUST return a list of warnings `{severity, code, text}`, with `severity` ∈ `block`, `warn`, `info` and `text` in plain text in the current language (`ttText('adv_' . $code, …)`, unescaped arguments). Without a stored domain, or with `NotMapped` or `DateAnchor`, there are no warnings. The module's `advise($field)` evaluates the row from `mappingRows()` (a non-existent field has no warnings) and `adviseAll()` returns the warnings of the non-excluded fields that have any.
- **FR-008-02** — `MappingAdvisor::blocks($warnings)` MUST be true if there is any `block` warning, and `needsConfirm($warnings)` if there is any `warn`; `info` warnings neither block nor ask for confirmation.
- **FR-008-03** — Only five situations MUST block (`block`): a REDCap status field `*_complete` (`redcap_complete_field`); a negative `concept_id` in the field or in an option (`concept_negative`); a fact without its own date and without a configured default date (`no_date_at_all`); a reference or end date that does not exist in the data dictionary (`date_field_gone`); and a field in the Visit domain with automatic visit generation enabled (`visit_conflict`).
- **FR-008-04** — Regarding dates, the advisor MUST inform that the default date will be used, naming it (`using_default_date`, `info`); warn if the chosen date is on another instrument (`date_other_form`, `warn`, with the date and the instrument); and inform that an end date will be ignored in a domain other than Condition, Drug, Procedure, Device and Visit (`end_date_ignored`, `info`). Person and Death do not go through the reference date checks.
- **FR-008-05** — On a text field without validation, the advisor MUST warn (`warn`) about the first design error it finds, in this order: a label that talks about a date (`label_date_no_validation`: "fecha", "date", "día de"…), a label that asks for a number (`label_numeric_no_validation`: "número", "cuántos", "recuento", "porcentaje", "edad", "count", "how many", "age"…) and a unit in parentheses at the end (`label_unit_no_validation`: kg, cm, mg/dL, %, mmHg…). With validation there is no design warning.
- **FR-008-06** — The advisor MUST warn (`warn`) about a field flagged as an identifier in REDCap (`identifier`); about a free-text value outside Note (`free_text`); about a field in Note that is not text (`note_not_text`); and about a field in Note when the configuration suppresses free text (`note_suppressed`).
- **FR-008-07** — A yes/no (`yesno`, `truefalse`, or two options with one of yes/no/true/false/present/absent, in Spanish or English) in Drug, Procedure, Device or Specimen MUST warn with `vague_yesno` (`warn`, with the domain) if the name or the label contain a term that does not say what was done ("medicación", "tratamiento", "fármaco", "prueba", "procedimiento", "medication", "treatment", "drug", "test", "procedure", "something"…) and, otherwise, inform with `yesno_in_domain` (`info`). In other domains there is no warning.
- **FR-008-08** — In Measurement, the advisor MUST warn (`warn`) about a numeric measurement without a unit (`measurement_no_unit`), about a text measurement (`measurement_text`) and about a categorical result that is neither yes/no nor a scale mapped "one concept per option" (`categorical_per_choice`).
- **FR-008-09** — A scale (five or more options whose labels, except at most one, start with a number) MUST warn with `scale_per_choice` (`warn`, with the number of points) if the strategy decides per option, and with `scale_wrong_domain` (`warn`) if the domain is neither Measurement nor Observation. The labels are looked at, not REDCap's codes 1, 2, 3.
- **FR-008-10** — A field in Person that the configuration does not use MUST warn with `person_no_rows` (`warn`), or with `person_not_configured` (`warn`) if the PERSON configuration does not yet declare more than the Record ID; the fields it does use (sex, birth, race, ethnicity, Record ID) do not warn. In Death, the advisor MUST warn with `death_no_date_config` (`warn`) if there is no configured death date and the field is not a date, and inform with `death_concept_is_cause` (`info`) if there is a concept in the field or in an emitting option.
- **FR-008-11** — The advisor MUST warn (`warn`) about a numeric value in a domain whose table has no value column (everything except Measurement, Observation, Person and Death), with the domain and the table (`numeric_lost`), and about a clinical yes/no per option in Condition, Procedure, Drug or Device that also emits the negative option (`emits_negative`); an option without a stored decision counts with its default value (the "No" does not emit).
- **FR-008-12** — Without a vocabulary, the advisor MUST inform about a non-empty `concept_id` that is 0 as an integer (`concept_zero`, `info`) and warn (`warn`) about: the same concept in all emitting options (`same_concept_all`); a concept without a name in the field (`no_concept_name`) or in an option (`no_concept_name_option`, with the option); and a name that does not look like it comes from Athena (`concept_name_is_label`, up to three): with marks of Spanish (accents, ñ, ¿¡ or unambiguous words such as "peso", "fecha", "paciente") or with survey wording (question mark, or starting with "do", "is", "how", "please", "select"…). An English name that matches the label does not warn.
- **FR-008-13** — Vocabulary extension point: with a configured vocabulary, the advisor MUST NOT perform the name checks of FR-008-12 (`no_concept_name`, `no_concept_name_option`, `concept_name_is_label`) and MUST delegate to those of spec 014 (`concept_not_found`, `concept_invalid`, `concept_not_standard`, `concept_wrong_domain`, `concept_name_mismatch`, `vocab_unavailable`).
- **FR-008-14** — `advise()` MUST cache the warnings per field during the request and recompute them after any save of the store (`invalidateMappingCache()`), so that the editor always shows the warnings of the current content.
- **FR-008-15** — When approving from the editor, a `block` warning MUST prevent approval even if confirmed; `warn` warnings MUST require explicit confirmation (`confirmed = 1`), and the confirmed approval is logged as `Approved with warnings` with the codes; with only `info` warnings it is approved without asking.

## Non-functional requirements

- **NFR-008-01** — The bar for blocking is high: only the five codes of FR-008-03 have `block` severity.
- **NFR-008-02** — Bilingual: the 40 advisor codes (the 33 of this spec and the 7 of the extension points) have their `adv_<code>` text in Spanish and in English, with what happens if ignored and how to fix it.

## Acceptance criteria

```gherkin
Scenario: the central case
  Given a yes/no "Medication given" mapped to Drug per option
  When its warnings are requested
  Then there is a vague_yesno warning with severity warn
  And approving it asks for confirmation

Scenario: impossible data
  Given a haemoglobin field in Measurement without its own date
  And the project without a configured default date
  When its warnings are requested
  Then there is a no_date_at_all warning with severity block
  And the editor does not approve it even if confirmed

Scenario: a freshly opened yes/no does not raise a false warning
  Given "Fever", a yes/no in Condition per option with no stored options
  When its warnings are requested
  Then there is no emits_negative warning

Scenario: the concept name
  Given "Hemoglobin" with concept_id 3000963 and the name "Peso corporal"
  And no vocabulary configured
  When its warnings are requested
  Then there is a concept_name_is_label warning
  But with the name "Body weight" on a field "Body weight" there is none
```

## Out of scope

- Checking concepts against a real vocabulary (existence, deprecated,
  non-standard, domain, name that does not match) and warning if it does not
  respond: spec 014.
- The warning about a mapping imported from another project with different
  option labels (`import_labels_differ`): spec 015.
- What prevents approving because it is incomplete (no domain, no concept,
  pending options): `readyToApprove()`, spec 006.
- How warnings and confirmation dialogs are rendered: spec 007.

## Manual verification

No functional requirement is `[manual]`. Complementary check in the Docker
environment: in the editor, map a yes/no "¿Recibió tratamiento?" to Drug and
approve; the dialog shows the warning with its explanation; after accepting,
*External Modules → View logs* shows "Approved with warnings" with
`vague_yesno` among the codes.

## Amendments

| Date | Requirement | Change | Motivated by |
|---|---|---|---|
| — | — | Initial version | — |

### Proposed amendment (pending decision)

- **FR-008-12, `concept_zero`.** The concept_id 0 ("no matching concept")
  typed in the editor is stored as the integer 0, and the advisor discards
  empty concepts with `empty()`, which considers 0 empty. That is why
  `concept_zero` only appears with a non-empty value that is 0 as an integer
  (for example `"00"` coming from an import) and never with the 0 typed in the
  editor; moreover `readyToApprove()` (006) treats 0 as "the concept is
  missing". Proposal: decide whether 0 is an approvable value and, if so,
  check `!== null` instead of `empty()` in `checkConceptId()` and in
  `readyToApprove()`. The current tests characterise today's behaviour.
