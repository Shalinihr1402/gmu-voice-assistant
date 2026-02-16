<?php

// ✅ CORS HEADERS (MUST BE BEFORE session_start)
header("Access-Control-Allow-Origin: http://localhost:3000");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json");

// ✅ Handle preflight request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

session_start();

$_SESSION['student_id'] = 2;
$_SESSION['aadhaar'] = "789654123012";

require_once "config/db.php";

// ✅ Check login session
if (!isset($_SESSION['student_id'])) {
    echo json_encode(["error" => "Not logged in"]);
    exit();
}

$student_id = $_SESSION['student_id'];

// ✅ Fetch student data
$stmt = $conn->prepare("
    SELECT name, usn, branch, semester 
    FROM students 
    WHERE student_id = ?
");

$stmt->bind_param("i", $student_id);
$stmt->execute();
$result = $stmt->get_result()->fetch_assoc();

// ✅ If no data found
if (!$result) {
    echo json_encode(["error" => "Student not found"]);
    exit();
}

// ✅ Return student profile
echo json_encode($result);

$stmt->close();
$conn->close();
