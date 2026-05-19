<?php
ini_set('session.cookie_samesite', 'Lax');
ini_set('session.cookie_secure', '0');

header("Access-Control-Allow-Origin: http://localhost:3000");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

session_start();
require_once __DIR__ . "/services/VapiSessionService.php";
require_once __DIR__ . "/services/VapiAssistantConfigService.php";

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(["enabled" => false, "error" => "Please login before starting the Vapi voice assistant."]);
    exit();
}

$language = strtolower(trim((string) ($_GET["language"] ?? "multi")));
if (!in_array($language, ["en", "hi", "kn", "multi"], true)) {
    $language = "multi";
}

$tokenPayload = VapiSessionService::createForCurrentSession($_SESSION['user_id']);
echo json_encode(VapiAssistantConfigService::buildConfig($tokenPayload, $language), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
