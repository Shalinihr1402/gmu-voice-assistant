<?php
header("Access-Control-Allow-Origin: http://localhost:3000");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

session_start();

if (!isset($_SESSION['user_id']) && !isset($_SESSION['student_id'])) {
    http_response_code(401);
    header("Content-Type: application/json");
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
    header("Content-Type: application/json");
    echo json_encode([
        "error" => "Deepgram API key is missing. Set DEEPGRAM_API_KEY in your server environment."
    ]);
    exit();
}

$raw = file_get_contents("php://input");
$input = json_decode($raw, true);
$text = trim((string) ($input["text"] ?? ""));

if ($text === "") {
    http_response_code(400);
    header("Content-Type: application/json");
    echo json_encode(["error" => "Text is required for speech synthesis."]);
    exit();
}

$payload = json_encode([
    "text" => $text
]);

$voice = getenv("DEEPGRAM_TTS_MODEL");
if (!$voice && isset($_SERVER["DEEPGRAM_TTS_MODEL"])) {
    $voice = $_SERVER["DEEPGRAM_TTS_MODEL"];
}
if (!$voice && isset($_ENV["DEEPGRAM_TTS_MODEL"])) {
    $voice = $_ENV["DEEPGRAM_TTS_MODEL"];
}
if (!$voice) {
    $voice = "aura-2-thalia-en";
}

$encoding = "mp3";
$url = "https://api.deepgram.com/v1/speak?model=" . rawurlencode($voice) . "&encoding=" . rawurlencode($encoding);

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Authorization: Token " . $apiKey,
    "Content-Type: application/json"
]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

$response = curl_exec($ch);
$statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($response === false) {
    http_response_code(502);
    header("Content-Type: application/json");
    echo json_encode([
        "error" => $curlError ?: "Unable to reach Deepgram TTS."
    ]);
    exit();
}

if ($statusCode >= 400) {
    http_response_code($statusCode);
    header("Content-Type: application/json");

    $data = json_decode($response, true);
    echo json_encode([
        "error" => $data["err_msg"] ?? $data["message"] ?? "Deepgram TTS failed."
    ]);
    exit();
}

header("Content-Type: audio/mpeg");
header("Content-Length: " . strlen($response));
echo $response;
