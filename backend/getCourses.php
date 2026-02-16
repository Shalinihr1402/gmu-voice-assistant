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

if (!isset($_SESSION['student_id'])) {
    echo json_encode(["error" => "Not logged in"]);
    exit();
}

$student_id = $_SESSION['student_id'];

/* Get student's branch and semester */
$studentQuery = $conn->prepare("
    SELECT branch, semester 
    FROM students 
    WHERE student_id = ?
");
$studentQuery->bind_param("i", $student_id);
$studentQuery->execute();
$studentResult = $studentQuery->get_result()->fetch_assoc();

if (!$studentResult) {
    echo json_encode(["error" => "Student not found"]);
    exit();
}

$branch = $studentResult['branch'];
$semester = $studentResult['semester'];

/* Fetch courses */
$stmt = $conn->prepare("
    SELECT course_code, course_title, course_type
    FROM courses
    WHERE program = ? AND semester = ?
");

$stmt->bind_param("si", $branch, $semester);
$stmt->execute();
$result = $stmt->get_result();

$courses = [];

while ($row = $result->fetch_assoc()) {
    $courses[] = [
        "code" => $row['course_code'],
        "title" => $row['course_title'],
        "group" => "ACADEMIC",
        "type" => $row['course_type']
    ];
}

echo json_encode($courses);

$stmt->close();
$conn->close();
