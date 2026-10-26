# Plan 018 — Validation with realistic projects

## Technical summary

The validation has two parts with very different costs:

1. **Automatic and permanent**, inside `php tests/run.php`: a CSV corpus with
   the 186 fields and their expected domain (`tests/fixtures/eval10/fields.csv`),
   generated from the specification of the projects, on which the real
   classifier is measured; the multicentre scenario P10 → P5 reproduced in
   memory; and checks that the evaluation material is complete, consistent
   and free of secrets.
2. **Manual and one-off**, with a real REDCap and PostgreSQL: create the 10
   projects, curate them with the same requests as the editor, export them,
   load them and run the integrity checks. The scripts live in `tests/eval/`
   and write everything that contains secrets or new results outside the
   module.

## Files

| File | Action | Responsibility |
|---|---|---|
| `tests/eval/specs.php` | Create | The 10 projects and their reference mapping |
| `tests/eval/build_corpus.php` | Create | `specs.php` → `tests/fixtures/eval10/fields.csv` |
| `tests/fixtures/eval10/fields.csv` | Create (generated) | Versioned regression corpus |
| `tests/eval/generate.php`, `configure.php`, `curate.php`, `measure.php`, `summary.php`, `client.php` | Create | Full evaluation in REDCap |
| `tests/eval/export_all.sh`, `checks.sh`, `checks.sql`, `integrity.sql` | Create | Export, load and checks in PostgreSQL |
| `tests/eval/results/` | Create | `results_round1.json`, `classifier_round2.json`, `checks.txt` |
| `tests/eval/README.md` | Create | Procedure and rules |
| `tests/specs/018_multiproject_validation.test.php` | Create | Tests of this spec |
| specs 004, 006, 012, 014, 015 | Amend | Each anomaly, in its own spec |

## Design

### Reference mapping (`specs.php`)

```php
F('sexo', 'demografia', 'radio', 'Sexo', ['ch' => '1, Hombre | 2, Mujer', 'g' => ['cat', ['1' => 60, '2' => 40]],
   'm' => ['d' => 'Person', 's' => 'per_choice', 'v' => ['1' => [8507, 'MALE'], '2' => [8532, 'FEMALE']]]]),
F('nyha', 'exploracion', 'radio', 'Clase funcional NYHA', ['ch' => '1, I | 2, II | 3, III | 4, IV', …, 't' => 'Observation|Measurement']),
F('iniciales', 'demografia', 'text', 'Iniciales', ['id' => 'y', 'g' => ['initials'], 'm' => MX()]),   // MX() = ['x' => true]
```

`g` is the data generator; `m`, the curator's mapping (`d`, `c`, `cn`, `s`,
`v`, `u`, `date`, `end`, `x`, and `c = 'sug:<word>'` to look it up with the
wand); `t`, the acceptable domains if it is not mapped. Field names, labels
and choices of the Spanish projects are data and stay in Spanish.

### Corpus

```
lang,project,field_name,form_name,field_type,label,choices,validation,identifier,expected
es,P1,record_id,demografia,text,"Código del paciente",,,,Person
es,P1,iniciales,demografia,text,Iniciales,,,y,NotMapped
es,P1,nyha,exploracion,radio,"Clase funcional NYHA","1, I | 2, II | 3, III | 4, IV",,,Observation|Measurement
```

Rule for `expected` (the same as `measure.php`): `m.x` → `NotMapped`;
otherwise `m.d`; otherwise `t`; and `record_id` → `Person`. Fixed order
P1…P10 and fields in dictionary order, so that the diff is readable.
`fputcsv` with `escape = ''`.

### Measurement

```php
foreach ($byProject as $p => $rows) {
    $dict = /* f(field_name, form_name, field_type, label, choices, validation, '', identifier) */;
    $res  = (new DictionaryProfiler())->profile($dict, ['record_id_field' => $rows[0]['field_name']]);
    // hit if $res[$field]['suggested_domain'] ∈ explode('|', expected)
}
```

The profile is computed per project (not field by field) because that is
what the module does, and the first field is the `record_id_field`, as in
REDCap. The measurement of the holdout benchmark repeats the one from spec 004
(field by field, `record_id`/`study_id` as the identifier).

### Multicentre scenario

```
\REDCap::$dict = P10 dictionary from the corpus
London: sex (per_choice 8507/8532), discharge_med (per_choice, "None" does not emit),
        glucose (3004501, mg/dL) approved; ct_result excluded
$doc = json_decode(mappingDocument()->json())          // the MAPEO.json as it is
\REDCap::$dict = P5 dictionary from the corpus
Madrid: store()->importMapping($doc, profile(), $dict)
```

### Evaluation material without secrets

- First line of every PHP file: `if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }`
  (the web server publishes the module folder).
- `$WORK = getenv('OMOP_EVAL_WORK') ?: '/tmp/omoptest'` for `projects.json`,
  cookies and results; `OMOP_EVAL_BASE` for the URL; `OMOP_EVAL_DB_*` for the
  REDCap database.

## Decisions and discarded alternatives

- **A generated CSV corpus, not `specs.php` in the test.** `specs.php` defines
  global functions (`F`, `MX`…) that clash with those of
  `tests/lib/fixtures.php` (PHP function names are case-insensitive: `F` is
  `f`). The test runs `build_corpus.php` in a separate process to check that
  the CSV is up to date. *Discarded:* maintaining the CSV by hand, which gets
  out of sync.
- **Thresholds of 97 % overall and 95 % per language.** They leave a margin of
  one or two fields with respect to what was measured (98.9 %, 97.9 %)
  without allowing real regressions. *Discarded:* requiring exactly 184/186,
  which would make the test fail when the classifier improves.
- **Fix in the affected spec.** 018 does not touch code: each anomaly is an
  amendment with its requirement and its test. *Discarded:* a list of "018
  fixes", which would leave the specs not saying what the code does.
- **REDCap scenarios by hand.** Creating projects, users and DAGs or loading
  into PostgreSQL is not possible without those servers; the scripts make
  them reproducible. *Discarded:* simulating them in `tests/bootstrap.php`,
  which would test nothing new.

## Test strategy

- `tests/specs/018_multiproject_validation.test.php`: corpus integrity,
  regeneration, measurement overall, per language and on the holdout
  benchmark (it prints the misses unless `--quiet`), the P10 → P5 scenario and
  checks of the material (`tests/eval/`).
- Manually: FR-018-08 to FR-018-12 with `tests/eval/README.md`.

## Constitution check

- **9 (every discarded record is counted):** the record without a birth date
  in each project is discarded and the report explains it.
- **11 and 12 (read only, rights and DAGs):** team scenarios in P4.
- **19 and 20 (TDD and traceability):** each anomaly has its amendment and its
  test in the affected spec; this spec measures.
- **21 (bilingual):** accuracy measured and required per language.
- AGENTS.md: the holdout benchmark was not looked at while fixing and its
  threshold is not touched; `projects.json` and the tokens are not versioned.

## Risks

- Overfitting: the round 2 fixes were made while looking at these 186 fields.
  The holdout benchmark (26/27, unchanged) is the honest measure; the eval10
  corpus is now a development set.
- One person wrote the projects, though imitating different teams: the
  vocabulary of the labels is less varied than in real life.
- `export_all.sh` carries the pids of the original run; they have to be
  adjusted.
