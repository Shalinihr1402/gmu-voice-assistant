<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Vapi-Secret");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . "/services/VapiToolService.php";

$raw = file_get_contents("php://input");
$payload = json_decode($raw, true);
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(["error" => "Invalid JSON payload"]);
    exit();
}

$messageType = $payload["message"]["type"] ?? $payload["type"] ?? "";
if ($messageType === "tool-calls" || !empty(VapiToolService::extractToolCalls($payload))) {
    echo json_encode(VapiToolService::buildToolResults($payload), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit();
}

echo json_encode(["ok" => true, "type" => $messageType]);
