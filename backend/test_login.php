<?php
require_once __DIR__ . "/cors.php";
header("Content-Type: application/json");

$raw = file_get_contents("php://input");
$data = json_decode($raw, true);

$loginId = trim($data['loginId'] ?? '');
$password = $data['password'] ?? '';

require_once "config/db.php";

$stmt = $conn->prepare("SELECT u.user_id, u.password_text, r.role_key FROM users u INNER JOIN roles r ON r.role_id=u.role_id WHERE u.login_id=? AND u.is_active=1");
$stmt->bind_param("s", $loginId);
$stmt->execute();
$result = $stmt->get_result();

echo json_encode([
    "raw_body"   => $raw,
    "parsed_id"  => $loginId,
    "parsed_pass"=> $password,
    "rows_found" => $result->num_rows,
    "content_type" => $_SERVER['CONTENT_TYPE'] ?? 'not set',
]);
