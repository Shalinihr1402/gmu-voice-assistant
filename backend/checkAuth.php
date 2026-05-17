<?php

// ✅ Allow React dev servers / XAMPP hosts (see cors.php)
require_once __DIR__ . "/cors.php";

session_start();

header("Content-Type: application/json");

if (isset($_SESSION["user_id"])) {
    echo json_encode([
        "loggedIn" => true,
        "role" => $_SESSION["role"] ?? null,
        "name" => $_SESSION["full_name"] ?? null
    ]);
} else {
    echo json_encode(["loggedIn" => false]);
}
