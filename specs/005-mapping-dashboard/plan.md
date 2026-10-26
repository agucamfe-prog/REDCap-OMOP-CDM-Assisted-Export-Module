# Plan 005 — Mapping dashboard

## Technical summary

A module framework page, `pages/dashboard.php`, that reads the classifier's
proposal (`$module->profile()`, spec 004) and the project structure
(`$module->getProjectStructure()`), and renders it: header, cards, breakdown by
target and table. The count per domain is done by a module method,
`profileSummary()`, so that it can be tested without rendering anything.

The two downloads (`?download=csv` and `?download=json`) are resolved at the
top of the file, before including the REDCap header; they write the response
and end with `exit`. The table filter is a ten-line `<script>` that does not
talk to the server. The page writes no setting.

## Files

| File | Action | Responsibility |
|---|---|---|
| `pages/dashboard.php` | Create | Downloads, header, cards, breakdown, table and filter |
| `OmopCdmExportModule.php` | Modify | `profileSummary()`, `audit()` |
| `config.json` | Modify | Dashboard link in the project menu |
| `lang/Spanish.ini`, `lang/English.ini` | Modify | `dash_*`, `card_*`, `domain_breakdown`, `btn_csv`, `btn_json`, `filter_placeholder`, `th_*`, `badge_*`, `lbl_unit`, `lbl_options`, `conf_*`, `repeating_note`, `domain_NotMapped`, `domain_DateAnchor` |
| `tests/support/run_page.php` | Create | Run a page in a separate process and return its output |
| `tests/specs/005_mapping_dashboard.test.php` | Create | Tests for this spec |

## Design

### Module methods

```php
// Count per suggested_domain, from highest to lowest (arsort).
public function profileSummary(array $profile): array
// ['Measurement' => 4, 'Person' => 3, 'Condition' => 2, ...]

// Log entry with the module version added.
public function audit(string $message, array $params = []): void
// log('Mapping proposal download (CSV)', ['module_version' => 'v1.0.0'])
```

### Profile row rendered by the table (from spec 004)

```php
'weight' => [
    'field_name' => 'weight', 'form_name' => 'base', 'field_type' => 'text',
    'validation' => 'number', 'field_label' => 'Weight (kg)', 'is_identifier' => false,
    'value_kind' => 'numeric', 'unit_hint' => 'kg', 'suggested_strategy' => null,
    'mappable' => 'auto', 'choices' => [], 'choice_count' => 0,
    'suggested_domain' => 'Measurement', 'suggested_table' => 'measurement',
    'confidence' => 'medium', 'reason' => "Matches 'weight' in the field name or label. …",
]
```

### Flow of `pages/dashboard.php`

1. `$structure`, `$profile`, `$summary`, `$cdmVer = $module->setting('cdm-version') ?: '5.4'`
   (`setting()` caches per request, AGENTS rule). Domain labels come from
   `$module->domainLabel()`, which only translates `NotMapped` and `DateAnchor`.
2. `download === 'json'`: headers `Content-Type: application/json; charset=utf-8` and
   `Content-Disposition: attachment; filename="omop_propuesta_pid{pid}_{Ymd}.json"`;
   `json_encode` with `JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES`
   of the object `{generated: date('c'), project_id, cdm_version, structure, proposal}`;
   `audit(...)`; `exit`.
3. `download === 'csv'`: CSV headers; `fopen('php://output')`; BOM
   `"\xEF\xBB\xBF"`; `fputcsv` of the header and of one row per field
   (`is_identifier ? 'y' : ''`, `unit_hint ?? ''`); `audit(...)`; `exit`.
4. Any other value: `require APP_PATH_DOCROOT . 'ProjectGeneral/header.php'`,
   card counts with `array_filter` over the profile, HTML, filter script and
   `footer.php`.

CSV example:

```
field_name,form_name,field_type,validation,field_label,is_identifier,value_kind,unit_hint,mappable,choice_count,suggested_domain,suggested_table,confidence,reason
weight,base,text,number,"Weight (kg)",,numeric,kg,auto,0,Measurement,measurement,medium,"Matches 'weight' …"
```

Cards: `total = count($profile)`; `with a target = total − summary['NotMapped']`;
`low = confidence === 'low'`; `manual = mappable === 'manual'`;
`identifiers = is_identifier`; events = `count($structure['events'])` if
`longitudinal`, otherwise `1` with the label `card_single_event`.

Confidence badges: `['high' => 'badge-success', 'medium' => 'badge-warning', 'low' => 'badge-secondary']`.

## Decisions and discarded alternatives

- **The dashboard shows the proposal, not the saved mapping.** It is the
  classifier's snapshot for the first conversation; decisions live in the
  editor. *Discarded:* mixing states and proposal in the same table, because it
  duplicates the editor and confuses "proposed" with "decided".
- **Downloads before the header.** A single byte of HTML before the BOM breaks
  the CSV in Excel. *Discarded:* a separate page for downloads, which would
  duplicate loading the profile.
- **BOM in the CSV.** Without it, Excel on Windows reads UTF-8 as ANSI and
  mangles the accents of Spanish labels.
- **Filter in the browser.** The whole table is already on the page; filtering
  on the server would force a reload. *Discarded:* pagination, because projects
  rarely exceed a few hundred fields.
- **No XLSX.** It would need an external library (principle 14).

## Test strategy

- `tests/specs/005_mapping_dashboard.test.php` with the test project from
  `fixtures.php` (18 fields, one repeating instrument).
- `profileSummary()` is tested directly.
- The page is run with `tests/support/run_page.php`: a child PHP process with
  the same bootstrap, which replaces `header()` with a function in the module's
  namespace (so headers can be read in CLI) and leaves
  `ProjectGeneral/header.php` and `footer.php` as the markers `<!--rc-header-->`
  and `<!--rc-footer-->`. The separate process is needed because downloads end
  with `exit`. It returns output, headers, settings and log.
- Checked: the cards (parsing `<div class="n">`), the breakdown, the table
  rows, the badges, escaping with a field that contains `&`, quotes and
  `<script>`, that settings do not change, the CSV line by line, the JSON
  against `profile()` and `getProjectStructure()`, and the log.
- By hand: the filter (FR-005-12) and the visual appearance.

## Constitution check

- **Principle 11 (read-only):** the dashboard writes neither to the project nor
  to the module settings; a test checks it.
- **Principle 21 (bilingual):** every text through `tt()`; a test checks that
  each key is in both `.ini` files.
- **Principle 22 (explain):** each row carries the reason for the proposal.
- **Principle 14 (no dependencies):** CSV with `fputcsv`, native JSON.

## Risks

- **Projects with thousands of fields:** the table grows without pagination.
  Acceptable: the filter helps and the editor has the same size.
- **The reason comes from `tt()`, which escapes its arguments,** and the
  dashboard escapes it again: an argument with `&` or quotes would be shown as
  an entity. Today the classifier's arguments (terms, units, types) do not
  contain them.
