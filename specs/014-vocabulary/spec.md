# Spec 014 — Vocabulary: validation and suggestions

| | |
|---|---|
| **Status** | Amended |
| **Depends on** | 008 |
| **Tests** | `tests/specs/014_vocabulary.test.php` |

## Context

Up to this point the module can only judge the *shape* of a concept: the
advisor of spec 008 knows whether what was pasted looks like a `concept_id` or a
vocabulary code, and whether the name looks like Athena's or like the project's
label. It cannot know whether the number exists, whether it is standard, whether
it belongs to the right domain or — most importantly — whether it matches the
pasted name. A typo in the number produces a CDM that loads without errors and
says something different from what the project measures.

This spec adds an **optional** OMOP vocabulary. The module does not ship it
(the SNOMED licence does not allow it; constitution, principle 14): the
administrator downloads the vocabularies from Athena under their own licence and
the module builds an SQLite FTS5 index on the server from `CONCEPT.csv`, or else
queries the institution's ATLAS WebAPI. With a vocabulary, the advisor really
checks each concept, the editor suggests candidates (the wand),
`unit_concept_id` is resolved through UCUM and the export adds
`SOURCE_TO_CONCEPT_MAP`.

Everything is optional and nothing depends on the vocabulary responding: if
there is no vocabulary, or it does not answer, the module works as it does
without one and says so. It never blocks because it cannot look something up
(principle 5).

## User stories

- **US-014-1.** As a REDCap administrator, I want to build a vocabulary index
  once from the `CONCEPT.csv` I downloaded from Athena, from the Control Center
  or from the command line, so that every project uses it without the
  vocabulary ever leaving the server.
- **US-014-2.** As the administrator of an institution with ATLAS, I want to
  point the module at our WebAPI (with a token if needed) instead of maintaining
  another index.
- **US-014-3.** As a curator, I want to be warned when approving if the
  `concept_id` does not exist, is retired, is not standard, belongs to another
  domain or does not match the pasted name, so that I catch typos before
  exporting.
- **US-014-4.** As a curator, I want to click the wand next to a field or an
  option and pick, with one click, among standard candidates of the right
  domain, even if the label is in Spanish or in British spelling.
- **US-014-5.** As an analyst loading the CDM, I want `unit_concept_id` and
  `SOURCE_TO_CONCEPT_MAP` filled in from the vocabulary, so that ATLAS and Usagi
  can reuse the mapping.

## Functional requirements

- **FR-014-01** — `Vocabulary::buildIndex($conceptCsv, $sqlitePath, $progress)` MUST build, from an Athena `CONCEPT.csv` (tab-separated), an SQLite index with the `concept` table (eight columns) and an FTS5 table `concept_fts` over the normalised name, writing to `<path>.tmp` and renaming only when finished; it MUST reject with a `RuntimeException` a file that cannot be read or that lacks any mandatory column, without touching the previous index, and return the number of concepts. `resources/build_vocab_index.php CONCEPT.csv output.sqlite` MUST do the same from the command line (exits with code 2 and the usage if arguments are missing; 404 outside the CLI).
- **FR-014-02** — The vocabulary MUST be configured with **system** settings (`vocab-sqlite-path`, `webapi-url`, `webapi-token`): with none of them, `vocabulary()` returns `null`; with an index or a WebAPI, an instance whose `source()` is `sqlite:<file>` or `webapi:<url without trailing slash>`. If the index file exists, it takes precedence over the WebAPI.
- **FR-014-03** — With the ATLAS WebAPI, the module MUST query `POST /vocabulary/lookup/identifiers` and `POST /vocabulary/search` (standard `S`, valid `V`, domain if requested, ordered by shortest name), send the token as `Authorization: Bearer` if configured, and give up after 10 s (5 s to connect). `[manual]`
- **FR-014-04** — `lookup(array $ids)` MUST return, in one call, `id => concept` with the keys `id, name, domain, vocabulary, class, standard, code, invalid`; an id missing from the result is a `concept_id` that does not exist; non-positive ids are ignored.
- **FR-014-05** — `search($query, $domain, $limit)` MUST return only standard, non-retired concepts, of the requested domain if one is given, ordered by relevance and, on ties, by shortest name, with at most `$limit` results; an empty query returns `[]`.
- **FR-014-06** — `ucum($code)` MUST return the UCUM concept with that exact `concept_code`, or `null`; `unitConcept($unit)` MUST normalise the unit written on the form to UCUM (`mmHg` → `mm[Hg]`), resolve it with the vocabulary and, if it is not found or there is no vocabulary, use the built-in table `CdmModel::UCUM_BUILTIN`; an unrecognised unit gives `null`. Measurements with a numeric value carry that `unit_concept_id`.
- **FR-014-07** — With a vocabulary, a `concept_id` in the mapping that is not in the loaded vocabulary MUST produce the warning `concept_not_found` with severity `warn` (requires confirmation, does not block): the site may not have downloaded that vocabulary.
- **FR-014-08** — With a vocabulary, the advisor MUST check the field's concept (except "value per option" with options) and that of each emitted option, and warn with `warn` of `concept_invalid` (retired), `concept_not_standard`, `concept_wrong_domain` (expected domain: the field's; `Gender`, `Race` or `Ethnicity` for the configured PERSON fields; `Condition` in Death; none in Note or in the options of "value as concept") and `concept_name_mismatch` (the pasted name, normalised, neither matches nor is contained in the vocabulary's, or vice versa). None of these warnings blocks.
- **FR-014-09** — If the vocabulary is configured but does not respond, `lookup` and `search` MUST return `null` (with the reason in `lastError()`), the advisor MUST emit the warning `vocab_unavailable` with severity `info` only once and apply the name-shape check of spec 008 (`no_concept_name`, `concept_name_is_label`), just as without a vocabulary — a vocabulary that is down does not check less than having none — the unit MUST fall back to the built-in table and the export MUST go on (without `SOURCE_TO_CONCEPT_MAP` rows, counted in `stcm_unknown_concept`). Nothing is blocked because a lookup is impossible.
- **FR-014-10** — Only with a vocabulary, the export MUST generate `SOURCE_TO_CONCEPT_MAP`: one row per approved field with a concept (`source_code` = field name) and one per emitted option with a concept (`field=code`, description "Label: option"), with `source_concept_id` 0, `source_vocabulary_id` `REDCAP_P<pid>` (20 characters at most), `target_vocabulary_id` from the vocabulary, `valid_start_date` the approval date and `valid_end_date` 2099-12-31. Without a vocabulary the table stays empty; a concept the vocabulary does not know produces no row and is counted.
- **FR-014-11** — Suggestions MUST search with the query built by `athenaQuery()` from the field label (translated into English, without questionnaire noise and, in Measurement, with the LOINC property deduced from the saved unit) or from the option label, in the domain given by `athenaDomain()` or in `Meas Value` for the options of "value as concept".
- **FR-014-12** — The editor MUST offer the wand next to each field and each option when there is a vocabulary and the user has editing rights (action `suggest`, up to 8 candidates picked with one click; on a binary option — «Sí», "Yes" — the question is searched instead; retry without domain if there are no results; error 400 without a vocabulary and 502 if it does not respond), fill in the concept name when only the number is pasted, and show whether the vocabulary is active. `[manual]`
- **FR-014-13** — The Control Center page `pages/vocabulary.php` MUST be for superusers only and show the paths of `CONCEPT.csv` (readable or not), of the index (with its size) and of the WebAPI, and the source in use; it MUST offer "Build the index" (with confirmation, CSRF token and a log entry) and a test search: a number looks up the concept, a text searches with an optional domain. `[manual]`
- **FR-014-14** — If the search with all the words (as prefixes) finds nothing, it MUST relax to any of them: "fasting glucose" finds "Glucose […]". If all of them appear, it does not relax.
- **FR-014-15** — The search (index and WebAPI) and the suggestion query MUST convert British spelling to the American spelling of the OHDSI vocabularies with `TextNormalizer::americanize()` ("haemoglobin" → "hemoglobin", "anaemia", "oedema", "paediatric"…).

## Non-functional requirements

- **NFR-014-01** — No new dependencies: PDO SQLite with FTS5 and, for the WebAPI, `curl`. The module neither includes nor downloads vocabularies.
- **NFR-014-02** — Performance: the index is filled in transactions of 50,000 rows; `conceptInfo()` requests all the mapping's concepts at once on the first lookup; `lookup` works in batches of 500 and caches per request (missing ones too).
- **NFR-014-03** — Security: prepared statements (the FTS5 `MATCH` too), the vocabulary page for superusers only and with a CSRF token, the WebAPI token is a password-type setting.

## Acceptance criteria

```gherkin
Scenario: index built from Athena
  Given a CONCEPT.csv with 51 concepts
  When the administrator runs php resources/build_vocab_index.php CONCEPT.csv omop.sqlite
  Then 51 concepts are indexed and no omop.sqlite.tmp is left behind

Scenario: typo in the number
  Given the field "Weight (kg)" with concept_id 3025315 and name "Body height"
  When the curator approves
  Then concept_name_mismatch appears and the curator is asked to confirm

Scenario: partial vocabulary
  Given a concept_id that is not in the loaded vocabulary
  When the curator approves
  Then concept_not_found appears as a warning and the curator can approve after confirming

Scenario: vocabulary down
  Given a configured vocabulary that does not respond
  When the curator reviews a field and exports
  Then they see "The vocabulary is not responding" (info), nothing is blocked and the ZIP is generated

Scenario: suggestions with British spelling and extra words
  Given an index with "Glucose [Mass/volume] in Serum or Plasma" and "Hemoglobin [Mass/volume] in Blood"
  When "fasting glucose" and "haemoglobin" are searched in Measurement
  Then glucose and hemoglobin appear among the candidates
```

## Out of scope

- Following "Maps to" relationships automatically: the `concept_not_standard`
  warning says what to do, but the curator chooses the standard concept.
- Distributing or updating vocabularies (principle 14).
- `SOURCE_TO_CONCEPT_MAP` for the fields consumed by the configuration (sex,
  race, ethnicity): they feed PERSON and are not approved facts (spec 009).
- Checking the shape of the concept without a vocabulary: spec 008.
- Reordering candidates beyond `bm25` and the shortest name. Known limitation:
  "haemoglobin" without a saved unit puts "Hemoglobin A1c/Hemoglobin.total in
  Blood" first (it repeats the word) and "Hemoglobin [Mass/volume] in Blood"
  after it; with the unit `g/dL` saved, the LOINC property puts the latter
  first (FR-014-11).

## Manual verification

**FR-014-03 (WebAPI).** With a test ATLAS (e.g. Broadsea): set `webapi-url`
(and `webapi-token` if the instance requires it) in the module configuration and
leave `vocab-sqlite-path` empty. In the Control Center, the source in use must
be `webapi:<url>`; searching "body weight" with domain Measurement must list
3025315 first; searching `3025315` must return "Body weight". With a wrong
token, the search shows no results and the editor says "The vocabulary is not
responding: HTTP 401".

**FR-014-12 (wand and automatic name).** With a vocabulary: in the editor, the
header says "Vocabulary active (…)". Clicking the wand on «Peso (kg)» must offer
"Body weight"; choosing it fills in number and name. On a «Positivo» option of a
field with "value as concept", the wand offers "Positive" (Meas Value). Pasting
only `3025315` into the concept and leaving the box fills in the name. Without a
vocabulary, the header explains it and the wand does not appear.

**FR-014-13 (Control Center).** As a superuser, open *Control Center →
OMOP-CDM: vocabulary*: the three paths are shown with ✓/✗, plus the index size
and the source. "Build the index" asks for confirmation, ends with "Index built:
N concepts in S s." and leaves the entry "Vocabulary index built" in the module
log. As a normal user, the page says it is only for administrators.

## Amendments

| Date | Requirement | Change | Prompted by |
|---|---|---|---|
| 2026-10 | FR-014-07 | `concept_not_found` changes from blocking approval to a `warn` warning: sites download only some of Athena's vocabularies (in the evaluation, the Eunomia subset) and a correct concept from another vocabulary could not be approved (anomaly 1). | [018](../018-multiproject-validation/spec.md) |
| 2026-10 | FR-014-14 (new) | The search required all the words (strict AND); if there are no results it now relaxes to any of them (OR). "Fasting glucose" and "PCR SARS-CoV" gave 0 candidates (anomaly 6). | [018](../018-multiproject-validation/spec.md) |
| 2026-10 | FR-014-15 (new) | The search and the suggestion query convert British spelling to American: "Haemoglobin (g/dL)" did not find hemoglobin (anomaly 7). | [018](../018-multiproject-validation/spec.md) |
