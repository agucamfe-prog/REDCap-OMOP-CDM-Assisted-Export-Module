# OMOP-CDM Export project constitution

Principles that do not change from one spec to another. Every spec, plan, task
and code change is judged against this document. If a spec needs to contradict
a principle, the constitution is amended first (last section), with its reason.

## I. The module assists; the person decides

1. **Only what is approved is exported.** No state other than `approved`
   produces CDM rows. When in doubt, the field stays out (*fail closed*).
2. **Writing does not approve.** Filling in a concept, adopting an annotation
   or importing a mapping leaves the field `proposed`. Approving is an explicit
   act by an identified person, with a date.
3. **Modifying withdraws the approval.** A real change to the content of an
   approved field sends it back to `proposed`. Saving without changes withdraws
   nothing.
4. **What is saved prevails.** Improving the classifier never overwrites a saved
   decision. Going back to the proposal is always an explicit action.
5. **The bar for blocking is high.** Only what would produce impossible data is
   blocked (no date, negative `concept_id`, REDCap status field). Everything
   else warns and requires a confirmation, which is logged.

## II. Faithfulness to the data

6. **Absence is represented by absence.** The «No» of a diagnosis produces no
   row.
7. **Nothing is invented or interpreted.** A text that admits two readings
   (`1.234`, `95,5`) is not converted to a number; it is kept in
   `*_source_value`. A REDCap choice code is never a numeric value, except in
   numeric rating scales.
8. **No row without a date or without a person.** A person without a year of
   birth is dropped together with all their facts.
9. **Every drop is counted and explained** in the report that travels with the
   export, in the project's language.
10. **A wrong concept is worse than an empty one.** Without certainty
    (unrecognised unit, doubtful concept) the value is left `0` or empty and a
    warning is raised.

## III. Respect for REDCap and for personal data

11. **Read-only on the project.** The module never writes to the project's
    data or data dictionary. Its state lives in the module settings. The
    dictionary only changes if a person uploads the annotated dictionary.
12. **REDCap rights are respected**: design rights to edit the mapping, full
    export rights on every instrument involved to export the CDM, and data
    access groups (DAG) to restrict records.
13. **Pseudonymisation is available and declared.** What was applied is stated
    in the report. Nothing the module does makes the data anonymous.
14. **No external runtime dependencies.** PHP ≥ 7.4 and the extensions REDCap
    usually has (SQLite and Zip included). Nothing is downloaded on install.
    The vocabulary is optional and never distributed.

## IV. Standards

15. **OMOP CDM v5.4**, with columns in the order and with the nullability of
    the official `OMOP_CDMv5.4_Field_Level.csv` file.
16. **OHDSI vocabularies** (Athena): standard concepts, UCUM for units.
17. **REDCap External Module Framework, version 9.**

## V. Quality and way of working

18. **Spec-Driven Development.** No code without a spec: `spec.md` says what
    and why; `plan.md`, how; `tasks.md`, in which steps. Requirements are
    numbered `FR-NNN-XX`, and tests cite those numbers.
19. **Test-Driven Development.** Every implementation task is preceded by a
    task that writes its test, and that test fails before implementing (red →
    green → refactor). Tests run without REDCap (`php tests/run.php`).
20. **Traceability.** Every functional requirement has at least one test that
    cites it, or is marked `[manual]` with its verification procedure.
    `php tests/run.php --trace` checks it.
21. **Bilingual from day one.** Every visible text lives in `lang/*.ini`, with
    the same keys in Spanish and English. The classifier understands both
    languages regardless of the interface language.
22. **Explain, do not just decide.** Every proposal carries its reason; every
    warning, what happens if it is ignored and how to fix it.
23. **Small tasks.** A task fits in one working session of a junior developer:
    one or two files, verifiable acceptance criteria, no pending design
    decisions.

## Amendments

| Date | Principle | Change | Reason |
|---|---|---|---|
| — | — | Initial version | — |
