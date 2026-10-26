# Tasks 014 — Vocabulary: validation and suggestions

Before you start: read `constitution.md`, `AGENTS.md`, and the `spec.md` and
`plan.md` in this folder. Do the tasks in order. Each `[T]` must end with the
test **red**; the following `[I]` turns it green.

Run only this spec's tests: `php tests/run.php 014`

| Legend | |
|---|---|
| `[T]` | Write a test (red) |
| `[I]` | Implement (green) |
| `[R]` | Refactor, document or translate |
| `[P]` | Can be done in parallel with the previous task |

---

### T014-01 [T] Index from CONCEPT.csv

- **Requirements:** FR-014-01
- **Files:** `tests/specs/014_vocabulary.test.php`, `tests/fixtures/vocab/CONCEPT.csv`
- **What to do:**
  1. Create `tests/fixtures/vocab/CONCEPT.csv` with the tab-separated Athena
     header (`concept_id concept_name domain_id vocabulary_id
     concept_class_id standard_concept concept_code valid_start_date
     valid_end_date invalid_reason`) and 51 concepts: LOINC measurements
     (3025315 Body weight, 3004501 Glucose…, 3000963 Hemoglobin…), SNOMED
     conditions, a non-standard ICD10CM code (45576876), a retired one
     (4000001, `invalid_reason` `D`), Meas Value (9191 Positive, 9189
     Negative), RxNorm, Gender and UCUM (9529 kg, 8840 mg/dL, 8751 mg/L, 8876
     mm[Hg]).
  2. In the test, `$tmp = sys_get_temp_dir() . '/omop_test_vocab_' . getmypid() . '.sqlite'`
     and `Vocabulary::buildIndex($csv, $tmp)` must return 51.
  3. Check with `new PDO('sqlite:' . $tmp)` that `concept` and `concept_fts`
     exist, that the `concept_fts` entry of 3000963 is
     `hemoglobin mass volume in blood` and that no `$tmp . '.tmp'` is left.
  4. A CSV with only three columns and a non-existent path throw
     `RuntimeException` (use `throws()`), and the previous index still works.
- **Done when:** the test fails with `Class "…\Vocabulary" not found`.
- **Size:** M

### T014-02 [I] `Vocabulary::buildIndex` and the command line

- **Requirements:** FR-014-01, NFR-014-02
- **Files:** `classes/Vocabulary.php`, `resources/build_vocab_index.php`
- **What to do:**
  1. `buildIndex()` opens the CSV, creates `<path>.tmp` with the schema from
     the plan, checks the 8 required columns by name (lower case) and inserts
     with two prepared statements (concept, and FTS with
     `TextNormalizer::normalize()`), with a `commit` every 50,000 rows and
     `$progress($n)`.
  2. At the end it creates `ix_code` and `rename()`s the `.tmp` to the real
     path.
  3. `build_vocab_index.php`: 404 if `PHP_SAPI !== 'cli'`; without two
     arguments, usage on `STDERR` and `exit(2)`; otherwise it prints
     `N concepts indexed in S s → path`.
  4. Add the call through `exec(PHP_BINARY …)` to the test: exit code 0 and
     "51 concepts indexed"; without arguments, exit code 2.
- **Hints:** `PRAGMA journal_mode = OFF; PRAGMA synchronous = OFF` multiplies
  the speed. Lines with fewer fields than the header are skipped.
- **Done when:** `php tests/run.php 014` is green.
- **Size:** M

### T014-03 [T] Sources, lookup and search

- **Requirements:** FR-014-02, FR-014-04, FR-014-05
- **Files:** `tests/specs/014_vocabulary.test.php`
- **What to do:**
  - `new Vocabulary(null, null)` is not configured; `source()` is
    `sqlite:<basename>` with an index and
    `webapi:https://atlas.example.org/WebAPI` with
    `https://atlas.example.org/WebAPI/`; the index wins if it exists.
  - `make_module()->vocabulary() === null`; with
    `$m->system = ['vocab-sqlite-path' => $tmp]` it returns the instance.
  - `lookup([3025315, 999])` returns 3025315 with the 8 keys and does not
    return 999; `lookup([0, -5]) === []`.
  - `search('body weight', 'Measurement')[0]['id'] === 3025315`;
    `search('diabetes', 'Condition')` includes 201820 and not 45576876;
    `search('retired test') === []`; limit and empty query.
- **Done when:** the new tests fail (methods do not exist).
- **Size:** S

### T014-04 [I] `lookup`, `search` and module configuration

- **Requirements:** FR-014-02, FR-014-04, FR-014-05, NFR-014-03
- **Files:** `classes/Vocabulary.php`, `OmopCdmExportModule.php`, `config.json`
- **What to do:**
  1. `configured()`, `source()`, a lazy `pdo()` (null if the file does not
     exist), `lookup()` with a cache (`false` for missing ones) and batches of
     500.
  2. `search()` with `tokens(..., 2)`, `"t"*` terms joined by spaces and the
     query from the plan (`standard_concept = 'S'`, not retired, optional
     domain, `bm25`, `length`).
  3. In the module, `vocabulary()` reads `vocab-sqlite-path`, `webapi-url`
     and `webapi-token` with `getSystemSetting()` and caches; `setVocabulary()`
     for the tests. Declare the four system settings in `config.json`
     (`webapi-token` of type `password`).
- **Hints:** the `MATCH` always goes as a parameter (`?`), never
  concatenated.
- **Done when:** `php tests/run.php 014` is green.
- **Size:** M

### T014-05 [T] UCUM and `unit_concept_id`

- **Requirements:** FR-014-06
- **Files:** `tests/specs/014_vocabulary.test.php`
- **What to do:** `$v->ucum('mg/L')['id'] === 8751`;
  `ucum('furlong/fortnight') === null`; with a vocabulary
  `unitConcept('mg/L') === 8751` and `unitConcept('mmHg') === 8876`; without
  a vocabulary `unitConcept('kg') === 9529`, `unitConcept('mg/L') === null`;
  after `curate()`, glucose with value 95 comes out with `unit_concept_id`
  8840.
- **Done when:** the new tests fail.
- **Size:** S

### T014-06 [I] `ucum()` and `unitConcept()`

- **Requirements:** FR-014-06
- **Files:** `classes/Vocabulary.php`, `OmopCdmExportModule.php`, `classes/Transformer.php`
- **What to do:** `ucum()` looks up `vocabulary_id = 'UCUM' AND concept_code = ?`
  (in the WebAPI, a search with `VOCABULARY_ID: ['UCUM']` and the exact code)
  and caches with the key `ucum:<code>`. `unitConcept()` goes through
  `CdmModel::ucumCode()`, tries the vocabulary and falls back to
  `UCUM_BUILTIN`. In the transformer, `$spec['_unit_concept']` is computed
  once per field and only written if there is a `value_as_number`.
- **Done when:** `php tests/run.php 014` is green.
- **Size:** S

### T014-07 [T] Concept warnings and vocabulary down

- **Requirements:** FR-014-07, FR-014-08, FR-014-09
- **Files:** `tests/specs/014_vocabulary.test.php`
- **What to do:** with the curated project and the vocabulary injected:
  - `concept_id` 999 → `concept_not_found`, `warn`, `!MappingAdvisor::blocks()`.
  - 3025315 with "Body wieght" → `concept_name_mismatch`; with "body weight",
    not.
  - 45576876 → `concept_not_standard` and `concept_wrong_domain`; 4000001 →
    `concept_invalid`.
  - A `sex` option with 3025315 → `concept_wrong_domain` (Gender is
    expected); `bac` (value as concept) without `concept_wrong_domain`.
  - `new Vocabulary('/no/such/omop.sqlite', null)`: `lookup` and `search`
    return `null`; the advisor gives `vocab_unavailable` (info), does not
    block and gives the same name-shape warnings (`concept_name_is_label`,
    `no_concept_name`) as without a vocabulary; `unitConcept('kg') === 9529`;
    the export comes out without STCM and with
    `stats['stcm_unknown_concept'] > 0`. A WebAPI at `http://127.0.0.1:9`
    returns `null` and sets `lastError()`.
- **Done when:** the new tests fail.
- **Size:** M

### T014-08 [I] `conceptInfo()` and `checkVocabulary()`

- **Requirements:** FR-014-07, FR-014-08, FR-014-09, NFR-014-02
- **Files:** `OmopCdmExportModule.php`, `classes/MappingAdvisor.php`, `lang/Spanish.ini`, `lang/English.ini`
- **What to do:**
  1. `conceptInfo(int $id)`: `false` if there is no vocabulary or `lookup`
     gives `null`; on the first call it requests all the concepts of the
     mapping at once (fields and options) and stores them in `$conceptCache`.
  2. In the advisor, `checkVocabulary()` and `expectedDomain()` as in the
     plan's table; it returns `true` only if the vocabulary responded, and
     then `checkConceptName()` (spec 008) does not run; if there is no
     vocabulary or it does not respond, it returns `false` and it runs.
  3. Texts `adv_vocab_unavailable`, `adv_concept_not_found`,
     `adv_concept_invalid`, `adv_concept_not_standard`,
     `adv_concept_wrong_domain`, `adv_concept_name_mismatch` in both
     languages, each one saying what to do.
- **Hints:** the name is compared with `TextNormalizer::normalize()` and it is
  enough for one to contain the other ("Glucose" versus
  "Glucose [Mass/volume]…"). `http()` sets `lastError` to the `curl` error or
  `HTTP <code>`.
- **Done when:** `php tests/run.php 014` is green.
- **Size:** M

### T014-09 [T] SOURCE_TO_CONCEPT_MAP

- **Requirements:** FR-014-10
- **Files:** `tests/specs/014_vocabulary.test.php`
- **What to do:** with a vocabulary and `curate()`, index the rows by
  `source_code`: `weight` → 3025315, `REDCAP_P1`, `LOINC`, `2099-12-31`;
  `drug=1` → 1782521 `RxNorm` with description `Drug: Isoniazid`; there is no
  `comorb=3`. Without a vocabulary, the table is empty.
- **Done when:** the new tests fail.
- **Size:** S

### T014-10 [I] `buildSourceToConceptMap()`

- **Requirements:** FR-014-10
- **Files:** `classes/Transformer.php`, `classes/CsvExporter.php`, `lang/*.ini`
- **What to do:** if `vocabulary()` is `null`, it does nothing. Otherwise,
  for each approved field: a row for the field's concept and one per emitted
  option with a concept (strategies with options, and checkboxes).
  `target_vocabulary_id` comes from `conceptInfo()`; if it is not an array,
  `stats['stcm_unknown_concept']++` and there is no row. The report adds
  `rep_stcm_unknown` if the counter is > 0.
- **Hints:** `source_vocabulary_id` is `VARCHAR(20)` in the DDL: truncate with
  `substr(…, 0, 20)`.
- **Done when:** `php tests/run.php 014` is green.
- **Size:** S

### T014-11 [T] Suggestions, relaxed search and British spelling

- **Requirements:** FR-014-11, FR-014-14, FR-014-15
- **Files:** `tests/specs/014_vocabulary.test.php`
- **What to do:**
  - `search(athenaQuery('Weight (kg)', 'weight'), 'Measurement')[0]` is
    3025315; «Frecuencia cardiaca (lpm)» gives 3027018;
    `athenaQuery('Haemoglobin (g/dL)', 'hb', 'g/dL')` gives 3000963 first;
    `search('positive', 'Meas Value')[0]` is 9191.
  - `search('fasting glucose', 'Measurement')[0]['id'] === 3004501`;
    `search('weight loss')` gives only 4229881; `search('zzzz yyyy') === []`.
  - `TextNormalizer::americanize('Haemoglobin, anaemia, oedema')` →
    `hemoglobin, anemia, edema`; `search('haemoglobin', 'Measurement')`
    includes 3000963.
- **Done when:** at least the relaxed search and the British spelling fail.
- **Size:** S

### T014-12 [I] Relax to OR and `americanize()`

- **Requirements:** FR-014-14, FR-014-15
- **Files:** `classes/Vocabulary.php`, `classes/TextNormalizer.php`
- **What to do:** in `searchSqlite()`, iterate over
  `array_unique([implode(' ', $terms), implode(' OR ', $terms)])` and return
  the first non-empty rows. `americanize()` with the list from the plan
  (`haem`, `anaem`, `oedem`, `oestr`, `paediat`, `tumour`, `centre`,
  `ischaem`, `leukaem`, `foet`, `oesoph`, `glycaem`, `diarrhoea`,
  `dyspnoea`), applied in `searchSqlite()`, `searchWebapi()` and
  `athenaQuery()`.
- **Hints:** with a single word both queries are the same: `array_unique`
  avoids repeating it.
- **Done when:** `php tests/run.php 014` is green.
- **Size:** S

### T014-13 [I] WebAPI, wand and Control Center

- **Requirements:** FR-014-03, FR-014-12, FR-014-13
- **Files:** `classes/Vocabulary.php`, `pages/editor.php`, `pages/vocabulary.php`, `config.json`, `lang/*.ini`
- **What to do:**
  1. `http()` with `curl` (timeouts 10/5 s, `Authorization: Bearer` if there
     is a token), `lookupWebapi()`, `searchWebapi()` and `fromWebapi()`.
  2. In the editor, the `suggest` action as in the plan; the wand
     (`fa-magic`) only with `$vocabOn && $canEdit`; `$fillName` fills in the
     name when only the number is pasted; header `ed_vocab_on` /
     `ed_vocab_off`.
  3. `pages/vocabulary.php` in the Control Center (link in `config.json`),
     `SUPER_USER` only, with the paths, "Build the index" (POST with
     `redcap_csrf_token`, `confirm()` and `log()`) and the test search.
- **Done when:** the manual verification of FR-014-03, FR-014-12 and
  FR-014-13 in the spec passes in the development Docker environment.
- **Size:** L

### T014-14 [R] Documentation and translations

- **Requirements:** NFR-014-01
- **Files:** `README.md`, `resources/NOTICE.txt`, `lang/*.ini`
- **What to do:** a *Vocabulary (optional)* section in the README with the
  table of warnings, the two sources and the behaviour without a vocabulary;
  a licence notice in `NOTICE.txt`; check that the `voc_*`, `adv_concept_*`
  and `ed_vocab_*` keys exist in both `.ini` files.
- **Done when:** the `diff` of the keys of `lang/Spanish.ini` and
  `lang/English.ini` is empty.
- **Size:** S

---

## Definition of done for the spec

- [ ] All `014` tests green and the rest of the suite without regressions.
- [ ] `php tests/run.php --trace 014` with no orphan requirements.
- [ ] Manual verification of FR-014-03, FR-014-12 and FR-014-13 done and recorded.
- [ ] New texts in `lang/Spanish.ini` and `lang/English.ini` with the same keys.
- [ ] README updated (*Vocabulary* section).
- [ ] Spec status changed to `Implemented` (`Amended` after 018).
