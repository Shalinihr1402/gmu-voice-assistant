<?php
header("Access-Control-Allow-Origin: http://localhost:3000");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

session_start();

if (!isset($_SESSION['student_id']) && !isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(["error" => "Unauthorized access. Please login."]);
    exit();
}

require_once __DIR__ . "/config/db.php";
require_once __DIR__ . "/services/SttService.php";

$apiKey = getenv("DEEPGRAM_API_KEY");
if (!$apiKey && isset($_SERVER["DEEPGRAM_API_KEY"])) {
    $apiKey = $_SERVER["DEEPGRAM_API_KEY"];
}
if (!$apiKey && isset($_ENV["DEEPGRAM_API_KEY"])) {
    $apiKey = $_ENV["DEEPGRAM_API_KEY"];
}

if (!$apiKey) {
    http_response_code(500);
    echo json_encode([
        "error" => "Deepgram API key is missing. Set DEEPGRAM_API_KEY in your server environment."
    ]);
    exit();
}

if (!isset($_FILES["audio"]) || $_FILES["audio"]["error"] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(["error" => "Audio file is missing."]);
    exit();
}

$requestedLanguage = trim((string) ($_POST["language"] ?? "en"));
$studentId = isset($_SESSION['student_id']) ? (int) $_SESSION['student_id'] : null;

try {
    $sttService = new SttService($conn, $apiKey);
    $result = $sttService->transcribeUploadedAudio($_FILES["audio"], $requestedLanguage, $studentId);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    $code = (int) $e->getCode();
    if ($code < 400 || $code > 599) {
        $code = 500;
    }

    if ($code === 400) {
        echo json_encode([
            "transcript" => "",
            "warning" => $e->getMessage()
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit();
    }

    http_response_code($code);
    echo json_encode([
        "error" => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
