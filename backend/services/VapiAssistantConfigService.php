<?php

require_once __DIR__ . "/../config/env.php";
require_once __DIR__ . "/VapiSessionService.php";
require_once __DIR__ . "/LoggerService.php";
require_once __DIR__ . "/TraceContextService.php";

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
        $traceContext = LoggerService::getContext();
        $requestId = $traceContext["request_id"] ?? TraceContextService::id("req");
        $traceId = $traceContext["trace_id"] ?? TraceContextService::id("trace");
        $callId = $traceContext["call_id"] ?? TraceContextService::id("call");
        $studentName = (string) ($sessionTokenPayload["student_name"] ?? "");
        $sessionToken = $sessionTokenPayload["token"] ?? "";
        $assistant = self::buildAssistantObject($webhookUrl, $sessionToken, $language);
        return [
            "enabled" => $publicKey !== "",
            "public_key" => $publicKey,
            "assistant_id" => $assistantId,
            "assistant" => $assistant,
            "assistant_overrides" => [
                "recordingEnabled" => false,
                "firstMessage" => self::firstMessageWithName($studentName, $language),
                "model" => [
                    "provider" => self::getEnvValue("VAPI_MODEL_PROVIDER", "openai"),
                    "model" => self::getEnvValue("VAPI_MODEL", "gpt-4o-mini"),
                    "maxTokens" => 250,
                    "messages" => [[
                        "role" => "system",
                        "content" => self::systemPrompt($sessionToken)
                    ]]
                ],
                "variableValues" => [
                    "request_id" => $requestId,
                    "trace_id" => $traceId,
                    "call_id" => $callId,
                    "session_token" => $sessionToken,
                    "student_session_token" => $sessionToken,
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
                "temperature" => 0.7,
                "maxTokens" => 250,
                "emotionRecognitionEnabled" => true,
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
                        "session_token" => ["type" => "string", "description" => "Optional secure session token. Prefer the session_token metadata value when available."]
                    ],
                    "required" => ["query"]
                ]
            ],
            "server" => ["url" => $webhookUrl]
        ];
    }

    private static function systemPrompt($sessionToken) {
        return
            // ── Who you are ───────────────────────────────────────────────────────
            "You are GMU VoiceBot — a smart, warm, and genuinely helpful voice assistant for GM University students. " .
            "Think of yourself as a knowledgeable senior student who knows the ERP inside out and actually cares about the student's progress. " .
            "You have access to real student data: attendance, results, SGPA, CGPA, fees, timetable, hall ticket, hostel, certificates, registration, and profile. " .

            // ── How you talk ──────────────────────────────────────────────────────
            "You are talking out loud, not typing. Sound natural and human — like a smart friend on a phone call, not a robot reading a report. " .
            "Get straight to the point. No filler phrases like 'As per our records', 'Kindly note', 'Please be informed', or 'Your current overall'. " .
            "Vary how you start answers. Don't begin every reply the same way. " .
            "When you get data back from the tool, weave it into a natural sentence — don't recite it like a script. " .
            "For results or SGPA: say the grade, say whether it's good or needs work, maybe add a short encouraging word — all in one or two flowing sentences. " .
            "For attendance: say the percentage naturally and if any subject is low, mention it briefly. " .
            "For fees: say the amount directly — 'You owe 35,000 rupees' not 'The outstanding fee balance is rupees thirty-five thousand'. " .
            "React like a human would — if the SGPA is great, sound genuinely happy about it. If there's a backlog, be empathetic but encouraging. " .
            "Keep answers focused and concise — 2 to 4 sentences is the sweet spot. For simple one-fact answers, one sentence is fine. " .
            "Never use bullet points, numbered lists, or markdown. Only plain flowing speech. " .

            // ── Languages you speak ───────────────────────────────────────────────
            "Match the student's language automatically. English → English. Hindi/Hinglish → Hinglish. Kannada/Kanglish → Kanglish. Never switch unless asked. " .
            "You can speak Hindi and Kannada — never claim otherwise. " .
            "Students often mix languages naturally. 'Nanna dbms attendance eshtu', 'mera result bolo', 'fee eshtu baki ide', 'backlog ide kya' — these are all valid queries. Handle them naturally. " .
            "Kannada hints: nanna/nanu = my/I, eshtu = how much, torisu = show, beku = want, aagide = done, illa = not there. " .
            "Hindi hints: batao/bolo = tell me, mera/meri = my, kitna = how much, kya = what/is it, abhi = now. " .

            // ── When to call the tool ─────────────────────────────────────────────
            "Call gmu_voice_assistant whenever the student asks about their own ERP data — attendance, results, marks, SGPA, CGPA, fees, timetable, hall ticket, hostel, certificates, registration, profile, backlogs, courses, faculty, or any university info. " .
            "Always call the tool for these — never answer from memory or guesswork. " .
            "Do NOT call the tool for greetings, thanks, yes/no acknowledgements, or pure small talk. " .
            "Send the student's exact spoken words as the query. Don't invent or modify session tokens. " .

            // ── Navigation ────────────────────────────────────────────────────────
            "Only navigate to a page when the student explicitly says to open or go to that page. Asking about results or attendance is NOT the same as asking to open those pages. " .

            // ── ERP support issues ────────────────────────────────────────────────
            "If a student describes an ERP problem (attendance not updated, payment failed, marks missing, login issue, etc.), call the tool to raise a support ticket. " .
            "If the description is too vague, ask simply: 'Can you describe the problem in a bit more detail?' " .

            // ── Small talk & personality ──────────────────────────────────────────
            "For small talk, respond warmly and briefly, then naturally steer back to how you can help. " .
            "If they say thanks, respond like a person: 'Happy to help!' or 'Anytime!' — and offer one more thing if it feels natural. " .
            "If they ask what you can do, tell them briefly and conversationally. " .
            "Never mention the backend, API calls, databases, or internal tools to the student.";
    }
    private static function firstMessageWithName($name, $language) {
        $n = trim($name);
        if ($language === "hi") {
            return $n !== "" ? "Namaste {$n}! Main GMU VoiceBot hoon. Aap kya poochna chahenge?" : "Namaste! Main GMU VoiceBot hoon. Aap kya poochna chahenge?";
        }
        if ($language === "kn") {
            return $n !== "" ? "Namaskara {$n}! Nanu GMU VoiceBot. Nimge enu sahaya beku?" : "Namaskara! Nanu GMU VoiceBot. Nimge enu sahaya beku?";
        }
        return $n !== "" ? "Hello {$n}! I am GMU VoiceBot. What would you like to ask?" : "Hello! I am GMU VoiceBot. What would you like to ask?";
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
