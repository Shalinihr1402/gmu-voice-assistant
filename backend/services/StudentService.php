<?php

function getUSN($aadhaar, $conn) {
    $sql = "SELECT usn FROM students WHERE aadhaar_no = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $aadhaar);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();

    return "Your USN number is " . $row['usn'];
}

function getCourseCode($courseName, $conn) {
    $sql = "SELECT code FROM courses WHERE name = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $courseName);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();

    return "Course code for $courseName is " . $row['code'];
}
