<?php
// Do not emit notices/warnings into the response body — the SPA expects pure JSON.
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

require_once __DIR__ . "/cors.php";

ini_set('session.cookie_samesite', 'Lax');
ini_set('session.cookie_secure', '0');

header("Content-Type: application/json");

session_start();

require_once __DIR__ . "/intents/studentIntent.php";
require_once __DIR__ . "/intents/controllers/StudentController.php";
require_once __DIR__ . "/intents/controllers/FeeController.php";
require_once __DIR__ . "/services/LlmService.php";
require_once __DIR__ . "/services/ConversationContextService.php";
require_once __DIR__ . "/services/SmartQueryResolver.php";
require_once __DIR__ . "/services/SuggestionService.php";
require_once __DIR__ . "/services/UserService.php";
require_once __DIR__ . "/config/db.php";

// Check authentication
if (!isset($_SESSION['user_id'])) {
    echo json_encode([
        "status" => "error",
        "reply" => "Unauthorized access. Please login."
    ]);
    exit();
}

$userContext = UserService::getCurrentUserContext($_SESSION['user_id']);

if (!$userContext) {
    echo json_encode([
        "status" => "error",
        "reply" => "User context not found. Please login again."
    ]);
    exit();
}

$roleKey = $userContext['role_key'];
$student_id = $userContext['student_id'] ?? null;

// We only need session data for authentication above. Release the session lock
// so other frontend requests do not block this API response.
session_write_close();

// Read input
$raw = file_get_contents("php://input");
$input = json_decode($raw, true);

if (!$input || !isset($input["message"])) {
    echo json_encode([
        "status" => "error",
        "reply" => "No message received"
    ]);
    exit();
}

$message = trim($input["message"]);
$originalMessage = $message;
// Speech-to-text often transcribes "course" as "called" before a subject shorthand.
$message = preg_replace(
    '/\bcalled\s+(?:the\s+)?(?=(dbms|d\s*b\s*m\s*s|os|o\s*s|cn|c\s*n|ai|a\s*i)\b)/iu',
    "course code for ",
    $message
);
// Normalize common speech-to-text mistakes for course-code questions before
// generic "score" terms get remapped to result-related intents.
$message = preg_replace('/\b(core|corse|course)\s+score\b/iu', "course code", $message);
$message = preg_replace('/\bcore\s+code\b/iu', "course code", $message);
$message = preg_replace('/\bcorse\s+code\b/iu', "course code", $message);
// Map common spoken terms to keywords the intent classifier already understands.
$message = preg_replace('/\b(scores|score|marks|mark|grades|grading)\b/ui', " result ", $message);
$message = trim(preg_replace('/\s+/u', " ", $message));
$language = strtolower(trim((string) ($input["language"] ?? "en")));
if (in_array($language, ["hi", "hindi", "hi-in"], true)) {
    $language = "hi";
} elseif (in_array($language, ["kn", "kannada", "kn-in"], true)) {
    $language = "kn";
} else {
    $language = "en";
}

function detectRequestedReplyLanguage($message, $fallbackLanguage) {
    if (preg_match('/\b(hindi|hindi me|hindi mein)\b|हिंदी|हिन्दी/u', $message)) {
        return "hi";
    }

    if (preg_match('/\b(kannada|kannadadalli|kannada dalli)\b|ಕನ್ನಡ/u', $message)) {
        return "kn";
    }

    if (preg_match('/\b(english|in english)\b|इंग्लिश|अंग्रेजी|ಇಂಗ್ಲಿಷ್/u', $message)) {
        return "en";
    }

    return $fallbackLanguage;
}

function isLanguageSwitchRequest($message) {
    return (bool) preg_match(
        '/\b(translate|say|tell|reply|answer|speak|explain)\b.*\b(hindi|kannada|english)\b|\b(hindi|kannada|english)\b.*\b(please|bolo|mein|me|dalli|heli)\b|हिंदी में|हिन्दी में|कन्नड़ में|अंग्रेजी में|ಕನ್ನಡದಲ್ಲಿ|ಇಂಗ್ಲಿಷ್‌ನಲ್ಲಿ/u',
        $message
    );
}

function isShortFollowUpFragment($message) {
    $trimmed = trim((string) $message);
    if ($trimmed === "") {
        return false;
    }

    $wordCount = preg_match_all('/[\p{L}\p{N}]+/u', $trimmed);
    return $wordCount > 0 && ($wordCount <= 6 || mb_strlen($trimmed, "UTF-8") <= 40);
}

function hasSubjectLikePhrase($message) {
    return (bool) preg_match(
        '/\b(dbms|d\s*b\s*m\s*s|os|o\s*s|cn|c\s*n|ai|a\s*i|database management systems|operating systems|computer networks|dbms laboratory|artificial intelligence)\b|ಡಿಬಿಎಂಎಸ್|ಡಿಬಿಎಂಎಸ್ ಲ್ಯಾಬ್|ಆಪರೇಟಿಂಗ್ ಸಿಸ್ಟಮ್ಸ್|ಕಂಪ್ಯೂಟರ್ ನೆಟ್ವರ್ಕ್ಸ್|ಆರ್ಟಿಫಿಷಿಯಲ್ ಇಂಟೆಲಿಜೆನ್ಸ್|डीबीएमएस|ऑपरेटिंग सिस्टम|कंप्यूटर नेटवर्क|आर्टिफिशियल इंटेलिजेंस/u',
        $message
    );
}

function hasSemesterLikePhrase($message) {
    return (bool) preg_match(
        '/\b\d+\s*(st|nd|rd|th)?\s*sem(?:ester)?\b|\bsemester\s*\d+\b|\bsem\s*\d+\b|सेमेस्टर|ಸೆಮಿಸ್ಟರ್/u',
        $message
    );
}

function isAmbiguousSubjectOpener($message, $lastContext) {
    $lastIntent = is_array($lastContext) ? trim((string) ($lastContext["intent"] ?? "")) : "";
    if ($lastIntent !== "") {
        return false;
    }

    $normalized = strtolower(trim((string) $message));
    if ($normalized === "" || !hasSubjectLikePhrase($message)) {
        return false;
    }

    if (!preg_match('/^\s*(what\s+about|about)\b/u', $normalized)) {
        return false;
    }

    if (
        strpos($normalized, "attendance") !== false ||
        strpos($normalized, "course code") !== false ||
        strpos($normalized, "subject code") !== false ||
        preg_match('/\bcode\s+(of|for)\b/u', $normalized)
    ) {
        return false;
    }

    return true;
}

function respondWithSubjectIntentChoice($message, $language) {
    $subject = trim((string) StudentController::inferCourseSubject($message));
    $subjectLabel = $subject !== "" ? ucwords($subject) : "that subject";
    $subjectPrompt = $subject !== "" ? $subject : trim((string) $message);

    if ($language === "hi") {
        $reply = "Kya aap {$subjectLabel} ke liye attendance poochhna chahte hain ya course code?";
    } elseif ($language === "kn") {
        $reply = "{$subjectLabel} bagge neevu attendance keluttiddira athava course code?";
    } else {
        $reply = "Do you want attendance or the course code for {$subjectLabel}?";
    }

    echo json_encode([
        "status" => "success",
        "intent" => "SUBJECT_FOLLOWUP_AMBIGUOUS",
        "route" => "clarification",
        "confidence" => "high",
        "intent_source" => "ambiguous_subject_opener",
        "reply" => $reply,
        "reply_source" => "clarification",
        "suggestion" => null,
        "quick_actions" => [
            [
                "label" => "Attendance",
                "prompt" => "attendance in " . $subjectPrompt
            ],
            [
                "label" => "Course code",
                "prompt" => "course code for " . $subjectPrompt
            ]
        ],
        "suggestion_priority" => "high"
    ]);
    exit();
}

function resolveConversationMemoryFollowUp($message, $language, $lastContext) {
    if (empty($lastContext) || !is_array($lastContext)) {
        return null;
    }

    $lastIntent = $lastContext["intent"] ?? "";
    $lastReply = trim((string) ($lastContext["reply"] ?? ""));
    $resolvedLanguage = detectRequestedReplyLanguage($message, $language);

    if ($lastReply !== "" && isLanguageSwitchRequest($message)) {
        return [
            "type" => "translate_last_reply",
            "language" => $resolvedLanguage,
            "source" => "conversation_memory_translate"
        ];
    }

    // Respect explicit course-code wording even when the previous turn was
    // attendance. Short follow-ups like "course code of dbms" should not be
    // rewritten into attendance queries by memory.
    if (StudentController::isLikelyCourseCodeQuery($message)) {
        return [
            "type" => "rewrite_message",
            "message" => "course code for " . trim((string) StudentController::inferCourseSubject($message) ?: $message),
            "source" => "conversation_memory_explicit_course_code"
        ];
    }

    if (!isShortFollowUpFragment($message)) {
        return null;
    }

    if (in_array($lastIntent, ["GET_ATTENDANCE", "GET_SUBJECT_ATTENDANCE"], true) && hasSubjectLikePhrase($message)) {
        return [
            "type" => "rewrite_message",
            "message" => "attendance in " . trim((string) $message),
            "source" => "conversation_memory_followup"
        ];
    }

    if ($lastIntent === "GET_COURSE_CODE" && hasSubjectLikePhrase($message)) {
        return [
            "type" => "rewrite_message",
            "message" => "course code for " . trim((string) $message),
            "source" => "conversation_memory_followup"
        ];
    }

    if ($lastIntent === "GET_SGPA" && hasSemesterLikePhrase($message)) {
        return [
            "type" => "rewrite_message",
            "message" => "sgpa " . trim((string) $message),
            "source" => "conversation_memory_followup"
        ];
    }

    return null;
}

function buildExamReadinessReply($student_id, $message, $language) {
    $registrationReply = FeeController::getFinalRegistrationStatus($student_id, "en");
    $hallTicketReply = StudentController::getHallTicketStatus($student_id, $message, "en");
    $backlogReply = StudentController::getBacklogStatus($student_id, $message, "en");

    $registrationClear = stripos($registrationReply, "complete") !== false
        || stripos($registrationReply, "completed successfully") !== false
        || stripos($registrationReply, "no pending fee balance") !== false;
    $hallTicketReady = stripos($hallTicketReply, "generated") !== false
        || stripos($hallTicketReply, "can download") !== false
        || stripos($hallTicketReply, "available") !== false;
    $hasBacklogRisk = stripos($backlogReply, "backlog") !== false
        && stripos($backlogReply, "do not have any active backlog") === false
        && stripos($backlogReply, "no active backlog") === false;

    $reply = "Here is your exam readiness summary. ";

    if ($registrationClear) {
        $reply .= "Your registration and fee clearance look okay. ";
    } else {
        $reply .= "Your registration or fee clearance still needs attention. ";
    }

    if ($hallTicketReady) {
        $reply .= "Your hall ticket status looks ready for exam access. ";
    } else {
        $reply .= "Your hall ticket is not clearly ready yet. ";
    }

    if ($hasBacklogRisk) {
        $reply .= "There may also be backlog-related academic risk. ";
    } else {
        $reply .= "I do not see a backlog warning in the current summary. ";
    }

    $reply .= "Registration summary: {$registrationReply} Hall ticket summary: {$hallTicketReply} Academic summary: {$backlogReply}";

    if ($language !== "en") {
        $reply = LlmService::adaptReplyLanguage($reply, $language, []);
    }

    return $reply;
}

function respondWithClarification($intent, $clarification, $intentSource) {
    echo json_encode([
        "status" => "success",
        "intent" => $intent,
        "route" => "clarification",
        "confidence" => "medium",
        "intent_source" => $intentSource,
        "reply" => $clarification["reply"] ?? "",
        "reply_source" => "clarification",
        "suggestion" => null,
        "quick_actions" => [],
        "suggestion_priority" => null,
        "clarification" => [
            "corrected_text" => $clarification["corrected_text"] ?? "",
            "display_text" => $clarification["display_text"] ?? ""
        ]
    ]);
    exit();
}

function normalizeClarificationText($message) {
    $normalized = mb_strtolower(trim((string) $message), "UTF-8");
    $normalized = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $normalized);
    $normalized = preg_replace('/\s+/u', ' ', (string) $normalized);
    return trim((string) $normalized);
}

function countClarificationWords($message) {
    preg_match_all('/[\p{L}\p{N}]+/u', (string) $message, $matches);
    return count($matches[0] ?? []);
}

function buildIntentClarificationPayload($intent, $message, $language = "en") {
    $normalizedMessage = normalizeClarificationText($message);
    if ($normalizedMessage === "") {
        return null;
    }

    $wordCount = countClarificationWords($normalizedMessage);
    if ($wordCount > 2 || mb_strlen($normalizedMessage, "UTF-8") > 22) {
        return null;
    }

    $intentPrompts = [
        "GET_ATTENDANCE" => [
            ["display" => "attendance", "corrected" => "attendance"],
            ["display" => "overall attendance", "corrected" => "overall attendance"]
        ],
        "GET_FEES_BALANCE" => [
            ["display" => "fee balance", "corrected" => "fee balance"]
        ],
        "GET_FINAL_REGISTRATION_STATUS" => [
            ["display" => "registration status", "corrected" => "registration status"]
        ],
        "GET_PROFILE_SUMMARY" => [
            ["display" => "profile", "corrected" => "profile"]
        ],
        "GET_USN" => [
            ["display" => "USN", "corrected" => "usn"]
        ],
        "GET_SGPA" => [
            ["display" => "SGPA", "corrected" => "sgpa"]
        ],
        "GET_CGPA" => [
            ["display" => "CGPA", "corrected" => "cgpa"]
        ],
        "GET_BACKLOG_STATUS" => [
            ["display" => "backlog status", "corrected" => "backlog status"]
        ],
        "GET_CERTIFICATE_STATUS" => [
            ["display" => "certificates", "corrected" => "certificates"]
        ],
        "GET_HALL_TICKET_STATUS" => [
            ["display" => "hall ticket status", "corrected" => "hall ticket status"]
        ],
        "GET_COURSE_DETAILS" => [
            ["display" => "course details", "corrected" => "course details"]
        ]
    ];

    $candidates = $intentPrompts[$intent] ?? [];
    if (empty($candidates)) {
        return null;
    }

    $bestCandidate = null;
    $bestDistance = PHP_INT_MAX;

    foreach ($candidates as $candidate) {
        $normalizedCandidate = normalizeClarificationText($candidate["corrected"] ?? "");
        $compactMessage = str_replace(" ", "", $normalizedMessage);
        $compactCandidate = str_replace(" ", "", $normalizedCandidate);

        if ($compactCandidate === "" || $compactMessage === $compactCandidate) {
            continue;
        }

        $isPrefixLike = strpos($compactCandidate, $compactMessage) === 0 || strpos($compactMessage, $compactCandidate) === 0;
        $distance = levenshtein($compactMessage, $compactCandidate);
        $maxDistance = max(1, (int) floor(strlen($compactCandidate) * 0.3));

        if (!$isPrefixLike && $distance > $maxDistance) {
            continue;
        }

        if ($distance < $bestDistance) {
            $bestDistance = $distance;
            $bestCandidate = $candidate;
        }
    }

    if (!is_array($bestCandidate)) {
        return null;
    }

    $displayText = $bestCandidate["display"] ?? ($bestCandidate["corrected"] ?? "");
    $correctedText = $bestCandidate["corrected"] ?? "";

    if ($displayText === "" || $correctedText === "") {
        return null;
    }

    if ($language === "hi") {
        $reply = "क्या आपका मतलब {$displayText} था? कृपया हाँ या नहीं कहिए।";
    } elseif ($language === "kn") {
        $reply = "{$displayText} andre nimma artha? Dayavittu haudu athava illa heli.";
    } else {
        $reply = "Did you mean {$displayText}? Please say yes or no.";
    }

    return [
        "reply" => $reply,
        "corrected_text" => $correctedText,
        "display_text" => $displayText
    ];
}

$lastContext = ConversationContextService::getLastResolvedContext();

if (isAmbiguousSubjectOpener($message, $lastContext)) {
    respondWithSubjectIntentChoice($message, $language);
}

$smartResolution = SmartQueryResolver::resolve($message, $language, $lastContext);

if (is_array($smartResolution) && ($smartResolution["type"] ?? "") === "translate_last_reply") {
    $language = $smartResolution["requested_language"] ?? $language;
    $reply = LlmService::translateReply($lastContext["reply"] ?? "", $language, $userContext);
    $replyMeta = LlmService::getLastReplyMeta();
    $meta = [
        "intent" => $smartResolution["intent"] ?? ($lastContext["intent"] ?? "MEMORY_TRANSLATE"),
        "route" => "memory",
        "language" => $language,
        "reply" => $reply,
        "reply_source" => $replyMeta["source"] ?? "memory",
        "intent_source" => $smartResolution["source"] ?? "smart_query_language_followup"
    ];
    ConversationContextService::saveTurn($originalMessage, $reply, $meta);

    echo json_encode([
        "status" => "success",
        "intent" => $meta["intent"],
        "route" => "memory",
        "confidence" => "high",
        "intent_source" => $meta["intent_source"],
        "reply" => $reply,
        "reply_source" => $meta["reply_source"],
        "suggestion" => null,
        "quick_actions" => [],
        "suggestion_priority" => null
    ]);
    exit();
}

$forcedIntent = null;
$forcedRoute = null;
$forcedConfidence = null;
$forcedSource = null;
$resolvedEntities = [];

if (is_array($smartResolution) && ($smartResolution["type"] ?? "") === "resolved_intent") {
    $forcedIntent = $smartResolution["intent"] ?? null;
    $forcedRoute = $smartResolution["route"] ?? "database";
    $forcedConfidence = $smartResolution["confidence"] ?? "medium";
    $forcedSource = $smartResolution["source"] ?? "smart_query_resolver";
    $resolvedEntities = $smartResolution["entities"] ?? [];
    $language = $smartResolution["requested_language"] ?? $language;
    $message = trim((string) ($smartResolution["rewritten_message"] ?? $message));
}

$memoryResolution = resolveConversationMemoryFollowUp($message, $language, $lastContext);
$memoryResolutionSource = is_array($memoryResolution) ? ($memoryResolution["source"] ?? null) : null;

if (is_array($memoryResolution) && ($memoryResolution["type"] ?? "") === "translate_last_reply") {
    $language = $memoryResolution["language"] ?? $language;
    $reply = LlmService::translateReply($lastContext["reply"] ?? "", $language, $userContext);
    $replyMeta = LlmService::getLastReplyMeta();
    $meta = [
        "intent" => $lastContext["intent"] ?? "MEMORY_TRANSLATE",
        "route" => "memory",
        "language" => $language,
        "reply" => $reply,
        "reply_source" => $replyMeta["source"] ?? "memory",
        "intent_source" => $memoryResolution["source"] ?? "conversation_memory_translate"
    ];
    ConversationContextService::saveTurn($originalMessage, $reply, $meta);

    echo json_encode([
        "status" => "success",
        "intent" => $meta["intent"],
        "route" => "memory",
        "confidence" => "high",
        "intent_source" => $meta["intent_source"],
        "reply" => $reply,
        "reply_source" => $meta["reply_source"],
        "suggestion" => null,
        "quick_actions" => [],
        "suggestion_priority" => null
    ]);
    exit();
}

if (is_array($memoryResolution) && ($memoryResolution["type"] ?? "") === "rewrite_message") {
    $message = $memoryResolution["message"];
}

$normalizedMessage = strtolower($message);
$hasShortSgpaFragment = (bool) preg_match('/(^|\b)(s\s*g|sg)\b/u', $normalizedMessage)
    && strpos($normalizedMessage, 'sgpa') === false
    && strpos($normalizedMessage, 'cgpa') === false
    && strpos($normalizedMessage, 'usn') === false;
$hasShortCgpaFragment = (bool) preg_match('/(^|\b)(c\s*g|cg)\b/u', $normalizedMessage)
    && strpos($normalizedMessage, 'cgpa') === false
    && strpos($normalizedMessage, 'sgpa') === false;
$isExplicitUsnQuery = (bool) preg_match(
    '/^\s*(usn|registration number|university number)\s*[\?\.!]*\s*$|\b(what(?:\s+is|\'s)?|tell|show|give|share|say|confirm)\b.*\b(usn|registration number|university number)\b|\bmy\s+(usn|registration number|university number)\b|यूएसएन|रजिस्ट्रेशन नंबर/u',
    $normalizedMessage
);
$hasAttendanceWord = (bool) preg_match(
    '/\battendance\b|ಅಟೆಂಡೆನ್ಸ್|ಹಾಜರಿ|ಹಾಜರಾತಿ|attendence|atendance/u',
    $normalizedMessage
);
$hasOverallAttendanceWord = (bool) preg_match(
    '/\boverall\b|\btotal\b|ಒಟ್ಟು|ಟೋಟಲ್/u',
    $normalizedMessage
);
$hasSpecificSubjectWord = (bool) preg_match(
    '/\b(dbms|d\s*b\s*m\s*s|os|o\s*s|cn|c\s*n|ai|a\s*i|database management systems|operating systems|computer networks|dbms laboratory|artificial intelligence|cs501|cs502|cs503|cs5l1|cs5e1)\b|ಡಿಬಿಎಂಎಸ್|ಡಿಬಿಎಂಎಸ್ ಲ್ಯಾಬ್|ಆಪರೇಟಿಂಗ್ ಸಿಸ್ಟಮ್ಸ್|ಕಂಪ್ಯೂಟರ್ ನೆಟ್ವರ್ಕ್ಸ್|ಆರ್ಟಿಫಿಶಿಯಲ್ ಇಂಟೆಲಿಜೆನ್ಸ್/u',
    $normalizedMessage
);
$hasCourseCodeWord = StudentController::isLikelyCourseCodeQuery($message); /*
    '/\b(course|subject)\s+code\b|\bcode\s+(of|for)\b|\bcode\b|à²•à³‹à²¡à³|course code|subject code/u',
    $normalizedMessage
);
$hasKannadaCourseCodeHint = (bool) preg_match(
    '/ಕೋಡ್|ಕೋಡಿ|ಕೋರ್ಡ್|ಕೋರ್ಸ್ ಕೋಡ್|ಸಬ್ಜೆಕ್ಟ್ ಕೋಡ್|ವಿಷಯದ ಕೋಡ್/u',
    $message
);
*/
if ($student_id && $hasAttendanceWord && $hasSpecificSubjectWord && !$hasOverallAttendanceWord) {
    $clarification = StudentController::getSubjectAttendanceClarification($student_id, $message, $language);
    if (is_array($clarification)) {
        respondWithClarification("GET_SUBJECT_ATTENDANCE", $clarification, ($memoryResolutionSource ?? "api_fast_path"));
    }

    $reply = StudentController::getSubjectAttendance($student_id, $message, $language);
    $replySource = "db";

    if ($language !== "en" && $language !== "kn" && $language !== "hi") {
        $reply = LlmService::adaptReplyLanguage($reply, $language, $userContext);
        $replySource = LlmService::getLastReplyMeta()["source"] ?? "translated_db";
    } else {
        LlmService::setLastReplyMeta($language === "kn" ? "db_kannada" : "db");
        $replySource = $language === "kn" ? "db_kannada" : "db";
    }

    ConversationContextService::saveTurn($originalMessage, $reply, [
        "intent" => "GET_SUBJECT_ATTENDANCE",
        "route" => "database",
        "language" => $language,
        "reply" => $reply,
        "reply_source" => $replySource,
        "intent_source" => ($memoryResolutionSource ?? "api_fast_path"),
        "effective_message" => $message
    ]);
    $suggestion = SuggestionService::build("GET_SUBJECT_ATTENDANCE", $reply, $language, [
        "subject" => StudentController::inferAttendanceSubject($message)
    ]);

    echo json_encode([
        "status" => "success",
        "intent" => "GET_SUBJECT_ATTENDANCE",
        "route" => "database",
        "confidence" => "high",
        "intent_source" => ($memoryResolutionSource ?? "api_fast_path"),
        "reply" => $reply,
        "reply_source" => $replySource,
        "suggestion" => $suggestion["text"] ?? null,
        "quick_actions" => $suggestion["quick_actions"] ?? [],
        "suggestion_priority" => $suggestion["priority"] ?? null
    ]);
    exit();
}

if ($hasCourseCodeWord) {
    $clarification = StudentController::getCourseCodeClarification($message, $language);
    if (is_array($clarification)) {
        respondWithClarification("GET_COURSE_CODE", $clarification, ($memoryResolutionSource ?? "api_fast_path"));
    }

    $reply = StudentController::getCourseCode($message, $language);
    $replySource = "db";

    if ($language === "hi" && preg_match('/^The course code for (.+) is ([A-Z0-9-]+)\.$/', $reply, $matches)) {
        $reply = $matches[1] . " का कोर्स कोड " . $matches[2] . " है।";
    }

    if ($language !== "en" && $language !== "kn" && $language !== "hi") {
        $reply = LlmService::adaptReplyLanguage($reply, $language, $userContext);
        $replySource = LlmService::getLastReplyMeta()["source"] ?? "translated_db";
    } else {
        LlmService::setLastReplyMeta($language === "kn" ? "db_kannada" : "db");
        $replySource = $language === "kn" ? "db_kannada" : "db";
    }

    ConversationContextService::saveTurn($originalMessage, $reply, [
        "intent" => "GET_COURSE_CODE",
        "route" => "database",
        "language" => $language,
        "reply" => $reply,
        "reply_source" => $replySource,
        "intent_source" => ($memoryResolutionSource ?? "api_fast_path"),
        "effective_message" => $message
    ]);
    $suggestion = SuggestionService::build("GET_COURSE_CODE", $reply, $language, [
        "subject" => StudentController::inferCourseSubject($message)
    ]);

    echo json_encode([
        "status" => "success",
        "intent" => "GET_COURSE_CODE",
        "route" => "database",
        "confidence" => "high",
        "intent_source" => ($memoryResolutionSource ?? "api_fast_path"),
        "reply" => $reply,
        "reply_source" => $replySource,
        "suggestion" => $suggestion["text"] ?? null,
        "quick_actions" => $suggestion["quick_actions"] ?? [],
        "suggestion_priority" => $suggestion["priority"] ?? null
    ]);
    exit();
}

if ($student_id && $hasShortSgpaFragment) {
    $clarification = buildIntentClarificationPayload("GET_SGPA", "sg", $language);
    if (is_array($clarification)) {
        respondWithClarification("GET_SGPA", $clarification, ($memoryResolutionSource ?? "api_short_fragment"));
    }
}

if ($student_id && $hasShortCgpaFragment) {
    $clarification = buildIntentClarificationPayload("GET_CGPA", "cg", $language);
    if (is_array($clarification)) {
        respondWithClarification("GET_CGPA", $clarification, ($memoryResolutionSource ?? "api_short_fragment"));
    }
}

if (
    $student_id &&
    $isExplicitUsnQuery
) {
    $reply = StudentController::getUSN($student_id, $language);
    $replySource = "db";

    if ($language !== "en" && $language !== "kn" && $language !== "hi") {
        $reply = LlmService::adaptReplyLanguage($reply, $language, $userContext);
        $replySource = LlmService::getLastReplyMeta()["source"] ?? "translated_db";
    } else {
        LlmService::setLastReplyMeta($language === "kn" ? "db_kannada" : "db");
        $replySource = $language === "kn" ? "db_kannada" : "db";
    }

    ConversationContextService::saveTurn($originalMessage, $reply, [
        "intent" => "GET_USN",
        "route" => "database",
        "language" => $language,
        "reply" => $reply,
        "reply_source" => $replySource,
        "intent_source" => ($memoryResolutionSource ?? "api_fast_path_usn"),
        "effective_message" => $message
    ]);
    $suggestion = SuggestionService::build("GET_USN", $reply, $language);

    echo json_encode([
        "status" => "success",
        "intent" => "GET_USN",
        "route" => "database",
        "confidence" => "high",
        "intent_source" => ($memoryResolutionSource ?? "api_fast_path_usn"),
        "reply" => $reply,
        "reply_source" => $replySource,
        "suggestion" => $suggestion["text"] ?? null,
        "quick_actions" => $suggestion["quick_actions"] ?? [],
        "suggestion_priority" => $suggestion["priority"] ?? null
    ]);
    exit();
}

// Detect intent
$classification = ($forcedIntent !== null)
    ? [
        "intent" => $forcedIntent,
        "route" => $forcedRoute,
        "confidence" => $forcedConfidence,
        "source" => $forcedSource
    ]
    : IntentService::classifyIntent($message, $userContext);
$intent = $classification["intent"] ?? "UNKNOWN";
$route = $classification["route"] ?? "llm";
$confidence = $classification["confidence"] ?? "low";
$intentSource = $classification["source"] ?? "unknown";

$reply = "";
$replySource = "unknown";
$handledByDatabase = false;
$dbReplyIsLocalized = false;

if ($route === "database") {
    switch ($intent) {
        case "GET_USN":
            if (!$student_id) {
                $reply = "USN lookup is available for student accounts after student login.";
                $replySource = "db_guard";
                $handledByDatabase = true;
                break;
            }
            $clarification = buildIntentClarificationPayload("GET_USN", $message, $language);
            if (is_array($clarification)) {
                respondWithClarification("GET_USN", $clarification, $intentSource);
            }
            $reply = StudentController::getUSN($student_id, $language);
            $replySource = "db";
            $handledByDatabase = true;
            $dbReplyIsLocalized = ($language === "kn");
            break;

        case "GET_PROFILE_SUMMARY":
            if (!$student_id) {
                $reply = "Profile lookup is available for student accounts after student login.";
                $replySource = "db_guard";
                $handledByDatabase = true;
                break;
            }
            $clarification = buildIntentClarificationPayload("GET_PROFILE_SUMMARY", $message, $language);
            if (is_array($clarification)) {
                respondWithClarification("GET_PROFILE_SUMMARY", $clarification, $intentSource);
            }
            $reply = StudentController::getProfileSummary($student_id, $message, $language);
            $replySource = "db";
            $handledByDatabase = true;
            $dbReplyIsLocalized = ($language === "kn");
            break;

        case "GET_SGPA":
            if (!$student_id) {
                $reply = "SGPA lookup is available for student accounts after student login.";
                $replySource = "db_guard";
                $handledByDatabase = true;
                break;
            }
            $clarification = buildIntentClarificationPayload("GET_SGPA", $message, $language);
            if (is_array($clarification)) {
                respondWithClarification("GET_SGPA", $clarification, $intentSource);
            }
            $reply = StudentController::getSGPA($student_id, $message, $language);
            $replySource = "db";
            $handledByDatabase = true;
            $dbReplyIsLocalized = ($language === "kn");
            break;

        case "GET_CGPA":
            if (!$student_id) {
                $reply = "CGPA lookup is available for student accounts after student login.";
                $replySource = "db_guard";
                $handledByDatabase = true;
                break;
            }
            $clarification = buildIntentClarificationPayload("GET_CGPA", $message, $language);
            if (is_array($clarification)) {
                respondWithClarification("GET_CGPA", $clarification, $intentSource);
            }
            $reply = StudentController::getCGPA($student_id, $language);
            $replySource = "db";
            $handledByDatabase = true;
            $dbReplyIsLocalized = ($language === "kn");
            break;

        case "GET_BACKLOG_STATUS":
            if (!$student_id) {
                $reply = "Backlog status is available for student accounts after student login.";
                $replySource = "db_guard";
                $handledByDatabase = true;
                break;
            }
            $clarification = buildIntentClarificationPayload("GET_BACKLOG_STATUS", $message, $language);
            if (is_array($clarification)) {
                respondWithClarification("GET_BACKLOG_STATUS", $clarification, $intentSource);
            }
            $reply = StudentController::getBacklogStatus($student_id, $message, $language);
            $replySource = "db";
            $handledByDatabase = true;
            $dbReplyIsLocalized = ($language === "kn");
            break;

        case "GET_FEES_BALANCE":
            if (!$student_id) {
                $reply = "Fee balance lookup is available for student accounts after student login.";
                $replySource = "db_guard";
                $handledByDatabase = true;
                break;
            }
            $clarification = buildIntentClarificationPayload("GET_FEES_BALANCE", $message, $language);
            if (is_array($clarification)) {
                respondWithClarification("GET_FEES_BALANCE", $clarification, $intentSource);
            }
            $reply = FeeController::getFeeBalance($student_id, $language);
            $replySource = "db";
            $handledByDatabase = true;
            $dbReplyIsLocalized = ($language === "kn");
            break;

        case "GET_FINAL_REGISTRATION_STATUS":
            if (!$student_id) {
                $reply = "Final registration status is available for student accounts after student login.";
                $replySource = "db_guard";
                $handledByDatabase = true;
                break;
            }
            $clarification = buildIntentClarificationPayload("GET_FINAL_REGISTRATION_STATUS", $message, $language);
            if (is_array($clarification)) {
                respondWithClarification("GET_FINAL_REGISTRATION_STATUS", $clarification, $intentSource);
            }
            $reply = FeeController::getFinalRegistrationStatus($student_id, $language);
            $replySource = "db";
            $handledByDatabase = true;
            $dbReplyIsLocalized = ($language === "kn");
            break;

        case "GET_HALL_TICKET_STATUS":
            if (!$student_id) {
                $reply = "Hall ticket status is available for student accounts after student login.";
                $replySource = "db_guard";
                $handledByDatabase = true;
                break;
            }
            $clarification = buildIntentClarificationPayload("GET_HALL_TICKET_STATUS", $message, $language);
            if (is_array($clarification)) {
                respondWithClarification("GET_HALL_TICKET_STATUS", $clarification, $intentSource);
            }
            $reply = StudentController::getHallTicketStatus($student_id, $message, $language);
            $replySource = "db";
            $handledByDatabase = true;
            $dbReplyIsLocalized = ($language === "kn");
            break;

        case "GET_CERTIFICATE_STATUS":
            if (!$student_id) {
                $reply = "Certificate status is available for student accounts after student login.";
                $replySource = "db_guard";
                $handledByDatabase = true;
                break;
            }
            $clarification = buildIntentClarificationPayload("GET_CERTIFICATE_STATUS", $message, $language);
            if (is_array($clarification)) {
                respondWithClarification("GET_CERTIFICATE_STATUS", $clarification, $intentSource);
            }
            $reply = StudentController::getCertificateStatus($student_id, $message, $language);
            $replySource = "db";
            $handledByDatabase = true;
            $dbReplyIsLocalized = in_array($language, ["kn", "hi"], true);
            break;

        case "GET_COURSE_DETAILS":
            if (!$student_id) {
                $reply = "Course details are available for student accounts after student login.";
                $replySource = "db_guard";
                $handledByDatabase = true;
                break;
            }
            $clarification = buildIntentClarificationPayload("GET_COURSE_DETAILS", $message, $language);
            if (is_array($clarification)) {
                respondWithClarification("GET_COURSE_DETAILS", $clarification, $intentSource);
            }
            $reply = StudentController::getCourseDetails($student_id, $message, $language);
            $replySource = "db";
            $handledByDatabase = true;
            $dbReplyIsLocalized = ($language === "kn");
            break;

        case "GET_ATTENDANCE":
            if (!$student_id) {
                $reply = "Attendance lookup is available for student accounts after student login.";
                $replySource = "db_guard";
                $handledByDatabase = true;
                break;
            }
            $clarification = buildIntentClarificationPayload("GET_ATTENDANCE", $message, $language);
            if (is_array($clarification)) {
                respondWithClarification("GET_ATTENDANCE", $clarification, $intentSource);
            }
            $normalizedAttendanceMessage = strtolower(trim((string) $message));
            $isExplicitOverallAttendance = (bool) preg_match(
                '/\b(overall|total|my attendance|attendance percentage|attendance status)\b|ಒಟ್ಟು|ಒವರ್ ಆಲ್|overall|ಟೋಟಲ್/u',
                $normalizedAttendanceMessage
            );
            $hasSubjectAttendancePhrase = (bool) preg_match(
                '/\battendance\s+(?:in|of|for)\b/u',
                $normalizedAttendanceMessage
            );

            $reply = ($isExplicitOverallAttendance && !$hasSubjectAttendancePhrase)
                ? StudentController::getAttendance($student_id, $language)
                : StudentController::getSubjectAttendance($student_id, $message, $language);
            $replySource = "db";
            $handledByDatabase = true;
            $dbReplyIsLocalized = in_array($language, ["kn", "hi"], true);
            break;

        case "GET_SUBJECT_ATTENDANCE":
            if (!$student_id) {
                $reply = "Subject attendance lookup is available for student accounts after student login.";
                $replySource = "db_guard";
                $handledByDatabase = true;
                break;
            }
            $clarification = StudentController::getSubjectAttendanceClarification($student_id, $message, $language);
            if (is_array($clarification)) {
                respondWithClarification("GET_SUBJECT_ATTENDANCE", $clarification, $intentSource);
            }
            $reply = StudentController::getSubjectAttendance($student_id, $message, $language);
            $replySource = "db";
            $handledByDatabase = true;
            $dbReplyIsLocalized = in_array($language, ["kn", "hi"], true);
            break;

        case "GET_COURSE_CODE":
            $clarification = StudentController::getCourseCodeClarification($message, $language);
            if (is_array($clarification)) {
                respondWithClarification("GET_COURSE_CODE", $clarification, $intentSource);
            }
            $reply = StudentController::getCourseCode($message, $language);
            $replySource = "db";
            $handledByDatabase = true;
            $dbReplyIsLocalized = in_array($language, ["kn", "hi"], true);
            break;

        case "GET_EXAM_READINESS":
            if (!$student_id) {
                $reply = "Exam readiness is available for student accounts after student login.";
                $replySource = "db_guard";
                $handledByDatabase = true;
                break;
            }
            $reply = buildExamReadinessReply($student_id, $message, $language);
            $replySource = "db";
            $handledByDatabase = true;
            $dbReplyIsLocalized = in_array($language, ["kn", "hi"], true);
            break;
    }
}

if (!$handledByDatabase) {
    $intent = $handledByDatabase ? $intent : "LLM_ASSIST";
    $route = "llm";
    $reply = LlmService::getReply($message, $userContext, $language);
} elseif (!$dbReplyIsLocalized) {
    $reply = LlmService::adaptReplyLanguage($reply, $language, $userContext);
}

if ($replySource !== "unknown") {
    $metaSource = $replySource;

    if ($handledByDatabase && $dbReplyIsLocalized) {
        $metaSource = $language === "kn" ? "db_kannada" : "db";
    }

    LlmService::setLastReplyMeta($metaSource);
}

$replyMeta = LlmService::getLastReplyMeta();
$finalIntentSource = $memoryResolutionSource ?? $intentSource;
$conversationMeta = [
    "intent" => $intent,
    "route" => $route,
    "language" => $language,
    "reply" => $reply,
    "reply_source" => $replyMeta["source"] ?? "unknown",
    "intent_source" => $finalIntentSource,
    "effective_message" => $message,
    "subject" => $resolvedEntities["subject"] ?? ($lastContext["subject"] ?? ""),
    "semester" => $resolvedEntities["semester"] ?? ($lastContext["semester"] ?? null),
    "exam_type" => $resolvedEntities["exam_type"] ?? ($lastContext["exam_type"] ?? null)
];
$suggestion = SuggestionService::build($intent, $reply, $language, $conversationMeta);

if ($suggestion) {
    $conversationMeta["suggestion"] = $suggestion;
}

if ($handledByDatabase) {
    ConversationContextService::saveTurn($originalMessage, $reply, $conversationMeta);
} else {
    ConversationContextService::setLastResolvedContext($conversationMeta);
}

echo json_encode([
    "status" => "success",
    "intent" => $intent,
    "route" => $route,
    "confidence" => $confidence,
    "intent_source" => $finalIntentSource,
    "reply" => $reply,
    "reply_source" => $replyMeta["source"] ?? "unknown",
    "suggestion" => $suggestion["text"] ?? null,
    "quick_actions" => $suggestion["quick_actions"] ?? [],
    "suggestion_priority" => $suggestion["priority"] ?? null
]);
