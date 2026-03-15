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

$apiKey = getEnvValue("GEMINI_API_KEY") ?: getEnvValue("GOOGLE_API_KEY");
$model = getEnvValue("GEMINI_MODEL") ?: "gemini-2.5-flash";
$provider = strtolower(trim((string) (getEnvValue("LLM_PROVIDER") ?: "")));

if (!$apiKey) {
    http_response_code(500);
    echo json_encode([
        "ok" => false,
        "provider" => $provider ?: null,
        "model" => $model,
        "has_key" => false,
        "error" => "GEMINI_API_KEY is missing from the PHP environment."
    ]);
    exit();
}

$payload = json_encode([
    "system_instruction" => [
        "parts" => [[
            "text" => "Reply in one short sentence."
        ]]
    ],
    "contents" => [[
        "role" => "user",
        "parts" => [[
            "text" => "Say hello from Gemini."
        ]]
    ]],
    "generationConfig" => [
        "temperature" => 0.2,
        "maxOutputTokens" => 40
    ]
]);

$url = "https://generativelanguage.googleapis.com/v1beta/models/" . rawurlencode($model) . ":generateContent";
$ch = curl_init($url);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "x-goog-api-key: " . $apiKey,
    "Content-Type: application/json"
]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 20);

$response = curl_exec($ch);
$statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($response === false) {
    http_response_code(502);
    echo json_encode([
        "ok" => false,
        "provider" => $provider ?: null,
        "model" => $model,
        "has_key" => true,
        "error" => $curlError ?: "Unable to reach Gemini."
    ]);
    exit();
}

$data = json_decode($response, true);

if ($statusCode >= 400) {
    http_response_code($statusCode);
    echo json_encode([
        "ok" => false,
        "provider" => $provider ?: null,
        "model" => $model,
        "has_key" => true,
        "status_code" => $statusCode,
        "error" => $data["error"]["message"] ?? $data["message"] ?? "Gemini request failed."
    ]);
    exit();
}

$text = trim((string) ($data["candidates"][0]["content"]["parts"][0]["text"] ?? ""));

echo json_encode([
    "ok" => $text !== "",
    "provider" => $provider ?: null,
    "model" => $model,
    "has_key" => true,
    "status_code" => $statusCode,
    "reply" => $text !== "" ? $text : null
]);
