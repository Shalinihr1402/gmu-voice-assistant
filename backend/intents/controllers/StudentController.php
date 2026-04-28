<?php

require_once __DIR__ . "/../../config/db.php";

class StudentController {
    private static $courseAliases = [
        "dbms" => "database management systems",
        "dbms lab" => "dbms laboratory",
        "ಡಿಬಿಎಮ್ಎಸ್" => "database management systems",
        "ಡಿಬಿಎಂಎಸ್" => "database management systems",
        "ಡೇಟಾಬೇಸ್ ಮ್ಯಾನೆಜ್ಮೆಂಟ್ ಸಿಸ್ಟಮ್ಸ್" => "database management systems",
        "ಡಿಬಿಎಮ್ಎಸ್ ಲ್ಯಾಬ್" => "dbms laboratory",
        "ai" => "artificial intelligence",
        "artificial intelligence" => "artificial intelligence",
        "ಎಐ" => "artificial intelligence",
        "ಆರ್ಟಿಫಿಷಿಯಲ್ ಇಂಟೆಲಿಜೆನ್ಸ್" => "artificial intelligence",
        "os" => "operating systems",
        "operating systems" => "operating systems",
        "ಓಎಸ್" => "operating systems",
        "ಆಪರೇಟಿಂಗ್ ಸಿಸ್ಟಮ್ಸ್" => "operating systems",
        "cn" => "computer networks",
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
        return in_array($language, ["kn", "kannada", "kn-in"], true) ? "kn" : "en";
    }

    private static function isKannada($language) {
        return self::normalizeLanguage($language) === "kn";
    }

    private static function normalizeLookupText($text) {
        $text = strtolower(trim((string) $text));
        $text = preg_replace('/[^a-z0-9]+/', ' ', $text);
        $text = preg_replace('/\s+/', ' ', $text);
        return trim($text);
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
        $rawText = strtolower(trim((string) $text));
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
        $cleaned = preg_replace('/\s+/', ' ', (string) $cleaned);
        return trim((string) $cleaned);
    }

    private static function applyCourseAliases($text) {
        $normalized = self::normalizeLookupText($text);

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

        if ($normalizedQuery === "") {
            return 0;
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

    private static function extractExamType($message) {
        $message = strtolower($message);

        if (strpos($message, "supplementary") !== false || strpos($message, "supply") !== false) {
            return "SUPPLEMENTARY";
        }

        if (strpos($message, "see") !== false) {
            return "SEE";
        }

        if (strpos($message, "cie") !== false || strpos($message, "internal") !== false) {
            return "CIE";
        }

        return null;
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

        return "In semester {$semester}, your SGPA is {$sgpa}. You have earned {$totalCredits} credits. {$performanceLine}";
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

        $grouped = [];
        foreach ($backlogs as $backlog) {
            $semester = $backlog["semester"];
            if (!isset($grouped[$semester])) {
                $grouped[$semester] = [];
            }
            $grouped[$semester][] = $backlog["course_title"];
        }

        ksort($grouped);

        $parts = [];
        foreach ($grouped as $semester => $subjects) {
            $parts[] = "semester {$semester}: " . implode(", ", array_slice($subjects, 0, 4));
        }

        return self::isKannada($language)
            ? "ಈಗ ನಿಮಗೆ " . count($backlogs) . " backlog" . (count($backlogs) > 1 ? "ಗಳು ಇವೆ." : " ಇದೆ.") . " ಉಳಿದಿರುವ ವಿಷಯಗಳು: " . implode("; ", $parts) . "."
            : "You currently have " . count($backlogs) . " backlog" . (count($backlogs) > 1 ? "s" : "") . ". Uncleared subjects are " . implode("; ", $parts) . ".";
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
        foreach ($courses as $course) {
            $courseTitle = strtolower($course["course_title"]);
            $courseCode = strtolower($course["course_code"]);

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
            $score = self::scoreCourseMatch($message, $row['course_title'], $row['course_code']);

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestMatch = $row;
            }
        }

        $stmt->close();

        if ($bestMatch && $bestScore >= 60) {
            return self::isKannada($language)
                ? $bestMatch['course_title'] . " ವಿಷಯದ course code " . $bestMatch['course_code'] . "."
                : "The course code for " . $bestMatch['course_title'] . " is " . $bestMatch['course_code'] . ".";
        }

        return self::isKannada($language) ? "ಆ course code ಸಿಗಲಿಲ್ಲ. ದಯವಿಟ್ಟು subject ಹೆಸರು ಇನ್ನಷ್ಟು ಸ್ಪಷ್ಟವಾಗಿ ಹೇಳಿ." : "I could not find that course code. Please say the subject name more clearly.";
    }

    /* ================= SUBJECT-WISE ATTENDANCE ================= */

    public static function getSubjectAttendance($student_id, $message, $language = "en") {
        global $conn;

        $normalizedMessage = self::normalizeLookupText($message);
        $genericAttendancePhrases = [
            "individual subject",
            "subject wise",
            "subject wise attendance",
            "attendance in individual subject",
            "attendance in subject",
            "attendance of subject",
            "subject attendance"
        ];

        $stmt = $conn->prepare("
            SELECT c.course_title, 
                   c.course_code,
                   a.total_classes, 
                   a.attended_classes, 
                   a.percentage
            FROM attendance a
            JOIN courses c ON a.course_id = c.course_id
            WHERE a.student_id = ?
        ");

        if (!$stmt) {
            return self::isKannada($language) ? "Attendance ಮಾಹಿತಿ ತರುತ್ತಿರುವಾಗ ಸಿಸ್ಟಮ್ ದೋಷ ಉಂಟಾಯಿತು." : "System error while fetching attendance.";
        }

        $stmt->bind_param("i", $student_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $availableSubjects = [];
        $bestMatch = null;
        $bestScore = 0;

        while ($row = $result->fetch_assoc()) {
            $availableSubjects[] = $row['course_title'];
            $score = self::scoreCourseMatch($message, $row['course_title'], $row['course_code'] ?? "");

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestMatch = $row;
            }
        }

        $stmt->close();

        if ($bestMatch && $bestScore >= 60) {
            $percentage = round($bestMatch['percentage'], 2);

            $response = self::isKannada($language)
                        ? $bestMatch['course_title'] . " ವಿಷಯದಲ್ಲಿ ನಿಮ್ಮ attendance $percentage ಪ್ರತಿಶತ. ನೀವು " .
                          $bestMatch['total_classes'] . " classesಗಳಲ್ಲಿ " . $bestMatch['attended_classes'] . " classes attend ಮಾಡಿದ್ದೀರಿ."
                        : "Your attendance in " . $bestMatch['course_title'] .
                          " is $percentage percent. You attended " .
                          $bestMatch['attended_classes'] . " out of " .
                          $bestMatch['total_classes'] . " classes.";

            if ($percentage < 75) {
                $response .= self::isKannada($language)
                    ? " ಎಚ್ಚರಿಕೆ: ನಿಮ್ಮ attendance 75 ಪ್ರತಿಶತಕ್ಕಿಂತ ಕಡಿಮೆ ಇದೆ."
                    : " Warning: Your attendance is below the required 75 percent.";
            }

            return $response;
        }

        foreach ($genericAttendancePhrases as $phrase) {
            if (strpos($normalizedMessage, $phrase) !== false) {
                if (!empty($availableSubjects)) {
                    $preview = implode(", ", array_slice($availableSubjects, 0, 4));
                    return self::isKannada($language)
                        ? "ದಯವಿಟ್ಟು subject ಹೆಸರು ಹೇಳಿ. ಉದಾಹರಣೆಗೆ {$preview} ಬಗ್ಗೆ ಕೇಳಬಹುದು."
                        : "Please tell me the subject name. For example, you can ask about {$preview}.";
                }

                return self::isKannada($language) ? "ಯಾವ subject‌ನ attendance ಬೇಕೋ ಅದರ ಹೆಸರು ಹೇಳಿ." : "Please tell me the subject name for which you want attendance.";
            }
        }

        return self::isKannada($language) ? "ಆ subject‌ನ attendance ಸಿಗಲಿಲ್ಲ." : "I could not find attendance for that subject.";
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
            return self::isKannada($language) ? "Attendance ಮಾಹಿತಿ ತರುತ್ತಿರುವಾಗ ಸಿಸ್ಟಮ್ ದೋಷ ಉಂಟಾಯಿತು." : "System error while fetching attendance.";
        }

        $stmt->bind_param("i", $student_id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$result || !$result['overall_percentage']) {
            return self::isKannada($language) ? "Attendance ಮಾಹಿತಿ ಸಿಗಲಿಲ್ಲ." : "Attendance data not found.";
        }

        $overall = round($result['overall_percentage'], 2);

        return self::isKannada($language)
            ? "ನಿಮ್ಮ overall attendance $overall ಪ್ರತಿಶತವಾಗಿದೆ."
            : "Your overall attendance is $overall percent.";
    }
}
