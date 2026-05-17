<?php

require_once __DIR__ . "/cors.php";

header("Content-Type: application/json");

session_start();



require_once "config/db.php";

// ✅ Check login session
if (!isset($_SESSION['student_id'])) {
    echo json_encode(["error" => "Not logged in"]);
    exit();
}

$student_id = $_SESSION['student_id'];

// ✅ Fetch student data
$stmt = $conn->prepare("
   SELECT full_name, usn, branch, semester, email, mobile_no, aadhaar_number
FROM students
WHERE student_id = ?

");

if (!$stmt) {
    http_response_code(500);
    echo json_encode(["error" => "Database schema error: missing or invalid students table."]);
    exit();
}


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
