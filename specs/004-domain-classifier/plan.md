# Plan 004 — Bilingual domain classifier

## Technical summary

The classifier lives inside `DictionaryProfiler::profileField()` (spec 003),
after the structural rules. It works on two «haystacks» of normalised text
surrounded by spaces: the label alone and «variable name + label», both
without the temporal context phrases. Before normalisation, the text goes
through `TextNormalizer::americanize()`. A `$keywords` lexicon groups the terms
by OMOP domain or by internal group (administrative, structural, social
determinants, lifestyle, intensity, scale). `matchKeywords()` returns the
longest match among the requested groups, and `resolveGroup()` translates the
group into the OMOP domain according to the nature of the value. Exclusions
(administrative and structural) are searched separately and first, so that
specificity does not compete with them.

`TextNormalizer` provides normalisation (`normalize`, `tokens`) and the
conversion of British spelling (`americanize`), which are applied by the
classifier itself when building the haystacks, by the vocabulary (spec 014)
and by the editor's Athena search.

## Files

| File | Action | Responsibility |
|---|---|---|
| `classes/DictionaryProfiler.php` | Modify | Lexicon, context phrases, `matchKeywords`, `resolveGroup`, `namesEntity`, date, note, scale and fallback rules |
| `classes/TextNormalizer.php` | Modify | `normalize`, `tokens`, `booleanQuery`, `americanize` |
| `lang/*.ini` | Modify | `r_*` reasons |
| `tests/fixtures/benchmark.csv` | Create | Benchmark (120 fields, dev/holdout) |
| `tests/specs/004_domain_classifier.test.php` | Create | Tests for this spec |

## Design

### Lexicon

```php
private $keywords = [
    self::G_ADMIN      => ['historia clinica', 'nhc', 'iniciales', 'telefono', 'direccion',
                           'consentimiento', 'record id', 'patient name', 'mrn', ...],
    self::G_STRUCTURAL => ['centro de salud', 'centro', 'hospital', 'servicio', 'unidad',
                           'medico responsable', 'clinic', 'ward', 'attending', 'physician',
                           'rheumatologist', 'surgeon', ...],
    self::D_PERSON     => ['sexo', 'fecha de nacimiento', 'edad', 'sex', 'date of birth', 'ethnic*', ...],
    self::D_DEATH      => ['fallec*', 'exitus', 'death', 'died', ...],
    self::D_VISIT      => ['visita', 'consulta', 'ingreso', 'hospitaliza*', 'derivado a',
                           'visit', 'admission', 'discharge', 'admitted', ...],
    self::G_SDOH       => ['nivel de estudios', 'situacion laboral', 'domicilio', 'educational level', ...],
    self::G_LIFESTYLE  => ['tabaquismo', 'fumador', 'cigarrillo*', 'smoking', 'alcohol', ...],
    self::G_SEVERITY   => ['intensidad', 'gravedad', 'eva', 'severity', 'vas', ...],
    self::G_SCALE      => ['escala', 'cuestionario', 'puntuacion', 'score', 'phq', 'nihss', 'das28', ...],
    self::D_DRUG       => ['medicament*', 'tratamiento', 'drug*', 'prednisona', 'antithrombotic*', ...],
    self::D_PROCEDURE  => ['cirugia*', 'biopsia', 'ventilacion', 'mammography', ...],
    self::D_SPECIMEN   => ['muestra', 'sample', 'swab', ...],
    self::D_DEVICE     => ['dispositivo', 'marcapasos', 'pacemaker', 'stent', ...],
    self::D_MEASUREMENT=> ['peso', 'tension arterial', 'glucosa', 'nt probnp', 'pcr', 'hemoglobin', ...],
    self::D_OBSERVATION=> ['edad al diagnostico', 'age at diagnosis', 'treatment outcome', ...],
    self::D_CONDITION  => ['diagnos*', 'dolor', 'fiebre', 'perdida de peso', 'fatiga', 'anemia', 'leukemia', ...],
];
```

Terms are lower case, without accents, with spaces instead of hyphens
(«NT-proBNP» → `nt probnp`) and in American spelling («hemoglobin», not
«haemoglobin»), because they are compared with americanised, normalised text.
The `*` suffix marks a stem.

### Matching

```php
// $haystack = ' ' . normalize(text) . ' ' without context phrases
private function matchKeywords(string $haystack, array $domains): ?array
// for each term: pattern ' stem' if it ends in '*', ' term ' otherwise;
// the one with the largest strlen(needle) wins; at equal length, the first one
// found (order of $domains). Returns ['domain' => ..., 'term' => ..., 'priority' => ...]
```

Example: «Pérdida de peso» → haystack `' perdida de peso '`; `peso`
(Measurement, 4) and `perdida de peso` (Condition, 15) both match → Condition,
`high` confidence (term with a space).

### Semantic flow of profileField() (after the rules of spec 003)

1. **Date** (`date*` validation):
   `matchKeywords(label, [Person, Death, Visit]) ?? matchKeywords(full, …)`
   → that domain, `high`, `r_date_semantic(<domain>|<term>)`.
   Otherwise: `matchKeywords(label, [Condition])` and
   `preg_match('/\b(diagnos|onset|inicio de|inicio del)/', label)` →
   Condition, `medium`, `r_date_fact(<term>)`. Otherwise, DateAnchor.
2. **Rating scale** (categorical and `looksLikeRatingScale`) →
   Measurement, `high`, `r_rating_scale(<n>)`.
3. **Exclusions**: `matchKeywords(label, [admin, structural]) ?? (full)`;
   if nothing matches and the normalised label is in `$adminLabels`, admin. A
   structural match only counts if `namesEntity()`. → NotMapped, `high`,
   `r_admin` or `r_structural`.
4. **Clinical**: `matchKeywords(label, $clinical) ?? matchKeywords(full, $clinical)`.
   If the field is numeric and a `_severity` or `_scale` term matches in the
   same haystack, that term replaces the winner («Pain (VAS)» → `vas`,
   Measurement).
5. **Note**: type `notes` and the label matches
   `\b(evolucion|nota|notas|informe|progress note|clinical note|clinical notes|discharge summary|clinical course|curso clinico)\b`
   → Note, `medium`, `r_note`.
6. **No signal** → fallback (FR-004-10).
7. `resolveGroup()`, confidence by the number of words in the term, and
   reason suffixes.

### namesEntity()

```php
if (preg_match('/[?¿]/u', $rawLabel)) return false;
$t = preg_replace('/^(nombre de la|nombre del|nombre de|name of the|name of|tipo de|type of|'
                . 'responsible|treating|attending|lead)\s+/', '', trim($haystack));
$startsWith = fn(string $x) => strpos($x . ' ', rtrim($term, '*') . ' ') === 0
    || (substr($term, -1) === '*' && strpos($x, rtrim($term, '*')) === 0);
// with the prefix removed and without removing it: the prefix can itself be
// the term that matched («Attending physician» matches 'attending')
return $startsWith($t) || $startsWith(trim($haystack));
```

| Label | Term | Names it? | Result |
|---|---|---|---|
| Centro de Salud | centro de salud | yes | NotMapped |
| Nombre del centro | centro | yes (prefix) | NotMapped |
| Responsible rheumatologist | rheumatologist | yes (prefix) | NotMapped |
| Attending physician | attending | yes (without removing the prefix) | NotMapped |
| Ingreso en hospital | hospital | no | Visit |
| ¿Derivado a centro de salud? | centro de salud | no (question) | Visit |

### resolveGroup()

| Group | Numeric | Other |
|---|---|---|
| `_sdoh` | Observation `r_sdoh` | Observation `r_sdoh` |
| `_lifestyle` | Measurement `r_lifestyle_num` | Observation `r_lifestyle_cat` |
| `_severity` | Measurement `r_severity` | Measurement `r_severity` |
| `_scale` | Measurement `r_scale_score` | Observation `r_scale_item` |
| OMOP domain | that domain `r_keyword` | that domain `r_keyword` |

### Context phrases

`$contextPhrases` (normalised) are removed with
`str_replace(' ' . $phrase . ' ', ' ', $haystack)`. Long phrases before the
short ones they contain («en la radiografia de torax» after «en la
radiografia» does not matter: each one is removed if it appears whole).

### TextNormalizer

```php
public static function normalize(string $text): string      // 'Tensión ARTERIAL-sistólica' → 'tension arterial sistolica'
public static function tokens(string $text, int $min = 3): array
public static function booleanQuery(string $text, int $min = 3): string   // '+diabet* +mellit*'
public static function tooShortForFulltext(string $text, int $min = 3): bool
public static function americanize(string $text): string    // 'Haemoglobin' → 'Hemoglobin'
```

### Benchmark

```
lang,set,field_name,field_type,label,choices,validation,identifier,expected
es,dev,peso,text,Peso (kg),,number,,Measurement
es,dev,fecha_diag_tb,text,Fecha de diagnóstico de tuberculosis,,date_ymd,,Condition|DateAnchor
en,holdout,...
```

120 rows: 93 `dev` (49 es, 44 en) and 27 `holdout` (15 es, 12 en). The test
profiles each row (with `record_id_field` for `record_id`/`study_id`) and
counts a hit if the proposed domain is among those in `expected`.

## Decisions and rejected alternatives

- **Explainable rules and lexicon, not a statistical model.** Every proposal
  says which term decided it and the curator can dispute it. *Discarded:* a
  trained classifier, because it does not explain itself, needs labelled
  training data and a run-time dependency.
- **Specificity by term length.** Simple and predictable. *Discarded:* «the
  first domain in the order wins», which made «Pérdida de peso» (weight loss)
  a measurement.
- **Exclusions outside the specificity competition.** Excluding an identifier
  is a firm decision. *Discarded:* treating them as one more domain, because
  «residencia» (10 letters) beat «direccion» (9).
- **The entity is recognised by the shape of the label.** *Discarded:*
  excluding any label containing «hospital», which hid admissions.
- **When in doubt, do not exclude.** A structural field proposed as
  Observation costs one click; an excluded fact hides a data item.
- **British spelling normalised with `americanize()` in the classifier.** The
  lexicon only needs the American spelling and any new term works for both.
  *Discarded:* duplicating every term in the lexicon, because one is always
  missing (*leukaemia*, *diarrhoea*).
- **Untouchable holdout set.** Tuning while looking at it would invalidate the
  measurement.

## Testing strategy

`tests/specs/004_domain_classifier.test.php`:

- Unit cases per rule with `classify()` (domain) and a `$row` closure that
  returns the whole row to check confidence and reason (without a translator,
  the reason is `key(args)` and is compared exactly).
- Spanish/English pairs and comparison of the test project profile with and
  without a translator (FR-004-12).
- Amendment cases taken from the error causes found in spec 018.
- Benchmark: format, composition, accuracy per set and a list of failures in
  the output (not in `--quiet` mode).

## Constitution check

- **1 and 4:** the proposal never approves or overwrites what is saved (spec
  006 guarantees it); here we only propose.
- **13:** identifiers and contact details are out by default.
- **21:** bilingual lexicon, independent of the interface language.
- **22:** every proposal comes with its reason and its confidence.

## Risks

- A new term can steal fields from another domain by being longer: always run
  the full benchmark and compare the `dev` failures.
- `americanize()` replaces stems without word boundaries (`haem`, `centre`…):
  a Spanish word containing them would change; there is none in the benchmark
  or in the 10 evaluation projects.
- British spellings that `americanize()` does not cover (*dyslipidaemia*,
  *hyperlipidaemia*) stay in the lexicon in both forms.
- A label whose only Visit term is inside a context phrase («Fecha de la
  visita», visit date) loses that signal and, if it is a date, stays as
  DateAnchor; the benchmark accepts both targets.
