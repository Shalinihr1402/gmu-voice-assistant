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
require_once "config/db.php";

if (!isset($_SESSION['user_id'])) {
    echo json_encode(["error" => "Not logged in"]);
    exit();
}

$stmt = $conn->prepare("
    SELECT
        u.user_id,
        u.login_id,
        r.role_key,
        r.role_name,
        COALESCE(s.full_name, sm.full_name) AS full_name,
        COALESCE(s.email, sm.email) AS email,
        COALESCE(s.mobile_no, sm.mobile_no) AS mobile_no,
        COALESCE(s.branch, d.department_name) AS unit_name,
        sm.designation
    FROM users u
    INNER JOIN roles r ON r.role_id = u.role_id
    LEFT JOIN students s ON s.student_id = u.student_id
    LEFT JOIN staff_members sm ON sm.staff_id = u.staff_id
    LEFT JOIN departments d ON d.department_id = sm.department_id
    WHERE u.user_id = ?
");

if (!$stmt) {
    http_response_code(500);
    echo json_encode(["error" => "Database schema error: missing current user tables."]);
    exit();
}

$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$result = $stmt->get_result()->fetch_assoc();

if (!$result) {
    echo json_encode(["error" => "User not found"]);
    exit();
}

echo json_encode($result);

$stmt->close();
$conn->close();
