<?php
require_once __DIR__ . "/cors.php";

header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . "/services/VapiToolService.php";
require_once __DIR__ . "/services/VapiSecurityService.php";
require_once __DIR__ . "/services/LoggerService.php";
require_once __DIR__ . "/services/TraceContextService.php";

$webhookStart = LoggerService::nowMs();
TraceContextService::initialize([]);
$raw = file_get_contents("php://input");
$security = VapiSecurityService::validateRawWebhookRequest($raw);
if (!$security["ok"]) {
    LoggerService::security("vapi_webhook_rejected", [
        "status" => "rejected",
        "error_message" => $security["message"] ?? "Webhook request rejected.",
        "latency_ms" => LoggerService::durationMs($webhookStart)
    ]);
    http_response_code((int) ($security["status"] ?? 400));
    echo json_encode(["error" => $security["message"] ?? "Webhook request rejected."]);
    exit();
}

$payload = json_decode($raw, true);
if (!is_array($payload)) {
    LoggerService::error("vapi_webhook_json_parse_failed", [
        "status" => "error",
        "error_message" => "Invalid JSON payload",
        "latency_ms" => LoggerService::durationMs($webhookStart)
    ]);
    http_response_code(400);
    echo json_encode(["error" => "Invalid JSON payload"]);
    exit();
}

TraceContextService::initialize($payload);
$messageType = $payload["message"]["type"] ?? $payload["type"] ?? "";
LoggerService::voice("vapi_webhook_received", [
    "status" => "received",
    "message_type" => $messageType,
    "payload_size_bytes" => strlen((string) $raw)
]);

if ($messageType === "tool-calls" || !empty(VapiToolService::extractToolCalls($payload))) {
    $result = VapiToolService::buildToolResults($payload);
    $encoded = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $latency = LoggerService::durationMs($webhookStart);
    LoggerService::voice("vapi_webhook_completed", [
        "status" => "success",
        "message_type" => $messageType,
        "latency_ms" => $latency,
        "response_size_bytes" => strlen((string) $encoded)
    ]);
    LoggerService::markPerformance("vapi_webhook_latency", $latency, ["message_type" => $messageType]);
    echo $encoded;
    exit();
}

$encoded = json_encode(["ok" => true, "type" => $messageType]);
LoggerService::voice("vapi_webhook_completed", [
    "status" => "ignored",
    "message_type" => $messageType,
    "latency_ms" => LoggerService::durationMs($webhookStart),
    "response_size_bytes" => strlen((string) $encoded)
]);
echo $encoded;
