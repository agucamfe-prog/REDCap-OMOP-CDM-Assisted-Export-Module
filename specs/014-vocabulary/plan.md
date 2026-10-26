# Plan 014 — Vocabulary: validation and suggestions

## Technical summary

A class with no REDCap dependencies, `Vocabulary`, wraps the two sources (an
SQLite FTS5 index built from `CONCEPT.csv`, or the ATLAS WebAPI over `curl`)
behind four operations: `lookup`, `search`, `ucum` and the static
`buildIndex`. All of them return `null` when the source does not respond, and
that `null` is the signal the rest of the module uses to degrade without
blocking.

The module creates the instance from the system settings
(`OmopCdmExportModule::vocabulary()`), offers a cached `conceptInfo()` for the
advisor and the transformer, and `unitConcept()` for UCUM. The advisor (spec
008) gains `checkVocabulary()`, which replaces the shape check of the name when
there is a vocabulary. The transformer generates `SOURCE_TO_CONCEPT_MAP`. The
editor adds the `suggest` action (the wand) and the Control Center gets the
page `pages/vocabulary.php`.

## Files

| File | Action | Responsibility |
|---|---|---|
| `classes/Vocabulary.php` | Create | Index, WebAPI, search, lookup, UCUM |
| `resources/build_vocab_index.php` | Create | Building the index from the command line |
| `classes/TextNormalizer.php` | Modify | `americanize()` (British → American spelling) |
| `OmopCdmExportModule.php` | Modify | `vocabulary()`, `setVocabulary()`, `conceptInfo()`, `unitConcept()` |
| `classes/MappingAdvisor.php` | Modify | `checkVocabulary()`, `expectedDomain()` |
| `classes/Transformer.php` | Modify | `buildSourceToConceptMap()`, `_unit_concept` |
| `classes/CsvExporter.php` | Modify | `rep_stcm_unknown` note in the report |
| `pages/vocabulary.php` | Create | Control Center: paths, status, build, try out |
| `pages/editor.php` | Modify | `suggest` action, wand, automatic name, status header |
| `config.json` | Modify | System settings and Control Center link |
| `lang/*.ini` | Modify | `voc_*`, `adv_concept_*`, `adv_vocab_unavailable`, `ed_vocab_*`, `ed_suggest_*` |
| `tests/fixtures/vocab/CONCEPT.csv` | Create | 51 test concepts (LOINC, SNOMED, RxNorm, UCUM, Gender…) |

## Design

### Index schema

```sql
CREATE TABLE concept (concept_id INTEGER PRIMARY KEY, concept_name TEXT, domain_id TEXT,
                      vocabulary_id TEXT, concept_class_id TEXT, standard_concept TEXT,
                      concept_code TEXT, invalid_reason TEXT);
CREATE VIRTUAL TABLE concept_fts USING fts5(name);   -- rowid = concept_id
CREATE INDEX ix_code ON concept (vocabulary_id, concept_code);   -- for UCUM
```

`concept_fts.name` stores `TextNormalizer::normalize(concept_name)`:
`Hemoglobin [Mass/volume] in Blood` → `hemoglobin mass volume in blood`. That
way the search does not depend on the server's collation.

### Public signatures

```php
// classes/Vocabulary.php
public function __construct(?string $sqlitePath, ?string $webapiUrl, ?string $webapiToken = null)
public function configured(): bool          // an index or a WebAPI is configured
public function source(): string            // 'sqlite:omop.sqlite' | 'webapi:https://…' | ''
public function lastError(): ?string
public function lookup(array $ids): ?array  // id => concept; null if it does not respond
public function search(string $query, ?string $domain = null, int $limit = 10): ?array
public function ucum(string $code): ?array
public static function buildIndex(string $conceptCsv, string $sqlitePath, ?callable $progress = null): int

// OmopCdmExportModule.php
public function vocabulary(): ?Vocabulary
public function setVocabulary(?Vocabulary $v): void   // for the tests
public function conceptInfo(int $id)                  // array | null (does not exist) | false (no vocabulary, or it is down)
public function unitConcept(?string $unit): ?int
```

A concept, whether it comes from the index or from the WebAPI, always has
this shape:

```php
['id' => 3025315, 'name' => 'Body weight', 'domain' => 'Measurement', 'vocabulary' => 'LOINC',
 'class' => 'Clinical Observation', 'standard' => 'S', 'code' => '29463-7', 'invalid' => '']
```

In the WebAPI, `INVALID_REASON = 'V'` (valid) is translated to `''`.

### Search

1. `tokens(americanize($query), 2)` → `['fasting', 'glucose']`.
2. First `"fasting"* "glucose"*` (all of them, as prefixes); if there are no
   rows, `"fasting"* OR "glucose"*`.
3. `WHERE standard_concept = 'S' AND invalid_reason empty [AND domain_id = ?]
   ORDER BY bm25(concept_fts), length(concept_name) LIMIT n`.

### Suggestions (the editor's `suggest` action)

```
field label (or the option's; if the option is «Sí»/"Yes", the field's)
  └─ athenaQuery(label, field, unit if Measurement)   «Hemoglobina (g/dL)» → "hemoglobin mass volume"
       └─ search(q, athenaDomain(dom) | 'Meas Value', 8)
            └─ no results and a domain was given → search(q, null, 8)
```

Response: `{ok, query, domain, results: [concept…]}`; 400 `ed_vocab_none`,
502 `ed_vocab_error`.

### Validation in the advisor

`checkVocabulary()` gathers `[concept_id, pasted name, expected domain]` for
the field and its emitted options and calls `conceptInfo()`:

| `conceptInfo()` | Result |
|---|---|
| `false` | `vocab_unavailable` (info), checking stops and `checkVocabulary()` returns `false`: `checkConceptName()` (spec 008) applies as without a vocabulary |
| `null` | `concept_not_found` (warn) |
| `invalid ≠ ''` | `concept_invalid` (warn) |
| `standard ≠ 'S'` | `concept_not_standard` (warn) |
| domain ≠ expected | `concept_wrong_domain` (warn) |
| normalised names, neither contains the other | `concept_name_mismatch` (warn) |

`expectedDomain()`: Person → `Gender`/`Race`/`Ethnicity` depending on the
configured field (any other Person field: none); Death → `Condition`; Note →
none; everything else, its own domain. Options with "value as concept" do not
require a domain.

### SOURCE_TO_CONCEPT_MAP

```php
['source_code' => 'drug=1', 'source_concept_id' => 0, 'source_vocabulary_id' => 'REDCAP_P1',
 'source_code_description' => 'Drug: Isoniazid', 'target_concept_id' => 1782521,
 'target_vocabulary_id' => 'RxNorm', 'valid_start_date' => '2026-10-25',
 'valid_end_date' => '2099-12-31', 'invalid_reason' => null]
```

## Decisions and discarded alternatives

- **SQLite FTS5 in a separate file.** Millions of concepts without touching
  the REDCap database and without depending on its collation. *Discarded:*
  MySQL tables with `FULLTEXT`, because it mixes licensed data into the REDCap
  database and its behaviour with accents changes from one installation to
  another.
- **System settings, not project settings.** The index takes gigabytes and
  the licence belongs to the server. *Discarded:* one index per project.
- **`concept_not_found` warns, it does not block.** The loaded vocabulary may
  be partial (amendment from 018). *Discarded:* blocking, which prevented
  approving correct concepts.
- **Relax to OR only if AND gives nothing.** Keeps precision when all the
  words appear. *Discarded:* always OR, which fills the list with noise.
- **`null` as "does not respond".** Distinguishes "does not exist" (missing
  id) from "unknown" (`null`), and allows degrading without exceptions.
  *Discarded:* throwing exceptions, which would force wrapping every call in
  the advisor.
- **WebAPI with plain `curl`.** *Discarded:* an injectable HTTP client, which
  production does not need; that is why the real WebAPI is verified manually.

## Test strategy

- `tests/specs/014_vocabulary.test.php` builds a temporary index from
  `tests/fixtures/vocab/CONCEPT.csv` (51 concepts) and injects it with
  `setVocabulary()` into the test project curated with `curate()`.
- `buildIndex` and `build_vocab_index.php` (through `exec` with `PHP_BINARY`),
  with a CSV missing columns and an unreadable path.
- "Does not respond" without a network: `new Vocabulary('/no/such.sqlite', null)`
  is configured and returns `null`; in addition a WebAPI at
  `http://127.0.0.1:9` (closed port) exercises the `curl` path in milliseconds.
- Manually: real WebAPI, wand and Control Center (see *Manual verification*).

## Constitution check

- **5 (high bar for blocking):** no vocabulary warning blocks.
- **10 (a wrong concept is worse than an empty one):** `concept_name_mismatch`
  catches the typo; an unrecognised unit leaves `unit_concept_id` empty.
- **14 (no dependencies, optional vocabulary, never distributed):** PDO
  SQLite and `curl`, the administrator's vocabulary.
- **21 (bilingual):** `athenaQuery()` translates the label; every text is in
  `lang/*.ini`.
- **22 (explain):** each warning says what is happening and how to fix it.

## Risks

- Ordering by `bm25` may put first a concept that repeats the word
  ("Hemoglobin A1c/Hemoglobin.total" for "hemoglobin" without a unit).
  Mitigated by adding the LOINC property of the stored unit.
- Building the index from the web may exceed the server's time limit: that is
  why the command-line route exists.
- The WebAPI is not tested automatically; a change in its API would go
  unnoticed until the manual verification.
