<?php

header("Access-Control-Allow-Origin: http://localhost:3000");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json");
session_start();

require_once __DIR__ . "/intents/studentIntent.php";
require_once __DIR__ . "/intents/controllers/StudentController.php";
require_once __DIR__ . "/intents/controllers/FeeController.php";
require_once __DIR__ . "/config/db.php";

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Check authentication
if (!isset($_SESSION['student_id'])) {
    echo json_encode([
        "status" => "error",
        "reply" => "Unauthorized access. Please login."
    ]);
    exit();
}

$student_id = $_SESSION['student_id'];

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
$intent = IntentService::detectIntent($message);

$reply = "";
$confidence = "high";

switch ($intent) {

    case "GET_USN":
        $reply = StudentController::getUSN($student_id);
        break;

    case "GET_SGPA":
        $reply = StudentController::getSGPA($student_id);
        break;

    case "GET_FEES_BALANCE":
        $reply = FeeController::getFeeBalance($student_id);
        break;

    case "GET_ATTENDANCE":
        $reply = StudentController::getAttendance($student_id);
        break;

    default:
        $confidence = "low";

        // Fallback to Python AI
        $data = json_encode(["message" => $message]);

        $ch = curl_init("http://127.0.0.1:5000/chat");
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json"]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $response = curl_exec($ch);
        curl_close($ch);

        if ($response) {
            $pythonReply = json_decode($response, true);
            $reply = $pythonReply["reply"] ?? "Sorry, I did not understand your request.";
        } else {
            $reply = "Sorry, I did not understand your request.";
        }
}

// Final Response
echo json_encode([
    "status" => "success",
    "intent" => $intent,
    "confidence" => $confidence,
    "reply" => $reply
]);
