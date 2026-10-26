# Spec 003 — Data dictionary profiling

| | |
|---|---|
| **Status** | Implemented |
| **Depends on** | 001 |
| **Tests** | `tests/specs/003_dictionary_profiling.test.php` |

## Context

The REDCap data dictionary describes each field with a dozen columns designed
to render forms, not for an ETL: a type (`text`, `radio`, `yesno`…), a
validation (`date_ymd`, `number`…), a label containing HTML, choices in a
string such as `1, Sí | 0, No`, an identifier flag. Before deciding which OMOP
domain a field goes to (spec 004), those columns have to be translated into
what the rest of the module needs to know: what **kind of value** it holds
(number, category, date, free text), which concrete **choices**, whether the
label carries a **unit**, whether it is an **identifier** that must not leave
the project, whether it can be transformed automatically or needs manual
coding, and which **date** of the instrument dates it.

This spec defines that profile. It is produced by
`DictionaryProfiler::profile()`, which does not depend on REDCap: it receives
the dictionary as an array and an optional translator for the reasons, and
returns one row per field. The module calls it with the real dictionary, the
project structure (Record ID, instruments, repeating instruments) and its
`tt()`, and caches it per request (spec 001).

Some profile rules are exclusion decisions that come before any semantic
classification, because they are explicit statements by the project: the
Record ID is the person; a field flagged as an identifier in REDCap does not
reach the CDM; descriptive, file, signature and calculated fields are not
observed values. Keyword classification is spec 004.

## User stories

- **US-003-1.** As a mapping curator, I want to see for each field whether it
  is a number, a category, a date or free text, and its choices, so that I
  know which decision is mine to make.
- **US-003-2.** As a data protection officer, I want fields flagged as
  identifiers in REDCap to stay out of the CDM without anyone having to
  remember it.
- **US-003-3.** As a curator, I want the unit already present in the label
  («Peso (kg)», "Weight (kg)") and the instrument's date to arrive
  pre-filled, so that I confirm instead of typing.
- **US-003-4.** As a developer, I want to profile a dictionary without
  starting REDCap, so that I can test it with sample data.

## Functional requirements

- **FR-003-01** — `DictionaryProfiler::profile($dictionary, $structure)` MUST return one row per field, keyed by `field_name` and in dictionary order, with these 16 keys: `field_name`, `form_name`, `field_type`, `validation`, `field_label`, `is_identifier`, `value_kind`, `unit_hint`, `suggested_strategy`, `mappable`, `choices`, `choice_count`, `suggested_domain`, `suggested_table`, `confidence`, `reason`; `suggested_table` is `CdmModel::tableForDomain(suggested_domain)`.
- **FR-003-02** — The label MUST be stripped of HTML (`strip_tags`) and of leading and trailing whitespace before it is stored and analysed.
- **FR-003-03** — `value_kind` MUST be `categorical` for `radio`, `dropdown`, `checkbox`, `yesno` and `truefalse`; `numeric` for `slider` and for text with `integer`, `number` or `number_Ndp` (1 to 4) validation; `date` for validations starting with `date` (including `datetime*`); `identifier` for the Record ID; `none` for the types without a value (FR-003-09); and `text` in any other case (notes, text without validation or with another validation).
- **FR-003-04** — `parseChoices($raw)` MUST turn `"1, Sí | 0, No"` into `['1' => 'Sí', '0' => 'No']`: it splits on `|`, separates code and label at the **first** comma, trims whitespace and discards empty chunks or chunks without a comma; `choice_count` is the number of choices. The module exposes the same parser (`OmopCdmExportModule::parseChoices`).
- **FR-003-05** — `choicesFor($meta)` MUST give `yesno` the implicit choices `['1' => 'Yes', '0' => 'No']` and `truefalse` `['1' => 'True', '0' => 'False']`, even if the dictionary contains something else; `radio`, `dropdown` and `checkbox` their declared choices; and any other type (`calc`, `sql`, `text`, `slider`…) none, because that column holds a formula or a query there. The negative choice (`isNegativeChoice`) is looked for only among those choices.
- **FR-003-06** — `unit_hint` MUST be extracted, only for numeric fields, from the label's final parenthesis (1 to 20 characters) if it contains `/`, `%`, `^` or `°`, or if, once normalised, it is in the list of known units (`kg`, `mg`, `cm`, `mmhg`, `bpm`, `lpm`, `ml`, `ui`, `mmol`, `dias`, `anos`, `puntos`…); otherwise it is `null` («(LDL)», «(poner número exacto)» — "enter exact number"). If there is a unit, the reason says so.
- **FR-003-07** — `is_identifier` MUST be `true` when the `identifier` column is `y` (ignoring case and whitespace). A flagged field MUST be proposed as `NotMapped`, confidence `high`, reason `r_phi_flag`, except the Record ID, fields with date validation and sex or gender fields (`sexo`, `genero`, `sex`, `gender`, `sex at birth`, `gender identity`…), which keep their classification with the warning `r_sfx_phi` in the reason.
- **FR-003-08** — The field that the structure declares as `record_id_field` MUST be proposed as `Person`, confidence `high`, `value_kind` `identifier` and reason `r_record_id`, before any other rule (including the identifier rule).
- **FR-003-09** — `descriptive`, `file`, `signature` and `calc` fields MUST be proposed as `NotMapped`, confidence `high`, `value_kind` `none`, `mappable` `no`, with no unit and with reason `r_skip_type(<type>)`, even if the label is clinical.
- **FR-003-10** — A field with date validation and no clinical meaning of its own MUST be proposed as `DateAnchor` (candidate reference date for other facts), confidence `high`, reason `r_date_anchor(<validation>)` and no unit. Dates with a meaning (birth, death, visit, diagnosis) are classified by spec 004.
- **FR-003-11** — `mappable` MUST be `no` if the domain is `NotMapped`; `manual` if the value is free text and the domain is not `Note`; and `auto` in all other cases (numeric, categorical, date, clinical note).
- **FR-003-12** — `suggested_strategy` MUST be `null` for non-categorical fields or fields without choices; `single_concept` for a rating scale (≥ 5 choices whose labels, all but at most one, start with a number); `value_concept` for a `Measurement` or `Observation` categorical that is not a yes/no; and `per_choice` in all other cases.
- **FR-003-13** — Reasons MUST be composed with an injected translator `function(string $key, ...$args): string`; without a translator, the reason is the key followed by its arguments (`r_keyword(peso)r_sfx_unit(kg)`). The module injects its `tt()`, so reasons come out in the project's language.
- **FR-003-14** — `getProjectStructure()` MUST return `project_id`, `record_id_field` (the project's primary key), `longitudinal`, `surveys_enabled`, `forms`, `events` and `repeating` (one `event_id`/`form` entry per repeating instrument, with an empty `form` if the whole event repeats); the module's `profile()` passes that structure to the profiler.
- **FR-003-15** — The module MUST offer the candidate dates: `dateFields()` lists the fields with date validation and their clean label; `isDateField($f)` checks it for one field; `suggestedDateField($f, $domain)` proposes the date of a fact: the configured date of death for `Death`, the field itself if it is a date, or the first date in its own instrument (not counting the configured date of birth), and `null` if there is none or the field does not exist.

## Non-functional requirements

- **NFR-003-01** — The profiler does not depend on REDCap or on the module and does not modify the dictionary it receives.
- **NFR-003-02** — Profiling 1,000 fields takes less than 2 seconds without a debugger (Xdebug multiplies the time; with it, the limit is 10 seconds).

## Acceptance criteria

```gherkin
Scenario: weight with a unit
  Given a text field «Peso (kg)» with number validation
  When it is profiled
  Then value_kind is numeric, unit_hint is «kg» and mappable is auto

Scenario: abbreviation that is not a unit
  Given a numeric field «LDL (LDL)»
  When it is profiled
  Then unit_hint is null

Scenario: flagged identifier
  Given a field «Postal code» with identifier = y
  When it is profiled
  Then NotMapped is proposed with high confidence and reason r_phi_flag

Scenario: sex flagged as an identifier
  Given a radio field «Sexo» with identifier = y
  When it is profiled
  Then Person is proposed and the reason ends with r_sfx_phi

Scenario: implicit choices
  Given a yesno field «Fever»
  When it is profiled
  Then choices is [1 => Yes, 0 => No] and suggested_strategy is per_choice

Scenario: instrument date
  Given the field weight of the base instrument, which has dob, visit_date and death_date
  And dob configured as the date of birth
  When suggestedDateField('weight') is requested
  Then visit_date is proposed
```

## Out of scope

- Semantic classification by keywords, specificity and health-system
  entities (spec 004).
- Which choices emit a row and which do not, and detection of the negative
  choice (`isNegativeChoice`, specs 006 and 009).
- How the profile is rendered in the dashboard (005) and the editor's default
  values (007).
- Events and visits of longitudinal projects (spec 010).

## Manual verification

There are no `[manual]` requirements.

## Amendments

| Date | Requirement | Change | Motivated by |
|---|---|---|---|
| — | — | Initial version | — |
