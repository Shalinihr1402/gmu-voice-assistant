<?php

require_once __DIR__ . "/VapiSessionService.php";
require_once __DIR__ . "/VapiAssistantConfigService.php";
require_once __DIR__ . "/LlmService.php";
require_once __DIR__ . "/ERPQueryService.php";
require_once __DIR__ . "/VapiSecurityService.php";
require_once __DIR__ . "/LoggerService.php";
require_once __DIR__ . "/TraceContextService.php";

class VapiToolService {
    private static $activeToolContext = [];

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

    private static function payloadScalar($payload, $paths) {
        foreach ($paths as $path) {
            $current = $payload;
            foreach (explode(".", $path) as $part) {
                if (is_array($current) && array_key_exists($part, $current)) {
                    $current = $current[$part];
                } else {
                    $current = null;
                    break;
                }
            }
            if (is_scalar($current)) {
                $value = trim((string) $current);
                if ($value !== "") return $value;
            }
        }
        return "";
    }

    public static function buildToolResults($payload) {
        $batchStart = LoggerService::nowMs();
        $toolCalls = self::extractToolCalls($payload);
        LoggerService::voice("vapi_tool_batch_started", [
            "tool_call_count" => count($toolCalls)
        ]);

        $results = [];
        foreach ($toolCalls as $toolCall) {
            $results[] = self::handleToolCall($toolCall, $payload);
        }

        $latency = LoggerService::durationMs($batchStart);
        LoggerService::voice("vapi_tool_batch_completed", [
            "status" => "success",
            "tool_call_count" => count($results),
            "latency_ms" => $latency
        ]);
        LoggerService::markPerformance("vapi_tool_batch_latency", $latency);
        return ["results" => $results];
    }

    private static function handleToolCall($toolCall, $payload = []) {
        $toolStart = LoggerService::nowMs();
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
        $sessionToken = self::extractSessionToken($args, $payload);
        $toolExecutionId = $id !== "" ? (string) $id : TraceContextService::id("tool");
        $requestId = self::payloadScalar($payload, ["request_id", "message.request_id", "message.call.assistantOverrides.variableValues.request_id"]);
        $callId = self::payloadScalar($payload, ["call_id", "message.call.id", "message.callId", "message.call.assistantOverrides.variableValues.call_id"]);

        TraceContextService::set([
            "tool_execution_id" => $toolExecutionId,
            "request_id" => $requestId,
            "call_id" => $callId,
            "session_token_hash" => LoggerService::tokenHash($sessionToken)
        ]);
        self::$activeToolContext = [
            "start_ms" => $toolStart,
            "tool_name" => $toolName,
            "tool_execution_id" => $toolExecutionId,
            "request_id" => $requestId,
            "call_id" => $callId,
            "query" => $query,
            "normalized_query" => self::normalizeIntentText($query),
            "language" => $language,
            "session_token" => $sessionToken
        ];

        LoggerService::voice("vapi_tool_execution_started", [
            "tool_name" => $toolName,
            "tool_arguments" => $args,
            "query" => $query,
            "normalized_query" => self::normalizeIntentText($query),
            "language" => $language
        ]);

        if ($query === "") {
            return self::toolResult($id, [
                "reply" => "Sorry, I didn't catch that. Could you please repeat your question?",
                "intent" => "EMPTY_QUERY",
                "route" => "clarification"
            ]);
        }

        $queryValidation = VapiSecurityService::validateQuery($query);
        if (!$queryValidation["ok"]) {
            LoggerService::warning("vapi_tool_query_validation_failed", [
                "status" => "validation_failed",
                "error_message" => $queryValidation["message"] ?? "Invalid query"
            ]);
            return self::toolResult($id, [
                "reply" => $queryValidation["message"] ?? "Please repeat your question.",
                "intent" => "INVALID_QUERY",
                "route" => "validation"
            ]);
        }

        $directLanguageSwitch = self::directLanguageSwitchResult($query, $language);
        if ($directLanguageSwitch) {
            return self::toolResult($id, $directLanguageSwitch);
        }

        $session = VapiSessionService::resolve($sessionToken);
        if (!$session) {
            LoggerService::security("vapi_tool_session_validation_failed", [
                "status" => "session_expired",
                "error_message" => "Session token could not be resolved"
            ]);
            return self::toolResult($id, [
                "reply" => self::sessionExpiredReply($language),
                "intent" => "SESSION_EXPIRED",
                "route" => "auth"
            ]);
        }
        TraceContextService::set([
            "user_id" => (int) ($session["user_id"] ?? 0)
        ]);

        $securityValidation = VapiSecurityService::validateToolExecution($sessionToken, $id);
        if (!$securityValidation["ok"]) {
            LoggerService::security("vapi_tool_security_validation_failed", [
                "status" => "security_rejected",
                "error_message" => $securityValidation["message"] ?? "Tool execution rejected"
            ]);
            return self::toolResult($id, [
                "reply" => $securityValidation["message"] ?? "Your secure voice request could not be verified.",
                "intent" => "SECURITY_REJECTED",
                "route" => "security"
            ]);
        }

        // Conversation memory: load prior context and enrich short follow-up queries.
        // Uses file-based storage (same dir as VapiSessionService) so it persists
        // across separate VAPI webhook invocations without requiring PHP session cookies.
        $convCtx = self::loadConvContext($sessionToken);
        if (!empty($convCtx)) {
            $enrichedQuery = self::enrichQueryWithContext($query, $convCtx);
            if ($enrichedQuery !== $query) {
                $query = $enrichedQuery;
                self::$activeToolContext["query"] = $query;
                self::$activeToolContext["normalized_query"] = self::normalizeIntentText($query);
            }
        }

        $directSupportTicket = self::directSupportTicketResult($query, $language, $session);
        if ($directSupportTicket) {
            return self::toolResult($id, $directSupportTicket);
        }

        $directRegistrationAction = self::directRegistrationActionResult($query, $language);
        if ($directRegistrationAction) {
            return self::toolResult($id, $directRegistrationAction);
        }

        $directErpInfo = self::directErpInfoResult($query, $language, $session);
        if ($directErpInfo) {
            return self::toolResult($id, $directErpInfo);
        }

        $directResultNavigation = self::directResultNavigationResult($query, $language, $session);
        if ($directResultNavigation) {
            return self::toolResult($id, $directResultNavigation);
        }

        $directNavigation = self::directNavigationResult($query, $language);
        if ($directNavigation) {
            return self::toolResult($id, $directNavigation);
        }

        $intentStart = LoggerService::nowMs();
        $erpIntent = class_exists("ERPQueryService") ? ERPQueryService::detectIntent($query, $language) : "";
        $intentLatency = LoggerService::durationMs($intentStart);
        LoggerService::info("vapi_intent_detection_completed", [
            "query" => $query,
            "normalized_query" => self::normalizeIntentText($query),
            "detected_intent" => $erpIntent,
            "confidence" => $erpIntent !== "" ? "rule_match" : "none",
            "selected_route" => $erpIntent !== "" ? "erp_query_service" : "fallback",
            "latency_ms" => $intentLatency
        ]);
        LoggerService::markPerformance("vapi_intent_detection_latency", $intentLatency, ["detected_intent" => $erpIntent]);
        if ($erpIntent !== "") {
            $erpResult = ERPQueryService::handle($erpIntent, $query, $language, $session);
            if (is_array($erpResult)) {
                $erpResult["debug"]["intent_router"] = [
                    "handler" => "erp_query",
                    "intent" => $erpIntent,
                    "reason" => "erp_query_service"
                ];
                return self::toolResult($id, $erpResult);
            }
        }

        $directPaymentHelp = self::directPaymentHelpResult($query, $language);
        if ($directPaymentHelp) {
            return self::toolResult($id, $directPaymentHelp);
        }

        $directCourseCode = self::directCourseCodeResult($query, $language, $session["session_id"] ?? "");
        if ($directCourseCode) {
            return self::toolResult($id, $directCourseCode);
        }
        $apiResponse = self::callExistingApi($query, $language, $session["session_id"] ?? "");
        $result = self::shapeResult($apiResponse, $query, $language);
        return self::toolResult($id, $result);
    }


    private static function sessionExpiredReply($language) {
        if ($language === "hi") return "Aapka voice session expire ho gaya hai. Page ek baar refresh karein — bas ek second lagega aur phir hum continue kar sakte hain.";
        if ($language === "kn") return "Nimma voice session expire agide. Page ondu sari refresh madi — takshaṇa ready aaguttide.";
        return "Your voice session has expired. Please refresh the page to continue — it only takes a moment.";
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
    private static function directErpInfoResult($query, $language, $session) {
        $text = self::normalizeIntentText($query);
        $studentId = self::studentIdFromSession($session);

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
            if ($language === "hi") return "Aapki hostel application ka abhi koi record nahi mila. Agar aapne recently apply kiya hai, toh abhi bhi processing ho sakti hai.";
            if ($language === "kn") return "Nimma hostel application record sigalilla. Neevu ilidaagale apply madiddarey, adu inka processing aaguttiraborudu.";
            return "No hostel application found for your account. If you applied recently, it may still be processing.";
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
            if ($language === "hi") return "Aaj classes cancel hone ka koi notice nahi hai — classes regular schedule par chal rahi hain.";
            if ($language === "kn") return "Ivattu classes cancel agive anta notice illa — classes regular schedule nalli naḍeyuttide.";
            return "No cancellation notice for today — classes are running on schedule.";
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
        if ($language === "hi") return "Abhi " . $topic . " ki information nahi aa rahi. Thodi der mein dobara try karein ya page ek baar refresh karein.";
        if ($language === "kn") return "Iga " . $topic . " mahiti sigalilla. Dayavittu swalpa hogondu matte try madi athava page refresh madi.";
        return "I couldn't retrieve " . $topic . " information right now. Please try again in a moment or refresh the page.";
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

        // ── Ticket status check ───────────────────────────────────────────────
        $isStatusCheck = (bool) preg_match('/\b(ticket\s*status|status\s*of\s*(my\s*)?ticket|my\s*ticket|ticket\s*update|ticket\s*resolve|ticket\s*open|ticket\s*closed|ticket\s*kya\s*hua|ticket\s*ka\s*status|ticket\s*check|mera\s*ticket|nanna\s*ticket|ticket\s*hegide|ticket\s*aayitu)\b/ui', $text);
        $hasTicketCode = (bool) preg_match('/\bGMU-\d{4}\b/i', $query);

        if ($isStatusCheck || $hasTicketCode) {
            $studentId = self::studentIdFromSession($session);
            if ($studentId <= 0) return null;

            // Extract specific ticket code if mentioned
            $ticketCode = null;
            if (preg_match('/\b(GMU-\d{4})\b/i', $query, $m)) {
                $ticketCode = strtoupper($m[1]);
            }

            return self::supportTicketStatusResult($studentId, $ticketCode, $language);
        }

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

        $studentId = self::studentIdFromSession($session);
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
            "reply" => self::supportTicketSuccessReply($ticket["ticket_code"], $language),
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
                "issue_type" => $ticket["issue_type"]
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

        return ["ticket_code" => $ticketCode, "issue_type" => $issueType];
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

    private static function supportTicketSuccessReply($ticketCode, $language) {
        if ($language === "hi") return "Aapka ERP support ticket successfully raise ho gaya hai. Ticket ID: " . $ticketCode . ".";
        if ($language === "kn") return "Nimma ERP support ticket create agide. Ticket ID: " . $ticketCode . ".";
        return "Your ERP support ticket has been raised successfully. Ticket ID: " . $ticketCode . ".";
    }

    private static function supportTicketErrorReply($language) {
        if ($language === "hi") return "Support ticket create karte waqt problem aayi. Kripya thodi der baad try kijiye.";
        if ($language === "kn") return "Support ticket create maduvaga problem ayitu. Dayavittu swalpa samayada nantara try madi.";
        return "I wasn't able to raise the support ticket right now. Please try again in a few moments, or visit the ERP portal directly to submit your request.";
    }
    private static function supportTicketStatusResult($studentId, $ticketCode, $language) {
        require __DIR__ . "/../config/db.php";
        if (!isset($conn) || !$conn) {
            return [
                "reply" => self::supportTicketErrorReply($language),
                "intent" => "GET_TICKET_STATUS_ERROR",
                "route" => "support_ticket",
                "language" => $language,
                "client_action" => null,
                "suggestion" => null,
                "quick_actions" => [],
                "debug" => ["source" => "vapi_tool_service", "reply_source" => "ticket_status_db_error"]
            ];
        }

        if ($ticketCode !== null) {
            // Specific ticket by code
            $stmt = $conn->prepare("SELECT ticket_code, issue_type, status, priority, created_at FROM support_tickets WHERE ticket_code = ? AND student_id = ? LIMIT 1");
            if (!$stmt) return null;
            $stmt->bind_param("si", $ticketCode, $studentId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$row) {
                if ($language === "hi") $reply = "Ticket {$ticketCode} aapke account mein nahi mila. Ticket ID check karke dobara try karein.";
                elseif ($language === "kn") $reply = "Ticket {$ticketCode} nimma account nalli sigalilla. Ticket ID check maadi mattomme try maadi.";
                else $reply = "Ticket {$ticketCode} was not found in your account. Please check the ticket ID and try again.";
            } else {
                $reply = self::buildTicketStatusReply([$row], $language, true);
            }
        } else {
            // All tickets for this student — show latest 3
            $stmt = $conn->prepare("SELECT ticket_code, issue_type, status, priority, created_at FROM support_tickets WHERE student_id = ? ORDER BY created_at DESC LIMIT 3");
            if (!$stmt) return null;
            $stmt->bind_param("i", $studentId);
            $stmt->execute();
            $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();

            if (empty($rows)) {
                if ($language === "hi") $reply = "Aapka koi support ticket abhi tak raise nahi hua hai.";
                elseif ($language === "kn") $reply = "Nimma yaavudu support ticket ivaga raise aagilla.";
                else $reply = "You have not raised any support tickets yet.";
            } else {
                $reply = self::buildTicketStatusReply($rows, $language, false);
            }
        }

        return [
            "reply" => $reply,
            "intent" => "GET_TICKET_STATUS",
            "route" => "support_ticket",
            "language" => $language,
            "client_action" => null,
            "suggestion" => null,
            "quick_actions" => [],
            "debug" => ["source" => "vapi_tool_service", "reply_source" => "ticket_status_fetched"]
        ];
    }

    private static function buildTicketStatusReply($rows, $language, $specific) {
        $statusLabels = [
            "open"        => ["en" => "Open (pending)",        "hi" => "Open (pending)",      "kn" => "Open (pending)"],
            "in_progress" => ["en" => "In Progress",           "hi" => "In progress",          "kn" => "In progress"],
            "resolved"    => ["en" => "Resolved",              "hi" => "Resolve ho gaya",      "kn" => "Resolve aagide"],
            "closed"      => ["en" => "Closed",                "hi" => "Closed",               "kn" => "Closed"],
        ];

        $parts = [];
        foreach ($rows as $row) {
            $code     = $row["ticket_code"] ?? "";
            $type     = ucfirst(str_replace("_", " ", $row["issue_type"] ?? "general"));
            $status   = strtolower($row["status"] ?? "open");
            $label    = $statusLabels[$status][$language] ?? ucfirst($status);
            $date     = isset($row["created_at"]) ? date("d M Y", strtotime($row["created_at"])) : "";

            if ($language === "hi") $parts[] = "Ticket {$code} ({$type}): Status — {$label}, raised on {$date}.";
            elseif ($language === "kn") $parts[] = "Ticket {$code} ({$type}): Status — {$label}, {$date} raise aagide.";
            else $parts[] = "Ticket {$code} ({$type}): {$label}, raised on {$date}.";
        }

        $list = implode(" ", $parts);

        if ($specific) return $list;

        if ($language === "hi") return "Aapke recent support tickets: {$list}";
        if ($language === "kn") return "Nimma recent support tickets: {$list}";
        return "Your recent support tickets: {$list}";
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
        $sessionId = is_array($session) ? (string) ($session["session_id"] ?? "") : (string) $session;
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

        $availability = self::loadResultAvailability($sessionId);
        $student = is_array($availability["student"] ?? null) ? $availability["student"] : [];
        $selections = is_array($availability["selections"] ?? null) ? $availability["selections"] : [];
        $currentSemester = (int) ($student["current_semester"] ?? 0);

        if (preg_match('/\b(all|overall|every)\s+(semester|sem)?\s*(result|results|marks)\b|\b(result|results|marks)\s+(for\s+)?(all|every)\s+(semester|sem)s?\b/u', $text)) {
            $wantsOpen = self::hasNavigationVerb($text);
            return [
                "reply" => self::allResultsNavigationReply($language),
                "intent" => $wantsOpen ? "OPEN_ALL_RESULTS" : "GET_ALL_RESULTS_INFO",
                "route" => $wantsOpen ? "navigation" : "erp_query",
                "language" => $language,
                "client_action" => $wantsOpen ? ["type" => "navigate", "path" => "/results", "page" => "results"] : null,
                "suggestion" => $wantsOpen ? null : self::localizeSuggestion("Say 'open results' to view the grade sheet.", $language),
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

        // Fetch actual SGPA + performance commentary from DB so the voice bot speaks real data
        $examLabel = $selected ? strtoupper((string) ($selected["exam"] ?? "SEE")) : "SEE";
        $voiceReply = self::resultNavigationReply($semester, (bool) $selected, $language, $examLabel);
        if (is_array($session)) {
            $studentId = self::studentIdFromSession($session);
            if ($studentId > 0) {
                if (!class_exists("StudentController")) {
                    require_once __DIR__ . "/../intents/controllers/StudentController.php";
                }
                $sgpaReply = StudentController::getSGPA($studentId, $query, $language);
                if (is_string($sgpaReply) && trim($sgpaReply) !== "") {
                    $voiceReply = $sgpaReply;
                }
            }
        }

        $wantsToOpen = self::hasNavigationVerb($text);
        $clientAction = $wantsToOpen ? [
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
        ] : null;

        return [
            "reply" => $voiceReply,
            "intent" => $wantsToOpen ? "OPEN_FILTERED_RESULT" : "GET_RESULT_INFO",
            "route" => $wantsToOpen ? "navigation" : "erp_query",
            "language" => $language,
            "client_action" => $clientAction,
            "suggestion" => $wantsToOpen ? null : self::localizeSuggestion("Say 'open result' to view the grade sheet.", $language),
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
            '/\bre\s+valuation\b|\breevaluation\b|\brivaluation\b|\brevalu\b/u' => ' revaluation ',
            '/\bre\s*[- ]?\s*registration\b|\breregistration\b/u' => ' re-registration ',
            '/\x{0930}\x{093F}\x{091C}\x{0932}\x{094D}\x{091F}|\x{092A}\x{0930}\x{093F}\x{0923}\x{093E}\x{092E}|\x{0928}\x{0924}\x{0940}\x{091C}\x{093E}|\x{092E}\x{093E}\x{0930}\x{094D}\x{0915}\x{094D}\x{0938}|\x{0905}\x{0902}\x{0915}|\x{0917}\x{094D}\x{0930}\x{0947}\x{0921}/u' => ' result ',
            '/\x{0CB0}\x{0CBF}\x{0CB8}\x{0CB2}\x{0CCD}\x{0C9F}\x{0CCD}|\x{0CB0}\x{0CBF}\x{0C9C}\x{0CB2}\x{0CCD}\x{0C9F}\x{0CCD}|\x{0CAB}\x{0CB2}\x{0CBF}\x{0CA4}\x{0CBE}\x{0C82}\x{0CB6}|\x{0CAE}\x{0CBE}\x{0CB0}\x{0CCD}\x{0C95}\x{0CCD}\x{0CB8}\x{0CCD}|\x{0C85}\x{0C82}\x{0C95}|\x{0C97}\x{0CCD}\x{0CB0}\x{0CC7}\x{0CA1}\x{0CCD}/u' => ' result ',
            '/\b(resultu|rijalt|resalt|marksu|marks card|grade card|gradecard|ankagalu|phalitansha|falitansha)\b/u' => ' result ',
            '/\bna result|mera result|nanna result|result torisu|result nodu|marks torisu|sgpa torisu|sgpa dikhao\b/u' => ' result ',
            '/\bpehla|pahla|firstu|ondu|ondane|modala\b/u' => ' first ',
            '/\bdoosra|dusra|secondu|eradu|eradane\b/u' => ' second ',
            '/\bteesra|tisra|thirdu|mooru|moorane\b/u' => ' third ',
            '/\bchautha|choutha|fourthu|naalku|nalku|naalkane|nalkane\b/u' => ' fourth ',
            '/\bpanchwa|paanchwa|fifthu|aidu|aidane\b/u' => ' fifth ',
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

        // Exam type — matches all 4 real ERP options: SEE, RESIT, RE-Registration, Revaluation
        $exam = "";
        if (preg_match('/\b(revaluation|reval|re[\s\-]?valu)\b/u', $text)) {
            $exam = "Revaluation";
        } elseif (preg_match('/\b(re[\s\-]?registration|reregistration|re[\s\-]?reg)\b/u', $text)) {
            $exam = "RE-Registration";
        } elseif (preg_match('/\b(resit|re[\s\-]?sit)\b/u', $text)) {
            $exam = "RESIT";
        } elseif (preg_match('/\b(see|s\.e\.e)\b/u', $text)) {
            $exam = "SEE";
        }

        // Season — ODD = Jan exams (sem 1,3,5,7), EVEN = June exams (sem 2,4,6,8)
        $season = "";
        if (preg_match('/\b(odd|jan|january|first[\s\-]?half)\b/u', $text)) {
            $season = "ODD";
        } elseif (preg_match('/\b(even|june|july|second[\s\-]?half)\b/u', $text)) {
            $season = "EVEN";
        }

        // Academic year — exact format "2024-25" or loose "2024", "last year", "this year"
        $year = "";
        if (preg_match('/\b(20\d{2})\s*-\s*(\d{2})\b/u', $text, $matches)) {
            $year = $matches[1] . "-" . $matches[2];
        } elseif (preg_match('/\b(20\d{2})\b/u', $text, $matches)) {
            $y = (int) $matches[1];
            $year = $y . "-" . substr((string)($y + 1), 2);
        } elseif (preg_match('/\b(last\s+year|previous\s+year|pichle\s+saal|hindina\s+varsha)\b/u', $text)) {
            $cy = (int) date("Y"); $pm = (int) date("n");
            $sy = $pm >= 7 ? $cy - 1 : $cy - 2;
            $year = $sy . "-" . substr((string)($sy + 1), 2);
        } elseif (preg_match('/\b(this\s+year|current\s+year|ee\s+varsha|is\s+saal)\b/u', $text)) {
            $cy = (int) date("Y"); $pm = (int) date("n");
            $sy = $pm >= 7 ? $cy : $cy - 1;
            $year = $sy . "-" . substr((string)($sy + 1), 2);
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

    private static function resultSummaryFromSelection($sessionId, $student, $semester, $selected, $language) {
        if ($sessionId === "" || !is_array($selected) || empty($selected)) {
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
            return "";
        }

        $decoded = json_decode((string) $response, true);
        if (!is_array($decoded)) {
            return "";
        }

        $selection = is_array($decoded["selection"] ?? null) ? $decoded["selection"] : $selected;
        $summary = is_array($decoded["summary"] ?? null) ? $decoded["summary"] : [];
        $sgpa = $summary["sgpa"] ?? null;
        if ($sgpa === null || $sgpa === "") {
            return "";
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
            if ($sgpa >= 9.5) return "Kamaal! Aap apni class ke top mein hain — aise hi jabardast kaam karte raho!";
            if ($sgpa >= 9) return "Excellent! Aap distinction level par hain. Itna accha pace maintain karo!";
            if ($sgpa >= 8) return "First class! Aapko apne aap par garv hona chahiye — thoda aur push karo!";
            if ($sgpa >= 7) return "Achha kiya! Thoda aur focus karoge toh first class aa jayega.";
            if ($sgpa >= 6) return "Aap pass hain. Weak subjects par zyada dhyan do — improvement zaroor hoga.";
            return "Aap pass hain, lekin serious improvement chahiye. Mentor se milkar plan banao.";
        }
        if ($language === "kn") {
            if ($sgpa >= 9.5) return "Brilliant! Neevu nimma class topalli iddira — ee outstanding work munduvarisi!";
            if ($sgpa >= 9) return "Excellent! Neevu distinction level nalli iddira. Ee pace maintain madi!";
            if ($sgpa >= 8) return "First class! Neevu nimma bagge garva padabeku — innu push madi, distinction kaigochutte!";
            if ($sgpa >= 7) return "Channagi madiddira! Innu focus madidare first class asanavaguttide.";
            if ($sgpa >= 6) return "Neevu pass agiddira. Weak subjects mele hechchu gaman kodi.";
            return "Neevu pass agiddira, aadare serious improvement beku. Mentor jothe matadi plan madi.";
        }
        if ($sgpa >= 9.5) return "Absolutely brilliant! You are at the top of your class — keep up this outstanding work!";
        if ($sgpa >= 9) return "Excellent! You are performing at distinction level. Keep this pace going!";
        if ($sgpa >= 8) return "First class result! You should be proud of yourself — push a bit more and distinction is within reach!";
        if ($sgpa >= 7) return "Good job! A bit more focus and first class is easily achievable.";
        if ($sgpa >= 6) return "You have passed. Focus on weaker subjects and you will see real improvement.";
        return "You have passed, but serious improvement is needed. Connect with your mentor and build a clear plan.";
    }
    private static function resultNavigationReply($semester, $hasFullSelection, $language, $examType = "SEE") {
        $exam = strtoupper((string) $examType);
        // Human-readable exam label
        $examLabels = [
            "SEE"             => ["en" => "SEE",          "hi" => "SEE",              "kn" => "SEE"],
            "RESIT"           => ["en" => "Resit",        "hi" => "Resit",            "kn" => "Resit"],
            "RE-REGISTRATION" => ["en" => "Re-registration", "hi" => "Re-registration", "kn" => "Re-registration"],
            "REVALUATION"     => ["en" => "Revaluation",  "hi" => "Revaluation",      "kn" => "Revaluation"],
        ];
        $lbl = $examLabels[$exam][$language] ?? $examLabels[$exam]["en"] ?? $exam;

        if ($hasFullSelection) {
            if ($language === "hi") return "Semester {$semester} {$lbl} result check kar raha hoon.";
            if ($language === "kn") return "Semester {$semester} {$lbl} result check maduttiddene.";
            return "Checking your semester {$semester} {$lbl} result.";
        }
        if ($language === "hi") return "Semester {$semester} result ke liye details load kar raha hoon.";
        if ($language === "kn") return "Semester {$semester} result details load maduttiddene.";
        return "Loading your semester {$semester} result.";
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
    private static function directRegistrationActionResult($query, $language) {
        $text = self::normalizeIntentText($query);
        if ($text === "") return null;

        // ── Re-Registration ───────────────────────────────────────────────────
        $isReReg = (bool) preg_match(
            '/\b(re[\s\-]?registration|re[\s\-]?register|re[\s\-]?reg|re\s+registration|reregistration)\b/u', $text
        ) || (bool) preg_match('/\b(re|ree)\s*(reg|regi|reji|rejis)\b/u', $text);

        if ($isReReg) {
            $replies = [
                "en" => "Opening Registration page. To apply for re-registration, click the 'Apply Re-Registration' tile on your dashboard or find it in the Registration section.",
                "hi" => "Registration page open ho raha hai. Re-registration ke liye, apne dashboard mein 'Apply Re-Registration' tile par click karein ya Registration section mein dekhein.",
                "kn" => "Registration page open agide. Re-registration apply madalu, nimma dashboard nalli 'Apply Re-Registration' tile click madi athava Registration section nalli nodri.",
            ];
            $reply = $replies[$language] ?? $replies["en"];
            return [
                "reply" => $reply,
                "intent" => "RE_REGISTRATION",
                "route" => "registration_action",
                "language" => $language,
                "client_action" => ["type" => "navigate", "path" => "/registration", "page" => "re_registration"],
                "quick_actions" => [],
                "debug" => ["source" => "vapi_tool_service", "reply_source" => "re_registration_nav"]
            ];
        }

        // ── Apply Resit ───────────────────────────────────────────────────────
        $isResit = (bool) preg_match(
            '/\b(apply\s+resit|resit\s+apply|resit\s+exam|resit\s+registration|resit|re\s*sit)\b/u', $text
        );

        if ($isResit) {
            $replies = [
                "en" => "Opening Registration page. To apply for a resit exam, go to the 'Apply Resit' tile on your dashboard. Make sure your eligibility is cleared before applying.",
                "hi" => "Registration page open ho raha hai. Resit exam ke liye apply karne ke liye, apne dashboard mein 'Apply Resit' tile par jaayein. Apply karne se pehle eligibility check kar lein.",
                "kn" => "Registration page open agide. Resit exam apply madalu, nimma dashboard nalli 'Apply Resit' tile ge hogi. Apply maduvudakke munche eligibility check madi.",
            ];
            $reply = $replies[$language] ?? $replies["en"];
            return [
                "reply" => $reply,
                "intent" => "APPLY_RESIT",
                "route" => "registration_action",
                "language" => $language,
                "client_action" => ["type" => "navigate", "path" => "/registration", "page" => "resit"],
                "quick_actions" => [],
                "debug" => ["source" => "vapi_tool_service", "reply_source" => "resit_nav"]
            ];
        }

        // ── Apply Exam / SEE ──────────────────────────────────────────────────
        $isApplyExam = (bool) preg_match(
            '/\b(apply\s+(for\s+)?(exam|see|s\s*e\s*e)|see\s+apply|(exam|see)\s+apply|see\s+exam|see\s+registration|apply\s+see)\b/u', $text
        ) && !(bool) preg_match('/\b(result|marks|sgpa|cgpa|grade)\b/u', $text);

        if ($isApplyExam) {
            $replies = [
                "en" => "Opening Registration page. To apply for the SEE exam, click 'Apply Exam' in the top navigation or find the option in the Registration section.",
                "hi" => "Registration page open ho raha hai. SEE exam apply karne ke liye, top navigation mein 'Apply Exam' click karein ya Registration section mein option dhundhein.",
                "kn" => "Registration page open agide. SEE exam apply madalu, top navigation nalli 'Apply Exam' click madi athava Registration section nalli option nodri.",
            ];
            $reply = $replies[$language] ?? $replies["en"];
            return [
                "reply" => $reply,
                "intent" => "APPLY_EXAM",
                "route" => "registration_action",
                "language" => $language,
                "client_action" => ["type" => "navigate", "path" => "/registration", "page" => "apply_exam"],
                "quick_actions" => [],
                "debug" => ["source" => "vapi_tool_service", "reply_source" => "apply_exam_nav"]
            ];
        }

        return null;
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

        // Attendance is shown as an inline chart in the VoiceBot panel unless the user
        // explicitly says "attendance page" — in that case fall through to ERPQueryService.
        if ($page === "attendance" && !preg_match('/\b(page|section)\b/u', $text)) {
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
            "registration"    => "registration page",
            "re_registration" => "re-registration section",
            "resit"           => "resit application section",
            "apply_exam"      => "exam application section",
            "payment"         => "payment portal",
            "results"         => "result page",
            "certificate"     => "competency certificate page",
            "profile"         => "profile page",
            "dashboard"       => "dashboard",
            "attendance"      => "attendance page",
            "hall_ticket"     => "hall ticket page",
            "portal"          => "portal",
            "home"            => "home page"
        ];
        $label = $labels[$page] ?? "page";
        if ($language === "hi") {
            return ucfirst($label) . " open ho gaya.";
        }
        if ($language === "kn") {
            return ucfirst($label) . " open agide.";
        }
        return ucfirst($label) . " opened.";
    }
    private static function callExistingApi($query, $language, $sessionId) {
        $apiStart = LoggerService::nowMs();
        $url = VapiAssistantConfigService::getEnvValue("VOICEBOT_INTERNAL_API_URL", self::defaultApiUrl());
        $userId = (int) (self::$activeToolContext["session"] ?? 0);
        if ($userId === 0 && !empty(self::$activeToolContext["session_token"])) {
            $sess = VapiSessionService::resolve(self::$activeToolContext["session_token"]);
            $userId = (int) ($sess["user_id"] ?? 0);
        }
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

        // Use 127.0.0.1 to avoid DNS lookup delay; pass auth via headers
        $internalUrl = str_replace("localhost", "127.0.0.1", $url);
        $ch = curl_init($internalUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => [
                "Content-Type: application/json",
                "Connection: close",
                "X-Internal-UserId: " . $userId,
                "X-Internal-Secret: " . md5("gmu_internal_" . date("Ymd") . $userId),
                "Cookie: PHPSESSID=" . $sessionId
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 20
        ]);
        $response = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $latency = LoggerService::durationMs($apiStart);
        LoggerService::info("vapi_internal_api_completed", [
            "status" => $status >= 400 || $response === false ? "error" : "success",
            "http_status" => $status,
            "latency_ms" => $latency,
            "error_message" => $error ?: ""
        ]);
        LoggerService::markPerformance("vapi_internal_api_latency", $latency, ["http_status" => $status]);

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

    private static function unknownQueryReply($query, $language) {
        // Detect small talk that somehow bypassed the LLM's direct handling.
        static $smallTalkPatterns = [
            '/\b(hi|hello|hey|good morning|good afternoon|good evening|namaste|namaskara)\b/i',
            '/\b(thank(s| you)|thanks a lot|thank u|shukriya|dhanyavad|dhanyavaadagalu)\b/i',
            '/\b(bye|goodbye|see you|see ya|take care|alvida|hogi banni)\b/i',
            '/\b(how are you|how r u|are you okay|are you fine|kaise ho|hegidira|howdy)\b/i',
            '/\b(have you (eaten|had|taken)|did you (eat|have|take)|lunch|dinner|breakfast|sleep|tired|bored)\b/i',
        ];
        foreach ($smallTalkPatterns as $pattern) {
            if (preg_match($pattern, (string) $query)) {
                if ($language === "hi") return "Main ek AI hoon, lekin aapki madad ke liye hamesha taiyar hoon! Kya aap attendance, fees, ya results check karna chahte hain?";
                if ($language === "kn") return "Nanu AI agiddini, aadare nimage sahaya madalu sadaa siddha! Attendance, fees, athava results check madabekay?";
                return "I'm an AI, but I'm always here to help! Want to check attendance, fees, results, or anything else?";
            }
        }
        // Generic unknown query — list capabilities rather than a dead end.
        if ($language === "hi") return "Mujhe woh samajh nahi aaya. Aap attendance, SGPA, fee balance, exam results, timetable, hall ticket, ya ERP support ke baare mein pooch sakte hain.";
        if ($language === "kn") return "Adhu nanage arthaavagalilla. Attendance, SGPA, fee balance, exam results, timetable, hall ticket, athava ERP support bagge keḷabahududu.";
        return "I didn't quite get that. You can ask me about attendance, SGPA, fee balance, exam results, timetable, hall ticket status, or raise an ERP support ticket.";
    }

    private static function shapeResult($apiResponse, $query, $language) {
        $reply = trim((string) ($apiResponse["reply"] ?? ""));
        if ($reply === "") {
            $reply = self::unknownQueryReply($query, $language);
        }

        $route = (string) ($apiResponse["route"] ?? "llm");
        $intent = (string) ($apiResponse["intent"] ?? "UNKNOWN");
        $clientAction = self::clientActionFromResponse($apiResponse, $query);

        $result = [
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

        if (array_key_exists("visual", $apiResponse)) {
            $result["visual"] = $apiResponse["visual"];
        }

        return $result;
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
        if (preg_match('/\b(re[\s\-]?registration|reregistration)\b/u', $text)) {
            return "re_registration";
        }
        if (preg_match('/\b(apply\s+resit|resit\s+apply|resit\s+exam|resit)\b/u', $text)) {
            return "resit";
        }
        if (preg_match('/\b(apply\s+exam|apply\s+see|see\s+apply|see\s+registration)\b/u', $text) && !preg_match('/\b(result|marks|sgpa)\b/u', $text)) {
            return "apply_exam";
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
        if (preg_match('/\b(attendance|attendence|atendance|attendance\s+page|hajari|hajarati)\b/u', $text)) {
            return "attendance";
        }
        if (preg_match('/\b(hall\s*ticket|hallticket|admit\s*card|exam\s*ticket)\b/u', $text)) {
            return "hall_ticket";
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

    private static function normalizeKannadaNavigationTerms($text) {
        $normalized = (string) $text;
        $replacements = [
            '/\\b(?:hogu|hogi|hofgu|hoggu|hogbu|hgo|ge\\s+hogu|ge\\s+hofgu|ge\\s+hogi)\\b/u' => ' go ',
            '/ಹೋಗಿ|ಹೋಗು|ಹೋಗ್ಬು|ಹೋಗಬೇಕು|ಹೋಗಬೇಕಾಗಿದೆ/u' => ' go ',
            '/ತೆರೆಯಿರಿ|ತೆರೆ|ತೆರೆಯು|ಓಪನ್\s*ಮಾಡಿ|ಓಪನ್\s*ಮಾಡು|ಓಪನ್/u' => ' open ',
            '/ತೋರಿಸಿ|ತೋರಿಸು|ನೋಡಿ|ನೋಡು/u' => ' show ',
            '/ಪುಟ|ಪೇಜ್/u' => ' page ',
            '/ಮುಖ್ಯ\s*ಪುಟ|ಮೇನ್\s*ಪೇಜ್|ಹೋಮ್|ಮನೆ/u' => ' home ',
            '/\\b(?:registration|register|rijistreshan|registreshan|regestration)\\b/u' => ' registration ',
            '/ರಿಜಿಸ್ಟ್ರೇಷನ್|ರಿಜಿಸ್ಟ್ರೇಶನ್|ನೋಂದಣಿ/u' => ' registration ',
            '/ಪ್ರೊಫೈಲ್/u' => ' profile ',
            '/ಪೇಮೆಂಟ್|ಪಾವತಿ|ಶುಲ್ಕ/u' => ' payment ',
            '/ರಿಸಲ್ಟ್|ಫಲಿತಾಂಶ|ಅಂಕಪಟ್ಟಿ|ಮಾರ್ಕ್ಸ್/u' => ' result ',
            '/ಸರ್ಟಿಫಿಕೇಟ್|ಪ್ರಮಾಣಪತ್ರ|ಡಿಜಿಟಲ್/u' => ' certificate ',
            '/ಡ್ಯಾಶ್\s*ಬೋರ್ಡ್|ಡ್ಯಾಶ್‌ಬೋರ್ಡ್|ಡ್ಯಾಶ್ಬೋರ್ಡ್/u' => ' dashboard ',
            '/ಪೋರ್ಟಲ್/u' => ' portal '
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
            "attendance" => "attendance",
            "attendance page" => "attendance",
            "attendance analytics" => "attendance",
            "hall ticket" => "hall_ticket",
            "hallticket" => "hall_ticket",
            "admit card" => "hall_ticket",
            "registration" => "registration",
            "registration page" => "registration",
            "erp registration" => "registration",
            "erp registration page" => "registration",
            "register" => "registration",
            "re registration" => "re_registration",
            "re-registration" => "re_registration",
            "reregistration" => "re_registration",
            "apply re registration" => "re_registration",
            "resit" => "resit",
            "apply resit" => "resit",
            "resit exam" => "resit",
            "apply exam" => "apply_exam",
            "apply see" => "apply_exam",
            "see registration" => "apply_exam",
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
        $allowed = ["/home", "/dashboard", "/registration", "/payment", "/results", "/certificate", "/profile", "/portal", "/attendance", "/attendance-analytics", "/hall-ticket", "/re-registration", "/resit", "/apply-exam"];
        $basePath = parse_url($path, PHP_URL_PATH) ?: "";
        return in_array($basePath, $allowed, true) ? $path : "";
    }
    private static function pagePath($page) {
        $map = [
            "home"           => "/home",
            "dashboard"      => "/dashboard",
            "attendance"     => "/attendance",
            "hall_ticket"    => "/hall-ticket",
            "registration"   => "/registration",
            "re_registration"=> "/registration",
            "resit"          => "/registration",
            "apply_exam"     => "/registration",
            "payment"        => "/payment",
            "results"        => "/results",
            "certificate"    => "/certificate",
            "profile"        => "/profile",
            "portal"         => "/portal"
        ];
        return $map[$page] ?? "";
    }

    private static function toolResult($toolCallId, $result) {
        if (is_array($result) && isset($result["reply"])) {
            $result["reply"] = VapiSecurityService::sanitizeReply($result["reply"]);
        }
        if (is_array($result)) {
            $result["tool_execution_id"] = self::$activeToolContext["tool_execution_id"] ?? $toolCallId;
            $result["request_id"] = self::$activeToolContext["request_id"] ?? "";
            $result["call_id"] = self::$activeToolContext["call_id"] ?? "";
            if (!isset($result["debug"]) || !is_array($result["debug"])) {
                $result["debug"] = [];
            }
            $result["debug"]["tool_execution_id"] = $result["tool_execution_id"];
            $result["debug"]["request_id"] = $result["request_id"];
            $result["debug"]["call_id"] = $result["call_id"];
        }

        $duration = isset(self::$activeToolContext["start_ms"]) ? LoggerService::durationMs(self::$activeToolContext["start_ms"]) : null;
        $responseSize = strlen((string) json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $intent = is_array($result) ? (string) ($result["intent"] ?? "") : "";
        $route = is_array($result) ? (string) ($result["route"] ?? "") : "";
        $status = in_array($route, ["auth", "security", "validation"], true) ? "rejected" : "success";
        LoggerService::voice("vapi_tool_execution_completed", array_merge(self::$activeToolContext, [
            "status" => $status,
            "intent" => $intent,
            "route" => $route,
            "latency_ms" => $duration,
            "response_size_bytes" => $responseSize
        ]));
        if ($duration !== null) {
            LoggerService::markPerformance("vapi_tool_execution_latency", $duration, [
                "intent" => $intent,
                "route" => $route
            ]);
        }

        // Save this turn to conversation context so the next follow-up query can be enriched.
        // Only save for meaningful routes; skip auth/security/validation/clarification exits.
        $convToken = self::$activeToolContext["session_token"] ?? "";
        $convQuery = self::$activeToolContext["query"] ?? "";
        if ($convToken !== "" && $convQuery !== "" &&
            !in_array($route, ["auth", "security", "validation", "clarification"], true)) {
            // Extract the actual semester used in this query (if any) so chained
            // "previous semester?" follow-ups can anchor against it, not just enrolled sem.
            $resolvedSemester = null;
            if (class_exists("StudentController")) {
                $resolvedSemester = StudentController::inferRequestedSemester($convQuery);
            }
            self::saveConvTurn($convToken, $convQuery, (string) ($result["reply"] ?? ""), [
                "intent"          => $intent,
                "language"        => is_array($result) ? (string) ($result["language"] ?? "") : "",
                "pending_intent"  => is_array($result) ? ($result["pending_intent"]  ?? "") : "",
                "pending_titles"  => is_array($result) ? ($result["pending_titles"]  ?? []) : [],
                "last_semester"   => $resolvedSemester,
            ]);
        }

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
        if (preg_match('/\b(torisu|torisi|hogu|hogi|hogbeku|hogali|hogona|nodu|nodi|noddu|maadi|madi|madu|madbeku|maadbeku|beku|bekagide|bekagitu|kannada|kannadadalli|heli|mathadu|maatadu|sari|nimma|nimmadu|nanna|nanage|naanu|namdu|nam|illi|yelli|yaava|yava|hege|madodu|kodu|kodi|eshtu|yeshtu|aitu|aaitu|ayitu|agide|aagide|agutte|bandide|banditu|illa|illave|ellaru|yella|oddu|bartini|barali|hajari|hajarati)\b/u', $roman)) return "kn";
        if (preg_match('/\b(hindi|mein|me|karo|kholo|dikhao|dikhana|dikha|dekho|batao|batana|bolo|bata|chalo|mera|meri|aapka|kripya|bharna|karna|kijiye|kaise|kahan|kya|kitna|kitni|kitne|mujhe|apna|hua|gaya|pichla|pehla|doosra|teesra|chautha|panchwa|aur|nahi|hai|hain|ho|tha|the|abhi|abhibhi)\b/u', $roman)) return "hi";
        return "en";
    }
    private static function defaultApiUrl() {
        $host = $_SERVER["HTTP_HOST"] ?? "localhost:8080";
        $scriptDir = rtrim(str_replace("\\", "/", dirname($_SERVER["SCRIPT_NAME"] ?? "/gmu-voice-assistant/backend")), "/");
        $scheme = (!empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off") ? "https" : "http";
        return $scheme . "://" . $host . $scriptDir . "/api.php";
    }

    // -------------------------------------------------------------------------
    // Conversation memory — file-based, keyed by session token.
    // Stored alongside VapiSessionService tokens so the same /tmp cleanup applies.
    // -------------------------------------------------------------------------

    private static function convContextDir() {
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . "gmu_conv_ctx";
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        return $dir;
    }

    private static function convContextPath($sessionToken) {
        $safe = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) $sessionToken);
        return self::convContextDir() . DIRECTORY_SEPARATOR . $safe . "_ctx.json";
    }

    private static function loadConvContext($sessionToken) {
        if ($sessionToken === "") return [];
        $path = self::convContextPath($sessionToken);
        if (!is_file($path)) return [];
        $data = json_decode((string) @file_get_contents($path), true);
        if (!is_array($data)) return [];
        // Expire after same TTL as the session token (1 hour).
        if ((int) ($data["updated_at"] ?? 0) < time() - 3600) {
            @unlink($path);
            return [];
        }
        return $data;
    }

    private static function saveConvTurn($sessionToken, $query, $reply, $meta) {
        if ($sessionToken === "" || $query === "") return;
        $path = self::convContextPath($sessionToken);
        $data = self::loadConvContext($sessionToken);

        $turns = $data["turns"] ?? [];
        $turns[] = [
            "user"      => $query,
            "assistant" => $reply,
            "intent"    => $meta["intent"] ?? "",
        ];
        if (count($turns) > 10) {
            $turns = array_slice($turns, -10);
        }

        $newIntent   = $meta["intent"] ?? "";
        $newLanguage = $meta["language"] ?? "";

        // Track pending disambiguation.
        $isPendingDisambig = ($newIntent === "COURSE_DISAMBIGUATION");
        $pendingIntent  = $isPendingDisambig ? ($meta["pending_intent"]  ?? "") : "";
        $pendingTitles  = $isPendingDisambig ? ($meta["pending_titles"]  ?? []) : [];

        // Persist the resolved semester so chained "previous semester?" uses it as anchor.
        $newSemester = $meta["last_semester"] ?? null;
        $savedSemester = ($newSemester !== null) ? (int) $newSemester : ($data["last_semester"] ?? null);

        @file_put_contents($path, json_encode([
            "turns"            => $turns,
            "last_intent"      => $newIntent   !== "" ? $newIntent   : ($data["last_intent"]   ?? ""),
            "last_language"    => $newLanguage !== "" ? $newLanguage : ($data["last_language"] ?? ""),
            "last_semester"    => $savedSemester,
            "pending_intent"   => $pendingIntent,
            "pending_titles"   => $pendingTitles,
            "updated_at"       => time(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
    }

    /**
     * Returns true when the query looks like a follow-up fragment rather than a
     * self-contained question. Criteria: short (≤ 8 words) AND contains no
     * standalone ERP intent keyword of its own.
     */
    private static function isFollowUpQuery($query) {
        if (str_word_count(strtolower((string) $query)) > 8) return false;

        $standaloneKeywords = [
            "attendance", "sgpa", "cgpa", "fees", "fee", "result", "results",
            "marks", "profile", "usn", "backlog", "hall ticket", "hallticket",
            "certificate", "registration", "timetable", "assignment", "assignments",
            "faculty", "grievance", "payment", "balance", "receipt", "deadline",
            "course", "courses", "hostel", "cancel", "class"
        ];
        $lower = strtolower((string) $query);
        foreach ($standaloneKeywords as $kw) {
            if (strpos($lower, $kw) !== false) return false;
        }
        return true;
    }

    /**
     * If $query is a follow-up fragment (short, no own intent keyword) and the
     * last context has a resolvable intent, prepend the topic so downstream intent
     * detection can match it.
     *
     * Examples:
     *   "For DBMS?"        + last=GET_ATTENDANCE       → "attendance for dbms"
     *   "How about OS?"    + last=GET_ATTENDANCE       → "attendance for os"
     *   "This semester?"   + last=GET_SGPA             → "SGPA this semester"
     */
    private static function enrichQueryWithContext($query, $context) {
        if (empty($context)) return $query;

        // If there is an unresolved disambiguation, try to match the student's answer
        // to one of the pending course titles before doing normal follow-up enrichment.
        $resolved = self::resolvePendingDisambiguation($query, $context);
        if ($resolved !== null) return $resolved;

        if (!self::isFollowUpQuery($query)) return $query;

        $lastIntent = (string) ($context["last_intent"] ?? "");
        if ($lastIntent === "") return $query;

        static $intentTopicMap = [
            "GET_ATTENDANCE"                   => "attendance",
            "GET_SUBJECT_ATTENDANCE"           => "attendance",
            "GET_SGPA"                         => "SGPA",
            "GET_CGPA"                         => "CGPA",
            "GET_INTERNAL_MARKS"               => "internal marks",
            "GET_FEES_BALANCE"                 => "fee balance",
            "GET_FEE_INFO"                     => "fee",
            "GET_BACKLOG_STATUS"               => "backlog",
            "GET_RESULT_STATUS"                => "result",
            "GET_COURSE_DETAILS"               => "course details",
            "GET_ACADEMIC_PERFORMANCE_SUMMARY" => "academic performance",
            "GET_EXAM_TIMETABLE"               => "exam timetable",
            "GET_TIMETABLE"                    => "timetable",
        ];

        if (!isset($intentTopicMap[$lastIntent])) return $query;
        $topic = $intentTopicMap[$lastIntent];

        $normalized = trim(strtolower((string) $query));
        // Strip leading connectors so we isolate the meaningful fragment.
        $stripped = preg_replace('/^(for|how about|what about|and|also|about|what)\s+/i', '', $normalized);
        $stripped = trim((string) $stripped, " ?.,");

        if ($stripped === "" || $stripped === "it" || $stripped === "that" || $stripped === "this") {
            // Pure pronoun: keep the topic alone and let the ERP handler decide.
            return $topic;
        }

        // Semester and time references — resolve to an explicit number when possible
        // so downstream handlers get "SGPA semester 3" not just "SGPA previous semester".
        if (preg_match('/\b(this|current|last|previous|prev|past|latest|most recent|semester\s*\d+|\d+\s*(st|nd|rd|th)\s*semester|this year|last year|current year|previous year|\d{4}[-\/]\d{2,4})\b/i', $stripped)) {
            // If we saved the last explicit semester used, convert "previous" to a real number.
            $lastSemester = (int) ($context["last_semester"] ?? 0);
            if ($lastSemester > 0 && preg_match('/\b(last|previous|prev|past)\b/i', $stripped)) {
                $prevSem = max(1, $lastSemester - 1);
                return $topic . " semester " . $prevSem;
            }
            if ($lastSemester > 0 && preg_match('/\b(this|current)\b/i', $stripped)) {
                return $topic . " semester " . $lastSemester;
            }
            return $topic . " " . $stripped;
        }

        // Everything else is treated as a subject / course name.
        return $topic . " for " . $stripped;
    }

    /**
     * When the last bot turn was a disambiguation question (e.g. "Did you mean
     * Database Management Systems or DBMS Laboratory?"), this method maps the
     * student's answer to the correct course title so the next query can be
     * fulfilled directly.
     *
     * Returns the enriched query string on success, null if no pending context.
     */
    private static function resolvePendingDisambiguation($query, $context) {
        $pendingIntent = (string) ($context["pending_intent"] ?? "");
        $pendingTitles = $context["pending_titles"] ?? [];

        if ($pendingIntent === "" || empty($pendingTitles)) return null;

        static $intentTopicMap = [
            "GET_SUBJECT_ATTENDANCE" => "attendance",
            "GET_INTERNAL_MARKS"     => "internal marks",
            "GET_RESULT_STATUS"      => "result",
            "GET_COURSE_DETAILS"     => "course details",
        ];
        $topic = $intentTopicMap[$pendingIntent] ?? "attendance";

        $normalized = strtolower(trim((string) $query));

        // Ordinal picks: "first", "1st", "second one", etc.
        $ordinalMap = [
            '/\b(first|1st|one|first one)\b/'   => 0,
            '/\b(second|2nd|two|second one)\b/'  => 1,
            '/\b(third|3rd|three|third one)\b/'  => 2,
        ];
        foreach ($ordinalMap as $pattern => $idx) {
            if (preg_match($pattern, $normalized) && isset($pendingTitles[$idx])) {
                return $topic . " for " . $pendingTitles[$idx];
            }
        }

        // Direct text match: find the pending title whose words appear most in the answer.
        $bestTitle = null;
        $bestSim = 0;
        foreach ($pendingTitles as $title) {
            similar_text($normalized, strtolower((string) $title), $pct);
            if ($pct > $bestSim) {
                $bestSim = $pct;
                $bestTitle = $title;
            }
        }
        if ($bestSim >= 40 && $bestTitle !== null) {
            return $topic . " for " . $bestTitle;
        }

        return null;
    }
}














