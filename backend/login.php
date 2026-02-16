<?php

// 🔥 MUST BE FIRST LINE (NO SPACE ABOVE)
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

// Get JSON input
$data = json_decode(file_get_contents("php://input"), true);

$aadhaar = $data['aadhaar'] ?? '';
$password = $data['password'] ?? '';

// Check user
$stmt = $conn->prepare("SELECT student_id, password FROM students WHERE aadhaar_number = ?");
$stmt->bind_param("s", $aadhaar);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 1) {
    $row = $result->fetch_assoc();

    if ($password === $row['password']) {   // (Use password_hash later)
        $_SESSION['student_id'] = $row['student_id'];

        echo json_encode(["success" => true]);
        exit();
    }
}

echo json_encode([
    "success" => false,
    "message" => "Invalid Aadhaar or Password"
]);

$stmt->close();
$conn->close();
