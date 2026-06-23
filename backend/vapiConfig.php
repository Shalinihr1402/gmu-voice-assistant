<?php
require_once __DIR__ . "/cors.php";

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

// Fetch student first name for personalized greeting
$studentName = "";
try {
    require_once __DIR__ . "/config/db.php";
    $nameStmt = $conn->prepare("SELECT COALESCE(s.full_name, sm.full_name, u.login_id) AS full_name FROM users u LEFT JOIN students s ON s.student_id = u.student_id LEFT JOIN staff_members sm ON sm.staff_id = u.staff_id WHERE u.user_id = ?");
    if ($nameStmt) {
        $nameStmt->bind_param("i", $_SESSION['user_id']);
        $nameStmt->execute();
        $nameRow = $nameStmt->get_result()->fetch_assoc();
        $nameStmt->close();
        if ($nameRow && !empty($nameRow["full_name"])) {
            $parts = explode(" ", trim($nameRow["full_name"]));
            $studentName = $parts[0];
        }
    }
} catch (Exception $e) {}

$tokenPayload["student_name"] = $studentName;
echo json_encode(VapiAssistantConfigService::buildConfig($tokenPayload, $language), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
