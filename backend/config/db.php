<?php
mysqli_report(MYSQLI_REPORT_OFF);

$host = "localhost";
$user = "root";
$password = "";
$database = "gmu_voice_assistant";

$conn = new mysqli($host, $user, $password, $database);

if ($conn->connect_error) {
    http_response_code(500);
    header("Content-Type: application/json");
    echo json_encode([
        "error" => "Database connection failed"
    ]);
    exit();
}
?>
