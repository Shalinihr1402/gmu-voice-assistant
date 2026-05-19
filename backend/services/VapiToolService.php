<?php

require_once __DIR__ . "/VapiSessionService.php";
require_once __DIR__ . "/VapiAssistantConfigService.php";
require_once __DIR__ . "/LlmService.php";

class VapiToolService {
    public static function extractToolCalls($payload) {
        $message = $payload["message"] ?? $payload;
        if (!is_array($message)) {
            return [];
        }

        if (isset($message["toolCalls"]) && is_array($message["toolCalls"])) {
            return $message["toolCalls"];
        }

        if (isset($message["toolCallList"]) && is_array($message["toolCallList"])) {
            return $message["toolCallList"];
        }

        if (isset($message["toolCall"]) && is_array($message["toolCall"])) {
            return [$message["toolCall"]];
        }

        return [];
    }

    public static function buildToolResults($payload) {
        $results = [];
        foreach (self::extractToolCalls($payload) as $toolCall) {
            $results[] = self::handleToolCall($toolCall, $payload);
        }
        return ["results" => $results];
    }

    private static function handleToolCall($toolCall, $payload = []) {
        $id = $toolCall["id"] ?? $toolCall["toolCallId"] ?? "";
        $function = $toolCall["function"] ?? [];
        $args = $function["arguments"] ?? $toolCall["arguments"] ?? [];

        if (is_string($args)) {
            $decoded = json_decode($args, true);
            $args = is_array($decoded) ? $decoded : [];
        }

        $query = trim((string) ($args["query"] ?? $args["message"] ?? ""));
        $language = self::normalizeLanguage($args["language"] ?? "multi", $query);
        $sessionToken = self::extractSessionToken($args, $payload);

        if ($query === "") {
            return self::toolResult($id, [
                "reply" => "Please repeat your question.",
                "intent" => "EMPTY_QUERY",
                "route" => "clarification"
            ]);
        }

        $directLanguageSwitch = self::directLanguageSwitchResult($query, $language);
        if ($directLanguageSwitch) {
            return self::toolResult($id, $directLanguageSwitch);
        }

        $directPaymentHelp = self::directPaymentHelpResult($query, $language);
        if ($directPaymentHelp) {
            return self::toolResult($id, $directPaymentHelp);
        }

        $directNavigation = self::directNavigationResult($query, $language);
        if ($directNavigation) {
            return self::toolResult($id, $directNavigation);
        }

        $session = VapiSessionService::resolve($sessionToken);
        if (!$session) {
            $session = VapiSessionService::latestValidSession();
        }

        if (!$session) {
            return self::toolResult($id, [
                "reply" => self::sessionExpiredReply($language),
                "intent" => "SESSION_EXPIRED",
                "route" => "auth"
            ]);
        }

        $directCourseCode = self::directCourseCodeResult($query, $language, $session["session_id"] ?? "");
        if ($directCourseCode) {
            return self::toolResult($id, $directCourseCode);
        }

        $directResultNavigation = self::directResultNavigationResult($query, $language, $session["session_id"] ?? "");
        if ($directResultNavigation) {
            return self::toolResult($id, $directResultNavigation);
        }

        $apiResponse = self::callExistingApi($query, $language, $session["session_id"] ?? "");
        $result = self::shapeResult($apiResponse, $query, $language);
        return self::toolResult($id, $result);
    }


    private static function sessionExpiredReply($language) {
        if ($language === "hi") return "Aapka secure voice session expire ho gaya. Page refresh karke phir try kijiye.";
        if ($language === "kn") return "Nimma secure voice session expire agide. Page refresh madi matte try madi.";
        return "Your secure voice session expired. Please refresh the page and try again.";
    }
    private static function extractSessionToken($args, $payload) {
        $candidates = [
            $args["session_token"] ?? null,
            $args["student_session_token"] ?? null,
            $payload["message"]["call"]["assistantOverrides"]["variableValues"]["student_session_token"] ?? null,
            $payload["message"]["call"]["assistantOverrides"]["variableValues"]["session_token"] ?? null,
            $payload["message"]["assistantOverrides"]["variableValues"]["student_session_token"] ?? null,
            $payload["message"]["assistant"]["variableValues"]["student_session_token"] ?? null,
            $payload["call"]["assistantOverrides"]["variableValues"]["student_session_token"] ?? null,
            $payload["assistantOverrides"]["variableValues"]["student_session_token"] ?? null,
            $payload["variableValues"]["student_session_token"] ?? null
        ];

        foreach ($candidates as $candidate) {
            $token = trim((string) $candidate);
            if ($token !== "" && $token !== "{{student_session_token}}") {
                return $token;
            }
        }

        return "";
    }

    private static function directLanguageSwitchResult($query, $language) {
        $text = strtolower((string) $query);
        $normalized = preg_replace('/[^a-z0-9\s]+/u', ' ', $text);
        $normalized = preg_replace('/\s+/', ' ', (string) $normalized);
        $normalized = trim((string) $normalized);
        $asksToSpeak = preg_match('/\b(speak|speaak|speek|talk|baat|bath|batao|bolo|mathadu|maatadu|matadu|maathadu|matanadu|reply|answer|language|mode)\b/u', $normalized)
            || preg_match('/kannadadalli|hindiyalli|englishalli|hindi me|hindi mein|kannada me|kannada mein|english me|english mein/u', $normalized);
        if (!$asksToSpeak) {
            return null;
        }

        if (preg_match('/\b(kannada|kannadadalli|kannada dalli|kanada|kannad)\b/u', $normalized)) {
            return self::languageSwitchPayload('kn', 'Sari, innu Kannada/Kanglish nalli mataduttene. Nimma prashne heli.', $language);
        }
        if (preg_match('/\b(hindi|hindiyalli|hindi me|hindi mein|hindi mai|hindhi)\b/u', $normalized)) {
            return self::languageSwitchPayload('hi', 'Theek hai, ab main Hindi/Hinglish mein baat karunga. Aap apna sawaal poochiye.', $language);
        }
        if (preg_match('/\b(english|englishalli|english me|english mein|inglish)\b/u', $normalized)) {
            return self::languageSwitchPayload('en', 'Sure, I will continue in English. Please ask your question.', $language);
        }

        return null;
    }

    private static function languageSwitchPayload($languageKey, $reply, $currentLanguage) {
        return [
            "reply" => $reply,
            "intent" => "SWITCH_LANGUAGE",
            "route" => "settings",
            "language" => $languageKey,
            "client_action" => ["type" => "set_language", "language" => $languageKey],
            "suggestion" => null,
            "quick_actions" => [],
            "debug" => ["source" => "vapi_tool_service", "reply_source" => "direct_language_switch", "previous_language" => $currentLanguage]
        ];
    }
    private static function directCourseCodeResult($query, $language, $sessionId) {
        $text = strtolower((string) $query);
        if (!preg_match('/\b(course|cource|subject)\s*code\b|\bcode\s*(of|for)\b/u', $text)) {
            return null;
        }

        $subject = self::inferCourseCodeSubject($text);
        if ($subject === "") {
            return [
                "reply" => self::prepareReplyForVoice(self::localizeGenericReply("Please say the subject name clearly. For example, say: course code of DBMS.", $language), $language),
                "intent" => "GET_COURSE_CODE",
                "route" => "clarification",
                "language" => $language,
                "client_action" => null,
                "suggestion" => null,
                "quick_actions" => [],
                "debug" => ["source" => "vapi_tool_service", "reply_source" => "direct_course_code_clarification"]
            ];
        }

        $apiResponse = self::callExistingApi("course code for " . $subject, $language, $sessionId);
        $result = self::shapeResult($apiResponse, "course code for " . $subject, $language);
        $result["debug"]["reply_source"] = "direct_course_code";
        return $result;
    }

    private static function inferCourseCodeSubject($text) {
        $subjects = [
            "dbms" => "database management systems",
            "database management system" => "database management systems",
            "database management systems" => "database management systems",
            "operating system" => "operating systems",
            "operating systems" => "operating systems",
            "os" => "operating systems",
            "computer network" => "computer networks",
            "computer networks" => "computer networks",
            "cn" => "computer networks",
            "dbms laboratory" => "dbms laboratory",
            "lab" => "dbms laboratory",
            "artificial intelligence" => "artificial intelligence",
            "ai" => "artificial intelligence"
        ];

        foreach ($subjects as $needle => $subject) {
            if (preg_match('/\b' . preg_quote($needle, '/') . '\b/u', $text)) {
                return $subject;
            }
        }
        return "";
    }
    private static function directPaymentHelpResult($query, $language) {
        $text = strtolower((string) $query);
        $normalized = preg_replace('/[^a-z0-9\s]+/u', ' ', $text);
        $normalized = preg_replace('/\s+/', ' ', (string) $normalized);
        $normalized = trim((string) $normalized);

        $hasGrievance = (bool) preg_match('/\b(grievance|grievences|grievance|grevence|grevance|graviance|grevience|gradient|gradients|complaint|complain|issue|shikayat|sikayat)\b/u', $normalized);
        $hasPayment = (bool) preg_match('/\b(payment|pay|fees?|fee|receipt|transaction|amount|money|balance)\b/u', $normalized);
        $asksApply = (bool) preg_match('/\b(apply|raise|submit|file|register|where|how|need|want|create)\b/u', $normalized);
        $asksStatus = (bool) preg_match('/\b(status|result|track|check|view|see|history)\b/u', $normalized);
        $asksPayFees = (bool) preg_match('/\b(where|how|pay|payment|paid)\b/u', $normalized) && (bool) preg_match('/\b(fees?|fee|amount)\b/u', $normalized);
        $asksOptions = (bool) preg_match('/\b(options?|methods?|available|what can i pay|which fee)\b/u', $normalized) && $hasPayment;

        if ($hasGrievance && $asksStatus) {
            return self::helpReplyResult(
                "To check your grievance result, open Registration page, click Payment, then choose Grievance Result. Enter your USN or grievance number and submit.",
                "PAYMENT_GRIEVANCE_RESULT",
                $language
            );
        }

        if (($hasGrievance && ($asksApply || $hasPayment)) || preg_match('/\b(payment problem|fee not updated|fees not updated|payment deducted|money deducted|receipt not generated)\b/u', $normalized)) {
            return self::helpReplyResult(
                "To apply for a payment grievance, open Registration page, click Payment, then choose Payment Grievance. Fill USN, phone number, amount, transaction date, issue details, upload proof if available, and submit.",
                "APPLY_PAYMENT_GRIEVANCE",
                $language
            );
        }

        if ($asksPayFees || preg_match('/\b(how to pay fees|where to pay fees|pay my fees|fee payment|payment portal)\b/u', $normalized)) {
            return self::helpReplyResult(
                "To pay fees in ERP, open Registration page and scroll to Payment Details. Click Payment. In GM Smart Pay, select the required fee option, check the amount, and proceed with payment.",
                "PAY_FEES_HELP",
                $language
            );
        }

        if ($asksOptions) {
            return self::helpReplyResult(
                "In the Payment section you can use College or Tuition Fee, Hostel Fee, Skill or Late Registration Fee, Download Receipt, Payment Grievance, and Grievance Result.",
                "PAYMENT_OPTIONS_HELP",
                $language
            );
        }

        return null;
    }

    private static function helpReplyResult($reply, $intent, $language) {
        $reply = self::localizeHelpReply($reply, $intent, $language);
        return [
            "reply" => self::prepareReplyForVoice($reply, $language),
            "intent" => $intent,
            "route" => "help",
            "language" => $language,
            "client_action" => null,
            "suggestion" => self::localizeSuggestion("You can say: open payment portal, apply payment grievance, or check grievance result.", $language),
            "quick_actions" => [
                ["label" => "Open payment", "prompt" => "Open payment portal"],
                ["label" => "Apply grievance", "prompt" => "Apply payment grievance"]
            ],
            "debug" => ["source" => "vapi_tool_service", "reply_source" => "direct_payment_help"]
        ];
    }

    private static function localizeHelpReply($reply, $intent, $language) {
        if ($language === "hi") {
            $map = [
                "PAYMENT_GRIEVANCE_RESULT" => "Grievance result check karne ke liye Registration page kholiye, Payment par click kijiye, phir Grievance Result choose kijiye. USN ya grievance number daal kar submit kijiye.",
                "APPLY_PAYMENT_GRIEVANCE" => "Payment grievance apply karne ke liye Registration page kholiye, Payment par click kijiye, phir Payment Grievance choose kijiye. USN, phone number, amount, transaction date, issue details aur proof bhar kar submit kijiye.",
                "PAY_FEES_HELP" => "ERP mein fees pay karne ke liye Registration page kholiye aur Payment Details tak scroll kijiye. Payment click karke GM Smart Pay mein fee option select kijiye, amount check kijiye, aur payment proceed kijiye.",
                "PAYMENT_OPTIONS_HELP" => "Payment section mein College ya Tuition Fee, Hostel Fee, Skill ya Late Registration Fee, Download Receipt, Payment Grievance, aur Grievance Result options milte hain."
            ];
            return $map[$intent] ?? $reply;
        }
        if ($language === "kn") {
            $map = [
                "PAYMENT_GRIEVANCE_RESULT" => "Grievance result check madalu Registration page open madi, Payment click madi, nantara Grievance Result choose madi. USN athava grievance number haki submit madi.",
                "APPLY_PAYMENT_GRIEVANCE" => "Payment grievance apply madalu Registration page open madi, Payment click madi, nantara Payment Grievance choose madi. USN, phone number, amount, transaction date, issue details mattu proof iddare upload madi submit madi.",
                "PAY_FEES_HELP" => "ERP nalli fees pay madalu Registration page open madi, Payment Details varege scroll madi. Payment click madi, GM Smart Pay nalli fee option select madi, amount check madi, payment proceed madi.",
                "PAYMENT_OPTIONS_HELP" => "Payment section nalli College/Tuition Fee, Hostel Fee, Skill/Late Registration Fee, Download Receipt, Payment Grievance mattu Grievance Result options iruttave."
            ];
            return $map[$intent] ?? $reply;
        }
        return $reply;
    }

    private static function localizeSuggestion($suggestion, $language) {
        if ($language === "hi") return "Aap keh sakte hain: open payment portal, apply payment grievance, ya check grievance result.";
        if ($language === "kn") return "Neevu helabahudu: open payment portal, apply payment grievance, athava check grievance result.";
        return $suggestion;
    }
    private static function directResultNavigationResult($query, $language, $sessionId) {
        $text = strtolower((string) $query);
        if (!preg_match('/\b(result|results|marks|marksheet|grade\s*sheet|sgpa)\b/u', $text)) {
            return null;
        }
        if (!preg_match('/\b(show|open|view|display|check|go|navigate|take|get|see|tell|kholo|khol|dikhao|torisu|hogu|maadi|madi)\b/u', $text)) {
            return null;
        }

        $availability = self::loadResultAvailability($sessionId);
        $student = is_array($availability["student"] ?? null) ? $availability["student"] : [];
        $selections = is_array($availability["selections"] ?? null) ? $availability["selections"] : [];
        $currentSemester = (int) ($student["current_semester"] ?? 0);

        if (preg_match('/\b(all|overall|every)\s+(semester|sem)?\s*(result|results|marks)\b|\b(result|results|marks)\s+(for\s+)?(all|every)\s+(semester|sem)s?\b/u', $text)) {
            return [
                "reply" => self::allResultsNavigationReply($language),
                "intent" => "OPEN_ALL_RESULTS",
                "route" => "navigation",
                "language" => $language,
                "client_action" => ["type" => "navigate", "path" => "/results", "page" => "results"],
                "suggestion" => null,
                "quick_actions" => [],
                "debug" => ["source" => "vapi_tool_service", "reply_source" => "direct_result_navigation"]
            ];
        }

        $semester = self::extractSemester($text);
        if ($semester <= 0 && preg_match('/\b(current|latest|present)\s+(semester|sem)?\s*(result|results|marks)?\b/u', $text)) {
            $semester = $currentSemester;
        }
        if ($semester <= 0 && preg_match('/\b(previous|last)\s+(semester|sem)?\s*(result|results|marks)?\b/u', $text)) {
            $semester = max(1, $currentSemester - 1);
        }
        if ($semester <= 0) {
            return null;
        }
        $semesterSelections = array_values(array_filter($selections, static function ($selection) use ($semester) {
            return (int) ($selection["semester"] ?? 0) === $semester;
        }));

        $selected = self::pickResultSelection($semesterSelections, $text);
        $params = ["semester" => (string) $semester];
        if (!empty($student["usn"])) {
            $params["usn"] = strtoupper((string) $student["usn"]);
        }
        if ($selected) {
            $params["exam"] = (string) ($selected["exam"] ?? "");
            $params["year"] = (string) ($selected["year"] ?? "");
            $params["season"] = (string) ($selected["season"] ?? "");
        }

        $path = "/results?" . http_build_query(array_filter($params, static function ($value) {
            return $value !== "" && $value !== null;
        }));

        return [
            "reply" => self::resultNavigationReply($semester, (bool) $selected, $language),
            "intent" => "OPEN_FILTERED_RESULT",
            "route" => "navigation",
            "language" => $language,
            "client_action" => ["type" => "navigate", "path" => $path, "page" => "results"],
            "suggestion" => null,
            "quick_actions" => [],
            "debug" => ["source" => "vapi_tool_service", "reply_source" => "direct_result_navigation"]
        ];
    }

    private static function allResultsNavigationReply($language) {
        if ($language === "hi") return "Result page sabhi available semesters ke saath open ho gaya.";
        if ($language === "kn") return "Result page ella available semesters jothe open agide.";
        return "Result page is open with all available semesters.";
    }
    private static function extractSemester($text) {
        if (preg_match('/\b(?:semester|sem)\s*(\d{1,2})\b/u', $text, $matches)) {
            return (int) $matches[1];
        }
        if (preg_match('/\b([1-8])(?:st|nd|rd|th)\s*(?:semester|sem|result)\b/u', $text, $matches)) {
            return (int) $matches[1];
        }
        $words = ["first" => 1, "second" => 2, "third" => 3, "fourth" => 4, "fifth" => 5, "sixth" => 6, "seventh" => 7, "eighth" => 8];
        foreach ($words as $word => $value) {
            if (preg_match('/\b' . preg_quote($word, '/') . '\s*(?:semester|sem|result)\b/u', $text)) {
                return $value;
            }
        }
        return 0;
    }

    private static function pickResultSelection($selections, $text) {
        if (empty($selections)) {
            return null;
        }

        $exam = "";
        if (preg_match('/\b(see|resit|re-registration|reregistration)\b/u', $text, $matches)) {
            $exam = strtoupper($matches[1] === "reregistration" ? "RE-REGISTRATION" : $matches[1]);
        }
        $season = "";
        if (preg_match('/\b(odd|even)\b/u', $text, $matches)) {
            $season = strtoupper($matches[1]);
        }
        $year = "";
        if (preg_match('/\b(20\d{2})\s*-?\s*(?:20)?(\d{2})\b/u', $text, $matches)) {
            $year = $matches[1] . "-" . $matches[2];
        }

        $filtered = array_values(array_filter($selections, static function ($selection) use ($exam, $season, $year) {
            if ($exam !== "" && strtoupper((string) ($selection["exam"] ?? "")) !== $exam) return false;
            if ($season !== "" && strtoupper((string) ($selection["season"] ?? "")) !== $season) return false;
            if ($year !== "" && (string) ($selection["year"] ?? "") !== $year) return false;
            return true;
        }));

        if (!empty($filtered)) {
            $selections = $filtered;
        }

        foreach ($selections as $selection) {
            if (strtoupper((string) ($selection["exam"] ?? "")) === "SEE") {
                return $selection;
            }
        }
        return $selections[0];
    }

    private static function resultNavigationReply($semester, $hasFullSelection, $language) {
        if ($hasFullSelection) {
            if ($language === "hi") return "Semester " . $semester . " result filters ke saath open ho gaya.";
            if ($language === "kn") return "Semester " . $semester . " result filters jothe open agide.";
            return "Semester " . $semester . " result is open with filters.";
        }
        if ($language === "hi") return "Semester " . $semester . " result page open ho gaya. Baaki filters select kijiye.";
        if ($language === "kn") return "Semester " . $semester . " result page open agide. Ulida filters select madi.";
        return "Semester " . $semester . " result page is open. Please select the remaining filters.";
    }

    private static function loadResultAvailability($sessionId) {
        if ($sessionId === "") {
            return [];
        }

        $url = preg_replace('/api\.php(?:\?.*)?$/', 'getResultAvailability.php', VapiAssistantConfigService::getEnvValue("VOICEBOT_INTERNAL_API_URL", self::defaultApiUrl()));
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_HTTPGET => true,
            CURLOPT_HTTPHEADER => ["Cookie: PHPSESSID=" . $sessionId],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10
        ]);
        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $status >= 400) {
            return [];
        }
        $decoded = json_decode((string) $response, true);
        return is_array($decoded) ? $decoded : [];
    }
    private static function directNavigationResult($query, $language) {
        $text = self::normalizeIntentText($query);
        if ($text === "") {
            return null;
        }

        $page = self::detectPage($text, []);
        if ($page === "") {
            return null;
        }

        $isExplicitHome = $page === "home" && self::isExplicitHomeCommand($text);
        $hasNavigationVerb = self::hasNavigationVerb($text);

        if ($page === "home" && !$isExplicitHome) {
            return null;
        }

        if ($page !== "home" && !$hasNavigationVerb) {
            return null;
        }

        $path = self::pagePath($page);
        if ($path === "") {
            return null;
        }

        return [
            "reply" => self::navigationReply($page, $language),
            "intent" => "OPEN_PAGE",
            "route" => "navigation",
            "language" => $language,
            "client_action" => ["type" => "navigate", "path" => $path, "page" => $page],
            "suggestion" => null,
            "quick_actions" => [],
            "debug" => ["source" => "vapi_tool_service", "reply_source" => "direct_navigation"]
        ];
    }
    private static function navigationReply($page, $language) {
        $labels = [
            "registration" => "registration page",
            "payment" => "payment portal",
            "results" => "result page",
            "certificate" => "digital competency certificate page",
            "profile" => "profile page",
            "dashboard" => "dashboard",
            "portal" => "portal",
            "home" => "home page"
        ];
        $label = $labels[$page] ?? "page";
        if ($language === "hi") {
            return ucfirst($label) . " open ho gaya.";
        }
        if ($language === "kn") {
            return ucfirst($label) . " open agide.";
        }
        return ucfirst($label) . " is open.";
    }
    private static function callExistingApi($query, $language, $sessionId) {
        $url = VapiAssistantConfigService::getEnvValue("VOICEBOT_INTERNAL_API_URL", self::defaultApiUrl());
        $body = json_encode([
            "message" => $query,
            "language" => $language,
            "voice_provider" => "vapi",
            "raw_transcript" => $query,
            "corrected_transcript" => $query,
            "transcript_confidence" => 1.0,
            "mean_word_confidence" => 1.0,
            "low_confidence_words" => []
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => ["Content-Type: application/json", "Cookie: PHPSESSID=" . $sessionId],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 25
        ]);
        $response = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $status >= 400) {
            return [
                "status" => "error",
                "reply" => $error ?: "Voice backend did not respond correctly.",
                "reply_source" => "vapi_internal_api_error",
                "http_status" => $status
            ];
        }

        $decoded = json_decode((string) $response, true);
        return is_array($decoded) ? $decoded : ["status" => "error", "reply" => "Voice backend returned an invalid response."];
    }

    private static function shapeResult($apiResponse, $query, $language) {
        $reply = trim((string) ($apiResponse["reply"] ?? ""));
        if ($reply === "") {
            $reply = "I could not find an answer for that. Please ask again.";
        }

        $route = (string) ($apiResponse["route"] ?? "llm");
        $intent = (string) ($apiResponse["intent"] ?? "UNKNOWN");
        $clientAction = self::clientActionFromResponse($apiResponse, $query);

        return [
            "reply" => self::prepareReplyForVoice($reply, $language),
            "intent" => $intent,
            "route" => $route,
            "language" => $language,
            "client_action" => $clientAction,
            "suggestion" => $apiResponse["suggestion"] ?? null,
            "quick_actions" => $apiResponse["quick_actions"] ?? [],
            "debug" => [
                "source" => "vapi_tool_service",
                "reply_source" => $apiResponse["reply_source"] ?? "unknown",
                "intent_source" => $apiResponse["intent_source"] ?? "unknown",
                "effective_message" => $apiResponse["effective_message"] ?? $query
            ]
        ];
    }

    private static function localizeGenericReply($reply, $language) {
        if ($language === "en" || trim((string) $reply) === "") {
            return $reply;
        }
        if ($language === "hi" && preg_match('/[\x{0900}-\x{097F}]/u', $reply)) {
            return $reply;
        }
        if ($language === "kn" && (preg_match('/[\x{0C80}-\x{0CFF}]/u', $reply) || preg_match('/\b(nimma|nanna|madi|maduttene|rupayi|beku|illa|ide)\b/i', $reply))) {
            return $reply;
        }
        if (class_exists("LlmService")) {
            $translated = LlmService::adaptReplyLanguage($reply, $language, []);
            if (trim((string) $translated) !== "") {
                return $translated;
            }
        }
        return $reply;
    }

    private static function prepareReplyForVoice($reply, $language = "en") {
        $reply = self::localizeGenericReply((string) $reply, $language);
        $reply = preg_replace_callback('/\b(?:Rs\.?|INR)\s*([0-9][0-9,]*(?:\.\d+)?)/i', function ($matches) use ($language) {
            return self::spokenCurrency($matches[1], $language);
        }, $reply);
        $reply = preg_replace_callback('/\x{20B9}\s*([0-9][0-9,]*(?:\.\d+)?)/u', function ($matches) use ($language) {
            return self::spokenCurrency($matches[1], $language);
        }, $reply);
        $reply = preg_replace('/\bR\s*S\s*([0-9])/i', 'rupees $1', $reply);
        return trim(preg_replace('/\s+/', ' ', $reply));
    }

    private static function spokenCurrency($amount, $language) {
        $clean = str_replace(',', '', (string) $amount);
        $number = (int) floor((float) $clean);
        if ($number <= 0) {
            return $language === "kn" ? "zero rupayi" : ($language === "hi" ? "zero rupaye" : "rupees zero");
        }
        if ($language === "hi") return self::numberToHindiRoman($number) . " rupaye";
        if ($language === "kn") return self::numberToKannadaRoman($number) . " rupayi";
        return "rupees " . self::numberToEnglish($number);
    }

    private static function numberToEnglish($number) {
        $ones = [0 => "zero", 1 => "one", 2 => "two", 3 => "three", 4 => "four", 5 => "five", 6 => "six", 7 => "seven", 8 => "eight", 9 => "nine", 10 => "ten", 11 => "eleven", 12 => "twelve", 13 => "thirteen", 14 => "fourteen", 15 => "fifteen", 16 => "sixteen", 17 => "seventeen", 18 => "eighteen", 19 => "nineteen"];
        $tens = [2 => "twenty", 3 => "thirty", 4 => "forty", 5 => "fifty", 6 => "sixty", 7 => "seventy", 8 => "eighty", 9 => "ninety"];
        if ($number < 20) return $ones[$number];
        if ($number < 100) return $tens[intdiv($number, 10)] . ($number % 10 ? " " . $ones[$number % 10] : "");
        if ($number < 1000) return $ones[intdiv($number, 100)] . " hundred" . ($number % 100 ? " " . self::numberToEnglish($number % 100) : "");
        if ($number < 100000) return self::numberToEnglish(intdiv($number, 1000)) . " thousand" . ($number % 1000 ? " " . self::numberToEnglish($number % 1000) : "");
        if ($number < 10000000) return self::numberToEnglish(intdiv($number, 100000)) . " lakh" . ($number % 100000 ? " " . self::numberToEnglish($number % 100000) : "");
        return (string) $number;
    }

    private static function numberToHindiRoman($number) {
        $special = [30000 => "tees hazaar", 40000 => "chaalis hazaar", 50000 => "pachaas hazaar", 60000 => "saath hazaar", 70000 => "sattar hazaar", 80000 => "assi hazaar", 90000 => "nabbe hazaar"];
        return $special[$number] ?? self::numberToEnglish($number);
    }

    private static function numberToKannadaRoman($number) {
        $special = [30000 => "moovattu savira", 40000 => "nalavattu savira", 50000 => "aivattu savira", 60000 => "aravattu savira", 70000 => "eppattu savira", 80000 => "embattu savira", 90000 => "tombattu savira"];
        return $special[$number] ?? self::numberToEnglish($number);
    }
    private static function clientActionFromResponse($apiResponse, $query) {
        $existingAction = $apiResponse["client_action"] ?? $apiResponse["clientAction"] ?? null;
        if (is_array($existingAction) && ($existingAction["type"] ?? "") === "navigate") {
            $path = self::sanitizePath((string) ($existingAction["path"] ?? ""));
            if ($path !== "") {
                return [
                    "type" => "navigate",
                    "path" => $path,
                    "page" => self::canonicalPage((string) ($existingAction["page"] ?? basename($path)))
                ];
            }
        }

        $route = (string) ($apiResponse["route"] ?? "");
        $intent = (string) ($apiResponse["intent"] ?? "");
        $text = self::normalizeIntentText($query);

        if ($route === "navigation" || $intent === "OPEN_PAGE") {
            $page = self::detectPage($text, $apiResponse);
            if ($page !== "") {
                $path = self::pagePath($page);
                return $path === "" ? null : ["type" => "navigate", "path" => $path, "page" => $page];
            }
        }

        return null;
    }

    private static function detectPage($text, $apiResponse) {
        $text = self::normalizeIntentText($text);
        $debug = $apiResponse["debug"]["understanding"]["entities"]["target_page"] ?? null;
        $debugPage = self::canonicalPage((string) $debug);
        if ($debugPage !== "") {
            if ($debugPage === "home" && !self::isExplicitHomeCommand($text)) {
                return "";
            }
            return $debugPage;
        }

        if (self::isExplicitHomeCommand($text)) {
            return "home";
        }
        if (preg_match('/\b(registration|register|registation|ragistration|rijistreshan)\b/u', $text)) {
            return "registration";
        }
        if (preg_match('/\b(competency\s+certificate|digital\s+competency|digital\s+certificate|certificate\s+page|certificate)\b/u', $text)) {
            return "certificate";
        }
        if (preg_match('/\b(student\s+dashboard|dashboard\s+page|main\s+dashboard|dashboard|dash\s*board)\b/u', $text)) {
            return "dashboard";
        }
        if (preg_match('/\b(payment|fee\s+payment|fees\s+payment|payment\s+portal)\b/u', $text)) {
            return "payment";
        }
        if (preg_match('/\b(result|results|marksheet|marks|result\s+page)\b/u', $text)) {
            return "results";
        }
        if (preg_match('/\b(profile|profail|profle)\b/u', $text)) {
            return "profile";
        }
        if (preg_match('/\b(portal|role\s+portal)\b/u', $text)) {
            return "portal";
        }
        return "";
    }

    private static function normalizeIntentText($text) {
        $text = strtolower(trim((string) $text));
        $text = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $text);
        return trim(preg_replace('/\s+/u', ' ', (string) $text));
    }

    private static function hasNavigationVerb($text) {
        return (bool) preg_match('/\b(open|go|goto|navigate|show|take|visit|kholo|khol|dikhao|jao|chalo|torisu|hogu|maadi|madi|tere|tereyiri)\b/u', $text);
    }

    private static function isExplicitHomeCommand($text) {
        return (bool) preg_match('/\b(come\s+back|go\s+back|return\s+home|go\s+home)\b/u', $text);
    }

    private static function canonicalPage($page) {
        $page = strtolower(trim((string) $page));
        $page = str_replace(["_", "-"], " ", $page);
        $map = [
            "home" => "home",
            "main" => "home",
            "dashboard" => "dashboard",
            "student dashboard" => "dashboard",
            "registration" => "registration",
            "register" => "registration",
            "payment" => "payment",
            "payment portal" => "payment",
            "fees" => "payment",
            "fee payment" => "payment",
            "results" => "results",
            "result" => "results",
            "marks" => "results",
            "certificate" => "certificate",
            "competency certificate" => "certificate",
            "digital competency" => "certificate",
            "profile" => "profile",
            "portal" => "portal"
        ];
        return $map[$page] ?? "";
    }

    private static function sanitizePath($path) {
        $path = trim((string) $path);
        $allowed = ["/home", "/dashboard", "/registration", "/payment", "/results", "/certificate", "/profile", "/portal", "/attendance-analytics"];
        $basePath = parse_url($path, PHP_URL_PATH) ?: "";
        return in_array($basePath, $allowed, true) ? $path : "";
    }
    private static function pagePath($page) {
        $map = [
            "home" => "/home",
            "dashboard" => "/dashboard",
            "registration" => "/registration",
            "payment" => "/payment",
            "results" => "/results",
            "certificate" => "/certificate",
            "profile" => "/profile",
            "portal" => "/portal"
        ];
        return $map[$page] ?? "";
    }

    private static function toolResult($toolCallId, $result) {
        return [
            "toolCallId" => $toolCallId,
            "result" => $result
        ];
    }

    private static function normalizeLanguage($language, $query) {
        $language = strtolower(trim((string) $language));
        if ($language === "hi" || $language === "kn") {
            return $language;
        }
        if (preg_match('/[\x{0C80}-\x{0CFF}]/u', $query)) return "kn";
        if (preg_match('/[\x{0900}-\x{097F}]/u', $query)) return "hi";
        $roman = strtolower((string) $query);
        if (preg_match('/\b(torisu|hogu|maadi|madi|beku|bekagide|kannada|kannadadalli|heli|mathadu|maatadu|sari|nimma|nanna|elli|yelli|madodu|madbeku)\b/u', $roman)) return "kn";
        if (preg_match('/\b(hindi|mein|karo|kholo|dikhao|batao|chalo|mera|aapka|kripya|bharna|karna|kijiye|kaise|kahan)\b/u', $roman)) return "hi";
        return "en";
    }
    private static function defaultApiUrl() {
        $host = $_SERVER["HTTP_HOST"] ?? "localhost:8080";
        $scriptDir = rtrim(str_replace("\\", "/", dirname($_SERVER["SCRIPT_NAME"] ?? "/gmu-voice-assistant/backend")), "/");
        $scheme = (!empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off") ? "https" : "http";
        return $scheme . "://" . $host . $scriptDir . "/api.php";
    }
}





