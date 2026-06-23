<?php
// Do not emit notices/warnings into the response body — the SPA expects pure JSON.
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

require_once __DIR__ . "/cors.php";

ini_set('session.cookie_samesite', 'Lax');
ini_set('session.cookie_secure', '0');

header("Access-Control-Allow-Origin: http://localhost:3000");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json");

// Check internal auth from VapiToolService BEFORE session_start (headers are always available)
$internalUserId = (int) ($_SERVER['HTTP_X_INTERNAL_USERID'] ?? 0);
$internalSecret = (string) ($_SERVER['HTTP_X_INTERNAL_SECRET'] ?? '');
$isInternalCall = ($internalUserId > 0 && $internalSecret === md5("gmu_internal_" . date("Ymd") . $internalUserId));

session_start();

if ($isInternalCall && !isset($_SESSION['user_id'])) {
    $_SESSION['user_id'] = $internalUserId;
}

require_once __DIR__ . "/intents/studentIntent.php";
require_once __DIR__ . "/intents/controllers/StudentController.php";
require_once __DIR__ . "/intents/controllers/FeeController.php";
require_once __DIR__ . "/services/LlmService.php";
require_once __DIR__ . "/services/ConversationContextService.php";
require_once __DIR__ . "/services/SmartQueryResolver.php";
require_once __DIR__ . "/services/MultilingualUnderstandingService.php";
require_once __DIR__ . "/services/VoiceUnderstandingService.php";
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

// Read input (use pre-parsed body for internal calls, otherwise read stream)
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
$rawTranscriptMeta = trim((string) ($input["raw_transcript"] ?? ""));
$correctedTranscriptMeta = trim((string) ($input["corrected_transcript"] ?? ""));
$transcriptConfidenceMeta = (float) ($input["transcript_confidence"] ?? 0.0);
$meanWordConfidenceMeta = (float) ($input["mean_word_confidence"] ?? 0.0);
$lowConfidenceWordsMeta = is_array($input["low_confidence_words"] ?? null) ? $input["low_confidence_words"] : [];
if (in_array($language, ["hi", "hindi", "hi-in"], true)) {
    $language = "hi";
} elseif (in_array($language, ["kn", "kannada", "kn-in"], true)) {
    $language = "kn";
} else {
    $language = "en";
}

function debugVoicebotLog($message, $context = []) {
    $enabled = getenv("VOICEBOT_DEBUG");
    if ($enabled === false || $enabled === "" || strtolower((string) $enabled) === "false") {
        return;
    }

    $line = "[voicebot-api] " . $message;
    if (!empty($context)) {
        $encoded = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded !== false) {
            $line .= " " . $encoded;
        }
    }

    error_log($line);
}

function detectRequestedReplyLanguage($message, $fallbackLanguage) {
    if (preg_match('/\b(hindi|hindi me|hindi mein)\b|à¤¹à¤¿à¤‚à¤¦à¥€|à¤¹à¤¿à¤¨à¥à¤¦à¥€/u', $message)) {
        return "hi";
    }

    if (preg_match('/\b(kannada|kannadadalli|kannada dalli)\b|à²•à²¨à³à²¨à²¡/u', $message)) {
        return "kn";
    }

    if (preg_match('/\b(english|in english)\b|à¤‡à¤‚à¤—à¥à¤²à¤¿à¤¶|à¤…à¤‚à¤—à¥à¤°à¥‡à¤œà¥€|à²‡à²‚à²—à³à²²à²¿à²·à³/u', $message)) {
        return "en";
    }

    return $fallbackLanguage;
}

function isLanguageSwitchRequest($message) {
    return (bool) preg_match(
        '/\b(translate|say|tell|reply|answer|speak|explain)\b.*\b(hindi|kannada|english)\b|\b(hindi|kannada|english)\b.*\b(please|bolo|mein|me|dalli|heli)\b|à¤¹à¤¿à¤‚à¤¦à¥€ à¤®à¥‡à¤‚|à¤¹à¤¿à¤¨à¥à¤¦à¥€ à¤®à¥‡à¤‚|à¤•à¤¨à¥à¤¨à¤¡à¤¼ à¤®à¥‡à¤‚|à¤…à¤‚à¤—à¥à¤°à¥‡à¤œà¥€ à¤®à¥‡à¤‚|à²•à²¨à³à²¨à²¡à²¦à²²à³à²²à²¿|à²‡à²‚à²—à³à²²à²¿à²·à³â€Œà²¨à²²à³à²²à²¿/u',
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
        '/\b(dbms|d\s*b\s*m\s*s|os|o\s*s|cn|c\s*n|ai|a\s*i|database management systems|operating systems|computer networks|dbms laboratory|artificial intelligence)\b|à²¡à²¿à²¬à²¿à²Žà²‚à²Žà²¸à³|à²¡à²¿à²¬à²¿à²Žà²‚à²Žà²¸à³ à²²à³à²¯à²¾à²¬à³|à²†à²ªà²°à³‡à²Ÿà²¿à²‚à²—à³ à²¸à²¿à²¸à³à²Ÿà²®à³à²¸à³|à²•à²‚à²ªà³à²¯à³‚à²Ÿà²°à³ à²¨à³†à²Ÿà³à²µà²°à³à²•à³à²¸à³|à²†à²°à³à²Ÿà²¿à²«à²¿à²·à²¿à²¯à²²à³ à²‡à²‚à²Ÿà³†à²²à²¿à²œà³†à²¨à³à²¸à³|à¤¡à¥€à¤¬à¥€à¤à¤®à¤à¤¸|à¤‘à¤ªà¤°à¥‡à¤Ÿà¤¿à¤‚à¤— à¤¸à¤¿à¤¸à¥à¤Ÿà¤®|à¤•à¤‚à¤ªà¥à¤¯à¥‚à¤Ÿà¤° à¤¨à¥‡à¤Ÿà¤µà¤°à¥à¤•|à¤†à¤°à¥à¤Ÿà¤¿à¤«à¤¿à¤¶à¤¿à¤¯à¤² à¤‡à¤‚à¤Ÿà¥‡à¤²à¤¿à¤œà¥‡à¤‚à¤¸/u',
        $message
    );
}

function hasSemesterLikePhrase($message) {
    return (bool) preg_match(
        '/\b\d+\s*(st|nd|rd|th)?\s*sem(?:ester)?\b|\bsemester\s*\d+\b|\bsem\s*\d+\b|à¤¸à¥‡à¤®à¥‡à¤¸à¥à¤Ÿà¤°|à²¸à³†à²®à²¿à²¸à³à²Ÿà²°à³/u',
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
    $isCourseCodeRequest = class_exists("StudentController")
        && (
            StudentController::isLikelyCourseCodeQuery($message)
            || (bool) preg_match('/\b(course|subject)\s+code\b|\bcode\s+(of|for)\b|\bcode\b|à²•à³‹à²¡à³|à²•à³‹à²¡à²¿|à²µà²¿à²·à²¯à²¦ à²•à³‹à²¡à³/u', $message)
        );

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

    if (preg_match('/\b(attendance|attendence|atendance|hajari)\b/u', strtolower((string) $message)) && hasSubjectLikePhrase($message)) {
        return [
            "type" => "rewrite_message",
            "message" => "attendance in " . trim((string) $message),
            "source" => "conversation_memory_attendance_explicit"
        ];
    }

    if ($isCourseCodeRequest && hasSubjectLikePhrase($message)) {
        return [
            "type" => "rewrite_message",
            "message" => "course code for " . trim((string) $message),
            "source" => "conversation_memory_course_code"
        ];
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

$lastContext = ConversationContextService::getLastResolvedContext();
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
        "suggestion_priority" => null,
        "effective_message" => $message,
        "debug" => [
            "understanding" => $understanding ?? [],
            "raw_transcript" => $rawTranscriptMeta,
            "corrected_transcript" => $correctedTranscriptMeta,
            "transcript_confidence" => $transcriptConfidenceMeta,
            "mean_word_confidence" => $meanWordConfidenceMeta
        ]
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
        "suggestion_priority" => null,
        "effective_message" => $message,
        "debug" => [
            "understanding" => $understanding ?? [],
            "raw_transcript" => $rawTranscriptMeta,
            "corrected_transcript" => $correctedTranscriptMeta,
            "transcript_confidence" => $transcriptConfidenceMeta,
            "mean_word_confidence" => $meanWordConfidenceMeta
        ]
    ]);
    exit();
}

if (is_array($memoryResolution) && ($memoryResolution["type"] ?? "") === "rewrite_message") {
    $message = $memoryResolution["message"];
}

if (
    $language === "kn" &&
    $transcriptConfidenceMeta > 0 &&
    $transcriptConfidenceMeta < 0.75
) {
    debugVoicebotLog("kannada_low_confidence_block", [
        "raw_transcript" => $rawTranscriptMeta,
        "corrected_transcript" => $correctedTranscriptMeta,
        "transcript_confidence" => $transcriptConfidenceMeta,
        "mean_word_confidence" => $meanWordConfidenceMeta,
        "low_confidence_count" => count($lowConfidenceWordsMeta)
    ]);

    echo json_encode([
        "status" => "success",
        "intent" => "LOW_CONFIDENCE_KANNADA",
        "route" => "safety_block",
        "confidence" => "low",
        "intent_source" => "kannada_low_confidence_guard",
        "reply" => "à²¨à²¾à²¨à³ à²–à²šà²¿à²¤à²µà²¾à²—à²¿ à²•à³‡à²³à²²à²¿à²²à³à²². à²¦à²¯à²µà²¿à²Ÿà³à²Ÿà³ à²¨à²¿à²§à²¾à²¨à²µà²¾à²—à²¿ à²®à²¤à³à²¤à³† à²¹à³‡à²³à²¿.",
        "reply_source" => "stt_guard",
        "suggestion" => null,
        "quick_actions" => [],
        "suggestion_priority" => null,
        "effective_message" => $message,
        "debug" => [
            "understanding" => $understanding ?? [],
            "raw_transcript" => $rawTranscriptMeta,
            "corrected_transcript" => $correctedTranscriptMeta,
            "transcript_confidence" => $transcriptConfidenceMeta,
            "mean_word_confidence" => $meanWordConfidenceMeta
        ]
    ]);
    exit();
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
    '/^\s*(usn|registration number|university number)\s*[\?\.!]*\s*$|\b(what(?:\s+is|\'s)?|tell|show|give|share|say|confirm)\b.*\b(usn|registration number|university number)\b|\bmy\s+(usn|registration number|university number)\b|à¤¯à¥‚à¤à¤¸à¤à¤¨|à¤°à¤œà¤¿à¤¸à¥à¤Ÿà¥à¤°à¥‡à¤¶à¤¨ à¤¨à¤‚à¤¬à¤°/u',
    $normalizedMessage
);
$hasAttendanceWord = (bool) preg_match(
    '/\battendance\b|à²…à²Ÿà³†à²‚à²¡à³†à²¨à³à²¸à³|à²¹à²¾à²œà²°à²¿|à²¹à²¾à²œà²°à²¾à²¤à²¿|attendence|atendance/u',
    $normalizedMessage
);
$hasOverallAttendanceWord = (bool) preg_match(
    '/\boverall\b|\btotal\b|à²’à²Ÿà³à²Ÿà³|à²Ÿà³‹à²Ÿà²²à³/u',
    $normalizedMessage
);
$inferredAttendanceSubject = StudentController::inferAttendanceSubject($message);
$inferredCourseSubject = StudentController::inferCourseSubject($message);
$hasSpecificSubjectWord = (bool) preg_match(
    '/\b(dbms|d\s*b\s*m\s*s|os|o\s*s|cn|c\s*n|ai|a\s*i|database management systems|operating systems|computer networks|dbms laboratory|artificial intelligence|cs501|cs502|cs503|cs5l1|cs5e1)\b|à²¡à²¿à²¬à²¿à²Žà²‚à²Žà²¸à³|à²¡à²¿à²¬à²¿à²Žà²‚à²Žà²¸à³ à²²à³à²¯à²¾à²¬à³|à²†à²ªà²°à³‡à²Ÿà²¿à²‚à²—à³ à²¸à²¿à²¸à³à²Ÿà²®à³à²¸à³|à²•à²‚à²ªà³à²¯à³‚à²Ÿà²°à³ à²¨à³†à²Ÿà³à²µà²°à³à²•à³à²¸à³|à²†à²°à³à²Ÿà²¿à²«à²¿à²¶à²¿à²¯à²²à³ à²‡à²‚à²Ÿà³†à²²à²¿à²œà³†à²¨à³à²¸à³/u',
    $normalizedMessage
);
$hasSpecificSubjectWord = $hasSpecificSubjectWord || $inferredAttendanceSubject !== "" || $inferredCourseSubject !== "";
$hasCourseCodeWord = StudentController::isLikelyCourseCodeQuery($message);
$hasCourseCodeWord = $hasCourseCodeWord || (
    $hasSpecificSubjectWord
    && (bool) preg_match('/\b(course|subject)\s+code\b|\bcode\s+(of|for)\b|\bcode\b|à²•à³‹à²¡à³|à²•à³‹à²¡à²¿|à²µà²¿à²·à²¯à²¦ à²•à³‹à²¡à³/u', $message)
); /*
    '/\b(course|subject)\s+code\b|\bcode\s+(of|for)\b|\bcode\b|à²•à³‹à²¡à³|course code|subject code/u',
    $normalizedMessage
);
$hasKannadaCourseCodeHint = (bool) preg_match(
    '/à²•à³‹à²¡à³|à²•à³‹à²¡à²¿|à²•à³‹à²°à³à²¡à³|à²•à³‹à²°à³à²¸à³ à²•à³‹à²¡à³|à²¸à²¬à³à²œà³†à²•à³à²Ÿà³ à²•à³‹à²¡à³|à²µà²¿à²·à²¯à²¦ à²•à³‹à²¡à³/u',
    $message
);
*/
if ($student_id && $hasAttendanceWord && $hasSpecificSubjectWord && !$hasOverallAttendanceWord) {
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
        $reply = $matches[1] . " à¤•à¤¾ à¤•à¥‹à¤°à¥à¤¸ à¤•à¥‹à¤¡ " . $matches[2] . " à¤¹à¥ˆà¥¤";
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
    $isExplicitUsnQuery &&
    !$hasAttendanceWord &&
    !$hasCourseCodeWord &&
    !(bool) preg_match('/\b(result|marks|semester|internal|subject|course)\b|à²«à²²à²¿à²¤à²¾à²‚à²¶|à²®à²¾à²°à³à²•à³à²¸à³|à²¸à³†à²®à²¿à²¸à³à²Ÿà²°à³|à²‡à²‚à²Ÿà²°à³à²¨à²²à³|à²µà²¿à²·à²¯/u', $message)
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
            $dbReplyIsLocalized = in_array($language, ["hi", "kn"], true);
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
            $dbReplyIsLocalized = in_array($language, ["hi", "kn"], true);
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
            $dbReplyIsLocalized = in_array($language, ["hi", "kn"], true);
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
            $dbReplyIsLocalized = in_array($language, ["hi", "kn"], true);
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
            $dbReplyIsLocalized = in_array($language, ["hi", "kn"], true);
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
            $dbReplyIsLocalized = in_array($language, ["hi", "kn"], true);
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
            $dbReplyIsLocalized = in_array($language, ["hi", "kn"], true);
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
            $dbReplyIsLocalized = in_array($language, ["hi", "kn"], true);
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
            $dbReplyIsLocalized = in_array($language, ["hi", "kn"], true);
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
                '/\b(overall|total|my attendance|attendance percentage|attendance status)\b|à²’à²Ÿà³à²Ÿà³|à²’à²µà²°à³ à²†à²²à³|overall|à²Ÿà³‹à²Ÿà²²à³/u',
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
    "suggestion_priority" => $suggestion["priority"] ?? null,
    "effective_message" => $message,
    "debug" => [
        "understanding" => $understanding ?? [],
        "raw_transcript" => $rawTranscriptMeta,
        "corrected_transcript" => $correctedTranscriptMeta,
        "transcript_confidence" => $transcriptConfidenceMeta,
        "mean_word_confidence" => $meanWordConfidenceMeta
    ]
]);




