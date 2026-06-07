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

    public static function buildConfig($sessionTokenPayload, $language = "multi", $studentName = "") {
        $publicKey = self::getEnvValue("VAPI_PUBLIC_KEY");
        $assistantId = self::getEnvValue("VAPI_ASSISTANT_ID");
        $webhookUrl = self::getEnvValue("VAPI_WEBHOOK_URL", self::defaultWebhookUrl());
        $studentName = self::cleanStudentName($studentName);
        $assistant = self::buildAssistantObject($webhookUrl, $sessionTokenPayload["token"] ?? "", $language, $studentName);

        return [
            "enabled" => $publicKey !== "",
            "public_key" => $publicKey,
            "assistant_id" => $assistantId,
            "assistant" => $assistant,
            "assistant_overrides" => [
                "recordingEnabled" => false,
                "variableValues" => [
                    "student_session_token" => $sessionTokenPayload["token"] ?? "",
                    "voice_language" => $language ?: "multi",
                    "student_name" => $studentName !== "" ? $studentName : "there"
                ]
            ],
            "session_token" => $sessionTokenPayload["token"] ?? "",
            "expires_in" => VapiSessionService::getTtlSeconds(),
            "setup_hint" => $publicKey === "" ? "Set VAPI_PUBLIC_KEY in backend/.env before using Vapi." : null
        ];
    }

    public static function buildAssistantObject($webhookUrl, $sessionToken, $language = "multi", $studentName = "") {
        $modelProvider = self::getEnvValue("VAPI_MODEL_PROVIDER", "openai");
        $model = self::getEnvValue("VAPI_MODEL", "gpt-4o-mini");
        $voiceProvider = self::getEnvValue("VAPI_VOICE_PROVIDER", "openai");
        $voiceId = self::getEnvValue("VAPI_VOICE_ID", "shimmer");
        $voiceModel = self::getEnvValue("VAPI_VOICE_MODEL", $voiceProvider === "openai" ? "gpt-4o-mini-tts" : "");
        $transcriberProvider = self::getEnvValue("VAPI_TRANSCRIBER_PROVIDER", "deepgram");
        $transcriberModel = self::getEnvValue("VAPI_TRANSCRIBER_MODEL", "nova-3");

        return [
            "name" => "GMU Multilingual VoiceBot",
            "firstMessage" => self::firstMessage($language, $studentName),
            "firstMessageMode" => "assistant-speaks-first",
            "model" => [
                "provider" => $modelProvider,
                "model" => $model,
                "temperature" => 0.2,
                "messages" => [[
                    "role" => "system",
                    "content" => self::systemPrompt($sessionToken, $language, $studentName)
                ]],
                "tools" => [self::gmuToolDefinition($webhookUrl)]
            ],
            "transcriber" => self::transcriberConfig($transcriberProvider, $transcriberModel, $language),
            "voice" => self::voiceConfig($voiceProvider, $voiceId, $voiceModel),
            "server" => [
                "url" => $webhookUrl
            ]
        ];
    }


    private static function cleanStudentName($name) {
        $name = trim(preg_replace('/\s+/', ' ', (string) $name));
        if ($name === "") {
            return "";
        }
        return preg_replace('/[^\p{L}\p{M}\p{N}\s.\'-]/u', '', $name) ?: "";
    }
    private static function transcriberConfig($provider, $transcriberModel, $language) {
        $transcriber = [
            "provider" => $provider,
            "language" => self::vapiLanguage($language, $provider, $transcriberModel)
        ];

        if (strtolower((string) $provider) === "assembly-ai") {
            if ($transcriberModel !== "") {
                $transcriber["speechModel"] = $transcriberModel;
            }
            return $transcriber;
        }

        if ($transcriberModel !== "") {
            $transcriber["model"] = $transcriberModel;
        }
        return $transcriber;
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

    private static function systemPrompt($sessionToken, $language = "multi", $studentName = "") {
        $selectedLanguage = in_array($language, ["en", "hi", "kn"], true) ? $language : "multi";
        $selectedLanguageText = ["en" => "English", "hi" => "Hindi/Hinglish", "kn" => "Kannada/Kanglish", "multi" => "automatic multilingual"][$selectedLanguage] ?? "automatic multilingual";
        $studentName = self::cleanStudentName($studentName);
        return "You are GMU VoiceBot, the official ERP voice assistant for GM University students. The logged-in student name is " . ($studentName !== "" ? $studentName : "not available") . ". " .
            "Understand English, Hindi, Kannada, Hinglish, Kanglish, and mixed student speech. Current selected voice language is " . $selectedLanguageText . ". If selected voice language is Kannada/Kanglish, reply in Kannada/Kanglish even when the transcript is roman text like page ge hogu. If selected voice language is Hindi/Hinglish, reply in Hindi/Hinglish. If selected voice language is English, reply in English. If selected voice language is automatic multilingual, reply in the dominant language used by the student. Keep replies short and natural. Never say you cannot speak Kannada or Hindi; use natural Kanglish or Hinglish if needed. " .
            "You must call gmu_voice_assistant for all student data and ERP queries. This includes attendance, result, results, marks, marksheet, grade sheet, SGPA, CGPA, semester result, latest result, previous result, all results, fees, tuition deadlines, hostel application status, class cancellation notices, certificates, registration, profile, grievance, courses, faculty, campus information, documents, and university-related requests. " .
            "Never answer result, marks, marksheet, grade, SGPA, CGPA, semester result, or latest result questions from your own knowledge. Always call gmu_voice_assistant first so the backend can fetch the student's result data or open the result page. " .
            "Do not call the tool for greetings, thanks, okay, yes, no, or casual small talk. " .
            "For tool calls, send query as the exact user request. Use hi only when the user's speech is primarily Hindi. Use kn only when the user's speech is primarily Kannada. Use en for English, even if spoken with an Indian accent. Use multi only when the sentence genuinely mixes multiple languages. Send session_token exactly as: " . $sessionToken . ". " .
            "Navigation safety: never navigate unless the user explicitly asks to open, go, navigate, show, or return to a page. Result requests are different: if the user asks to show, check, view, display, tell, see, get, latest, previous, semester, SGPA, CGPA, or marks with result terms, call the tool and let the backend decide the result action. Ignore incomplete or partial transcript fragments when deciding navigation. Do not infer navigation from a page name alone. Do not repeat navigation commands. Only navigate once per user request. If the same command was already executed recently, do not repeat it. " .
            "ERP support tickets: if the student reports an ERP problem such as ERP not working, login issue, attendance not updated, payment failed, fee payment problem, marks or result not showing, registration error, certificate download problem, hall ticket issue, profile issue, or VoiceBot issue, call gmu_voice_assistant. If the issue description is too short, ask one brief follow-up question: Please briefly explain the problem. Do not invent ticket IDs; only speak the ticket ID returned by the backend. " .
            "Language switching: if the user asks to speak in Kannada, Hindi, or English, call the tool once and speak exactly its reply. " .
            "Pronunciation rules: When speaking Kannada, pronounce Kannada words naturally and avoid English-style pronunciation. When speaking Hindi, pronounce Hindi words naturally and avoid English-style pronunciation. When speaking Kanglish or Hinglish, preserve the natural Indian student speaking style. Numbers and digits: never compress numbers unless they represent a year, percentage, amount, or semester. For USN, registration numbers, hall ticket numbers, receipt numbers, phone numbers, OTPs, IDs, roll numbers, and tokens, read every character separately. Always pronounce digit 0 clearly. Never skip leading zeros. Never convert 001 into one. In English say zero: GMU22MCA001 should be spoken as G M U two two M C A zero zero one; 90045 as nine zero zero four five; OTP 1050 as one zero five zero. In Kannada/Kanglish say sonne for 0: 001 as sonne sonne ondu, 005 as sonne sonne aidu, 1001 as ondu sonne sonne ondu. In Hindi/Hinglish say shoonya for 0: 001 as shoonya shoonya ek, 005 as shoonya shoonya paanch. For Kannada roman words, prefer clear long-vowel spelling when needed: say aagide, maadide, hogi, torisi, heli, nimge, sahaya. Do not pronounce aagide like azide. Academic terms must be read letter by letter: MCA as M C A, CSE as C S E, AI as A I, ERP as E R P, GMU as G M U, SEE as S E E, CGPA as C G P A, SGPA as S G P A. Never merge abbreviations into words. For attendance, marks, fees, USN, semester results, certificates, and registration details, read values slowly and clearly. Read IDs character by character. Read monetary amounts naturally in the student's language, and do not skip zeros. " .
            "After a tool response, speak only the reply field. If client_action exists, briefly confirm once and stop speaking further. Do not mention backend, API, database, tool calls, or internal routing.";
    }
    private static function firstMessage($language, $studentName = "") {
        $studentName = self::cleanStudentName($studentName);
        $namePart = $studentName !== "" ? " " . $studentName : "";
        if ($language === "hi") {
            return "Namaste" . $namePart . ". GM University ERP Assistant mein aapka swagat hai. Aap result, attendance, fees, registration, certificates, ya kisi bhi ERP service ke baare mein pooch sakte hain. Main aapki kaise madad kar sakti hoon?";
        }
        if ($language === "kn") {
            return "Namaskara" . $namePart . ". GM University ERP Assistant ge swagata. Neevu results, attendance, fees, registration, certificates, athava ERP service bagge kelabahudu. Nimge hege sahaya madali?";
        }
        return "Hello" . $namePart . ". Welcome back to your GM University ERP Assistant. You can ask me about results, attendance, fees, registration, certificates, or any ERP service. How can I help you today?";
    }

    private static function vapiLanguage($language, $transcriberProvider = "", $transcriberModel = "") {
        $provider = strtolower((string) $transcriberProvider);
        $model = strtolower((string) $transcriberModel);

        if ($provider === "deepgram" && $model === "flux-general-en") {
            return "en";
        }

        if (in_array($provider, ["assembly-ai", "google"], true)) {
            return "multi";
        }

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




