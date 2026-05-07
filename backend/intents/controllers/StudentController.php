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

        return preg_replace(array_keys($replacements), array_values($replacements), $text);
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
                '/\x{0C86}\x{0CAA}\x{0CB0}\x{0CC7}\x{0C9F}\x{0CBF}\x{0C82}\x{0C97}\x{0CCD}\s*\x{0CB8}\x{0CBF}\x{0CB8}\x{0CCD}\x{0C9F}\x{0CAE}\x{0CCD}(?:\x{0CB8}\x{0CCD}|\x{0CB8}\x{0CCD}\x{0CB8}\x{0CCD})?/u'
            ],
            "computer networks" => [
                '/\bcn\b/u',
                '/\bc\s*n\b/u',
                '/\bcomputer networks?\b/u',
                '/\bcomputer net work(s)?\b/u',
                '/\x{0C95}\x{0C82}\x{0CAA}\x{0CCD}\x{0CAF}\x{0CC2}\x{0C9F}\x{0CB0}\x{0CCD}\s*\x{0CA8}\x{0CC6}\x{0C9F}\x{0CCD}(?:\x{0CB5}\x{0CB0}\x{0CCD}\x{0C95}\x{0CCD}|\x{0CB5}\x{0CB0}\x{0CCD}\x{0C95}\x{0CCD}\x{0CB8}\x{0CCD}|\x{0CB5}\x{0CB0}\x{0CCD}\x{0C95}\x{0CCD}\x{0CB8}\x{0CCD})/u'
            ],
            "artificial intelligence" => [
                '/\bai\b/u',
                '/\ba\s*i\b/u',
                '/\bartificial intelligence\b/u',
                '/\x{0C86}\x{0CB0}\x{0CCD}\x{0C9F}\x{0CBF}\x{0CAB}\x{0CBF}\x{0CB7}\x{0CBF}\x{0CAF}\x{0CB2}\x{0CCD}\s*\x{0C87}\x{0C82}\x{0C9F}\x{0CC6}\x{0CB2}\x{0CBF}\x{0C9C}\x{0CC6}\x{0CA8}\x{0CCD}\x{0CB8}\x{0CCD}/u'
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
            "ai" => "artificial intelligence"
        ];

        if ($normalizedQuery === "") {
            return 0;
        }

        if (isset($directAliases[$normalizedQuery]) && $normalizedTitle === $directAliases[$normalizedQuery]) {
            return 100;
        }

        if ($normalizedQuery === $normalizedCode || $normalizedQuery === $shortName) {
            return 100;
        }

        if (strpos($normalizedTitle, $normalizedQuery) !== false || strpos($normalizedQuery, $normalizedTitle) !== false) {
            return 95;
        }

        $queryWords = array_values(array_filter(explode(' ', $normalizedQuery)));
        $titleWords = array_values(array_filter(explode(' ', $normalizedTitle)));

        $matchedWords = 0;
        foreach ($queryWords as $queryWord) {
            foreach ($titleWords as $titleWord) {
                if (
                    $queryWord === $titleWord ||
                    strpos($titleWord, $queryWord) !== false ||
                    strpos($queryWord, $titleWord) !== false ||
                    levenshtein($queryWord, $titleWord) <= 2
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
        if ($titleDistance <= 3) {
            return 68;
        }

        return 0;
    }

    private static function extractKnownAttendanceSubject($message) {
        $normalizedMessage = self::normalizeLookupText($message);

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
                "ಆಪರೇಟಿಂಗ್ ಸಿಸ್ಟಮ್ಸ್",
                "ಆಪರೇಟಿಂಗ್ ಸಿಸ್ಟಮ್"
            ],
            "computer networks" => [
                "computer networks",
                "computer network",
                "computer net work",
                "cn",
                "c n",
                "ಕಂಪ್ಯೂಟರ್ ನೆಟ್ವರ್ಕ್ಸ್",
                "ಕಂಪ್ಯೂಟರ್ ನೆಟ್ವರ್ಕ್",
                "ಕಂಪ್ಯೂಟರ್ ನೆಟ್ ವರ್ಕ್ಸ್",
                "ಕಂಪ್ಯೂಟರ್ ನೆಟ್ ವರ್ಕ್"
            ],
            "artificial intelligence" => [
                "artificial intelligence",
                "artificial intelligent",
                "ai",
                "a i",
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
        $requestedSemester = self::extractRequestedSemester($message);
        $semester = $requestedSemester ?: self::getLatestSemester($student_id);

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
            if (self::isKannada($language)) {
                return "ನಿಮ್ಮ {$semester}ನೇ ಸೆಮಿಸ್ಟರ್ SGPA {$sgpa}. ನೀವು {$totalCredits} ಕ್ರೆಡಿಟ್ ಗಳಿಸಿದ್ದೀರಿ. ನಿಮ್ಮ ಫಲಿತಾಂಶ ಫೇಲ್ ಆಗಿದೆ, ಏಕೆಂದರೆ ಇನ್ನೂ " . count($backlogs) . " ಬ್ಯಾಕ್‌ಲಾಗ್ ಇದೆ. ಒಳಗೊಂಡ ವಿಷಯಗಳು: " . implode(", ", array_slice($backlogs, 0, 3)) . ".";
            }

            return "In semester {$semester}, your SGPA is {$sgpa}. You have earned {$totalCredits} credits. Your result status is fail because you still have " . count($backlogs) . " backlog" . (count($backlogs) > 1 ? "s" : "") . ", including " . implode(", ", array_slice($backlogs, 0, 3)) . ".";
        }

        if ($sgpa >= 9) {
            $performanceLine = "You passed with outstanding performance.";
        } elseif ($sgpa >= 8) {
            $performanceLine = "You passed with excellent performance.";
        } elseif ($sgpa >= 7) {
            $performanceLine = "You passed with good performance.";
        } elseif ($sgpa >= 6) {
            $performanceLine = "You passed with satisfactory performance.";
        } else {
            $performanceLine = "You passed, but you should improve next semester.";
        }

        if (self::isKannada($language)) {
            $kannadaPerformanceLine = "ನೀವು  ಪಾಸ್ ಆಗಿದ್ದೀರಿ.";
            if ($sgpa >= 9 || $sgpa >= 8) {
                $kannadaPerformanceLine = "ನೀವು ಅತ್ಯುತ್ತಮ ಪ್ರದರ್ಶನದೊಂದಿಗೆ ಪಾಸ್ ಆಗಿದ್ದೀರಿ.";
            } elseif ($sgpa >= 7) {
                $kannadaPerformanceLine = "ನೀವು ಉತ್ತಮ ಪ್ರದರ್ಶನದೊಂದಿಗೆ ಪಾಸ್ ಆಗಿದ್ದೀರಿ.";
            } elseif ($sgpa >= 6) {
                $kannadaPerformanceLine = "ನೀವು ತೃಪ್ತಿಕರ ಪ್ರದರ್ಶನದೊಂದಿಗೆ ಪಾಸ್ ಆಗಿದ್ದೀರಿ.";
            } else {
                $kannadaPerformanceLine = "ನೀವು ಪಾಸ್ ಆಗಿದ್ದೀರಿ, ಆದರೆ ಮುಂದಿನ ಸೆಮಿಸ್ಟರ್‌ನಲ್ಲಿ ಇನ್ನಷ್ಟು ಉತ್ತಮಪಡಿಸಬೇಕು.";
            }

            return "ನಿಮ್ಮ {$semester}ನೇ ಸೆಮಿಸ್ಟರ್ SGPA {$sgpa}. ನೀವು {$totalCredits} ಕ್ರೆಡಿಟ್ ಗಳಿಸಿದ್ದೀರಿ. {$kannadaPerformanceLine}";
        }

        return "In semester {$semester}, your SGPA is {$sgpa}. You have earned {$totalCredits} credits. {$performanceLine} You do not have any backlog in this semester.";
    }

    public static function getCGPA($student_id, $language = "en") {
        global $conn;

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
            $reply = "ನಿಮ್ಮ current CGPA {$cgpa}. ಇದು {$semesterCount} ಸೆಮಿಸ್ಟರ್ ಆಧಾರದಲ್ಲಿ ಲೆಕ್ಕಿಸಲಾಗಿದೆ.";
            if ($backlogCount > 0) {
                $reply .= " ಈಗ ನಿಮಗೆ {$backlogCount} uncleared backlog" . ($backlogCount > 1 ? "ಗಳು ಇವೆ." : " ಇದೆ.");
            } else {
                $reply .= " ಈಗ ನಿಮಗೆ ಯಾವುದೇ backlog ಇಲ್ಲ.";
            }
            return $reply;
        }

        $reply = "Your current CGPA is {$cgpa}, calculated across {$semesterCount} semester" . ($semesterCount > 1 ? "s" : "") . ".";

        if ($backlogCount > 0) {
            $reply .= " You currently have {$backlogCount} uncleared backlog" . ($backlogCount > 1 ? "s" : "") . ".";
        } else {
            $reply .= " You do not have any current backlog.";
        }

        return $reply;
    }

    public static function getBacklogStatus($student_id, $message = "", $language = "en") {
        $requestedSemester = self::extractRequestedSemester($message);

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

        if ($requestedExamType) {
            $stmt = $conn->prepare("
                SELECT exam_type, semester, academic_year, status, status_message
                FROM hall_tickets
                WHERE student_id = ?
                AND exam_type = ?
                ORDER BY hall_ticket_id DESC
                LIMIT 1
            ");

            if (!$stmt) {
                return self::isKannada($language) ? "Hall ticket ಮಾಹಿತಿ ಪರಿಶೀಲಿಸುವಾಗ ಸಿಸ್ಟಮ್ ದೋಷ ಉಂಟಾಯಿತು." : "System error while checking hall ticket status.";
            }

            $stmt->bind_param("is", $student_id, $requestedExamType);
        } else {
            $stmt = $conn->prepare("
                SELECT exam_type, semester, academic_year, status, status_message
                FROM hall_tickets
                WHERE student_id = ?
                ORDER BY hall_ticket_id DESC
                LIMIT 1
            ");

            if (!$stmt) {
                return self::isKannada($language) ? "Hall ticket ಮಾಹಿತಿ ಪರಿಶೀಲಿಸುವಾಗ ಸಿಸ್ಟಮ್ ದೋಷ ಉಂಟಾಯಿತು." : "System error while checking hall ticket status.";
            }

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
                    ? "{$bestMatch["course_title"]} à²µà²¿à²·à²¯à²¦ course code {$bestMatch["course_code"]}."
                    : "The course code for {$bestMatch["course_title"]} is {$bestMatch["course_code"]}.";
            }

            $credits = rtrim(rtrim(number_format((float) $bestMatch["credits"], 1, ".", ""), "0"), ".");
            return self::isKannada($language)
                ? "{$bestMatch["course_title"]} à²µà²¿à²·à²¯à²¦ course code {$bestMatch["course_code"]}. à²‡à²¦à³ {$semester}à²¨à³‡ à²¸à³†à²®à²¿à²¸à³à²Ÿà²°à³â€Œà²¨ {$bestMatch["course_type"]} course à²†à²—à²¿à²¦à³à²¦à³ {$credits} credit" . ($credits === "1" ? "" : "s") . " à²¹à³Šà²‚à²¦à²¿à²¦à³†."
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
        $requestedSemester = self::extractRequestedSemester($message);
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

            if (self::isHindi($language)) {
                $response = $bestMatch['course_title'] . " में आपकी उपस्थिति $percentage प्रतिशत है। आपने "
                    . $bestMatch['total_classes'] . " कक्षाओं में से " . $bestMatch['attended_classes'] . " कक्षाओं में उपस्थिति दी है।";
            } else {
                $response = self::isKannada($language)
                            ? $bestMatch['course_title'] . " ವಿಷಯದಲ್ಲಿ ನಿಮ್ಮ attendance $percentage ಪ್ರತಿಶತ. ನೀವು " .
                              $bestMatch['total_classes'] . " classesಗಳಲ್ಲಿ " . $bestMatch['attended_classes'] . " classes attend ಮಾಡಿದ್ದೀರಿ."
                            : "Your attendance in " . $bestMatch['course_title'] .
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

    public static function getCertificateStatus($student_id, $message = "", $language = "en") {
        $result = CertificateService::fetchCertificates();
        $records = [];

        if (($result["status"] ?? "error") !== "success") {
            $errorMessage = $result["message"] ?? "Unable to fetch certificate information right now.";
            $normalizedError = strtolower(trim((string) $errorMessage));
            $requestedSubject = CertificateService::extractRequestedSubject($message);
            $hasLiveSessionIssue =
                strpos($normalizedError, "cookie") !== false ||
                strpos($normalizedError, "session") !== false ||
                strpos($normalizedError, "login") !== false ||
                strpos($normalizedError, "authenticated") !== false;

            if ($hasLiveSessionIssue) {
                $records = CertificateService::getFallbackCertificates();

                if (empty($records)) {
                    if (self::isKannada($language)) {
                        if ($requestedSubject !== "") {
                            return "{$requestedSubject} competency certificate ನ live status ಈಗ ಪರಿಶೀಲಿಸಲು ಆಗುತ್ತಿಲ್ಲ. ERP ನಲ್ಲಿ Competency Certificate page ತೆರೆದು login session ಸಕ್ರಿಯವಾಗಿರುವಾಗ ಮತ್ತೆ ಪ್ರಯತ್ನಿಸಿ.";
                        }

                        return "Competency certificate ವಿವರಗಳು ERP page ನಲ್ಲಿ student login ನಂತರ ಲಭ್ಯವಾಗುತ್ತವೆ. ನಿಮ್ಮ live certificate list ಈಗ ಸಂಪರ್ಕದಲ್ಲಿಲ್ಲ, ಆದ್ದರಿಂದ ದಯವಿಟ್ಟು ERP ನಲ್ಲಿ Competency Certificate page ತೆರೆದು ಮತ್ತೆ ಪ್ರಯತ್ನಿಸಿ.";
                    }

                    if (self::isHindi($language)) {
                        if ($requestedSubject !== "") {
                            return "मैं अभी {$requestedSubject} competency certificate की live स्थिति verify नहीं कर पा रहा हूं। कृपया ERP में Competency Certificate page खोलकर login session active होने के बाद फिर से पूछिए।";
                        }

                        return "Competency certificate की जानकारी student login के बाद ERP page पर उपलब्ध होती है। मैं अभी आपकी live certificate list तक नहीं पहुंच पा रहा हूं, इसलिए कृपया ERP में Competency Certificate page एक बार खोलकर फिर से पूछिए।";
                    }

                    if ($requestedSubject !== "") {
                        return "I can't verify the live competency certificate status for {$requestedSubject} right now. Please open the ERP Competency Certificate page and try again after your ERP session is active.";
                    }

                    return "Competency certificate details are available from the ERP Competency Certificate page after student login. I can't access your live certificate list right now, so please open that ERP page once and try again.";
                }
            } else {
                return self::isKannada($language)
                    ? "Certificate ಮಾಹಿತಿ ಈಗ ಸಿಗುತ್ತಿಲ್ಲ. {$errorMessage}"
                    : "I could not fetch certificate information right now.";
            }
        } else {
            $records = $result["records"] ?? [];
        }

        if (empty($records)) {
            return self::isKannada($language)
                ? "ಈಗ ಯಾವುದೇ competency certificate ಸಿಗಲಿಲ್ಲ."
                : (
                    self::isHindi($language)
                        ? "मुझे अभी कोई competency certificate नहीं मिला।"
                        : "I could not find any competency certificates right now."
                );
        }

        $subjectMatches = CertificateService::matchCertificatesBySubject($records, $message);
        if (!empty($subjectMatches)) {
            $record = $subjectMatches[0];
            $subject = $record["subject"] ?? "this subject";
            $code = $record["code"] ?? "";
            $status = self::localizeCertificateStatus($record["status"] ?? "available", $language);
            $date = $record["date"] ?? "";

            if (self::isKannada($language)) {
                $reply = "{$subject}";
                if ($code !== "") {
                    $reply .= " ({$code})";
                }
                $reply .= " certificate status {$status}.";
                if ($date !== "") {
                    $reply .= " Date {$date}.";
                }
                if (!empty($record["download_url"])) {
                    $reply .= " Download link ERP page ನಲ್ಲಿ ಲಭ್ಯವಿದೆ.";
                }
                return $reply;
            }

            $reply = self::isHindi($language)
                ? "{$subject} certificate की स्थिति {$status} है।"
                : "The certificate for {$subject}";
            if ($code !== "") {
                $reply = self::isHindi($language)
                    ? "{$subject} ({$code}) certificate की स्थिति {$status} है।"
                    : $reply . " ({$code})";
            }
            if ($date !== "") {
                $reply .= self::isHindi($language) ? " तारीख {$date} है।" : " Date: {$date}.";
            }
            if (!empty($record["download_url"])) {
                $reply .= self::isHindi($language)
                    ? " डाउनलोड लिंक ERP page पर उपलब्ध है।"
                    : " The download link is available on the ERP page.";
            }
            return $reply;
        }

        $totalCount = count($records);
        $availableRecords = array_values(array_filter($records, function ($record) {
            return ($record["status"] ?? "") === "available";
        }));
        $previewRecords = !empty($availableRecords) ? $availableRecords : $records;
        $previewSubjects = array_map(function ($record) {
            return $record["subject"] ?? "";
        }, array_slice($previewRecords, 0, 4));
        $previewSubjects = array_values(array_filter($previewSubjects, function ($value) {
            return trim((string) $value) !== "";
        }));
        $preview = implode(", ", $previewSubjects);

        if (self::isKannada($language)) {
            $reply = "ನಿಮಗೆ ಒಟ್ಟು {$totalCount} competency certificate ದಾಖಲೆಗಳು ಸಿಕ್ಕಿವೆ.";
            if (!empty($availableRecords)) {
                $reply .= " Download ಮಾಡಲು ಲಭ್ಯವಿರುವವು: " . count($availableRecords) . ".";
            }
            if ($preview !== "") {
                $reply .= " ಉದಾಹರಣೆಗೆ {$preview}.";
            }
            return $reply;
        }

        if (self::isHindi($language)) {
            $reply = "मुझे {$totalCount} competency certificate record मिले हैं।";
            if (!empty($availableRecords)) {
                $reply .= " इनमें से " . count($availableRecords) . " डाउनलोड के लिए उपलब्ध हैं।";
            }
            if ($preview !== "") {
                $reply .= " उपलब्ध certificate subjects हैं: {$preview}।";
            }
            return $reply;
        }

        $reply = "I found {$totalCount} competency certificate record";
        $reply .= $totalCount === 1 ? "" : "s";
        if (!empty($availableRecords)) {
            $reply .= ", with " . count($availableRecords) . " available to download";
        }
        $reply .= ".";
        if ($preview !== "") {
            $reply .= " Available certificate subjects: {$preview}.";
        }
        return $reply;
    }
}

