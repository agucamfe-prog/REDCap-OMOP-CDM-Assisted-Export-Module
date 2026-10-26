# Spec 018 — Validation with realistic projects

| | |
|---|---|
| **Status** | Implemented |
| **Depends on** | all (001–017) |
| **Tests** | `tests/specs/018_multiproject_validation.test.php` |

## Context

Specs 001–017 are tested with a small tuberculosis project and with the
classifier benchmark (spec 004). That is enough for each piece, but it does not
tell whether the module serves real teams: dictionaries written by different
people, in two languages, with longitudinal designs, repeating instruments,
data access groups, dirty data and different ways of curating.

This spec validates the module end to end with **10 realistic made-up
projects**: 5 from teams that work in Spanish and 5 in English, 186 fields,
405 synthetic participants and 9 clinical areas (heart failure, type 2
diabetes, hospitalised COVID-19, breast cancer screening, multicentre stroke,
paediatric asthma, ICU sepsis, rheumatoid arthritis and pregnancy with
gestational diabetes). There are classic and longitudinal designs (2–3 events),
repeating instruments, one project with data access groups (DAGs) and the four
curation modes: manual, `@OMOP` annotations, import between sites
(P10 → P5) and vocabulary suggestions.

| Project | Language | Area | Design | Mode | Records | Fields |
|---|---|---|---|---|---|---|
| P1 RICA-Madrid | es | Heart failure | 3 events, repeating | manual | 40 | 29 |
| P2 DIABAP | es | Type 2 diabetes | classic, repeating | annotations | 45 | 19 |
| P3 COVID-HOSP | es | COVID-19 | classic, pseudonymised | manual | 45 | 18 |
| P4 CRIBAMA | es | Breast screening | classic, 2 DAGs | manual | 40 | 13 |
| P5 MSOS Madrid | es | Stroke (translated) | 2 events | import from P10 | 35 | 17 |
| P6 PAR | en | Paediatric asthma | 3 events, repeating | manual | 40 | 18 |
| P7 SEPSIS-ICU | en | ICU sepsis | classic, repeating | manual | 40 | 19 |
| P8 RABR | en | Rheumatoid arthritis | classic, repeating, care site and provider | manual | 40 | 17 |
| P9 PREG-GDM | en | Pregnancy and GDM | 3 events, pseudonymised | suggestions | 40 | 19 |
| P10 MSOS London | en | Stroke | 2 events | manual | 40 | 17 |

Every field has a **reference mapping** (the one an expert curator would make)
in `tests/eval/specs.php`: `m` is the curator's mapping (`d` domain or `x`
exclude) and `t` is the acceptable domain or domains when the field is not
mapped.

Whatever was found was fixed **as an amendment to the affected spec**, not
here: this spec only measures, leaves the regression corpus behind and links the
amendments.

## User stories

- **US-018-1.** As the module owner, I want to measure the classifier on
  projects that were not written for it, in both languages, to know how much
  work it really saves.
- **US-018-2.** As a developer, I want a versioned regression corpus with those
  186 fields that runs with the rest of the tests, so that no classifier
  improvement undoes what was gained without anyone noticing.
- **US-018-3.** As an external evaluator, I want the scripts and the procedure
  to reproduce the full evaluation (REDCap → curation → export → PostgreSQL)
  with no secrets in the repository.
- **US-018-4.** As the curator of a multicentre study, I want what was tested
  between London and Madrid to stay as a test, so that cross-language import
  does not break.

## Functional requirements

- **FR-018-01** — The corpus `tests/fixtures/eval10/fields.csv` MUST contain the 186 fields of the 10 projects, one per row, with the columns `lang, project, field_name, form_name, field_type, label, choices, validation, identifier, expected`: P1–P5 in Spanish (96 fields) and P6–P10 in English (90), with no repeated names within a project, and every expected domain valid for the module (`CdmModel::validDomains()`).
- **FR-018-02** — The expected domain MUST be derived from the reference mapping: `NotMapped` if the curator excludes the field (`m.x`), the curator's domain if they map it (`m.d`), and otherwise the acceptable domains from `t` separated by `|` (as in `tests/fixtures/benchmark.csv`); each project's `record_id`, `Person`. `tests/eval/build_corpus.php` MUST regenerate the corpus from `specs.php` byte for byte.
- **FR-018-03** — The measurement MUST profile each project with its full dictionary and the first field as the record identifier (as REDCap does), count a hit if the proposed domain is among the acceptable ones, and report the accuracy per project, per language and overall, and every miss (project, language, field, label, proposed and expected) except in `--quiet` mode.
- **FR-018-04** — The multicentre scenario MUST be reproduced without REDCap with the P10 (English) and P5 (Spanish) dictionaries from the corpus: London approves sex, antithrombotic at discharge and glucose and excludes the CT; Madrid imports its `MAPEO.json` and receives those fields as proposals (the exclusion, excluded), with the codes whose labels were translated in `import_diff` (sex `1, 2`; antithrombotic `1, 3, 4`) and the `import_labels_differ` warning, which does not block.
- **FR-018-05** — The evaluation scripts MUST be versioned in `tests/eval/` with a README; every PHP script MUST refuse to run outside the command line (404), and there MUST NOT be any `projects.json`, API tokens or passwords in the module: `projects.json`, cookies and new results are written to `$OMOP_EVAL_WORK`, outside the module.
- **FR-018-06** — The measured results MUST be kept in `tests/eval/results/` and be consistent with the spec: round 1, 154/186 (ES 82/96, EN 72/90); round 2, 184/186; integrity, 10 schemas × 10 checks with 0 issues. The corpus cannot give less today than round 2.
- **FR-018-07** — The 10 integrity checks (`fk_person_broken`, `fk_visit_broken`, `fk_care_site_broken`, `fk_provider_broken`, `fact_outside_visit`, `fact_outside_period`, `fact_before_birth`, `fact_after_death`, `end_before_start`, `person_without_period`) MUST be in `tests/eval/integrity.sql`, runnable for one schema with `psql -v s=<schema>`, and in `checks.sh` for the ten schemas.
- **FR-018-08** — The 10 projects MUST be creatable in REDCap with `tests/eval/generate.php` (API, fixed seed 20261025): dictionaries from `specs.php`, events, repeating instruments, DAGs, 405 synthetic records with 3 % missing data, dirty values (`< 5`, `1.250`, `12,5`, `hemolizada`) and one record per project with no date of birth. `[manual]`
- **FR-018-09** — Curation MUST be reproducible with `configure.php` and `curate.php` through the same requests the editor makes, in each project's mode: manual (field by field, confirming warnings), annotations (P2: "Use the @OMOP annotations" and "Approve what is ready"), import (P5 from P10) and suggestions (P9: the magic wand with the Eunomia vocabulary). `[manual]`
- **FR-018-10** — The 10 exports MUST load with their kit into PostgreSQL 17 (one schema per project, `p1`…`p10`) and pass the 10 integrity checks with 0 issues. `[manual]`
- **FR-018-11** — The team scenarios MUST be checked in P4: a DAG user only exports the persons in their group; a user with design rights but without full export rights on some instrument involved cannot export and is told which instrument prevents it; a member without design rights sees the module links and the editor in read-only mode. `[manual]`
- **FR-018-12** — Every anomaly found MUST be fixed as an amendment to the affected spec (a requirement in its list and a row in its *Amendments* table citing 018), not as a silent change; this spec's *Anomalies* table links them. `[manual]`

## Non-functional requirements

- **NFR-018-01** — Overall classifier accuracy on the eval10 corpus ≥ 97 % (measured: 184/186 = 98.9 %).
- **NFR-018-02** — Accuracy in each language ≥ 95 % (measured: ES 94/96 = 97.9 %; EN 90/90).
- **NFR-018-03** — The spec 004 reserved set (`holdout`), which was not looked at while fixing, MUST stay ≥ 70 % (measured: 26/27, unchanged). Lowering a threshold to make a test pass is forbidden (AGENTS.md).
- **NFR-018-04** — All data are synthetic; no data from real participants enters the repository.

## Results

### Classifier

| Round | Overall | Spanish | English |
|---|---|---|---|
| 1 (before fixing) | 154/186 = 82.8 % | 82/96 = 85.4 % | 72/90 = 80.0 % |
| 2 (after fixing general causes) | 184/186 = 98.9 % | 94/96 = 97.9 % | 90/90 = 100 % |
| Reserved set (holdout, spec 004) | 26/27 before and after | | |

The 32 round-1 misses were grouped by cause and general rules were fixed,
never field by field: a diagnosis date treated as a time anchor
(`fecha_dx`, `onset_date`, `ra_dx`, `dx_asthma`); a yes/no diagnosis or symptom
proposed as Observation (`fatiga`, `af`, `hypothyroidism`, `osteoarthritis`,
`anaemia`); scale scores (NIHSS, DAS28, ACT) as Observation or Condition;
"at discharge" and "before follow-up" dragging towards Visit
(`discharge_med`, `died`); clinical notes as Observation (`evolucion`,
`progress_note`); dirty lab values (`ntprobnp`, `pcr_mg`) and the categorical
result of a test (`pcr`) as Observation; a finding "on the X-ray"
(«en la radiografía») as Procedure (`neumonia`); a yes/no procedure as
Observation (`ventilation`); yes/no drugs (`prednisone`, `levothyroxine`);
entities (`centre`, `rheumatologist`) as facts, and "since the last visit"
(«desde la última consulta») dragging towards Visit (`hipoglucemias`). The same
five causes in P10 repeated in its translation P5. Two misses remain, both in
P2:

| Field | Proposed | Expected | Why it is accepted |
|---|---|---|---|
| `hipoglucemias` «Número de hipoglucemias desde la última consulta» (number of hypoglycaemias since the last visit) | Condition | Observation or Measurement | A count of episodes; fixing it would need a rule for a single field |
| `comentarios` «Comentarios» (comments) | Observation | NotMapped | The curator excludes it; "Exclude free-text fields" resolves it in bulk |

### Curation and export

- 405 participants → 395 persons in the CDM: each project's record with no date
  of birth is dropped with all its facts and the report explains it
  (principle 8).
- 6,751 CDM rows in the 10 exports; 10 checks × 10 schemas with 0 issues in
  PostgreSQL 17.
- P2 (annotations): 15 fields adopted, 11 approved in bulk.
- P5 (import from P10): 9 fields imported, 5 approved in bulk, 2 with
  `import_labels_differ` confirmed one by one.
- P9 (suggestions): the six concepts searched with the magic wand (weight,
  systolic and diastolic blood pressure, fasting glucose, haemoglobin, TSH) come
  first after amendments 6 and 7; before, "fasting glucose" and "haemoglobin"
  gave 0 candidates. In P3, the SARS-CoV-2 PCR also goes from 0 candidates to
  first place.

## Anomalies fixed (amendments)

| # | Anomaly | Fix | Amended spec |
|---|---|---|---|
| 1 | `concept_not_found` blocked approval with a partial vocabulary (Eunomia) | A warning that requires confirmation | [014](../014-vocabulary/spec.md) (FR-014-07) |
| 2 | Import required identical labels: no categorical field was imported between London and Madrid | Same codes + `import_labels_differ` warning; exclusions too | [015](../015-mapping-reuse/spec.md) (FR-015-10, FR-015-12, FR-015-13) |
| 3 | Fields consumed by the configuration (Record ID, birth, death, care site) always stayed "pending" | `config` state | [006](../006-mapping-store/spec.md) |
| 4 | A member without design rights could not see any module page | `redcap_module_link_check_display()` | [012](../012-permissions-dags/spec.md) (FR-012-11, FR-012-12) |
| 5 | Classifier gaps (round 1, 32 misses) | General rules; reserved set untouched | [004](../004-domain-classifier/spec.md) (FR-004-06, -07, -09, -11, -13, -14, -15; NFR-004-04) |
| 6 | Searching with all the words (AND) gave 0 candidates ("fasting glucose", "PCR SARS-CoV") | Relax to OR if there are no results | [014](../014-vocabulary/spec.md) (FR-014-14) |
| 7 | "Haemoglobin (g/dL)" did not find haemoglobin | `americanize()` in search and suggestions | [014](../014-vocabulary/spec.md) (FR-014-15) |

## Acceptance criteria

```gherkin
Scenario: classifier regression
  Given the corpus tests/fixtures/eval10/fields.csv
  When php tests/run.php 018 is run
  Then the overall accuracy is ≥ 97 % and that of each language ≥ 95 %
  And the reserved set stays ≥ 70 %
  And the failing fields are listed with what was proposed and what was expected

Scenario: the reference changes
  Given a change in tests/eval/specs.php
  When php tests/eval/build_corpus.php is run
  Then the diff of fields.csv shows exactly the affected fields

Scenario: cross-language multicentre study
  Given London's approved mapping (P10)
  When Madrid (P5) imports its MAPEO.json
  Then sex and the antithrombotic arrive as proposals with import_labels_differ
  And nothing is left approved

Scenario: full reproduction
  Given a REDCap with the module and a PostgreSQL 17
  When the steps in tests/eval/README.md are followed
  Then the 10 exports load and the 10 checks give 0
```

## Out of scope

- Fixing the classifier or any other piece: that is done in the affected spec
  (*Anomalies* table).
- Data Quality Dashboard with the full vocabulary (spec 017).
- Measuring the curation time of real people: the curation modes were run with
  scripts that imitate the editor.
- The paper's screenshots (Playwright scripts): they are not part of the
  validation.

## Manual verification

The steps are in [`tests/eval/README.md`](../../tests/eval/README.md). Inside
the REDCap web container, from the module root, with
`OMOP_EVAL_WORK=/tmp/omoptest`:

**FR-018-08.** `php tests/eval/generate.php SUPERTOKEN` (API token of a
super user). It must create 10 projects and write `$OMOP_EVAL_WORK/projects.json`.
Check in REDCap: P1, P6 and P9 with 3 events; P5 and P10 with 2; P4 with the
DAGs `hospital_norte` and `hospital_sur`; P1 with `tratamiento` repeating;
405 records in total.

**FR-018-09.** `php tests/eval/configure.php` and `php tests/eval/curate.php`.
The per-project output must have no `#` lines (blocks) and only the expected
suggestion errors. P2 reports 15 adopted; P5, 9 imported.
`php tests/eval/measure.php round2` must give `TOTAL 184/186`.

**FR-018-10.** `sh tests/eval/export_all.sh` (adjusting the pids in the
script), load each ZIP with `carga_cdm/cargar.sh` into schema `p1`…`p10` of a
PostgreSQL 17 `cdm` database and run `sh tests/eval/checks.sh`, or for one
schema `psql -U postgres -d cdm -At -F'|' -v s=p1 -f tests/eval/integrity.sql`.
The 10 checks must give 0 in all 10 schemas.

**FR-018-11.** In P4, with three test users:
1. A user in DAG `hospital_norte` with design rights and full export rights:
   the export only contains persons from that group (compare the PERSON count
   with the number of records in the DAG).
2. A user with design rights and without full export rights on `cribado`: the
   export page does not allow exporting and names the instrument `cribado`.
3. A member without design rights: sees the module links in the project menu,
   and the dashboard and the editor in read-only mode (no save or approve
   buttons).

**FR-018-12.** Open the specs in the *Anomalies* table and check that each one
has the amended or new requirement in its list and a 2026-10 row in its
*Amendments* table citing 018.

## Amendments

| Date | Requirement | Change | Prompted by |
|---|---|---|---|
