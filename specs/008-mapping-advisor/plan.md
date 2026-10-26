# Plan 008 — Mapping advisor

## Technical summary

`classes/MappingAdvisor.php` receives the mapping of **one** field
(specification, classifier profile, stored options and data dictionary
metadata) and returns a list of warnings. Each rule is a private `check…()`
method that appends warnings to `$out` by reference; `check()` calls them in a
fixed order. The rules ask the module for what is not in the field: the
default date, the PERSON, DEATH and visit configurations, the data dictionary
and, if there is one, the vocabulary.

The module exposes `advise($field)` (with a per-request cache that the store
invalidates on save) and `adviseAll()`. The two statics `blocks()` and
`needsConfirm()` are the contract with the editor (spec 007), which uses them
to decide whether to approve, ask for confirmation or refuse.

## Files

| File | Action | Responsibility |
|---|---|---|
| `classes/MappingAdvisor.php` | Create | Rules, severities, texts |
| `OmopCdmExportModule.php` | Modify | `advise()`, `adviseAll()`, `$adviceCache` in `invalidateMappingCache()` |
| `lang/Spanish.ini`, `lang/English.ini` | Modify | `adv_<code>` for the 33 codes of this spec, `adv_blocked`, `adv_confirm`, `adv_confirm_ask` |
| `tests/specs/008_mapping_advisor.test.php` | Create | Tests for this spec |

## Design

### Signatures

```php
public function __construct($module)
public function check(string $field, array $spec, array $prof, array $values, array $dictMeta): array
public static function blocks(array $warnings): bool
public static function needsConfirm(array $warnings): bool
private function w(string $severity, string $code, array $args = []): array
// ['severity' => 'warn', 'code' => 'date_other_form',
//  'text' => 'The chosen date (med_start) is on another instrument (meds). …']

// In the module
public function advise(string $field): array
public function adviseAll(): array      // [field => warnings], without excluded or empty ones
```

### Order of `check()` and codes

| Rule | Codes (severity) |
|---|---|
| (015) imported mapping | `import_labels_differ` (warn) — extension point |
| `checkIdentifier` | `identifier` (warn) |
| `checkFieldValidation` | `label_date_no_validation`, `label_numeric_no_validation`, `label_unit_no_validation` (warn, only the first) |
| `checkFreeText` | `free_text` (warn) |
| `checkVagueYesNo` | `vague_yesno` (warn), `yesno_in_domain` (info) |
| `checkMeasurementValue` | `measurement_no_unit`, `measurement_text`, `categorical_per_choice` (warn) |
| `checkRatingScale` | `scale_per_choice` (warn) |
| `checkScaleDomain` | `scale_wrong_domain` (warn) |
| `checkDateSanity` | `no_date_at_all` (block), `using_default_date` (info), `date_field_gone` (block), `date_other_form` (warn) |
| `checkDeath` | `death_no_date_config` (warn), `death_concept_is_cause` (info) |
| `checkVisitConflict` | `visit_conflict` (block) |
| `checkPersonDomain` | `person_not_configured`, `person_no_rows` (warn) |
| `checkNumericInValuelessDomain` | `numeric_lost` (warn) |
| `checkConceptId` | `concept_negative` (block), `concept_zero` (info), `same_concept_all` (warn) |
| `checkVocabulary` (014) **or** `checkConceptName` | 014: `concept_not_found`, `concept_invalid`, `concept_not_standard`, `concept_wrong_domain`, `concept_name_mismatch` (warn), `vocab_unavailable` (info) — without a vocabulary: `no_concept_name`, `no_concept_name_option`, `concept_name_is_label` (warn) |
| `checkEndDate` | `end_date_ignored` (info), `date_field_gone` (block) |
| `checkNote` | `note_not_text`, `note_suppressed` (warn) |
| `checkCompleteField` | `redcap_complete_field` (block) |
| `checkEmitAll` | `emits_negative` (warn) |

### Input data (example)

```php
$spec = ['domain' => 'Drug', 'value_strategy' => 'per_choice', 'date_field' => 'visit_date', ...];
$prof = ['field_label' => 'Medication given', 'value_kind' => 'categorical',
         'choices' => ['1' => 'Yes', '0' => 'No'], 'is_identifier' => false, ...];
$values = ['1' => ['emit' => true, 'concept_id' => 1782521, 'concept_name' => 'isoniazid', ...]];
$dictMeta = ['field_type' => 'yesno', 'form_name' => 'base', ...];
// → [['severity' => 'warn', 'code' => 'vague_yesno', 'text' => '…']]
```

### Constants

```php
private const RISKY_YESNO_DOMAINS = ['Drug', 'Procedure', 'Device', 'Specimen'];
private const VAGUE_TERMS = ['medicacion', 'medicamento', 'tratamiento', 'farmaco', 'pastilla*',
    'prueba*', 'analitica', 'intervencion*', 'procedimiento*', 'algo',
    'medication', 'treatment', 'drug*', 'test*', 'procedure*', 'something'];
// '*' = stem: matches the beginning of a word ("tests", "procedures").
```

The comparison text is `' ' . TextNormalizer::normalize($field . ' ' . $label) . ' '`,
to search whole words without accents or capitals.

## Decisions and discarded alternatives

- **Three severities and a high bar for `block`.** The module assists, it does
  not rule (principle 5): whoever knows what they are doing must be able to go
  ahead, leaving a record. *Discarded:* blocking everything doubtful, which
  teaches people to dodge the warnings.
- **Plain text in the warning.** Whoever renders it escapes it once
  (`htmlspecialchars` in PHP, `textContent` in JavaScript). *Discarded:* HTML
  in the warning, which was escaped twice and showed "&#039;" on screen.
- **Conservative rules on the concept name.** Without a vocabulary only the
  shape of the text can be looked at; matching the label is not a signal (an
  English questionnaire uses LOINC terminology). A repeated false warning
  teaches people to ignore them all.
- **Scales by their labels.** In REDCap every categorical field uses the codes
  1, 2, 3: looking at the code turned any five-option dropdown into a scale.
- **The untouched "No" does not emit** when evaluating `emits_negative`, as in
  `blankValueSpec()` (006). Otherwise, every freshly opened yes/no raised a
  false warning.
- **With a vocabulary the name check is replaced, not added to:** the
  vocabulary knows the real name and the heuristic would only add noise.

## Test strategy

- `tests/specs/008_mapping_advisor.test.php`. A closure
  `$adv($meta, $spec, $values, $settings, $vocab)` adds a field `x` to the test
  data dictionary, writes its mapping **directly** into the setting
  `omop-mapping-json` (bypassing the store's validations, so that what arrives
  imported, such as a negative `concept_id`, can also be tested), creates the
  module with `make_module($settings)` and returns `$m->advise('x')`.
  `$has($w, $code, $sev)` and `$hasnt($w, $code)` check the result.
- A minimal case per own code, plus the counter-example of the rules that
  raised the most false warnings (freshly opened yes/no, non-numeric
  five-option dropdown, English name equal to the label, Condition with yes/no).
- FR-008-13 with the test vocabulary of `tests/fixtures/vocab`.
- FR-008-15 running `pages/editor.php` with `tests/support/run_page.php`.
- NFR-008-01 and NFR-008-02 reading the source code: the `w('block', …)` calls
  and the `adv_*` keys of both `.ini` files.

## Constitution check

- **Principle 5:** five blocks, all of impossible data; the rest warns or informs.
- **Principle 6:** `emits_negative`.
- **Principle 7:** `numeric_lost`, `free_text`, instrument design warnings.
- **Principle 8:** `no_date_at_all`, `date_field_gone`.
- **Principle 10:** `concept_name_is_label`, `same_concept_all`.
- **Principle 22:** each `adv_*` text explains what happens and how to fix it.

## Risks

- **False positives of the text heuristics** (vague terms, Spanish in the
  name): mitigated with short lists of unambiguous words and the tests'
  counter-examples.
- **Cost in large projects:** the editor evaluates the advisor on every row;
  the caches of `advise()` and `mappingRows()` avoid recomputing.
- **`concept_zero` almost unreachable** with values typed in the editor: see
  the proposed amendment in the spec.
