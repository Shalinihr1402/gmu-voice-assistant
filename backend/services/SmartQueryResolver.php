<?php

require_once __DIR__ . "/../intents/controllers/StudentController.php";

class SmartQueryResolver {
    private static $intentExamples = [
        "GET_USN" => [
            "my usn",
            "what is my usn",
            "registration number",
            "university number",
            "à¤¯à¥‚à¤à¤¸à¤à¤¨ à¤•à¥à¤¯à¤¾ à¤¹à¥ˆ",
            "à¤®à¥‡à¤°à¤¾ usn",
            "à²¨à²¨à³à²¨ usn",
            "à²¨à²¨à³à²¨ à²¯à³à²Žà²¸à³à²Žà²¨à³"
        ],
        "GET_PROFILE_SUMMARY" => [
            "who am i",
            "my profile",
            "what semester am i in",
            "what is my department",
            "student details",
            "à¤®à¥‡à¤°à¥€ à¤ªà¥à¤°à¥‹à¤«à¤¾à¤‡à¤²",
            "à¤®à¥ˆà¤‚ à¤•à¥Œà¤¨ à¤¹à¥‚à¤",
            "à¤®à¥‡à¤°à¤¾ à¤µà¤¿à¤­à¤¾à¤—",
            "à²¨à²¨à³à²¨ profile",
            "à²¨à²¾à²¨à³ à²¯à²¾à²°à³",
            "à²¨à²¨à³à²¨ department"
        ],
        "GET_SGPA" => [
            "my sgpa",
            "dbms score",
            "subject score",
            "semester result",
            "semester gpa",
            "how did i do this semester",
            "sgpa for 5th semester",
            "à¤®à¥‡à¤°à¤¾ sgpa",
            "à¤¸à¥‡à¤®à¥‡à¤¸à¥à¤Ÿà¤° à¤°à¤¿à¤œà¤²à¥à¤Ÿ",
            "5th sem sgpa",
            "à²¨à²¨à³à²¨ sgpa",
            "semester result",
            "5th sem sgpa"
        ],
        "GET_CGPA" => [
            "my cgpa",
            "overall result",
            "overall gpa",
            "cumulative gpa",
            "à¤®à¥‡à¤°à¤¾ cgpa",
            "overall cgpa",
            "à²¨à²¨à³à²¨ cgpa",
            "overall gpa"
        ],
        "GET_BACKLOG_STATUS" => [
            "do i have backlogs",
            "have i failed",
            "failed subjects",
            "supplementary subjects",
            "am i pass or fail",
            "à¤•à¥à¤¯à¤¾ à¤®à¥‡à¤°à¥‡ backlog à¤¹à¥ˆà¤‚",
            "à¤®à¥ˆà¤‚ fail à¤¹à¥à¤† à¤•à¥à¤¯à¤¾",
            "backlog ideya",
            "à²¨à²¾à²¨à³ fail à²†à²—à²¿à²¦à³à²¦à³‡à²¨à²¾"
        ],
        "GET_FEES_BALANCE" => [
            "my fee balance",
            "how much fee is pending",
            "amount due",
            "pending fees",
            "fee status",
            "à¤®à¥‡à¤°à¥€ fees pending",
            "à¤•à¤¿à¤¤à¤¨à¥€ fees due à¤¹à¥ˆ",
            "à²¨à²¨à³à²¨ fee balance",
            "fees pending ideya",
            "eshtu due ide"
        ],
        "GET_FINAL_REGISTRATION_STATUS" => [
            "is my registration complete",
            "final registration status",
            "am i registered",
            "registration clear",
            "can i complete registration",
            "à¤®à¥‡à¤°à¤¾ registration complete à¤¹à¥ˆ à¤•à¥à¤¯à¤¾",
            "final registration status",
            "registration complete aagideya",
            "final registration"
        ],
        "GET_HALL_TICKET_STATUS" => [
            "is my hall ticket generated",
            "hall ticket status",
            "can i download hall ticket",
            "exam ticket",
            "hallticket",
            "hall ticket aaya kya",
            "hall ticket status kya hai",
            "hall ticket bandideya",
            "hall ticket ideya"
        ],
        "GET_CERTIFICATE_STATUS" => [
            "what certificates are available",
            "certificate status",
            "download certificate",
            "my certificates",
            "certificate list",
            "certificate available hai kya",
            "certificate dikhao",
            "certificate ideya",
            "certificate list torisu"
        ],
        "GET_COURSE_DETAILS" => [
            "what subjects do i have",
            "my courses",
            "registered subjects",
            "subject list",
            "this semester subjects",
            "à¤®à¥‡à¤°à¥‡ subjects à¤•à¥à¤¯à¤¾ à¤¹à¥ˆà¤‚",
            "course list",
            "à²¨à²¨à³à²¨ subjects",
            "course list",
            "à²ˆ semester subjects"
        ],
        "GET_ATTENDANCE" => [
            "my overall attendance",
            "attendance percentage",
            "attendance status",
            "am i short of attendance",
            "à¤®à¥‡à¤°à¥€ attendance à¤•à¥à¤¯à¤¾ à¤¹à¥ˆ",
            "overall attendance",
            "à²¨à²¨à³à²¨ attendance",
            "overall attendance eshtu"
        ],
        "GET_SUBJECT_ATTENDANCE" => [
            "attendance in dbms",
            "dbms attendance",
            "what about dbms",
            "attendance for os",
            "subject attendance",
            "dbms ki attendance",
            "os attendance",
            "dbms alli",
            "os alli attendance",
            "that subject attendance"
        ],
        "GET_COURSE_CODE" => [
            "course code for dbms",
            "subject code",
            "code of os",
            "what is the code for ai",
            "dbms code",
            "dbms ka code",
            "subject code kya hai",
            "dbms code yenu",
            "course code heli"
        ],
        "GET_EXAM_READINESS" => [
            "am i clear for exams",
            "can i write exams",
            "am i eligible for exam",
            "exam clear",
            "ready for exam",
            "à¤•à¥à¤¯à¤¾ à¤®à¥ˆà¤‚ exam à¤•à¥‡ à¤²à¤¿à¤ clear à¤¹à¥‚à¤",
            "à¤•à¥à¤¯à¤¾ à¤®à¥ˆà¤‚ exam à¤²à¤¿à¤– à¤¸à¤•à¤¤à¤¾ à¤¹à¥‚à¤",
            "exam ge clear iddina",
            "nanu exam à²¬à²°à³†à²¯à²¬à²¹à³à²¦à²¾"
        ]
    ];

    private static function normalize($message) {
        $message = mb_strtolower(trim((string) $message), "UTF-8");

        $replacements = [
            '/\b(resértelo|resertelo|rezertelo|resultu|rijalt|resalt)\b/u' => ' result ',
            '/अटेंडेंस|उपस्थिति/u' => ' attendance ',
            '/रिजल्ट|परिणाम/u' => ' result ',
            '/फीस|शुल्क|बाकाया/u' => ' fees due ',
            '/रजिस्ट्रेशन|पंजीकरण/u' => ' registration ',
            '/हॉल\s*टिकट|प्रवेश\s*पत्र/u' => ' hall ticket ',
            '/प्रोफाइल|प्रोफ़ाइल/u' => ' profile ',
            '/विभाग|डिपार्टमेंट/u' => ' department ',
            '/सेमेस्टर/u' => ' semester ',
            '/सर्टिफिकेट|प्रमाणपत्र/u' => ' certificate ',
            '/कंसल्टेंसी|कन्सल्टेंसी|कम्पीटेंसी|कंपीटेंसी|कम्पिटेंसी|कंपिटेंसी/u' => ' competency ',
            '/रिपोर्ट/u' => ' certificate ',
            '/अंग्रेजी/u' => ' english ',
            '/हिंदी|हिन्दी/u' => ' hindi ',
            '/ಅಟೆಂಡೆನ್ಸ್|ಹಾಜರಿ|ಹಾಜರಾತಿ/u' => ' attendance ',
            '/ರಿಸಲ್ಟ್|ಫಲಿತಾಂಶ/u' => ' result ',
            '/ಫೀಸ್|ಶುಲ್ಕ|ಬಾಕಿ/u' => ' fees due ',
            '/ರಿಜಿಸ್ಟ್ರೇಶನ್|ನೋಂದಣಿ/u' => ' registration ',
            '/ಹಾಲ್\s*ಟಿಕೆಟ್/u' => ' hall ticket ',
            '/ಪ್ರೊಫೈಲ್/u' => ' profile ',
            '/ವಿಭಾಗ/u' => ' department ',
            '/ಸೆಮಿಸ್ಟರ್/u' => ' semester ',
            '/ಸರ್ಟಿಫಿಕೆಟ್|ಪ್ರಮಾಣಪತ್ರ/u' => ' certificate ',
            '/ಕನ್ನಡದಲ್ಲಿ|ಕನ್ನಡ/u' => ' kannada ',
            '/ಇಂಗ್ಲಿಷ್/u' => ' english ',
            '/dbms alli/u' => ' dbms ',
            '/os alli/u' => ' os ',
            '/cn alli/u' => ' cn ',
            '/ai alli/u' => ' ai ',
            '/mein|me\b/u' => ' in ',
            '/dalli/u' => ' in '
        ];

        $message = preg_replace(array_keys($replacements), array_values($replacements), $message);
        $message = preg_replace('/\b(usn|u\s*s\s*n|yu\s*es\s*en|uesn|yuesen|yusn|upsn|usm|usf|u\s*s\s*m|u\s*s\s*f)\b/u', ' usn ', (string) $message);
        $message = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', (string) $message);
        $message = preg_replace('/\s+/u', ' ', (string) $message);
        return trim((string) $message);
    }

    private static function tokenize($message) {
        $normalized = self::normalize($message);
        if ($normalized === "") {
            return [];
        }

        return array_values(array_filter(explode(' ', $normalized), function ($token) {
            return trim((string) $token) !== '';
        }));
    }

    private static function scoreIntent($normalizedMessage, $intent) {
        $examples = self::$intentExamples[$intent] ?? [];
        $messageTokens = self::tokenize($normalizedMessage);
        $bestScore = 0;

        foreach ($examples as $example) {
            $normalizedExample = self::normalize($example);
            if ($normalizedExample === "") {
                continue;
            }

            $score = 0;
            if (strpos($normalizedMessage, $normalizedExample) !== false) {
                $score += 95;
            }

            $exampleTokens = self::tokenize($normalizedExample);
            if (!empty($messageTokens) && !empty($exampleTokens)) {
                $overlap = count(array_intersect($messageTokens, $exampleTokens));
                $coverage = $overlap / max(1, count($exampleTokens));
                $score += (int) round($coverage * 70);
            }

            if ($score > $bestScore) {
                $bestScore = $score;
            }
        }

        return $bestScore;
    }

    private static function detectRequestedLanguage($normalizedMessage, $fallbackLanguage) {
        if (strpos($normalizedMessage, 'hindi') !== false) {
            return 'hi';
        }

        if (strpos($normalizedMessage, 'kannada') !== false) {
            return 'kn';
        }

        if (strpos($normalizedMessage, 'english') !== false) {
            return 'en';
        }

        return $fallbackLanguage;
    }

    private static function isLanguageOnlyFollowUp($normalizedMessage) {
        return (bool) preg_match('/\b(hindi|kannada|english)\b/', $normalizedMessage)
            && !preg_match('/\b(attendance|result|fees|registration|hall|ticket|profile|course|subject|certificate|usn|cgpa|sgpa|backlog|exam)\b/', $normalizedMessage);
    }

    private static function detectEntities($message, $lastContext = []) {
        return [
            'subject' => StudentController::inferAttendanceSubject($message) ?: StudentController::inferCourseSubject($message) ?: ($lastContext['subject'] ?? ''),
            'semester' => StudentController::inferRequestedSemester($message) ?: ($lastContext['semester'] ?? null),
            'exam_type' => StudentController::inferExamType($message) ?: ($lastContext['exam_type'] ?? null)
        ];
    }

    private static function buildResolvedMessage($intent, $message, $entities, $lastContext = []) {
        $subject = trim((string) ($entities['subject'] ?? ''));
        $semester = $entities['semester'] ?? null;
        $examType = trim((string) ($entities['exam_type'] ?? ''));
        $normalized = self::normalize($message);

        switch ($intent) {
            case 'GET_SUBJECT_ATTENDANCE':
                if ($subject !== '') {
                    return "attendance in " . $subject . ($semester ? " semester " . $semester : "");
                }
                break;
            case 'GET_COURSE_CODE':
                if ($subject !== '') {
                    return "course code for " . $subject;
                }
                break;
            case 'GET_SGPA':
                if ($semester) {
                    return "sgpa semester " . $semester;
                }
                break;
            case 'GET_HALL_TICKET_STATUS':
                if ($examType !== '') {
                    return "hall ticket status " . $examType;
                }
                break;
            case 'GET_EXAM_READINESS':
                return $normalized;
        }

        return $normalized;
    }

    public static function resolve($message, $language = 'en', $lastContext = []) {
        $normalizedMessage = self::normalize($message);
        $tokens = self::tokenize($normalizedMessage);
        $entities = self::detectEntities($message, $lastContext);
        $requestedLanguage = self::detectRequestedLanguage($normalizedMessage, $language);
        $lastIntent = trim((string) ($lastContext['intent'] ?? ''));
        $isCourseCodeLike = strpos($normalizedMessage, 'code') !== false
            || strpos($normalizedMessage, 'course code') !== false
            || strpos($normalizedMessage, 'subject code') !== false;
        $bestIntent = null;
        $bestScore = 0;

        foreach (array_keys(self::$intentExamples) as $intent) {
            $score = self::scoreIntent($normalizedMessage, $intent);

            if ($lastIntent === $intent && count($tokens) <= 7) {
                if ($isCourseCodeLike && in_array($intent, ['GET_ATTENDANCE', 'GET_SUBJECT_ATTENDANCE'], true)) {
                    continue;
                }

                $score += 25;
            }

            if ($intent === 'GET_SUBJECT_ATTENDANCE' && !empty($entities['subject'])) {
                $score += 22;
            }

            if ($intent === 'GET_COURSE_CODE' && !empty($entities['subject']) && $isCourseCodeLike) {
                $score += 28;
            }

            if (strpos($normalizedMessage, 'attendance') !== false) {
                if ($intent === 'GET_SUBJECT_ATTENDANCE' || $intent === 'GET_ATTENDANCE') {
                    $score += 24;
                }

                if ($intent === 'GET_COURSE_CODE' && strpos($normalizedMessage, 'code') === false) {
                    $score -= 35;
                }
            }

            if (strpos($normalizedMessage, 'code') !== false) {
                if ($intent === 'GET_COURSE_CODE') {
                    $score += 20;
                }

                if ($intent === 'GET_SUBJECT_ATTENDANCE' || $intent === 'GET_ATTENDANCE') {
                    $score -= 18;
                }
            }

            if ($intent === 'GET_SGPA' && !empty($entities['semester']) && strpos($normalizedMessage, 'sgpa') !== false) {
                $score += 20;
            }

            if ($intent === 'GET_HALL_TICKET_STATUS' && !empty($entities['exam_type'])) {
                $score += 12;
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestIntent = $intent;
            }
        }

        if (self::isLanguageOnlyFollowUp($normalizedMessage) && !empty($lastContext['reply'])) {
            return [
                'type' => 'translate_last_reply',
                'intent' => $lastIntent !== '' ? $lastIntent : 'MEMORY_TRANSLATE',
                'route' => 'memory',
                'confidence' => 'high',
                'requested_language' => $requestedLanguage,
                'source' => 'smart_query_language_followup',
                'entities' => $entities
            ];
        }

        if ($bestIntent === null || $bestScore < 35) {
            return null;
        }

        $resolvedMessage = self::buildResolvedMessage($bestIntent, $message, $entities, $lastContext);
        $route = $bestIntent === 'GET_EXAM_READINESS' ? 'database' : 'database';
        $confidence = $bestScore >= 90 ? 'high' : ($bestScore >= 55 ? 'medium' : 'low');

        return [
            'type' => 'resolved_intent',
            'intent' => $bestIntent,
            'route' => $route,
            'confidence' => $confidence,
            'requested_language' => $requestedLanguage,
            'source' => 'smart_query_resolver',
            'entities' => $entities,
            'rewritten_message' => $resolvedMessage,
            'score' => $bestScore
        ];
    }
}

