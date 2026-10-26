# Plan 001 — Module skeleton and internationalisation

## Technical summary

The module is a class `OmopCdmExportModule` that extends
`ExternalModules\AbstractExternalModule` (framework v9) and is declared in
`config.json`. The class contains no business logic: it holds per-request
caches (settings, dictionary, profile), translates, and builds the services in
`classes/`, to which it passes `$this`. The pages in `pages/` are served by the
framework with `$module` already defined and render the REDCap header
themselves, because several of them respond with downloads (CSV, JSON, ZIP)
that cannot have HTML in front of them.

The texts live in `lang/English.ini` and `lang/Spanish.ini`. The framework
loads the file indicated by each project's reserved setting
`reserved-language-project`, and `tt()` looks it up. The module adds `ttText()`
to obtain plain text (for JavaScript, files, and strings that are escaped only
once later).

## Files

| File | Action | Responsibility |
|---|---|---|
| `config.json` | Create | Metadata, compatibility, links, project and system settings |
| `OmopCdmExportModule.php` | Create | Module class: context, settings cache, texts, factories |
| `lang/English.ini` | Create | English texts (the framework's default file) |
| `lang/Spanish.ini` | Create | The same texts in Spanish |
| `pages/dashboard.php`, `editor.php`, `export.php` | Create (skeleton) | Project pages; their content arrives in 005, 007, 011 |
| `pages/vocabulary.php` | Create (skeleton) | Control Center page; its content arrives in 014 |
| `tests/bootstrap.php` | Create | Stand-ins for `AbstractExternalModule`, `REDCap`, `Project` and the user |
| `tests/specs/001_module_skeleton.test.php` | Create | Tests for this spec |

## Design

### config.json

```json
{
  "name": "OMOP-CDM Export",
  "namespace": "OmopCdm\\OmopCdmExportModule",
  "framework-version": 9,
  "compatibility": {"php-version-min": "7.4.0", "redcap-version-min": "12.0.0"},
  "links": {
    "project": [
      {"name": "OMOP-CDM: mapping dashboard", "tt_name": "dash_title", "icon": "fas fa-project-diagram",
       "url": "pages/dashboard.php", "show-header-and-footer": false},
      ...editor_title → pages/editor.php, ex_title → pages/export.php
    ],
    "control-center": [{"tt_name": "voc_title", "icon": "fas fa-book", "url": "pages/vocabulary.php"}]
  },
  "project-settings": [
    {"key": "desc-source", "tt_name": "cfg_sec_source", "name": "<b>1. Data source</b>", "type": "descriptive"},
    {"key": "cdm-holder", "tt_name": "cfg_holder", "type": "text", "required": true},
    ...
  ],
  "system-settings": [ desc-vocab, vocab-concept-csv, vocab-sqlite-path, webapi-url, webapi-token (password) ],
  "tt_name": "module_name", "tt_description": "module_desc"
}
```

Project settings sections and their keys:

| Section | Keys |
|---|---|
| 1. Data source | `cdm-version`, `cdm-source-name`, `cdm-source-abbreviation`, `cdm-holder`, `vocabulary-version` |
| 2. PERSON (+ DEATH) | `birth-date-field`, `birth-year-field`, `gender-field`, `race-field`, `ethnicity-field`, `death-date-field` |
| 3. Dates (+ visits) | `default-date-field`, `generate-visits`, `visit-concept-id` |
| 4. Privacy | `hash-person-source-value`, `date-shift-days`, `birth-precision`, `suppress-free-text` |
| 5. Care sites and providers | `care-site-field`, `provider-field` |
| 6. Notes and load kit | `note-language-concept-id`, `load-schema` |

Each section is a `descriptive` setting whose `name` starts with `<b>N.`.
`reserved-language-project` is **not** declared: the framework adds it when it
detects several `.ini` files in `lang/`, and declaring a `reserved-*` key is a
configuration error.

### Module class

```php
class OmopCdmExportModule extends AbstractExternalModule
{
    private $settingCache = [];          // key => value (null included)
    private $dictionaryCache = null;
    private $profileCache = null;
    private $store = null;               // MappingStore, one per request

    public function pid(): int
    public function setting(string $key)                  // array_key_exists, not isset: caches null
    public function writeSetting(string $key, $value): void
    public function ttText(string $key, ...$args): string
    public static function validDomains(): array          // = CdmModel::validDomains()
    public function domainLabel(string $domain): string
    public function getDictionary(): array                // REDCap::getDataDictionary($pid, 'array'), cached
    public function profile(): array                      // DictionaryProfiler with tt() as translator, cached
    public function store(): MappingStore
    public function transformer(): Transformer
    public function exporter(): CsvExporter
    public function mappingDocument(): MappingDocument
    public function idRegistry(bool $dryRun = false): IdRegistry
    public function pseudonymizer(): Pseudonymizer
    public function vocabulary(): ?Vocabulary              // null if Vocabulary::configured() is false
    public function audit(string $message, array $params = []): void
}
```

`setting()` uses `array_key_exists` so that a setting that does not exist
(`null`) is cached too:

```php
if (!array_key_exists($key, $this->settingCache)) {
    $this->settingCache[$key] = $this->getProjectSetting($key);
}
return $this->settingCache[$key];
```

`ttText()`:

```php
return str_replace('\n', "\n",
    html_entity_decode($this->tt($key, ...$args), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
```

Example: `tt('r_keyword', "O'Brien")` → `Matches 'O&#039;Brien' in…`;
`ttText(...)` → `Matches 'O'Brien' in…`.

### Language files

INI format with values in double quotes; sections commented per screen
(`; --- Panel ---`). Key conventions: `dash_*` dashboard, `ed_*` editor,
`ex_*` export, `voc_*` vocabulary, `cfg_*` settings, `r_*` classifier reasons,
`rep_*` report, `domain_*` the module's own codes. `doc_lang` holds the ISO code
of the language (`en`, `es`); it is used by the mapping document and by the
NOTE language concept.

### Pages

```php
<?php
namespace OmopCdm\OmopCdmExportModule;
/** @var OmopCdmExportModule $module */

// 1. Downloads (exit before rendering anything)
// 2. require_once APP_PATH_DOCROOT . 'ProjectGeneral/header.php';
// 3. HTML with $module->tt(...) and htmlspecialchars
// 4. require_once APP_PATH_DOCROOT . 'ProjectGeneral/footer.php';
```

## Decisions and rejected alternatives

- **`show-header-and-footer: false` on the project pages.** It allows a
  download to be served before there is any HTML output. *Rejected:* letting
  the framework render the header, because it corrupts the CSV and ZIP files.
- **Per-project language through the framework's reserved setting.** An
  English-speaking team can work on an instance in Spanish. *Rejected:* using
  REDCap's global language, which does not belong to the project.
- **Settings cache in the module class.** A single query per key and request.
  *Rejected:* caching in each service, because it duplicates the logic and gets
  out of sync on writes.
- **A single `store()`; the other services new on each call.** The store holds
  state that must be consistent throughout the request (what has just been
  saved); the transformer or the exporter do not. *Rejected:* a dependency
  injection container, overkill for nine services.
- **No activation hooks.** Nothing to install, nothing to uninstall.

## Testing strategy

`tests/specs/001_module_skeleton.test.php`, without REDCap:

- `config.json` is read with `json_decode`, and the version, links, setting
  keys, and the existence of each `tt_name` in the `.ini` files (walked with
  `array_walk_recursive`) are checked.
- The `.ini` files are read with `parse_ini_file` and their keys, empty values
  and `{n}` placeholders are compared.
- A regular expression extracts the literal keys of `tt('…')`, `ttText('…')`,
  `->t('…')` and `$t('…')` from `OmopCdmExportModule.php`, `classes/` and
  `pages/`; all of them must exist. Dynamic keys (`'domain_' . $d`) are not
  counted.
- Settings cache: an anonymous subclass of the module counts the calls to
  `getProjectSetting()`.
- Language: `AbstractExternalModule::$lang` is replaced with the contents of
  `Spanish.ini`, as the framework does, and restored afterwards.
- The pages are checked as text (inclusion of header and footer, download →
  header order).
- FR-001-15 is verified by hand (see the spec).

## Constitution check

- **11 (read-only):** state lives in module settings; no method in this spec
  writes to the project.
- **14 (no dependencies):** no Composer, no downloads, no tables.
- **17 (framework v9):** declared and checked.
- **21 (bilingual):** key parity checked on every test run.

## Risks

- A new text added to only one `.ini`: caught by FR-001-05.
- A key used in the code that does not exist: the framework renders it as
  `[key]`; caught by FR-001-06, except for dynamically built keys, which their
  own specs must check.
- Future framework changes to the name of the language setting: verified by
  hand (FR-001-15).
