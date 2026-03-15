<?php
header("Access-Control-Allow-Origin: http://localhost:3000");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

session_start();

if (!isset($_SESSION['student_id'])) {
    http_response_code(401);
    echo json_encode(["error" => "Unauthorized access. Please login."]);
    exit();
}

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

$audioPath = $_FILES["audio"]["tmp_name"];
$mimeType = mime_content_type($audioPath);
$audioData = file_get_contents($audioPath);

if ($audioData === false || $audioData === "") {
    http_response_code(400);
    echo json_encode(["error" => "Uploaded audio is empty."]);
    exit();
}

// Treat extremely short clips as silence instead of surfacing a noisy 400.
if (strlen($audioData) < 2048) {
    echo json_encode([
        "transcript" => ""
    ]);
    exit();
}

$ch = curl_init("https://api.deepgram.com/v1/listen?model=nova-3&smart_format=true&punctuate=true");
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $audioData);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Authorization: Token " . $apiKey,
    "Content-Type: " . ($mimeType ?: "audio/webm")
]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

$response = curl_exec($ch);
$statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($response === false) {
    http_response_code(502);
    echo json_encode([
        "error" => $curlError ?: "Unable to reach Deepgram."
    ]);
    exit();
}

$data = json_decode($response, true);

if ($statusCode >= 400) {
    $message = $data["err_msg"] ?? $data["message"] ?? "Deepgram transcription failed.";

    if ($statusCode === 400) {
        echo json_encode([
            "transcript" => "",
            "warning" => $message
        ]);
        exit();
    }

    http_response_code($statusCode);
    echo json_encode([
        "error" => $message
    ]);
    exit();
}

$transcript = $data["results"]["channels"][0]["alternatives"][0]["transcript"] ?? "";

echo json_encode([
    "transcript" => $transcript
]);
