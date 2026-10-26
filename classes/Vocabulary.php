<?php

namespace OmopCdm\OmopCdmExportModule;

require_once __DIR__ . '/TextNormalizer.php';

/**
 * Optional access to an OMOP vocabulary to check and suggest concepts.
 *
 * Without a vocabulary the module can only look at the SHAPE of what is
 * pasted: whether it looks like a concept_id, whether the name looks English.
 * It cannot know whether the number exists, whether it is standard, whether it
 * belongs to the right domain or whether it matches the pasted name. This class
 * makes that possible without redistributing anything:
 *
 *  - Local index: the administrator downloads the vocabularies from Athena
 *    under their own licence and the module builds an SQLite index from
 *    CONCEPT.csv. SNOMED never leaves the server.
 *  - WebAPI: if the institution runs ATLAS, its WebAPI is queried.
 *
 * Everything is optional. If there is no vocabulary, or it does not respond,
 * the module works as before and says so; it never blocks because it cannot
 * query.
 */
class Vocabulary
{
    private $sqlitePath;
    private $webapiUrl;
    private $webapiToken;
    private $pdo = null;
    private $cache = [];
    private $lastError = null;

    public function __construct(?string $sqlitePath, ?string $webapiUrl, ?string $webapiToken = null)
    {
        $this->sqlitePath  = $sqlitePath ?: null;
        $this->webapiUrl   = $webapiUrl ? rtrim($webapiUrl, '/') : null;
        $this->webapiToken = $webapiToken ?: null;
    }

    /** Is any source configured? */
    public function configured(): bool
    {
        return $this->sqlitePath !== null || $this->webapiUrl !== null;
    }

    /** Source in use, to show on screen. */
    public function source(): string
    {
        if ($this->sqlitePath !== null && is_file($this->sqlitePath)) {
            return 'sqlite:' . basename($this->sqlitePath);
        }
        return $this->webapiUrl !== null ? 'webapi:' . $this->webapiUrl : '';
    }

    public function lastError(): ?string
    {
        return $this->lastError;
    }

    /**
     * Data for several concepts at once.
     *
     * @param int[] $ids
     * @return array|null id => concept; null if the vocabulary does not respond.
     *         An id missing from the returned array is a concept_id that does not exist.
     */
    public function lookup(array $ids): ?array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($i) => $i > 0)));
        $missing = array_values(array_filter($ids, fn($i) => !array_key_exists($i, $this->cache)));
        if ($missing) {
            $fetched = $this->pdo() !== null ? $this->lookupSqlite($missing) : $this->lookupWebapi($missing);
            if ($fetched === null) {
                return null;
            }
            foreach ($missing as $id) {
                $this->cache[$id] = $fetched[$id] ?? false;
            }
        }
        $out = [];
        foreach ($ids as $id) {
            if ($this->cache[$id] !== false) {
                $out[$id] = $this->cache[$id];
            }
        }
        return $out;
    }

    /**
     * Candidates for a search, Usagi style: standard, valid and, if given, of
     * the requested domain.
     *
     * @return array|null list of concepts; null if there is no vocabulary.
     */
    public function search(string $query, ?string $domain = null, int $limit = 10): ?array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }
        return $this->pdo() !== null
            ? $this->searchSqlite($query, $domain, $limit)
            : $this->searchWebapi($query, $domain, $limit);
    }

    /** UCUM concept of a unit by its exact code ('mg/dL', 'mm[Hg]'). */
    public function ucum(string $code): ?array
    {
        $key = 'ucum:' . $code;
        if (array_key_exists($key, $this->cache)) {
            return $this->cache[$key] ?: null;
        }
        $c = null;
        if ($this->pdo() !== null) {
            $st = $this->pdo->prepare("SELECT * FROM concept WHERE vocabulary_id = 'UCUM' AND concept_code = ? LIMIT 1");
            $st->execute([$code]);
            $r = $st->fetch(\PDO::FETCH_ASSOC);
            $c = $r ? self::row($r) : null;
        } elseif ($this->webapiUrl !== null) {
            $res = $this->http('POST', '/vocabulary/search', ['QUERY' => $code, 'VOCABULARY_ID' => ['UCUM']]);
            foreach ((array) $res as $r) {
                if (($r['CONCEPT_CODE'] ?? null) === $code) {
                    $c = self::fromWebapi($r);
                    break;
                }
            }
        }
        $this->cache[$key] = $c ?: false;
        return $c;
    }

    // ------------------------------------------------------------- SQLite

    private function pdo(): ?\PDO
    {
        if ($this->pdo !== null) {
            return $this->pdo;
        }
        if ($this->sqlitePath === null || !is_file($this->sqlitePath) || !class_exists('\PDO')) {
            return null;
        }
        try {
            $this->pdo = new \PDO('sqlite:' . $this->sqlitePath, null, null,
                [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
            $this->pdo = null;
        }
        return $this->pdo;
    }

    private function lookupSqlite(array $ids): ?array
    {
        $out = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            $in = implode(',', array_map('intval', $chunk));
            foreach ($this->pdo->query("SELECT * FROM concept WHERE concept_id IN ($in)") as $r) {
                $out[(int) $r['concept_id']] = self::row($r);
            }
        }
        return $out;
    }

    private function searchSqlite(string $query, ?string $domain, int $limit): array
    {
        $tokens = TextNormalizer::tokens(TextNormalizer::americanize($query), 2);
        if (!$tokens) {
            return [];
        }
        // FTS5: first all the words, as prefixes. If nothing matches, relax to
        // ANY of them: no concept has both words of "fasting glucose", yet
        // "Glucose" is exactly what was wanted, and one extra word in the label
        // must not leave the user without candidates.
        // Ordered by relevance and, on a tie, by the shortest name:
        // "Body weight" before "Body weight [Percentile] Per age".
        $terms = array_map(fn($t) => '"' . str_replace('"', '', $t) . '"*', $tokens);
        foreach (array_unique([implode(' ', $terms), implode(' OR ', $terms)]) as $match) {
            $sql = "SELECT c.* FROM concept_fts f JOIN concept c ON c.concept_id = f.rowid
                    WHERE concept_fts MATCH ? AND c.standard_concept = 'S' AND (c.invalid_reason IS NULL OR c.invalid_reason = '')";
            $args = [$match];
            if ($domain) {
                $sql .= ' AND c.domain_id = ?';
                $args[] = $domain;
            }
            $sql .= ' ORDER BY bm25(concept_fts), length(c.concept_name) LIMIT ' . (int) $limit;
            $st = $this->pdo->prepare($sql);
            $st->execute($args);
            $rows = $st->fetchAll(\PDO::FETCH_ASSOC);
            if ($rows) {
                return array_map([self::class, 'row'], $rows);
            }
        }
        return [];
    }

    /**
     * Builds the index from an Athena CONCEPT.csv (tab-separated).
     *
     * It is several million rows: they are inserted in a transaction and
     * written first to a temporary file that only replaces the good index
     * when it finishes, so an interrupted build never leaves a half-built
     * index behind.
     *
     * @return int rows indexed
     */
    public static function buildIndex(string $conceptCsv, string $sqlitePath, ?callable $progress = null): int
    {
        $in = @fopen($conceptCsv, 'r');
        if ($in === false) {
            throw new \RuntimeException('Cannot read ' . $conceptCsv);
        }
        $tmp = $sqlitePath . '.tmp';
        @unlink($tmp);
        $pdo = new \PDO('sqlite:' . $tmp, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('PRAGMA journal_mode = OFF; PRAGMA synchronous = OFF;');
        $pdo->exec('CREATE TABLE concept (concept_id INTEGER PRIMARY KEY, concept_name TEXT, domain_id TEXT,
                    vocabulary_id TEXT, concept_class_id TEXT, standard_concept TEXT, concept_code TEXT,
                    invalid_reason TEXT)');
        $pdo->exec('CREATE VIRTUAL TABLE concept_fts USING fts5(name)');

        $header = explode("\t", rtrim((string) fgets($in), "\r\n"));
        $pos = array_flip(array_map('strtolower', $header));
        foreach (['concept_id', 'concept_name', 'domain_id', 'vocabulary_id', 'concept_class_id',
                  'standard_concept', 'concept_code', 'invalid_reason'] as $c) {
            if (!isset($pos[$c])) {
                throw new \RuntimeException('CONCEPT.csv is missing the column ' . $c);
            }
        }
        $ins = $pdo->prepare('INSERT INTO concept VALUES (?,?,?,?,?,?,?,?)');
        $fts = $pdo->prepare('INSERT INTO concept_fts (rowid, name) VALUES (?, ?)');
        $n = 0;
        $pdo->beginTransaction();
        while (($line = fgets($in)) !== false) {
            $f = explode("\t", rtrim($line, "\r\n"));
            if (count($f) < count($header)) {
                continue;
            }
            $id = (int) $f[$pos['concept_id']];
            $ins->execute([$id, $f[$pos['concept_name']], $f[$pos['domain_id']], $f[$pos['vocabulary_id']],
                           $f[$pos['concept_class_id']], $f[$pos['standard_concept']], $f[$pos['concept_code']],
                           $f[$pos['invalid_reason']]]);
            $fts->execute([$id, TextNormalizer::normalize($f[$pos['concept_name']])]);
            if (++$n % 50000 === 0) {
                $pdo->commit();
                $pdo->beginTransaction();
                if ($progress) {
                    $progress($n);
                }
            }
        }
        $pdo->commit();
        $pdo->exec("CREATE INDEX ix_code ON concept (vocabulary_id, concept_code)");
        $pdo = null;
        fclose($in);
        if (!@rename($tmp, $sqlitePath)) {
            throw new \RuntimeException('Could not write ' . $sqlitePath);
        }
        return $n;
    }

    // ------------------------------------------------------------- WebAPI

    private function lookupWebapi(array $ids): ?array
    {
        if ($this->webapiUrl === null) {
            return null;
        }
        $res = $this->http('POST', '/vocabulary/lookup/identifiers', $ids);
        if ($res === null) {
            return null;
        }
        $out = [];
        foreach ((array) $res as $r) {
            $c = self::fromWebapi($r);
            $out[$c['id']] = $c;
        }
        return $out;
    }

    private function searchWebapi(string $query, ?string $domain, int $limit): ?array
    {
        if ($this->webapiUrl === null) {
            return null;
        }
        $body = ['QUERY' => TextNormalizer::americanize($query), 'STANDARD_CONCEPT' => 'S', 'INVALID_REASON' => 'V'];
        if ($domain) {
            $body['DOMAIN_ID'] = [$domain];
        }
        $res = $this->http('POST', '/vocabulary/search', $body);
        if ($res === null) {
            return null;
        }
        $out = array_map([self::class, 'fromWebapi'], array_slice((array) $res, 0, 200));
        usort($out, fn($a, $b) => strlen($a['name']) <=> strlen($b['name']));
        return array_slice($out, 0, $limit);
    }

    private function http(string $method, string $path, $body = null)
    {
        if (!function_exists('curl_init')) {
            $this->lastError = 'curl';
            return null;
        }
        $ch = curl_init($this->webapiUrl . $path);
        $headers = ['Accept: application/json', 'Content-Type: application/json'];
        if ($this->webapiToken) {
            $headers[] = 'Authorization: Bearer ' . $this->webapiToken;
        }
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_HTTPHEADER => $headers]);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }
        $txt  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        if ($txt === false || $code >= 400) {
            $this->lastError = $err ?: ('HTTP ' . $code);
            return null;
        }
        $j = json_decode($txt, true);
        if (!is_array($j)) {
            $this->lastError = 'non-JSON response';
            return null;
        }
        return $j;
    }

    // ---------------------------------------------------------- Formats

    private static function row(array $r): array
    {
        return [
            'id'         => (int) $r['concept_id'],
            'name'       => (string) $r['concept_name'],
            'domain'     => (string) $r['domain_id'],
            'vocabulary' => (string) $r['vocabulary_id'],
            'class'      => (string) $r['concept_class_id'],
            'standard'   => (string) $r['standard_concept'],
            'code'       => (string) $r['concept_code'],
            'invalid'    => (string) ($r['invalid_reason'] ?? ''),
        ];
    }

    private static function fromWebapi(array $r): array
    {
        return [
            'id'         => (int) ($r['CONCEPT_ID'] ?? 0),
            'name'       => (string) ($r['CONCEPT_NAME'] ?? ''),
            'domain'     => (string) ($r['DOMAIN_ID'] ?? ''),
            'vocabulary' => (string) ($r['VOCABULARY_ID'] ?? ''),
            'class'      => (string) ($r['CONCEPT_CLASS_ID'] ?? ''),
            'standard'   => (string) ($r['STANDARD_CONCEPT'] ?? ''),
            'code'       => (string) ($r['CONCEPT_CODE'] ?? ''),
            'invalid'    => (string) (($r['INVALID_REASON'] ?? '') === 'V' ? '' : ($r['INVALID_REASON'] ?? '')),
        ];
    }
}
