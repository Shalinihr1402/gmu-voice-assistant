<?php

require_once __DIR__ . "/../config/env.php";
require_once __DIR__ . "/VapiSessionService.php";

class VapiAssistantConfigService {
    public static function getEnvValue($key, $default = "") {
        $value = getenv($key);
        if ($value === false || $value === "") {
            $value = $_SERVER[$key] ?? $_ENV[$key] ?? $default;
        }
        return $value === null ? $default : (string) $value;
    }

    public static function buildConfig($sessionTokenPayload, $language = "multi") {
        $publicKey = self::getEnvValue("VAPI_PUBLIC_KEY");
        $assistantId = self::getEnvValue("VAPI_ASSISTANT_ID");
        $webhookUrl = self::getEnvValue("VAPI_WEBHOOK_URL", self::defaultWebhookUrl());
        $assistant = self::buildAssistantObject($webhookUrl, $sessionTokenPayload["token"] ?? "", $language);

        return [
            "enabled" => $publicKey !== "",
            "public_key" => $publicKey,
            "assistant_id" => $assistantId,
            "assistant" => $assistant,
            "assistant_overrides" => [
                "recordingEnabled" => false,
                "variableValues" => [
                    "student_session_token" => $sessionTokenPayload["token"] ?? "",
                    "voice_language" => $language ?: "multi"
                ]
            ],
            "session_token" => $sessionTokenPayload["token"] ?? "",
            "expires_in" => VapiSessionService::getTtlSeconds(),
            "setup_hint" => $publicKey === "" ? "Set VAPI_PUBLIC_KEY in backend/.env before using Vapi." : null
        ];
    }

    public static function buildAssistantObject($webhookUrl, $sessionToken, $language = "multi") {
        $modelProvider = self::getEnvValue("VAPI_MODEL_PROVIDER", "openai");
        $model = self::getEnvValue("VAPI_MODEL", "gpt-4o-mini");
        $voiceProvider = self::getEnvValue("VAPI_VOICE_PROVIDER", "openai");
        $voiceId = self::getEnvValue("VAPI_VOICE_ID", "shimmer");
        $voiceModel = self::getEnvValue("VAPI_VOICE_MODEL", $voiceProvider === "openai" ? "gpt-4o-mini-tts" : "");
        $transcriberProvider = self::getEnvValue("VAPI_TRANSCRIBER_PROVIDER", "deepgram");
        $transcriberModel = self::getEnvValue("VAPI_TRANSCRIBER_MODEL", "nova-3");

        return [
            "name" => "GMU Multilingual VoiceBot",
            "firstMessage" => self::firstMessage($language),
            "model" => [
                "provider" => $modelProvider,
                "model" => $model,
                "temperature" => 0.2,
                "messages" => [[
                    "role" => "system",
                    "content" => self::systemPrompt($sessionToken)
                ]],
                "tools" => [self::gmuToolDefinition($webhookUrl)]
            ],
            "transcriber" => [
                "provider" => $transcriberProvider,
                "model" => $transcriberModel,
                "language" => self::vapiLanguage($language)
            ],
            "voice" => self::voiceConfig($voiceProvider, $voiceId, $voiceModel),
            "server" => [
                "url" => $webhookUrl
            ]
        ];
    }

    private static function voiceConfig($provider, $voiceId, $voiceModel) {
        $voice = [
            "provider" => $provider,
            "voiceId" => $voiceId
        ];
        if ($voiceModel !== "") {
            $voice["model"] = $voiceModel;
        }
        return $voice;
    }
    private static function gmuToolDefinition($webhookUrl) {
        return [
            "type" => "function",
            "function" => [
                "name" => "gmu_voice_assistant",
                "description" => "Required for all GMU ERP/student-data requests, including every result, marks, marksheet, grade, SGPA, CGPA, semester result, latest result, and result-page request. Also use for university requests and explicit page navigation. Do not use for greetings, thanks, filler, or casual acknowledgements.",
                "parameters" => [
                    "type" => "object",
                    "properties" => [
                        "query" => ["type" => "string", "description" => "The student's exact spoken request."],
                        "language" => ["type" => "string", "enum" => ["en", "hi", "kn", "multi"], "description" => "Detected or requested language."],
                        "session_token" => ["type" => "string", "description" => "Use the student_session_token from the system prompt exactly."]
                    ],
                    "required" => ["query", "session_token"]
                ]
            ],
            "server" => ["url" => $webhookUrl]
        ];
    }

    private static function systemPrompt($sessionToken) {
        return "You are GMU VoiceBot, the official ERP voice assistant for GM University students. " .
            "Understand English, Hindi, Kannada, Hinglish, Kanglish, and mixed student speech. Reply in the dominant language used by the student. If the student speaks mostly English, reply fully in English. Do not switch to Hindi or Kannada unless the user clearly speaks those languages. Keep replies short and natural. Never say you cannot speak Kannada or Hindi; use natural Kanglish or Hinglish if needed. " .
            "You must call gmu_voice_assistant for all student data and ERP queries. This includes attendance, result, results, marks, marksheet, grade sheet, SGPA, CGPA, semester result, latest result, previous result, all results, fees, tuition deadlines, hostel application status, class cancellation notices, certificates, registration, profile, grievance, courses, faculty, campus information, documents, and university-related requests. " .
            "Never answer result, marks, marksheet, grade, SGPA, CGPA, semester result, or latest result questions from your own knowledge. Always call gmu_voice_assistant first so the backend can fetch the student's result data or open the result page. " .
            "Do not call the tool for greetings, thanks, okay, yes, no, or casual small talk. " .
            "For tool calls, send query as the exact user request. Use hi only when the user's speech is primarily Hindi. Use kn only when the user's speech is primarily Kannada. Use en for English, even if spoken with an Indian accent. Use multi only when the sentence genuinely mixes multiple languages. Send session_token exactly as: " . $sessionToken . ". " .
            "Navigation safety: never navigate unless the user explicitly asks to open, go, navigate, show, or return to a page. Result requests are different: if the user asks to show, check, view, display, tell, see, get, latest, previous, semester, SGPA, CGPA, or marks with result terms, call the tool and let the backend decide the result action. Ignore incomplete or partial transcript fragments when deciding navigation. Do not infer navigation from a page name alone. Do not repeat navigation commands. Only navigate once per user request. If the same command was already executed recently, do not repeat it. " .
            "ERP support tickets: if the student reports an ERP problem such as ERP not working, login issue, attendance not updated, payment failed, fee payment problem, marks or result not showing, registration error, certificate download problem, hall ticket issue, profile issue, or VoiceBot issue, call gmu_voice_assistant. If the issue description is too short, ask one brief follow-up question: Please briefly explain the problem. Do not invent ticket IDs; only speak the ticket ID returned by the backend. " .
            "Language switching: if the user asks to speak in Kannada, Hindi, or English, call the tool once and speak exactly its reply. " .
            "After a tool response, speak only the reply field. If client_action exists, briefly confirm once and stop speaking further. Do not mention backend, API, database, tool calls, or internal routing.";
    }
    private static function firstMessage($language) {
        if ($language === "hi") {
            return "Namaste, main GMU VoiceBot hoon. Aap kya poochna chahenge?";
        }
        if ($language === "kn") {
            return "Namaskara, nanu GMU VoiceBot. Nimge enu sahaya beku?";
        }
        return "Hello, I am GMU VoiceBot. What would you like to ask?";
    }

    private static function vapiLanguage($language) {
        if ($language === "hi") return "hi";
        if ($language === "kn") return "kn";
        if ($language === "en") return "en";
        return "multi";
    }

    private static function defaultWebhookUrl() {
        $https = (!empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off");
        $scheme = $https ? "https" : "http";
        $host = $_SERVER["HTTP_HOST"] ?? "localhost:8080";
        $scriptDir = rtrim(str_replace("\\", "/", dirname($_SERVER["SCRIPT_NAME"] ?? "/gmu-voice-assistant/backend")), "/");
        return $scheme . "://" . $host . $scriptDir . "/vapiWebhook.php";
    }
}
