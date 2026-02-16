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

/* 1️⃣ Get student's quota */
$studentQuery = $conn->prepare("
    SELECT quota 
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

$quota = $studentResult['quota'];

/* 2️⃣ Get fee structure based on quota + payments */
$stmt = $conn->prepare("
    SELECT 
        fs.fee_id,
        fs.fee_type,
        fs.total_fee,
        IFNULL(SUM(sp.amount_paid), 0) AS paid
    FROM fee_structure fs
    LEFT JOIN student_payments sp 
        ON fs.fee_id = sp.fee_id 
        AND sp.student_id = ?
    WHERE fs.quota = ?
    GROUP BY fs.fee_id
");

$stmt->bind_param("is", $student_id, $quota);
$stmt->execute();
$result = $stmt->get_result();

$payments = [];

while ($row = $result->fetch_assoc()) {

    $balance = $row['total_fee'] - $row['paid'];

    $payments[] = [
        "fee_type" => $row['fee_type'],
        "total_fee" => $row['total_fee'],
        "paid" => $row['paid'],
        "balance" => $balance
    ];
}

echo json_encode($payments);

$stmt->close();
$conn->close();
