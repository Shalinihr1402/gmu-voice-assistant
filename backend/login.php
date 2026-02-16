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

$aadhaar = $data['aadhaar'] ?? '';
$password = $data['password'] ?? '';

// 🔥 DEFAULT PASSWORD
$defaultPassword = "123456";

// Check if Aadhaar exists
$stmt = $conn->prepare("
    SELECT student_id 
    FROM students 
    WHERE aadhaar_number = ?
");

$stmt->bind_param("s", $aadhaar);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 1) {

    if ($password === $defaultPassword) {

        $row = $result->fetch_assoc();
        $_SESSION['student_id'] = $row['student_id'];

        echo json_encode(["success" => true]);
        exit();
    } else {
        echo json_encode([
            "success" => false,
            "message" => "Wrong password"
        ]);
        exit();
    }
}

echo json_encode([
    "success" => false,
    "message" => "Aadhaar not found"
]);

$stmt->close();
$conn->close();
