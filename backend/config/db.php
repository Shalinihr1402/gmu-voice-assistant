<?php
require_once __DIR__ . '/env.php';

mysqli_report(MYSQLI_REPORT_OFF);

$host = getenv('DB_HOST') ?: "127.0.0.1";
$user = getenv('DB_USER') ?: "root";
$password = getenv('DB_PASSWORD');
$database = getenv('DB_NAME') ?: "gmu_voice_assistant";

if ($password === false) {
    $password = "";
}


$conn = @new mysqli($host, $user, $password, $database);

if ($conn->connect_error) {
    http_response_code(500);
    header("Content-Type: application/json");
    echo json_encode([
        "error" => "Database connection failed",
        "details" => sprintf(
            "Unable to connect to MySQL database '%s' on host '%s'. Import backend/schema.sql or set DB_* values in backend/.env.",
            $database,
            $host
        )
    ]);
    exit();
}
?>
