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

if (!isset($_SESSION['user_id']) && !isset($_SESSION['student_id'])) {
    http_response_code(401);
    echo json_encode([
        "ok" => false,
        "error" => "Unauthorized access. Please login."
    ]);
    exit();
}

function getEnvValue($key) {
    $value = getenv($key);

    if ($value !== false && $value !== "") {
        return $value;
    }

    if (isset($_SERVER[$key]) && $_SERVER[$key] !== "") {
        return $_SERVER[$key];
    }

    if (isset($_ENV[$key]) && $_ENV[$key] !== "") {
        return $_ENV[$key];
    }

    return null;
}

$apiKey = getEnvValue("DEEPGRAM_API_KEY");
$ttsModel = getEnvValue("DEEPGRAM_TTS_MODEL") ?: "aura-2-asteria-en";

if (!$apiKey) {
    http_response_code(500);
    echo json_encode([
        "ok" => false,
        "has_key" => false,
        "tts_model" => $ttsModel,
        "error" => "DEEPGRAM_API_KEY is missing from the PHP environment."
    ]);
    exit();
}

$payload = json_encode([
    "ttl_seconds" => 300
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
        "ok" => false,
        "has_key" => true,
        "tts_model" => $ttsModel,
        "error" => $curlError ?: "Unable to reach Deepgram."
    ]);
    exit();
}

$data = json_decode($response, true);
$token = $data["access_token"] ?? null;

if ($statusCode >= 400 || !$token) {
    http_response_code($statusCode >= 400 ? $statusCode : 502);
    echo json_encode([
        "ok" => false,
        "has_key" => true,
        "tts_model" => $ttsModel,
        "status_code" => $statusCode,
        "error" => $data["err_msg"] ?? $data["message"] ?? "Unable to create Deepgram token.",
        "raw" => $data
    ]);
    exit();
}

echo json_encode([
    "ok" => true,
    "has_key" => true,
    "tts_model" => $ttsModel,
    "status_code" => $statusCode,
    "token_preview" => substr($token, 0, 16) . "...",
    "token_length" => strlen($token)
]);
