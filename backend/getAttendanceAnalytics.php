<?php

require_once __DIR__ . "/cors.php";

header("Content-Type: application/json");

session_start();
require_once "config/db.php";

if (!isset($_SESSION['student_id'])) {
    http_response_code(401);
    echo json_encode(["error" => "Not logged in"]);
    exit();
}

$student_id = (int) $_SESSION['student_id'];

$studentStmt = $conn->prepare("
    SELECT full_name, usn, branch, semester
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
    echo json_encode(["error" => "Student not found"]);
    exit();
}

$semester = (int) $student["semester"];
$branch = (string) $student["branch"];

$attendanceStmt = $conn->prepare("
    SELECT
        c.course_code,
        c.course_title,
        c.course_type,
        a.total_classes,
        a.attended_classes,
        a.percentage
    FROM attendance a
    INNER JOIN courses c ON a.course_id = c.course_id
    WHERE a.student_id = ?
      AND c.program = ?
      AND c.semester = ?
    ORDER BY c.course_code ASC
");

if (!$attendanceStmt) {
    http_response_code(500);
    echo json_encode(["error" => "Could not load attendance data."]);
    exit();
}

$attendanceStmt->bind_param("isi", $student_id, $branch, $semester);
$attendanceStmt->execute();
$result = $attendanceStmt->get_result();

$subjects = [];
$totalClasses = 0;
$attendedClasses = 0;
$belowThresholdCount = 0;

while ($row = $result->fetch_assoc()) {
    $subjectTotal = (int) $row["total_classes"];
    $subjectAttended = (int) $row["attended_classes"];
    $percentage = round((float) $row["percentage"], 2);

    $subjects[] = [
        "course_code" => $row["course_code"],
        "course_title" => $row["course_title"],
        "course_type" => $row["course_type"],
        "total_classes" => $subjectTotal,
        "attended_classes" => $subjectAttended,
        "percentage" => $percentage,
        "status" => $percentage >= 75 ? "SAFE" : "LOW"
    ];

    $totalClasses += $subjectTotal;
    $attendedClasses += $subjectAttended;

    if ($percentage < 75) {
        $belowThresholdCount += 1;
    }
}

$attendanceStmt->close();
$conn->close();

if (empty($subjects)) {
    http_response_code(404);
    echo json_encode(["error" => "No attendance data found for the current semester."]);
    exit();
}

$overallPercentage = $totalClasses > 0 ? round(($attendedClasses / $totalClasses) * 100, 2) : 0.0;

echo json_encode([
    "student" => [
        "full_name" => $student["full_name"],
        "usn" => $student["usn"],
        "branch" => $branch,
        "semester" => $semester
    ],
    "summary" => [
        "overall_percentage" => $overallPercentage,
        "total_classes" => $totalClasses,
        "attended_classes" => $attendedClasses,
        "subject_count" => count($subjects),
        "below_threshold_count" => $belowThresholdCount
    ],
    "subjects" => $subjects
]);
