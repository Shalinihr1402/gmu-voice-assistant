<?php

require_once __DIR__ . "/../../config/db.php";
require_once __DIR__ . "/../../services/CertificateService.php";

class StudentController {
    private static $courseAliases = [
        "dbms" => "database management systems",
        "d b m s" => "database management systems",
        "database management system" => "database management systems",
        "dbms lab" => "dbms laboratory",
        "dbms laboratory" => "dbms laboratory",
        "ಡಿಬಿಎಮ್ಎಸ್" => "database management systems",
        "ಡಿಬಿಎಂಎಸ್" => "database management systems",
        "ಡೇಟಾಬೇಸ್ ಮ್ಯಾನೆಜ್ಮೆಂಟ್ ಸಿಸ್ಟಮ್ಸ್" => "database management systems",
        "ಡಿಬಿಎಮ್ಎಸ್ ಲ್ಯಾಬ್" => "dbms laboratory",
        "ai" => "artificial intelligence",
        "a i" => "artificial intelligence",
        "artificial intelligence" => "artificial intelligence",
        "ada" => "design and analysis of algorithms",
        "a d a" => "design and analysis of algorithms",
        "analysis and design of algorithms" => "design and analysis of algorithms",
        "design and analysis of algorithms" => "design and analysis of algorithms",
        "ada" => "design and analysis of algorithms",
        "a d a" => "design and analysis of algorithms",
        "analysis and design of algorithms" => "design and analysis of algorithms",
        "design and analysis of algorithms" => "design and analysis of algorithms",
        "ಎಐ" => "artificial intelligence",
        "ಆರ್ಟಿಫಿಷಿಯಲ್ ಇಂಟೆಲಿಜೆನ್ಸ್" => "artificial intelligence",
        "os" => "operating systems",
        "o s" => "operating systems",
        "operating system" => "operating systems",
        "operating systems" => "operating systems",
        "ಓಎಸ್" => "operating systems",
        "ಆಪರೇಟಿಂಗ್ ಸಿಸ್ಟಮ್ಸ್" => "operating systems",
        "cn" => "computer networks",
        "c n" => "computer networks",
        "computer network" => "computer networks",
        "computer net work" => "computer networks",
        "computer net works" => "computer networks",
        "computer networks" => "computer networks",
        "ಸಿಎನ್" => "computer networks",
        "ಕಂಪ್ಯೂಟರ್ ನೆಟ್ವರ್ಕ್ಸ್" => "computer networks",
        "ds" => "data structures",
        "data structures" => "data structures",
        "ಡೇಟಾ ಸ್ಟ್ರಕ್ಚರ್ಸ್" => "data structures",
        "se" => "software engineering",
        "software engineering" => "software engineering",
        "ಸಾಫ್ಟ್‌ವೇರ್ ಎಂಜಿನಿಯರಿಂಗ್" => "software engineering",
        "oop" => "object oriented programming",
        "oops" => "object oriented programming",
        "ಒಒಪಿ" => "object oriented programming",
        "java" => "java programming",
        "ಜಾವಾ" => "java programming",
        "ಮೈಕ್ರೋಪ್ರೊಸೆಸರ್ಸ್" => "microprocessors",
        "ಮ್ಯಾಥಮ್ಯಾಟಿಕ್ಸ್ ಫಾರ್ ಕಂಪ್ಯೂಟಿಂಗ್" => "mathematics for computing"
    ];

    private static function normalizeLanguage($language) {
        $language = strtolower(trim((string) $language));
        if (in_array($language, ["hi", "hindi", "hi-in"], true)) {
            return "hi";
        }
        return in_array($language, ["kn", "kannada", "kn-in"], true) ? "kn" : "en";
    }

    private static function isHindi($language) {
        return self::normalizeLanguage($language) === "hi";
    }

    private static function isKannada($language) {
        return self::normalizeLanguage($language) === "kn";
    }

    private static function normalizeLookupText($text) {
        $text = strtolower(trim((string) $text));
        $text = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text);
        $text = preg_replace('/\s+/u', ' ', (string) $text);
        return trim((string) $text);
    }

    private static function canonicalizeCourseQueryTerms($text) {
        $text = strtolower((string) $text);

        $replacements = [
            '/\bcore\s+score\b/u' => ' course code ',
            '/\bcorse\s+code\b/u' => ' course code ',
            '/\bcore\s+code\b/u' => ' course code ',
            '/\bcourse\s+score\b/u' => ' course code ',
            '/\x{0C95}\x{0CCB}\x{0CA1}\x{0CCD}|\x{0C95}\x{0CCB}\x{0CA1}\x{0CBF}/u' => ' code ',
            '/\x{0CB5}\x{0CBF}\x{0CB7}\x{0CAF}|\x{0CB8}\x{0CAC}\x{0CCD}\x{0C9C}\x{0CC6}\x{0C95}\x{0CCD}\x{0C9F}\x{0CCD}/u' => ' subject ',
            '/\x{0C95}\x{0CCB}\x{0CB0}\x{0CCD}\x{0CB8}\x{0CCD}/u' => ' course ',
            '/\x{0C8F}\x{0CA8}\x{0CC1}|\x{0CAF}\x{0CC7}\x{0CA8}\x{0CC1}/u' => ' ',
            '/\x{0CB9}\x{0CC7}\x{0CB3}\x{0CBF}|\x{0CB9}\x{0CC7}\x{0CB3}\x{0CC1}/u' => ' ',
            '/\x{0C93}\x{0C8E}\x{0CB8}\x{0CCD}/u' => ' os ',
            '/\x{0CA1}\x{0CBF}\x{0CAC}\x{0CBF}\x{0C8E}\x{0C82}\x{0C8E}\x{0CB8}\x{0CCD}/u' => ' dbms ',
            '/\x{0C86}\x{0CAA}\x{0CB0}\x{0CC7}\x{0C9F}\x{0CBF}\x{0C82}\x{0C97}\x{0CCD}\s*\x{0CB8}\x{0CBF}\x{0CB8}\x{0CCD}\x{0C9F}\x{0CAE}\x{0CCD}(?:\x{0CB8}\x{0CCD})?/u' => ' operating systems ',
            '/\x{0C95}\x{0C82}\x{0CAA}\x{0CCD}\x{0CAF}\x{0CC2}\x{0C9F}\x{0CB0}\x{0CCD}\s*\x{0CA8}\x{0CC6}\x{0C9F}\x{0CCD}\x{0CB5}\x{0CB0}\x{0CCD}\x{0C95}\x{0CCD}(?:\x{0CB8}\x{0CCD})?/u' => ' computer networks ',
            '/\x{0C95}\x{0C82}\x{0CAA}\x{0CCD}\x{0CAF}\x{0CC2}\x{0C9F}\x{0CB0}\x{0CCD}\s*\x{0CA8}\x{0CC6}\x{0C9F}\x{0CCD}\s*\x{0CB5}\x{0CB0}\x{0CCD}\x{0C95}\x{0CCD}(?:\x{0CB8}\x{0CCD})?/u' => ' computer networks ',
            '/\x{0C86}\x{0CB0}\x{0CCD}\x{0C9F}\x{0CBF}\x{0CAB}\x{0CBF}\x{0CB7}\x{0CBF}\x{0CAF}\x{0CB2}\x{0CCD}\s*\x{0C87}\x{0C82}\x{0C9F}\x{0CC6}\x{0CB2}\x{0CBF}\x{0C9C}\x{0CC6}\x{0CA8}\x{0CCD}\x{0CB8}\x{0CCD}/u' => ' artificial intelligence '
        ];

        $text = preg_replace(array_keys($replacements), array_values($replacements), $text);

        $phoneticReplacements = [
            '/ಆಪರೇಟಿಂಗ್\s*ಸಿಸ್ಟಂ(?:ಸ್)?/u' => ' operating systems ',
            '/ಅಪರೇಟಿಂಗ್\s*ಸಿಸ್ಟಂ(?:ಸ್)?/u' => ' operating systems ',
            '/ಒಪರೇಟಿಂಗ್\s*ಸಿಸ್ಟಂ(?:ಸ್)?/u' => ' operating systems ',
            '/ಆಪರೇಟಿಂಗ\s*ಸಿಸ್ಟಂ(?:ಸ್)?/u' => ' operating systems ',
            '/ಫಿಶಲ್\s*ಇಂಟೆಲಿಜೆನ್ಸ್/u' => ' artificial intelligence ',
            '/ಆರ್ಟಿಫಿಶಿಯಲ್\s*ಇಂಟೆಲಿಜೆನ್ಸ್/u' => ' artificial intelligence ',
            '/ಆರ್ಟಿಫಿಷಿಯಲ್\s*ಇಂಟೆಲಿಜೆನ್ಸ್/u' => ' artificial intelligence ',
            '/ಅರ್ಟಿಫಿಶಿಯಲ್\s*ಇಂಟೆಲಿಜೆನ್ಸ್/u' => ' artificial intelligence ',
            '/ಕಂಪ್ಯೂಟರ್\s*ನೆಟ್ವರ್ಕ್ಸ್?/u' => ' computer networks ',
            '/ಕಂಪ್ಯೂಟರ್\s*ನೆಟ್\s*ವರ್ಕ್ಸ್?/u' => ' computer networks ',
            '/ಡಿಬಿಎಮ್ಎಸ್/u' => ' dbms ',
            '/ಡಿ\s*ಬಿ\s*ಎಂ\s*ಎಸ್/u' => ' dbms '
        ];

        return preg_replace(array_keys($phoneticReplacements), array_values($phoneticReplacements), (string) $text);
    }

    private static function buildCourseShortName($courseTitle) {
        $words = preg_split('/[^a-z0-9]+/i', strtolower((string) $courseTitle));
        $words = array_values(array_filter($words, function ($word) {
            return $word !== "";
        }));

        if (empty($words)) {
            return "";
        }

        $shortName = "";
        foreach ($words as $word) {
            $shortName .= $word[0];
        }

        return $shortName;
    }

    private static function stripCourseQueryNoise($text) {
        $rawText = self::canonicalizeCourseQueryTerms(trim((string) $text));
        $rawText = str_replace(
            [
                "ಕೋಡ್",
                "ವಿಷಯ",
                "ಸಬ್ಜೆಕ್ಟ್",
                "ಕೋರ್ಸ್",
                "ಹೇಳಿ",
                "ಹೇಳು",
                "ಏನು",
                "ಯೇನು",
                "subject code",
                "course code"
            ],
            " ",
            $rawText
        );

        $text = self::normalizeLookupText($rawText);
        $noisePatterns = [
            '/\bwhat\b/',
            '/\bis\b/',
            '/\bthe\b/',
            '/\bcourse\b/',
            '/\bsubject\b/',
            '/\bcode\b/',
            '/\bof\b/',
            '/\bfor\b/',
            '/\btell\b/',
            '/\bme\b/',
            '/\bplease\b/',
            '/\bcan\b/',
            '/\byou\b/',
            '/\bgive\b/',
            '/\bfind\b/',
            '/\bi\b/',
            '/\bwant\b/',
            '/\bto\b/',
            '/\bknow\b/'
        ];

        $cleaned = preg_replace($noisePatterns, ' ', $text);
        $cleaned = preg_replace('/\b(attendance|percentage|overall|total|in|of|for|eshtu)\b/', ' ', (string) $cleaned);
        $cleaned = preg_replace('/\s+/', ' ', (string) $cleaned);
        return trim((string) $cleaned);
    }

    private static function isCourseCodeRequest($message) {
        $normalizedMessage = self::normalizeLookupText(self::canonicalizeCourseQueryTerms($message));

        if ($normalizedMessage === "") {
            return false;
        }

        if (
            strpos($normalizedMessage, "attendance") !== false &&
            strpos($normalizedMessage, "code") === false
        ) {
            return false;
        }

        if (preg_match('/\b(course|subject)\s+code\b/', $normalizedMessage)) {
            return true;
        }

        if (preg_match('/\bcode\s+(of|for)\b/', $normalizedMessage)) {
            return true;
        }

        if (preg_match('/\bwhat\s+is\s+the\s+course\s+of\b/', $normalizedMessage)) {
            return true;
        }

        if (preg_match('/\bwhich\s+course\s+is\b/', $normalizedMessage)) {
            return true;
        }

        if (strpos($normalizedMessage, "code") !== false && preg_match('/\b(course|subject|vishaya|dbms|os|cn|ai)\b/', $normalizedMessage)) {
            return true;
        }

        return false;
    }

    public static function isLikelyCourseCodeQuery($message) {
        return self::isCourseCodeRequest($message);
    }

    public static function inferCourseSubject($message) {
        return self::extractKnownCourseSubject($message);
    }

    private static function extractKnownCourseSubject($message) {
        $normalizedMessage = self::normalizeLookupText(self::canonicalizeCourseQueryTerms($message));
        $rawMessage = strtolower((string) $message);

        $exactPhraseMap = [
            "ಒಎಸ್" => "operating systems",
            "ಡಿಬಿಎಂಎಸ್" => "database management systems",
            "ಸಿಎನ್" => "computer networks",
            "ಎಐ" => "artificial intelligence",
            "ಕಂಪ್ಯೂಟರ್ ನೆಟ್ವರ್ಕ್ಸ್" => "computer networks",
            "ಕಂಪ್ಯೂಟರ್ ನೆಟ್ವರ್ಕ್" => "computer networks",
            "ಕಂಪ್ಯೂಟರ್ ನೆಟ್ ವರ್ಕ್ಸ್" => "computer networks",
            "ಕಂಪ್ಯೂಟರ್ ನೆಟ್ ವರ್ಕ್" => "computer networks",
            "ಆಪರೇಟಿಂಗ್ ಸಿಸ್ಟಮ್" => "operating systems",
            "ಆಪರೇಟಿಂಗ್ ಸಿಸ್ಟಮ್ಸ್" => "operating systems",
            "ಡಾಟಾಬೇಸ್ ಮ್ಯಾನೇಜ್ಮೆಂಟ್ ಸಿಸ್ಟಮ್" => "database management systems",
            "ಡಾಟಾಬೇಸ್ ಮ್ಯಾನೇಜ್ಮೆಂಟ್ ಸಿಸ್ಟಮ್ಸ್" => "database management systems",
            "ಆರ್ಟಿಫಿಶಿಯಲ್ ಇಂಟೆಲಿಜೆನ್ಸ್" => "artificial intelligence",
            "ಡಿಬಿಎಂಎಸ್ ಲ್ಯಾಬ್" => "dbms laboratory"
        ];

        foreach ($exactPhraseMap as $phrase => $canonical) {
            if (strpos($message, $phrase) !== false || strpos($rawMessage, strtolower($phrase)) !== false) {
                return $canonical;
            }
        }

        $subjectPatterns = [
            "database management systems" => [
                '/\bdbms\b/u',
                '/\bd\s*b\s*m\s*s\b/u',
                '/\bdatabase management systems?\b/u',
                '/\x{0CA1}\x{0CBE}\x{0C9F}\x{0CBE}\x{0CAC}\x{0CC7}\x{0CB8}\x{0CCD}\s*\x{0CAE}\x{0CCD}\x{0CAF}\x{0CBE}\x{0CA8}\x{0CC7}\x{0C9C}\x{0CCD}\x{0CAE}\x{0CC6}\x{0C82}\x{0C9F}\x{0CCD}\s*\x{0CB8}\x{0CBF}\x{0CB8}\x{0CCD}\x{0C9F}\x{0CAE}\x{0CCD}(?:\x{0CB8}\x{0CCD})?/u'
            ],
            "dbms laboratory" => [
                '/\bdbms lab(?:oratory)?\b/u'
            ],
            "operating systems" => [
                '/\bos\b/u',
                '/\bo\s*s\b/u',
                '/\boperating systems?\b/u',
                '/\x{0C86}\x{0CAA}\x{0CB0}\x{0CC7}\x{0C9F}\x{0CBF}\x{0C82}\x{0C97}\x{0CCD}\s*\x{0CB8}\x{0CBF}\x{0CB8}\x{0CCD}\x{0C9F}\x{0CAE}\x{0CCD}(?:\x{0CB8}\x{0CCD}|\x{0CB8}\x{0CCD}\x{0CB8}\x{0CCD})?/u',
                '/ಆಪರೇಟಿಂಗ್\s*ಸಿಸ್ಟಂ(?:ಸ್)?/u',
                '/ಅಪರೇಟಿಂಗ್\s*ಸಿಸ್ಟಂ(?:ಸ್)?/u',
                '/ಒಪರೇಟಿಂಗ್\s*ಸಿಸ್ಟಂ(?:ಸ್)?/u'
            ],
            "design and analysis of algorithms" => [
                "/\\bada\\b/u",
                "/\\ba\\s*d\\s*a\\b/u",
                "/\\b(?:design and analysis of algorithms|analysis and design of algorithms)\\b/u"
            ],
            "computer networks" => [
                '/\bcn\b/u',
                '/\bc\s*n\b/u',
                '/\bcomputer networks?\b/u',
                '/\bcomputer net work(s)?\b/u',
                '/\x{0C95}\x{0C82}\x{0CAA}\x{0CCD}\x{0CAF}\x{0CC2}\x{0C9F}\x{0CB0}\x{0CCD}\s*\x{0CA8}\x{0CC6}\x{0C9F}\x{0CCD}(?:\x{0CB5}\x{0CB0}\x{0CCD}\x{0C95}\x{0CCD}|\x{0CB5}\x{0CB0}\x{0CCD}\x{0C95}\x{0CCD}\x{0CB8}\x{0CCD}|\x{0CB5}\x{0CB0}\x{0CCD}\x{0C95}\x{0CCD}\x{0CB8}\x{0CCD})/u',
                '/ಕಂಪ್ಯೂಟರ್\s*ನೆಟ್ವರ್ಕ್ಸ್?/u',
                '/ಕಂಪ್ಯೂಟರ್\s*ನೆಟ್\s*ವರ್ಕ್ಸ್?/u'
            ],
            "artificial intelligence" => [
                '/\bai\b/u',
                '/\ba\s*i\b/u',
                '/\bartificial intelligence\b/u',
                '/\x{0C86}\x{0CB0}\x{0CCD}\x{0C9F}\x{0CBF}\x{0CAB}\x{0CBF}\x{0CB7}\x{0CBF}\x{0CAF}\x{0CB2}\x{0CCD}\s*\x{0C87}\x{0C82}\x{0C9F}\x{0CC6}\x{0CB2}\x{0CBF}\x{0C9C}\x{0CC6}\x{0CA8}\x{0CCD}\x{0CB8}\x{0CCD}/u',
                '/ಆರ್ಟಿಫಿಶಿಯಲ್\s*ಇಂಟೆಲಿಜೆನ್ಸ್/u',
                '/ಆರ್ಟಿಫಿಷಿಯಲ್\s*ಇಂಟೆಲಿಜೆನ್ಸ್/u',
                '/ಅರ್ಟಿಫಿಶಿಯಲ್\s*ಇಂಟೆಲಿಜೆನ್ಸ್/u',
                '/ಫಿಶಲ್\s*ಇಂಟೆಲಿಜೆನ್ಸ್/u'
            ]
        ];

        foreach ($subjectPatterns as $canonical => $patterns) {
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $normalizedMessage) || preg_match($pattern, $rawMessage)) {
                    return $canonical;
                }
            }
        }

        return "";
    }

    private static function applyCourseAliases($text) {
        $normalized = self::normalizeLookupText(self::canonicalizeCourseQueryTerms($text));

        if ($normalized === "") {
            return "";
        }

        foreach (self::$courseAliases as $alias => $expanded) {
            $aliasText = self::normalizeLookupText($alias);
            $expandedText = self::normalizeLookupText($expanded);

            if ($aliasText === "" || $expandedText === "") {
                continue;
            }

            $normalized = preg_replace('/\b' . preg_quote($aliasText, '/') . '\b/', $expandedText, $normalized);
        }

        $normalized = preg_replace('/\s+/', ' ', (string) $normalized);
        return trim((string) $normalized);
    }

    private static function scoreCourseMatch($query, $courseTitle, $courseCode) {
        $normalizedQuery = self::applyCourseAliases(self::stripCourseQueryNoise($query));
        $normalizedTitle = self::normalizeLookupText($courseTitle);
        $normalizedCode = self::normalizeLookupText($courseCode);
        $shortName = self::normalizeLookupText(self::buildCourseShortName($courseTitle));

        $directAliases = [
            "dbms" => "database management systems",
            "database management system" => "database management systems",
            "database management systems" => "database management systems",
            "dbms lab" => "dbms laboratory",
            "os" => "operating systems",
            "operating system" => "operating systems",
            "operating systems" => "operating systems",
            "computer network" => "computer networks",
            "computer net work" => "computer networks",
            "computer net works" => "computer networks",
            "computer networks" => "computer networks",
            "cn" => "computer networks",
            "artificial intelligence" => "artificial intelligence",
        "ada" => "design and analysis of algorithms",
        "a d a" => "design and analysis of algorithms",
        "analysis and design of algorithms" => "design and analysis of algorithms",
        "design and analysis of algorithms" => "design and analysis of algorithms",
        "ada" => "design and analysis of algorithms",
        "a d a" => "design and analysis of algorithms",
        "analysis and design of algorithms" => "design and analysis of algorithms",
        "design and analysis of algorithms" => "design and analysis of algorithms",
            "ai" => "artificial intelligence",
            "ada" => "design and analysis of algorithms",
            "analysis and design of algorithms" => "design and analysis of algorithms",
            "design and analysis of algorithms" => "design and analysis of algorithms",
            "ada" => "design and analysis of algorithms",
            "analysis and design of algorithms" => "design and analysis of algorithms",
            "design and analysis of algorithms" => "design and analysis of algorithms"
        ];

        if ($normalizedQuery === "") {
            return 0;
        }

        if (mb_strlen($normalizedQuery, "UTF-8") <= 1) {
            return 0;
        }

        if (isset($directAliases[$normalizedQuery]) && $normalizedTitle === $directAliases[$normalizedQuery]) {
            return 100;
        }

        if ($normalizedQuery === $normalizedCode || $normalizedQuery === $shortName) {
            return 100;
        }

        if (
            mb_strlen($normalizedQuery, "UTF-8") >= 2 &&
            (strpos($normalizedTitle, $normalizedQuery) !== false || strpos($normalizedQuery, $normalizedTitle) !== false)
        ) {
            return 95;
        }

        $queryWords = array_values(array_filter(explode(' ', $normalizedQuery)));
        $titleWords = array_values(array_filter(explode(' ', $normalizedTitle)));

        $matchedWords = 0;
        foreach ($queryWords as $queryWord) {
            foreach ($titleWords as $titleWord) {
                $queryWordLength = mb_strlen($queryWord, "UTF-8");
                $titleWordLength = mb_strlen($titleWord, "UTF-8");

                if (
                    $queryWord === $titleWord ||
                    (
                        $queryWordLength >= 2 &&
                        $titleWordLength >= 2 &&
                        (
                            strpos($titleWord, $queryWord) !== false ||
                            strpos($queryWord, $titleWord) !== false
                        )
                    ) ||
                    (
                        $queryWordLength >= 3 &&
                        $titleWordLength >= 3 &&
                        levenshtein($queryWord, $titleWord) <= 2
                    )
                ) {
                    $matchedWords += 1;
                    break;
                }
            }
        }

        if (!empty($queryWords)) {
            $coverage = $matchedWords / count($queryWords);
            if ($coverage >= 0.99) {
                return 90;
            }
            if ($coverage >= 0.7) {
                return 75;
            }
            if ($coverage >= 0.5) {
                return 60;
            }
        }

        if ($shortName !== "") {
            $distance = levenshtein($normalizedQuery, $shortName);
            if ($distance <= 1) {
                return 88;
            }
            if ($distance <= 2) {
                return 72;
            }
        }

        $titleDistance = levenshtein($normalizedQuery, $normalizedTitle);
        if (mb_strlen($normalizedQuery, "UTF-8") >= 3 && $titleDistance <= 3) {
            return 68;
        }

        return 0;
    }

    private static function getCoursePromptLabel($courseTitle) {
        $normalizedTitle = self::normalizeLookupText($courseTitle);

        $labels = [
            "database management systems" => "DBMS",
            "dbms laboratory" => "DBMS lab",
            "operating systems" => "OS",
            "computer networks" => "CN",
            "artificial intelligence" => "AI"
        ];

        return $labels[$normalizedTitle] ?? trim((string) $courseTitle);
    }

    private static function buildClarificationPayload($intent, $courseTitle, $language = "en") {
        $label = self::getCoursePromptLabel($courseTitle);

        if ($intent === "GET_COURSE_CODE") {
            $correctedText = "course code for " . strtolower($label);
            $displayText = "course code for " . $label;
        } else {
            $correctedText = strtolower($label) . " attendance";
            $displayText = $label . " attendance";
        }

        if (self::isHindi($language)) {
            $reply = "क्या आपका मतलब {$displayText} था? कृपया हाँ या नहीं कहिए।";
        } elseif (self::isKannada($language)) {
            $reply = "{$displayText} andre nimma artha? Dayavittu haudu athava illa heli.";
        } else {
            $reply = "Did you mean {$displayText}? Please say yes or no.";
        }

        return [
            "reply" => $reply,
            "corrected_text" => $correctedText,
            "display_text" => $displayText
        ];
    }

    private static function findShortQueryClarificationCandidate($intent, $message, $rows, $language = "en") {
        $normalizedQuery = self::applyCourseAliases(self::stripCourseQueryNoise($message));

        if ($normalizedQuery === "" || mb_strlen($normalizedQuery, "UTF-8") > 2) {
            return null;
        }

        $matches = [];

        foreach ($rows as $row) {
            $label = self::normalizeLookupText(self::getCoursePromptLabel($row["course_title"] ?? ""));
            $code = self::normalizeLookupText($row["course_code"] ?? "");
            $shortName = self::normalizeLookupText(self::buildCourseShortName($row["course_title"] ?? ""));

            if (
                strpos($label, $normalizedQuery) === 0 ||
                ($code !== "" && strpos($code, $normalizedQuery) === 0) ||
                ($shortName !== "" && strpos($shortName, $normalizedQuery) === 0)
            ) {
                $matches[self::normalizeLookupText($row["course_title"] ?? "")] = $row;
            }
        }

        if (count($matches) !== 1) {
            return null;
        }

        $candidate = array_values($matches)[0];
        return self::buildClarificationPayload($intent, $candidate["course_title"] ?? "", $language);
    }

    private static function maybeBuildClarificationFromScores($intent, $message, $bestMatch, $bestScore, $secondBestScore, $language = "en") {
        if (!$bestMatch) {
            return null;
        }

        $normalizedQuery = self::applyCourseAliases(self::stripCourseQueryNoise($message));

        if ($normalizedQuery === "") {
            return null;
        }

        if (mb_strlen($normalizedQuery, "UTF-8") <= 1) {
            return self::buildClarificationPayload($intent, $bestMatch["course_title"] ?? "", $language);
        }

        if ($bestScore < 45 || $bestScore >= 90) {
            return null;
        }

        if ($secondBestScore > 0 && ($bestScore - $secondBestScore) < 8) {
            return null;
        }

        $normalizedMessage = self::normalizeLookupText($message);
        $normalizedTitle = self::normalizeLookupText($bestMatch["course_title"] ?? "");
        $promptLabel = self::normalizeLookupText(self::getCoursePromptLabel($bestMatch["course_title"] ?? ""));

        if (
            $normalizedTitle !== "" &&
            (strpos($normalizedMessage, $normalizedTitle) !== false || ($promptLabel !== "" && strpos($normalizedMessage, $promptLabel) !== false))
        ) {
            return null;
        }

        return self::buildClarificationPayload($intent, $bestMatch["course_title"] ?? "", $language);
    }

    private static function extractKnownAttendanceSubject($message) {
        $normalizedMessage = self::normalizeLookupText(self::canonicalizeCourseQueryTerms($message));

        $subjectPatterns = [
            "database management systems" => [
                "database management systems",
                "dbms",
                "d b m s",
                "ಡಿಬಿಎಂಎಸ್"
            ],
            "dbms laboratory" => [
                "dbms laboratory",
                "dbms lab",
                "ಡಿಬಿಎಂಎಸ್ ಲ್ಯಾಬ್"
            ],
            "operating systems" => [
                "operating systems",
                "operating system",
                "os",
                "o s",
                "ಆಪರೇಟಿಂಗ್ ಸಿಸ್ಟಂ",
                "ಆಪರೇಟಿಂಗ್ ಸಿಸ್ಟಮ್ಸ್",
                "ಅಪರೇಟಿಂಗ್ ಸಿಸ್ಟಂ",
                "ಒಪರೇಟಿಂಗ್ ಸಿಸ್ಟಂ",
                "ಆಪರೇಟಿಂಗ್ ಸಿಸ್ಟಮ್ಸ್",
                "ಆಪರೇಟಿಂಗ್ ಸಿಸ್ಟಮ್"
            ],
            "design and analysis of algorithms" => [
                "/\\bada\\b/u",
                "/\\ba\\s*d\\s*a\\b/u",
                "/\\b(?:design and analysis of algorithms|analysis and design of algorithms)\\b/u"
            ],
            "computer networks" => [
                "computer networks",
                "computer network",
                "computer net work",
                "cn",
                "c n",
                "ಕಂಪ್ಯೂಟರ್ ನೆಟ್ವರ್ಕ್ಸ್",
                "ಕಂಪ್ಯೂಟರ್ ನೆಟ್ ವರ್ಕ್ಸ್",
                "ಕಂಪ್ಯೂಟರ್ ನೆಟ್ವರ್ಕ್ಸ್",
                "ಕಂಪ್ಯೂಟರ್ ನೆಟ್ವರ್ಕ್",
                "ಕಂಪ್ಯೂಟರ್ ನೆಟ್ ವರ್ಕ್ಸ್",
                "ಕಂಪ್ಯೂಟರ್ ನೆಟ್ ವರ್ಕ್"
            ],
            "design and analysis of algorithms" => [
                "design and analysis of algorithms",
                "analysis and design of algorithms",
                "ada",
                "a d a"
            ],
            "artificial intelligence" => [
                "artificial intelligence",
                "artificial intelligent",
                "ai",
                "a i",
                "ಆರ್ಟಿಫಿಶಿಯಲ್ ಇಂಟೆಲಿಜೆನ್ಸ್",
                "ಆರ್ಟಿಫಿಷಿಯಲ್ ಇಂಟೆಲಿಜೆನ್ಸ್",
                "ಅರ್ಟಿಫಿಶಿಯಲ್ ಇಂಟೆಲಿಜೆನ್ಸ್",
                "ಫಿಶಲ್ ಇಂಟೆಲಿಜೆನ್ಸ್",
                "ಆರ್ಟಿಫಿಶಿಯಲ್ ಇಂಟೆಲಿಜೆನ್ಸ್"
            ]
        ];

        foreach ($subjectPatterns as $canonical => $patterns) {
            foreach ($patterns as $pattern) {
                $normalizedPattern = self::normalizeLookupText($pattern);
                if ($normalizedPattern !== "" && strpos($normalizedMessage, $normalizedPattern) !== false) {
                    return $canonical;
                }
            }
        }

        return "";
    }

    public static function inferAttendanceSubject($message) {
        return self::extractKnownAttendanceSubject($message);
    }

    private static function getStudentAcademicContext($student_id) {
        global $conn;

        $stmt = $conn->prepare("
            SELECT branch, semester
            FROM students
            WHERE student_id = ?
        ");

        if (!$stmt) {
            return null;
        }

        $stmt->bind_param("i", $student_id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $result ?: null;
    }

    private static function getStudentProfileRow($student_id) {
        global $conn;

        $stmt = $conn->prepare("
            SELECT full_name, usn, branch, semester, email, mobile_no
            FROM students
            WHERE student_id = ?
        ");

        if (!$stmt) {
            return null;
        }

        $stmt->bind_param("i", $student_id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $result ?: null;
    }

    private static function extractRequestedSemester($message) {
        $message = strtolower($message);

        if (preg_match('/\b(?:semester|sem)\s*(\d+)\b/', $message, $matches)) {
            return (int) $matches[1];
        }

        $wordToSemester = [
            "first" => 1,
            "second" => 2,
            "third" => 3,
            "fourth" => 4,
            "fifth" => 5,
            "sixth" => 6,
            "seventh" => 7,
            "eighth" => 8,
            "1st" => 1,
            "2nd" => 2,
            "3rd" => 3,
            "4th" => 4,
            "5th" => 5,
            "6th" => 6,
            "7th" => 7,
            "8th" => 8
        ];

        foreach ($wordToSemester as $word => $semester) {
            if (strpos($message, $word . " sem") !== false || strpos($message, $word . " semester") !== false) {
                return $semester;
            }
        }

        return null;
    }

    public static function inferRequestedSemester($message) {
        return self::extractRequestedSemester($message);
    }

    private static function extractExamType($message) {
        $message = strtolower($message);

        if (strpos($message, "supplementary") !== false || strpos($message, "supply") !== false) {
            return "SUPPLEMENTARY";
        }

        // RE-REGISTRATION hall ticket — matches real ERP exam type
        if (preg_match('/\bre[\s\-]?registration\b|\breregistration\b/i', $message)) {
            return "RE-REGISTRATION";
        }

        // RESIT hall ticket
        if (preg_match('/\bresit\b|\bre[\s\-]?sit\b/i', $message)) {
            return "RESIT";
        }

        // Revaluation — real ERP 4th exam type
        if (preg_match('/\b(revaluation|reval|re\s*valu)\b/i', $message)) {
            return "Revaluation";
        }

        if (strpos($message, "see") !== false || preg_match('/\bs\s*e\s*e\b|\bc\b/i', $message)) {
            return "SEE";
        }

        if (strpos($message, "cie") !== false || strpos($message, "internal") !== false) {
            return "CIE";
        }

        return null;
    }

    public static function inferExamType($message) {
        return self::extractExamType($message);
    }

    private static function getLatestSemester($student_id) {
        global $conn;

        $semStmt = $conn->prepare("
            SELECT semester
            FROM results
            WHERE student_id = ?
            ORDER BY semester DESC
            LIMIT 1
        ");

        if (!$semStmt) {
            return null;
        }

        $semStmt->bind_param("i", $student_id);
        $semStmt->execute();
        $semResult = $semStmt->get_result()->fetch_assoc();
        $semStmt->close();

        return $semResult["semester"] ?? null;
    }

    /**
     * Resolves relative time phrases to an actual semester number.
     * Explicit ordinals always take priority ("semester 3", "5th sem").
     * Falls back to student's enrolled semester for relative phrases.
     */
    public static function inferRelativeSemester($message, $student_id) {
        return self::resolveRelativeSemester($message, $student_id);
    }

    private static function resolveRelativeSemester($message, $student_id) {
        $explicit = self::extractRequestedSemester($message);
        if ($explicit !== null) return $explicit;

        $lower = strtolower((string) $message);

        // "this semester" / "current semester" / "current sem"
        if (preg_match('/\b(this|current)\s*(semester|sem)\b/i', $lower)) {
            $student = self::getStudentAcademicContext($student_id);
            $sem = (int) ($student["semester"] ?? 0);
            return $sem > 0 ? $sem : self::getLatestSemester($student_id);
        }

        // "last semester" / "previous semester" / "past semester" / "preceding semester"
        if (preg_match('/\b(last|previous|prev|past|preceding)\s*(semester|sem)\b/i', $lower)) {
            $student = self::getStudentAcademicContext($student_id);
            $current = (int) ($student["semester"] ?? 0);
            if ($current < 1) {
                $current = (int) (self::getLatestSemester($student_id) ?? 1);
            }
            return max(1, $current - 1);
        }

        // "latest" / "most recent" → highest semester available in results
        if (preg_match('/\b(latest|most recent)\b/i', $lower)) {
            return self::getLatestSemester($student_id);
        }

        return null;
    }

    private static function getSemesterRows($student_id, $semester) {
        global $conn;

        $stmt = $conn->prepare("
            SELECT c.course_title, c.course_code, r.grade_point, r.credits
            FROM results r
            JOIN courses c ON r.course_id = c.course_id
            WHERE r.student_id = ?
            AND r.semester = ?
        ");

        if (!$stmt) {
            return null;
        }

        $stmt->bind_param("ii", $student_id, $semester);
        $stmt->execute();
        $result = $stmt->get_result();

        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }

        $stmt->close();
        return $rows;
    }

    private static function buildSemesterPerformance($student_id, $semester) {
        $rows = self::getSemesterRows($student_id, $semester);

        if ($rows === null) {
            return [
                "error" => "System error while fetching result."
            ];
        }

        if (empty($rows)) {
            return [
                "error" => "I could not find result data for semester {$semester}."
            ];
        }

        $totalCredits = 0.0;
        $totalPoints = 0.0;
        $backlogs = [];

        foreach ($rows as $row) {
            $credits = (float) $row["credits"];
            $gradePoint = (float) $row["grade_point"];

            $totalCredits += $credits;
            $totalPoints += ($gradePoint * $credits);

            // College rule: grade point 0 means the course is not cleared.
            if ($gradePoint <= 0) {
                $backlogs[] = $row["course_title"];
            }
        }

        if ($totalCredits <= 0) {
            return [
                "error" => "Result data is incomplete for semester {$semester}."
            ];
        }

        $sgpa = round($totalPoints / $totalCredits, 2);

        return [
            "semester" => $semester,
            "sgpa" => $sgpa,
            "credits" => $totalCredits,
            "backlogs" => $backlogs
        ];
    }

    private static function groupBacklogsBySemester($backlogs) {
        $grouped = [];

        foreach ($backlogs as $backlog) {
            $semester = (int) ($backlog["semester"] ?? 0);
            $courseTitle = trim((string) ($backlog["course_title"] ?? ""));

            if ($semester <= 0 || $courseTitle === "") {
                continue;
            }

            if (!isset($grouped[$semester])) {
                $grouped[$semester] = [];
            }

            $grouped[$semester][] = $courseTitle;
        }

        ksort($grouped);
        return $grouped;
    }

    /* ================= GET USN ================= */

    public static function getUSN($student_id, $language = "en") {
        global $conn;

        $stmt = $conn->prepare("
            SELECT usn 
            FROM students 
            WHERE student_id = ?
        ");

        if (!$stmt) {
            return self::isKannada($language) ? "USN ಮಾಹಿತಿ ತರುತ್ತಿರುವಾಗ ಸಿಸ್ಟಮ್ ದೋಷ ಉಂಟಾಯಿತು." : "System error while fetching USN.";
        }

        $stmt->bind_param("i", $student_id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$result) {
            return self::isKannada($language) ? "USN ಮಾಹಿತಿ ಸಿಗಲಿಲ್ಲ." : "USN not found.";
        }

        return self::isKannada($language)
            ? "ನಿಮ್ಮ USN " . $result['usn'] . "."
            : "Your USN is " . $result['usn'] . ".";
    }

    public static function getProfileSummary($student_id, $message = "", $language = "en") {
        $profile = self::getStudentProfileRow($student_id);

        if (!$profile) {
            return self::isKannada($language) ? "ಈಗ ನಿಮ್ಮ ವಿದ್ಯಾರ್ಥಿ ಪ್ರೊಫೈಲ್ ಸಿಗಲಿಲ್ಲ." : "I could not find your student profile right now.";
        }

        $message = strtolower(trim((string) $message));
        $fullName = trim((string) ($profile["full_name"] ?? ""));
        $branch = trim((string) ($profile["branch"] ?? ""));
        $semester = (int) ($profile["semester"] ?? 0);
        $usn = trim((string) ($profile["usn"] ?? ""));

        if (strpos($message, "semester") !== false) {
            if ($semester > 0) {
                return self::isKannada($language)
                    ? "ನೀವು ಈಗ {$semester}ನೇ ಸೆಮಿಸ್ಟರ್‌ನಲ್ಲಿ ಓದುತ್ತಿದ್ದೀರಿ."
                    : "You are currently studying in the {$semester}" . self::getOrdinalSuffix($semester) . " semester.";
            }

            return self::isKannada($language) ? "ಈಗ ನಿಮ್ಮ ಸೆಮಿಸ್ಟರ್ ವಿವರಗಳು ಸಿಗಲಿಲ್ಲ." : "I could not find your semester details right now.";
        }

        if (strpos($message, "department") !== false || strpos($message, "branch") !== false) {
            if ($branch !== "") {
                return self::isKannada($language)
                    ? "ನೀವು {$branch} ವಿಭಾಗದವರು."
                    : "You are from the {$branch} department.";
            }

            return self::isKannada($language) ? "ಈಗ ನಿಮ್ಮ ವಿಭಾಗದ ವಿವರಗಳು ಸಿಗಲಿಲ್ಲ." : "I could not find your department details right now.";
        }

        if (strpos($message, "what am i studying") !== false || strpos($message, "profile") !== false || strpos($message, "who am i") !== false || strpos($message, "do you know who i am") !== false) {
            $parts = [];

            if ($fullName !== "") {
                $parts[] = $fullName;
            }

            if ($semester > 0 && $branch !== "") {
                $parts[] = "a {$semester}" . self::getOrdinalSuffix($semester) . " semester {$branch} student at GM University";
            } elseif ($branch !== "") {
                $parts[] = "a {$branch} student at GM University";
            } elseif ($semester > 0) {
                $parts[] = "a student in the {$semester}" . self::getOrdinalSuffix($semester) . " semester at GM University";
            }

            if (!empty($parts)) {
                if (self::isKannada($language)) {
                    $reply = "ಇದು ನಿಮ್ಮ ಪ್ರೊಫೈಲ್ ವಿವರ.";
                    if ($fullName !== "") {
                        $reply .= " ನಿಮ್ಮ ಹೆಸರು {$fullName}.";
                    }
                    if ($branch !== "") {
                        $reply .= " ನೀವು {$branch} ವಿಭಾಗದವರು.";
                    }
                    if ($semester > 0) {
                        $reply .= " ನೀವು {$semester}ನೇ ಸೆಮಿಸ್ಟರ್‌ನಲ್ಲಿ ಇದ್ದೀರಿ.";
                    }
                    if ($usn !== "") {
                        $reply .= " ನಿಮ್ಮ USN {$usn}.";
                    }
                    return $reply;
                }

                $reply = "You are " . implode(", ", $parts) . ".";
                if ($usn !== "") {
                    $reply .= " Your USN is {$usn}.";
                }
                $reply .= " How can I help you today?";
                return $reply;
            }
        }

        if (self::isKannada($language)) {
            $reply = "ಇದು ನಿಮ್ಮ ಪ್ರೊಫೈಲ್ ವಿವರ.";
            if ($fullName !== "") {
                $reply .= " ನಿಮ್ಮ ಹೆಸರು {$fullName}.";
            }
            if ($branch !== "") {
                $reply .= " ನೀವು {$branch} ವಿಭಾಗದವರು.";
            }
            if ($semester > 0) {
                $reply .= " ನೀವು {$semester}ನೇ ಸೆಮಿಸ್ಟರ್‌ನಲ್ಲಿ ಇದ್ದೀರಿ.";
            }
            if ($usn !== "") {
                $reply .= " ನಿಮ್ಮ USN {$usn}.";
            }
            return $reply;
        }

        $reply = "Here is your profile summary.";
        if ($fullName !== "") {
            $reply .= " Your name is {$fullName}.";
        }
        if ($branch !== "") {
            $reply .= " You are from {$branch}.";
        }
        if ($semester > 0) {
            $reply .= " You are in the {$semester}" . self::getOrdinalSuffix($semester) . " semester.";
        }
        if ($usn !== "") {
            $reply .= " Your USN is {$usn}.";
        }

        return $reply;
    }

    private static function getOrdinalSuffix($number) {
        $number = (int) $number;

        if ($number % 100 >= 11 && $number % 100 <= 13) {
            return "th";
        }

        switch ($number % 10) {
            case 1:
                return "st";
            case 2:
                return "nd";
            case 3:
                return "rd";
            default:
                return "th";
        }
    }


    /* ================= CALCULATE SGPA ================= */

    public static function getSGPA($student_id, $message = "", $language = "en") {
        $requestedSemester = self::resolveRelativeSemester($message, $student_id);
        $student = self::getStudentAcademicContext($student_id);
        $currentSemester = (int) ($student["semester"] ?? 0);
        $semester = $requestedSemester ?: ($currentSemester ?: self::getLatestSemester($student_id));

        if (!$semester) {
            return self::isKannada($language) ? "ಫಲಿತಾಂಶದ ಮಾಹಿತಿ ಸಿಗಲಿಲ್ಲ." : "No result information found.";
        }

        $performance = self::buildSemesterPerformance($student_id, $semester);
        if (isset($performance["error"])) {
            return $performance["error"];
        }

        $sgpa = $performance["sgpa"];
        $totalCredits = $performance["credits"];
        $backlogs = $performance["backlogs"];

        if (!empty($backlogs)) {
            $backlogCount = count($backlogs);
            $backlogList = implode(", ", array_slice($backlogs, 0, 3));
            if (self::isHindi($language)) {
                return "Semester {$semester} mein aapka SGPA {$sgpa} hai. Aapke paas {$backlogCount} backlog" . ($backlogCount > 1 ? "s hain" : " hai") . ", jisme " . $backlogList . " shamil hain. Himmat mat haaro — har topper ne kisi na kisi baar takleef uthaya hai. Smart tarike se padho, backlogs clear karo aur wapas aao aur bhi mazboot hokar!";
            }
            if (self::isKannada($language)) {
                return "Nimma semester {$semester} SGPA {$sgpa}. Nimge {$backlogCount} backlog" . ($backlogCount > 1 ? "galu ive" : " ide") . ": " . $backlogList . ". Dhairya bidalabedi — pratiyobba topper challenge face madiddarey. Smart aagi odi, backlogs clear madi, innashu strong aagi hogle!";
            }
            return "In semester {$semester}, your SGPA is {$sgpa}. You have {$backlogCount} backlog" . ($backlogCount > 1 ? "s" : "") . " in " . $backlogList . ". Don't lose heart — every topper has faced challenges. Study smart, clear your backlogs, and you will come back even stronger!";
        }

        // Performance commentary based on SGPA bands
        if (self::isHindi($language)) {
            if ($sgpa >= 9.5) {
                $performanceLine = "Waah! Yeh toh kamaal ka performance hai — aap apni class ke top mein hain. Aise hi jabardast kaam karte raho!";
            } elseif ($sgpa >= 9) {
                $performanceLine = "Excellent! Aap distinction level par hain. Itna accha kaam karte raho aur aap aur bhi upar jaoge!";
            } elseif ($sgpa >= 8) {
                $performanceLine = "First class result! Aapko apne aap par garv hona chahiye. Thoda aur push karo aur distinction aa jayega!";
            } elseif ($sgpa >= 7) {
                $performanceLine = "Achha kiya! Thoda aur focus karoge toh first class asaani se aa jayega.";
            } elseif ($sgpa >= 6) {
                $performanceLine = "Aap pass hain. Apne weak subjects par zyada dhyan do — improvement zaroor hoga.";
            } else {
                $performanceLine = "Aap pass hain, lekin is baar serious improvement chahiye. Mentor se milkar ek clear plan banao.";
            }
            return "Semester {$semester} mein aapka SGPA {$sgpa} hai. Aapne {$totalCredits} credits haasil kiye hain. {$performanceLine}";
        }

        if (self::isKannada($language)) {
            if ($sgpa >= 9.5) {
                $performanceLine = "Wah! Ee performance absolutely brilliant — neevu nimma class topalli iddira. Ee excellent work munduvarisi!";
            } elseif ($sgpa >= 9) {
                $performanceLine = "Excellent! Neevu distinction level nalli iddira. Ee pace maintain madi — neevu innu heechchu saadhisabahudu!";
            } elseif ($sgpa >= 8) {
                $performanceLine = "First class result! Neevu nimma bagge garva padabeku. Innu swalpa push madi — distinction kaigochutte!";
            } elseif ($sgpa >= 7) {
                $performanceLine = "Channagi madiddira! Innu focus madidare first class asanavaguttide.";
            } elseif ($sgpa >= 6) {
                $performanceLine = "Neevu pass agiddira. Weak subjects mele hechchu gaman kodi — improvement aaguttade.";
            } else {
                $performanceLine = "Neevu pass agiddira, aadare serious improvement beku. Mentor jothe matadi clear plan madi.";
            }
            return "Nimma semester {$semester} SGPA {$sgpa}. Neevu {$totalCredits} credits galisiddira. {$performanceLine}";
        }

        if ($sgpa >= 9.5) {
            $performanceLine = "Absolutely brilliant! You are in the top of your class — keep up this outstanding work!";
        } elseif ($sgpa >= 9) {
            $performanceLine = "Excellent work! You are performing at distinction level. Keep this pace going — you can go even higher!";
        } elseif ($sgpa >= 8) {
            $performanceLine = "First class result! You should be proud of yourself. Push just a little more and distinction is within reach!";
        } elseif ($sgpa >= 7) {
            $performanceLine = "Good job! A bit more focus and first class is easily achievable.";
        } elseif ($sgpa >= 6) {
            $performanceLine = "You have passed. Focus more on your weaker subjects and you will see real improvement.";
        } else {
            $performanceLine = "You have passed, but this semester needs serious attention. Connect with your mentor and build a clear improvement plan.";
        }

        return "In semester {$semester}, your SGPA is {$sgpa}. You have earned {$totalCredits} credits. {$performanceLine}";
    }

    public static function getCGPA($student_id, $query = "", $language = "en") {
        global $conn;

        // If a specific semester is requested, compute cumulative GPA up through that semester.
        $upToSemester = self::resolveRelativeSemester($query, $student_id);

        if ($upToSemester !== null) {
            $stmt = $conn->prepare("
                SELECT semester, grade_point, credits
                FROM results
                WHERE student_id = ? AND semester <= ?
                ORDER BY semester ASC
            ");
            if (!$stmt) {
                return self::isKannada($language) ? "CGPA ಮಾಹಿತಿ ತರುತ್ತಿರುವಾಗ ಸಿಸ್ಟಮ್ ದೋಷ ಉಂಟಾಯಿತು." : "System error while fetching CGPA.";
            }
            $stmt->bind_param("ii", $student_id, $upToSemester);
        } else {
            $stmt = $conn->prepare("
                SELECT semester, grade_point, credits
                FROM results
                WHERE student_id = ?
                ORDER BY semester ASC
            ");
            if (!$stmt) {
                return self::isKannada($language) ? "CGPA ಮಾಹಿತಿ ತರುತ್ತಿರುವಾಗ ಸಿಸ್ಟಮ್ ದೋಷ ಉಂಟಾಯಿತು." : "System error while fetching CGPA.";
            }
            $stmt->bind_param("i", $student_id);
        }

        $stmt->execute();
        $result = $stmt->get_result();

        $totalCredits = 0.0;
        $totalPoints = 0.0;
        $semesterSet = [];
        $backlogCount = 0;

        while ($row = $result->fetch_assoc()) {
            $credits = (float) $row["credits"];
            $gradePoint = (float) $row["grade_point"];

            $totalCredits += $credits;
            $totalPoints += ($gradePoint * $credits);
            $semesterSet[$row["semester"]] = true;

            if ($gradePoint <= 0) {
                $backlogCount += 1;
            }
        }

        $stmt->close();

        if ($totalCredits <= 0) {
            return self::isKannada($language) ? "ನಿಮ್ಮ CGPA ಲೆಕ್ಕಿಸಲು ಬೇಕಾದ ಫಲಿತಾಂಶ ಮಾಹಿತಿ ಸಿಗಲಿಲ್ಲ." : "I could not find enough result data to calculate your CGPA.";
        }

        $cgpa = round($totalPoints / $totalCredits, 2);
        $semesterCount = count($semesterSet);

        if (self::isKannada($language)) {
            $semNote = $upToSemester !== null ? " (semester {$upToSemester} ವರೆಗೆ)" : "";
            $reply = "ನಿಮ್ಮ CGPA{$semNote} {$cgpa}. ಇದು {$semesterCount} ಸೆಮಿಸ್ಟರ್ ಆಧಾರದಲ್ಲಿ ಲೆಕ್ಕಿಸಲಾಗಿದೆ.";
            if ($backlogCount > 0) {
                $reply .= " ಈಗ ನಿಮಗೆ {$backlogCount} uncleared backlog" . ($backlogCount > 1 ? "ಗಳು ಇವೆ." : " ಇದೆ.");
            } else {
                $reply .= " ಈಗ ನಿಮಗೆ ಯಾವುದೇ backlog ಇಲ್ಲ.";
            }
            return $reply;
        }

        $throughNote = $upToSemester !== null
            ? " through semester {$upToSemester}"
            : "";
        $reply = "Your CGPA{$throughNote} is {$cgpa}, calculated across {$semesterCount} semester" . ($semesterCount > 1 ? "s" : "") . ".";

        if ($backlogCount > 0) {
            $reply .= " You currently have {$backlogCount} uncleared backlog" . ($backlogCount > 1 ? "s" : "") . ".";
        } else {
            $reply .= " You do not have any current backlog.";
        }

        return $reply;
    }

    public static function getBacklogStatus($student_id, $message = "", $language = "en") {
        $requestedSemester = self::resolveRelativeSemester($message, $student_id);

        if ($requestedSemester) {
            $performance = self::buildSemesterPerformance($student_id, $requestedSemester);
            if (isset($performance["error"])) {
                return $performance["error"];
            }

            $backlogs = $performance["backlogs"];
            if (empty($backlogs)) {
                return self::isKannada($language)
                    ? "ನೀವು {$requestedSemester}ನೇ ಸೆಮಿಸ್ಟರ್ ಪಾಸ್ ಆಗಿದ್ದೀರಿ ಮತ್ತು ಆ ಸೆಮಿಸ್ಟರ್‌ನಲ್ಲಿ ಯಾವುದೇ backlog ಇಲ್ಲ."
                    : "You passed semester {$requestedSemester} and you do not have any backlog in that semester.";
            }

            return self::isKannada($language)
                ? "{$requestedSemester}ನೇ ಸೆಮಿಸ್ಟರ್‌ನಲ್ಲಿ ನಿಮಗೆ " . count($backlogs) . " backlog" . (count($backlogs) > 1 ? "ಗಳು ಇವೆ." : " ಇದೆ.") . " ಉಳಿದಿರುವ ವಿಷಯಗಳು: " . implode(", ", array_slice($backlogs, 0, 4)) . "."
                : "In semester {$requestedSemester}, you have " . count($backlogs) . " backlog" . (count($backlogs) > 1 ? "s" : "") . ". The uncleared subject" . (count($backlogs) > 1 ? "s are " : " is ") . implode(", ", array_slice($backlogs, 0, 4)) . ".";
        }

        global $conn;

        $stmt = $conn->prepare("
            SELECT c.course_title, r.semester, r.grade_point
            FROM results r
            JOIN courses c ON r.course_id = c.course_id
            WHERE r.student_id = ?
            ORDER BY r.semester ASC
        ");

        if (!$stmt) {
            return self::isKannada($language) ? "Backlog ಮಾಹಿತಿ ಪರಿಶೀಲಿಸುವಾಗ ಸಿಸ್ಟಮ್ ದೋಷ ಉಂಟಾಯಿತು." : "System error while checking backlog status.";
        }

        $stmt->bind_param("i", $student_id);
        $stmt->execute();
        $result = $stmt->get_result();

        $backlogs = [];
        while ($row = $result->fetch_assoc()) {
            if ((float) $row["grade_point"] <= 0) {
                $backlogs[] = [
                    "semester" => (int) $row["semester"],
                    "course_title" => $row["course_title"]
                ];
            }
        }

        $stmt->close();

        if (empty($backlogs)) {
            return self::isKannada($language)
                ? "ಈಗ ನಿಮಗೆ ಯಾವುದೇ backlog ಇಲ್ಲ. ಲಭ್ಯವಿರುವ ಫಲಿತಾಂಶದ ಪ್ರಕಾರ ನೀವು ಎಲ್ಲ cleared semesters ಪಾಸ್ ಆಗಿದ್ದೀರಿ."
                : "You do not have any current backlog. Your available result records show that you have passed all cleared semesters.";
        }

        $grouped = self::groupBacklogsBySemester($backlogs);

        $parts = [];
        foreach ($grouped as $semester => $subjects) {
            $parts[] = "semester {$semester}: " . implode(", ", array_slice($subjects, 0, 4));
        }

        return self::isKannada($language)
            ? "ಈಗ ನಿಮಗೆ " . count($backlogs) . " backlog" . (count($backlogs) > 1 ? "ಗಳು ಇವೆ." : " ಇದೆ.") . " ಉಳಿದಿರುವ ವಿಷಯಗಳು: " . implode("; ", $parts) . "."
            : "You currently have " . count($backlogs) . " backlog" . (count($backlogs) > 1 ? "s" : "") . " across " . count($grouped) . " semester" . (count($grouped) > 1 ? "s" : "") . ". Uncleared subjects are " . implode("; ", $parts) . ".";
    }

    public static function getHallTicketStatus($student_id, $message = "", $language = "en") {
        global $conn;

        $requestedExamType = self::extractExamType($message);
        $requestedSemester = self::resolveRelativeSemester($message, $student_id);

        if ($requestedExamType && $requestedSemester) {
            $stmt = $conn->prepare("
                SELECT exam_type, semester, academic_year, status, status_message
                FROM hall_tickets
                WHERE student_id = ? AND exam_type = ? AND semester = ?
                ORDER BY hall_ticket_id DESC LIMIT 1
            ");
            if (!$stmt) return self::isKannada($language) ? "Hall ticket ಮಾಹಿತಿ ಪರಿಶೀಲಿಸುವಾಗ ಸಿಸ್ಟಮ್ ದೋಷ ಉಂಟಾಯಿತು." : "System error while checking hall ticket status.";
            $stmt->bind_param("isi", $student_id, $requestedExamType, $requestedSemester);
        } elseif ($requestedExamType) {
            $stmt = $conn->prepare("
                SELECT exam_type, semester, academic_year, status, status_message
                FROM hall_tickets
                WHERE student_id = ? AND exam_type = ?
                ORDER BY hall_ticket_id DESC LIMIT 1
            ");
            if (!$stmt) return self::isKannada($language) ? "Hall ticket ಮಾಹಿತಿ ಪರಿಶೀಲಿಸುವಾಗ ಸಿಸ್ಟಮ್ ದೋಷ ಉಂಟಾಯಿತು." : "System error while checking hall ticket status.";
            $stmt->bind_param("is", $student_id, $requestedExamType);
        } elseif ($requestedSemester) {
            $stmt = $conn->prepare("
                SELECT exam_type, semester, academic_year, status, status_message
                FROM hall_tickets
                WHERE student_id = ? AND semester = ?
                ORDER BY hall_ticket_id DESC LIMIT 1
            ");
            if (!$stmt) return self::isKannada($language) ? "Hall ticket ಮಾಹಿತಿ ಪರಿಶೀಲಿಸುವಾಗ ಸಿಸ್ಟಮ್ ದೋಷ ಉಂಟಾಯಿತು." : "System error while checking hall ticket status.";
            $stmt->bind_param("ii", $student_id, $requestedSemester);
        } else {
            $stmt = $conn->prepare("
                SELECT exam_type, semester, academic_year, status, status_message
                FROM hall_tickets
                WHERE student_id = ?
                ORDER BY hall_ticket_id DESC LIMIT 1
            ");
            if (!$stmt) return self::isKannada($language) ? "Hall ticket ಮಾಹಿತಿ ಪರಿಶೀಲಿಸುವಾಗ ಸಿಸ್ಟಮ್ ದೋಷ ಉಂಟಾಯಿತು." : "System error while checking hall ticket status.";
            $stmt->bind_param("i", $student_id);
        }

        $stmt->execute();
        $record = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$record) {
            return self::isKannada($language) ? "ಈಗ ನಿಮ್ಮ ಖಾತೆಗೆ hall ticket ಮಾಹಿತಿ ಸಿಗಲಿಲ್ಲ." : "I could not find any hall ticket status for your account right now.";
        }

        $examType = $record["exam_type"];
        $semester = $record["semester"];
        $academicYear = $record["academic_year"];
        $status = strtoupper(trim((string) $record["status"]));
        $statusMessage = trim((string) ($record["status_message"] ?? ""));

        if ($status === "GENERATED") {
            return self::isKannada($language)
                ? "{$academicYear}ರಲ್ಲಿ {$semester}ನೇ ಸೆಮಿಸ್ಟರ್‌ನ {$examType} hall ticket ಯಶಸ್ವಿಯಾಗಿ generate ಆಗಿದೆ. ನೀವು ಅದನ್ನು hall ticket sectionನಲ್ಲಿ download ಮಾಡಬಹುದು."
                : "Your {$examType} hall ticket for semester {$semester} in {$academicYear} has been generated successfully. You can download it from the hall ticket section.";
        }

        if ($status === "PENDING") {
            return self::isKannada($language)
                ? "{$academicYear}ರಲ್ಲಿ {$semester}ನೇ ಸೆಮಿಸ್ಟರ್‌ನ {$examType} hall ticket ಇನ್ನೂ generate ಆಗಿಲ್ಲ. " . ($statusMessage !== "" ? $statusMessage : "ದಯವಿಟ್ಟು ನಂತರ ಮತ್ತೆ ಪರಿಶೀಲಿಸಿ.")
                : "Your {$examType} hall ticket for semester {$semester} in {$academicYear} is not generated yet. " . ($statusMessage !== "" ? $statusMessage : "Please check again later.");
        }

        if ($status === "NOT_APPROVED" || $status === "BLOCKED") {
            return self::isKannada($language)
                ? "{$academicYear}ರಲ್ಲಿ {$semester}ನೇ ಸೆಮಿಸ್ಟರ್‌ನ {$examType} hall ticket ಈಗ ಲಭ್ಯವಿಲ್ಲ. " . ($statusMessage !== "" ? $statusMessage : "ದಯವಿಟ್ಟು ನಿಮ್ಮ HOD ಅಥವಾ exam section ಅನ್ನು ಸಂಪರ್ಕಿಸಿ.")
                : "Your {$examType} hall ticket for semester {$semester} in {$academicYear} is not available right now. " . ($statusMessage !== "" ? $statusMessage : "Please contact your HOD or the exam section.");
        }

        return self::isKannada($language)
            ? "ನಿಮ್ಮ {$examType} examಗೆ hall ticket record ಸಿಕ್ಕಿದೆ, ಆದರೆ current status manual verification ಬೇಕಾಗಿದೆ. ದಯವಿಟ್ಟು exam section ಅನ್ನು ಸಂಪರ್ಕಿಸಿ."
            : "I found a hall ticket record for your {$examType} exam, but the current status needs manual verification. Please contact the exam section.";
    }

    public static function getCourseDetails($student_id, $message = "", $language = "en") {
        global $conn;

        $student = self::getStudentAcademicContext($student_id);
        if (!$student) {
            return self::isKannada($language) ? "ನಿಮ್ಮ ಸೆಮಿಸ್ಟರ್ ಮತ್ತು ವಿಭಾಗದ ವಿವರಗಳು ಸಿಗಲಿಲ್ಲ." : "I could not find your semester and branch details.";
        }

        $branch = $student["branch"];
        $semester = (int) $student["semester"];

        $stmt = $conn->prepare("
            SELECT course_code, course_title, course_type, credits
            FROM courses
            WHERE program = ? AND semester = ?
            ORDER BY course_type ASC, course_code ASC
        ");

        if (!$stmt) {
            return self::isKannada($language) ? "Course ವಿವರ ತರುತ್ತಿರುವಾಗ ಸಿಸ್ಟಮ್ ದೋಷ ಉಂಟಾಯಿತು." : "System error while fetching course details.";
        }

        $stmt->bind_param("si", $branch, $semester);
        $stmt->execute();
        $result = $stmt->get_result();

        $courses = [];
        while ($row = $result->fetch_assoc()) {
            $courses[] = $row;
        }

        $stmt->close();

        if (empty($courses)) {
            return self::isKannada($language) ? "ನಿಮ್ಮ current semesterಗೆ course details ಸಿಗಲಿಲ್ಲ." : "I could not find any course details for your current semester.";
        }

        $normalizedMessage = strtolower(trim($message));
        $knownSubject = self::extractKnownCourseSubject($message);
        $bestMatch = null;
        $bestScore = 0;
        foreach ($courses as $course) {
            $courseTitle = strtolower($course["course_title"]);
            $courseCode = strtolower($course["course_code"]);

            if (
                $knownSubject !== "" &&
                self::normalizeLookupText($course["course_title"]) === self::normalizeLookupText($knownSubject)
            ) {
                $bestMatch = $course;
                $bestScore = 100;
                continue;
            }

            $score = self::scoreCourseMatch($message, $course["course_title"], $course["course_code"]);

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestMatch = $course;
            }

            if (
                $normalizedMessage !== "" &&
                (strpos($normalizedMessage, $courseTitle) !== false || strpos($normalizedMessage, $courseCode) !== false)
            ) {
                $credits = rtrim(rtrim(number_format((float) $course["credits"], 1, ".", ""), "0"), ".");
                return self::isKannada($language)
                    ? "{$course["course_title"]} ವಿಷಯದ course code {$course["course_code"]}. ಇದು {$semester}ನೇ ಸೆಮಿಸ್ಟರ್‌ನ {$course["course_type"]} course ಆಗಿದ್ದು {$credits} credit" . ($credits === "1" ? "" : "s") . " ಹೊಂದಿದೆ."
                    : "{$course["course_title"]} has course code {$course["course_code"]}. It is a {$course["course_type"]} course with {$credits} credit" . ($credits === "1" ? "" : "s") . " in semester {$semester}.";
            }
        }

        if ($bestMatch && $bestScore >= 60) {
            if (self::isCourseCodeRequest($message)) {
                return self::isKannada($language)
                    ? "{$bestMatch["course_title"]} ವಿಷಯದ course code {$bestMatch["course_code"]}."
                    : "The course code for {$bestMatch["course_title"]} is {$bestMatch["course_code"]}.";
            }

            $credits = rtrim(rtrim(number_format((float) $bestMatch["credits"], 1, ".", ""), "0"), ".");
            return self::isKannada($language)
                ? "{$bestMatch["course_title"]} ವಿಷಯದ course code {$bestMatch["course_code"]}. ಇದು {$semester}ನೇ ಸೆಮಿಸ್ಟರ್‌ನ {$bestMatch["course_type"]} course ಆಗಿದ್ದು {$credits} credit" . ($credits === "1" ? "" : "s") . " ಹೊಂದಿದೆ."
                : "{$bestMatch["course_title"]} has course code {$bestMatch["course_code"]}. It is a {$bestMatch["course_type"]} course with {$credits} credit" . ($credits === "1" ? "" : "s") . " in semester {$semester}.";
        }

        $courseLabels = array_map(function ($course) {
            return $course["course_title"] . " (" . $course["course_code"] . ")";
        }, $courses);

        $preview = implode(", ", array_slice($courseLabels, 0, 6));
        if (count($courseLabels) > 6) {
            $preview .= ", and " . (count($courseLabels) - 6) . " more";
        }

        return self::isKannada($language)
            ? "{$semester}ನೇ ಸೆಮಿಸ್ಟರ್‌ನಲ್ಲಿ ನಿಮ್ಮ subjects: {$preview}."
            : "In semester {$semester}, your subjects are {$preview}.";
    }


    public static function getCourseCodeClarification($message, $language = "en") {
        global $conn;

        $stmt = $conn->prepare("
            SELECT course_code, course_title
            FROM courses
        ");

        if (!$stmt) {
            return null;
        }

        $stmt->execute();
        $result = $stmt->get_result();

        $bestMatch = null;
        $bestScore = 0;
        $secondBestScore = 0;
        $rows = [];

        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
            $score = self::scoreCourseMatch($message, $row['course_title'], $row['course_code']);

            if ($score > $bestScore) {
                $secondBestScore = $bestScore;
                $bestScore = $score;
                $bestMatch = $row;
            } elseif ($score > $secondBestScore) {
                $secondBestScore = $score;
            }
        }

        $stmt->close();

        $shortQueryCandidate = self::findShortQueryClarificationCandidate("GET_COURSE_CODE", $message, $rows, $language);
        if (is_array($shortQueryCandidate)) {
            return $shortQueryCandidate;
        }

        return self::maybeBuildClarificationFromScores("GET_COURSE_CODE", $message, $bestMatch, $bestScore, $secondBestScore, $language);
    }

    /* ================= GET COURSE CODE ================= */

   public static function getCourseCode($message, $language = "en") {
        global $conn;

        $knownSubject = self::extractKnownCourseSubject($message);

        $stmt = $conn->prepare("
            SELECT course_code, course_title
            FROM courses
        ");

        if (!$stmt) {
            return self::isKannada($language) ? "Course ಮಾಹಿತಿ ತರುತ್ತಿರುವಾಗ ಸಿಸ್ಟಮ್ ದೋಷ ಉಂಟಾಯಿತು." : "System error while fetching course information.";
        }

        $stmt->execute();
        $result = $stmt->get_result();

        $bestMatch = null;
        $bestScore = 0;

        while ($row = $result->fetch_assoc()) {
            if (
                $knownSubject !== "" &&
                self::normalizeLookupText($row['course_title']) === self::normalizeLookupText($knownSubject)
            ) {
                $bestMatch = $row;
                $bestScore = 100;
                continue;
            }

            $score = self::scoreCourseMatch($message, $row['course_title'], $row['course_code']);

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestMatch = $row;
            }
        }

        $stmt->close();

        if ($bestMatch && $bestScore >= 60) {
            return self::isKannada($language)
                ? "ನಿಮ್ಮ " . $bestMatch['course_title'] . " subject code " . $bestMatch['course_code'] . "."
                : "The course code for " . $bestMatch['course_title'] . " is " . $bestMatch['course_code'] . ".";
        }

        return self::isKannada($language) ? "ಆ course code ಸಿಗಲಿಲ್ಲ. ದಯವಿಟ್ಟು subject ಹೆಸರು ಇನ್ನಷ್ಟು ಸ್ಪಷ್ಟವಾಗಿ ಹೇಳಿ." : "I could not find that course code. Please say the subject name more clearly.";
    }

    /* ================= SUBJECT-WISE ATTENDANCE ================= */

    public static function getSubjectAttendance($student_id, $message, $language = "en") {
        global $conn;

        $normalizedMessage = self::normalizeLookupText($message);
        $requestedSemester = self::resolveRelativeSemester($message, $student_id);
        $genericAttendancePhrases = [
            "individual subject",
            "subject wise",
            "subject wise attendance",
            "attendance in individual subject",
            "attendance in subject",
            "attendance of subject",
            "subject attendance",
            "particular subject"
        ];

        $student = self::getStudentAcademicContext($student_id);
        if (!$student) {
            if (self::isHindi($language)) {
                return "मुझे अभी आपके वर्तमान सेमेस्टर की जानकारी नहीं मिली।";
            }
            return self::isKannada($language)
                ? "ನಿಮ್ಮ ಸೆಮಿಸ್ಟರ್ ವಿವರಗಳು ಈಗ ಸಿಗುತ್ತಿಲ್ಲ."
                : "I could not find your current semester details.";
        }

        $semester = (int) ($requestedSemester ?: ($student["semester"] ?? 0));

        $stmt = $conn->prepare("
            SELECT c.course_title, 
                   c.course_code,
                   c.semester,
                   a.total_classes, 
                   a.attended_classes, 
                   a.percentage
            FROM attendance a
            JOIN courses c ON a.course_id = c.course_id
            WHERE a.student_id = ?
              AND c.semester = ?
        ");

        if (!$stmt) {
            if (self::isHindi($language)) {
                return "अटेंडेंस की जानकारी लेते समय सिस्टम त्रुटि हुई।";
            }
            return self::isKannada($language) ? "Attendance ಮಾಹಿತಿ ತರುತ್ತಿರುವಾಗ ಸಿಸ್ಟಮ್ ದೋಷ ಉಂಟಾಯಿತು." : "System error while fetching attendance.";
        }

        $stmt->bind_param("ii", $student_id, $semester);
        $stmt->execute();
        $result = $stmt->get_result();
        $availableSubjects = [];
        $bestMatch = null;
        $bestScore = 0;
        $knownSubject = self::extractKnownAttendanceSubject($message);

        while ($row = $result->fetch_assoc()) {
            $availableSubjects[] = $row['course_title'];

            if ($knownSubject !== "" && self::normalizeLookupText($row['course_title']) === self::normalizeLookupText($knownSubject)) {
                $bestMatch = $row;
                $bestScore = 100;
                continue;
            }

            $score = self::scoreCourseMatch($message, $row['course_title'], $row['course_code'] ?? "");

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestMatch = $row;
            }
        }

        $stmt->close();

        if (empty($availableSubjects)) {
            if (self::isHindi($language)) {
                return "सेमेस्टर {$semester} के लिए अटेंडेंस डेटा उपलब्ध नहीं है।";
            }
            return self::isKannada($language)
                ? "Attendance data for semester {$semester} is not available."
                : "Attendance data for semester {$semester} is not available.";
        }

        // Past-semester fallback: if the current semester has attendance rows but the
        // specific course was not found there (bestScore < 60), search all semesters so
        // a student in sem 5 can still ask about sem 4 courses like "Java attendance".
        if ((!$bestMatch || $bestScore < 60) && $requestedSemester === null) {
            $pastStmt = $conn->prepare("
                SELECT c.course_title, c.course_code, c.semester,
                       a.total_classes, a.attended_classes, a.percentage
                FROM attendance a
                JOIN courses c ON a.course_id = c.course_id
                WHERE a.student_id = ?
            ");
            if ($pastStmt) {
                $pastStmt->bind_param("i", $student_id);
                $pastStmt->execute();
                $pastResult = $pastStmt->get_result();
                while ($pastRow = $pastResult->fetch_assoc()) {
                    if (in_array($pastRow["course_title"], $availableSubjects, true)) {
                        continue; // already scored in current-semester pass
                    }
                    $score = self::scoreCourseMatch($message, $pastRow["course_title"], $pastRow["course_code"] ?? "");
                    if ($score > $bestScore) {
                        $bestScore = $score;
                        $bestMatch = $pastRow;
                    }
                }
                $pastStmt->close();
            }
        }

        $cleanSubjectHint = self::applyCourseAliases(self::stripCourseQueryNoise($message));
        $askedGenerically = false;

        foreach ($genericAttendancePhrases as $phrase) {
            if (strpos($normalizedMessage, $phrase) !== false) {
                $askedGenerically = true;
                break;
            }
        }

        if ($cleanSubjectHint === "" || $askedGenerically) {
            $preview = implode(", ", array_slice($availableSubjects, 0, 4));
            if (self::isHindi($language)) {
                return "कृपया सेमेस्टर {$semester} का सही विषय नाम बताइए। उदाहरण के लिए, आप {$preview} के बारे में पूछ सकते हैं।";
            }
            return self::isKannada($language)
                ? "Please tell me the exact subject name from semester {$semester}. For example, you can ask about {$preview}."
                : "Please tell me the exact subject name from semester {$semester}. For example, you can ask about {$preview}.";
        }

        if ($bestMatch && $bestScore >= 60) {
            $percentage = round($bestMatch['percentage'], 2);
            $matchSem = (int) ($bestMatch['semester'] ?? $semester);
            $semNote = ($matchSem > 0 && $matchSem !== $semester)
                ? (self::isHindi($language)
                    ? " (सेमेस्टर {$matchSem})"
                    : " (semester {$matchSem})")
                : "";

            if (self::isHindi($language)) {
                $response = $bestMatch['course_title'] . "{$semNote} में आपकी उपस्थिति $percentage प्रतिशत है। आपने "
                    . $bestMatch['total_classes'] . " कक्षाओं में से " . $bestMatch['attended_classes'] . " कक्षाओं में उपस्थिति दी है।";
            } else {
                $response = self::isKannada($language)
                            ? $bestMatch['course_title'] . "{$semNote} ವಿಷಯದಲ್ಲಿ ನಿಮ್ಮ attendance $percentage ಪ್ರತಿಶತ. ನೀವು " .
                              $bestMatch['total_classes'] . " classesಗಳಲ್ಲಿ " . $bestMatch['attended_classes'] . " classes attend ಮಾಡಿದ್ದೀರಿ."
                            : "Your attendance in " . $bestMatch['course_title'] . "{$semNote}" .
                              " is $percentage percent. You attended " .
                              $bestMatch['attended_classes'] . " out of " .
                              $bestMatch['total_classes'] . " classes.";
            }

            if ($percentage < 75) {
                $response .= self::isHindi($language)
                    ? " चेतावनी: आपकी उपस्थिति आवश्यक 75 प्रतिशत से कम है।"
                    : (
                        self::isKannada($language)
                            ? " ಎಚ್ಚರಿಕೆ: ನಿಮ್ಮ attendance 75 ಪ್ರತಿಶತಕ್ಕಿಂತ ಕಡಿಮೆ ಇದೆ."
                            : " Warning: Your attendance is below the required 75 percent."
                    );
            }

            return $response;
        }

        $preview = implode(", ", array_slice($availableSubjects, 0, 4));
        if (self::isHindi($language)) {
            return "मुझे सेमेस्टर {$semester} में उस विषय की अटेंडेंस नहीं मिली। उपलब्ध विषयों में {$preview} शामिल हैं।";
        }
        return self::isKannada($language)
            ? "I could not find attendance for that subject in semester {$semester}. Available subjects include {$preview}."
            : "I could not find attendance for that subject in semester {$semester}. Available subjects include {$preview}.";
    }

    // Returns all courses from $rows whose match score >= $threshold, sorted best-first.
    private static function collectTopCourseMatches($message, $rows, $threshold = 80) {
        $scored = [];
        foreach ($rows as $row) {
            $score = self::scoreCourseMatch($message, $row["course_title"] ?? "", $row["course_code"] ?? "");
            if ($score >= $threshold) {
                $scored[] = ["row" => $row, "score" => $score];
            }
        }
        usort($scored, fn($a, $b) => $b["score"] <=> $a["score"]);
        return $scored;
    }

    // Builds a "Did you mean X or Y?" reply when 2+ courses match with high confidence.
    // Includes pending_intent and pending_titles so VapiToolService can resolve the
    // next user reply through conversation memory.
    private static function buildMultiDisambiguationReply($pendingIntent, $topMatches, $language = "en") {
        $labels = array_map(
            fn($m) => self::getCoursePromptLabel($m["row"]["course_title"] ?? ""),
            array_slice($topMatches, 0, 3)
        );
        $titles = array_map(
            fn($m) => $m["row"]["course_title"] ?? "",
            array_slice($topMatches, 0, 3)
        );

        if (count($labels) === 2) {
            $optionStr = $labels[0] . " or " . $labels[1];
        } else {
            $last = array_pop($labels);
            $optionStr = implode(", ", $labels) . ", or " . $last;
        }

        if (self::isHindi($language)) {
            $reply = "क्या आपका मतलब {$optionStr} था? कृपया बताइए।";
        } elseif (self::isKannada($language)) {
            $reply = "Nimma artha {$optionStr} aa? Dayavittu yavudu beku antha heli.";
        } else {
            $reply = "Did you mean {$optionStr}? Please say which one.";
        }

        return [
            "reply"          => $reply,
            "intent"         => "COURSE_DISAMBIGUATION",
            "pending_intent" => $pendingIntent,
            "pending_titles" => array_values($titles),
            "route"          => "clarification",
            "language"       => $language,
            "client_action"  => null,
            "suggestion"     => null,
            "quick_actions"  => [],
            "debug"          => ["source" => "student_controller", "reply_source" => "multi_disambiguation"]
        ];
    }

    public static function getSubjectAttendanceClarification($student_id, $message, $language = "en") {
        global $conn;

        $student = self::getStudentAcademicContext($student_id);
        if (!$student) {
            return null;
        }

        $requestedSemester = self::resolveRelativeSemester($message, $student_id);
        $semester = (int) ($requestedSemester ?: ($student["semester"] ?? 0));

        $stmt = $conn->prepare("
            SELECT c.course_title, c.course_code
            FROM attendance a
            JOIN courses c ON a.course_id = c.course_id
            WHERE a.student_id = ?
              AND c.semester = ?
        ");

        if (!$stmt) {
            return null;
        }

        $stmt->bind_param("ii", $student_id, $semester);
        $stmt->execute();
        $result = $stmt->get_result();

        $bestMatch = null;
        $bestScore = 0;
        $secondBestScore = 0;
        $rows = [];

        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
            $score = self::scoreCourseMatch($message, $row["course_title"], $row["course_code"] ?? "");

            if ($score > $bestScore) {
                $secondBestScore = $bestScore;
                $bestScore = $score;
                $bestMatch = $row;
            } elseif ($score > $secondBestScore) {
                $secondBestScore = $score;
            }
        }

        $stmt->close();

        // Multi-match: if 2+ courses score >= 80, existing maybeBuildClarificationFromScores
        // will never fire (it bails for scores >= 90). Catch the tie here explicitly.
        $topMatches = self::collectTopCourseMatches($message, $rows, 80);
        if (count($topMatches) >= 2) {
            return self::buildMultiDisambiguationReply("GET_SUBJECT_ATTENDANCE", $topMatches, $language);
        }

        $shortQueryCandidate = self::findShortQueryClarificationCandidate("GET_SUBJECT_ATTENDANCE", $message, $rows, $language);
        if (is_array($shortQueryCandidate)) {
            return $shortQueryCandidate;
        }

        return self::maybeBuildClarificationFromScores("GET_SUBJECT_ATTENDANCE", $message, $bestMatch, $bestScore, $secondBestScore, $language);
    }

    /* ================= OVERALL ATTENDANCE ================= */

    public static function getAttendance($student_id, $language = "en") {
        global $conn;

        $stmt = $conn->prepare("
            SELECT AVG(percentage) AS overall_percentage
            FROM attendance
            WHERE student_id = ?
        ");

        if (!$stmt) {
            if (self::isHindi($language)) {
                return "अटेंडेंस की जानकारी लेते समय सिस्टम त्रुटि हुई।";
            }
            return self::isKannada($language) ? "Attendance ಮಾಹಿತಿ ತರುತ್ತಿರುವಾಗ ಸಿಸ್ಟಮ್ ದೋಷ ಉಂಟಾಯಿತು." : "System error while fetching attendance.";
        }

        $stmt->bind_param("i", $student_id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$result || !$result['overall_percentage']) {
            if (self::isHindi($language)) {
                return "अटेंडेंस डेटा नहीं मिला।";
            }
            return self::isKannada($language) ? "Attendance ಮಾಹಿತಿ ಸಿಗಲಿಲ್ಲ." : "Attendance data not found.";
        }

        $overall = round($result['overall_percentage'], 2);

        if (self::isHindi($language)) {
            return "आपकी कुल उपस्थिति $overall प्रतिशत है।";
        }

        return self::isKannada($language)
            ? "ನಿಮ್ಮ overall attendance $overall ಪ್ರತಿಶತವಾಗಿದೆ."
            : "Your overall attendance is $overall percent.";
    }

    private static function localizeCertificateStatus($status, $language = "en") {
        $normalizedStatus = strtolower(trim((string) $status));
        $normalizedLanguage = self::normalizeLanguage($language);

        if ($normalizedLanguage === "hi") {
            $map = [
                "available" => "उपलब्ध",
                "pending" => "लंबित",
                "unavailable" => "उपलब्ध नहीं"
            ];

            return $map[$normalizedStatus] ?? $status;
        }

        if ($normalizedLanguage === "kn") {
            $map = [
                "available" => "ಲಭ್ಯ",
                "pending" => "ಬಾಕಿ",
                "unavailable" => "ಲಭ್ಯವಿಲ್ಲ"
            ];

            return $map[$normalizedStatus] ?? $status;
        }

        return $status;
    }

    // ── certificate helpers ───────────────────────────────────────────────────

    private static function certIsDownloadQuery(string $text): bool {
        return (bool) preg_match('/\b(download|how to get|get certificate|certificate kaise|certificate download|torisu|download maduvage)\b/i', $text);
    }

    private static function certIsCountQuery(string $text): bool {
        return (bool) preg_match('/\b(how many|count|kitne|eshtu|yeshtu|total certificate)\b/i', $text);
    }

    private static function certIsGradeQuery(string $text): bool {
        return (bool) preg_match('/\b(grade|marks?|score|what grade|certificate grade|grade bolo|grade torisu|grade hegide)\b/i', $text);
    }

    private static function certExtractSemFilter(string $text): ?int {
        if (preg_match('/\b(?:sem(?:ester)?\.?\s*|semester\s*)(\d)\b/i', $text, $m)) return (int) $m[1];
        if (preg_match('/\b(\d)(?:st|nd|rd|th)\s+sem(?:ester)?\b/i', $text, $m)) return (int) $m[1];
        return null;
    }

    private static function certExtractYearFilter(string $text): ?string {
        if (preg_match('/\b(20\d{2}[-–]\d{2})\b/', $text, $m)) return $m[1];
        if (preg_match('/\b(20\d{2})\b/', $text, $m)) {
            $y = (int) $m[1]; return "{$y}-" . substr($y + 1, 2);
        }
        return null;
    }

    private static function certExtractSeasonFilter(string $text): ?string {
        if (preg_match('/\b(odd|jan(?:uary)?)\b/i', $text)) return "ODD";
        if (preg_match('/\b(even|june?|july)\b/i', $text)) return "EVEN";
        return null;
    }

    private static function certApplyFilters(array $records, ?int $sem, ?string $year, ?string $season): array {
        return array_values(array_filter($records, function ($r) use ($sem, $year, $season) {
            if ($sem !== null && (int)($r["sem"] ?? 0) !== $sem) return false;
            if ($year !== null && ($r["academic_year"] ?? "") !== $year) return false;
            if ($season !== null && strtoupper($r["season"] ?? "") !== $season) return false;
            return true;
        }));
    }

    private static function certBuildListReply(array $records, string $language, string $context = ""): string {
        $count = count($records);
        if ($count === 0) {
            return self::isKannada($language)
                ? "ಆ filter ಗೆ ಯಾವುದೇ certificate ಸಿಗಲಿಲ್ಲ."
                : (self::isHindi($language) ? "उस filter के लिए कोई certificate नहीं मिला।" : "No certificates found for that filter.");
        }
        $lines = [];
        foreach ($records as $r) {
            $subj = $r["subject"] ?? "Unknown";
            $grade = $r["grade"] ?? "";
            $yr = $r["academic_year"] ?? "";
            $sn = $r["season"] ?? "";
            $sem = $r["sem"] ?? "";
            $date = $r["date"] ?? "";
            $line = $subj;
            if ($grade) $line .= " — Grade: {$grade}";
            if ($yr || $sn || $sem) $line .= " ({$yr} {$sn} Sem{$sem})";
            if ($date) $line .= " issued {$date}";
            $lines[] = $line;
        }
        $list = implode("; ", $lines);
        if (self::isKannada($language)) {
            return ($context ? "{$context}: " : "") . "ಒಟ್ಟು {$count} certificate: {$list}.";
        }
        if (self::isHindi($language)) {
            return ($context ? "{$context}: " : "") . "कुल {$count} certificate: {$list}.";
        }
        return ($context ? "{$context}: " : "") . "{$count} certificate" . ($count > 1 ? "s" : "") . ": {$list}.";
    }

    // ── main method ───────────────────────────────────────────────────────────

    public static function getCertificateStatus($student_id, $message = "", $language = "en") {
        $result = CertificateService::fetchCertificates();
        $records = [];

        if (($result["status"] ?? "error") !== "success") {
            $errorMessage = $result["message"] ?? "Unable to fetch certificate information right now.";
            $normalizedError = strtolower(trim((string) $errorMessage));
            $hasLiveSessionIssue =
                strpos($normalizedError, "cookie") !== false ||
                strpos($normalizedError, "session") !== false ||
                strpos($normalizedError, "login") !== false ||
                strpos($normalizedError, "authenticated") !== false;

            if ($hasLiveSessionIssue) {
                $records = CertificateService::getFallbackCertificates();
                if (empty($records)) {
                    if (self::isKannada($language)) return "ERP session active ಇಲ್ಲ, ಆದ್ದರಿಂದ live certificate list ತೋರಿಸಲು ಸಾಧ್ಯವಿಲ್ಲ. ERP ನಲ್ಲಿ Competency Certificate page ತೆರೆದು ಮತ್ತೆ ಪ್ರಯತ್ನಿಸಿ.";
                    if (self::isHindi($language)) return "ERP session active नहीं है इसलिए live certificate list नहीं दिखा सकता। कृपया ERP में Competency Certificate page खोलकर फिर से पूछिए।";
                    return "Your ERP session isn't active, so I can't fetch the live certificate list. Please open the Competency Certificate page in ERP and try again.";
                }
            } else {
                return self::isKannada($language)
                    ? "Certificate ಮಾಹಿತಿ ಈಗ ಸಿಗುತ್ತಿಲ್ಲ."
                    : (self::isHindi($language) ? "अभी certificate जानकारी नहीं मिल रही।" : "I could not fetch certificate information right now.");
            }
        } else {
            $records = $result["records"] ?? [];
        }

        if (empty($records)) {
            return self::isKannada($language)
                ? "ಈಗ ಯಾವುದೇ competency certificate ಸಿಗಲಿಲ್ಲ."
                : (self::isHindi($language) ? "अभी कोई competency certificate नहीं मिला।" : "I could not find any competency certificates right now.");
        }

        $text = strtolower($message);

        // ── Download guidance ─────────────────────────────────────────────────
        if (self::certIsDownloadQuery($text)) {
            if (self::isKannada($language)) return "ERP ತೆರೆದು Competency Certificate page ಗೆ ಹೋಗಿ. ನಿಮ್ಮ certificates ಪಟ್ಟಿಯಲ್ಲಿ CERTIFICATE button ಕ್ಲಿಕ್ ಮಾಡಿದರೆ PDF ಡೌನ್‌ಲೋಡ್ ಆಗುತ್ತದೆ.";
            if (self::isHindi($language)) return "ERP में Competency Certificate page खोलिए। अपनी certificate list में CERTIFICATE button click करने पर PDF download हो जाएगा।";
            return "Open the ERP Competency Certificate page. In your certificate list, click the CERTIFICATE button next to the subject to download the PDF.";
        }

        // ── Extract filters from the query ────────────────────────────────────
        $semFilter    = self::certExtractSemFilter($text);
        $yearFilter   = self::certExtractYearFilter($text);
        $seasonFilter = self::certExtractSeasonFilter($text);
        $filtered     = self::certApplyFilters($records, $semFilter, $yearFilter, $seasonFilter);
        $pool         = (empty($filtered) && $semFilter === null && $yearFilter === null && $seasonFilter === null) ? $records : $filtered;

        // ── Subject-specific grade query ──────────────────────────────────────
        $subjectMatches = CertificateService::matchCertificatesBySubject($pool, $message);
        if (!empty($subjectMatches)) {
            $record  = $subjectMatches[0];
            $subject = $record["subject"] ?? "this subject";
            $code    = $record["code"] ?? "";
            $grade   = $record["grade"] ?? "N/A";
            $date    = $record["date"] ?? "";
            $yr      = $record["academic_year"] ?? "";
            $sn      = $record["season"] ?? "";
            $sem     = $record["sem"] ?? "";

            if (self::certIsGradeQuery($text)) {
                if (self::isKannada($language)) return "{$subject} certificate ನ grade {$grade}. ({$yr} {$sn} Sem{$sem}, {$date})";
                if (self::isHindi($language)) return "{$subject} certificate में grade {$grade} है। ({$yr} {$sn} Sem{$sem}, {$date})";
                return "Your grade in {$subject} is {$grade} ({$yr} {$sn} Sem{$sem}, issued {$date}).";
            }

            // General subject info
            $codeStr = $code ? " ({$code})" : "";
            if (self::isKannada($language)) {
                $reply = "{$subject}{$codeStr} certificate — Grade: {$grade}, {$yr} {$sn} Sem{$sem}.";
                if ($date) $reply .= " Date: {$date}.";
                $reply .= " Download ಮಾಡಲು ERP Competency Certificate page ನಲ್ಲಿ CERTIFICATE button ಕ್ಲಿಕ್ ಮಾಡಿ.";
                return $reply;
            }
            if (self::isHindi($language)) {
                $reply = "{$subject}{$codeStr} certificate — Grade: {$grade}, {$yr} {$sn} Sem{$sem}.";
                if ($date) $reply .= " Date: {$date}.";
                $reply .= " Download के लिए ERP Competency Certificate page में CERTIFICATE button click करें।";
                return $reply;
            }
            $reply = "{$subject}{$codeStr} — Grade: {$grade}, {$yr} {$sn} Sem{$sem}";
            if ($date) $reply .= ", issued {$date}";
            $reply .= ". To download, click the CERTIFICATE button on the ERP Competency Certificate page.";
            return $reply;
        }

        // ── Grade query without specific subject ──────────────────────────────
        if (self::certIsGradeQuery($text)) {
            $lines = [];
            foreach ($pool as $r) {
                $subj  = $r["subject"] ?? "Unknown";
                $grade = $r["grade"] ?? "N/A";
                $sem   = $r["sem"] ?? "";
                $lines[] = "{$subj}: {$grade} (Sem{$sem})";
            }
            $list = implode("; ", $lines);
            if (self::isKannada($language)) return "ನಿಮ್ಮ certificate grades: {$list}.";
            if (self::isHindi($language)) return "आपके certificate grades: {$list}.";
            return "Your certificate grades: {$list}.";
        }

        // ── Count query ───────────────────────────────────────────────────────
        if (self::certIsCountQuery($text)) {
            $total = count($records);
            $filteredCount = count($pool);
            $hasFilter = $semFilter !== null || $yearFilter !== null || $seasonFilter !== null;
            if ($hasFilter) {
                if (self::isKannada($language)) return "ಆ filter ಗೆ {$filteredCount} certificate ಸಿಕ್ಕಿದೆ (ಒಟ್ಟು {$total} ಇದೆ).";
                if (self::isHindi($language)) return "उस filter के लिए {$filteredCount} certificate मिले (कुल {$total} हैं)।";
                return "You have {$filteredCount} certificate" . ($filteredCount !== 1 ? "s" : "") . " matching that filter (total: {$total}).";
            }
            if (self::isKannada($language)) return "ನಿಮಗೆ ಒಟ್ಟು {$total} competency certificate ಇದೆ.";
            if (self::isHindi($language)) return "आपके पास कुल {$total} competency certificate हैं।";
            return "You have {$total} competency certificate" . ($total !== 1 ? "s" : "") . " in total.";
        }

        // ── Filtered list (semester / year / season) ──────────────────────────
        $hasFilter = $semFilter !== null || $yearFilter !== null || $seasonFilter !== null;
        if ($hasFilter) {
            $ctxParts = [];
            if ($semFilter) $ctxParts[] = "Sem{$semFilter}";
            if ($yearFilter) $ctxParts[] = $yearFilter;
            if ($seasonFilter) $ctxParts[] = $seasonFilter;
            $ctx = implode(" ", $ctxParts);
            return self::certBuildListReply($pool, $language, $ctx);
        }

        // ── Full list / default ───────────────────────────────────────────────
        return self::certBuildListReply($records, $language);
    }
}



