# Tasks NNN — <Name>

Before starting: read `constitution.md`, `AGENTS.md`, and this folder's
`spec.md` and `plan.md`. Do the tasks in order. Every `[T]` must end with the
test **red**; the following `[I]` turns it green.

Run only this spec's tests: `php tests/run.php NNN`

| Legend | |
|---|---|
| `[T]` | Write a test (red) |
| `[I]` | Implement (green) |
| `[R]` | Refactor, document or translate |
| `[P]` | Can be done in parallel with the previous task |

---

### TNNN-01 [T] <Short title>

- **Requirements:** FR-NNN-01
- **Files:** `tests/specs/NNN_<name>.test.php`
- **What to do:** concrete steps, with function names and sample data.
- **Done when:** verifiable criterion (e.g. «the test `FR-NNN-01 …` fails with
  the message …»).
- **Size:** S (≤ 1 h) · M (≤ 3 h) · L (≤ 1 day)

### TNNN-02 [I] <Short title>

- **Requirements:** FR-NNN-01
- **Files:** `classes/X.php`
- **What to do:** …
- **Hints:** references, known pitfalls.
- **Done when:** `php tests/run.php NNN` is green.
- **Size:** M

---

## Definition of done for the spec

- [ ] All `NNN` tests green and no regressions in the rest of the suite.
- [ ] `php tests/run.php --trace NNN` reports no orphan requirements.
- [ ] New texts in `lang/Spanish.ini` and `lang/English.ini` with the same keys.
- [ ] README updated if anything visible to users changes.
- [ ] Spec status changed to `Implemented`.
