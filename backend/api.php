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

// Detect intent
$classification = IntentService::classifyIntent($message, $userContext);
$intent = $classification["intent"] ?? "UNKNOWN";
$route = $classification["route"] ?? "llm";
$confidence = $classification["confidence"] ?? "low";
$intentSource = $classification["source"] ?? "unknown";

$reply = "";
$replySource = "unknown";
$handledByDatabase = false;

if ($route === "database") {
    switch ($intent) {
        case "GET_USN":
            if (!$student_id) {
                $reply = "USN lookup is available for student accounts after student login.";
                $replySource = "db_guard";
                $handledByDatabase = true;
                break;
            }
            $reply = StudentController::getUSN($student_id);
            $replySource = "db";
            $handledByDatabase = true;
            break;

        case "GET_PROFILE_SUMMARY":
            if (!$student_id) {
                $reply = "Profile lookup is available for student accounts after student login.";
                $replySource = "db_guard";
                $handledByDatabase = true;
                break;
            }
            $reply = StudentController::getProfileSummary($student_id, $message);
            $replySource = "db";
            $handledByDatabase = true;
            break;

        case "GET_SGPA":
            if (!$student_id) {
                $reply = "SGPA lookup is available for student accounts after student login.";
                $replySource = "db_guard";
                $handledByDatabase = true;
                break;
            }
            $reply = StudentController::getSGPA($student_id, $message);
            $replySource = "db";
            $handledByDatabase = true;
            break;

        case "GET_CGPA":
            if (!$student_id) {
                $reply = "CGPA lookup is available for student accounts after student login.";
                $replySource = "db_guard";
                $handledByDatabase = true;
                break;
            }
            $reply = StudentController::getCGPA($student_id);
            $replySource = "db";
            $handledByDatabase = true;
            break;

        case "GET_BACKLOG_STATUS":
            if (!$student_id) {
                $reply = "Backlog status is available for student accounts after student login.";
                $replySource = "db_guard";
                $handledByDatabase = true;
                break;
            }
            $reply = StudentController::getBacklogStatus($student_id, $message);
            $replySource = "db";
            $handledByDatabase = true;
            break;

        case "GET_FEES_BALANCE":
            if (!$student_id) {
                $reply = "Fee balance lookup is available for student accounts after student login.";
                $replySource = "db_guard";
                $handledByDatabase = true;
                break;
            }
            $reply = FeeController::getFeeBalance($student_id);
            $replySource = "db";
            $handledByDatabase = true;
            break;

        case "GET_FINAL_REGISTRATION_STATUS":
            if (!$student_id) {
                $reply = "Final registration status is available for student accounts after student login.";
                $replySource = "db_guard";
                $handledByDatabase = true;
                break;
            }
            $reply = FeeController::getFinalRegistrationStatus($student_id);
            $replySource = "db";
            $handledByDatabase = true;
            break;

        case "GET_HALL_TICKET_STATUS":
            if (!$student_id) {
                $reply = "Hall ticket status is available for student accounts after student login.";
                $replySource = "db_guard";
                $handledByDatabase = true;
                break;
            }
            $reply = StudentController::getHallTicketStatus($student_id, $message);
            $replySource = "db";
            $handledByDatabase = true;
            break;

        case "GET_COURSE_DETAILS":
            if (!$student_id) {
                $reply = "Course details are available for student accounts after student login.";
                $replySource = "db_guard";
                $handledByDatabase = true;
                break;
            }
            $reply = StudentController::getCourseDetails($student_id, $message);
            $replySource = "db";
            $handledByDatabase = true;
            break;

        case "GET_ATTENDANCE":
            if (!$student_id) {
                $reply = "Attendance lookup is available for student accounts after student login.";
                $replySource = "db_guard";
                $handledByDatabase = true;
                break;
            }
            $reply = StudentController::getAttendance($student_id);
            $replySource = "db";
            $handledByDatabase = true;
            break;

        case "GET_SUBJECT_ATTENDANCE":
            if (!$student_id) {
                $reply = "Subject attendance lookup is available for student accounts after student login.";
                $replySource = "db_guard";
                $handledByDatabase = true;
                break;
            }
            $reply = StudentController::getSubjectAttendance($student_id, $message);
            $replySource = "db";
            $handledByDatabase = true;
            break;

        case "GET_COURSE_CODE":
            $reply = StudentController::getCourseCode($message);
            $replySource = "db";
            $handledByDatabase = true;
            break;
    }
}

if (!$handledByDatabase) {
    $intent = $handledByDatabase ? $intent : "LLM_ASSIST";
    $route = "llm";
    $reply = LlmService::getReply($message, $userContext);
}

if ($replySource !== "unknown") {
    LlmService::setLastReplyMeta($replySource);
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
