<?php
$host = "localhost";
$user = "root";
$password = "";
$database = "gmu_voice_assistant";

$conn = new mysqli($host, $user, $password, $database);

if ($conn->connect_error) {
    die("Database connection failed");
}
?>
