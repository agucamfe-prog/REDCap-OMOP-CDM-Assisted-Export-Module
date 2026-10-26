<?php
/**
 * Builds the SQLite vocabulary index from the command line.
 *
 *   php build_vocab_index.php /path/vocabulary/CONCEPT.csv /path/omop_concepts.sqlite
 *
 * CONCEPT.csv is the one from the Athena download (tab-separated). With every
 * vocabulary it is several million concepts and a few minutes; that is why
 * this route exists alongside the Control Center button, which depends on the
 * web server's time limit.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/../classes/TextNormalizer.php';
require __DIR__ . '/../classes/Vocabulary.php';

if ($argc < 3) {
    fwrite(STDERR, "Usage: php build_vocab_index.php CONCEPT.csv output.sqlite\n");
    exit(2);
}
$t = microtime(true);
$n = \OmopCdm\OmopCdmExportModule\Vocabulary::buildIndex($argv[1], $argv[2], function ($n) {
    fwrite(STDERR, "\r$n concepts...");
});
fwrite(STDERR, "\n");
printf("%d concepts indexed in %.1f s → %s\n", $n, microtime(true) - $t, $argv[2]);
