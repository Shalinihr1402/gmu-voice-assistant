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
            "registration number",
            "university number"
        ],
        "GET_PROFILE_SUMMARY" => [
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
            "my result"
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
        $message = preg_replace('/[^a-z0-9\s]+/', ' ', $message);
        $message = preg_replace('/\s+/', ' ', (string) $message);
        return trim((string) $message);
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

        $aiClassification = self::classifyWithAi($message);
        if ($aiClassification !== null) {
            if (
                $aiClassification["route"] === self::DATABASE_ROUTE &&
                $aiClassification["confidence"] === "low"
            ) {
                $fallbackIntent = self::detectIntentFallback($message);
                if ($fallbackIntent !== "UNKNOWN") {
                    return [
                        "route" => self::DATABASE_ROUTE,
                        "intent" => $fallbackIntent,
                        "confidence" => "medium",
                        "source" => "keyword_fallback"
                    ];
                }

                return [
                    "route" => self::LLM_ROUTE,
                    "intent" => "UNKNOWN",
                    "confidence" => "low",
                    "source" => "ai_classifier"
                ];
            }

            return $aiClassification;
        }

        $fallbackIntent = self::detectIntentFallback($message);
        return [
            "route" => $fallbackIntent === "UNKNOWN" ? self::LLM_ROUTE : self::DATABASE_ROUTE,
            "intent" => $fallbackIntent,
            "confidence" => $fallbackIntent === "UNKNOWN" ? "low" : "medium",
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
        curl_setopt($ch, CURLOPT_TIMEOUT, 8);

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
        $message = self::normalizeIntentText($message);

        if (
            preg_match('/\b(course|subject)\s+code\b/', $message) ||
            preg_match('/\bcode\s+(of|for)\b/', $message)
        ) {
            return "GET_COURSE_CODE";
        }

        if (strpos($message, "attendance") !== false) {
            $overallAttendanceHints = [
                "my attendance",
                "overall attendance",
                "attendance percentage",
                "attendance status",
                "total attendance"
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
