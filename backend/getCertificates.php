<?php

header("Access-Control-Allow-Origin: http://localhost:3000");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit();
}

session_start();

require_once __DIR__ . "/services/CertificateService.php";

if (!isset($_SESSION["student_id"]) && !isset($_SESSION["user_id"])) {
    http_response_code(401);
    echo json_encode([
        "status" => "error",
        "message" => "Unauthorized access. Please login."
    ]);
    exit();
}

$result = CertificateService::fetchCertificates();
if ($result["status"] !== "success") {
    http_response_code(400);
    echo json_encode($result);
    exit();
}

echo json_encode([
    "status" => "success",
    "count" => count($result["records"]),
    "records" => $result["records"]
]);
