<?php
header("Content-Type: application/json");
$conn = new mysqli('127.0.0.1','root','','gmu_voice_assistant');
if($conn->connect_error){
    echo json_encode(["status"=>"DB_ERROR","msg"=>$conn->connect_error]);
    exit;
}

// Check all users including inactive
$r = $conn->query("SELECT u.login_id, u.password_text, u.is_active, r.role_key, s.full_name
    FROM users u
    INNER JOIN roles r ON r.role_id = u.role_id
    LEFT JOIN students s ON s.student_id = u.student_id
    LIMIT 10");
$rows = [];
if($r) while($row=$r->fetch_assoc()) $rows[] = $row;
echo json_encode(["status"=>"OK","users"=>$rows], JSON_PRETTY_PRINT);
