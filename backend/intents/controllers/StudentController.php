<?php

require_once __DIR__ . "/../../config/db.php";

class StudentController {

    /* ================= GET USN ================= */

    public static function getUSN($student_id) {
        global $conn;

        $stmt = $conn->prepare("
            SELECT usn 
            FROM students 
            WHERE student_id = ?
        ");

        if (!$stmt) {
            return "System error while fetching USN.";
        }

        $stmt->bind_param("i", $student_id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$result) {
            return "USN not found.";
        }

        return "Your USN is " . $result['usn'] . ".";
    }


    /* ================= CALCULATE SGPA ================= */

    public static function getSGPA($student_id) {
        global $conn;

        // Get latest semester
        $semStmt = $conn->prepare("
            SELECT semester
            FROM results
            WHERE student_id = ?
            ORDER BY semester DESC
            LIMIT 1
        ");

        if (!$semStmt) {
            return "System error while fetching result.";
        }

        $semStmt->bind_param("i", $student_id);
        $semStmt->execute();
        $semResult = $semStmt->get_result()->fetch_assoc();
        $semStmt->close();

        if (!$semResult) {
            return "No result information found.";
        }

        $semester = $semResult['semester'];

        // Fetch grade points & credits
        $stmt = $conn->prepare("
            SELECT grade_point, credits
            FROM results
            WHERE student_id = ?
            AND semester = ?
        ");

        $stmt->bind_param("ii", $student_id, $semester);
        $stmt->execute();
        $result = $stmt->get_result();

        $totalCredits = 0;
        $totalPoints = 0;

        while ($row = $result->fetch_assoc()) {

            $credits = (float)$row['credits'];
            $gradePoint = (float)$row['grade_point'];

            $totalCredits += $credits;
            $totalPoints += ($gradePoint * $credits);
        }

        $stmt->close();

        if ($totalCredits == 0) {
            return "Result data incomplete.";
        }

        $sgpa = round($totalPoints / $totalCredits, 2);

        // Performance message
        if ($sgpa >= 9) {
            $performance = "You performed outstandingly.";
        } elseif ($sgpa >= 8) {
            $performance = "You performed excellently.";
        } elseif ($sgpa >= 7) {
            $performance = "You performed well.";
        } elseif ($sgpa >= 6) {
            $performance = "Your performance is satisfactory.";
        } else {
            $performance = "You need improvement next semester.";
        }

        return "In semester $semester, your SGPA is $sgpa. You have earned $totalCredits credits. $performance";
    }


    /* ================= GET COURSE CODE ================= */

    public static function getCourseCode($message) {
        global $conn;

        $message = strtolower($message);

        $stmt = $conn->prepare("
            SELECT course_code, course_title 
            FROM courses
        ");

        if (!$stmt) {
            return "System error while fetching course information.";
        }

        $stmt->execute();
        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {

            $title = strtolower($row['course_title']);

            if (strpos($message, $title) !== false) {
                $stmt->close();
                return "The course code of " . $row['course_title'] . " is " . $row['course_code'] . ".";
            }
        }

        $stmt->close();

        return "I could not find that course.";
    }
     public static function getSubjectAttendance($student_id, $message) {
    global $conn;

    $message = strtolower($message);

    $stmt = $conn->prepare("
        SELECT c.course_title, a.total_classes, 
               a.attended_classes, a.percentage
        FROM attendance a
        JOIN courses c ON a.course_id = c.course_id
        WHERE a.student_id = ?
    ");

    if (!$stmt) {
        return "System error while fetching attendance.";
    }

    $stmt->bind_param("i", $student_id);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {

        $title = strtolower($row['course_title']);

        if (strpos($message, $title) !== false) {

            $stmt->close();

            return "Your attendance in " . $row['course_title'] .
                   " is " . $row['percentage'] . 
                   " percent. You attended " . 
                   $row['attended_classes'] . 
                   " out of " . 
                   $row['total_classes'] . " classes.";
        }
    }

    $stmt->close();

    return "I could not find attendance for that subject.";
}

    /* ================= GET ATTENDANCE ================= */

    public static function getAttendance($student_id) {
        global $conn;

        $stmt = $conn->prepare("
            SELECT attendance_percentage 
            FROM attendance 
            WHERE student_id = ?
            ORDER BY semester DESC
            LIMIT 1
        ");

        if (!$stmt) {
            return "System error while fetching attendance.";
        }

        $stmt->bind_param("i", $student_id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$result) {
            return "Attendance data not found.";
        }

        return "Your attendance percentage is " . $result['attendance_percentage'] . " percent.";
    }
}
