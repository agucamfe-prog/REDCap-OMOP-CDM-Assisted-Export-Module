# Tasks 001 — Module skeleton and internationalisation

Before starting: read `constitution.md`, `AGENTS.md`, and this folder's
`spec.md` and `plan.md`. Do the tasks in order. Every `[T]` must end with the
test **red**; the following `[I]` turns it green.

Run only this spec's tests: `php tests/run.php 001`

| Legend | |
|---|---|
| `[T]` | Write a test (red) |
| `[I]` | Implement (green) |
| `[R]` | Refactor, document or translate |
| `[P]` | Can be done in parallel with the previous task |

---

### T001-01 [I] Test environment without REDCap

- **Requirements:** (infrastructure for all)
- **Files:** `tests/bootstrap.php`, `tests/lib/harness.php`, `tests/run.php`
- **What to do:**
  1. In `tests/bootstrap.php`, inside `namespace ExternalModules`, define
     `AbstractExternalModule` with `$settings`, `$system`, `$logs`, `static $lang`
     and the methods `getProjectId()` (returns 1), `getProjectSetting`,
     `setProjectSetting`, `removeProjectSetting`, `getSystemSetting`, `log`,
     `getModuleVersion()` (returns `'vtest'`), `getUrl`, `getUser` and `tt($key,
     ...$args)`, which looks up `self::$lang`, substitutes `{0}`, `{1}`… and
     escapes each argument with `htmlspecialchars(..., ENT_QUOTES)`.
  2. In the global namespace, `REDCap` (with `$dict`, `$data`, `$dag`,
     `getDataDictionary`, `getData`, `getEventNames`), `Project` (properties
     copied from `Project::$conf`) and `TestUser`.
  3. Load `lang/English.ini` into `AbstractExternalModule::$lang` and
     `require_once` `OmopCdmExportModule.php`.
- **Hints:** this is the only `[I]` task without a prior test: it is the
  scaffolding that makes it possible to write the others. Do not put module
  logic in the bootstrap.
- **Done when:** `php tests/run.php` finishes without PHP errors (even if there
  are no tests yet).
- **Size:** M

### T001-02 [T] config.json: declaration, links and settings

- **Requirements:** FR-001-01, FR-001-02, FR-001-03
- **Files:** `tests/specs/001_module_skeleton.test.php`
- **What to do:** read `config.json` with `json_decode(..., true)` and write one
  test per check: valid JSON; `framework-version === 9`;
  `namespace . '\\OmopCdmExportModule' === OmopCdmExportModule::class`;
  compatibility 7.4.0 / 12.0.0; the URLs of `links.project` are exactly
  `pages/dashboard.php`, `pages/editor.php`, `pages/export.php`, all with
  `show-header-and-footer === false`; a single Control Center link to
  `pages/vocabulary.php`; each link with `tt_name`, `icon` and an existing
  file (`is_file`); project and system setting keys (list in the spec); six
  `descriptive` settings whose `name` starts with `<b>N.`; no key starting with
  `reserved-`; `webapi-token` of type `password`.
- **Done when:** the tests fail because `config.json` does not exist.
- **Size:** S

### T001-03 [I] Write config.json and the empty pages

- **Requirements:** FR-001-01, FR-001-02, FR-001-03
- **Files:** `config.json`, `pages/*.php`
- **What to do:** write `config.json` according to the plan (sections and keys
  in the table). Create the four pages with only the PHP header
  (`namespace OmopCdm\OmopCdmExportModule;` and `/** @var OmopCdmExportModule $module */`).
- **Hints:** the `name` of the settings is the fallback text if the
  translation is missing; write it in English except for the section titles.
  The `field-list` settings are filled by REDCap with the project's fields.
- **Done when:** `php tests/run.php 001` is green for FR-001-01 to 03.
- **Size:** M

### T001-04 [T] Language files: parity and coverage

- **Requirements:** FR-001-04, FR-001-05, FR-001-06
- **Files:** `tests/specs/001_module_skeleton.test.php`
- **What to do:**
  1. Walk `config.json` with `array_walk_recursive` and collect the values of
     `tt_name` and `tt_description`; each one must exist in both `.ini` files.
  2. `glob('lang/*.ini')` must return `English.ini` and `Spanish.ini`.
  3. `array_diff_key` empty in both directions; no empty value.
  4. For each key, the sorted set of `{n}` (`preg_match_all('/\{\d+\}/')`)
     is the same in both languages.
  5. With the expression `(?:\btt|\bttText|->t|\$t|\$tt)\(\s*'([A-Za-z0-9_]+)'\s*[,)]`
     extract the literal keys from `OmopCdmExportModule.php`, `classes/*.php`
     and `pages/*.php`; all of them must exist.
- **Hints:** the `\s*[,)]` part excludes dynamic keys such as
  `'domain_' . $d`, which cannot be checked this way.
- **Done when:** they fail because there are no `.ini` files or keys are
  missing.
- **Size:** M

### T001-05 [I] Write English.ini and Spanish.ini

- **Requirements:** FR-001-04, FR-001-05, FR-001-06, FR-001-07
- **Files:** `lang/English.ini`, `lang/Spanish.ini`
- **What to do:** create both files with the same keys, in the same order and
  with the same section comments. Start with `module_name`, `module_desc`,
  `domain_NotMapped` («Not mapped» / «Sin mapear»), `domain_DateAnchor`, the
  page titles (`dash_title`, `editor_title`, `ex_title`, `voc_title`), the
  `cfg_*` keys from `config.json` and `doc_lang` (`en` / `es`).
- **Hints:** values always in double quotes; line breaks are written as a
  literal `\n` (`ttText` converts them). Each later spec will add its keys to
  both files at the same time.
- **Done when:** `php tests/run.php 001` is green for FR-001-04 to 06.
- **Size:** M

### T001-06 [T] Settings cache

- **Requirements:** FR-001-08, FR-001-09, NFR-001-02
- **Files:** `tests/specs/001_module_skeleton.test.php`
- **What to do:** create an anonymous subclass that counts the reads:
  ```php
  $c = new class extends OmopCdmExportModule {
      public $lecturas = [];
      public function getProjectSetting($k, $pid = null) {
          $this->lecturas[$k] = ($this->lecturas[$k] ?? 0) + 1;
          return parent::getProjectSetting($k, $pid);
      }
  };
  $c->settings = ['cdm-holder' => 'Hospital'];
  ```
  Check: two `setting('cdm-holder')` → `lecturas['cdm-holder'] === 1`;
  two `setting('does-not-exist')` → 1; 1,000 reads → 1; `writeSetting('cdm-holder',
  'Other')` → `settings['cdm-holder'] === 'Other'` and `setting()` returns `'Other'`
  without a new read.
- **Done when:** it fails because `setting()` does not exist.
- **Size:** S

### T001-07 [I] Module class: context and settings

- **Requirements:** FR-001-08, FR-001-09, FR-001-13
- **Files:** `OmopCdmExportModule.php`
- **What to do:** create the class with `pid()`, `setting()` (with
  `array_key_exists`), `writeSetting()` (calls `setProjectSetting` and
  updates `$settingCache`) and `getDictionary()` (caches
  `\REDCap::getDataDictionary($this->pid(), 'array')`).
- **Hints:** `isset()` would return `false` for a setting whose value is `null`
  and would query it every time. Add a comment on the property explaining why
  the cache exists.
- **Done when:** `php tests/run.php 001` is green for FR-001-08 and 09.
- **Size:** S

### T001-08 [T] Texts: ttText, domainLabel and per-project language

- **Requirements:** FR-001-07, FR-001-10, FR-001-11
- **Files:** `tests/specs/001_module_skeleton.test.php`
- **What to do:**
  1. `$m->tt('r_keyword', "O'Brien <b>")` contains `O&#039;Brien &lt;b&gt;`;
     `$m->ttText(...)` contains `O'Brien <b>`.
  2. `ttText('ed_reset_all_confirm', 1, 2, 3, 4)` contains `"\n\n"` and does
     not contain the literal sequence `\n`.
  3. `domainLabel('NotMapped') === 'Not mapped'`, `domainLabel('Measurement')
     === 'Measurement'`.
  4. Save `AbstractExternalModule::$lang`, load `parse_ini_file('lang/Spanish.ini')`
     into it, check `ttText('domain_NotMapped') === 'Sin mapear'` and
     `doc_lang === 'es'`; restore the saved value.
- **Done when:** it fails because `ttText` and `domainLabel` do not exist.
- **Size:** S

### T001-09 [I] ttText and domainLabel

- **Requirements:** FR-001-10, FR-001-11
- **Files:** `OmopCdmExportModule.php`
- **What to do:** implement `ttText()` as in the plan, and `domainLabel()`:
  `in_array($domain, ['NotMapped', 'DateAnchor'], true) ? $this->tt('domain_' . $domain) : $domain`.
  Once spec 003 exists, replace the literals with
  `DictionaryProfiler::D_SKIP` and `D_DATE_ANCHOR`.
- **Hints:** `ENT_QUOTES | ENT_HTML5` in `html_entity_decode`, so that
  `&#039;` and `&apos;` become quotes again.
- **Done when:** `php tests/run.php 001` is green.
- **Size:** S

### T001-10 [T] Service factory and logging

- **Requirements:** FR-001-12, FR-001-13
- **Files:** `tests/specs/001_module_skeleton.test.php`
- **What to do:** with `make_module()` check `store() === store()` and
  `instanceof MappingStore`; `transformer()`, `exporter()`,
  `mappingDocument()`, `idRegistry(true)`, `pseudonymizer()` return the
  correct class and `exporter() !== exporter()`; `vocabulary() === null`;
  `OmopCdmExportModule::validDomains() === CdmModel::validDomains()`;
  `audit('Test', ['x' => 1])` leaves the message in `$m->logs` with
  `module_version === 'vtest'`. For the dictionary cache: call
  `getDictionary()`, empty `\REDCap::$dict` and check that the second call
  returns the same thing (restore `\REDCap::$dict` at the end).
- **Hints:** the service classes arrive with their specs (002, 006, 009,
  011, 013, 014, 016). Write each assertion once its class exists; until then,
  that factory's test stays red, which is the signal to wire it up.
- **Done when:** it fails because the factories do not exist.
- **Size:** S

### T001-11 [I] Factories, audit and cached profile

- **Requirements:** FR-001-12, FR-001-13
- **Files:** `OmopCdmExportModule.php`
- **What to do:** add the `require_once` lines for `classes/` and the methods
  `store()` (lazy, a single instance), `transformer()`, `exporter()`,
  `mappingDocument()`, `idRegistry($dryRun)`, `pseudonymizer()` (with
  `date-shift-days`, `birth-precision`, `suppress-free-text` and the project
  salt if there is a shift), `vocabulary()` (with the system settings; `null`
  if it is not configured), `validDomains()`, `audit()` and `profile()`
  (creates `DictionaryProfiler` with a translator `fn($k, ...$a) => $this->tt($k, ...$a)`
  and caches the result).
- **Hints:** `audit()` adds `module_version` to the parameters before calling
  `$this->log()`.
- **Done when:** `php tests/run.php 001` is green.
- **Size:** M

### T001-12 [T] Pages and non-functional requirements

- **Requirements:** FR-001-14, NFR-001-01, NFR-001-03
- **Files:** `tests/specs/001_module_skeleton.test.php`
- **What to do:** read each page as text. The three project pages contain
  `ProjectGeneral/header.php` and `ProjectGeneral/footer.php`; `vocabulary.php`
  contains `ControlCenter/header.php` and `footer.php`; in `dashboard.php` the
  position of `$_GET['download']` is lower than that of the header. Check that
  there is no `composer.json` or `vendor/`, that the class has no
  `redcap_module_system_enable` or `redcap_module_project_enable`, and that no
  PHP file contains `match (`, `?->`, `str_contains(`, `#[` at the start of a
  line, `enum` or `readonly`.
- **Done when:** it fails because the pages do not include the header.
- **Size:** S

### T001-13 [I] Header and footer on the pages

- **Requirements:** FR-001-14
- **Files:** `pages/*.php`
- **What to do:** on each project page, leave an initial block for downloads
  (empty for now in `editor.php` and `export.php`; in `dashboard.php`,
  `$download = $_GET['download'] ?? '';` and an `exit` after each download),
  then `require_once APP_PATH_DOCROOT . 'ProjectGeneral/header.php';`, the
  title with `htmlspecialchars($module->tt('dash_title'))` and the
  `footer.php`. In `vocabulary.php`, the `ControlCenter/` ones.
- **Done when:** `php tests/run.php 001` is green.
- **Size:** S

### T001-14 [R] Manual verification and documentation

- **Requirements:** FR-001-15
- **Files:** `README.md`
- **What to do:** follow the spec's *Manual verification* procedure on the
  development REDCap and record the result in the review. Document in the
  README the installation («Installing downloads nothing»), the folder
  structure and how the language is chosen (`reserved-language-project`).
- **Done when:** the six manual verification steps pass and the README
  reflects them.
- **Size:** S

---

## Definition of done for the spec

- [ ] All `001` tests green and no regressions in the rest of the suite.
- [ ] `php tests/run.php --trace 001` with no orphan requirements.
- [ ] New texts in `lang/Spanish.ini` and `lang/English.ini` with the same keys.
- [ ] README updated if anything visible to the user changes.
- [ ] Spec status changed to `Implemented`.
