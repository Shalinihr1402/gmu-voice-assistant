<?php

// 🔥 CORS HEADERS — VERY IMPORTANT
header("Access-Control-Allow-Origin: http://localhost:3000");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Max-Age: 86400");
header("Content-Type: application/json");

// ✅ Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// ✅ Read JSON input
$raw = file_get_contents("php://input");
$input = json_decode($raw, true);
$message = $input["message"] ?? "";

// ✅ Forward to Python AI server
$data = json_encode(["message" => $message]);

$ch = curl_init("http://127.0.0.1:5000/chat");
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json"]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

$response = curl_exec($ch);

if ($response === false) {
    echo json_encode(["reply" => "Python server not responding"]);
    exit();
}

curl_close($ch);

// ✅ Return AI response
echo $response;
