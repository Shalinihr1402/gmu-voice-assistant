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
require_once "services/ResultCatalogService.php";

if (!isset($_SESSION['student_id'])) {
    http_response_code(401);
    echo json_encode(["error" => "Not logged in"]);
    exit();
}

$student_id = (int) $_SESSION['student_id'];
ResultCatalogService::ensureCatalogReady($conn);

$studentStmt = $conn->prepare("
    SELECT student_id, full_name, usn, branch, semester
    FROM students
    WHERE student_id = ?
");

if (!$studentStmt) {
    http_response_code(500);
    echo json_encode(["error" => "Could not load student profile."]);
    exit();
}

$studentStmt->bind_param("i", $student_id);
$studentStmt->execute();
$student = $studentStmt->get_result()->fetch_assoc();
$studentStmt->close();

if (!$student) {
    http_response_code(404);
    echo json_encode(["error" => "Student not found."]);
    exit();
}

$selections = ResultCatalogService::getPublishedSelections($conn, $student_id);
$conn->close();

echo json_encode([
    "student" => [
        "student_id" => (int) $student["student_id"],
        "full_name" => $student["full_name"],
        "usn" => $student["usn"],
        "branch" => $student["branch"],
        "current_semester" => (int) $student["semester"]
    ],
    "selections" => $selections
]);
