<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

ini_set('session.cookie_samesite', 'Lax');
ini_set('session.cookie_secure', '0');

header("Access-Control-Allow-Origin: http://localhost:3000");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json");

session_start();

require_once __DIR__ . "/intents/studentIntent.php";
require_once __DIR__ . "/intents/controllers/StudentController.php";
require_once __DIR__ . "/intents/controllers/FeeController.php";
require_once __DIR__ . "/services/LlmService.php";
require_once __DIR__ . "/services/UserService.php";
require_once __DIR__ . "/config/db.php";

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

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
$language = strtolower(trim((string) ($input["language"] ?? "en")));
if (in_array($language, ["hi", "hindi", "hi-in"], true)) {
    $language = "hi";
} elseif (in_array($language, ["kn", "kannada", "kn-in"], true)) {
    $language = "kn";
} else {
    $language = "en";
}

$normalizedMessage = strtolower($message);
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
    $reply = StudentController::getSubjectAttendance($student_id, $message, $language);
    LlmService::setLastReplyMeta($language === "kn" ? "db_kannada" : "db");
    echo json_encode([
        "status" => "success",
        "intent" => "GET_SUBJECT_ATTENDANCE",
        "route" => "database",
        "confidence" => "high",
        "intent_source" => "api_fast_path",
        "reply" => $reply,
        "reply_source" => $language === "kn" ? "db_kannada" : "db"
    ]);
    exit();
}

if ($hasCourseCodeWord) {
    $reply = StudentController::getCourseCode($message, $language);
    LlmService::setLastReplyMeta($language === "kn" ? "db_kannada" : "db");
    echo json_encode([
        "status" => "success",
        "intent" => "GET_COURSE_CODE",
        "route" => "database",
        "confidence" => "high",
        "intent_source" => "api_fast_path",
        "reply" => $reply,
        "reply_source" => $language === "kn" ? "db_kannada" : "db"
    ]);
    exit();
}

// Detect intent
$classification = IntentService::classifyIntent($message, $userContext);
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
            $reply = StudentController::getHallTicketStatus($student_id, $message, $language);
            $replySource = "db";
            $handledByDatabase = true;
            $dbReplyIsLocalized = ($language === "kn");
            break;

        case "GET_COURSE_DETAILS":
            if (!$student_id) {
                $reply = "Course details are available for student accounts after student login.";
                $replySource = "db_guard";
                $handledByDatabase = true;
                break;
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
            $normalizedAttendanceMessage = strtolower(trim((string) $message));
            $isExplicitOverallAttendance = (bool) preg_match(
                '/\b(overall|total|my attendance|attendance percentage|attendance status)\b|ಒಟ್ಟು|ಒವರ್ ಆಲ್|overall|ಟೋಟಲ್/u',
                $normalizedAttendanceMessage
            );

            $reply = $isExplicitOverallAttendance
                ? StudentController::getAttendance($student_id, $language)
                : StudentController::getSubjectAttendance($student_id, $message, $language);
            $replySource = "db";
            $handledByDatabase = true;
            $dbReplyIsLocalized = ($language === "kn");
            break;

        case "GET_SUBJECT_ATTENDANCE":
            if (!$student_id) {
                $reply = "Subject attendance lookup is available for student accounts after student login.";
                $replySource = "db_guard";
                $handledByDatabase = true;
                break;
            }
            $reply = StudentController::getSubjectAttendance($student_id, $message, $language);
            $replySource = "db";
            $handledByDatabase = true;
            $dbReplyIsLocalized = ($language === "kn");
            break;

        case "GET_COURSE_CODE":
            $reply = StudentController::getCourseCode($message, $language);
            $replySource = "db";
            $handledByDatabase = true;
            $dbReplyIsLocalized = ($language === "kn");
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
        $metaSource = "db_kannada";
    }

    LlmService::setLastReplyMeta($metaSource);
}

$replyMeta = LlmService::getLastReplyMeta();

echo json_encode([
    "status" => "success",
    "intent" => $intent,
    "route" => $route,
    "confidence" => $confidence,
    "intent_source" => $intentSource,
    "reply" => $reply,
    "reply_source" => $replyMeta["source"] ?? "unknown"
]);
