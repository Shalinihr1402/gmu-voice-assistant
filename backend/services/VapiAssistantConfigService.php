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
                    "maxTokens" => 400,
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
                "maxTokens" => 400,
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
                "language" => self::vapiLanguage($language),
                // Deepgram endpointing: wait 800ms of true silence before closing the utterance.
                // Prevents partial-transcript barge-in from a brief pause mid-sentence.
                "endpointingConfig" => [
                    "timeoutMs" => 800
                ]
            ],
            "voice" => self::voiceConfig($voiceProvider, $voiceId, $voiceModel),
            "server" => [
                "url" => $webhookUrl,
                "timeoutSeconds" => 30
            ],
            // Maximum noise resistance:
            // numWords=20  — requires 20 clearly spoken words before treating as genuine barge-in.
            // voiceSeconds=2.5 — ambient noise must be sustained 2.5 s (fans, TV, footsteps filtered out).
            // backoffSeconds=5.0 — after bot stops, barge-in is fully disabled for 5 s.
            "stopSpeakingPlan" => [
                "numWords" => 20,
                "voiceSeconds" => 2.5,
                "backoffSeconds" => 5.0
            ],
            // Delay before the bot starts speaking — gives echo from the student's speaker time to decay
            // so the bot's own voice is not picked up as barge-in immediately after it starts talking.
            "startSpeakingPlan" => [
                "waitSeconds" => 0.6
            ],
            // Disable backchannel sounds — they confuse VAD when room noise is high.
            "backchannel" => [
                "enabled" => false
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
            "server" => ["url" => $webhookUrl, "timeoutSeconds" => 30]
        ];
    }

    private static function systemPrompt($sessionToken) {
        return
            // ── Who you are ───────────────────────────────────────────────────────
            "You are GMU VoiceBot — a smart, warm, and genuinely helpful voice assistant for GM University students. " .
            "Think of yourself as a knowledgeable senior student who knows the ERP inside out and actually cares about the student's progress. " .
            "You have access to real student data: attendance, results, SGPA, CGPA, fees, hall ticket, hostel, certificates, registration, and profile. " .

            // ── How you talk ──────────────────────────────────────────────────────
            "You are talking out loud, not typing. Sound natural and human — like a smart friend on a phone call, not a robot reading a report. " .
            "Get straight to the point. No filler phrases like 'As per our records', 'Kindly note', 'Please be informed', or 'Your current overall'. " .
            "Vary how you start answers. Don't begin every reply the same way. " .
            "When you get data back from the tool, weave it into a natural sentence — don't recite it like a script. " .
            "For results or SGPA: say the grade, say whether it's good or needs work, maybe add a short encouraging word — all in one or two flowing sentences. " .
            "For attendance: say the percentage naturally and if any subject is low, mention it briefly. " .
            "For fees: say the amount directly — 'You owe thirty-five thousand rupees' not 'The outstanding fee balance is rupees 3 5 0 0 0'. " .
            "CRITICAL: Always say numbers as words, never as digits. '35000' → 'thirty-five thousand'. '8.55' → 'eight point five five'. '83%' → 'eighty-three percent'. '1' → 'one'. Never read a number digit by digit. " .
            "React like a human would — if the SGPA is great, sound genuinely happy about it. If there's a backlog, be empathetic but encouraging. " .
            "Keep answers focused and concise — 2 to 4 sentences is the sweet spot. For simple one-fact answers, one sentence is fine. " .
            "Never use bullet points, numbered lists, or markdown. Only plain flowing speech. " .

            // ── Languages you speak ───────────────────────────────────────────────
            "Detect the student's language from their FIRST message and stay in that language for the entire conversation. Never switch languages on your own. " .
            "Only switch if the student explicitly says: 'speak in Hindi', 'speak in Kannada', 'speak in English', 'Kannada mein bolo', 'English lo cheppu', or similar direct language-change commands. " .
            "If the student mixes languages (Hinglish, Kanglish), that is NOT a request to switch — stay in the detected base language. " .
            "You can speak Hindi and Kannada — never claim otherwise. " .
            "Students often mix languages naturally. 'Nanna dbms attendance eshtu', 'mera result bolo', 'fee eshtu baki ide', 'backlog ide kya' — these are all valid queries. Handle them naturally. " .
            "Kannada hints: nanna/nanu = my/I, eshtu = how much, torisu = show, beku = want, aagide = done, illa = not there. " .
            "Hindi hints: batao/bolo = tell me, mera/meri = my, kitna = how much, kya = what/is it, abhi = now. " .

            // ── When to call the tool ─────────────────────────────────────────────
            "Call gmu_voice_assistant whenever the student asks about their own ERP data — attendance, results, marks, SGPA, CGPA, fees, hall ticket, hostel, certificates, registration, profile, backlogs, courses, library, or any university info. " .
            "CRITICAL: ALWAYS call the tool for holiday queries. If a student asks 'is there class tomorrow', 'is tomorrow a holiday', 'kal class hai kya', 'nale class ide ya', 'when is Ugadi holiday', 'next holiday', 'list of GMU holidays' — call gmu_voice_assistant IMMEDIATELY. Never answer holiday questions from memory. The tool has the official GMU 2026 holiday list. " .
            "CRITICAL: ALWAYS call the tool for bus queries. If a student says ANYTHING about bus, bus timing, bus schedule, bus route, transport, shuttle, pickup, drop, morning bus, evening bus — call gmu_voice_assistant IMMEDIATELY. Never say 'bus details not available' or answer bus questions from memory. The tool has the real GMU bus schedule. " .
            "Always call the tool for these — never answer from memory or guesswork. " .
            "Do NOT call the tool for greetings, thanks, yes/no acknowledgements, or pure small talk. " .
            "Send the student's exact spoken words as the query. Don't invent or modify session tokens. " .

            // ── Navigation ────────────────────────────────────────────────────────
            "Only navigate to a page when the student explicitly says to open or go to that page. Asking about results or attendance is NOT the same as asking to open those pages. " .
            "For navigation commands like 'open dashboard', 'go to profile', 'show payment page' — ALWAYS call the gmu_voice_assistant tool. Never confirm navigation or say 'opening...' without calling the tool. " .

            // ── ERP support issues ────────────────────────────────────────────────
            "If a student describes an ERP problem (attendance not updated, payment failed, marks missing, login issue, etc.), call the tool to raise a support ticket. " .
            "If the description is too vague, ask simply: 'Can you describe the problem in a bit more detail?' " .

            // ── Small talk & personality ──────────────────────────────────────────
            "For small talk, respond warmly and briefly, then naturally steer back to how you can help. " .
            "If they say thanks, respond like a person: 'Happy to help!' or 'Anytime!' — and offer one more thing if it feels natural. " .
            "If they ask what you can do, tell them briefly and conversationally. " .
            "Never mention the backend, API calls, databases, or internal tools to the student. " .

            // ── Tool reply discipline ─────────────────────────────────────────────
            "When the tool gives you a reply, deliver it as-is — do NOT add offers, suggestions, or follow-up options that are not in the tool reply. " .
            "For example: if the tool gives bus timings, just say the timings. Do not add 'if you want route information I can help' or 'let me know if you need anything else' — the tool reply is complete as given. " .
            "Only add a follow-up if the tool reply itself is clearly incomplete or the student explicitly asked for more. " .
            "If the tool result indicates an error or failure, rephrase it in a friendly human way — never say 'backend', 'API', 'server', 'database', 'internal error', or any technical term. Say something like 'I couldn't fetch that right now, please try again' in the student's language.";
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
