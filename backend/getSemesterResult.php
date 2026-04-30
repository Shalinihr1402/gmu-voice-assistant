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

if (!isset($_SESSION['student_id'])) {
    echo json_encode(["error" => "Not logged in"]);
    exit();
}

$student_id = (int) $_SESSION['student_id'];
$input = json_decode(file_get_contents("php://input"), true) ?: [];

$usn = trim((string) ($input["usn"] ?? ""));
$semester = (int) ($input["semester"] ?? 0);
$exam = trim((string) ($input["exam"] ?? ""));
$year = trim((string) ($input["year"] ?? ""));
$season = trim((string) ($input["season"] ?? ""));

if ($semester <= 0 || $exam === "" || $year === "" || $season === "") {
    http_response_code(422);
    echo json_encode(["error" => "Please fill USN, semester, exam, year, and season."]);
    exit();
}

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
    echo json_encode(["error" => "Student not found."]);
    exit();
}

if ($usn !== "" && strcasecmp($usn, (string) $student["usn"]) !== 0) {
    http_response_code(404);
    echo json_encode(["error" => "USN does not match the logged in student."]);
    exit();
}

$resultStmt = $conn->prepare("
    SELECT c.course_code, c.course_title, r.credits, r.grade_point
    FROM results r
    JOIN courses c ON r.course_id = c.course_id
    WHERE r.student_id = ?
      AND r.semester = ?
    ORDER BY c.course_code ASC
");

if (!$resultStmt) {
    http_response_code(500);
    echo json_encode(["error" => "Could not load result data."]);
    exit();
}

$resultStmt->bind_param("ii", $student_id, $semester);
$resultStmt->execute();
$result = $resultStmt->get_result();

$subjects = [];
$totalCredits = 0.0;
$totalPoints = 0.0;
$backlogs = [];

while ($row = $result->fetch_assoc()) {
    $credits = (float) $row["credits"];
    $gradePoint = (float) $row["grade_point"];

    $subjects[] = [
        "course_code" => $row["course_code"],
        "course_title" => $row["course_title"],
        "credits" => $credits,
        "grade_point" => $gradePoint,
        "status" => $gradePoint > 0 ? "PASS" : "BACKLOG"
    ];

    $totalCredits += $credits;
    $totalPoints += ($gradePoint * $credits);

    if ($gradePoint <= 0) {
        $backlogs[] = $row["course_title"];
    }
}

$resultStmt->close();
$conn->close();

if (empty($subjects)) {
    http_response_code(404);
    echo json_encode(["error" => "No result found for the selected semester."]);
    exit();
}

$sgpa = $totalCredits > 0 ? round($totalPoints / $totalCredits, 2) : 0.0;
$status = empty($backlogs) ? "PASS" : "FAIL";

echo json_encode([
    "student" => [
        "full_name" => $student["full_name"],
        "usn" => $student["usn"],
        "branch" => $student["branch"],
        "current_semester" => (int) $student["semester"]
    ],
    "selection" => [
        "semester" => $semester,
        "exam" => $exam,
        "year" => $year,
        "season" => $season
    ],
    "summary" => [
        "sgpa" => $sgpa,
        "credits" => $totalCredits,
        "status" => $status,
        "backlog_count" => count($backlogs),
        "backlogs" => $backlogs
    ],
    "subjects" => $subjects
]);
