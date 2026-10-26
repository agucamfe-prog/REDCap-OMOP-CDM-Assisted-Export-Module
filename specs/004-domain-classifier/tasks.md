# Tasks 004 — Bilingual domain classifier

Before starting: read `constitution.md`, `AGENTS.md`, and this folder's
`spec.md` and `plan.md`. Do the tasks in order. Every `[T]` must end with the
test **red**; the following `[I]` turns it green.

Run only this spec's tests: `php tests/run.php 004`

| Legend | |
|---|---|
| `[T]` | Write a test (red) |
| `[I]` | Implement (green) |
| `[R]` | Refactor, document or translate |
| `[P]` | Can be done in parallel with the previous task |

---

### T004-01 [T] Normalisation and whole-word matching

- **Requirements:** FR-004-01, FR-004-02
- **Files:** `tests/specs/004_domain_classifier.test.php`
- **What to do:** define `$row = fn(...) => (new DictionaryProfiler())->profile([...])['x']`
  (see the plan). Check:
  `TextNormalizer::normalize("  ¿Tensión  ARTERIAL-sistólica? (mmHg) ") === 'tension arterial sistolica mmhg'`;
  `TextNormalizer::tokens('La tos y la fiebre, FIEBRE', 3) === ['tos', 'fiebre']`;
  «TENSIÓN-ARTERIAL sistólica» (integer) → reason `r_keyword(tension arterial)`;
  «Situación laboral» (dropdown) → reason `r_sdoh(situacion laboral)` (not `lab`);
  «Diagnosticado de EPOC» (yesno) → reason `r_keyword(diagnos*)r_sfx_choices`.
- **Done when:** they fail because there is no lexicon and no `matchKeywords`.
- **Size:** S

### T004-02 [I] TextNormalizer and matchKeywords()

- **Requirements:** FR-004-01, FR-004-02
- **Files:** `classes/TextNormalizer.php`, `classes/DictionaryProfiler.php`
- **What to do:** complete `TextNormalizer` with `tokens()`, `booleanQuery()`
  and `tooShortForFulltext()`. In the profiler, create `$keywords` with a few
  terms per domain (those of the tests) and `matchKeywords($haystack, $domains)`
  as in the plan. In `profileField()`, build the two haystacks
  `' ' . TextNormalizer::normalize($label) . ' '` and
  `' ' . TextNormalizer::normalize($fieldName . ' ' . $label) . ' '`.
- **Hints:** the space on both sides of the haystack is what turns `strpos`
  into a whole-word search. For stems the pattern has no trailing space.
- **Done when:** `php tests/run.php 004` is green for FR-004-01 and 02.
- **Size:** M

### T004-03 [T] Label first, specificity and confidence

- **Requirements:** FR-004-03, FR-004-04
- **Files:** `tests/specs/004_domain_classifier.test.php`
- **What to do:** `classify('Peso', 'text', 'number', '', 'peso_visita') === 'Measurement'`;
  `classify('', 'yesno', '', '', 'visita_extra') === 'Visit'`;
  `classify('Pérdida de peso', 'yesno') === 'Condition'`; «Tensión arterial
  sistólica (mmHg)» → `high` confidence; «Peso» → `medium`.
- **Done when:** «peso_visita» fails (it comes out as Visit) or «Pérdida de
  peso» fails.
- **Size:** S

### T004-04 [I] Domain order, length and confidence

- **Requirements:** FR-004-03, FR-004-04
- **Files:** `classes/DictionaryProfiler.php`, `lang/*.ini`
- **What to do:** add `$order` (plan) and `$clinical` (the order without
  administrative and structural). `$hit = matchKeywords(label, $clinical) ?? matchKeywords(full, $clinical)`.
  Confidence: `strpos($hit['term'], ' ') !== false ? 'high' : 'medium'`.
  Reason `r_keyword(<term>)` (key in both `.ini` files: «Coincide con '{0}' en
  el nombre o la etiqueta del campo» / «Matches '{0}' in the field name or
  label»).
- **Hints:** compare lengths with a strict `>`: that way, at equal length, the
  first one in `$order` wins.
- **Done when:** `php tests/run.php 004` is green.
- **Size:** S

### T004-05 [T] Administrative fields and health system entities

- **Requirements:** FR-004-05, FR-004-06
- **Files:** `tests/specs/004_domain_classifier.test.php`
- **What to do:** «Teléfono de contacto», «Patient name», «¿Firmó el
  consentimiento informado?» → NotMapped `high`; «Dirección de residencia» →
  reason `r_admin(direccion)`; «Nombre» and «Full name» → NotMapped; «Nombre
  del medicamento» → Drug. «Centro de Salud» (dropdown) → reason
  `r_structural(centro de salud)`; «Clinic», «Nombre del centro», «Tipo de
  servicio» → NotMapped; «Ingreso en hospital», «Admitted to hospital» and
  «¿Derivado a centro de salud?» → Visit.
- **Done when:** they fail because there are no exclusion groups.
- **Size:** S

### T004-06 [I] Exclusions: G_ADMIN, adminLabels, G_STRUCTURAL and namesEntity()

- **Requirements:** FR-004-05, FR-004-06
- **Files:** `classes/DictionaryProfiler.php`, `lang/*.ini`
- **What to do:** add the two groups to the lexicon and `$adminLabels`
  (`nombre`, `name`, `apellido`, `apellidos`, `nombre completo`, `full name`,
  `surname`, `nombre y apellidos`). Before the clinical domains:
  `$exclusion = matchKeywords(label, [G_ADMIN, G_STRUCTURAL]) ?? matchKeywords(full, …)`;
  if it is null and `trim(label)` is in `$adminLabels`, administrative; if it
  is structural and `!namesEntity(...)`, it is cancelled. Implement
  `namesEntity()` with the prefixes «nombre de la/del/de», «name of (the)»,
  «tipo de», «type of». Reasons `r_admin` and `r_structural`.
- **Hints:** «nombre» (name) cannot be an administrative term: it would match
  in «Nombre del medicamento» (drug name). That is why it goes in
  `$adminLabels` (exact label).
- **Done when:** `php tests/run.php 004` is green.
- **Size:** M

### T004-07 [T] Groups, scales, dates, fallback and context

- **Requirements:** FR-004-07, FR-004-08, FR-004-09, FR-004-10, FR-004-11
- **Files:** `tests/specs/004_domain_classifier.test.php`
- **What to do:**
  - Groups: «Educational level» → `r_sdoh(educational level)`; «Fumador» →
    Observation; «Cigarrillos al día» (integer) → Measurement; «Intensidad del
    dolor» → `r_severity(intensidad)`; «NIHSS» radio with 3 choices →
    `r_scale_item(nihss)`; «Biopsia» Procedure, «Swab sample» Specimen,
    «Pacemaker» Device, «Died» Death, «Age at diagnosis» Observation.
  - Scale: radio «Dolor» `0, 0 Sin dolor | 1, 1 | … | 5, 5 Máximo` →
    Measurement `high` `r_rating_scale(6)`; «Nivel» with Bajo…Extremo →
    Observation.
  - Dates: «Fecha de nacimiento» Person, «Exitus fecha» Death, «Fecha de
    ingreso» → `r_date_semantic(Visit|ingreso)`; «Fecha de extracción» →
    DateAnchor.
  - Fallback: «Algo» integer → `r_fb_num`, `low`; dropdown → `r_fb_cat`; text
    → `r_fb_text`; «Nombre del medicamento» → `r_keyword(nombre del medicamento)r_sfx_freetext`,
    `low`; «Fever» yesno → `r_keyword(fever)r_sfx_choices`.
  - Context: «¿Refiere dolor en el momento de la visita?» → Condition; «Peso
    medido en la visita» → Measurement; «Discharge» → Visit.
- **Done when:** the groups, the meaningful dates and the context fail.
- **Size:** M

### T004-08 [I] resolveGroup(), scale, dates, fallback and stripContextPhrases()

- **Requirements:** FR-004-07, FR-004-08, FR-004-09, FR-004-10, FR-004-11
- **Files:** `classes/DictionaryProfiler.php`, `lang/*.ini`
- **What to do:** complete the lexicon for all groups; implement
  `resolveGroup()` with the plan's table (for now `_scale` → always
  Observation); the scale rule before the exclusions; the date rule with
  Person/Death/Visit before DateAnchor; `fallbackDomain()` and
  `fallbackReason()`; the suffixes `r_sfx_freetext`, `r_sfx_choices`,
  `r_sfx_unit` and `r_sfx_phi`; `$contextPhrases` (base list of FR-004-11) and
  `stripContextPhrases()` applied to both haystacks. Add all the `r_*`
  reasons to both `.ini` files.
- **Hints:** context phrases are written already normalised («ultima»,
  without the accent). Free text drops to `low` after the confidence is
  computed, not before.
- **Done when:** `php tests/run.php 004` is green.
- **Size:** L

### T004-09 [T] Benchmark and invariants

- **Requirements:** FR-004-12, NFR-004-01, NFR-004-02, NFR-004-03
- **Files:** `tests/specs/004_domain_classifier.test.php`
- **What to do:**
  - ES/EN pairs (Fiebre/Fever, Peso/Weight, Tabaquismo/Smoking,
    Biopsia/Biopsy, Fecha de nacimiento/Date of birth) go to the same domain.
  - `array_column(make_module()->profile(), 'suggested_domain', 'field_name')`
    equals that of the profiler without a translator.
  - Every row has a domain in `CdmModel::validDomains()`, a valid confidence
    and a non-empty reason.
  - Read `tests/fixtures/benchmark.csv`, validate the header and values,
    profile each row and compute the accuracy of `dev` and `holdout`; print
    the failures (unless `--quiet`); require 120 rows, both languages in both
    sets, `dev ≥ 95 %` and `holdout ≥ 70 %`.
- **Done when:** it fails because the benchmark does not exist.
- **Size:** M

### T004-10 [I] Build the benchmark and tune with the development set

- **Requirements:** NFR-004-01, NFR-004-02, NFR-004-03
- **Files:** `tests/fixtures/benchmark.csv`, `classes/DictionaryProfiler.php`
- **What to do:** gather 120 real fields (from your own or public projects,
  with no participant data) in Spanish and English, with their correct target
  or the defensible targets separated by `|`. Before looking at any results,
  randomly mark 27 as `holdout` (≈ 22 %), with both languages. Tune the
  lexicon only with the `dev` failures.
- **Hints:** **do not open the `holdout` failures to fix them**: if a holdout
  failure is fixed by looking at it, the measurement is no longer valid
  (AGENTS.md). If you are tempted, add a similar case to `dev`.
- **Done when:** `php tests/run.php 004` is green with `dev ≥ 95 %` and
  `holdout ≥ 70 %`.
- **Size:** L

### T004-11 [T] Amendment 2026-10: cases from the multi-project validation

- **Requirements:** FR-004-06, FR-004-07, FR-004-09, FR-004-11, FR-004-13, FR-004-14, FR-004-15, NFR-004-04
- **Files:** `tests/specs/004_domain_classifier.test.php`
- **What to do:** one test per error cause from spec 018:
  - prefixes: «Responsible rheumatologist», «Treating physician», «Lead
    surgeon», «Attending physician», «Attending doctor» (dropdown) → NotMapped;
  - numeric scale: «NIHSS score on admission» (integer), «Puntuación
    Barthel» (`r_scale_score(puntuacion)`), «DAS28» (number) → Measurement;
    «Pain (VAS)» integer → reason `r_severity(vas)`, and as a slider, «Pain
    score», «EVA», «Escala de dolor», «Dolor (EVA)» → Measurement; «Pain
    (VAS)» yesno is still Condition;
  - dates: «Date of RA diagnosis», «Fecha de diagnóstico de tuberculosis»
    (`r_date_fact(tuberculosis)`), «Date of stroke onset» (`medium`), «Fecha
    de inicio de síntomas» → Condition; «Fecha de inicio del tratamiento» and
    «Fecha de la fiebre» → DateAnchor;
  - context: «Antithrombotic at discharge» → Drug, «Pain at discharge»,
    «Dolor desde la última consulta», «Disnea al alta» → Condition;
  - lexicon: Fatiga, Fatigue, Hipotiroidismo, Anemia → Condition; «NT-proBNP
    (pg/mL)» (`r_keyword(nt probnp)r_sfx_unit(pg/mL)`), «PCR (mg/L)»,
    «Proteína C reactiva (mg/L)» → Measurement; Prednisona → Drug;
    «Ventilación mecánica», «Ventilation» → Procedure;
  - notes: «Daily progress note», «Notas de evolución», «Informe de alta»
    (`notes`) → Note; «Evolución» (`text`) is not Note; «Algo» (`notes`) →
    Observation;
  - British spelling: Haemoglobin, Oedema, Dyspnoea, Anaemia, Ischaemic heart
    disease, Glycaemia, Hypoglycaemia;
  - British/American pairs (Leukaemia/Leukemia, Diarrhoea/Diarrhea,
    Haemoglobin/Hemoglobin, Oedema/Edema, Paediatric/Pediatric asthma, Health
    centre/center) with the same reason; Leucemia and Diarrea → Condition;
  - `americanize()` of the 12-term sentence of FR-004-15;
  - benchmark holdout ≥ 26/27.
- **Done when:** the amendment cases fail.
- **Size:** M

### T004-12 [I] Amendment 2026-10: lexicon, diagnosis dates, notes, prefixes and scales

- **Requirements:** FR-004-06, FR-004-07, FR-004-09, FR-004-11, FR-004-13, FR-004-14, FR-004-15
- **Files:** `classes/DictionaryProfiler.php`, `lang/*.ini`
- **What to do:**
  1. Lexicon: add the terms of FR-004-13 (normalised: `nt probnp`,
     `proteina c reactiva`) and leucemia/`leukemia`, diarrea/`diarrhea`. Apply
     `TextNormalizer::americanize()` before `normalize()` when building the
     two haystacks, and keep in the lexicon only the American spelling of
     whatever `americanize()` already converts.
  2. `namesEntity()`: add `responsible|treating|attending|lead` to the prefix
     regex and check the start with and without the prefix removed (the
     prefix `attending` is also a term); add `physician`, `doctor`,
     `rheumatologist`, `cardiologist`, `neurologist`, `oncologist`, `surgeon`,
     `consultant`, `clinician` to the structural group.
  3. `resolveGroup()`: numeric `_scale` → Measurement `r_scale_score`. In
     `profileField()`, if the field is numeric and a `_severity` or `_scale`
     term matches in the winning haystack, that term replaces the winner.
  4. Dates: after Person/Death/Visit, the plan's `r_date_fact` rule.
  5. Notes: the `r_note` rule before the fallback.
  6. The new context phrases of FR-004-11.
  7. Reasons `r_scale_score`, `r_date_fact` and `r_note` in both `.ini` files.
- **Hints:** after each step run the full benchmark: a new term can steal
  `dev` fields. Trap: `attending` is a structural term **and** a prefix; if
  only the label without the prefix is checked, «Attending physician» no
  longer starts with `attending` and is not excluded. That is why the start
  is checked both ways. `americanize()` goes before `normalize()`: afterwards
  there is no upper case any more, but its patterns are case-insensitive and
  work just as well on the original text.
- **Done when:** `php tests/run.php 004` is green, `dev` has no new failures
  and `holdout` is at 26/27.
- **Size:** L

### T004-13 [R] americanize(), documentation and figures

- **Requirements:** FR-004-15, NFR-004-04
- **Files:** `classes/TextNormalizer.php`, `README.md`
- **What to do:** check that `americanize()` covers the 14 substitutions of
  FR-004-15 and that the vocabulary (spec 014) uses it when searching. In the
  README, *Scope* section: the benchmark table (dev 93/93, holdout 26/27 with
  its single failure shown) and the table of the two rounds of spec 018
  (154/186 → 184/186), and the three rules that explain the result (label
  first, specificity, context phrases). *What is not a fact* section: health
  system entities.
- **Done when:** someone else reviews it and the full `php tests/run.php` is
  green.
- **Size:** S

---

## Definition of done for the spec

- [ ] All `004` tests green and the rest of the suite without regressions.
- [ ] `php tests/run.php --trace 004` with no orphan requirements.
- [ ] New texts in `lang/Spanish.ini` and `lang/English.ini` with the same keys.
- [ ] README updated if anything visible to the user changes.
- [ ] Spec status changed to `Implemented`.
