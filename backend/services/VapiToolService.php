<?php

require_once __DIR__ . "/VapiSessionService.php";
require_once __DIR__ . "/VapiAssistantConfigService.php";
require_once __DIR__ . "/LlmService.php";
require_once __DIR__ . "/ERPQueryService.php";
require_once __DIR__ . "/ResultCatalogService.php";

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
        $toolName = (string) ($toolCall["name"] ?? $function["name"] ?? "gmu_voice_assistant");
        $args = $function["arguments"] ?? $toolCall["arguments"] ?? [];

        if (is_string($args)) {
            $decoded = json_decode($args, true);
            $args = is_array($decoded) ? $decoded : [];
        }

        $query = trim((string) ($args["query"] ?? $args["message"] ?? ""));
        $language = self::normalizeLanguage($args["language"] ?? "multi", $query);
        $preferredLanguage = self::extractPreferredLanguage($payload);
        $sessionToken = self::extractSessionToken($args, $payload);

        if ($query === "") {
            return self::toolResult($id, $toolName, [
                "reply" => "Please repeat your question.",
                "intent" => "EMPTY_QUERY",
                "route" => "clarification"
            ]);
        }

        $directLanguageSwitch = self::directLanguageSwitchResult($query, $language, $sessionToken);
        if ($directLanguageSwitch) {
            return self::toolResult($id, $toolName, $directLanguageSwitch);
        }
        $session = VapiSessionService::resolve($sessionToken);
        if (!$session) {
            $session = VapiSessionService::latestValidSession();
        }

        if (!$session) {
            $navigationResult = self::directNavigationResult($query, $language);
            if (is_array($navigationResult)) {
                return self::toolResult($id, $toolName, $navigationResult);
            }

            $paymentHelpResult = self::directPaymentHelpResult($query, $language);
            if (is_array($paymentHelpResult)) {
                return self::toolResult($id, $toolName, $paymentHelpResult);
            }

            return self::toolResult($id, $toolName, [
                "reply" => self::sessionExpiredReply($language),
                "intent" => "SESSION_EXPIRED",
                "route" => "auth"
            ]);
        }

        $sessionLanguage = self::languageFromSession($session);
        if ($sessionLanguage !== "multi") {
            error_log("VOICE LANGUAGE SESSION: {$language} -> {$sessionLanguage}");
            $language = $sessionLanguage;
        } elseif ($preferredLanguage !== "multi") {
            $language = $preferredLanguage;
        }

        $route = self::routeIntent($query, $language, $session);
        $sessionId = $session["session_id"] ?? "";
        $result = null;

        switch ($route["handler"]) {
            case "support_ticket":
                $result = self::directSupportTicketResult($query, $language, $session);
                break;
            case "erp_info":
                $result = self::directErpInfoResult($query, $language, $session);
                break;
            case "erp_query":
                $result = ERPQueryService::handle($route["intent"] ?? "", $query, $language, $session);
                break;
            case "payment_help":
                $result = self::directPaymentHelpResult($query, $language);
                break;
            case "page_navigation":
                $result = self::directNavigationResult($query, $language);
                break;
            case "result_navigation":
                $result = self::directResultNavigationResult($query, $language, $session);
                break;
            case "course_code":
                $result = self::directCourseCodeResult($query, $language, $sessionId);
                break;
            default:
                $apiResponse = self::callExistingApi($query, $language, $sessionId);
                $result = self::shapeResult($apiResponse, $query, $language);
                break;
        }

        if (!is_array($result)) {
            $apiResponse = self::callExistingApi($query, $language, $sessionId);
            $result = self::shapeResult($apiResponse, $query, $language);
        }

        if (isset($result["reply"])) {
            $result["reply"] = self::prepareReplyForVoice((string) $result["reply"], $language);
        }

        $result["debug"]["intent_router"] = $route;
        return self::toolResult($id, $toolName, $result);
    }

    private static function routeIntent($query, $language, $session) {
        $text = self::normalizeIntentText($query);
        $page = self::detectPage($text, []);
        $isHomeNavigation = $page === "home" && self::isExplicitHomeCommand($text);
        $isNonResultNavigation = $page !== "" && $page !== "results" && self::hasNavigationVerb($text);

        if ($isHomeNavigation || $isNonResultNavigation) {
            return ["handler" => "page_navigation", "intent" => "OPEN_PAGE", "reason" => "explicit_page_navigation", "page" => $page];
        }

        if (self::isSupportTicketIssue($text)) {
            return ["handler" => "support_ticket", "intent" => "SUPPORT_TICKET", "reason" => "erp_problem_signal"];
        }

        $erpIntent = class_exists("ERPQueryService") ? ERPQueryService::detectIntent($query, $language) : "";
        if ($erpIntent !== "") {
            error_log("ERP QUERY DETECTED: " . $erpIntent);
            return ["handler" => "erp_query", "intent" => $erpIntent, "reason" => "erp_query_service"];
        }

        if (self::isTuitionDeadlineQuery($text) || self::isHostelApplicationQuery($text) || self::isClassCancellationQuery($text)) {
            return ["handler" => "erp_info", "intent" => "ERP_INFO", "reason" => "erp_info_signal"];
        }

        if (self::isPaymentHelpQuery($text)) {
            return ["handler" => "payment_help", "intent" => "PAYMENT_HELP", "reason" => "payment_help_signal"];
        }

        if (self::isResultIntent($query)) {
            return ["handler" => "result_navigation", "intent" => "RESULT", "reason" => "result_signal"];
        }

        if (self::isCourseCodeIntent($text)) {
            return ["handler" => "course_code", "intent" => "GET_COURSE_CODE", "reason" => "course_code_signal"];
        }

        return ["handler" => "api", "intent" => "API_ROUTER", "reason" => "fallback"];
    }

    private static function isPaymentHelpQuery($text) {
        $normalized = self::normalizeIntentText($text);
        $hasPayment = (bool) preg_match('/\b(payment|pay|fees?|fee|receipt|transaction|amount|money|balance)\b/u', $normalized);
        $hasGrievance = (bool) preg_match('/\b(grievance|grievences|grevence|grevance|graviance|grevience|complaint|complain|shikayat|sikayat)\b/u', $normalized);
        $asksPayFees = (bool) preg_match('/\b(where|how|pay|payment|paid|process|steps)\b/u', $normalized) && (bool) preg_match('/\b(fees?|fee|amount)\b/u', $normalized);
        $asksGrievanceHelp = $hasGrievance && (bool) preg_match('/\b(apply|raise|submit|file|register|where|how|need|want|create|status|track|check|view|see|history)\b/u', $normalized);
        $asksOptions = (bool) preg_match('/\b(options?|methods?|available|what can i pay|which fee)\b/u', $normalized) && $hasPayment;
        $asksDeadline = (bool) preg_match('/\b(last\s+date|deadline|due\s+date|due|by\s+when|when)\b/u', $normalized) && (bool) preg_match('/\b(fees?|fee|tuition|payment)\b/u', $normalized);

        return !$asksDeadline && ($asksPayFees || $asksGrievanceHelp || $asksOptions);
    }

    private static function isResultIntent($query) {
        $text = self::normalizeResultQueryText((string) $query);
        if (self::isExplicitHomeCommand(self::normalizeIntentText($query))) {
            return false;
        }

        return (bool) preg_match('/\b(result|results|marks|marksheet|grade\s*sheet|score|sgpa|cgpa|internal\s*marks)\b/u', $text);
    }

    private static function isCourseCodeIntent($text) {
        $normalized = self::normalizeIntentText($text);
        return (bool) preg_match('/\b(course|cource|subject)\s*code\b|\bcode\s*(of|for)\b/u', $normalized);
    }
    private static function extractPreferredLanguage($payload) {
        $candidates = [
            $payload["message"]["call"]["assistantOverrides"]["variableValues"]["voice_language"] ?? null,
            $payload["message"]["assistantOverrides"]["variableValues"]["voice_language"] ?? null,
            $payload["call"]["assistantOverrides"]["variableValues"]["voice_language"] ?? null,
            $payload["assistantOverrides"]["variableValues"]["voice_language"] ?? null,
            $payload["variableValues"]["voice_language"] ?? null
        ];

        foreach ($candidates as $candidate) {
            $language = strtolower(trim((string) $candidate));
            if (in_array($language, ["en", "hi", "kn"], true)) {
                return $language;
            }
        }

        return "multi";
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

    private static function directLanguageSwitchResult($query, $language, $sessionToken = "") {
        $text = strtolower((string) $query);
        $normalized = preg_replace('/[^a-z0-9\s]+/u', ' ', $text);
        $normalized = preg_replace('/\s+/', ' ', (string) $normalized);
        $normalized = trim((string) $normalized);

        error_log("VOICE LANGUAGE CHECK: current={$language}; query={$normalized}");

        $englishRequested = (bool) preg_match('/\b(speak\s+in\s+english|switch\s+to\s+english|english\s+mode|talk\s+in\s+english|use\s+english|reply\s+in\s+english|answer\s+in\s+english|englishalli|english\s+me|english\s+mein|inglish)\b/u', $normalized);
        $kannadaRequested = (bool) preg_match('/\b(speak\s+in\s+kannada|switch\s+to\s+kannada|kannada\s+mode|talk\s+in\s+kannada|use\s+kannada|reply\s+in\s+kannada|answer\s+in\s+kannada|kannadadalli|kannada\s+dalli|kannada\s+me|kannada\s+mein|kanada|kannad)\b/u', $normalized);
        $hindiRequested = (bool) preg_match('/\b(speak\s+in\s+hindi|switch\s+to\s+hindi|hindi\s+mode|talk\s+in\s+hindi|use\s+hindi|reply\s+in\s+hindi|answer\s+in\s+hindi|hindiyalli|hindi\s+me|hindi\s+mein|hindi\s+mai|hindhi)\b/u', $normalized);

        if ($englishRequested) {
            error_log("VOICE LANGUAGE UPDATE: {$language} -> en");
            self::persistLanguageSwitch($sessionToken, 'en', $language);
            return self::languageSwitchPayload('en', 'Sure, I will speak in English now. How can I help you?', $language);
        }
        if ($kannadaRequested) {
            error_log("VOICE LANGUAGE UPDATE: {$language} -> kn");
            self::persistLanguageSwitch($sessionToken, 'kn', $language);
            return self::languageSwitchPayload('kn', 'Haudu, naanu Kannada dalli mathaduttene. Heli, nimge hege sahaya madali?', $language);
        }
        if ($hindiRequested) {
            error_log("VOICE LANGUAGE UPDATE: {$language} -> hi");
            self::persistLanguageSwitch($sessionToken, 'hi', $language);
            return self::languageSwitchPayload('hi', 'Ji haan, main ab Hindi mein baat karunga. Batayiye, main aapki kaise madad kar sakta hoon?', $language);
        }

        return null;
    }
    private static function persistLanguageSwitch($sessionToken, $requestedLanguage, $currentLanguage) {
        $updated = VapiSessionService::updateLanguage($sessionToken, $requestedLanguage);
        error_log("VOICE LANGUAGE PERSIST: current={$currentLanguage}; requested={$requestedLanguage}; updated=" . ($updated ? "yes" : "no"));
    }

    private static function languageFromSession($session) {
        $language = strtolower(trim((string) ($session["language"] ?? "multi")));
        return in_array($language, ["en", "hi", "kn"], true) ? $language : "multi";
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
            "debug" => ["source" => "vapi_tool_service", "reply_source" => "direct_language_switch", "previous_language" => $currentLanguage, "requested_language" => $languageKey]
        ];
    }
    private static function directErpInfoResult($query, $language, $session) {
        $text = self::normalizeIntentText($query);
        $studentId = (int) ($session["student_id"] ?? 0);

        if (self::isTuitionDeadlineQuery($text)) {
            return self::erpInfoPayload(self::tuitionDeadlineReply($language), "GET_TUITION_DEADLINE", $language);
        }

        if (self::isHostelApplicationQuery($text)) {
            return self::erpInfoPayload(self::hostelApplicationReply($studentId, $language), "GET_HOSTEL_APPLICATION_STATUS", $language);
        }

        if (self::isClassCancellationQuery($text)) {
            return self::erpInfoPayload(self::classCancellationReply($language), "GET_CLASS_CANCELLATION_STATUS", $language);
        }

        return null;
    }

    private static function isTuitionDeadlineQuery($text) {
        $hasDeadline = self::textHasAny($text, [
            "last date", "lastdate", "deadline", "due date", "due", "when", "by when",
            "kab", "kab tak", "tak", "date", "antim tithi", "akhri tarikh",
            "kone dinanka", "last dinanka", "last date yaavaga", "yaavaga", "yavaga"
        ]);
        $hasFee = self::textHasAny($text, [
            "tuition", "tuition fee", "tuition fees", "college fee", "college fees",
            "program fee", "program fees", "fee", "fees", "feesu", "shulk", "shulka"
        ]);
        return $hasDeadline && $hasFee;
    }

    private static function isHostelApplicationQuery($text) {
        $hasHostel = self::textHasAny($text, ["hostel", "hostelu", "hostel application", "hostel room", "room"]);
        $hasApplication = self::textHasAny($text, [
            "application", "applied", "status", "approved", "approve", "rejected", "reject",
            "through", "go through", "went through", "submit", "submitted", "pending",
            "allotment", "allotted", "problem", "issue", "error", "apply agideya",
            "approve agideya", "status enu", "hogideya", "aayta", "ayta", "mila", "hua", "ho gaya"
        ]);
        return $hasHostel && $hasApplication;
    }

    private static function isClassCancellationQuery($text) {
        $hasClass = self::textHasAny($text, ["class", "classes", "lecture", "lectures", "period", "periods", "college"]);
        $hasCancel = self::textHasAny($text, [
            "cancelled", "canceled", "cancel", "holiday", "off", "not there", "today",
            "aaj", "ivattu", "cancel agideya", "cancel aagideya", "class ideya", "classes ideya",
            "chutti", " ?????? "
        ]);
        return $hasClass && $hasCancel;
    }

    private static function textHasAny($text, $needles) {
        $text = " " . strtolower((string) $text) . " ";
        foreach ($needles as $needle) {
            $needle = strtolower(trim((string) $needle));
            if ($needle !== "" && strpos($text, $needle) !== false) {
                return true;
            }
        }
        return false;
    }

    private static function erpInfoPayload($reply, $intent, $language) {
        return [
            "reply" => self::prepareReplyForVoice($reply, $language),
            "intent" => $intent,
            "route" => "erp_info",
            "language" => $language,
            "client_action" => null,
            "suggestion" => null,
            "quick_actions" => [],
            "debug" => ["source" => "vapi_tool_service", "reply_source" => "direct_erp_info"]
        ];
    }

    private static function tuitionDeadlineReply($language) {
        require __DIR__ . "/../config/db.php";
        if (!isset($conn) || !$conn) {
            return self::erpInfoUnavailableReply("tuition fee deadline", $language);
        }

        $stmt = $conn->prepare("SELECT title, due_date, description FROM erp_deadlines WHERE is_active = 1 AND (category IN ('tuition_fee', 'tuition', 'fees', 'fee') OR title LIKE '%tuition%' OR title LIKE '%fee%') ORDER BY due_date ASC LIMIT 1");
        if (!$stmt) {
            return self::erpInfoUnavailableReply("tuition fee deadline", $language);
        }
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
            return self::erpInfoUnavailableReply("tuition fee deadline", $language);
        }

        $date = self::spokenDate((string) ($row["due_date"] ?? ""));
        $description = trim((string) ($row["description"] ?? ""));
        if ($language === "hi") return "Tuition fee pay karne ki last date " . $date . " hai." . ($description !== "" ? " " . $description : "");
        if ($language === "kn") return "Tuition fee pay madalu last date " . $date . " ide." . ($description !== "" ? " " . $description : "");
        return "The last date to pay tuition fee is " . $date . "." . ($description !== "" ? " " . $description : "");
    }

    private static function hostelApplicationReply($studentId, $language) {
        if ($studentId <= 0) {
            return self::erpInfoUnavailableReply("hostel application status", $language);
        }
        require __DIR__ . "/../config/db.php";
        if (!isset($conn) || !$conn) {
            return self::erpInfoUnavailableReply("hostel application status", $language);
        }

        $stmt = $conn->prepare("SELECT status, remarks, applied_at FROM hostel_applications WHERE student_id = ? ORDER BY applied_at DESC, application_id DESC LIMIT 1");
        if (!$stmt) {
            return self::erpInfoUnavailableReply("hostel application status", $language);
        }
        $stmt->bind_param("i", $studentId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
            if ($language === "hi") return "Mujhe aapki hostel application ka record nahi mila.";
            if ($language === "kn") return "Nimma hostel application record sigalilla.";
            return "I could not find a hostel application record for your account.";
        }

        $status = strtolower(trim((string) ($row["status"] ?? "pending")));
        $remarks = trim((string) ($row["remarks"] ?? ""));
        if ($language === "hi") return "Aapki hostel application status " . $status . " hai." . ($remarks !== "" ? " " . $remarks : "");
        if ($language === "kn") return "Nimma hostel application status " . $status . " ide." . ($remarks !== "" ? " " . $remarks : "");
        return "Your hostel application status is " . $status . "." . ($remarks !== "" ? " " . $remarks : "");
    }

    private static function classCancellationReply($language) {
        require __DIR__ . "/../config/db.php";
        if (!isset($conn) || !$conn) {
            return self::erpInfoUnavailableReply("class cancellation notice", $language);
        }

        $today = date('Y-m-d');
        $stmt = $conn->prepare("SELECT title, class_scope, is_cancelled, description FROM class_notices WHERE notice_date = ? ORDER BY is_cancelled DESC, notice_id DESC LIMIT 1");
        if (!$stmt) {
            return self::erpInfoUnavailableReply("class cancellation notice", $language);
        }
        $stmt->bind_param("s", $today);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
            if ($language === "hi") return "Aaj classes cancel hone ka koi notice nahi mila.";
            if ($language === "kn") return "Ivattu classes cancel agive anta notice sigalilla.";
            return "I could not find any class cancellation notice for today.";
        }

        $scope = trim((string) ($row["class_scope"] ?? "all"));
        $description = trim((string) ($row["description"] ?? ""));
        $isCancelled = (int) ($row["is_cancelled"] ?? 0) === 1;
        if ($isCancelled) {
            if ($language === "hi") return "Haan, aaj classes cancelled hain" . ($scope !== "" ? " for " . $scope : "") . "." . ($description !== "" ? " " . $description : "");
            if ($language === "kn") return "Haudu, ivattu classes cancelled agive" . ($scope !== "" ? " for " . $scope : "") . "." . ($description !== "" ? " " . $description : "");
            return "Yes, classes are cancelled today" . ($scope !== "" ? " for " . $scope : "") . "." . ($description !== "" ? " " . $description : "");
        }

        if ($language === "hi") return "Aaj classes cancel nahi hain" . ($scope !== "" ? " for " . $scope : "") . "." . ($description !== "" ? " " . $description : "");
        if ($language === "kn") return "Ivattu classes cancel agilla" . ($scope !== "" ? " for " . $scope : "") . "." . ($description !== "" ? " " . $description : "");
        return "Classes are not cancelled today" . ($scope !== "" ? " for " . $scope : "") . "." . ($description !== "" ? " " . $description : "");
    }

    private static function erpInfoUnavailableReply($topic, $language) {
        if ($language === "hi") return "Mujhe abhi " . $topic . " ki information nahi mili.";
        if ($language === "kn") return "Iga " . $topic . " information sigalilla.";
        return "I could not find " . $topic . " information right now.";
    }

    private static function spokenDate($date) {
        $timestamp = strtotime((string) $date);
        if (!$timestamp) {
            return (string) $date;
        }
        return date('j F Y', $timestamp);
    }
    private static function directSupportTicketResult($query, $language, $session) {
        $text = self::normalizeIntentText($query);
        if (!self::isSupportTicketIssue($text)) {
            return null;
        }

        $wordCount = count(preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY));
        $hasEnoughTicketDetail = self::hasEnoughSupportTicketDetail($text);
        if (!$hasEnoughTicketDetail && $wordCount <= 3 && !preg_match('/\b(payment\s+failed|erp\s+not\s+working|login\s+(issue|problem|error)|attendance\s+(issue|problem)|registration\s+(issue|error)|certificate\s+(issue|problem))\b/u', $text)) {
            return [
                "reply" => self::supportTicketDetailPrompt($language),
                "intent" => "SUPPORT_TICKET_NEEDS_DETAILS",
                "route" => "support_ticket",
                "language" => $language,
                "client_action" => null,
                "suggestion" => null,
                "quick_actions" => [],
                "debug" => ["source" => "vapi_tool_service", "reply_source" => "support_ticket_clarification"]
            ];
        }

        $studentId = (int) ($session["student_id"] ?? 0);
        if ($studentId <= 0) {
            return null;
        }

        $ticket = self::createSupportTicket($studentId, $query, self::classifySupportIssue($text));
        if (!$ticket) {
            return [
                "reply" => self::supportTicketErrorReply($language),
                "intent" => "SUPPORT_TICKET_ERROR",
                "route" => "support_ticket",
                "language" => $language,
                "client_action" => null,
                "suggestion" => null,
                "quick_actions" => [],
                "debug" => ["source" => "vapi_tool_service", "reply_source" => "support_ticket_error"]
            ];
        }

        return [
            "reply" => self::supportTicketSuccessReply($ticket["ticket_code"], $ticket["complaint"], $ticket["raised_on"], $language),
            "intent" => "CREATE_SUPPORT_TICKET",
            "route" => "support_ticket",
            "language" => $language,
            "client_action" => null,
            "suggestion" => null,
            "quick_actions" => [],
            "debug" => [
                "source" => "vapi_tool_service",
                "reply_source" => "support_ticket_created",
                "ticket_id" => $ticket["ticket_code"],
                "issue_type" => $ticket["issue_type"],
                "raised_on" => $ticket["raised_on"]
            ]
        ];
    }

    private static function hasEnoughSupportTicketDetail($text) {
        $text = strtolower((string) $text);
        if (preg_match('/\battendance\b.*\bnot\s+updated\b|\bnot\s+updated\b.*\battendance\b/u', $text)) return true;
        if (preg_match('/\b(payment|fee|fees)\b.*\b(failed|deducted|not\s+updated|receipt\s+not\s+generated)\b/u', $text)) return true;
        if (preg_match('/\b(login|registration|certificate|result|marks|profile|hall\s*ticket)\b.*\b(issue|problem|error|not\s+working|not\s+showing|not\s+opening|missing)\b/u', $text)) return true;
        return count(preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY)) >= 5;
    }
    private static function isSupportTicketIssue($text) {
        $hasProblemSignal = (bool) preg_match('/\b(issue|problem|error|failed|failure|not\s+working|not\s+updated|not\s+showing|not\s+opening|not\s+downloading|missing|unable|cannot|can\s*not|stuck|deducted|wrong|incorrect|raise|create|submit|arise|arice)\b/u', $text);
        $hasErpArea = (bool) preg_match('/\b(erp|login|attendance|attendence|atendance|payment|fee|fees|tuition|hostel|class|classes|lecture|marks|result|registration|certificate|hall\s*ticket|profile|voicebot|voice\s*bot|support|ticket|ticked|tiket)\b/u', $text);
        return $hasProblemSignal && $hasErpArea;
    }

    private static function classifySupportIssue($text) {
        if (preg_match('/\b(attendance|attendence|atendance|class\s+present|absent)\b/u', $text)) return "attendance";
        if (preg_match('/\b(hostel)\b/u', $text)) return "hostel";
        if (preg_match('/\b(class|classes|lecture|lectures)\b/u', $text)) return "classes";
        if (preg_match('/\b(tuition|payment|fee|fees|amount|deducted|transaction)\b/u', $text)) return "payment";
        if (preg_match('/\b(result|results|marks|marksheet|grade)\b/u', $text)) return "results";
        if (preg_match('/\b(registration|register)\b/u', $text)) return "registration";
        if (preg_match('/\b(login|password|signin|sign\s+in)\b/u', $text)) return "login";
        if (preg_match('/\b(certificate|competency|download)\b/u', $text)) return "certificates";
        if (preg_match('/\b(hall\s*ticket|hallticket|admit\s+card)\b/u', $text)) return "hall_ticket";
        if (preg_match('/\b(profile)\b/u', $text)) return "profile";
        if (preg_match('/\b(voicebot|voice\s*bot|assistant)\b/u', $text)) return "voicebot";
        return "general";
    }
    private static function studentIdFromSession($session) {
        $studentId = (int) ($session["student_id"] ?? 0);
        if ($studentId > 0) {
            return $studentId;
        }

        $userId = (int) ($session["user_id"] ?? 0);
        if ($userId <= 0) {
            return 0;
        }

        require __DIR__ . "/../config/db.php";
        if (!isset($conn) || !$conn) {
            return 0;
        }

        $stmt = $conn->prepare("SELECT student_id FROM users WHERE user_id = ? AND is_active = 1 LIMIT 1");
        if (!$stmt) {
            return 0;
        }

        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return (int) ($row["student_id"] ?? 0);
    }
    private static function createSupportTicket($studentId, $description, $issueType) {
        require __DIR__ . "/../config/db.php";
        if (!isset($conn) || !$conn) {
            return null;
        }

        self::ensureSupportTicketsTable($conn);

        $studentStmt = $conn->prepare("SELECT usn FROM students WHERE student_id = ? LIMIT 1");
        if (!$studentStmt) {
            return null;
        }
        $studentStmt->bind_param("i", $studentId);
        $studentStmt->execute();
        $student = $studentStmt->get_result()->fetch_assoc();
        $studentStmt->close();

        $usn = strtoupper((string) ($student["usn"] ?? ""));
        $priority = self::ticketPriority($issueType, (string) $description);
        $status = "open";

        $stmt = $conn->prepare("INSERT INTO support_tickets (ticket_code, student_id, usn, issue_type, issue_description, priority, status) VALUES (?, ?, ?, ?, ?, ?, ?)");
        if (!$stmt) {
            return null;
        }

        $placeholderCode = "GMU-TMP-" . bin2hex(random_bytes(6));
        $stmt->bind_param("sisssss", $placeholderCode, $studentId, $usn, $issueType, $description, $priority, $status);
        if (!$stmt->execute()) {
            $stmt->close();
            return null;
        }
        $id = (int) $stmt->insert_id;
        $stmt->close();

        $ticketCode = "GMU-" . str_pad((string) $id, 4, "0", STR_PAD_LEFT);
        $update = $conn->prepare("UPDATE support_tickets SET ticket_code = ? WHERE ticket_id = ?");
        if ($update) {
            $update->bind_param("si", $ticketCode, $id);
            $update->execute();
            $update->close();
        }

        return [
            "ticket_code" => $ticketCode,
            "issue_type" => $issueType,
            "complaint" => self::summarizeSupportComplaint($description),
            "raised_on" => date("jS F Y")
        ];
    }

    private static function ensureSupportTicketsTable($conn) {
        $conn->query("CREATE TABLE IF NOT EXISTS support_tickets (
            ticket_id INT AUTO_INCREMENT PRIMARY KEY,
            ticket_code VARCHAR(30) NOT NULL UNIQUE,
            student_id INT NOT NULL,
            usn VARCHAR(30) DEFAULT NULL,
            issue_type VARCHAR(50) NOT NULL,
            issue_description TEXT NOT NULL,
            priority VARCHAR(20) NOT NULL DEFAULT 'medium',
            status VARCHAR(20) NOT NULL DEFAULT 'open',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_support_student (student_id),
            INDEX idx_support_status (status),
            INDEX idx_support_issue_type (issue_type)
        ) ENGINE=InnoDB");
    }

    private static function ticketPriority($issueType, $description) {
        $text = strtolower((string) $description);
        if (preg_match('/\b(payment|fee|fees)\b/u', $issueType) || preg_match('/\b(failed|deducted|exam|urgent|deadline)\b/u', $text)) {
            return "high";
        }
        if (in_array($issueType, ["login", "registration", "results", "certificates", "hostel", "classes"], true)) {
            return "medium";
        }
        return "low";
    }

    private static function supportTicketDetailPrompt($language) {
        if ($language === "hi") return "Main support ticket raise kar sakta hoon. Kripya problem thoda detail mein batayiye.";
        if ($language === "kn") return "Nanu support ticket create madabahudu. Dayavittu problem swalpa detail aagi heli.";
        return "I can raise a support ticket for this issue. Please briefly explain the problem.";
    }

    private static function summarizeSupportComplaint($description) {
        $summary = trim(preg_replace('/\s+/u', ' ', (string) $description));
        $summary = trim($summary, " \t\n\r\0\x0B.?!,;:");
        if ($summary === "") {
            return "ERP support issue";
        }
        if (function_exists('mb_strlen') && mb_strlen($summary, 'UTF-8') > 90) {
            return rtrim(mb_substr($summary, 0, 87, 'UTF-8')) . "...";
        }
        if (!function_exists('mb_strlen') && strlen($summary) > 90) {
            return rtrim(substr($summary, 0, 87)) . "...";
        }
        return $summary;
    }

    private static function supportTicketSuccessReply($ticketCode, $complaint, $raisedOn, $language) {
        $complaint = self::summarizeSupportComplaint($complaint);
        $raisedOn = trim((string) $raisedOn) !== "" ? trim((string) $raisedOn) : date("jS F Y");

        if ($language === "hi") {
            return "Aapki complaint register ho gayi hai. Complaint: \"" . $complaint . "\". Token number: " . $ticketCode . ". Raised on " . $raisedOn . ". Future reference ke liye token note kar lijiye ya screenshot le lijiye.";
        }
        if ($language === "kn") {
            return "Nimma complaint register agide. Complaint: \"" . $complaint . "\". Token number: " . $ticketCode . ". Raised on " . $raisedOn . ". Mundina reference ge token note madi athava screenshot tegedukolli.";
        }
        return "Your complaint has been registered successfully. Complaint: \"" . $complaint . "\". Token number: " . $ticketCode . ". Raised on " . $raisedOn . ". Please note this token or take a screenshot for future reference.";
    }

    private static function supportTicketErrorReply($language) {
        if ($language === "hi") return "Support ticket create karte waqt problem aayi. Kripya thodi der baad try kijiye.";
        if ($language === "kn") return "Support ticket create maduvaga problem ayitu. Dayavittu swalpa samayada nantara try madi.";
        return "I could not raise the support ticket right now. Please try again after some time.";
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
        $asksDeadline = (bool) preg_match('/\b(last\s+date|deadline|due\s+date|due|by\s+when|when)\b/u', $normalized) && (bool) preg_match('/\b(fees?|fee|tuition|payment)\b/u', $normalized);
        if ($asksDeadline) {
            return null;
        }
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
    private static function directResultNavigationResult($query, $language, $session) {
        $sessionId = (string) ($session["session_id"] ?? "");
        $text = self::normalizeResultQueryText((string) $query);
        $resultPattern = '/\b(result|results|marks|marksheet|grade\s*sheet|score|sgpa|cgpa|internal\s*marks)\b/u';
        if (!preg_match($resultPattern, $text)) {
            return null;
        }
        $hasResultNavigationVerb = (bool) preg_match('/\b(show|open|view|display|check|go|navigate|take|get|see|tell|kholo|khol|dikhao|dikhana|batao|batana|torisu|torisi|hogu|hogi|nodu|nodi|beku|bekagide|maadi|madi|madu|kodu|kodi)\b/u', $text);
        $resultWords = '\b(result|results|marks|marksheet|grade\s*sheet|score|sgpa|cgpa|internal\s*marks)\b';
        $questionWords = '\b(what|which|where|how|kya|kaise|kahan|kab|yaava|yava|hege|elli|enu)\b';
        $hasResultQuestionIntent = (bool) preg_match('/' . $questionWords . '.*' . $resultWords . '|' . $resultWords . '.*' . $questionWords . '/u', $text);
        $hasSemesterMention = self::extractSemester($text) > 0 || (bool) preg_match('/\b(current|latest|present|previous|last|abhi|pichla|hindina|eega|ivaga)\s+(semester|sem)?\s*(result|results|marks)?\b/u', $text);
        $isResultOnlyRequest = (bool) preg_match($resultPattern, $text);
        if (!$hasResultNavigationVerb && !$hasSemesterMention && !preg_match('/\b(sgpa|cgpa)\b/u', $text) && !($hasResultQuestionIntent && $hasSemesterMention) && !$isResultOnlyRequest) {
            return null;
        }

        $availability = self::loadResultAvailability($sessionId, $session);
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
        if ($semester <= 0 && $currentSemester > 0) {
            $semester = $currentSemester;
        }
        if ($semester <= 0 && !empty($selections)) {
            $semester = (int) ($selections[0]["semester"] ?? 0);
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

        $summaryReply = $selected ? self::resultSummaryFromSelection($sessionId, $student, $semester, $selected, $language, $session) : "";

        return [
            "reply" => $summaryReply !== "" ? $summaryReply : self::resultNavigationReply($semester, (bool) $selected, $language),
            "intent" => "OPEN_FILTERED_RESULT",
            "route" => "navigation",
            "language" => $language,
            "client_action" => [
                "type" => "navigate",
                "path" => $path,
                "page" => "results",
                "result_request" => [
                    "semester" => (string) $semester,
                    "examType" => (string) ($selected["exam"] ?? "SEE"),
                    "year" => (string) ($selected["year"] ?? ""),
                    "season" => (string) ($selected["season"] ?? ""),
                    "usn" => (string) ($student["usn"] ?? "")
                ]
            ],
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
    private static function normalizeResultQueryText($query) {
        $text = strtolower(trim((string) $query));
        $replacements = [
            '/\bs\s*e\s*e\b|\bc\s*e\s*e\b|\bcee\b|\bc\s+(?=result|results|marks|sgpa|cgpa|grade)/u' => ' see ',
            '/\bsea\s+(?=result|results|marks|sgpa|cgpa|grade)/u' => ' see ',
            '/\bre\s+sit\b/u' => ' resit ',
            '/\bre\s+valuation\b/u' => ' revaluation ',
            '/\bre\s*[- ]?\s*registration\b|\breregistration\b/u' => ' re-registration ',
            '/\x{0930}\x{093F}\x{091C}\x{0932}\x{094D}\x{091F}|\x{092A}\x{0930}\x{093F}\x{0923}\x{093E}\x{092E}|\x{0928}\x{0924}\x{0940}\x{091C}\x{093E}|\x{092E}\x{093E}\x{0930}\x{094D}\x{0915}\x{094D}\x{0938}|\x{0905}\x{0902}\x{0915}|\x{0917}\x{094D}\x{0930}\x{0947}\x{0921}/u' => ' result ',
            '/\x{0CB0}\x{0CBF}\x{0CB8}\x{0CB2}\x{0CCD}\x{0C9F}\x{0CCD}|\x{0CB0}\x{0CBF}\x{0C9C}\x{0CB2}\x{0CCD}\x{0C9F}\x{0CCD}|\x{0CAB}\x{0CB2}\x{0CBF}\x{0CA4}\x{0CBE}\x{0C82}\x{0CB6}|\x{0CAE}\x{0CBE}\x{0CB0}\x{0CCD}\x{0C95}\x{0CCD}\x{0CB8}\x{0CCD}|\x{0C85}\x{0C82}\x{0C95}|\x{0C97}\x{0CCD}\x{0CB0}\x{0CC7}\x{0CA1}\x{0CCD}/u' => ' result ',
            '/\b(resultu|rijalt|resalt|marksu|marks card|grade card|gradecard|ankagalu|phalitansha|falitansha)\b/u' => ' result ',
            '/\bna result|mera result|nanna result|result torisu|result nodu|marks torisu|sgpa torisu|sgpa dikhao\b/u' => ' result ',
            '/\bpehla|pahla|firstu|ondu|ondane|modala\b/u' => ' first ',
            '/\bdoosra|dusra|secondu|eradu|eradane\b/u' => ' second ',
            '/\bteesra|tisra|thirdu|mooru|moorane\b/u' => ' third ',
            '/\bchautha|choutha|fourthu|forth|fourthh|naalku|nalku|naalkane|nalkane\b/u' => ' fourth ',
            '/\bpanchwa|paanchwa|fifthu|fitsh|fith|fivth|fiveth|aidu|aidane\b/u' => ' fifth ',
            '/\bchhatha|sixthu|aaru|aarane\b/u' => ' sixth ',
            '/\bsaatwa|seventhu|elu|elane\b/u' => ' seventh ',
            '/\baathwa|aathva|eighthu|entu|entane\b/u' => ' eighth '
        ];
        foreach ($replacements as $pattern => $replacement) {
            $text = preg_replace($pattern, $replacement, $text);
        }
        $text = preg_replace('/[^\p{L}\p{N}\s+-]+/u', ' ', (string) $text);
        return trim(preg_replace('/\s+/u', ' ', (string) $text));
    }
    private static function extractSemester($text) {
        $text = self::normalizeResultQueryText($text);
        if (preg_match('/\b(?:semester|sem)\s*(\d{1,2})\b/u', $text, $matches)) {
            return (int) $matches[1];
        }
        if (preg_match('/\b([1-8])(?:st|nd|rd|th)?\s*(?:semester|sem|result|marks)?\b/u', $text, $matches)) {
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
        } elseif ($exam !== "" || $season !== "" || $year !== "") {
            return [
                "exam" => $exam !== "" ? $exam : "SEE",
                "year" => $year,
                "season" => $season
            ];
        }

        foreach ($selections as $selection) {
            if (strtoupper((string) ($selection["exam"] ?? "")) === "SEE") {
                return $selection;
            }
        }
        return $selections[0];
    }

    private static function resultSummaryFromSelection($sessionId, $student, $semester, $selected, $language, $session = []) {
        if (!is_array($selected) || empty($selected)) {
            return "";
        }

        $payload = [
            "usn" => strtoupper((string) ($student["usn"] ?? "")),
            "semester" => (int) $semester,
            "exam" => (string) ($selected["exam"] ?? "SEE"),
            "year" => (string) ($selected["year"] ?? ""),
            "season" => (string) ($selected["season"] ?? "")
        ];

        if ($payload["semester"] <= 0 || $payload["exam"] === "" || $payload["year"] === "" || $payload["season"] === "") {
            return "";
        }

        $url = preg_replace('/api\.php(?:\?.*)?$/', 'getSemesterResult.php', VapiAssistantConfigService::getEnvValue("VOICEBOT_INTERNAL_API_URL", self::defaultApiUrl()));
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => ["Content-Type: application/json", "Cookie: PHPSESSID=" . $sessionId],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 12
        ]);
        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $status >= 400) {
            return self::resultSummaryFromDatabase($session, $payload, $language);
        }

        $decoded = json_decode((string) $response, true);
        if (!is_array($decoded)) {
            return self::resultSummaryFromDatabase($session, $payload, $language);
        }

        $selection = is_array($decoded["selection"] ?? null) ? $decoded["selection"] : $selected;
        $summary = is_array($decoded["summary"] ?? null) ? $decoded["summary"] : [];
        $sgpa = $summary["sgpa"] ?? null;
        if ($sgpa === null || $sgpa === "") {
            return self::resultSummaryFromDatabase($session, $payload, $language);
        }

        return self::resultSummaryReply(
            (string) ($selection["semester"] ?? $semester),
            (string) ($selection["exam"] ?? $payload["exam"]),
            $sgpa,
            $language
        );
    }

    private static function resultSummaryReply($semester, $exam, $sgpa, $language) {
        $sgpaText = rtrim(rtrim(number_format((float) $sgpa, 2, '.', ''), '0'), '.');
        $examText = strtoupper((string) $exam);
        $feedback = self::sgpaFeedback((float) $sgpa, $language);

        if ($language === "hi") {
            return "Aapka semester " . $semester . " " . $examText . " result open ho gaya hai. Aapka SGPA " . $sgpaText . " hai. " . $feedback;
        }
        if ($language === "kn") {
            return "Nimma semester " . $semester . " " . $examText . " result open agide. Nimma SGPA " . $sgpaText . ". " . $feedback;
        }
        return "Your semester " . $semester . " " . $examText . " result is now open. Your SGPA is " . $sgpaText . ". " . $feedback;
    }

    private static function sgpaFeedback($sgpa, $language) {
        if ($language === "hi") {
            if ($sgpa >= 9.5) return "Outstanding performance. Aise hi excellent work continue kijiye.";
            if ($sgpa >= 9) return "Excellent performance. Aap bahut achha kar rahe hain.";
            if ($sgpa >= 8) return "Very good performance. Is consistency ko maintain kijiye.";
            if ($sgpa >= 7) return "Good performance. Thoda aur focus karenge to aur improve hoga.";
            if ($sgpa >= 6) return "Average performance. Weak areas par zyada focus kijiye.";
            if ($sgpa >= 5) return "Aap pass hain, lekin improvement zaroori hai. Regular revision kijiye.";
            return "Performance ko serious attention chahiye. Mentor se baat karke improvement plan banaiye.";
        }

        if ($language === "kn") {
            if ($sgpa >= 9.5) return "Outstanding performance. Ee excellent work munduvarisi.";
            if ($sgpa >= 9) return "Excellent performance. Neevu tumba chennagi maduttiddira.";
            if ($sgpa >= 8) return "Very good performance. Ee consistency maintain madi.";
            if ($sgpa >= 7) return "Good performance. Innu swalpa focus madidare improve agutte.";
            if ($sgpa >= 6) return "Average performance. Weak areas mele hecchu focus madi.";
            if ($sgpa >= 5) return "Neevu pass agiddira, aadre improvement beku. Regular revision madi.";
            return "Performance ge serious attention beku. Mentor jothe matadi improvement plan madi.";
        }

        if ($sgpa >= 9.5) return "Outstanding performance. Keep up the excellent work.";
        if ($sgpa >= 9) return "Excellent performance. You are doing very well.";
        if ($sgpa >= 8) return "Very good performance. Keep maintaining this consistency.";
        if ($sgpa >= 7) return "Good performance. With a little more focus, you can improve further.";
        if ($sgpa >= 6) return "Average performance. Please focus more on weaker areas.";
        if ($sgpa >= 5) return "You have passed, but improvement is needed. Please revise regularly.";
        return "Your performance needs serious attention. Please contact your mentor and work on improvement.";
    }
    private static function resultNavigationReply($semester, $hasFullSelection, $language) {
        if ($hasFullSelection) {
            if ($language === "hi") return "Semester " . $semester . " result check kar raha hoon.";
            if ($language === "kn") return "Semester " . $semester . " result check maduttiddene.";
            return "Checking semester " . $semester . " result.";
        }
        if ($language === "hi") return "Semester " . $semester . " result ke liye details load kar raha hoon.";
        if ($language === "kn") return "Semester " . $semester . " result details load maduttiddene.";
        return "Loading details for semester " . $semester . " result.";
    }

    private static function loadResultAvailability($sessionId, $session = []) {
        if ($sessionId === "") {
            return self::loadResultAvailabilityFromDatabase($session);
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
            return self::loadResultAvailabilityFromDatabase($session);
        }
        $decoded = json_decode((string) $response, true);
        return is_array($decoded) ? $decoded : self::loadResultAvailabilityFromDatabase($session);
    }

    private static function loadResultAvailabilityFromDatabase($session) {
        $studentId = (int) ($session["student_id"] ?? 0);
        if ($studentId <= 0) return [];
        require __DIR__ . "/../config/db.php";
        if (!isset($conn) || !$conn) return [];
        ResultCatalogService::ensureCatalogReady($conn);
        $stmt = $conn->prepare("SELECT student_id, full_name, usn, branch, semester FROM students WHERE student_id = ? LIMIT 1");
        if (!$stmt) return [];
        $stmt->bind_param("i", $studentId);
        $stmt->execute();
        $student = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$student) return [];
        return [
            "student" => [
                "student_id" => (int) $student["student_id"],
                "full_name" => $student["full_name"],
                "usn" => $student["usn"],
                "branch" => $student["branch"],
                "current_semester" => (int) $student["semester"]
            ],
            "selections" => ResultCatalogService::getPublishedSelections($conn, $studentId)
        ];
    }

    private static function resultSummaryFromDatabase($session, $payload, $language) {
        $studentId = (int) ($session["student_id"] ?? 0);
        if ($studentId <= 0) return "";
        require __DIR__ . "/../config/db.php";
        if (!isset($conn) || !$conn) return "";
        ResultCatalogService::ensureCatalogReady($conn);
        $selection = ResultCatalogService::findPublishedSelection($conn, $studentId, (int) ($payload["semester"] ?? 0), (string) ($payload["exam"] ?? "SEE"), (string) ($payload["year"] ?? ""), (string) ($payload["season"] ?? ""));
        if (!$selection) return "";
        $semester = (int) ($payload["semester"] ?? 0);
        $stmt = $conn->prepare("SELECT credits, grade_point FROM results WHERE student_id = ? AND semester = ?");
        if (!$stmt) return "";
        $stmt->bind_param("ii", $studentId, $semester);
        $stmt->execute();
        $result = $stmt->get_result();
        $credits = 0.0;
        $points = 0.0;
        while ($row = $result->fetch_assoc()) {
            $credit = (float) $row["credits"];
            $credits += $credit;
            $points += ((float) $row["grade_point"] * $credit);
        }
        $stmt->close();
        if ($credits <= 0) return "";
        return self::resultSummaryReply((string) $semester, (string) ($selection["exam"] ?? $payload["exam"] ?? "SEE"), round($points / $credits, 2), $language);
    }
    private static function directNavigationResult($query, $language) {
        $text = self::normalizeIntentText($query);
        if ($text === "") {
            return null;
        }

        if (self::isExplicitHomeCommand($text)) {
            $page = "home";
        } else {
            $page = self::detectPage($text, []);
        }
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
                "reply" => self::voiceBackendTechnicalReply($language),
                "reply_source" => "vapi_internal_api_error",
                "http_status" => $status
            ];
        }

        $decoded = json_decode((string) $response, true);
        return is_array($decoded) ? $decoded : ["status" => "error", "reply" => self::voiceBackendTechnicalReply($language)];
    }

    private static function voiceBackendTechnicalReply($language) {
        if ($language === "hi") return "Is request ko process karte waqt technical issue aaya. Kripya thodi der baad dobara try kijiye.";
        if ($language === "kn") return "Ee request process maduvaga technical issue aayitu. Dayavittu swalpa samayada nantara matte try maadi.";
        return "I could not process this request right now due to a technical issue. Please try again after some time.";
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
        // Navigation is intentionally owned only by routeIntent(). Fallback
        // api.php responses may classify text as navigation, but they must not
        // move the frontend because that reintroduces competing routers.
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
        if (preg_match('/\b(registration|register|registation|ragistration|rijistreshan)\b/u', $text) || (preg_match('/\berp\s+page\b/u', $text) && preg_match('/\b(open|go|navigate|show|take|visit)\b/u', $text))) {
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
        if (preg_match('/\b(profile|profail|profle)\b/u', $text)) {
            return "profile";
        }
        if (preg_match('/\b(result|results|marksheet|marks|result\s+page)\b/u', $text)) {
            return "results";
        }
        if (preg_match('/\b(portal|role\s+portal)\b/u', $text)) {
            return "portal";
        }
        return "";
    }

    private static function normalizeKannadaNavigationTerms($text) {
        $normalized = (string) $text;
        $replacements = [
            '/\\b(?:hogu|hogi|hofgu|hoggu|hogbu|hgo|ge\\s+hogu|ge\\s+hofgu|ge\\s+hogi)\\b/u' => ' go ',
            '/ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²Ãƒâ€šÃ‚Â¹ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â³ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â¹ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬ÂÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²Ãƒâ€šÃ‚Â¿|ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²Ãƒâ€šÃ‚Â¹ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â³ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â¹ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬ÂÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â³Ãƒâ€šÃ‚Â|ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²Ãƒâ€šÃ‚Â¹ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â³ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â¹ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬ÂÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â³Ãƒâ€šÃ‚ÂÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â³Ãƒâ€šÃ‚Â|ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²Ãƒâ€šÃ‚Â¹ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â³ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â¹ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬ÂÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â³ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â¡ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â¢ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â³Ãƒâ€šÃ‚Â|ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²Ãƒâ€šÃ‚Â¹ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â³ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â¹ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬ÂÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â³ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â¡ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â¢ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²Ãƒâ€šÃ‚Â¾ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬ÂÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²Ãƒâ€šÃ‚Â¿ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²Ãƒâ€šÃ‚Â¦ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â³ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â /u' => ' go ',
            '/ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²Ãƒâ€šÃ‚Â¤ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â³ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²Ãƒâ€šÃ‚Â°ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â³ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²Ãƒâ€šÃ‚Â¯ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²Ãƒâ€šÃ‚Â¿ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²Ãƒâ€šÃ‚Â°ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²Ãƒâ€šÃ‚Â¿|ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²Ãƒâ€šÃ‚Â¤ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â³ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²Ãƒâ€šÃ‚Â°ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â³ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â |ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²Ãƒâ€šÃ‚Â¤ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â³ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²Ãƒâ€šÃ‚Â°ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â³ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²Ãƒâ€šÃ‚Â¯ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â³Ãƒâ€šÃ‚Â|ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²ÃƒÂ¢Ã¢â€šÂ¬Ã…â€œÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²Ãƒâ€šÃ‚ÂªÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²Ãƒâ€šÃ‚Â¨ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â³Ãƒâ€šÃ‚Â\s*ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²Ãƒâ€šÃ‚Â®ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²Ãƒâ€šÃ‚Â¾ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²Ãƒâ€šÃ‚Â¡ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²Ãƒâ€šÃ‚Â¿|ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²ÃƒÂ¢Ã¢â€šÂ¬Ã…â€œÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²Ãƒâ€šÃ‚ÂªÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²Ãƒâ€šÃ‚Â¨ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â³Ãƒâ€šÃ‚Â\s*ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²Ãƒâ€šÃ‚Â®ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²Ãƒâ€šÃ‚Â¾ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²Ãƒâ€šÃ‚Â¡ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â³Ãƒâ€šÃ‚Â|ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²ÃƒÂ¢Ã¢â€šÂ¬Ã…â€œÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²Ãƒâ€šÃ‚ÂªÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²Ãƒâ€šÃ‚Â¨ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â³Ãƒâ€šÃ‚Â/u' => ' open ',
            '/ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²Ãƒâ€šÃ‚Â¤ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â³ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â¹ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²Ãƒâ€šÃ‚Â°ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²Ãƒâ€šÃ‚Â¿ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²Ãƒâ€šÃ‚Â¸ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²Ãƒâ€šÃ‚Â¿|ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²Ãƒâ€šÃ‚Â¤ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â³ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â¹ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²Ãƒâ€šÃ‚Â°ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²Ãƒâ€šÃ‚Â¿ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²Ãƒâ€šÃ‚Â¸ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â³Ãƒâ€šÃ‚Â|ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²Ãƒâ€šÃ‚Â¨ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â³ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â¹ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²Ãƒâ€šÃ‚Â¡ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²Ãƒâ€šÃ‚Â¿|ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²Ãƒâ€šÃ‚Â¨ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â³ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â¹ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²Ãƒâ€šÃ‚Â¡ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â³Ãƒâ€šÃ‚Â/u' => ' show ',
            '/ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²Ãƒâ€šÃ‚ÂªÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â³Ãƒâ€šÃ‚ÂÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²Ãƒâ€¦Ã‚Â¸|ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²Ãƒâ€šÃ‚ÂªÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â³ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â¡ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²Ãƒâ€¦Ã¢â‚¬Å“ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â³Ãƒâ€šÃ‚Â/u' => ' page ',
            '/ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²Ãƒâ€šÃ‚Â®ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â³Ãƒâ€šÃ‚ÂÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Å“ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â³Ãƒâ€šÃ‚ÂÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²Ãƒâ€šÃ‚Â¯\s*ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²Ãƒâ€šÃ‚ÂªÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â³Ãƒâ€šÃ‚ÂÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²Ãƒâ€¦Ã‚Â¸|ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²Ãƒâ€šÃ‚Â®ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â³ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â¡ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²Ãƒâ€šÃ‚Â¨ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â³Ãƒâ€šÃ‚Â\s*ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²Ãƒâ€šÃ‚ÂªÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â³ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â¡ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²Ãƒâ€¦Ã¢â‚¬Å“ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â³Ãƒâ€šÃ‚Â|ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²Ãƒâ€šÃ‚Â¹ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â³ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â¹ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²Ãƒâ€šÃ‚Â®ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â³Ãƒâ€šÃ‚Â|ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²Ãƒâ€šÃ‚Â®ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²Ãƒâ€šÃ‚Â¨ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â³ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â /u' => ' home ',
            '/\\b(?:registration|register|rijistreshan|registreshan|regestration)\\b/u' => ' registration ',
            '/ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²Ãƒâ€šÃ‚Â°ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²Ãƒâ€šÃ‚Â¿ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²Ãƒâ€¦Ã¢â‚¬Å“ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²Ãƒâ€šÃ‚Â¿ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²Ãƒâ€šÃ‚Â¸ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â³Ãƒâ€šÃ‚ÂÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²Ãƒâ€¦Ã‚Â¸ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â³Ãƒâ€šÃ‚ÂÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²Ãƒâ€šÃ‚Â°ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â³ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â¡ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²Ãƒâ€šÃ‚Â·ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²Ãƒâ€šÃ‚Â¨ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â³Ãƒâ€šÃ‚Â|ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²Ãƒâ€šÃ‚Â°ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²Ãƒâ€šÃ‚Â¿ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²Ãƒâ€¦Ã¢â‚¬Å“ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²Ãƒâ€šÃ‚Â¿ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²Ãƒâ€šÃ‚Â¸ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â³Ãƒâ€šÃ‚ÂÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²Ãƒâ€¦Ã‚Â¸ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â³Ãƒâ€šÃ‚ÂÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²Ãƒâ€šÃ‚Â°ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â³ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â¡ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²Ãƒâ€šÃ‚Â¶ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²Ãƒâ€šÃ‚Â¨ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â³Ãƒâ€šÃ‚Â|ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²Ãƒâ€šÃ‚Â¨ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â³ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â¹ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²Ãƒâ€šÃ‚Â¦ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²Ãƒâ€šÃ‚Â£ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²Ãƒâ€šÃ‚Â¿/u' => ' registration ',
            '/ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²Ãƒâ€šÃ‚ÂªÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â³Ãƒâ€šÃ‚ÂÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²Ãƒâ€šÃ‚Â°ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â³Ãƒâ€¦Ã‚Â ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²Ãƒâ€šÃ‚Â«ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â³Ãƒâ€¹Ã¢â‚¬Â ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²Ãƒâ€šÃ‚Â²ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â³Ãƒâ€šÃ‚Â/u' => ' profile ',
            '/ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²Ãƒâ€šÃ‚ÂªÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â³ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â¡ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²Ãƒâ€šÃ‚Â®ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â³ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²Ãƒâ€¦Ã‚Â¸ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â³Ãƒâ€šÃ‚Â|ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²Ãƒâ€šÃ‚ÂªÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²Ãƒâ€šÃ‚Â¾ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²Ãƒâ€šÃ‚ÂµÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²Ãƒâ€šÃ‚Â¤ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²Ãƒâ€šÃ‚Â¿|ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²Ãƒâ€šÃ‚Â¶ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â³Ãƒâ€šÃ‚ÂÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²Ãƒâ€šÃ‚Â²ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â³Ãƒâ€šÃ‚ÂÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â¢/u' => ' payment ',
            '/ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²Ãƒâ€šÃ‚Â°ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²Ãƒâ€šÃ‚Â¿ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²Ãƒâ€šÃ‚Â¸ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²Ãƒâ€šÃ‚Â²ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â³Ãƒâ€šÃ‚ÂÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²Ãƒâ€¦Ã‚Â¸ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â³Ãƒâ€šÃ‚Â|ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²Ãƒâ€šÃ‚Â«ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²Ãƒâ€šÃ‚Â²ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²Ãƒâ€šÃ‚Â¿ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²Ãƒâ€šÃ‚Â¤ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²Ãƒâ€šÃ‚Â¾ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²Ãƒâ€šÃ‚Â¶|ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â¦ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â¢ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²Ãƒâ€šÃ‚ÂªÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²Ãƒâ€¦Ã‚Â¸ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â³Ãƒâ€šÃ‚ÂÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²Ãƒâ€¦Ã‚Â¸ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²Ãƒâ€šÃ‚Â¿|ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²Ãƒâ€šÃ‚Â®ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²Ãƒâ€šÃ‚Â¾ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²Ãƒâ€šÃ‚Â°ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â³Ãƒâ€šÃ‚ÂÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â¢ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â³Ãƒâ€šÃ‚ÂÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²Ãƒâ€šÃ‚Â¸ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â³Ãƒâ€šÃ‚Â/u' => ' result ',
            '/ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²Ãƒâ€šÃ‚Â¸ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²Ãƒâ€šÃ‚Â°ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â³Ãƒâ€šÃ‚ÂÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²Ãƒâ€¦Ã‚Â¸ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²Ãƒâ€šÃ‚Â¿ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²Ãƒâ€šÃ‚Â«ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²Ãƒâ€šÃ‚Â¿ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â¢ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â³ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â¡ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²Ãƒâ€¦Ã‚Â¸ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â³Ãƒâ€šÃ‚Â|ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²Ãƒâ€šÃ‚ÂªÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â³Ãƒâ€šÃ‚ÂÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²Ãƒâ€šÃ‚Â°ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²Ãƒâ€šÃ‚Â®ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²Ãƒâ€šÃ‚Â¾ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²Ãƒâ€šÃ‚Â£ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²Ãƒâ€šÃ‚ÂªÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²Ãƒâ€šÃ‚Â¤ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â³Ãƒâ€šÃ‚ÂÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²Ãƒâ€šÃ‚Â°|ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²Ãƒâ€šÃ‚Â¡ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²Ãƒâ€šÃ‚Â¿ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²Ãƒâ€¦Ã¢â‚¬Å“ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²Ãƒâ€šÃ‚Â¿ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²Ãƒâ€¦Ã‚Â¸ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²Ãƒâ€šÃ‚Â²ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â³Ãƒâ€šÃ‚Â/u' => ' certificate ',
            '/ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²Ãƒâ€šÃ‚Â¡ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â³Ãƒâ€šÃ‚ÂÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²Ãƒâ€šÃ‚Â¯ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²Ãƒâ€šÃ‚Â¾ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²Ãƒâ€šÃ‚Â¶ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â³Ãƒâ€šÃ‚Â\s*ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â³ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â¹ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²Ãƒâ€šÃ‚Â°ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â³Ãƒâ€šÃ‚ÂÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²Ãƒâ€šÃ‚Â¡ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â³Ãƒâ€šÃ‚Â|ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²Ãƒâ€šÃ‚Â¡ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â³Ãƒâ€šÃ‚ÂÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²Ãƒâ€šÃ‚Â¯ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²Ãƒâ€šÃ‚Â¾ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²Ãƒâ€šÃ‚Â¶ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â³Ãƒâ€šÃ‚ÂÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã¢â‚¬â„¢ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â³ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â¹ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²Ãƒâ€šÃ‚Â°ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â³Ãƒâ€šÃ‚ÂÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²Ãƒâ€šÃ‚Â¡ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â³Ãƒâ€šÃ‚Â|ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²Ãƒâ€šÃ‚Â¡ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â³Ãƒâ€šÃ‚ÂÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²Ãƒâ€šÃ‚Â¯ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²Ãƒâ€šÃ‚Â¾ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²Ãƒâ€šÃ‚Â¶ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â³Ãƒâ€šÃ‚ÂÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â³ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â¹ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²Ãƒâ€šÃ‚Â°ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â³Ãƒâ€šÃ‚ÂÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²Ãƒâ€šÃ‚Â¡ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â³Ãƒâ€šÃ‚Â/u' => ' dashboard ',
            '/ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²Ãƒâ€šÃ‚ÂªÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â³ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â¹ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²Ãƒâ€šÃ‚Â°ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â³Ãƒâ€šÃ‚ÂÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²Ãƒâ€¦Ã‚Â¸ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â²Ãƒâ€šÃ‚Â²ÃƒÆ’Ã‚Â Ãƒâ€šÃ‚Â³Ãƒâ€šÃ‚Â/u' => ' portal '
        ];

        foreach ($replacements as $pattern => $replacement) {
            $normalized = preg_replace($pattern, $replacement, $normalized);
        }

        return $normalized;
    }
    private static function normalizeIntentText($text) {
        $text = self::normalizeKannadaNavigationTerms(strtolower(trim((string) $text)));
        $text = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $text);
        return trim(preg_replace('/\s+/u', ' ', (string) $text));
    }

    private static function hasNavigationVerb($text) {
        return (bool) preg_match('/\b(open|go|goto|navigate|show|take|visit|kholo|khol|dikhao|jao|chalo|torisu|hogu|hogi|hofgu|hoggu|nodi|maadi|madi|tere|tereyiri)\b/u', $text);
    }

    private static function isExplicitHomeCommand($text) {
        return (bool) preg_match('/\b(come\s+back|comeback|go\s+back|back\s+home|back\s+to\s+home|back\s+to\s+main|return\s+home|return\s+to\s+home|return\s+to\s+main|go\s+home|open\s+home|show\s+home|home\s+page|main\s+page)\b|\bhome(?:\s+\p{L}+){0,2}\s+(?:go|open|show)\b/u', $text);
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
            "registration page" => "registration",
            "erp registration" => "registration",
            "erp registration page" => "registration",
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

    private static function toolResult($toolCallId, $toolName, $result) {
        $encodedResult = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encodedResult === false) {
            $encodedResult = json_encode(["reply" => "Tool result could not be encoded."], JSON_UNESCAPED_SLASHES);
        }

        return [
            "name" => $toolName !== "" ? $toolName : "gmu_voice_assistant",
            "toolCallId" => $toolCallId,
            "result" => $encodedResult
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
        if (preg_match('/\b(torisu|torisi|hogu|hogi|nodu|nodi|maadi|madi|madu|beku|bekagide|kannada|kannadadalli|heli|mathadu|maatadu|sari|nimma|nanna|elli|yelli|yaava|yava|hege|madodu|madbeku|kodu|kodi)\b/u', $roman)) return "kn";
        if (preg_match('/\b(hindi|mein|me|karo|kholo|dikhao|dikhana|batao|batana|chalo|mera|meri|aapka|kripya|bharna|karna|kijiye|kaise|kahan|kya|pichla|pehla|doosra|teesra|chautha|panchwa)\b/u', $roman)) return "hi";
        return "en";
    }
    private static function defaultApiUrl() {
        $host = $_SERVER["HTTP_HOST"] ?? "localhost:8080";
        $scriptDir = rtrim(str_replace("\\", "/", dirname($_SERVER["SCRIPT_NAME"] ?? "/gmu-voice-assistant/backend")), "/");
        $scheme = (!empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off") ? "https" : "http";
        return $scheme . "://" . $host . $scriptDir . "/api.php";
    }
}














