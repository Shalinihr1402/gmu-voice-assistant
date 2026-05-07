<?php

require_once __DIR__ . "/../intents/controllers/StudentController.php";

class SmartQueryResolver {
    private static $intentExamples = [
        "GET_USN" => [
            "my usn",
            "what is my usn",
            "registration number",
            "university number",
            "यूएसएन क्या है",
            "मेरा usn",
            "ನನ್ನ usn",
            "ನನ್ನ ಯುಎಸ್ಎನ್"
        ],
        "GET_PROFILE_SUMMARY" => [
            "who am i",
            "my profile",
            "what semester am i in",
            "what is my department",
            "student details",
            "मेरी प्रोफाइल",
            "मैं कौन हूँ",
            "मेरा विभाग",
            "ನನ್ನ profile",
            "ನಾನು ಯಾರು",
            "ನನ್ನ department"
        ],
        "GET_SGPA" => [
            "my sgpa",
            "semester result",
            "semester gpa",
            "how did i do this semester",
            "sgpa for 5th semester",
            "मेरा sgpa",
            "सेमेस्टर रिजल्ट",
            "5th sem sgpa",
            "ನನ್ನ sgpa",
            "semester result",
            "5th sem sgpa"
        ],
        "GET_CGPA" => [
            "my cgpa",
            "overall result",
            "overall gpa",
            "cumulative gpa",
            "मेरा cgpa",
            "overall cgpa",
            "ನನ್ನ cgpa",
            "overall gpa"
        ],
        "GET_BACKLOG_STATUS" => [
            "do i have backlogs",
            "have i failed",
            "failed subjects",
            "supplementary subjects",
            "am i pass or fail",
            "क्या मेरे backlog हैं",
            "मैं fail हुआ क्या",
            "backlog ideya",
            "ನಾನು fail ಆಗಿದ್ದೇನಾ"
        ],
        "GET_FEES_BALANCE" => [
            "my fee balance",
            "how much fee is pending",
            "amount due",
            "pending fees",
            "fee status",
            "मेरी fees pending",
            "कितनी fees due है",
            "ನನ್ನ fee balance",
            "fees pending ideya",
            "eshtu due ide"
        ],
        "GET_FINAL_REGISTRATION_STATUS" => [
            "is my registration complete",
            "final registration status",
            "am i registered",
            "registration clear",
            "can i complete registration",
            "मेरा registration complete है क्या",
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
            "मेरे subjects क्या हैं",
            "course list",
            "ನನ್ನ subjects",
            "course list",
            "ಈ semester subjects"
        ],
        "GET_ATTENDANCE" => [
            "my overall attendance",
            "attendance percentage",
            "attendance status",
            "am i short of attendance",
            "मेरी attendance क्या है",
            "overall attendance",
            "ನನ್ನ attendance",
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
            "क्या मैं exam के लिए clear हूँ",
            "क्या मैं exam लिख सकता हूँ",
            "exam ge clear iddina",
            "nanu exam ಬರೆಯಬಹುದಾ"
        ]
    ];

    private static function normalize($message) {
        $message = mb_strtolower(trim((string) $message), "UTF-8");

        $replacements = [
            '/अटेंडेंस|उपस्थिति/u' => ' attendance ',
            '/रिजल्ट|परिणाम/u' => ' result ',
            '/फीस|शुल्क|बाकाया/u' => ' fees due ',
            '/रजिस्ट्रेशन|पंजीकरण/u' => ' registration ',
            '/हॉल\s*टिकट|प्रवेश\s*पत्र/u' => ' hall ticket ',
            '/प्रोफाइल/u' => ' profile ',
            '/विभाग|डिपार्टमेंट/u' => ' department ',
            '/सेमेस्टर/u' => ' semester ',
            '/सर्टिफिकेट|प्रमाणपत्र/u' => ' certificate ',
            '/अंग्रेजी/u' => ' english ',
            '/हिंदी/u' => ' hindi ',
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
        $bestIntent = null;
        $bestScore = 0;

        foreach (array_keys(self::$intentExamples) as $intent) {
            $score = self::scoreIntent($normalizedMessage, $intent);

            if ($lastIntent === $intent && count($tokens) <= 7) {
                $score += 25;
            }

            if ($intent === 'GET_SUBJECT_ATTENDANCE' && !empty($entities['subject'])) {
                $score += 22;
            }

            if ($intent === 'GET_COURSE_CODE' && !empty($entities['subject']) && strpos($normalizedMessage, 'code') !== false) {
                $score += 28;
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
