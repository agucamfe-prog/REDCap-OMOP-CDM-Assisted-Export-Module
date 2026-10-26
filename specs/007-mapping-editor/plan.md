# Plan 007 — Mapping editor

## Technical summary

`pages/editor.php` has two halves. The first handles the POST actions: it
checks the CSRF token and the rights, dispatches the action from a whitelist
against `MappingStore` (spec 006) and the advisor (spec 008), responds JSON
and ends with `exit`. The second renders the editor table with what is stored
or, if there is nothing, with what is proposed, and a `<script>` that saves
each change via AJAX, chains the requests, asks for confirmations and keeps
the progress bar.

The Athena search is built on the server with three module methods
(`athenaQuery()`, `athenaDomain()`, `athenaVocabulary()`) and a function that
assembles the URL (`athenaUrl()`), so it can be tested without a browser.

## Files

| File | Action | Responsibility |
|---|---|---|
| `pages/editor.php` | Create | POST actions, row rendering, editor JavaScript |
| `OmopCdmExportModule.php` | Modify | `athenaUrl()`, `athenaQuery()`, `loincProperty()`, `athenaDomain()`, `athenaVocabulary()`, `domainLabel()`, `dateFields()`, `isDateField()`, `suggestedDateField()`, `ttText()`, `userCanEditMapping()` |
| `classes/DictionaryProfiler.php` | Modify | `suggestedStrategy()`, `isBinaryLabel()` (static) |
| `config.json` | Modify | Editor link |
| `lang/*.ini` | Modify | `editor_title`, `ed_*`, `adv_blocked`, `adv_confirm`, `adv_confirm_ask` |
| `tests/specs/007_mapping_editor.test.php` | Create | Tests for this spec (uses `tests/support/run_page.php` from spec 005) |

## Design

### POST actions

| `action` | Input | Response |
|---|---|---|
| `save-field` | `field`, whitelist keys, `confirmed` | `{ok, spec, error, warnings, counts}` or `{ok, spec, warnings, blocked\|confirm, counts}` |
| `save-value` | `field`, `raw_value`, `emit`, `concept_id`, `concept_name`, `source_value` | `{ok, value, changed, state, counts}` |
| `reset-proposal` | `field` | `{ok, spec, changed, from, to, warnings, counts}` |
| `reset-all-preview` / `reset-all` | — | `{ok, changes: {field: "A → B"}, count}` / `{ok, changed, counts}` |
| `bulk-preview` / `bulk` | `which` ∈ `approve-ready`, `exclude-notmapped`, `exclude-manual`, `exclude-pending`, `adopt-annotations` | `{ok, fields, count}` / `{ok, changed, counts}` |
| `reset-everything-preview` | — | `{ok, impact: {fields, approved, excluded, concepts}}` |
| `reset-everything` | `confirmed`, `word` | `{ok, cleared, counts}` or 400 |
| `restore-backup` | — | `{ok, restored, counts}` or 400 |
| `suggest`, `import-mapping` | (specs 014 and 015) | |

Order of checks: CSRF (403) → `userCanEditMapping()` (403) → action.
Everything inside `try { … } catch (\Throwable $e)`, which logs
`Editor error` and responds 500 with `error` and `where`. `$respond` empties
the buffers (`ob_end_clean`), sets the code and headers, writes the JSON and
calls `exit` (a `return` would let the framework keep printing).

### `save-field` step by step

1. Validate field and domain; copy to `$changes` only the whitelist keys
   present in `$_POST`.
2. (Spec 015) adopt the `@OMOP` annotation if the field is not edited.
3. (Spec 014) fill in `concept_name` from the vocabulary if missing.
4. If `spec['edited']` is false: complete `value_strategy`
   (`suggestedStrategy($valueKind, $choices, $domain)`), `date_field`
   (`suggestedDateField($field, $domain)`) and `domain` with what is proposed,
   except for the keys that already arrive.
5. If approval is requested: `updateField()` without the state; `advise()`;
   `blocks()` → respond `blocked`; `needsConfirm()` without `confirmed` →
   respond `confirm`; if there are warnings,
   `log('Approved with warnings', {field, codes})`.
6. `updateField()` with the state; translate `_error` with `ttText('ed_err_' . $error, $bad)`.

### Rendering a row

```php
$cur        = $spec['domain'] ?? $prof['suggested_domain'];
$isConfig   = $state === 'config';
$noExport   = $isConfig || in_array($cur, ['NotMapped', 'DateAnchor'], true);
$strategy   = $edited || $spec['value_strategy'] ? $spec['value_strategy']
            : DictionaryProfiler::suggestedStrategy($prof['value_kind'], $prof['choices'], $cur);
$shownDate  = $edited ? $spec['date_field'] : $module->suggestedDateField($name, $cur);
$shownUnit  = $edited || $spec['unit_source_value'] ? $spec['unit_source_value'] : $prof['unit_hint'];
$hasEnd     = in_array($cur, ['Condition', 'Drug', 'Procedure', 'Device', 'Visit'], true);
$athQuery   = $module->athenaQuery($prof['field_label'] ?: $name, $name,
                  $athDomain === 'Measurement' ? $unit : '');
$athena     = $module->athenaUrl($athQuery, $athDomain);                 // without vocabulary
$athenaByVocab = $module->athenaUrl($athQuery, $athDomain, $athVocab);   // with vocabulary
```

Controls with `data-k` (field) and `data-vk` (option); empty domain and date
selectors, which the JavaScript fills in from `OMOP.domains` and
`OMOP.dateFields`.

### `OMOP` object for the browser

```js
{url, csrf, dateFields: [["dob","base"], ["visit_date","base"], …],
 stateLabels: {proposed: "…", …, config: "…"}, counts: {total: 18, …},
 tpl: "{0} of {1} fields approved · …", domains: [["Condition","Condition"], …],
 endDomains: ["Condition","Drug","Procedure","Device","Visit"],
 bulkConfirm: {"approve-ready": "…", …}, resetAllWord: "DELETE", …}
```

`json_encode(..., JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP)` so
that a text containing `</script>` does not close the block.

### JavaScript (main functions)

`omopPost()` chains on `omopQueue` (a promise); `omopSend()` reads the
response as text and parses it by hand (`omopReportBadResponse()` if it is not
JSON); `omopSave()`, `omopSaveValue()`, `omopState()` (forces `blur`, sends
all `data-k`, handles `blocked`/`confirm`), `omopBulkAsk()`,
`omopResetAll()`, `omopResetEverything()`, `omopRestore()`, `omopFilter()`,
`omopRender()` (bar: `approved / max(1, total − excluded − config)`; count
`ed_counts` with `{0}`…`{5}` = approved, total, pending, excluded, to review
and configuration), `omopCleanId()` (removes non-breaking spaces when leaving
the concept).

### Athena search

```php
public function athenaUrl(string $query, string $domain, string $vocabulary = ''): string
// https://athena.ohdsi.org/search-terms/terms?query=body+weight&standardConcept=Standard
//   &invalidReason=Valid&page=1&pageSize=15&domain=Measurement[&vocabulary=LOINC]
public function athenaDomain(?string $domain): string      // Person→Gender, Death→Condition, own codes→''
public function athenaVocabulary(?string $domain): string  // LOINC, RxNorm, Gender, SNOMED or ''
public function loincProperty(?string $unit): string       // g/dL → 'mass volume'
public function athenaQuery(string $label, string $fieldName, string $unit = ''): string
// 'Hemoglobina (g/dL)', 'hb', 'g/dL' → 'hemoglobin mass volume'
// 'Peso basal (kg)' → 'body weight';  'Sudoración nocturna' → 'night sweats'
```

Private constants: `ATHENA_GLOSSARY` (Spanish → English), `PROTOCOL_WORDS`,
`UNIT_WORDS`, `ATHENA_STOPWORDS`.

## Decisions and discarded alternatives

- **Autosave on change, no Save button.** A final button gets forgotten and
  work is lost. *Discarded:* saving on `blur`, which saved (and previously
  withdrew approvals) just from passing through a box.
- **Request queue.** Approving right after typing fires two requests almost at
  once; if the approval arrives first, the server evaluates an incomplete
  state.
- **The proposal is saved only the first time.** What is seen has to be what
  is approved, but reapplying it on every save overwrote decisions (a "single
  concept" went back to "per option" and lost its approval).
- **Own CSRF token** in addition to REDCap's: the session may not be started
  depending on how REDCap serves the module page.
- **An Athena button without a vocabulary filter and another one with it.**
  The filter is right for analytes and drugs; when it is not, it leaves zero
  results. *Discarded:* a single filtered button.
- **Build the search on the server,** with the glossary, so it can be tested.
  *Discarded:* sending the label as is (in Spanish, Athena returns results by
  chance).
- **Common lists sent once.** With 500 fields, repeating the domains and dates
  on every row made the page several megabytes.
- **The reset word is checked on the server too:** a client that skips the
  `prompt()` must not be able to erase with `confirmed=1`.

## Test strategy

- `tests/specs/007_mapping_editor.test.php` with `tests/support/run_page.php`:
  the page runs in a child PHP process with the CSRF token `tok` in the
  session, `$_POST` and the module settings; it returns the JSON, the headers,
  the HTTP code, the resulting settings and the log. Chaining
  `$r['settings']` simulates successive requests.
- For rendering, closures `$row($html, $field)` (the field's
  `<tr data-field>`) and `$omop($html)` (the decoded `OMOP` object).
- The Athena methods are tested directly.
- The JavaScript interaction (FR-007-14) and the filters and bar (FR-007-15)
  are verified by hand, with the spec's procedure.

## Constitution check

- **Principle 2 (writing does not approve):** only `state = approved` or
  `approve-ready` approve, always by explicit action.
- **Principle 3:** the server returns the real state after every save.
- **Principle 4:** the proposal only on the first save; going back to it is an action.
- **Principle 5:** `block` prevents approving; `warn` requires a logged confirmation.
- **Principle 12:** the actions require design rights on the server.
- **Principle 21:** texts in `lang/*.ini`; a test checks keys in both languages.
- **AGENTS (escaping):** everything rendered goes through `htmlspecialchars`;
  everything arriving by POST, through the whitelist and the CSRF check.

## Risks

- **Large projects:** `advise()` per row is expensive; the module caches
  profile, rows and warnings per request (specs 006, 008).
- **The JavaScript has no automatic tests** in the PHP suite; it is covered by
  the manual verification. If a browser test is added, it must live outside
  `tests/run.php` (no runtime dependencies).
- **Athena changes its interface:** the URL parameters could stop working;
  they live in a single method.
