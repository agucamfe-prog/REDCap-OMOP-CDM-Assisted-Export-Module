<?php
/**
 * Minimal test framework: no dependencies, so that it runs on any server with
 * REDCap and in continuous integration.
 *
 * Every check cites the requirements it verifies:
 *
 *     test('FR-009-03', 'the "No" of a diagnosis produces no row', $cond);
 *     test('FR-009-03,FR-009-04', '...', $cond, 'detail shown on failure');
 *
 * The runner (tests/run.php) groups results by spec and, with --trace, checks
 * that every requirement of every spec.md has at least one test.
 */

final class Harness
{
    /** @var array<int,array{spec:string,req:string[],desc:string,ok:bool}> */
    public static $results = [];
    public static $spec = '';
    public static $quiet = false;

    public static function record(string $req, string $desc, bool $ok, string $detail): void
    {
        $ids = array_values(array_filter(array_map('trim', explode(',', $req))));
        self::$results[] = ['spec' => self::$spec, 'req' => $ids, 'desc' => $desc, 'ok' => $ok];
        if (!self::$quiet || !$ok) {
            printf("%s %-11s %s%s\n", $ok ? "\033[32mok\033[0m  " : "\033[31mFAIL\033[0m", $ids[0] ?? '?', $desc,
                $detail !== '' && !$ok ? "  [$detail]" : '');
        }
    }
}

/** One check. $req: one or more FR-NNN-XX ids separated by commas. */
function test(string $req, string $desc, bool $cond, string $detail = ''): void
{
    Harness::record($req, $desc, $cond, $detail);
}

/** Title of a block of checks inside a spec file. */
function section(string $s): void
{
    if (!Harness::$quiet) {
        echo "\n  -- $s\n";
    }
}

/** Checks that a call throws an exception (optionally, of a given class). */
function throws(callable $fn, string $class = \Throwable::class): bool
{
    try {
        $fn();
    } catch (\Throwable $e) {
        return $e instanceof $class;
    }
    return false;
}
