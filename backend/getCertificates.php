<?php

require_once __DIR__ . "/cors.php";

header("Content-Type: application/json");

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
