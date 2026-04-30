<?php

class IntentService {
    private const DATABASE_ROUTE = "database";
    private const LLM_ROUTE = "llm";

    private static $intentPriority = [
        "GET_COURSE_CODE" => 120,
        "GET_SUBJECT_ATTENDANCE" => 110,
        "GET_ATTENDANCE" => 100,
        "GET_FINAL_REGISTRATION_STATUS" => 100,
        "GET_HALL_TICKET_STATUS" => 100,
        "GET_PROFILE_SUMMARY" => 95,
        "GET_FEES_BALANCE" => 90,
        "GET_BACKLOG_STATUS" => 90,
        "GET_CGPA" => 90,
        "GET_SGPA" => 85,
        "GET_COURSE_DETAILS" => 70,
        "GET_USN" => 65
    ];

    private static $intentMap = [
        "GET_USN" => [
            "usn",
            "my usn",
            "registration number",
            "university number"
        ],
        "GET_PROFILE_SUMMARY" => [
            "profile",
            "who am i",
            "do you know who i am",
            "my profile",
            "tell me about my profile",
            "student profile",
            "which semester am i in",
            "what semester am i in",
            "my semester",
            "which department am i from",
            "what department am i from",
            "my department",
            "my branch",
            "what am i studying"
        ],
        "GET_SGPA" => [
            "sgpa",
            "gpa",
            "semester gpa",
            "result",
            "semester result",
            "my result",
            "my sgpa"
        ],
        "GET_CGPA" => [
            "cgpa",
            "overall gpa",
            "overall result",
            "cumulative gpa",
            "current cgpa"
        ],
        "GET_BACKLOG_STATUS" => [
            "backlog",
            "backlogs",
            "failed subject",
            "fail or pass",
            "pass or fail",
            "have i failed",
            "did i fail",
            "supplementary"
        ],
        "GET_FEES_BALANCE" => [
            "fee",
            "fees",
            "fee balance",
            "balance",
            "due",
            "pending amount",
            "amount due"
        ],
        "GET_FINAL_REGISTRATION_STATUS" => [
            "final registration",
            "registration status",
            "am i registered",
            "have i registered",
            "is my registration complete",
            "is my final registration complete",
            "registration completed",
            "registered or not"
        ],
        "GET_HALL_TICKET_STATUS" => [
            "hall ticket",
            "hallticket",
            "admission ticket",
            "ticket generated",
            "is my hall ticket generated",
            "my hall ticket status",
            "can i download hall ticket"
        ],
        "GET_COURSE_DETAILS" => [
            "subject",
            "subjects",
            "course",
            "courses",
            "my subjects",
            "my courses",
            "what subjects do i have",
            "what courses do i have",
            "subject details",
            "course details",
            "registered subjects",
            "registered courses"
        ],
        "GET_ATTENDANCE" => [
            "my attendance",
            "overall attendance",
            "attendance percentage",
            "attendance status"
        ],
        "GET_SUBJECT_ATTENDANCE" => [
            "attendance in",
            "attendance of",
            "percentage in",
            "subject attendance"
        ],
        "GET_COURSE_CODE" => [
            "course code",
            "subject code",
            "code of",
            "code for"
        ]
    ];

    private static function getEnvValue($key) {
        $value = getenv($key);

        if ($value !== false && $value !== "") {
            return $value;
        }

        if (isset($_SERVER[$key]) && $_SERVER[$key] !== "") {
            return $_SERVER[$key];
        }

        if (isset($_ENV[$key]) && $_ENV[$key] !== "") {
            return $_ENV[$key];
        }

        return null;
    }

    private static function normalizeIntentText($message) {
        $message = strtolower(trim((string) $message));
        $message = self::canonicalizeHindiIntentTerms($message);
        $message = self::canonicalizeKannadaIntentTerms($message);
        $message = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $message);
        $message = preg_replace('/\s+/', ' ', (string) $message);
        return trim((string) $message);
    }

    private static function containsAny($message, $needles) {
        foreach ($needles as $needle) {
            if ($needle !== "" && strpos($message, $needle) !== false) {
                return true;
            }
        }

        return false;
    }

    private static function canonicalizeHindiIntentTerms($message) {
        $replacements = [
            '/फाइनल|अंतिम/u' => ' final ',
            '/रजिस्ट्रेशन|रजिस्ट्रेसन|रेजिस्ट्रेशन|पंजीकरण|पंजीयन/u' => ' registration ',
            '/हॉल\s*टिकट|हाल\s*टिकट|एडमिट\s*कार्ड|प्रवेश\s*पत्र/u' => ' hall ticket ',
            '/स्टेटस|स्थिति|हालत/u' => ' status ',
            '/प्रोफाइल|प्रोफ़ाइल|प्रोफ़ाइल|मेरे\s+बारे|मेरा\s+प्रोफाइल|मेरी\s+प्रोफाइल/u' => ' profile ',
            '/फीस|फी|शुल्क|बकाया/u' => ' fee balance due ',
            '/अटेंडेंस|अटेंडेंस|उपस्थिति|हाजिरी/u' => ' attendance ',
            '/रिजल्ट|रिज़ल्ट|रेजल्ट|रिजल|रेजल|रजल|परिणाम|नतीजा/u' => ' result ',
            '/एसजीपीए|एस\s*जी\s*पी\s*ए/u' => ' sgpa ',
            '/सीजीपीए|सी\s*जी\s*पी\s*ए/u' => ' cgpa ',
            '/बैकलॉग|बेकलॉग|सप्लीमेंटरी/u' => ' backlog ',
            '/फेल|असफल/u' => ' fail ',
            '/पास|उत्तीर्ण/u' => ' pass ',
            '/कोर्स|कोर्सेस|सब्जेक्ट|सब्जेक्ट्स|विषय/u' => ' course subject ',
            '/कोड/u' => ' code ',
            '/यूएसएन|यू\s*एस\s*एन/u' => ' usn ',
            '/मैं\s+कौन/u' => ' who am i ',
            '/सेमेस्टर/u' => ' semester ',
            '/ब्रांच|विभाग|डिपार्टमेंट/u' => ' branch department ',
            '/कितनी|कितना/u' => ' how much ',
            '/पूरा|पूर्ण|कम्प्लीट|कंप्लीट/u' => ' complete ',
            '/पेंडिंग|लंबित/u' => ' pending ',
            '/क्या/u' => ' ',
            '/मेरा|मेरी|मेरे|अपना|अपनी|आपका|आपकी/u' => ' my '
        ];

        return preg_replace(
            array_keys($replacements),
            array_values($replacements),
            (string) $message
        );
    }

    private static function canonicalizeKannadaIntentTerms($message) {
        $replacements = [
            '/\b(nanna|nan|nanage|nanna\s+bagge|nanna\s+profile|nimma)\b/u' => ' my ',
            '/\b(dayavittu|swalpa|please)\b|ದಯವಿಟ್ಟು/u' => ' ',
            '/\b(enu|yenu|yen|helu|heli|tilsu|tilisi|torisu|torisi|beku|please tell)\b/u' => ' ',
            '/\b(profail|profle)\b|ಪ್ರೊಫೈಲ್/u' => ' profile ',
            '/\b(semesteru|semister|sem)\b|ಸೆಮಿಸ್ಟರ್/u' => ' semester ',
            '/\b(departmentu|departmente|branchu|vibhaga)\b|ವಿಭಾಗ|ಡಿಪಾರ್ಟ್‌ಮೆಂಟ್|ಬ್ರಾಂಚ್/u' => ' branch department ',
            '/\b(feesu|feesu|fee|fi|baki|bakki|balanceu|balance|due|fees balance|fee balance)\b|ಶುಲ್ಕ|ಫೀಸ್|ಫೀ|ಬಾಕಿ|ಬ್ಯಾಲೆನ್ಸ್/u' => ' fee balance due ',
            '/\b(attendence|atendance|attendanceu|attendance|hajari)\b|ಹಾಜರಾತಿ|ಹಾಜರಿ|ಅಟೆಂಡೆನ್ಸ್|ಅಟೆಂಡೆನ್ಸ್/u' => ' attendance ',
            '/\b(resultu|result|rijalt|resalt|phalithaansha|marks card)\b|ಫಲಿತಾಂಶ|ರಿಸಲ್ಟ್|ರಿಜಲ್ಟ್|ಮಾರ್ಕ್ಸ್/u' => ' result ',
            '/\b(backlogu|back)\b|ಬ್ಯಾಕ್ಲಾಗ್/u' => ' backlog ',
            '/\b(faila|fail)\b|ಫೇಲ್/u' => ' fail ',
            '/\b(passa|pass)\b|ಪಾಸ್/u' => ' pass ',
            '/\b(courseu|coursu|subjectu|vishaya)\b|ಕೋರ್ಸ್|ಸಬ್ಜೆಕ್ಟ್|ವಿಷಯ/u' => ' course subject ',
            '/\b(codeu|kode)\b|ಕೋಡ್/u' => ' code ',
            '/\b(usn|yu es en|u s n|yu esn|uesn|yuesen|yusn|upsn)\b|ಯುಎಸ್‌ಎನ್|ಯು ಎಸ್ ಎನ್|ಯುಎಸ್ಎನ್|ಯುಪಿಎಸನ್|ಯು ಪಿ ಎಸ್ ಎನ್/u' => ' usn ',
            '/\b(sgpa|esjipie|s j p a)\b|ಎಸ್‌ಜಿಪಿಎ|ಎಸ್ ಜಿಪಿಎ/u' => ' sgpa ',
            '/\b(cgpa|sijipie|c j p a)\b|ಸಿಜಿಪಿಎ|ಸಿ ಜಿಪಿಎ/u' => ' cgpa ',
            '/\b(final)\b|ಫೈನಲ್|ಅಂತಿಮ/u' => ' final ',
            '/\b(registrationu|rijistreshan|registrashan|regis tration|rijis tration|rijis treshan|rejistration)\b|ರಿಜಿಸ್ಟ್ರೇಶನ್|ರಿಜಿಸ್ ಟ್ರೇಶನ್|ನೋಂದಣಿ/u' => ' registration ',
            '/\b(hallticket|hall\s*ticketu|haal ticket|hal ticket|all ticket|al ticket)\b|ಹಾಲ್\s*ಟಿಕೆಟ್|ಆಲ್\s*ಟಿಕೆಟ್|ಅಲ್\s*ಟಿಕೆಟ್/u' => ' hall ticket ',
            '/\b(statusu)\b|ಸ್ಥಿತಿ/u' => ' status ',
            '/\b(yestu|eshtu|yeshtu|how much)\b|ಎಷ್ಟು/u' => ' how much ',
            '/\b(completea|completeda|complyta)\b|ಪೂರ್ಣ|ಕಂಪ್ಲೀಟ್/u' => ' complete ',
            '/\b(pendinga|pending)\b|ಪೆಂಡಿಂಗ್/u' => ' pending ',
            '/\b(naanu\s+yaaru|nanu\s+yaaru)\b|ನಾನು\s+ಯಾರು/u' => ' who am i ',
            '/\b(yava\s+semester|which\s+semester)\b|ಯಾವ\s+ಸೆಮಿಸ್ಟರ್/u' => ' which semester ',
            '/\b(yava\s+department|yava\s+branch)\b|ಯಾವ\s+ವಿಭಾಗ/u' => ' which department ',
            '/\b(heli|helu|tilisi|tilsu|torisu|show madi|open madi)\b|ಹೇಳಿ|ಹೇಳು|ತಿಳಿಸಿ|ತೋರಿಸು/u' => ' '
        ];

        $message = preg_replace(
            array_keys($replacements),
            array_values($replacements),
            (string) $message
        );

        $message = str_replace(
            [
                'course subject registration',
                'course subject status',
                'result status',
                'attendance status',
                'fee balance due status',
                'hall ticket status',
                'final registration status',
                'course registration status'
            ],
            [
                'course registration',
                'course details',
                'result',
                'attendance',
                'fee balance',
                'hall ticket',
                'final registration',
                'course registration'
            ],
            $message
        );

        return $message;
    }

    public static function classifyIntent($message, $userContext = []) {
        $message = trim((string) $message);
        $roleKey = strtolower(trim((string) ($userContext["role_key"] ?? "student")));

        if ($roleKey !== "student") {
            return [
                "route" => self::LLM_ROUTE,
                "intent" => "ROLE_AWARE_ASSIST",
                "confidence" => "medium",
                "source" => "role_policy"
            ];
        }

        $fallbackIntent = self::detectIntentFallback($message);
        if ($fallbackIntent !== "UNKNOWN") {
            return [
                "route" => self::DATABASE_ROUTE,
                "intent" => $fallbackIntent,
                "confidence" => "medium",
                "source" => "keyword_fallback_fast"
            ];
        }

        $aiClassification = self::classifyWithAi($message);
        if ($aiClassification !== null) {
            if (
                $aiClassification["route"] === self::DATABASE_ROUTE &&
                $aiClassification["confidence"] === "low"
            ) {
                return [
                    "route" => self::LLM_ROUTE,
                    "intent" => "UNKNOWN",
                    "confidence" => "low",
                    "source" => "ai_classifier"
                ];
            }

            return $aiClassification;
        }

        return [
            "route" => self::LLM_ROUTE,
            "intent" => "UNKNOWN",
            "confidence" => "low",
            "source" => "keyword_fallback"
        ];
    }

    private static function classifyWithAi($message) {
        $apiKey = self::getEnvValue("GEMINI_API_KEY") ?: self::getEnvValue("GOOGLE_API_KEY");
        if (!$apiKey) {
            return null;
        }

        $model = self::getEnvValue("INTENT_CLASSIFIER_MODEL") ?: "gemini-2.5-flash";
        $allowedIntents = implode(", ", array_keys(self::$intentMap));
        $prompt = "Classify the user query for routing in a university assistant. "
            . "Return only JSON with keys route, intent, confidence. "
            . "route must be either database or llm. "
            . "intent must be one of {$allowedIntents}, ROLE_AWARE_ASSIST, or UNKNOWN. "
            . "Use database only when the query is a short factual student portal lookup that directly matches one database handler. "
            . "Use llm for ambiguous, conversational, multi-part, reasoning-heavy, or open-ended queries. "
            . "confidence must be high, medium, or low. "
            . "Query: " . $message;

        $payload = json_encode([
            "contents" => [[
                "role" => "user",
                "parts" => [[
                    "text" => $prompt
                ]]
            ]],
            "generationConfig" => [
                "temperature" => 0.1,
                "topP" => 0.8,
                "maxOutputTokens" => 100
            ]
        ]);

        $url = "https://generativelanguage.googleapis.com/v1beta/models/" . rawurlencode($model) . ":generateContent";
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "x-goog-api-key: " . $apiKey,
            "Content-Type: application/json"
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 4);

        $response = curl_exec($ch);
        $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $statusCode >= 400) {
            return null;
        }

        $data = json_decode($response, true);
        $parts = $data["candidates"][0]["content"]["parts"] ?? [];
        $text = "";
        foreach ($parts as $part) {
            $text .= (string) ($part["text"] ?? "");
        }

        $parsed = self::parseClassificationJson($text);
        if ($parsed === null) {
            return null;
        }

        $route = $parsed["route"] ?? self::LLM_ROUTE;
        $intent = $parsed["intent"] ?? "UNKNOWN";
        $confidence = $parsed["confidence"] ?? "low";

        $validRoute = in_array($route, [self::DATABASE_ROUTE, self::LLM_ROUTE], true);
        $validIntent = $intent === "UNKNOWN" || $intent === "ROLE_AWARE_ASSIST" || isset(self::$intentMap[$intent]);
        $validConfidence = in_array($confidence, ["high", "medium", "low"], true);

        if (!$validRoute || !$validIntent || !$validConfidence) {
            return null;
        }

        if ($route === self::DATABASE_ROUTE && !isset(self::$intentMap[$intent])) {
            return null;
        }

        return [
            "route" => $route,
            "intent" => $intent,
            "confidence" => $confidence,
            "source" => "ai_classifier"
        ];
    }

    private static function parseClassificationJson($text) {
        $text = trim((string) $text);
        if ($text === "") {
            return null;
        }

        if (preg_match('/\{.*\}/s', $text, $matches)) {
            $text = $matches[0];
        }

        $data = json_decode($text, true);
        return is_array($data) ? $data : null;
    }

    public static function detectIntent($message) {
        return self::detectIntentFallback($message);
    }

    private static function detectIntentFallback($message) {
        $rawMessage = strtolower(trim((string) $message));
        $message = self::normalizeIntentText($message);

        if ($message === "") {
            return "UNKNOWN";
        }

        if (
            preg_match('/ಯು\s*ಎ\s*ಸ\s*ಎನ್/u', $rawMessage) ||
            preg_match('/ಯು\s*ಪಿ\s*ಎ\s*ಸ\s*ಎನ್/u', $rawMessage) ||
            preg_match('/ಯು\s*ಪಿ\s*ಎಸ್\s*ಎನ್/u', $rawMessage) ||
            preg_match('/ಯು\s*ಎಸ್\s*ಎನ್/u', $rawMessage) ||
            preg_match('/\by\s*u\s*s\s*n\b/u', $rawMessage)
        ) {
            return "GET_USN";
        }

        if (
            preg_match('/ಹಾಲ್\s*ಟಿಕೆಟ್/u', $rawMessage) ||
            preg_match('/ಆಲ್\s*ಟಿಕೆಟ್/u', $rawMessage) ||
            preg_match('/ಅಲ್\s*ಟಿಕೆಟ್/u', $rawMessage)
        ) {
            return "GET_HALL_TICKET_STATUS";
        }

        if (
            preg_match('/ಫೈನಲ್\s*ರಿಜಿ/u', $rawMessage) ||
            preg_match('/ರಿಜಿ\s*ಸ್ಟ್ರೇ/u', $rawMessage) ||
            preg_match('/ನೋಂದಣಿ/u', $rawMessage)
        ) {
            return "GET_FINAL_REGISTRATION_STATUS";
        }

        if (
            preg_match('/ಫೀಸ್|ಫೀ\s|ಬಾಕಿ|ಬ್ಯಾಲೆನ್ಸ್/u', $rawMessage)
        ) {
            return "GET_FEES_BALANCE";
        }

        if (
            preg_match('/ಅಟೆಂಡ|ಹಾಜರ/u', $rawMessage)
        ) {
            return "GET_ATTENDANCE";
        }

        if (
            preg_match('/ರಿಸಲ|ರಿಜಲ|ಫಲಿತಾಂಶ|ಎಸ್\s*ಜಿ\s*ಪಿ\s*ಎ/u', $rawMessage)
        ) {
            return "GET_SGPA";
        }

        if (
            preg_match('/ಬ್ಯಾಕ್\s*(ಲಾಗ್|ಲೋಗ್|ಲಾಕ್)(್ಸ್|ಸ್)?|ಬ್ಯಾಕ್?(ಲಾಗ್|ಲೋಗ್|ಲಾಕ್)(್ಸ್|ಸ್)?|ಫೇಲ್|ಸಪ್ಲಿಮೆಂಟರಿ/u', $rawMessage) ||
            preg_match('/\b(backlog|backlogs|fail|failed|supplementary|supply)\b/', $rawMessage) ||
            preg_match('/ಬ್ಯಾ.*(ಲಾಗ|ಲೋಗ|ಲಾಕ್)/u', $rawMessage)
        ) {
            return "GET_BACKLOG_STATUS";
        }

        if (self::containsAny($message, ["usn", "my usn"])) {
            return "GET_USN";
        }

        if (self::containsAny($message, ["hall ticket", "hallticket"])) {
            return "GET_HALL_TICKET_STATUS";
        }

        if (self::containsAny($message, [
            "final registration",
            "registration status",
            "registered or not",
            "registration complete",
            "registration pending",
            "course registration"
        ])) {
            return "GET_FINAL_REGISTRATION_STATUS";
        }

        if (self::containsAny($message, [
            "fee balance",
            "fees balance",
            "pending amount",
            "amount due",
            "balance due",
            "fee",
            "fees"
        ])) {
            return "GET_FEES_BALANCE";
        }

        if (self::containsAny($message, [
            "profile",
            "who am i",
            "my semester",
            "which semester",
            "what semester",
            "my department",
            "which department",
            "my branch",
            "what am i studying"
        ])) {
            return "GET_PROFILE_SUMMARY";
        }

        if (self::containsAny($message, ["cgpa", "overall gpa", "cumulative gpa"])) {
            return "GET_CGPA";
        }

        if (self::containsAny($message, [
            "sgpa",
            "semester cgpa",
            "semester result",
            "my result",
            "result"
        ])) {
            return "GET_SGPA";
        }

        if (self::containsAny($message, ["backlog", "failed subject", "supplementary", "fail"])) {
            return "GET_BACKLOG_STATUS";
        }

        if (
            preg_match('/\b(course|subject)\s+code\b/', $message) ||
            preg_match('/\bcode\s+(of|for)\b/', $message) ||
            preg_match('/ಕೋಡ್/u', $rawMessage) ||
            preg_match('/course code|subject code/', $message)
        ) {
            return "GET_COURSE_CODE";
        }

        if (self::containsAny($message, [
            "course subject",
            "my courses",
            "my subjects",
            "course details",
            "subject details",
            "registered subjects",
            "registered courses",
            "course"
        ])) {
            return "GET_COURSE_DETAILS";
        }

        if (strpos($message, "attendance") !== false) {
            $overallAttendanceHints = [
                "my attendance",
                "overall attendance",
                "attendance percentage",
                "attendance status",
                "total attendance",
                "attendance"
            ];

            foreach ($overallAttendanceHints as $hint) {
                if (strpos($message, $hint) !== false) {
                    return "GET_ATTENDANCE";
                }
            }

            if (
                preg_match('/\b[a-z0-9&(). -]+\s+attendance\b/', $message) ||
                preg_match('/\battendance\s+(?:in|of|for)\b/', $message)
            ) {
                return "GET_SUBJECT_ATTENDANCE";
            }

            return "GET_ATTENDANCE";
        }

        if (self::containsAny($message, [
            "student portal",
            "semester",
            "department",
            "branch",
            "result",
            "attendance",
            "registration",
            "hall ticket",
            "fee",
            "usn",
            "profile"
        ])) {
            return "GET_PROFILE_SUMMARY";
        }

        $bestIntent = "UNKNOWN";
        $bestScore = 0;

        foreach (self::$intentMap as $intent => $keywords) {
            $score = self::$intentPriority[$intent] ?? 50;

            foreach ($keywords as $keyword) {
                if (strpos($message, $keyword) !== false) {
                    $keywordWords = array_values(array_filter(explode(" ", $keyword)));
                    $scoreBoost = max(1, count($keywordWords)) * 10;

                    if ($score + $scoreBoost > $bestScore) {
                        $bestScore = $score + $scoreBoost;
                        $bestIntent = $intent;
                    }
                }
            }
        }

        return $bestIntent;
    }
}
