<?php
header("Content-Type: application/json");
session_start();

require_once "config/db.php";
require_once "services/IntentService.php";
require_once "controllers/StudentController.php";
require_once "controllers/FeeController.php";

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Read input
$raw = file_get_contents("php://input");
$input = json_decode($raw, true);

if (!$input || !isset($input["message"])) {
    echo json_encode(["reply" => "No message received"]);
    exit();
}

$message = $input["message"];

// Get student id from session (temporary fixed for now)
$student_id = $_SESSION['student_id'] ?? 1;

// Detect intent
$intent = IntentService::detectIntent($message);

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
        // Fallback to Python AI
        $data = json_encode(["message" => $message]);

        $ch = curl_init("http://127.0.0.1:5000/chat");
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json"]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $response = curl_exec($ch);
        curl_close($ch);

        if (!$response) {
            $reply = "Sorry, I did not understand your request.";
        } else {
            echo $response;
            exit();
        }
}

echo json_encode(["reply" => $reply]);
