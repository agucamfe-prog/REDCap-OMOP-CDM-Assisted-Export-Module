# Evaluation with 10 realistic projects (spec 018)

Scripts used to validate the module against ten invented projects (five from
teams that work in Spanish and five in English; 186 fields; 405 synthetic
participants). The full procedure and its results are in
[`specs/018-multiproject-validation/spec.md`](../../specs/018-multiproject-validation/spec.md).
All data are synthetic.

`tests/run.php` does **not** run anything in this folder: the automatic
measurement of the classifier uses the corpus `tests/fixtures/eval10/fields.csv`,
which is generated from `specs.php` with `build_corpus.php`. Everything else
needs a real REDCap and a real PostgreSQL.

| File | What it does |
|---|---|
| `specs.php` | The 10 projects: dictionary, events, DAGs, data generators and the reference mapping of each field (`m`: the curator's mapping, with `d` domain or `x` exclude; `t`: acceptable domains if the field is not mapped). Project titles, labels and choices of the Spanish-speaking teams stay in Spanish: they are data |
| `build_corpus.php` | `specs.php` → `tests/fixtures/eval10/fields.csv` (`-` to write it to standard output) |
| `generate.php` | Creates the projects with the REDCap API (`php generate.php SUPERTOKEN`) and writes `projects.json`. Fixed seed `mt_srand(20261025)` |
| `configure.php` | Enables the module and applies the settings of each project and the system vocabulary |
| `curate.php` | Curates the mapping with the same requests the editor makes, in each project's mode (manual, annotations, import P10→P5, suggestions) |
| `measure.php` | Measures the classifier against the reference → `classifier_<round>.json` |
| `summary.php` | Summary table → `summary.json` |
| `client.php` | Minimal HTTP client for the module's pages (session and CSRF token) |
| `export_all.sh` | Downloads the 10 ZIPs (the pids are written in the script: adjust them) |
| `checks.sh`, `checks.sql` | Row counts and integrity checks of the 10 loaded schemas |
| `integrity.sql` | The 10 integrity checks for one schema: `psql -v s=p1 -f integrity.sql` |
| `results/` | Round 1, round 2 and the integrity checks as they were measured |

## Rules

- **Command line only.** Every PHP script answers 404 if called from the
  browser: this folder is inside the module, which the web server publishes.
- **Nothing secret here.** `projects.json` (pid and API token of each project),
  the cookies and new results are written to `$OMOP_EVAL_WORK`
  (default `/tmp/omoptest`), outside the module. Do not copy them into this folder.
- Environment variables: `OMOP_EVAL_WORK`, `OMOP_EVAL_BASE` (module URL,
  default `http://localhost/redcap_v17.1.4/ExternalModules/?prefix=omop_cdm_export`)
  and, for `configure.php`, `OMOP_EVAL_DB_HOST`, `OMOP_EVAL_DB_USER`,
  `OMOP_EVAL_DB_PASS`, `OMOP_EVAL_DB_NAME`.

## Order

```bash
# Inside the REDCap web container (rc1_web), from the module root
export OMOP_EVAL_WORK=/tmp/omoptest OMOP_EVAL_DB_PASS=...
mkdir -p $OMOP_EVAL_WORK
php tests/eval/generate.php SUPERTOKEN     # 10 projects with data
php tests/eval/configure.php               # module settings and vocabulary
php tests/eval/curate.php                  # curation in the four modes
php tests/eval/measure.php round2          # classifier accuracy
sh  tests/eval/export_all.sh               # ZIPs in /tmp/eval/zips/P1…P10
# Load each ZIP with its kit (carga_cdm/cargar.sh) into schema p1…p10, then:
sh  tests/eval/checks.sh > $OMOP_EVAL_WORK/checks.txt
cp tests/eval/results/results_round1.json $OMOP_EVAL_WORK/   # summary.php compares against round 1
php tests/eval/summary.php
```

If you change `specs.php`, regenerate the corpus and review the diff:
`php tests/eval/build_corpus.php && php tests/run.php 018`.
