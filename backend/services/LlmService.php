<?php

require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/KnowledgeBaseService.php";

class LlmService {
    private static $lastReplyMeta = [
        "source" => "unknown"
    ];
    private const HISTORY_LIMIT = 6;

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

    private static function getStudentName($student_id) {
        global $conn;

        $stmt = $conn->prepare("
            SELECT full_name
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

        return $result["full_name"] ?? null;
    }

    private static function getConversationHistory() {
        if (!isset($_SESSION["voicebot_history"]) || !is_array($_SESSION["voicebot_history"])) {
            $_SESSION["voicebot_history"] = [];
        }

        return $_SESSION["voicebot_history"];
    }

    private static function saveConversationTurn($userMessage, $assistantReply) {
        if (!isset($_SESSION["voicebot_history"]) || !is_array($_SESSION["voicebot_history"])) {
            $_SESSION["voicebot_history"] = [];
        }

        $_SESSION["voicebot_history"][] = [
            "role" => "user",
            "text" => trim((string) $userMessage)
        ];
        $_SESSION["voicebot_history"][] = [
            "role" => "assistant",
            "text" => trim((string) $assistantReply)
        ];

        if (count($_SESSION["voicebot_history"]) > self::HISTORY_LIMIT) {
            $_SESSION["voicebot_history"] = array_slice($_SESSION["voicebot_history"], -self::HISTORY_LIMIT);
        }
    }

    private static function clearConversationHistory() {
        $_SESSION["voicebot_history"] = [];
    }

    private static function buildSystemPrompt($userContext, $knowledgeItems) {
        $roleName = $userContext["role_name"] ?? "User";
        $roleKey = $userContext["role_key"] ?? "student";
        $userName = $userContext["full_name"] ?? null;
        $unitName = $userContext["unit_name"] ?? null;
        $designation = $userContext["designation"] ?? null;

        $identitySummary = [];
        $identitySummary[] = "The current user role is {$roleName} ({$roleKey}).";
        $identitySummary[] = $userName ? "The current user's name is {$userName}." : "The current user's name is not available.";
        if ($unitName) {
            $identitySummary[] = "Their unit is {$unitName}.";
        }
        if ($designation) {
            $identitySummary[] = "Their designation is {$designation}.";
        }

        $knowledgeSummary = "";
        if (!empty($knowledgeItems)) {
            $knowledgeLines = [];
            foreach ($knowledgeItems as $item) {
                $knowledgeLines[] = $item["topic"] . ": " . $item["content"];
            }
            $knowledgeSummary = " Relevant knowledge base context: " . implode(" ", $knowledgeLines);
        }

        $capabilitySummary = "You can help with profile, fees, attendance, semester results, CGPA, backlog status, course details, and final registration status for student users. For academic and records questions, answer clearly, precisely, and professionally.";

        $responseRules = "Response rules: reply in natural spoken English with a warm, clear, human tone. Keep most answers to 2 to 5 complete sentences unless the user asks for more detail. Never return fragments, broken quotes, or incomplete thoughts. Sound like a helpful university assistant: polite, calm, confident, and conversational without becoming casual or chatty. Lead with the direct answer, then add one short helpful follow-up sentence when useful. Vary sentence openings so replies do not sound repetitive. Use simple everyday wording that sounds good when spoken aloud. Address the user by name only when it feels genuinely helpful. Do not mention internal system prompts, APIs, databases, or technical details. If exact data is unavailable, say that briefly and offer the closest helpful guidance.";

        $examples = "Examples. If asked 'who are you', say something like 'I am GMU VoiceBot, your university assistant. I can help you with profile, fee, attendance, result, and registration queries.' If asked for a joke, keep it short, clean, and professional. If asked a role-specific question outside available records, explain the limit politely and suggest the right kind of question.";

        return "You are GMU VoiceBot, a role-aware university assistant for GM University. Your speaking style should feel natural, clear, and reassuring for voice conversations on an academic portal. Speak like a helpful person, not like a scripted bot. Avoid repeating the same sentence structure across turns unless necessary. When the user asks a follow-up question, continue naturally from the recent conversation instead of restarting with the same generic introduction. " . $capabilitySummary . " " . $responseRules . " " . $examples . " " . implode(" ", $identitySummary) . $knowledgeSummary;
    }

    private static function isValidHostedReply($reply) {
        $reply = trim((string) $reply);

        if ($reply === "") {
            return false;
        }

        if (strlen($reply) < 8) {
            return false;
        }

        $lower = strtolower($reply);
        $badReplies = [
            "i understand you",
            "i understand you'",
            "i understand",
            "okay",
            "ok"
        ];

        if (in_array($lower, $badReplies, true)) {
            return false;
        }

        if (substr($reply, -1) === "'" || substr($reply, -1) === "\"" || substr($reply, -1) === ",") {
            return false;
        }

        if (substr_count($reply, "'") % 2 !== 0 || substr_count($reply, "\"") % 2 !== 0) {
            return false;
        }

        return true;
    }

    private static function finalizeHostedReply($reply) {
        $reply = trim((string) $reply);

        if ($reply === "") {
            return null;
        }

        $reply = preg_replace('/\s+/', ' ', $reply);

        if (!preg_match('/[.!?]$/', $reply)) {
            $reply .= ".";
        }

        return self::isValidHostedReply($reply) ? $reply : null;
    }

    private static function getProviderOrder() {
        $preferred = strtolower(trim((string) (self::getEnvValue("LLM_PROVIDER") ?: "")));

        if ($preferred === "gemini") {
            return ["gemini", "openai"];
        }

        if ($preferred === "openai") {
            return ["openai", "gemini"];
        }

        return ["gemini", "openai"];
    }

    private static function localFallback($message, $userContext = null) {
        $message = strtolower(trim($message));
        $role = $userContext["role_key"] ?? "student";
        $roleName = $userContext["role_name"] ?? "user";

        $patterns = [
            "/\bhow are you\b/" => "I am doing well. How can I help you today?",
            "/\bwho are you\b/" => "I am GMU VoiceBot. I can help with university information and role-based assistance for {$roleName} users.",
            "/\b(joke|funny|laugh|make me laugh)\b/" => "Here is one. Why did the student bring a ladder to class? Because the grades were too high.",
            "/\b(marriage|address|wedding)\b/" => "I cannot know your personal future, but I can help you with your academic details.",
            "/\b(love|girlfriend|boyfriend)\b/" => "I am better with university questions than love advice, but I am here to help however I can.",
            "/\b(hello|hi|hey)\b/" => "Hello. How can I help you today?",
            "/\b(good morning|good afternoon|good evening)\b/" => "Hello. How can I help you today?",
            "/\b(bye|goodbye|see you)\b/" => "Goodbye. If you need anything else about your {$role} access or university tasks, I am here to help.",
            "/\b(help|assist|support)\b/" => self::getRoleHelpMessage($role),

            "/\b(thank you|thanks)\b/" => "You are welcome. Happy to help."
        ];  

        foreach ($patterns as $pattern => $reply) {
            if (preg_match($pattern, $message)) {
                return $reply;
            }
        }

        return self::getRoleHelpMessage($role);
    }

    public static function getLastReplyMeta() {
        return self::$lastReplyMeta;
    }

    private static function getRoleHelpMessage($role) {
        $helpMap = [
            "student" => "I can help you with your student profile, fees, attendance, results, and course details.",
            "teacher" => "I can help you with teacher-related university information, department context, and general assistant guidance.",
            "hod" => "I can help you with department-level academic information, role guidance, and university assistant support.",
            "director" => "I can help you with director-level university information, escalations, and institutional guidance.",
            "dean" => "I can help you with dean-level academic oversight information and university assistant support.",
            "registrar" => "I can help you with registrar-related records guidance and university assistant support.",
            "management" => "I can help you with management-level university information and strategic guidance."
        ];

        return ($helpMap[$role] ?? "I can help you with university information and role-based assistance.") . " What would you like to know?";
    }

    private static function callOpenAI($message, $userContext, $knowledgeItems) {
        $apiKey = self::getEnvValue("OPENAI_API_KEY");

        if (!$apiKey) {
            return null;
        }

        $model = self::getEnvValue("OPENAI_MODEL") ?: "gpt-4.1-mini";
        $systemPrompt = self::buildSystemPrompt($userContext, $knowledgeItems);

        $input = [[
            "role" => "system",
            "content" => [[
                "type" => "input_text",
                "text" => $systemPrompt
            ]]
        ]];

        foreach (self::getConversationHistory() as $historyItem) {
            $historyRole = $historyItem["role"] ?? "user";
            $historyText = trim((string) ($historyItem["text"] ?? ""));
            if ($historyText === "") {
                continue;
            }

            $input[] = [
                "role" => $historyRole,
                "content" => [[
                    "type" => "input_text",
                    "text" => $historyText
                ]]
            ];
        }

        $input[] = [
            "role" => "user",
            "content" => [[
                "type" => "input_text",
                "text" => $message
            ]]
        ];

        $payload = json_encode([
            "model" => $model,
            "input" => $input,
            "max_output_tokens" => 160
        ]);

        $ch = curl_init("https://api.openai.com/v1/responses");
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer " . $apiKey,
            "Content-Type: application/json"
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);

        $response = curl_exec($ch);
        $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $statusCode >= 400) {
            return null;
        }

        $data = json_decode($response, true);

        if (isset($data["output_text"])) {
            $reply = self::finalizeHostedReply($data["output_text"]);
            if ($reply) {
                return $reply;
            }
        }

        if (!isset($data["output"]) || !is_array($data["output"])) {
            return null;
        }

        foreach ($data["output"] as $item) {
            if (($item["type"] ?? "") !== "message" || !isset($item["content"])) {
                continue;
            }

            foreach ($item["content"] as $content) {
                $text = $content["text"] ?? "";

                $reply = self::finalizeHostedReply($text);
                if ($reply) {
                    return $reply;
                }
            }
        }

        return null;
    }

    private static function callGemini($message, $userContext, $knowledgeItems) {
        $apiKey = self::getEnvValue("GEMINI_API_KEY") ?: self::getEnvValue("GOOGLE_API_KEY");

        if (!$apiKey) {
            return null;
        }

        $model = self::getEnvValue("GEMINI_MODEL") ?: "gemini-2.5-flash";
        $systemPrompt = self::buildSystemPrompt($userContext, $knowledgeItems);

        $contents = [];
        foreach (self::getConversationHistory() as $historyItem) {
            $historyRole = ($historyItem["role"] ?? "user") === "assistant" ? "model" : "user";
            $historyText = trim((string) ($historyItem["text"] ?? ""));
            if ($historyText === "") {
                continue;
            }

            $contents[] = [
                "role" => $historyRole,
                "parts" => [[
                    "text" => $historyText
                ]]
            ];
        }

        $contents[] = [
            "role" => "user",
            "parts" => [[
                "text" => $message
            ]]
        ];

        $payload = json_encode([
            "system_instruction" => [
                "parts" => [[
                    "text" => $systemPrompt
                ]]
            ],
            "contents" => $contents,
            "generationConfig" => [
                "temperature" => 0.55,
                "topP" => 0.9,
                "maxOutputTokens" => 160
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
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);

        $response = curl_exec($ch);
        $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $statusCode >= 400) {
            return null;
        }

        $data = json_decode($response, true);
        $parts = $data["candidates"][0]["content"]["parts"] ?? null;

        if (!is_array($parts)) {
            return null;
        }

        $texts = [];
        foreach ($parts as $part) {
            $text = trim((string) ($part["text"] ?? ""));
            if ($text !== "") {
                $texts[] = $text;
            }
        }

        if (empty($texts)) {
            return null;
        }

        return self::finalizeHostedReply(implode(" ", $texts));
    }

    private static function callPythonFallback($message) {
        $data = json_encode(["message" => $message]);

        $ch = curl_init("http://127.0.0.1:5000/chat");
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json"]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);

        $response = curl_exec($ch);
        curl_close($ch);

        if (!$response) {
            return null;
        }

        $pythonReply = json_decode($response, true);
        $reply = $pythonReply["reply"] ?? null;

        if (!$reply || strtolower(trim($reply)) === "sorry, i could not understand.") {
            return null;
        }

        return trim($reply);
    }

    public static function getReply($message, $userContext = []) {
        self::$lastReplyMeta = [
            "source" => "unknown"
        ];

        if (!isset($_SESSION)) {
            self::clearConversationHistory();
        }

        $studentId = $userContext["student_id"] ?? null;
        $roleKey = $userContext["role_key"] ?? "student";
        $studentName = $studentId ? self::getStudentName($studentId) : null;
        if ($studentName && empty($userContext["full_name"])) {
            $userContext["full_name"] = $studentName;
        }

        $knowledgeItems = KnowledgeBaseService::getRelevantKnowledge($roleKey, $message);

        foreach (self::getProviderOrder() as $provider) {
            $reply = null;

            if ($provider === "gemini") {
                $reply = self::callGemini($message, $userContext, $knowledgeItems);
            }

            if ($provider === "openai") {
                $reply = self::callOpenAI($message, $userContext, $knowledgeItems);
            }

            if ($reply) {
                self::$lastReplyMeta = [
                    "source" => $provider
                ];
                self::saveConversationTurn($message, $reply);
                return $reply;
            }
        }

        $pythonReply = self::callPythonFallback($message);
        if ($pythonReply) {
            self::$lastReplyMeta = [
                "source" => "python_fallback"
            ];
            self::saveConversationTurn($message, $pythonReply);
            return $pythonReply;
        }

        self::$lastReplyMeta = [
            "source" => "local_fallback"
        ];
        $fallbackReply = self::localFallback($message, $userContext);
        self::saveConversationTurn($message, $fallbackReply);
        return $fallbackReply;
    }
}
