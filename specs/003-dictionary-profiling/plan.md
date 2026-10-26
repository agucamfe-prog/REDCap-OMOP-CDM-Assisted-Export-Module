# Plan 003 — Data dictionary profiling

## Technical summary

`classes/DictionaryProfiler.php` walks the dictionary and, for each field,
calls `profileField()`, which applies a fixed sequence of structural rules
(Record ID, types without a value, identifier, date) and, if none of them
decides, works out the kind of value and the unit and hands the field to the
semantic classifier (spec 004). Every output goes through a single row
builder, `row()`, which adds the derived fields (`suggested_strategy`,
`mappable`, `suggested_table`, `choice_count`), so that no rule can forget
them.

The module adds the extraction layer: `getDictionary()`,
`getProjectStructure()` and the candidate dates (`dateFields()`,
`isDateField()`, `suggestedDateField()`), which need the full dictionary and
the settings.

## Files

| File | Action | Responsibility |
|---|---|---|
| `classes/DictionaryProfiler.php` | Create | `profile()`, `profileField()`, `row()`, `valueKind()`, `extractUnit()`, choices, strategy, mappability |
| `classes/TextNormalizer.php` | Create (minimal) | `normalize()`, to compare units and labels (extended in 004) |
| `OmopCdmExportModule.php` | Modify | `profile()` with translator, `getProjectStructure()`, `dateFields()`, `isDateField()`, `suggestedDateField()`, `parseChoices()` |
| `tests/specs/003_dictionary_profiling.test.php` | Create | Tests for this spec |

## Design

### Public signatures

```php
class DictionaryProfiler
{
    const D_SKIP = 'NotMapped';  const D_DATE_ANCHOR = 'DateAnchor';  /* + D_PERSON … D_SPECIMEN */
    const IMPLICIT_CHOICES = ['yesno' => ['1' => 'Yes', '0' => 'No'],
                              'truefalse' => ['1' => 'True', '0' => 'False']];

    public function __construct(?callable $translator = null)
    public function profile(array $dictionary, array $structure = []): array
    public static function choicesFor(array $meta): array
    public static function parseChoices(string $raw): array
    public static function suggestedStrategy(string $valueKind, array $choices, string $domain): ?string
    public static function looksBinaryChoices(array $choices): bool
    public static function looksLikeRatingScaleStatic(array $choices): bool
}
```

### Profile row (example: `weight` from the test project)

```php
[
  'field_name' => 'weight', 'form_name' => 'base', 'field_type' => 'text',
  'validation' => 'number', 'field_label' => 'Weight (kg)', 'is_identifier' => false,
  'value_kind' => 'numeric', 'unit_hint' => 'kg', 'suggested_strategy' => null,
  'mappable' => 'auto', 'choices' => [], 'choice_count' => 0,
  'suggested_domain' => 'Measurement', 'suggested_table' => 'measurement',
  'confidence' => 'medium',
  'reason' => "Matches 'weight' in the field name or label. Unit detected in the label: 'kg'",
]
```

### Order of `profileField()`

1. `field_name === $structure['record_id_field']` → `Person`, `identifier`, `high`, `r_record_id`.
2. Type in `['descriptive', 'file', 'signature', 'calc']` → `NotMapped`, `none`, `high`, `r_skip_type`.
3. Flagged as an identifier and neither a date nor sex → `NotMapped`, `high`, `r_phi_flag`.
4. Date validation → date rules of spec 004; if there is no signal, `DateAnchor`, `high`, `r_date_anchor`.
5. `valueKind()` and, if it is `numeric`, `extractUnit()`.
6. Semantic classification (spec 004) and reason suffixes (`r_sfx_unit`, `r_sfx_phi`…).

### valueKind()

| Input | Result |
|---|---|
| `radio`, `dropdown`, `checkbox`, `yesno`, `truefalse` | `categorical` |
| `slider` | `numeric` |
| `notes` | `text` |
| validation `integer`, `number`, `number_1dp` … `number_4dp` | `numeric` |
| anything else | `text` |

(`date`, `identifier` and `none` are assigned directly by steps 1, 2 and 4.)

### extractUnit()

```php
if (!preg_match('/\(([^()]{1,20})\)\s*$/u', $label, $m)) return null;
$c = trim($m[1]);
if (preg_match('#[/%^°]#u', $c)) return $c;                     // «mg/dL», «%», «°C»
return in_array(TextNormalizer::normalize($c), $this->knownUnits, true) ? $c : null;
```

`knownUnits`: mass (`kg`, `g`, `mg`, `mcg`, `ug`…), length, volume, pressure
(`mmhg`, `cmh2o`…), rate (`lpm`, `rpm`, `bpm`), time (`min`, `h`, `dias`,
`semanas`, `meses`, `anos`…), international units, moles, energy and counts
(`puntos`, `unidades`, `copias`, `celulas`).

### Mappability and strategy

```php
mappability($domain, $kind): 'no' if NotMapped; 'manual' if text and not Note; otherwise 'auto'
suggestedStrategy($kind, $choices, $domain):
    null             if kind ≠ categorical or no choices
    'single_concept' if looksLikeRatingScaleStatic($choices)
    'value_concept'  if domain ∈ {Measurement, Observation} and !looksBinaryChoices($choices)
    'per_choice'     otherwise
```

`looksBinaryChoices`: exactly two choices and one of the labels is
`si/no/yes/true/false/verdadero/falso` (normalised). «Positivo/Negativo»
("Positive/Negative") is not binary: it is a result and goes as
`value_concept`.

### Translator

```php
private function t(string $key, ...$args): string
{
    return $this->translator !== null
        ? call_user_func($this->translator, $key, ...$args)
        : ($args ? $key . '(' . implode('|', $args) . ')' : $key);
}
```

### Module: structure and dates

`getProjectStructure()` creates `new \Project($pid)` and returns (test
project):

```php
['project_id' => 1, 'record_id_field' => 'record_id', 'longitudinal' => false,
 'surveys_enabled' => false, 'forms' => ['base', 'meds'], 'events' => [],
 'repeating' => [['event_id' => 1, 'form' => 'meds']]]
```

If `RepeatingFormsEvents[$event]` is not an array (REDCap stores `'WHOLE'`
for a whole repeating event), `['event_id' => $event, 'form' => '']` is added.

`suggestedDateField('weight')` in the test project: instrument `base`,
candidates `visit_date`, `death_date` (`dob` is excluded, as it is the
`birth-date-field` setting) → the first one, `visit_date`.

## Decisions and rejected alternatives

- **The profiler receives arrays and a translator, not the module.** It is
  tested without REDCap and reused for the benchmark. *Discarded:* passing it
  the module, which would force simulating the whole framework.
- **The identifier flag overrides the heuristics**, with two exceptions the
  CDM needs (date of birth → `year_of_birth`, sex → `gender_concept_id`).
  *Discarded:* exempting any Person term, because a flagged postal code ended
  up as PERSON data.
- **Unit only from a list or with a symbol.** The final parenthesis of a real
  label contains all sorts of things. Missing a unit costs the curator a
  second; inventing one propagates to `unit_source_value`.
- **Unit only in numeric fields.** In free text there is no value to measure.
- **Choices only for categoricals** (`DECLARED_CHOICE_TYPES = ['radio',
  'dropdown', 'checkbox']`). In a `calc` the same column holds the formula and
  in a `sql` a query: splitting them on commas would fabricate choices.
  *Discarded:* trusting the column to be empty.
- **Implicit choices for `yesno`/`truefalse`.** REDCap leaves their choices
  column empty; without synthesising them the rest of the module would treat
  the field as a single value and would also emit the «No».
- **Proposed date: the first one in the instrument.** When there are several,
  the first one in the dictionary is usually the date of the encounter.
  *Discarded:* returning `null` when there are several candidates, which left
  most real instruments without a date.

## Testing strategy

`tests/specs/003_dictionary_profiling.test.php`:

- Full profile of the test project (`make_module()->profile()`): keys, order,
  `value_kind`, choices, unit, strategy and mappability of specific fields
  (`weight`, `glu`, `comorb`, `fever`, `notes`, `site`, `bac`, `drug`…).
- A closure `$one(label, type, validation, choices, name, identifier,
  structure)` profiles a single field without a translator, for edge cases
  (`LDL (LDL)`, flagged `Postal code`, flagged `Sexo`, each type without a
  value).
- `parseChoices` with malformed chunks and labels containing commas.
- Structure with `RepeatingFormsEvents = [1 => 'WHOLE']` (restored afterwards).
- Candidate dates, adding to `\REDCap::$dict` a field in an instrument
  without dates; `reset_fixture()` at the end.
- Performance with a synthetic dictionary of 1,000 fields.

## Constitution check

- **1 (fail closed) and 13:** a flagged identifier does not reach the CDM.
- **7 (no interpretation):** the unit is only proposed if it is unambiguous.
- **11 (read only):** the profiler does not modify the dictionary.
- **22 (explain):** every row carries its reason, translated.

## Risks

- A new REDCap numeric validation (e.g. `number_comma_decimal`) would be
  profiled as `text`; it is added to the `valueKind()` list.
- A new REDCap categorical type with declared choices would have to be added
  to `DECLARED_CHOICE_TYPES`; until then it would be profiled without choices.
