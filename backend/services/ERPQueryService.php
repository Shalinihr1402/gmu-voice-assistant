<?php

require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../intents/controllers/StudentController.php";
require_once __DIR__ . "/../intents/controllers/FeeController.php";

class ERPQueryService {
    public static function detectIntent($query, $language = "en") {
        $text = self::normalizeText($query);
        if ($text === "") return "";

        if (self::isPaymentGrievanceResultQuery($text)) return "GET_PAYMENT_GRIEVANCE_RESULT";
        if (self::isPaymentGrievanceQuery($text)) return "GET_PAYMENT_GRIEVANCE";
        if (self::isGrievanceProcessQuery($text)) return "GET_GRIEVANCE_PROCESS";
        if (self::isFeeReceiptQuery($text)) return "GET_FEE_RECEIPT";
        if (self::isFeeDeadlineQuery($text)) return "GET_LAST_DATE_FEES";
        if (self::isFeePaymentNavigationQuery($text)) return "GET_FEE_PAYMENT_NAVIGATION";
        if (self::isFeeBalanceQuery($text)) return "GET_FEES_BALANCE";
        if (self::isFeeInfoQuery($text)) return "GET_FEE_INFO";
        if (self::hasAny($text, ["last working day", "last class day", "working day last", "last day of class", "classes end", "college last working", "kone working day", "last working dinanka"])) return "GET_LAST_WORKING_DAY";
        if (self::isInternalMarksQuery($text)) return "GET_INTERNAL_MARKS";
        if (self::isAssignmentQuery($text)) return "GET_ASSIGNMENTS";
        if (self::isExamTimetableQuery($text)) return "GET_EXAM_TIMETABLE";
        if (self::isTimetableQuery($text)) return "GET_TIMETABLE";
        if (self::hasAny($text, ["subject code", "subject codes", "course code", "course codes", "codes of subjects", "code for subject", "code of subject"])) return "GET_SUBJECT_CODES";
        if (self::hasAny($text, ["my subjects", "what are my subjects", "show my subjects", "registered subjects", "subject list", "my courses", "registered courses", "course list", "subjects yavuvu", "subjects kya", "subjects torisu"])) return "GET_SUBJECTS";
        if (self::isAcademicPerformanceQuery($text)) return "GET_ACADEMIC_PERFORMANCE_SUMMARY";
        if (self::hasAny($text, ["attendance", "attendence", "atendance", "attendance percentage", "overall attendance", "hajari", "hajarati"])) return "GET_ATTENDANCE";
        if (self::hasAny($text, ["usn", "u s n", "registration number", "university number", "my usn", "usn number"])) return "GET_USN";
        if (self::hasAny($text, ["result status", "result published", "result available", "result released", "marks status", "sgpa status", "latest result status"])) return "GET_RESULT_STATUS";
        if (self::hasAny($text, ["hall ticket", "hallticket", "admit card", "generate hall ticket", "hall ticket generated", "download hall ticket", "exam ticket"])) return "GET_HALLTICKET_STATUS";
        if (self::hasAny($text, ["certificate status", "competency certificate status", "digital competency certificate status", "digital competency status", "certificate issued", "certificate available", "certificate completed", "competency certificate", "digital competency certificate"])) return "GET_CERTIFICATE_STATUS";
        if (self::hasAny($text, ["final registration", "registration completed", "registration complete", "registration status", "registered or not", "am i registered", "have i registered", "course registration completed"])) return "GET_FINAL_REGISTRATION_STATUS";
        if (self::hasAny($text, ["faculty", "faculty details", "teacher details", "staff details", "professor", "hod details", "faculty contact", "teacher contact"])) return "GET_FACULTY_DETAILS";

        return "";
    }

    public static function handle($intent, $query, $language, $session) {
        $studentId = self::studentIdFromSession($session);
        error_log("ERP QUERY INTENT: {$intent}; student_id={$studentId}");

        if ($studentId <= 0 && self::requiresStudent($intent)) {
            return self::payload("This ERP detail is available only after student login.", $intent, $language, "student_guard");
        }

        switch ($intent) {
            case "GET_FEES_BALANCE":
                return self::payload(FeeController::answerFeeQuery($studentId, $query, $language, "balance"), $intent, $language, "fees_balance");
            case "GET_FEE_INFO":
                return self::payload(FeeController::answerFeeQuery($studentId, $query, $language, "fee_info"), $intent, $language, "fee_info");
            case "GET_FEE_PAYMENT_NAVIGATION":
                return self::payload(FeeController::answerFeeQuery($studentId, $query, $language, "payment_navigation"), $intent, $language, "payment_navigation");
            case "GET_FEE_RECEIPT":
                return self::payload(FeeController::answerFeeQuery($studentId, $query, $language, "receipt"), $intent, $language, "receipt");
            case "GET_TIMETABLE":
                return self::payload(self::getTimetable($studentId, $query, $language), $intent, $language, "timetable");
            case "GET_EXAM_TIMETABLE":
                return self::payload(self::getExamTimetable($studentId, $query, $language), $intent, $language, "exam_timetable");
            case "GET_INTERNAL_MARKS":
                return self::payload(self::getInternalMarks($studentId, $query, $language), $intent, $language, "internal_marks");
            case "GET_ASSIGNMENTS":
                return self::payload(self::getAssignments($studentId, $query, $language), $intent, $language, "assignments");
            case "GET_ATTENDANCE":
                return self::handleAttendanceQuery($studentId, $query, $language, $intent);
            case "GET_ACADEMIC_PERFORMANCE_SUMMARY":
                return self::payload(self::getAcademicPerformanceSummary($studentId, $language), $intent, $language, "academic_performance");
            case "GET_SUBJECTS":
                return self::payload(self::getSubjects($studentId, $query, $language), $intent, $language, "subjects");
            case "GET_SUBJECT_CODES":
                return self::payload(self::getSubjectCodes($studentId, $query, $language), $intent, $language, "subject_codes");
            case "GET_USN":
                return self::payload(StudentController::getUSN($studentId, $language), $intent, $language, "usn");
            case "GET_RESULT_STATUS":
                return self::payload(self::getResultStatus($studentId, $language), $intent, $language, "result_status");
            case "GET_HALLTICKET_STATUS":
            case "GET_HALL_TICKET_STATUS":
                return self::payload(StudentController::getHallTicketStatus($studentId, $query, $language), "GET_HALLTICKET_STATUS", $language, "hallticket_status");
            case "GET_CERTIFICATE_STATUS":
                return self::payload(StudentController::getCertificateStatus($studentId, $query, $language), $intent, $language, "certificate_status");
            case "GET_FINAL_REGISTRATION_STATUS":
                return self::payload(FeeController::getFinalRegistrationStatus($studentId, $language), $intent, $language, "final_registration");
            case "GET_FACULTY_DETAILS":
                return self::payload(self::getFacultyDetails($query, $language), $intent, $language, "faculty_details");
            case "GET_HOSTEL_FEES":
                return self::payload(FeeController::answerFeeQuery($studentId, $query, $language, "balance"), $intent, $language, "hostel_fees");
            case "GET_TUITION_FEES":
                return self::payload(FeeController::answerFeeQuery($studentId, $query, $language, "fee_info"), $intent, $language, "tuition_fees");
            case "GET_LAST_DATE_FEES":
                return self::payload(self::getFeeDeadline($language), $intent, $language, "fee_deadline");
            case "GET_LAST_WORKING_DAY":
                return self::payload(self::getLastWorkingDay($language), $intent, $language, "last_working_day");
            case "GET_GRIEVANCE_PROCESS":
                return self::payload(FeeController::answerFeeQuery($studentId, $query, $language, "grievance"), $intent, $language, "grievance_process");
            case "GET_PAYMENT_GRIEVANCE":
                return self::payload(FeeController::answerFeeQuery($studentId, $query, $language, "grievance"), $intent, $language, "payment_grievance");
            case "GET_PAYMENT_GRIEVANCE_RESULT":
                return self::payload(FeeController::answerFeeQuery($studentId, $query, $language, "grievance"), $intent, $language, "payment_grievance_result");
        }

        return null;
    }

    public static function getFeesBalance($studentId, $language = "en") {
        return FeeController::getFeeBalance($studentId, $language);
    }

    private static function handleAttendanceQuery($studentId, $query, $language, $intent) {
        $text = self::normalizeText($query);
        $hasSubjectPhrase = (bool) preg_match('/\b(attendance|attendence|atendance)\s+(in|of|for)\b|\b(dbms|database management|operating systems|computer networks|artificial intelligence|software engineering|java|data structures)\b/u', $text);
        if ($hasSubjectPhrase) {
            return self::payload(StudentController::getSubjectAttendance($studentId, $query, $language), $intent, $language, "attendance");
        }

        $chart = self::getAttendanceChart($studentId, $query, $language);
        if (is_array($chart)) {
            return self::payload($chart["reply"], $intent, $language, "attendance_chart", ["visual" => $chart["visual"]]);
        }

        return self::payload(StudentController::getAttendance($studentId, $language), $intent, $language, "attendance");
    }

    public static function getAttendance($studentId, $query = "", $language = "en") {
        $text = self::normalizeText($query);
        $hasSubjectPhrase = (bool) preg_match('/\b(attendance|attendence|atendance)\s+(in|of|for)\b|\b(dbms|database management|operating systems|computer networks|artificial intelligence|software engineering|java|data structures)\b/u', $text);
        if ($hasSubjectPhrase) {
            return StudentController::getSubjectAttendance($studentId, $query, $language);
        }
        return StudentController::getAttendance($studentId, $language);
    }

    private static function getAttendanceChart($studentId, $query = "", $language = "en") {
        global $conn;
        $student = self::getStudent($studentId);
        if (!$student) return null;

        $requestedSemester = null;
        $text = self::normalizeText($query);
        if (preg_match('/\b(?:semester|sem)\s*(\d{1,2})\b/u', $text, $matches)) {
            $requestedSemester = (int) $matches[1];
        } else {
            $words = ["first" => 1, "second" => 2, "third" => 3, "fourth" => 4, "fifth" => 5, "sixth" => 6, "seventh" => 7, "eighth" => 8, "1st" => 1, "2nd" => 2, "3rd" => 3, "4th" => 4, "5th" => 5, "6th" => 6, "7th" => 7, "8th" => 8];
            foreach ($words as $word => $sem) {
                if (strpos($text, $word . " semester") !== false || strpos($text, $word . " sem") !== false) {
                    $requestedSemester = $sem;
                    break;
                }
            }
        }

        $semester = (int) ($requestedSemester ?: ($student["semester"] ?? 0));
        $branch = (string) ($student["branch"] ?? "");
        if ($semester <= 0 || $branch === "") return null;

        $stmt = $conn->prepare("
            SELECT c.course_code, c.course_title, c.course_type, a.total_classes, a.attended_classes, a.percentage
            FROM attendance a
            INNER JOIN courses c ON a.course_id = c.course_id
            WHERE a.student_id = ?
              AND c.program = ?
              AND c.semester = ?
            ORDER BY c.course_code ASC
        ");
        if (!$stmt) return null;
        $stmt->bind_param("isi", $studentId, $branch, $semester);
        $stmt->execute();
        $result = $stmt->get_result();

        $subjects = [];
        $totalClasses = 0;
        $attendedClasses = 0;
        $belowThreshold = 0;
        while ($row = $result->fetch_assoc()) {
            $total = (int) $row["total_classes"];
            $attended = (int) $row["attended_classes"];
            $percentage = round((float) $row["percentage"], 2);
            $subjects[] = [
                "course_code" => (string) $row["course_code"],
                "course_title" => (string) $row["course_title"],
                "course_type" => (string) ($row["course_type"] ?? ""),
                "total_classes" => $total,
                "attended_classes" => $attended,
                "percentage" => $percentage,
                "status" => $percentage >= 75 ? "SAFE" : "LOW"
            ];
            $totalClasses += $total;
            $attendedClasses += $attended;
            if ($percentage < 75) $belowThreshold++;
        }
        $stmt->close();
        if (empty($subjects)) return null;

        $overall = $totalClasses > 0 ? round(($attendedClasses / $totalClasses) * 100, 2) : 0.0;
        $reply = self::attendanceChartReply($overall, $semester, count($subjects), $belowThreshold, $language);

        return [
            "reply" => $reply,
            "visual" => [
                "type" => "attendance_chart",
                "title" => "Subject-wise Attendance",
                "semester" => $semester,
                "student" => [
                    "full_name" => (string) ($student["full_name"] ?? ""),
                    "usn" => (string) ($student["usn"] ?? ""),
                    "branch" => $branch
                ],
                "summary" => [
                    "overall_percentage" => $overall,
                    "total_classes" => $totalClasses,
                    "attended_classes" => $attendedClasses,
                    "subject_count" => count($subjects),
                    "below_threshold_count" => $belowThreshold
                ],
                "subjects" => $subjects
            ]
        ];
    }

    private static function attendanceChartReply($overall, $semester, $subjectCount, $belowThreshold, $language) {
        $overallText = rtrim(rtrim(number_format((float) $overall, 2, '.', ''), '0'), '.');
        if ($language === "hi") {
            return "Aapka semester {$semester} subject-wise attendance chart yahin dikha raha hoon. Overall attendance {$overallText} percent hai. {$subjectCount} subjects track ho rahe hain" . ($belowThreshold > 0 ? ", aur {$belowThreshold} subject 75 percent se below hai." : ", aur sab subjects safe range mein hain.");
        }
        if ($language === "kn") {
            return "Nimma semester {$semester} subject-wise attendance chart illi torisuttiddene. Overall attendance {$overallText} percent ide. {$subjectCount} subjects track aguttive" . ($belowThreshold > 0 ? ", mattu {$belowThreshold} subject 75 percent ginta kadime ide." : ", ella subjects safe range alli ide.");
        }
        return "Here is your semester {$semester} subject-wise attendance chart. Your overall attendance is {$overallText} percent across {$subjectCount} subjects" . ($belowThreshold > 0 ? ", with {$belowThreshold} subject below 75 percent." : ", and all subjects are in the safe range.");
    }

    public static function getSubjects($studentId, $query = "", $language = "en") {
        return StudentController::getCourseDetails($studentId, $query !== "" ? $query : "my subjects", $language);
    }

    public static function getSubjectCodes($studentId, $query = "", $language = "en") {
        global $conn;
        $student = self::getStudent($studentId);
        if (!$student) return self::localize("I could not find your student details right now.", $language);

        $semester = (int) ($student["semester"] ?? 0);
        $branch = (string) ($student["branch"] ?? "");
        $stmt = $conn->prepare("SELECT course_code, course_title FROM courses WHERE semester = ? AND program = ? ORDER BY course_code");
        if (!$stmt) return self::localize("System error while fetching subject codes.", $language);
        $stmt->bind_param("is", $semester, $branch);
        $stmt->execute();
        $result = $stmt->get_result();
        $items = [];
        while ($row = $result->fetch_assoc()) {
            $items[] = $row["course_title"] . " is " . $row["course_code"];
        }
        $stmt->close();

        if (empty($items)) return self::localize("I could not find subject codes for your current semester.", $language);
        $preview = implode("; ", array_slice($items, 0, 6));
        return self::localize("Your current semester subject codes are: " . $preview . ".", $language);
    }

    public static function getTimetable($studentId, $query = "", $language = "en") {
        global $conn;
        $student = self::getStudent($studentId);
        if (!$student) return self::studentDataMissingReply($language);
        if (!self::tableHasColumns("class_timetable", ["program", "semester", "day_of_week", "start_time", "end_time", "course_id"])) {
            return self::erpDataNotUpdatedReply("class timetable", $language);
        }

        $day = self::requestedDayName($query);
        $program = (string) ($student["branch"] ?? "");
        $semester = (int) ($student["semester"] ?? 0);
        $stmt = $conn->prepare("
            SELECT t.start_time, t.end_time, t.room_no, t.faculty_name, c.course_code, c.course_title
            FROM class_timetable t
            LEFT JOIN courses c ON c.course_id = t.course_id
            WHERE t.program = ? AND t.semester = ? AND LOWER(t.day_of_week) = LOWER(?)
            ORDER BY t.start_time ASC
            LIMIT 8
        ");
        if (!$stmt) return self::erpTechnicalReply("class timetable", $language);
        $stmt->bind_param("sis", $program, $semester, $day);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        if (empty($rows)) return self::noErpRecordsReply("class timetable for {$day}", $language);

        $items = [];
        foreach ($rows as $row) {
            $subject = trim((string) (($row["course_code"] ?? "") . " " . ($row["course_title"] ?? ""))) ?: "class";
            $time = self::shortTime($row["start_time"] ?? "") . " to " . self::shortTime($row["end_time"] ?? "");
            $room = trim((string) ($row["room_no"] ?? ""));
            $faculty = trim((string) ($row["faculty_name"] ?? ""));
            $items[] = trim($time . ", " . $subject . ($room !== "" ? ", room " . $room : "") . ($faculty !== "" ? ", by " . $faculty : ""));
        }
        return self::localizedPrefix("Your {$day} timetable is: ", $language) . implode("; ", $items) . ".";
    }

    public static function getExamTimetable($studentId, $query = "", $language = "en") {
        global $conn;
        $student = self::getStudent($studentId);
        if (!$student) return self::studentDataMissingReply($language);
        if (!self::tableHasColumns("exam_timetable", ["program", "semester", "course_id", "exam_date", "start_time", "end_time"])) {
            return self::erpDataNotUpdatedReply("exam timetable", $language);
        }

        $program = (string) ($student["branch"] ?? "");
        $semester = (int) ($student["semester"] ?? 0);
        $stmt = $conn->prepare("
            SELECT e.exam_date, e.start_time, e.end_time, e.exam_type, e.venue, c.course_code, c.course_title
            FROM exam_timetable e
            LEFT JOIN courses c ON c.course_id = e.course_id
            WHERE e.program = ? AND e.semester = ? AND e.exam_date >= CURDATE()
            ORDER BY e.exam_date ASC, e.start_time ASC
            LIMIT 6
        ");
        if (!$stmt) return self::erpTechnicalReply("exam timetable", $language);
        $stmt->bind_param("si", $program, $semester);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        if (empty($rows)) return self::noErpRecordsReply("upcoming exam timetable", $language);

        $items = [];
        foreach ($rows as $row) {
            $subject = trim((string) (($row["course_code"] ?? "") . " " . ($row["course_title"] ?? ""))) ?: "exam";
            $date = self::spokenDate((string) ($row["exam_date"] ?? ""));
            $time = self::shortTime($row["start_time"] ?? "") . " to " . self::shortTime($row["end_time"] ?? "");
            $venue = trim((string) ($row["venue"] ?? ""));
            $type = trim((string) ($row["exam_type"] ?? ""));
            $items[] = trim($subject . " on " . $date . ", " . $time . ($type !== "" ? ", " . strtoupper($type) : "") . ($venue !== "" ? ", venue " . $venue : ""));
        }
        return self::localizedPrefix("Your upcoming exam timetable is: ", $language) . implode("; ", $items) . ".";
    }

    public static function getInternalMarks($studentId, $query = "", $language = "en") {
        global $conn;
        if (!self::tableHasColumns("internal_marks", ["student_id", "course_id", "component", "marks_obtained", "max_marks"])) {
            return self::erpDataNotUpdatedReply("internal marks", $language);
        }

        $stmt = $conn->prepare("
            SELECT c.course_code, c.course_title, im.component, im.marks_obtained, im.max_marks
            FROM internal_marks im
            LEFT JOIN courses c ON c.course_id = im.course_id
            WHERE im.student_id = ?
            ORDER BY c.semester DESC, c.course_code ASC, im.component ASC
            LIMIT 10
        ");
        if (!$stmt) return self::erpTechnicalReply("internal marks", $language);
        $stmt->bind_param("i", $studentId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        if (empty($rows)) return self::noErpRecordsReply("internal marks", $language);

        $items = [];
        foreach ($rows as $row) {
            $subject = trim((string) (($row["course_code"] ?? "") . " " . ($row["course_title"] ?? ""))) ?: "subject";
            $component = trim((string) ($row["component"] ?? "internal"));
            $items[] = $subject . " " . $component . ": " . self::formatDecimal($row["marks_obtained"] ?? 0) . " out of " . self::formatDecimal($row["max_marks"] ?? 0);
        }
        return self::localizedPrefix("Your internal marks are: ", $language) . implode("; ", $items) . ".";
    }

    public static function getAssignments($studentId, $query = "", $language = "en") {
        global $conn;
        $student = self::getStudent($studentId);
        if (!$student) return self::studentDataMissingReply($language);
        if (!self::tableHasColumns("assignments", ["program", "semester", "course_id", "title", "due_date", "status"])) {
            return self::erpDataNotUpdatedReply("assignments", $language);
        }

        $program = (string) ($student["branch"] ?? "");
        $semester = (int) ($student["semester"] ?? 0);
        $stmt = $conn->prepare("
            SELECT a.title, a.due_date, a.status, c.course_code, c.course_title
            FROM assignments a
            LEFT JOIN courses c ON c.course_id = a.course_id
            WHERE a.program = ? AND a.semester = ? AND (a.due_date >= CURDATE() OR LOWER(a.status) IN ('pending', 'open'))
            ORDER BY a.due_date ASC
            LIMIT 8
        ");
        if (!$stmt) return self::erpTechnicalReply("assignments", $language);
        $stmt->bind_param("si", $program, $semester);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        if (empty($rows)) return self::noErpRecordsReply("pending assignments", $language);

        $items = [];
        foreach ($rows as $row) {
            $subject = trim((string) (($row["course_code"] ?? "") . " " . ($row["course_title"] ?? ""))) ?: "subject";
            $title = trim((string) ($row["title"] ?? "assignment"));
            $date = self::spokenDate((string) ($row["due_date"] ?? ""));
            $status = trim((string) ($row["status"] ?? "pending"));
            $items[] = $subject . " - " . $title . ", due on " . $date . ", status " . $status;
        }
        return self::localizedPrefix("Your pending assignments are: ", $language) . implode("; ", $items) . ".";
    }
    public static function getAcademicPerformanceSummary($studentId, $language = "en") {
        $student = self::getStudent($studentId);
        if (!$student) return self::academicTechnicalReply($language);

        $latest = self::getLatestSemesterResultSummary($studentId);
        $overall = self::getOverallResultSummary($studentId);
        $attendance = self::getOverallAttendanceSummary($studentId);
        $allBacklogs = self::getAllBacklogs($studentId);

        if (!$latest && !$overall && !$attendance) {
            if ($language === "hi") return "ERP records mein abhi aapka academic performance calculate karne ke liye result aur attendance data available nahi hai. Data update hone ke baad please dobara check kijiye.";
            if ($language === "kn") return "ERP records nalli nimma academic performance calculate madalu result mattu attendance data ivaga available illa. Data update aadamele dayavittu mathe check maadi.";
            return "I could not find enough ERP records to calculate your overall academic performance. Please check again after your result and attendance data are updated.";
        }

        $parts = [];
        if ($overall) {
            $parts[] = "overall CGPA is " . self::formatDecimal($overall["cgpa"]);
        } else {
            $parts[] = "overall CGPA is not available yet";
        }

        if ($latest) {
            $parts[] = "latest semester " . $latest["semester"] . " result status is " . $latest["status"] . " with SGPA " . self::formatDecimal($latest["sgpa"]);
        } else {
            $parts[] = "latest semester result is not available yet";
        }

        if (!empty($allBacklogs)) {
            $backlogNames = implode(", ", array_slice($allBacklogs, 0, 4));
            $extra = count($allBacklogs) > 4 ? " and " . (count($allBacklogs) - 4) . " more" : "";
            $parts[] = "backlog status: active backlog in {$backlogNames}{$extra}";
        } else if ($overall || $latest) {
            $parts[] = "backlog status: no active backlogs found in ERP records";
        }

        if ($attendance) {
            $parts[] = "overall attendance is " . self::formatDecimal($attendance["percentage"]) . " percent";
        } else {
            $parts[] = "overall attendance is not available yet";
        }

        $feedback = self::academicPerformanceFeedback($overall["cgpa"] ?? null, $attendance["percentage"] ?? null, count($allBacklogs), $language);

        if ($language === "hi") {
            return "Aapka overall academic performance summary: " . implode(". ", $parts) . ". Feedback: {$feedback}";
        }
        if ($language === "kn") {
            return "Nimma overall academic performance summary: " . implode(". ", $parts) . ". Feedback: {$feedback}";
        }
        return "Your overall academic performance summary is: " . implode(". ", $parts) . ". Performance feedback: {$feedback}";
    }

    private static function getLatestSemesterResultSummary($studentId) {
        global $conn;
        $semester = 0;
        $stmt = $conn->prepare("SELECT semester FROM result_publications WHERE student_id = ? AND publication_status = 'PUBLISHED' ORDER BY published_at DESC, publication_id DESC LIMIT 1");
        if ($stmt) {
            $stmt->bind_param("i", $studentId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $semester = (int) ($row["semester"] ?? 0);
        }

        if ($semester <= 0) {
            $stmt = $conn->prepare("SELECT MAX(semester) AS semester FROM results WHERE student_id = ?");
            if (!$stmt) return null;
            $stmt->bind_param("i", $studentId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $semester = (int) ($row["semester"] ?? 0);
        }
        if ($semester <= 0) return null;

        $stmt = $conn->prepare("
            SELECT c.course_title, r.credits, r.grade_point
            FROM results r
            INNER JOIN courses c ON c.course_id = r.course_id
            WHERE r.student_id = ? AND r.semester = ?
            ORDER BY c.course_code
        ");
        if (!$stmt) return null;
        $stmt->bind_param("ii", $studentId, $semester);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        if (empty($rows)) return null;

        $weighted = 0.0;
        $credits = 0.0;
        $backlogs = [];
        foreach ($rows as $row) {
            $credit = (float) ($row["credits"] ?? 0);
            $gradePoint = (float) ($row["grade_point"] ?? 0);
            $weighted += $credit * $gradePoint;
            $credits += $credit;
            if ($gradePoint <= 0) $backlogs[] = (string) ($row["course_title"] ?? "subject");
        }

        return [
            "semester" => $semester,
            "sgpa" => $credits > 0 ? round($weighted / $credits, 2) : 0.0,
            "status" => empty($backlogs) ? "PASS" : "FAIL",
            "backlogs" => $backlogs
        ];
    }

    private static function getOverallResultSummary($studentId) {
        global $conn;
        $stmt = $conn->prepare("SELECT SUM(credits * grade_point) AS weighted, SUM(credits) AS credits FROM results WHERE student_id = ?");
        if (!$stmt) return null;
        $stmt->bind_param("i", $studentId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $credits = (float) ($row["credits"] ?? 0);
        if ($credits <= 0) return null;
        return ["cgpa" => round(((float) ($row["weighted"] ?? 0)) / $credits, 2)];
    }

    private static function getOverallAttendanceSummary($studentId) {
        global $conn;
        $stmt = $conn->prepare("SELECT SUM(attended_classes) AS attended, SUM(total_classes) AS total FROM attendance WHERE student_id = ?");
        if (!$stmt) return null;
        $stmt->bind_param("i", $studentId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $total = (int) ($row["total"] ?? 0);
        if ($total <= 0) return null;
        return [
            "percentage" => round(((int) ($row["attended"] ?? 0) / $total) * 100, 2),
            "attended" => (int) ($row["attended"] ?? 0),
            "total" => $total
        ];
    }

    private static function getAllBacklogs($studentId) {
        global $conn;
        $stmt = $conn->prepare("
            SELECT r.semester, c.course_title
            FROM results r
            INNER JOIN courses c ON c.course_id = r.course_id
            WHERE r.student_id = ? AND r.grade_point <= 0
            ORDER BY r.semester, c.course_code
        ");
        if (!$stmt) return [];
        $stmt->bind_param("i", $studentId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        $items = [];
        foreach ($rows as $row) {
            $items[] = "semester " . (int) ($row["semester"] ?? 0) . " " . (string) ($row["course_title"] ?? "subject");
        }
        return $items;
    }

    private static function academicPerformanceFeedback($cgpa, $attendance, $backlogCount, $language) {
        $cgpaValue = $cgpa === null ? null : (float) $cgpa;
        $attendanceValue = $attendance === null ? null : (float) $attendance;

        if ($backlogCount > 0 || ($attendanceValue !== null && $attendanceValue < 75) || ($cgpaValue !== null && $cgpaValue < 6)) {
            if ($language === "hi") return "Aapko academics par serious focus karna chahiye. Attendance aur backlog clearance ko priority dijiye.";
            if ($language === "kn") return "Nimma academics mele hecchu focus madabeku. Attendance mattu backlog clearance ge priority kodi.";
            return "Your academic performance needs improvement. Please focus on attendance and backlog clearance first.";
        }

        if (($cgpaValue !== null && $cgpaValue >= 8) && ($attendanceValue === null || $attendanceValue >= 75)) {
            if ($language === "hi") return "Aapka performance strong hai. Isi consistency ko maintain kijiye.";
            if ($language === "kn") return "Nimma performance strong ide. Ee consistency maintain maadi.";
            return "Your performance is strong. Keep maintaining this consistency.";
        }

        if (($cgpaValue !== null && $cgpaValue >= 6) && ($attendanceValue === null || $attendanceValue >= 75)) {
            if ($language === "hi") return "Aapka performance good hai. Thoda aur consistency rakhne se result aur improve hoga.";
            if ($language === "kn") return "Nimma performance good ide. Innu swalpa consistency iddare result improve agutte.";
            return "Your performance is good. A little more consistency can improve it further.";
        }

        if ($language === "hi") return "Available ERP data ke hisaab se aapka performance satisfactory hai.";
        if ($language === "kn") return "Available ERP data prakara nimma performance satisfactory ide.";
        return "Based on the available ERP data, your performance is satisfactory.";
    }

    private static function academicTechnicalReply($language) {
        if ($language === "hi") return "Academic performance check karte waqt technical issue aaya. Kripya thodi der baad try kijiye.";
        if ($language === "kn") return "Academic performance check maduvaga technical issue aayitu. Dayavittu swalpa samayada nantara try maadi.";
        return "I could not check your academic performance right now due to a technical issue. Please try again after some time.";
    }

    private static function formatDecimal($value) {
        return rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');
    }
    public static function getFacultyDetails($query = "", $language = "en") {
        global $conn;
        $text = self::normalizeText($query);
        $requestedName = self::extractFacultyName($text);

        if ($requestedName !== "") {
            $like = "%" . $requestedName . "%";
            $stmt = $conn->prepare("
                SELECT sm.full_name, sm.designation, sm.email, sm.mobile_no, d.department_name
                FROM staff_members sm
                LEFT JOIN departments d ON d.department_id = sm.department_id
                WHERE LOWER(sm.full_name) LIKE LOWER(?)
                ORDER BY sm.full_name
                LIMIT 3
            ");
            if (!$stmt) return self::facultyTechnicalReply($language);
            $stmt->bind_param("s", $like);
            $stmt->execute();
            $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();

            if (empty($rows)) {
                return self::facultyMissingReply($requestedName, $language);
            }

            return self::facultyRowsReply($rows, $language, true);
        }

        $stmt = $conn->prepare("
            SELECT sm.full_name, sm.designation, sm.email, sm.mobile_no, d.department_name
            FROM staff_members sm
            LEFT JOIN departments d ON d.department_id = sm.department_id
            ORDER BY d.department_name, sm.full_name
            LIMIT 6
        ");
        if (!$stmt) return self::facultyTechnicalReply($language);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        if (empty($rows)) {
            if ($language === "hi") return "ERP records mein abhi faculty details available nahi hain. Kripya department office se confirm kijiye.";
            if ($language === "kn") return "ERP records nalli ivaga faculty details available illa. Dayavittu department office nalli confirm maadi.";
            return "Faculty details are not available in the ERP records right now. Please confirm with the department office.";
        }

        return self::facultyRowsReply($rows, $language, false);
    }

    private static function extractFacultyName($text) {
        $text = trim((string) $text);
        $patterns = [
            '/\b(?:faculty|teacher|professor|prof|dr|sir|madam)\s+(?:details|contact|email|phone|number)?\s*(?:of|for|about)?\s*([a-z][a-z\s.]{2,})$/u',
            '/\b(?:details|contact|email|phone|number)\s+(?:of|for|about)\s+([a-z][a-z\s.]{2,})$/u',
            '/\b(?:who is|tell me about)\s+(?:professor|prof|dr)?\s*([a-z][a-z\s.]{2,})$/u'
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $matches)) {
                $name = trim(preg_replace('/\b(details|contact|email|phone|number|faculty|teacher|professor|prof|dr|sir|madam)\b/u', ' ', $matches[1]));
                $name = trim(preg_replace('/\s+/u', ' ', $name));
                if ($name !== "") return $name;
            }
        }
        return "";
    }

    private static function facultyRowsReply($rows, $language, $specific) {
        $parts = [];
        foreach ($rows as $row) {
            $name = trim((string) ($row["full_name"] ?? ""));
            $designation = trim((string) ($row["designation"] ?? ""));
            $department = trim((string) ($row["department_name"] ?? ""));
            $email = trim((string) ($row["email"] ?? ""));
            $phone = trim((string) ($row["mobile_no"] ?? ""));
            if ($name === "") continue;
            $line = $name;
            if ($designation !== "") $line .= ", " . $designation;
            if ($department !== "") $line .= ", " . $department;
            if ($email !== "") $line .= ", email " . $email;
            if ($phone !== "") $line .= ", phone " . $phone;
            $parts[] = $line;
        }

        if (empty($parts)) return self::facultyTechnicalReply($language);
        $details = implode("; ", $parts);

        if ($language === "hi") {
            return $specific ? "ERP record ke hisaab se faculty details: {$details}." : "ERP records mein available faculty details: {$details}.";
        }
        if ($language === "kn") {
            return $specific ? "ERP record prakara faculty details: {$details}." : "ERP records nalli available iruva faculty details: {$details}.";
        }
        return $specific ? "According to ERP records, the faculty details are: {$details}." : "Available faculty details in ERP records are: {$details}.";
    }

    private static function facultyMissingReply($name, $language) {
        $name = trim((string) $name);
        if ($language === "hi") return "ERP records mein " . ($name !== "" ? $name . " ke " : "is faculty ke ") . "faculty details available nahi hain. Kripya spelling check kijiye ya department office se confirm kijiye.";
        if ($language === "kn") return "ERP records nalli " . ($name !== "" ? $name . " avara " : "ee faculty ya ") . "details available illa. Dayavittu spelling check maadi athava department office nalli confirm maadi.";
        return "I could not find " . ($name !== "" ? $name . "'s " : "those ") . "faculty details in the ERP records. Please check the spelling or confirm with the department office.";
    }

    private static function facultyTechnicalReply($language) {
        if ($language === "hi") return "Faculty details check karte waqt technical issue aaya. Kripya thodi der baad try kijiye.";
        if ($language === "kn") return "Faculty details check maduvaga technical issue aayitu. Dayavittu swalpa samayada nantara try maadi.";
        return "I could not check faculty details right now due to a technical issue. Please try again after some time.";
    }
    public static function getResultStatus($studentId, $language = "en") {
        global $conn;
        $stmt = $conn->prepare("SELECT semester, exam_type, academic_year, season, publication_status, published_at FROM result_publications WHERE student_id = ? ORDER BY published_at DESC, publication_id DESC LIMIT 1");
        if (!$stmt) return self::localize("System error while checking result status.", $language);
        $stmt->bind_param("i", $studentId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) return self::localize("I could not find any published result for your account right now.", $language);

        $semester = (int) ($row["semester"] ?? 0);
        $exam = strtoupper((string) ($row["exam_type"] ?? "exam"));
        $status = strtolower((string) ($row["publication_status"] ?? "published"));
        return self::localize("Your semester {$semester} {$exam} result status is {$status}.", $language);
    }

    public static function getHostelFees($studentId, $language = "en") {
        global $conn;
        $stmt = $conn->prepare("SELECT fs.total_fee, IFNULL(SUM(sp.amount_paid), 0) AS paid FROM fee_structure fs LEFT JOIN student_payments sp ON sp.fee_id = fs.fee_id AND sp.student_id = ? WHERE fs.fee_type LIKE '%hostel%' GROUP BY fs.fee_id, fs.total_fee LIMIT 1");
        if (!$stmt) return self::localize("System error while checking hostel fees.", $language);
        $stmt->bind_param("i", $studentId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) return self::localize("I could not find hostel fee details for your account right now.", $language);
        $balance = max(0, (float) $row["total_fee"] - (float) $row["paid"]);
        return self::localize("Your hostel fee balance is Rs. " . number_format($balance, 2) . ".", $language);
    }

    public static function getTuitionFees($studentId, $language = "en") {
        global $conn;
        $student = self::getStudent($studentId);
        if (!$student) return self::localize("I could not find your student details right now.", $language);
        $quota = (string) ($student["quota"] ?? "");
        $stmt = $conn->prepare("SELECT fee_type, total_fee FROM fee_structure WHERE quota = ? AND (fee_type LIKE '%program%' OR fee_type LIKE '%tuition%' OR fee_type LIKE '%academic%') ORDER BY fee_id LIMIT 1");
        if (!$stmt) return self::localize("System error while checking tuition fees.", $language);
        $stmt->bind_param("s", $quota);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) return self::localize("I could not find tuition fee details for your quota right now.", $language);
        return self::localize("Your " . $row["fee_type"] . " is Rs. " . number_format((float) $row["total_fee"], 2) . ".", $language);
    }

    public static function getFeeDeadline($language = "en") {
        global $conn;
        $stmt = $conn->prepare("SELECT title, due_date, description FROM erp_deadlines WHERE is_active = 1 AND (category IN ('tuition_fee', 'tuition', 'fees', 'fee') OR title LIKE '%fee%') ORDER BY due_date ASC LIMIT 1");
        if (!$stmt) return self::localize("System error while checking fee deadline.", $language);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) return self::localize("The fee payment last date is not updated in ERP right now.", $language);
        $date = self::spokenDate((string) $row["due_date"]);
        $extra = trim((string) ($row["description"] ?? ""));
        return self::localize("The last date to pay fees is {$date}." . ($extra !== "" ? " {$extra}" : ""), $language);
    }

    public static function getLastWorkingDay($language = "en") {
        global $conn;
        $stmt = $conn->prepare("SELECT title, due_date, description FROM erp_deadlines WHERE is_active = 1 AND (category IN ('last_working_day', 'academic') OR title LIKE '%last working%') ORDER BY due_date ASC LIMIT 1");
        if (!$stmt) return self::localize("System error while checking last working day.", $language);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) return self::localize("The last working day is not updated in ERP right now.", $language);
        return self::localize("The last working day is " . self::spokenDate((string) $row["due_date"]) . ".", $language);
    }

    public static function grievanceProcessReply($language = "en") {
        return self::localize("To apply for a grievance, open the Registration page, go to Payment Details, choose Payment Grievance, fill the issue details, attach proof if available, and submit.", $language);
    }

    public static function paymentGrievanceReply($language = "en") {
        return self::localize("For a payment grievance, open Registration page, click Payment, then choose Payment Grievance. Enter your USN, phone number, amount, transaction date, issue details, and submit.", $language);
    }

    private static function payload($reply, $intent, $language, $source, $extra = []) {
        error_log("ERP QUERY RESPONSE: intent={$intent}; source={$source}");
        $payload = [
            "reply" => trim((string) $reply),
            "intent" => $intent,
            "route" => "database",
            "language" => $language,
            "client_action" => null,
            "suggestion" => null,
            "quick_actions" => [],
            "debug" => [
                "source" => "erp_query_service",
                "reply_source" => $source
            ]
        ];

        foreach ($extra as $key => $value) {
            $payload[$key] = $value;
        }

        return $payload;
    }

    private static function requiresStudent($intent) {
        return !in_array($intent, ["GET_LAST_DATE_FEES", "GET_LAST_WORKING_DAY", "GET_GRIEVANCE_PROCESS", "GET_PAYMENT_GRIEVANCE", "GET_PAYMENT_GRIEVANCE_RESULT", "GET_FEE_PAYMENT_NAVIGATION", "GET_FEE_RECEIPT"], true);
    }

    private static function studentIdFromSession($session) {
        $studentId = (int) ($session["student_id"] ?? 0);
        if ($studentId > 0) return $studentId;
        $userId = (int) ($session["user_id"] ?? 0);
        if ($userId <= 0) return 0;
        global $conn;
        $stmt = $conn->prepare("SELECT student_id FROM users WHERE user_id = ? AND is_active = 1 LIMIT 1");
        if (!$stmt) return 0;
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return (int) ($row["student_id"] ?? 0);
    }

    private static function getStudent($studentId) {
        global $conn;
        $stmt = $conn->prepare("SELECT student_id, usn, full_name, branch, semester, quota FROM students WHERE student_id = ? LIMIT 1");
        if (!$stmt) return null;
        $stmt->bind_param("i", $studentId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }


    private static function isFeeReceiptQuery($text) {
        return self::hasAny($text, ["receipt", "invoice", "download receipt", "payment receipt", "fee receipt"]);
    }

    private static function isFeePaymentNavigationQuery($text) {
        return self::hasAny($text, ["where can i pay", "how to pay", "pay my fees", "pay fees", "payment portal", "fee payment", "erp fee payment", "where is tuition fee payment", "how to pay hostel fees", "how to pay balance fees", "pay skill assessment", "late registration payment", "certificate fee payment", "other fee payment"])
            || (self::hasAny($text, ["where", "how", "pay", "payment"]) && self::hasAny($text, ["fee", "fees", "tuition", "hostel", "skill", "balance", "late registration", "certificate", "bonafide", "study certificate", "bank estimate", "photo copy", "photocopy", "breakage", "byoc", "malpractice", "pg application", "phd application", "admission"]));
    }

    private static function isFeeBalanceQuery($text) {
        return self::hasAny($text, ["fee balance", "fees balance", "remaining fees", "remaining fee", "pending fee", "pending fees", "due amount", "unpaid fees", "balance fee", "balance fees", "fee due", "fees due", "hostel fee balance", "tuition fee pending", "skill fee due", "baki fee", "bakki fees", "feesu balance"])
            || (self::hasAny($text, ["balance", "pending", "remaining", "due", "unpaid", "baki", "bakki"]) && self::hasAny($text, ["fee", "fees", "tuition", "hostel", "skill", "amount"]));
    }

    private static function isFeeInfoQuery($text) {
        return self::hasAny($text, ["what is hostel fee", "hostel fee amount", "tuition fee amount", "skill fee details", "fee structure", "fee details", "complete fee details", "full fee summary", "all fees", "what are my fees", "what is my fee", "what is my fees"]);
    }
    private static function isFeeDeadlineQuery($text) {
        return self::hasAny($text, ["last date", "deadline", "due date", "when pay", "pay by", "kab tak", "akhri tarikh", "last dinanka"]) && self::hasAny($text, ["fee", "fees", "tuition", "payment", "shulk", "feesu"]);
    }

    private static function isTimetableQuery($text) {
        return self::hasAny($text, ["today timetable", "today's timetable", "class timetable", "my timetable", "timetable", "next class", "tomorrow schedule", "today schedule", "class schedule", "schedule today", "next period"]);
    }

    private static function isExamTimetableQuery($text) {
        return self::hasAny($text, ["exam timetable", "exam schedule", "exam date", "when is my exam", "when is dbms exam", "show exam schedule", "see exam timetable", "cie timetable", "see timetable"])
            || (self::hasAny($text, ["exam", "cie", "see"]) && self::hasAny($text, ["when", "date", "schedule", "timetable", "time", "dbms", "subject"]));
    }

    private static function isInternalMarksQuery($text) {
        return self::hasAny($text, ["internal marks", "cia marks", "cie marks", "assignment marks", "internal score", "show my internal", "my internal marks", "test marks", "assessment marks"]);
    }

    private static function isAssignmentQuery($text) {
        return self::hasAny($text, ["pending assignments", "assignment deadline", "assignments", "my assignments", "any assignment", "assignment due", "submission date", "homework", "assignment status"]);
    }

    private static function tableHasColumns($table, $columns) {
        global $conn;
        $table = (string) $table;
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) return false;
        $stmt = $conn->prepare("SHOW TABLES LIKE ?");
        if (!$stmt) return false;
        $stmt->bind_param("s", $table);
        $stmt->execute();
        $exists = (bool) $stmt->get_result()->fetch_row();
        $stmt->close();
        if (!$exists) return false;

        $result = $conn->query("SHOW COLUMNS FROM `{$table}`");
        if (!$result) return false;
        $available = [];
        while ($row = $result->fetch_assoc()) {
            $available[strtolower((string) ($row["Field"] ?? ""))] = true;
        }
        foreach ($columns as $column) {
            if (empty($available[strtolower((string) $column)])) return false;
        }
        return true;
    }

    private static function requestedDayName($query) {
        $text = self::normalizeText($query);
        if (strpos($text, "tomorrow") !== false) return strtolower(date('l', strtotime('+1 day')));
        foreach (["monday", "tuesday", "wednesday", "thursday", "friday", "saturday", "sunday"] as $day) {
            if (strpos($text, $day) !== false) return $day;
        }
        return strtolower(date('l'));
    }

    private static function shortTime($time) {
        $timestamp = strtotime((string) $time);
        return $timestamp ? date('g:i A', $timestamp) : trim((string) $time);
    }

    private static function localizedPrefix($prefix, $language) {
        if ($language === "hi") return $prefix;
        if ($language === "kn") return $prefix;
        return $prefix;
    }

    private static function studentDataMissingReply($language) {
        if ($language === "hi") return "Student details ERP mein abhi available nahi hain. Kripya login check kijiye.";
        if ($language === "kn") return "Student details ERP nalli ivaga available illa. Dayavittu login check maadi.";
        return "I could not find your student details in ERP right now. Please check your login.";
    }

    private static function erpDataNotUpdatedReply($section, $language) {
        if ($language === "hi") return ucfirst($section) . " ERP mein abhi update nahi hai. College ERP team data update karne ke baad main bata paunga.";
        if ($language === "kn") return ucfirst($section) . " ERP nalli ivaga update agilla. College ERP team data update madida nantara nanu helabahudu.";
        return ucfirst($section) . " is not updated in ERP right now. Once the college ERP team updates it, I can show it here.";
    }

    private static function noErpRecordsReply($section, $language) {
        if ($language === "hi") return "ERP records mein " . $section . " available nahi hai.";
        if ($language === "kn") return "ERP records nalli " . $section . " available illa.";
        return "I could not find " . $section . " in ERP records right now.";
    }

    private static function erpTechnicalReply($section, $language) {
        if ($language === "hi") return ucfirst($section) . " check karte waqt technical issue aaya. Kripya thodi der baad try kijiye.";
        if ($language === "kn") return ucfirst($section) . " check maduvaga technical issue aayitu. Dayavittu swalpa samayada nantara try maadi.";
        return "I could not check " . $section . " right now due to a technical issue. Please try again after some time.";
    }
    private static function isAcademicPerformanceQuery($text) {
        return self::hasAny($text, [
            "overall academic performance",
            "academic performance",
            "performance summary",
            "overall performance",
            "my performance",
            "performance report",
            "academic summary",
            "study performance",
            "how is my academic performance",
            "performance hegide",
            "academic performance heli",
            "performance batao"
        ]);
    }
    private static function isPaymentGrievanceResultQuery($text) {
        return self::hasAny($text, ["grievance result", "complaint status", "grievance status", "track grievance", "check grievance", "payment complaint status"]);
    }
    private static function isPaymentGrievanceQuery($text) {
        return self::hasAny($text, ["payment grievance", "fee grievance", "payment complaint", "transaction grievance", "grievance result", "payment issue grievance"]);
    }

    private static function isGrievanceProcessQuery($text) {
        return self::hasAny($text, ["how to apply grievance", "apply grievance", "raise grievance", "submit grievance", "grievance process", "grievance kaise", "grievance hege", "complaint process"]);
    }

    private static function hasAny($text, $needles) {
        $text = " " . strtolower((string) $text) . " ";
        foreach ($needles as $needle) {
            $needle = strtolower(trim((string) $needle));
            if ($needle !== "" && strpos($text, $needle) !== false) return true;
        }
        return false;
    }

    private static function normalizeText($text) {
        $text = strtolower(trim((string) $text));
        $text = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $text);
        $text = preg_replace('/\s+/u', ' ', (string) $text);
        return trim((string) $text);
    }

    private static function localize($reply, $language) {
        if ($language === "hi") {
            return $reply;
        }
        if ($language === "kn") {
            return $reply;
        }
        return $reply;
    }

    private static function spokenDate($date) {
        $timestamp = strtotime((string) $date);
        return $timestamp ? date('j F Y', $timestamp) : (string) $date;
    }
}
