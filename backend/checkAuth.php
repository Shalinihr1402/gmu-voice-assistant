<?php

// ✅ Allow React (port 3000) to access backend (port 8080)
header("Access-Control-Allow-Origin: http://localhost:3000");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

// Handle preflight request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

session_start();

header("Content-Type: application/json");

if (isset($_SESSION["student_id"])) {
    echo json_encode(["loggedIn" => true]);
} else {
    echo json_encode(["loggedIn" => false]);
}
