<?php

namespace OmopCdm\OmopCdmExportModule;

/**
 * Text normalisation for searching and indexing.
 *
 * The index is built on text already normalised in PHP instead of relying on
 * the server collation: a REDCap database may come with utf8, utf8mb4,
 * _unicode_ci or _bin depending on the installation, and accent handling
 * differs in each case. Normalising here makes search behave the same on any
 * instance.
 */
class TextNormalizer
{
    private const ACCENTS = [
        'á'=>'a','à'=>'a','ä'=>'a','â'=>'a','ã'=>'a','å'=>'a','ā'=>'a',
        'é'=>'e','è'=>'e','ë'=>'e','ê'=>'e','ē'=>'e',
        'í'=>'i','ì'=>'i','ï'=>'i','î'=>'i','ī'=>'i',
        'ó'=>'o','ò'=>'o','ö'=>'o','ô'=>'o','õ'=>'o','ō'=>'o','ø'=>'o',
        'ú'=>'u','ù'=>'u','ü'=>'u','û'=>'u','ū'=>'u',
        'ñ'=>'n','ç'=>'c','ý'=>'y','ÿ'=>'y','ß'=>'ss','æ'=>'ae','œ'=>'oe',
    ];

    /** Lower case, no accents, no punctuation, collapsed whitespace. */
    public static function normalize(string $text): string
    {
        $text = function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
        $text = strtr($text, self::ACCENTS);
        // Anything that is not ASCII alphanumeric becomes a separator.
        $text = preg_replace('/[^a-z0-9]+/u', ' ', $text);
        return trim(preg_replace('/\s+/', ' ', $text));
    }

    /**
     * British → American spelling, which is the spelling of the OHDSI
     * vocabularies (LOINC, SNOMED and RxNorm write «hemoglobin», «anemia»,
     * «edema»). Without this, «Haemoglobin (g/dL)» did not find hemoglobin.
     */
    public static function americanize(string $text): string
    {
        return preg_replace(
            ['/haem/i', '/anaem/i', '/oedem/i', '/oestr/i', '/paediat/i', '/tumour/i', '/centre/i',
             '/ischaem/i', '/leukaem/i', '/foet/i', '/oesoph/i', '/glycaem/i', '/diarrhoea/i', '/dyspnoea/i'],
            ['hem', 'anem', 'edem', 'estr', 'pediat', 'tumor', 'center',
             'ischem', 'leukem', 'fet', 'esoph', 'glycem', 'diarrhea', 'dyspnea'],
            $text);
    }

    /** @return string[] */
    public static function tokens(string $text, int $minLength = 3): array
    {
        $tokens = [];
        foreach (explode(' ', self::normalize($text)) as $token) {
            if ($token !== '' && strlen($token) >= $minLength) {
                $tokens[$token] = true;
            }
        }
        return array_keys($tokens);
    }

    /**
     * Query for MATCH ... AGAINST in boolean mode.
     * Every token is mandatory and matches as a prefix: "+diabet* +mellit*".
     * Returns an empty string if no indexable token is left.
     */
    public static function booleanQuery(string $text, int $minLength = 3): string
    {
        $parts = [];
        foreach (self::tokens($text, $minLength) as $token) {
            $parts[] = '+' . $token . '*';
        }
        return implode(' ', $parts);
    }

    /** Is the query too short for the FULLTEXT index? */
    public static function tooShortForFulltext(string $text, int $minLength = 3): bool
    {
        return self::booleanQuery($text, $minLength) === '';
    }
}
