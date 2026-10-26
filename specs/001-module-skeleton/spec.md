# Spec 001 — Module skeleton and internationalisation

| | |
|---|---|
| **Status** | Implemented |
| **Depends on** | — |
| **Tests** | `tests/specs/001_module_skeleton.test.php` |

## Context

Before a single field can be classified, the module has to exist for REDCap:
a declaration (`config.json`) that the v9 external module framework
understands, a class that extends `AbstractExternalModule`, some pages that can
be reached from the project menu and from the Control Center, and some
settings in which to store everything the module needs to remember. The
constitution (principle 11) requires that state to live in the module
settings: the module never writes to the project's data or data dictionary.

This spec also settles two cross-cutting matters that the rest of the specs
take for granted. The first is **bilingualism from day one** (principle 21):
every visible text comes from `lang/Spanish.ini` or `lang/English.ini`, with
the same keys, and the language is chosen per project through the framework's
reserved setting `reserved-language-project`, not by the language of the
REDCap instance. The second is **cheap access to settings**: the framework does
not cache `getProjectSetting()`, and the transformation (spec 009) asks for the
default date and for the visits on every fact it emits. Without a per-request
cache, an export of 15,000 rows would fire tens of thousands of queries.

Finally, the module class is the **service factory**: the mapping store, the
transformation engine, the exporter, the mapping document, the identifier
registry, the pseudonymiser and the vocabulary are always obtained through it.
That way each service receives the module already configured, and the tests
can replace REDCap with in-memory versions (`tests/bootstrap.php`).

## User stories

- **US-001-1.** As a REDCap administrator, I want to install the module by
  copying a folder and enabling it, with no downloads and no new tables, so
  that the database schema is left untouched.
- **US-001-2.** As a project data manager, I want to find the dashboard, the
  editor and the export in the project menu, and all the settings grouped into
  sections in the module configuration.
- **US-001-3.** As a researcher in an English-speaking team, I want to choose
  the module language in my project so that screens, reports and the mapping
  document come out in English, even if the instance is in Spanish.
- **US-001-4.** As a developer, I want to read a setting as many times as I
  need without each read being a query, and to obtain the services already
  configured from the module.

## Functional requirements

- **FR-001-01** — `config.json` MUST be valid JSON and declare the name «OMOP-CDM Export», the namespace `OmopCdm\OmopCdmExportModule` (that of the class `OmopCdmExportModule`), `framework-version` 9, compatibility `php-version-min` 7.4.0 and `redcap-version-min` 12.0.0, authors, and `documentation` = `README.md`.
- **FR-001-02** — `config.json` MUST declare three project links (`pages/dashboard.php`, `pages/editor.php`, `pages/export.php`) with `show-header-and-footer` = `false`, and one Control Center link (`pages/vocabulary.php`); each link carries `tt_name`, an icon and a URL to a file that exists.
- **FR-001-03** — `config.json` MUST declare the project settings in six numbered descriptive sections (data source; PERSON and DEATH; dates and visits; privacy; care sites and providers; notes and load kit) and five system settings for the vocabulary (`desc-vocab`, `vocab-concept-csv`, `vocab-sqlite-path`, `webapi-url`, `webapi-token`, the last of type `password`). No key is repeated or uses the reserved prefix `reserved-`.
- **FR-001-04** — Every `tt_name` or `tt_description` key in `config.json` (module, links, settings and dropdown options) MUST exist in both language files.
- **FR-001-05** — `lang/` MUST contain exactly `English.ini` and `Spanish.ini`, with the same keys, no empty value and, for each key, the same interpolation placeholders `{0}`, `{1}`… in both languages.
- **FR-001-06** — Every literal text key used by the code (`tt()`, `ttText()`, the profiler's translator and the `$t()` calls in classes and pages) MUST exist in both language files.
- **FR-001-07** — The language of the texts MUST be the one the framework loads according to the project setting `reserved-language-project`: with `Spanish.ini` loaded the texts come out in Spanish, and with `English.ini` in English. Each file declares its language code in the key `doc_lang` (`es`, `en`).
- **FR-001-08** — `setting($key)` MUST read each project setting at most once per request, including settings that do not exist (value `null`).
- **FR-001-09** — `writeSetting($key, $value)` MUST save the project setting and update the cache, so that the next `setting($key)` returns the new value without a query.
- **FR-001-10** — `ttText($key, ...$args)` MUST return plain text: it interpolates like `tt()`, decodes the HTML entities that `tt()` introduces when escaping the arguments, and turns the literal `\n` of the `.ini` files into a line break.
- **FR-001-11** — `domainLabel($domain)` MUST translate the module's own codes `NotMapped` and `DateAnchor` (keys `domain_NotMapped`, `domain_DateAnchor`) and return OMOP domains as they are.
- **FR-001-12** — The module MUST be the service factory: `store()` always returns the same `MappingStore` instance; `transformer()`, `exporter()`, `mappingDocument()`, `idRegistry()` and `pseudonymizer()` return new instances of their classes built with the settings; `vocabulary()` returns `null` if neither an index nor a WebAPI is configured; `validDomains()` is the list from `CdmModel`; `audit($msg, $params)` writes to the framework log, adding `module_version`.
- **FR-001-13** — `getDictionary()` and `profile()` MUST be computed only once per request and reused; `pid()` returns the project id as an integer.
- **FR-001-14** — Each project page MUST render the REDCap header and footer itself (`ProjectGeneral/header.php`, `ProjectGeneral/footer.php`) and handle any download beforehand, so as not to corrupt a binary output; the Control Center page uses `ControlCenter/header.php` and `ControlCenter/footer.php`.
- **FR-001-15** — In a real REDCap, the four links MUST appear in the menu with their translated name, the pages MUST be displayed with the REDCap header and menu, and the module configuration MUST offer the language selector and the six settings sections. `[manual]`

## Non-functional requirements

- **NFR-001-01** — No runtime dependencies: there is no `composer.json` or `vendor/`, and enabling the module runs nothing (there are no `redcap_module_system_enable` or `redcap_module_project_enable` hooks); no table is created.
- **NFR-001-02** — A thousand reads of the same setting in one request cost a single query.
- **NFR-001-03** — The code is compatible with PHP 7.4: it does not use `match`, the `?->` operator, `str_contains`/`str_starts_with`/`str_ends_with`, `#[...]` attributes, `enum` or `readonly`.

## Acceptance criteria

```gherkin
Scenario: parity of the language files
  Given lang/English.ini and lang/Spanish.ini
  When their keys are compared
  Then there is no key that is in one and not in the other
  And each key has the same {n} placeholders in both

Scenario: project language
  Given a project with reserved-language-project = Spanish
  When the module asks for the text domain_NotMapped
  Then it gets «Sin mapear»

Scenario: a setting is queried once
  Given a module whose setting cdm-holder is «Hospital»
  When setting('cdm-holder') is called twice
  Then getProjectSetting('cdm-holder') has run only once

Scenario: writing keeps the cache
  Given that setting('cdm-holder') has already been read
  When writeSetting('cdm-holder', 'Other') is called
  Then setting('cdm-holder') returns «Other» without a new query

Scenario: plain text for JavaScript
  Given the text r_keyword = "Matches '{0}' in the field name or label"
  When ttText('r_keyword', "O'Brien <b>") is called
  Then the result contains «O'Brien <b>» without HTML entities
```

## Out of scope

- Who sees each link and who can edit or export (spec 012,
  `redcap_module_link_check_display`, `userCanEditMapping`, `exportBlockedBy`).
- The content of each page: dashboard (005), editor (007), export (011),
  vocabulary (014).
- The meaning of each setting in the transformation (009, 010, 016, 017).
- Languages other than Spanish and English: copying an `.ini` is enough, but no
  others are shipped.

## Manual verification

**FR-001-15**, on a development REDCap (for example, the `rc1_web`
container):

1. *Control Center → External Modules → Enable a module*: «OMOP-CDM Export»
   appears with its description. Enabling it shows no errors and does not ask
   to download anything.
2. In a project, *External Modules → Enable*. The left-hand menu shows
   «OMOP-CDM — panel de mapeo», «— editor de mapeo» and «— exportar» (or their
   English equivalents «OMOP-CDM — mapping dashboard», «— mapping editor» and
   «— export»).
3. *Configure*: the framework's language selector appears, together with the
   sections «1. Origen de los datos» to «6. Notas y kit de carga» («1. Data
   source» to «6. Notes and load kit» in English), with the *field-list*
   dropdowns populated with the project's fields.
4. Switch the language to *English* and reload the dashboard: titles, buttons
   and classifier reasons come out in English. Switch back to *Spanish*: they
   come out in Spanish.
5. Open the three pages: each one shows the REDCap header, project menu and
   footer, with no misplaced text or PHP warnings.
6. *Control Center*: the link «OMOP-CDM — vocabulario» («OMOP-CDM —
   vocabulary») opens the page with the Control Center header.

## Amendments

| Date | Requirement | Change | Prompted by |
|---|---|---|---|
| — | — | Initial version | — |
