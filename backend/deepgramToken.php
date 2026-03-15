<?php
header("Access-Control-Allow-Origin: http://localhost:3000");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: GET, OPTIONS");
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

$payload = json_encode([
    "ttl_seconds" => 30
]);

$ch = curl_init("https://api.deepgram.com/v1/auth/grant");
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
    echo json_encode([
        "error" => $curlError ?: "Unable to reach Deepgram."
    ]);
    exit();
}

$data = json_decode($response, true);
$token = $data["access_token"] ?? null;

if ($statusCode >= 400 || !$token) {
    http_response_code($statusCode >= 400 ? $statusCode : 502);
    echo json_encode([
        "error" => $data["err_msg"] ?? $data["message"] ?? "Unable to create Deepgram token."
    ]);
    exit();
}

echo json_encode([
    "token" => $token
]);
