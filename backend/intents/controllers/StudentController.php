<?php

require_once __DIR__ . "/../../config/db.php";

class StudentController {

    private static function getStudentAcademicContext($student_id) {
        global $conn;

        $stmt = $conn->prepare("
            SELECT branch, semester
            FROM students
            WHERE student_id = ?
        ");

        if (!$stmt) {
            return null;
        }

        $stmt->bind_param("i", $student_id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $result ?: null;
    }

    private static function extractRequestedSemester($message) {
        $message = strtolower($message);

        if (preg_match('/\b(?:semester|sem)\s*(\d+)\b/', $message, $matches)) {
            return (int) $matches[1];
        }

        $wordToSemester = [
            "first" => 1,
            "second" => 2,
            "third" => 3,
            "fourth" => 4,
            "fifth" => 5,
            "sixth" => 6,
            "seventh" => 7,
            "eighth" => 8,
            "1st" => 1,
            "2nd" => 2,
            "3rd" => 3,
            "4th" => 4,
            "5th" => 5,
            "6th" => 6,
            "7th" => 7,
            "8th" => 8
        ];

        foreach ($wordToSemester as $word => $semester) {
            if (strpos($message, $word . " sem") !== false || strpos($message, $word . " semester") !== false) {
                return $semester;
            }
        }

        return null;
    }

    private static function extractExamType($message) {
        $message = strtolower($message);

        if (strpos($message, "supplementary") !== false || strpos($message, "supply") !== false) {
            return "SUPPLEMENTARY";
        }

        if (strpos($message, "see") !== false) {
            return "SEE";
        }

        if (strpos($message, "cie") !== false || strpos($message, "internal") !== false) {
            return "CIE";
        }

        return null;
    }

    private static function getLatestSemester($student_id) {
        global $conn;

        $semStmt = $conn->prepare("
            SELECT semester
            FROM results
            WHERE student_id = ?
            ORDER BY semester DESC
            LIMIT 1
        ");

        if (!$semStmt) {
            return null;
        }

        $semStmt->bind_param("i", $student_id);
        $semStmt->execute();
        $semResult = $semStmt->get_result()->fetch_assoc();
        $semStmt->close();

        return $semResult["semester"] ?? null;
    }

    private static function getSemesterRows($student_id, $semester) {
        global $conn;

        $stmt = $conn->prepare("
            SELECT c.course_title, c.course_code, r.grade_point, r.credits
            FROM results r
            JOIN courses c ON r.course_id = c.course_id
            WHERE r.student_id = ?
            AND r.semester = ?
        ");

        if (!$stmt) {
            return null;
        }

        $stmt->bind_param("ii", $student_id, $semester);
        $stmt->execute();
        $result = $stmt->get_result();

        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }

        $stmt->close();
        return $rows;
    }

    private static function buildSemesterPerformance($student_id, $semester) {
        $rows = self::getSemesterRows($student_id, $semester);

        if ($rows === null) {
            return [
                "error" => "System error while fetching result."
            ];
        }

        if (empty($rows)) {
            return [
                "error" => "I could not find result data for semester {$semester}."
            ];
        }

        $totalCredits = 0.0;
        $totalPoints = 0.0;
        $backlogs = [];

        foreach ($rows as $row) {
            $credits = (float) $row["credits"];
            $gradePoint = (float) $row["grade_point"];

            $totalCredits += $credits;
            $totalPoints += ($gradePoint * $credits);

            // College rule: grade point 0 means the course is not cleared.
            if ($gradePoint <= 0) {
                $backlogs[] = $row["course_title"];
            }
        }

        if ($totalCredits <= 0) {
            return [
                "error" => "Result data is incomplete for semester {$semester}."
            ];
        }

        $sgpa = round($totalPoints / $totalCredits, 2);

        return [
            "semester" => $semester,
            "sgpa" => $sgpa,
            "credits" => $totalCredits,
            "backlogs" => $backlogs
        ];
    }

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

    public static function getSGPA($student_id, $message = "") {
        $requestedSemester = self::extractRequestedSemester($message);
        $semester = $requestedSemester ?: self::getLatestSemester($student_id);

        if (!$semester) {
            return "No result information found.";
        }

        $performance = self::buildSemesterPerformance($student_id, $semester);
        if (isset($performance["error"])) {
            return $performance["error"];
        }

        $sgpa = $performance["sgpa"];
        $totalCredits = $performance["credits"];
        $backlogs = $performance["backlogs"];

        if (!empty($backlogs)) {
            return "In semester {$semester}, your SGPA is {$sgpa}. You have earned {$totalCredits} credits. Your result status is fail because you still have " . count($backlogs) . " backlog" . (count($backlogs) > 1 ? "s" : "") . ", including " . implode(", ", array_slice($backlogs, 0, 3)) . ".";
        }

        if ($sgpa >= 9) {
            $performanceLine = "You passed with outstanding performance.";
        } elseif ($sgpa >= 8) {
            $performanceLine = "You passed with excellent performance.";
        } elseif ($sgpa >= 7) {
            $performanceLine = "You passed with good performance.";
        } elseif ($sgpa >= 6) {
            $performanceLine = "You passed with satisfactory performance.";
        } else {
            $performanceLine = "You passed, but you should improve next semester.";
        }

        return "In semester {$semester}, your SGPA is {$sgpa}. You have earned {$totalCredits} credits. {$performanceLine}";
    }

    public static function getCGPA($student_id) {
        global $conn;

        $stmt = $conn->prepare("
            SELECT semester, grade_point, credits
            FROM results
            WHERE student_id = ?
            ORDER BY semester ASC
        ");

        if (!$stmt) {
            return "System error while fetching CGPA.";
        }

        $stmt->bind_param("i", $student_id);
        $stmt->execute();
        $result = $stmt->get_result();

        $totalCredits = 0.0;
        $totalPoints = 0.0;
        $semesterSet = [];
        $backlogCount = 0;

        while ($row = $result->fetch_assoc()) {
            $credits = (float) $row["credits"];
            $gradePoint = (float) $row["grade_point"];

            $totalCredits += $credits;
            $totalPoints += ($gradePoint * $credits);
            $semesterSet[$row["semester"]] = true;

            if ($gradePoint <= 0) {
                $backlogCount += 1;
            }
        }

        $stmt->close();

        if ($totalCredits <= 0) {
            return "I could not find enough result data to calculate your CGPA.";
        }

        $cgpa = round($totalPoints / $totalCredits, 2);
        $semesterCount = count($semesterSet);

        $reply = "Your current CGPA is {$cgpa}, calculated across {$semesterCount} semester" . ($semesterCount > 1 ? "s" : "") . ".";

        if ($backlogCount > 0) {
            $reply .= " You currently have {$backlogCount} uncleared backlog" . ($backlogCount > 1 ? "s" : "") . ".";
        } else {
            $reply .= " You do not have any current backlog.";
        }

        return $reply;
    }

    public static function getBacklogStatus($student_id, $message = "") {
        $requestedSemester = self::extractRequestedSemester($message);

        if ($requestedSemester) {
            $performance = self::buildSemesterPerformance($student_id, $requestedSemester);
            if (isset($performance["error"])) {
                return $performance["error"];
            }

            $backlogs = $performance["backlogs"];
            if (empty($backlogs)) {
                return "You passed semester {$requestedSemester} and you do not have any backlog in that semester.";
            }

            return "In semester {$requestedSemester}, you have " . count($backlogs) . " backlog" . (count($backlogs) > 1 ? "s" : "") . ". The uncleared subject" . (count($backlogs) > 1 ? "s are " : " is ") . implode(", ", array_slice($backlogs, 0, 4)) . ".";
        }

        global $conn;

        $stmt = $conn->prepare("
            SELECT c.course_title, r.semester, r.grade_point
            FROM results r
            JOIN courses c ON r.course_id = c.course_id
            WHERE r.student_id = ?
            ORDER BY r.semester ASC
        ");

        if (!$stmt) {
            return "System error while checking backlog status.";
        }

        $stmt->bind_param("i", $student_id);
        $stmt->execute();
        $result = $stmt->get_result();

        $backlogs = [];
        while ($row = $result->fetch_assoc()) {
            if ((float) $row["grade_point"] <= 0) {
                $backlogs[] = [
                    "semester" => (int) $row["semester"],
                    "course_title" => $row["course_title"]
                ];
            }
        }

        $stmt->close();

        if (empty($backlogs)) {
            return "You do not have any current backlog. Your available result records show that you have passed all cleared semesters.";
        }

        $grouped = [];
        foreach ($backlogs as $backlog) {
            $semester = $backlog["semester"];
            if (!isset($grouped[$semester])) {
                $grouped[$semester] = [];
            }
            $grouped[$semester][] = $backlog["course_title"];
        }

        ksort($grouped);

        $parts = [];
        foreach ($grouped as $semester => $subjects) {
            $parts[] = "semester {$semester}: " . implode(", ", array_slice($subjects, 0, 4));
        }

        return "You currently have " . count($backlogs) . " backlog" . (count($backlogs) > 1 ? "s" : "") . ". Uncleared subjects are " . implode("; ", $parts) . ".";
    }

    public static function getHallTicketStatus($student_id, $message = "") {
        global $conn;

        $requestedExamType = self::extractExamType($message);

        if ($requestedExamType) {
            $stmt = $conn->prepare("
                SELECT exam_type, semester, academic_year, status, status_message
                FROM hall_tickets
                WHERE student_id = ?
                AND exam_type = ?
                ORDER BY hall_ticket_id DESC
                LIMIT 1
            ");

            if (!$stmt) {
                return "System error while checking hall ticket status.";
            }

            $stmt->bind_param("is", $student_id, $requestedExamType);
        } else {
            $stmt = $conn->prepare("
                SELECT exam_type, semester, academic_year, status, status_message
                FROM hall_tickets
                WHERE student_id = ?
                ORDER BY hall_ticket_id DESC
                LIMIT 1
            ");

            if (!$stmt) {
                return "System error while checking hall ticket status.";
            }

            $stmt->bind_param("i", $student_id);
        }

        $stmt->execute();
        $record = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$record) {
            return "I could not find any hall ticket status for your account right now.";
        }

        $examType = $record["exam_type"];
        $semester = $record["semester"];
        $academicYear = $record["academic_year"];
        $status = strtoupper(trim((string) $record["status"]));
        $statusMessage = trim((string) ($record["status_message"] ?? ""));

        if ($status === "GENERATED") {
            return "Your {$examType} hall ticket for semester {$semester} in {$academicYear} has been generated successfully. You can download it from the hall ticket section.";
        }

        if ($status === "PENDING") {
            return "Your {$examType} hall ticket for semester {$semester} in {$academicYear} is not generated yet. " . ($statusMessage !== "" ? $statusMessage : "Please check again later.");
        }

        if ($status === "NOT_APPROVED" || $status === "BLOCKED") {
            return "Your {$examType} hall ticket for semester {$semester} in {$academicYear} is not available right now. " . ($statusMessage !== "" ? $statusMessage : "Please contact your HOD or the exam section.");
        }

        return "I found a hall ticket record for your {$examType} exam, but the current status needs manual verification. Please contact the exam section.";
    }

    public static function getCourseDetails($student_id, $message = "") {
        global $conn;

        $student = self::getStudentAcademicContext($student_id);
        if (!$student) {
            return "I could not find your semester and branch details.";
        }

        $branch = $student["branch"];
        $semester = (int) $student["semester"];

        $stmt = $conn->prepare("
            SELECT course_code, course_title, course_type, credits
            FROM courses
            WHERE program = ? AND semester = ?
            ORDER BY course_type ASC, course_code ASC
        ");

        if (!$stmt) {
            return "System error while fetching course details.";
        }

        $stmt->bind_param("si", $branch, $semester);
        $stmt->execute();
        $result = $stmt->get_result();

        $courses = [];
        while ($row = $result->fetch_assoc()) {
            $courses[] = $row;
        }

        $stmt->close();

        if (empty($courses)) {
            return "I could not find any course details for your current semester.";
        }

        $normalizedMessage = strtolower(trim($message));
        foreach ($courses as $course) {
            $courseTitle = strtolower($course["course_title"]);
            $courseCode = strtolower($course["course_code"]);

            if (
                $normalizedMessage !== "" &&
                (strpos($normalizedMessage, $courseTitle) !== false || strpos($normalizedMessage, $courseCode) !== false)
            ) {
                $credits = rtrim(rtrim(number_format((float) $course["credits"], 1, ".", ""), "0"), ".");
                return "{$course["course_title"]} has course code {$course["course_code"]}. It is a {$course["course_type"]} course with {$credits} credit" . ($credits === "1" ? "" : "s") . " in semester {$semester}.";
            }
        }

        $courseLabels = array_map(function ($course) {
            return $course["course_title"] . " (" . $course["course_code"] . ")";
        }, $courses);

        $preview = implode(", ", array_slice($courseLabels, 0, 6));
        if (count($courseLabels) > 6) {
            $preview .= ", and " . (count($courseLabels) - 6) . " more";
        }

        return "In semester {$semester}, your subjects are {$preview}.";
    }


    /* ================= GET COURSE CODE ================= */

   public static function getCourseCode($message) {
    global $conn;

    $message = strtolower(trim($message));

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

        // Remove extra spaces for better matching
        $normalizedTitle = preg_replace('/\s+/', ' ', $title);
        $normalizedMessage = preg_replace('/\s+/', ' ', $message);

        if (strpos($normalizedMessage, $normalizedTitle) !== false) {
            $stmt->close();
            return "The course code of " . $row['course_title'] . " is " . $row['course_code'] . ".";
        }
    }

    $stmt->close();

    return "I could not find that course.";
}

    /* ================= SUBJECT-WISE ATTENDANCE ================= */

    public static function getSubjectAttendance($student_id, $message) {
        global $conn;

        $message = strtolower($message);

        $stmt = $conn->prepare("
            SELECT c.course_title, 
                   a.total_classes, 
                   a.attended_classes, 
                   a.percentage
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

                $percentage = round($row['percentage'], 2);

                $response = "Your attendance in " . $row['course_title'] .
                            " is $percentage percent. You attended " .
                            $row['attended_classes'] . " out of " .
                            $row['total_classes'] . " classes.";

                if ($percentage < 75) {
                    $response .= " Warning: Your attendance is below the required 75 percent.";
                }

                $stmt->close();
                return $response;
            }
        }

        $stmt->close();
        return "I could not find attendance for that subject.";
    }


    /* ================= OVERALL ATTENDANCE ================= */

    public static function getAttendance($student_id) {
        global $conn;

        $stmt = $conn->prepare("
            SELECT AVG(percentage) AS overall_percentage
            FROM attendance
            WHERE student_id = ?
        ");

        if (!$stmt) {
            return "System error while fetching attendance.";
        }

        $stmt->bind_param("i", $student_id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$result || !$result['overall_percentage']) {
            return "Attendance data not found.";
        }

        $overall = round($result['overall_percentage'], 2);

        return "Your overall attendance is $overall percent.";
    }
}
