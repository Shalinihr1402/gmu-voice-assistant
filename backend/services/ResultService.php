<?php

function getSGPA($aadhaar, $conn) {

    $sql = "SELECT r.sgpa 
            FROM results r
            JOIN students s ON r.student_id = s.student_id
            WHERE s.aadhaar_no = ?
            ORDER BY r.semester DESC
            LIMIT 1";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $aadhaar);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();

    return "Your latest semester SGPA is " . $row['sgpa'];
}
