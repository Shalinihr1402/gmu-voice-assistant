<?php

require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/KnowledgeBaseService.php";

class LlmService {

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

        return "You are GMU VoiceBot, a role-aware university assistant. Reply naturally, briefly, and politely in 1 to 3 sentences. Use the user's role to keep answers relevant. Never invent permissions, records, or personal facts. If the user asks for something outside their role context, say so clearly and redirect helpfully. Keep replies voice-friendly and easy to hear. " . implode(" ", $identitySummary) . $knowledgeSummary;
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
            "/\b(joke|funny)\b/" => "Here is one. Why did the student bring a ladder to class? Because the grades were too high.",
            "/\b(marriage|address|wedding)\b/" => "I cannot know your personal future, but I can help you with your academic details.",
            "/\b(love|girlfriend|boyfriend)\b/" => "I am better with university questions than love advice, but I am here to help however I can.",
            "/\b(hello|hi|hey)\b/" => "Hello. How can I help you today?",
            "/\b(good morning|good afternoon|good evening)\b/" => "Good day. How can I assist you?",
            "/\b(bye|goodbye|see you)\b/" => "Goodbye. Feel free to ask me anything related to your {$role} access and university workflow anytime.",
            "/\b(help|assist|support)\b/" => self::getRoleHelpMessage($role),

            "/\b(thank you|thanks)\b/" => "You are welcome. I am happy to help."
        ];  

        foreach ($patterns as $pattern => $reply) {
            if (preg_match($pattern, $message)) {
                return $reply;
            }
        }

        return self::getRoleHelpMessage($role);
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

        $payload = json_encode([
            "model" => $model,
            "input" => [[
                "role" => "system",
                "content" => [[
                    "type" => "input_text",
                    "text" => $systemPrompt
                ]]
            ], [
                "role" => "user",
                "content" => [[
                    "type" => "input_text",
                    "text" => $message
                ]]
            ]],
            "max_output_tokens" => 120
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

        if (isset($data["output_text"]) && trim($data["output_text"]) !== "") {
            return trim($data["output_text"]);
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

                if ($text !== "") {
                    return trim($text);
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

        $payload = json_encode([
            "system_instruction" => [
                "parts" => [[
                    "text" => $systemPrompt
                ]]
            ],
            "contents" => [[
                "role" => "user",
                "parts" => [[
                    "text" => $message
                ]]
            ]],
            "generationConfig" => [
                "temperature" => 0.4,
                "maxOutputTokens" => 120
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

        return trim(implode(" ", $texts));
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
                return $reply;
            }
        }

        $pythonReply = self::callPythonFallback($message);
        if ($pythonReply) {
            return $pythonReply;
        }

        return self::localFallback($message, $userContext);
    }
}
