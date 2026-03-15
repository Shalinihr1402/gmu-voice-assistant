<?php

header("Access-Control-Allow-Origin: http://localhost:3000");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

session_start();
require_once "config/db.php";

$data = json_decode(file_get_contents("php://input"), true);

$loginId = trim($data['loginId'] ?? ($data['aadhaar'] ?? ''));
$password = $data['password'] ?? '';

$stmt = $conn->prepare("
    SELECT
        u.user_id,
        u.password_text,
        r.role_key,
        s.student_id,
        COALESCE(s.full_name, sm.full_name) AS full_name
    FROM users u
    INNER JOIN roles r ON r.role_id = u.role_id
    LEFT JOIN students s ON s.student_id = u.student_id
    LEFT JOIN staff_members sm ON sm.staff_id = u.staff_id
    WHERE u.login_id = ? AND u.is_active = 1
");

if (!$stmt) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Database schema error: missing or invalid users table."
    ]);
    exit();
}

$stmt->bind_param("s", $loginId);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 1) {
    $row = $result->fetch_assoc();

    if ($password === $row['password_text']) {
        $_SESSION['user_id'] = $row['user_id'];
        $_SESSION['role'] = $row['role_key'];
        $_SESSION['full_name'] = $row['full_name'];
        unset($_SESSION['student_id']);

        if ($row['role_key'] === 'student' && $row['student_id']) {
            $_SESSION['student_id'] = $row['student_id'];
        }

        $updateStmt = $conn->prepare("UPDATE users SET last_login_at = NOW() WHERE user_id = ?");
        if ($updateStmt) {
            $updateStmt->bind_param("i", $row['user_id']);
            $updateStmt->execute();
            $updateStmt->close();
        }

        echo json_encode([
            "success" => true,
            "role" => $row['role_key'],
            "name" => $row['full_name']
        ]);
        exit();
    }

    echo json_encode([
        "success" => false,
        "message" => "Wrong password"
    ]);
    exit();
}

echo json_encode([
    "success" => false,
    "message" => "Login ID not found"
]);

$stmt->close();
$conn->close();
