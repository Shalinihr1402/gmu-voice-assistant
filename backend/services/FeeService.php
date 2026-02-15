<?php

function getFeeBalance($aadhaar, $conn) {

    $sql = "SELECT s.student_id, s.quota 
            FROM students s 
            WHERE s.aadhaar_no = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $aadhaar);
    $stmt->execute();
    $result = $stmt->get_result();
    $student = $result->fetch_assoc();

    $student_id = $student['student_id'];
    $quota = $student['quota'];

    $totalQuery = "SELECT SUM(total_fee) as total 
                   FROM fee_structure 
                   WHERE quota = ?";
    $stmt2 = $conn->prepare($totalQuery);
    $stmt2->bind_param("s", $quota);
    $stmt2->execute();
    $totalResult = $stmt2->get_result()->fetch_assoc();

    $paidQuery = "SELECT SUM(amount_paid) as paid 
                  FROM student_payments 
                  WHERE student_id = ?";
    $stmt3 = $conn->prepare($paidQuery);
    $stmt3->bind_param("i", $student_id);
    $stmt3->execute();
    $paidResult = $stmt3->get_result()->fetch_assoc();

    $balance = $totalResult['total'] - $paidResult['paid'];

    return "Your remaining fee balance is ₹" . $balance;
}
