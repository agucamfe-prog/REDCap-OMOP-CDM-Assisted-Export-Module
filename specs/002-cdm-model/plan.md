# Plan 002 — OMOP CDM v5.4 model

## Technical summary

`classes/CdmModel.php` is a stateless class with constants and static
methods. Tables and columns are written by hand in the order of the official
file (`tables()`), because they are used as is for the CSV headers and
positional loaders require it. The official file and its `NOTICE.txt` live in
`resources/`; the tests read the file and compare column by column, so any
divergence makes the suite fail.

## Files

| File | Action | Responsibility |
|---|---|---|
| `classes/CdmModel.php` | Create | Tables, columns, NOT NULL, lengths, domains, operators, UCUM, genders |
| `resources/OMOP_CDMv5.4_Field_Level.csv` | Add (unmodified) | Official OHDSI specification, for the tests |
| `resources/NOTICE.txt` | Create | Origin, author and licence of the third-party files |
| `OmopCdmExportModule.php` | Modify | `validDomains()` delegates to `CdmModel` |
| `tests/specs/002_cdm_model.test.php` | Create | Tests for this spec |

## Design

```php
namespace OmopCdm\OmopCdmExportModule;

class CdmModel
{
    const DOMAIN_TABLE = ['Person' => 'person', 'Visit' => 'visit_occurrence', /* … 11 */];
    const OPERATORS    = ['<' => 4171756, '<=' => 4171754, '=' => 4172703, '>=' => 4171755, '>' => 4172704];
    const UCUM_BUILTIN = ['kg' => 9529, 'cm' => 8582, 'mm[Hg]' => 8876, '%' => 8554,
                          'g/dL' => 8713, 'mg/dL' => 8840, 'mmol/L' => 8753, 'umol/L' => 8749];
    const GENDER_MALE = 8507; const GENDER_FEMALE = 8532; const GENDER_UNKNOWN = 8551;
    const CONCEPT_NO_MATCH = 0;
    const TEXT_LIMITS  = ['person_source_value' => 50, 'stop_reason' => 20, 'sig' => null,
                          'value_as_string' => 60, 'cdm_source_abbreviation' => 25, /* … */];
    const REQUIRED     = ['person' => ['person_id', 'gender_concept_id', 'year_of_birth',
                                       'race_concept_id', 'ethnicity_concept_id'], /* … 17 tables */];

    public static function validDomains(): array            // 11 + DateAnchor + NotMapped
    public static function tables(): array                  // table => [column, …] in official order
    public static function columns(string $table): array    // [] if it does not exist
    public static function emptyRow(string $table): array   // array_fill_keys(columns, null)
    public static function missingRequired(array $tables): array   // table => column => number of rows
    public static function fit(string $column, $value)      // mb_substr to the limit
    public static function ucumCode(?string $unit): ?string
    public static function tableForDomain(string $domain): ?string
}
```

### Examples

```php
CdmModel::columns('death');
// ['person_id', 'death_date', 'death_datetime', 'death_type_concept_id',
//  'cause_concept_id', 'cause_source_value', 'cause_source_concept_id']

CdmModel::missingRequired(['death' => [
    ['person_id' => 1, 'death_date' => ''],
    ['person_id' => null, 'death_date' => '2020-01-01'],
]]);
// ['death' => ['person_id' => 1, 'death_date' => 1]]

CdmModel::ucumCode('µmol/l');  // 'umol/L'
CdmModel::fit('stop_reason', str_repeat('á', 30));  // 20 × 'á'
```

`ucumCode()` normalises with
`strtolower(str_replace([' ', 'µ', 'μ'], ['', 'u', 'u'], trim($unit)))` and
looks the result up in an alias table (`mmhg`, `mm[hg]` → `mm[Hg]`; `bpm`,
`lpm`, `lat/min` → `{beats}/min`; `°c`, `ºc`, `c`, `cel` → `Cel`; `años`,
`years` → `a`…).

`fit()` uses `mb_substr` if `mbstring` is available and `substr` otherwise.

## Decisions and rejected alternatives

- **Columns written by hand and checked by tests.** The official file is
  large and changes rarely; having it in code makes the model readable and
  free of I/O. *Discarded:* reading the CSV on every request, because it adds
  a disk read per export and a point of failure in production.
- **Only 17 tables.** Those the module can fill from a REDCap project, plus
  `location` (empty) so that the set loads completely. *Discarded:* all 39
  tables, because exporting tables that are always empty is confusing.
- **`REQUIRED` for `location` too.** Today it travels empty, but if it ever
  receives rows, `missingRequired()` already watches its `location_id`.
  *Discarded:* skipping tables without rows, because the gap would only show
  up at load time.
- **Only eight built-in UCUM units.** A wrong `unit_concept_id` is worse than
  an empty one (principle 10). The rest come from the vocabulary.
  *Discarded:* a broad unit table that cannot be verified.
- **Truncation by characters.** Truncating by bytes would split an «á» and
  leave invalid UTF-8.

## Testing strategy

`tests/specs/002_cdm_model.test.php` reads the official file with `fgetcsv`
and, for every table of the model:

- compares the list of columns (order included);
- compares `REQUIRED` with the `isRequired = TRUE` rows;
- checks that every column in `TEXT_LIMITS` is `varchar(n)` (or
  `varchar(max)` if the limit is `null`) in every table of the model that has
  it.

In addition: concrete cases of `columns`, `emptyRow`, `tableForDomain`,
`missingRequired`, `fit`, `ucumCode`; exact values of `OPERATORS`,
`UCUM_BUILTIN` and the genders; `NOTICE.txt` and the existence of the files it
lists. Nothing is verified by hand.

## Constitution check

- **10 (a wrong concept is worse than an empty one):** only eight safe units.
- **14 (no dependencies):** the official file is shipped; nothing is
  downloaded.
- **15 (official CDM v5.4):** columns, order and NOT NULL are checked against
  the file on every run.

## Risks

- A future version of the official file: replace the CSV, run the tests and
  fix `CdmModel` until they pass.
- A new bounded column that is not in `TEXT_LIMITS` is not truncated; the
  test only checks the ones that are listed.
