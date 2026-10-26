# Spec 004 — Bilingual domain classifier

| | |
|---|---|
| **Status** | Amended |
| **Depends on** | 002, 003 |
| **Tests** | `tests/specs/004_domain_classifier.test.php` |

## Context

With the profile from spec 003 the module knows what kind of value each field
holds, but not what it is about. To propose an OMOP target (Condition, Drug,
Measurement…) the label has to be read. This spec defines the classifier that
does it: a bilingual Spanish/English lexicon of terms grouped by domain, some
matching rules and some priority rules. It is a starting point for the person
curating the mapping, never the final result (principle 1): that is why every
proposal carries its confidence and its reason (principle 22), and a
classifier mistake costs one click, not a data item.

The rules come from the typical errors of naive substring searches: «lab»
inside «laboral» (work-related), «peso» (weight) beating «pérdida de peso»
(weight loss), «visita» dragging «¿Refiere dolor en el momento de la visita?»
(does the patient report pain at the time of the visit?) into the Visit
domain, «residencia» turning a postal address into PERSON data, or «hospital»
excluding «Ingreso en hospital» (hospital admission). Each rule in this spec
answers one of those failures.

The classifier understands both languages regardless of the interface language
(principle 21). It is measured with a public benchmark,
`tests/fixtures/benchmark.csv`: 120 real fields with the correct target or the
defensible targets, split into a **development** set (93 fields, used to tune
the lexicon) and a **holdout** set (27 fields that are never looked at while
tuning). The honest figure is the holdout one.

In October 2026 spec 018 evaluated the classifier on 10 realistic projects
(186 fields, 5 in Spanish and 5 in English). It got 154/186 right (82.8 %).
The failures were grouped into general causes (not one-off cases), which were
fixed with the amendment described at the end: frequent clinical terms missing
from the lexicon, diagnosis dates treated as date anchors, context phrases that
dragged fields into Visit, progress notes, professional roles with a prefix,
numeric scale scores and British spelling. After the amendment: 184/186, and
the benchmark holdout stayed at 26/27.

## User stories

- **US-004-1.** As a curator, I want every field to arrive with a proposed
  domain, a confidence and the reason, so I can review the doubtful ones first.
- **US-004-2.** As the team of an English-language project, I want the
  classifier to understand my labels even if the interface is in Spanish.
- **US-004-3.** As the data protection officer, I want identifiers, contact
  details and entities (care sites, clinicians) to be proposed outside the CDM.
- **US-004-4.** As a maintainer, I want a reproducible accuracy measure, so I
  know whether a lexicon change makes things better or worse.

## Functional requirements

- **FR-004-01** — Text MUST be compared normalised with `TextNormalizer::normalize()`: lower case, no accents (`á`→`a`, `ñ`→`n`, `ß`→`ss`…), every non-alphanumeric character turned into a space and whitespace collapsed; `tokens()` gives the unique normalised words with a minimum length. Upper case, accents, hyphens and punctuation do not change the classification.
- **FR-004-02** — A lexicon term MUST match only as a whole word or phrase of the text; a term ending in `*` is a stem that matches the start of a word (`diagnos*` matches «diagnosticado»; `lab` does not match «laboral»).
- **FR-004-03** — The label alone MUST be classified first, falling back to «variable name + label» only if the label gives no signal (`peso_visita` labelled «Peso» is Measurement).
- **FR-004-04** — Among the clinical domains the longest matching term MUST win; at equal length the order Person, Death, Visit, social determinants, lifestyle, intensity, scales, Drug, Procedure, Specimen, Device, Measurement, Observation, Condition breaks the tie. A multi-word term gives `high` confidence and a one-word term `medium`.
- **FR-004-05** — Administrative fields and identifiers (medical record number, NHC, initials, phone, e-mail, DNI, address, consent, signature, *record id*, *patient name*, *MRN*…) and labels that are just a name («Nombre», «Apellidos», «Full name», «Surname») MUST be proposed `NotMapped` with `high` confidence and reason `r_admin`, checked separately and before specificity (FR-004-04): «Dirección de residencia» (home address) is administrative, not Person; «Nombre del medicamento» (drug name) is not administrative.
- **FR-004-06** — Health system entities (care sites, hospitals, services, units, wards, clinicians, kinship) MUST be proposed `NotMapped` with reason `r_structural` only when the label **starts** with the entity, allowing the prefixes «nombre de/del/de la», «name of (the)», «tipo de», «type of» and, after the amendment, «responsible», «treating», «attending» and «lead». If the entity appears in the middle of the label, or the label is a question (`¿`/`?`), the field is a patient fact and classification continues («Ingreso en hospital», «¿Derivado a centro de salud?» → Visit). *(Amended 2026-10)*
- **FR-004-07** — The internal groups MUST be resolved as follows: social determinant of health → Observation (`r_sdoh`); lifestyle → Measurement if numeric, Observation otherwise; intensity rating (gravedad, EVA, *severity*, *VAS*…) → Measurement (`r_severity`); scale or questionnaire → Measurement if the value is numeric (total score, `r_scale_score`) and Observation if categorical (item, `r_scale_item`); any other term → its OMOP domain (`r_keyword`). In a numeric field (integer, decimal or slider), a scale or intensity term beats any other clinical term even if it is shorter: «Pain (VAS)», «Dolor (EVA)» or «Pain score» with a number are Measurement with the number as the value, not Condition. *(Amended 2026-10)*
- **FR-004-08** — A categorical field with 5 or more choices whose labels, all but at most one, start with a number MUST be proposed as a rating scale: Measurement, `high` confidence, reason `r_rating_scale(<n>)`, before the keywords. Labels are checked, never codes.
- **FR-004-09** — A field with date validation MUST be proposed as Person, Death or Visit with `high` confidence (`r_date_semantic`) if its text matches those domains; otherwise as Condition with `medium` confidence (`r_date_fact`) when the label matches a Condition term **and** talks about diagnosis or onset (`diagnos`, `onset`, «inicio de», «inicio del»); and otherwise as `DateAnchor` (spec 003). The start date of a treatment is still an anchor. *(Amended 2026-10)*
- **FR-004-10** — With no signal at all, the field MUST be proposed as Measurement if numeric and Observation otherwise, with `low` confidence and reason `r_fb_num_unit`, `r_fb_num`, `r_fb_cat` or `r_fb_text`. With a signal, a free-text field drops to `low` confidence and adds `r_sfx_freetext` (unless it is excluded); a categorical Condition adds `r_sfx_choices`; the unit adds `r_sfx_unit`; a non-excluded identifier adds `r_sfx_phi`.
- **FR-004-11** — Before classifying, the phrases that place the data item in time without describing it MUST be removed («en el momento de la visita», «durante la visita», «medido en la visita», «al ingreso», «at the visit», «on admission», «during follow up»… and, after the amendment, «al alta», «en el alta», «at discharge», «on discharge», «desde la última consulta», «en la última consulta», «since last visit», «en la radiografía de tórax», «on chest x ray», «at 90 days»…), so they do not drag the field into Visit. *(Amended 2026-10)*
- **FR-004-12** — The proposed domain MUST NOT depend on the interface language: the same field in Spanish and in English goes to the same domain, and the profile with the module's translator gives the same domains as without it. Every proposal has a domain from `CdmModel::validDomains()`, a `high`, `medium` or `low` confidence and a non-empty reason.
- **FR-004-13** — The lexicon MUST recognise the frequent clinical terms of real projects, in both languages: symptoms and conditions such as fatiga/*fatigue*, cansancio, hipotiroidismo, anemia, neumonía, fibrilación auricular, insuficiencia cardiaca, sepsis, COVID-19, preeclampsia; analytes such as NT-proBNP, BNP, troponina, PCR / proteína C reactiva, ferritina, dímero D, lactato, procalcitonina, INR; scales such as NIHSS, DAS28, Rankin, Glasgow, APGAR, SOFA, NYHA, ECOG; drugs such as prednisona, levotiroxina, salbutamol, warfarina, metotrexato, biologics, antithrombotics and antiplatelets; and procedures such as ventilation, intubation and mammography. *(Added 2026-10)*
- **FR-004-14** — A `notes` field whose label talks about a clinical note (evolución, nota, notas, informe, *progress note*, *clinical note*, *discharge summary*, curso clínico) MUST be proposed as Note with `medium` confidence and reason `r_note`; a short-text field with the same label must not. *(Added 2026-10)*
- **FR-004-15** — The classifier MUST apply `TextNormalizer::americanize()` to the label and the name before normalising them, so that British spelling (*haemoglobin*, *anaemia*, *oedema*, *dyspnoea*, *ischaemic*, *glycaemia*, *leukaemia*, *diarrhoea*, *paediatric*, *centre*…) gives the same domain and reason as American spelling; the lexicon includes leucemia/*leukemia* and diarrea/*diarrhea*. `TextNormalizer::americanize()` MUST convert British spelling into the American spelling of the OHDSI vocabularies (`haem`→`hem`, `anaem`→`anem`, `oedem`→`edem`, `oestr`→`estr`, `paediat`→`pediat`, `tumour`→`tumor`, `centre`→`center`, `ischaem`→`ischem`, `leukaem`→`leukem`, `foet`→`fet`, `oesoph`→`esoph`, `glycaem`→`glycem`, `diarrhoea`→`diarrhea`, `dyspnoea`→`dyspnea`) for vocabulary searches. *(Added 2026-10)*

## Non-functional requirements

- **NFR-004-01** — The benchmark `tests/fixtures/benchmark.csv` has 120 fields with the columns `lang, set, field_name, field_type, label, choices, validation, identifier, expected`; `set` is `dev` or `holdout`, `lang` is `es` or `en`, both sets have fields in both languages and `expected` accepts several valid domains separated by `|`.
- **NFR-004-02** — Accuracy on the development set ≥ 95 %.
- **NFR-004-03** — Accuracy on the holdout set ≥ 70 %. The holdout set is never used to tune the lexicon or the rules, and its failures are shown in the test output.
- **NFR-004-04** — After the 2026-10 amendment, the holdout set does not get worse (≥ 26/27) and the accuracy on the 10 evaluation projects of spec 018 is ≥ 184/186 (measured by spec 018 with its evaluation projects).

## Acceptance criteria

```gherkin
Scenario: specificity
  Given a yesno field «Pérdida de peso»
  When it is classified
  Then Condition is proposed, because «perdida de peso» is longer than «peso»

Scenario: temporal context
  Given a yesno field «¿Refiere dolor en el momento de la visita?»
  When it is classified
  Then Condition is proposed, not Visit

Scenario: an entity being named
  Given a dropdown «Centro de Salud»
  When it is classified
  Then NotMapped is proposed with reason r_structural

Scenario: entity in the middle of the label
  Given a yesno field «Ingreso en hospital»
  When it is classified
  Then Visit is proposed

Scenario: diagnosis date (amendment)
  Given a field «Date of RA diagnosis» with date_ymd validation
  When it is classified
  Then Condition is proposed with medium confidence

Scenario: scale score (amendment)
  Given a field «NIHSS score on admission» with integer validation
  When it is classified
  Then Measurement is proposed

Scenario: discharge context phrase (amendment)
  Given a dropdown «Antithrombotic at discharge» with choices Aspirin and None
  When it is classified
  Then Drug is proposed, not Visit

Scenario: benchmark
  Given tests/fixtures/benchmark.csv
  When every field is classified
  Then accuracy on dev is ≥ 95 % and on holdout ≥ 70 %
```

## Out of scope

- Choosing the specific `concept_id` (the person does it in the editor, spec
  007, with help from the vocabulary, spec 014).
- The value strategy and which choices emit a row (specs 003, 006 and 009).
- Classifying from the project's data instead of from the dictionary.
- Languages other than Spanish and English.
- CARE_SITE and PROVIDER tables built from the excluded entities (spec 010).

## Manual verification

There are no `[manual]` requirements.

## Amendments

| Date | Requirement | Change | Prompted by |
|---|---|---|---|
| 2026-10 | FR-004-06 | Modified: the prefixes «responsible», «treating», «attending» and «lead» do not prevent recognising that the label names a clinician («Responsible rheumatologist» → NotMapped). | Spec 018: roles with a prefix proposed as Observation |
| 2026-10 | FR-004-07 | Modified: the numeric score of a scale (NIHSS, DAS28, Barthel) is Measurement; the initial version of the requirement sent every scale to Observation. | Spec 018: total scores proposed as Observation |
| 2026-10 | FR-004-09 | Modified: a diagnosis or disease onset date is the Condition fact, dated with its own value, not a date anchor. | Spec 018: diagnosis dates proposed as DateAnchor |
| 2026-10 | FR-004-11 | Modified: context phrases for discharge, last consultation, chest X-ray and 90-day follow-up are added. | Spec 018: «at discharge», «al alta», «desde la última consulta» dragged fields into Visit |
| 2026-10 | FR-004-13 | Added: frequent clinical terms missing from the lexicon (fatiga, NT-proBNP, PCR, NIHSS, DAS28, prednisona, hipotiroidismo, anemia, ventilación…). | Spec 018: frequent terms with no signal → catch-all |
| 2026-10 | FR-004-14 | Added: progress notes and reports of type `notes` go to Note. | Spec 018: long notes proposed as Observation |
| 2026-10 | FR-004-15 | Added: British spelling (`americanize()` applied by the classifier and by the vocabulary). | Spec 018: British projects with *haemoglobin*, *oedema*, *anaemia* |
| 2026-10 | NFR-004-04 | Added: the holdout does not get worse (26/27) and the 10 evaluation projects go from 154/186 (82.8 %) to 184/186 (98.9 %). | Spec 018 |
