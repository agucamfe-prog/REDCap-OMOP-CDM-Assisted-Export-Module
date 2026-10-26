# Plan 016 — Pseudonymization

## Technical summary

A small class with no dependencies, `Pseudonymizer`, knows how to shift a date
for a record and trim the precision of the date of birth, and says whether
free text must be suppressed. The module builds it from the project settings
and the salt (`pseudonymizer()`), and the `Transformer` consults it at the few
points every date and every text go through: the fact date, the end date, the
occasion date, the date of death, the birth date, the value of a text field
and the note. The hash of `person_source_value` lives in the module
(`hashPersonSourceValue()`), because the export page uses it too. The report
(`CsvExporter`) reads the settings and the stats to declare it.

## Files

| File | Action | Responsibility |
|---|---|---|
| `classes/Pseudonymizer.php` | Create | Date shift, birth precision, text suppression |
| `OmopCdmExportModule.php` | Modify | `projectSalt()`, `hashPersonSourceValue()` and the body of the `pseudonymizer()` factory (spec 001), which passes the settings and the salt |
| `classes/Transformer.php` | Modify | Applies the shift, the precision and the suppression |
| `classes/CsvExporter.php` | Modify | "Pseudonymisation applied" section of the report |
| `config.json` | Modify | Settings `hash-person-source-value`, `date-shift-days`, `birth-precision`, `suppress-free-text` |
| `lang/Spanish.ini`, `lang/English.ini` | Modify | `rep_privacy_applied`, `rep_date_shift`, `rep_birth_month`, `rep_birth_year`, `rep_free_text_suppressed`, `rep_hashed` and the setting labels |
| `tests/specs/016_pseudonymization.test.php` | Create | Tests for the spec |

## Design

### API

```php
class Pseudonymizer
{
    public function __construct(int $shiftDays, string $birthPrecision, bool $suppressFreeText, string $salt);
    public function shiftsDates(): bool;            // shiftDays > 0
    public function suppressesFreeText(): bool;
    public function birthPrecision(): string;       // 'full' | 'month' | 'year'
    public function offset(string $recordId): int;  // [-N, N] \ {0}
    public function shift(?string $date, string $recordId): ?string;  // Y-m-d
    public function birth(array $row): array;       // PERSON row
}

// OmopCdmExportModule
public function projectSalt(): string;
public function pseudonymizer(): Pseudonymizer;
public function hashPersonSourceValue(string $recordId): string;
```

### Shift

```php
$h   = hexdec(substr(hash('sha256', $salt . '|shift|' . $recordId), 0, 8));
$off = ($h % (2 * $N + 1)) - $N;     // uniform over [-N, N]
$off = $off === 0 ? $N : $off;       // never 0
```

Example with N = 30 and a specific salt: record `'1'` → −13, `'2'` → +12.
Record 1's weight of 2024-03-01 comes out on 2024-02-17.

`shift()` uses `DateTime::createFromFormat('!Y-m-d', $date)` and
`modify('±n days')`; it returns the input if `N = 0` or if it is `null`, and
`null` if it is not a real date: `createFromFormat()` returns `false` or
normalizes ("2024-13-01" → 2025-01-01), so `format('Y-m-d')` is required to
reproduce the input. The `Transformer` only passes it dates already validated
by `parseDate()`.

### Salt and hash

`projectSalt()` reads `person-hash-salt`; if it is empty,
`bin2hex(random_bytes(16))`, saved with `writeSetting()`. `pseudonymizer()`
only asks for the salt if `date-shift-days > 0` (otherwise it passes `''`).
`hashPersonSourceValue()`:
`substr(hash('sha256', projectSalt() . '|' . $recordId), 0, 32)` with the
setting on; the Record ID otherwise.

### Points of application in the Transformer

| Where | What |
|---|---|
| `buildPerson()` | `shift()` of the birth date, then `birth()`; the standalone year from the year field is left untouched |
| `factDate()` | `shift(realFactDate())` |
| `endDate()` | `shift()` of the end date, before comparing it with the start |
| `collectOccasions()` | `shift()` of the occasion date (start of the visit) |
| `deathDate()` | `shift()` of the date of death |
| `transformField()` | if `_kind === 'text'`, `suppressesFreeText()` and the value is not a number: in `measurement`, `skip('free_text_no_value', $field)` and nothing is emitted; in the other tables, `value_string = value_source = null` and `free_text_suppressed++`. With a number there is nothing to remove or to count |
| `emitNote()` | if `suppressesFreeText()`: emits nothing and `notes_suppressed++` |
| `run()` | `stats['date_shift'] = 1` if `shiftsDates()` |

Since `OBSERVATION_PERIOD` and `VISIT_OCCURRENCE` are built from the fact
dates, they come out shifted with no extra code.

### Report

```
PSEUDONYMISATION APPLIED
------------------------------------------------------------
  · Dates shifted by up to 30 days, ...
  · Date of birth reduced to the year.
  · 5 free-text answer(s) not exported ('do not export free text' setting).
  · person_source_value is hashed: the CDM does not contain the Record ID.
```

## Decisions and rejected alternatives

- **One shift per person, not per date.** It preserves ages and intervals.
  *Rejected:* independent noise on each date, which destroys the sequence of
  facts.
- **The shift is derived from the salt, not stored.** It is the same in every
  export without storing a per-person table. *Rejected:* storing the shifts in
  the settings.
- **Never zero.** *Rejected:* accepting the 0 of the uniform distribution,
  which would leave the real dates of ~1/(2N+1) of persons.
- **Precision after the shift.** The year and month exported are those of the
  shifted date, consistent with the rest of the person's dates. *Rejected:*
  trimming the real date, which would give ages inconsistent with the shifted
  facts.
- **Suppress the text, not the row.** The fact happened and the number, if
  any, is useful. *Rejected:* dropping the whole row, which would lose
  structured data. Exception: a measurement with neither number nor text says
  nothing and is dropped with its own reason (`free_text_no_value`), which the
  report lists among the drops with `skip_free_text_no_value`.
- **Count only what was removed.** `free_text_suppressed` counts rows that lost
  their text. *Rejected:* counting every free-text row processed, which
  overestimated what was not exported.
- **A standalone year of birth is not shifted.** The shift is in days and a
  standalone year has no day; moving it ±1 year depending on the sign would
  invent data. *Rejected:* shifting 1 January or 1 July of that year, which
  changes the year of many persons for no reason.
- **An impossible date yields `null`.** *Rejected:* letting `DateTime`
  normalize it, which would shift a day that does not exist.
- **A random salt per project.** *Rejected:* a fixed module salt, which would
  make the hash reversible by dictionary attack for anyone with the code.

## Testing strategy

`tests/specs/016_pseudonymization.test.php`:

- `Pseudonymizer` unit tests with salt `'sal'`: stability, range, spread over
  40 records, another salt, N = 1 (only −1 and +1), intervals, N = 0 and
  negative, `null` and non-date text, the three precisions and an unknown one.
- Default export (`make_module()` + `curate()`): nothing applied and no salt.
- Export with the four settings and two added text fields (`symptoms` in
  Observation, `death_cause` in Death): salt, hash, fact, end, visit, death and
  period dates moved exactly by their person's shift (computed with
  `new Pseudonymizer(30, 'full', false, $salt)`), `year` and `month` precision,
  suppressed text (exact count: 2), glucose "1.234" dropped as
  `free_text_no_value|glu`, NOTE not generated.
- Characterization of the standalone year: a `yob` field with shifting on.
- Report: `writeZip()` and reading `INFORME.txt` with `ZipArchive`, with
  whitespace normalized because the report wraps lines at 68 columns.

## Constitution check

- **III.13 Pseudonymization available and declared**: FR-016-10; the report
  talks about pseudonymization, never anonymization (NFR-016-03).
- **III.11 Read-only**: the salt lives in the module settings.
- **III.14 No external dependencies**: PHP's `hash()` and `random_bytes()`.
- **II.9 Every drop is counted**: `free_text_suppressed`, `notes_suppressed`.

## Risks

- **Losing the salt** changes every hash and shift of the next export: it can
  no longer be linked with the previous ones.
- **A standalone year of birth is not shifted** (a decision, FR-016-12): with
  a large N, the age computed at a shifted fact may differ from the real one
  by a year.
