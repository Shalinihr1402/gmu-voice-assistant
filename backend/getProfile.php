<?php
session_start();
require_once "config/db.php";

header("Content-Type: application/json");

if (!isset($_SESSION['student_id'])) {
    echo json_encode(["error" => "Not logged in"]);
    exit();
}

$student_id = $_SESSION['student_id'];

$stmt = $conn->prepare("
    SELECT name, usn, branch, semester 
    FROM students 
    WHERE student_id = ?
");
$stmt->bind_param("i", $student_id);
$stmt->execute();
$result = $stmt->get_result()->fetch_assoc();

echo json_encode($result);
