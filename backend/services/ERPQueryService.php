<?php

require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../intents/controllers/StudentController.php";
require_once __DIR__ . "/../intents/controllers/FeeController.php";
require_once __DIR__ . "/LoggerService.php";

class ERPQueryService {
    private static $activeErpContext = [];

    public static function detectIntent($query, $language = "en") {
        $start = LoggerService::nowMs();
        $text = self::normalizeText($query);
        if ($text === "") return self::observedDetectedIntent("", $query, $text, $language, $start);

        if (self::isPaymentGrievanceResultQuery($text)) return self::observedDetectedIntent("GET_PAYMENT_GRIEVANCE_RESULT", $query, $text, $language, $start);
        if (self::isPaymentGrievanceQuery($text)) return self::observedDetectedIntent("GET_PAYMENT_GRIEVANCE", $query, $text, $language, $start);
        if (self::isGrievanceProcessQuery($text)) return self::observedDetectedIntent("GET_GRIEVANCE_PROCESS", $query, $text, $language, $start);
        if (self::isFeeReceiptQuery($text)) return self::observedDetectedIntent("GET_FEE_RECEIPT", $query, $text, $language, $start);
        if (self::isFeeDeadlineQuery($text)) return self::observedDetectedIntent("GET_LAST_DATE_FEES", $query, $text, $language, $start);
        if (self::isFeePaymentNavigationQuery($text)) return self::observedDetectedIntent("GET_FEE_PAYMENT_NAVIGATION", $query, $text, $language, $start);
        if (self::isFeeBalanceQuery($text)) return self::observedDetectedIntent("GET_FEES_BALANCE", $query, $text, $language, $start);
        if (self::isFeeInfoQuery($text)) return self::observedDetectedIntent("GET_FEE_INFO", $query, $text, $language, $start);
        if (self::isProfileQuery($text)) return self::observedDetectedIntent("GET_PROFILE_SUMMARY", $query, $text, $language, $start);
        if (self::isBacklogQuery($text)) return self::observedDetectedIntent("GET_BACKLOG_STATUS", $query, $text, $language, $start);
        if (self::isResultStatusQuery($text)) return self::observedDetectedIntent("GET_RESULT_STATUS", $query, $text, $language, $start);
        if (self::isCgpaQuery($text)) return self::observedDetectedIntent("GET_CGPA", $query, $text, $language, $start);
        if (self::isSgpaQuery($text)) return self::observedDetectedIntent("GET_SGPA", $query, $text, $language, $start);
        if (self::hasAny($text, ["last working day", "last class day", "working day last", "last day of class", "classes end", "college last working", "kone working day", "last working dinanka"])) return self::observedDetectedIntent("GET_LAST_WORKING_DAY", $query, $text, $language, $start);
        if (self::isInternalMarksQuery($text)) return self::observedDetectedIntent("GET_INTERNAL_MARKS", $query, $text, $language, $start);
        if (self::isAssignmentQuery($text)) return self::observedDetectedIntent("GET_ASSIGNMENTS", $query, $text, $language, $start);
        if (self::isExamTimetableQuery($text)) return self::observedDetectedIntent("GET_EXAM_TIMETABLE", $query, $text, $language, $start);
        if (self::isTimetableQuery($text)) return self::observedDetectedIntent("GET_TIMETABLE", $query, $text, $language, $start);
        if (self::hasAny($text, ["subject code", "subject codes", "course code", "course codes", "codes of subjects", "code for subject", "code of subject"])) return self::observedDetectedIntent("GET_SUBJECT_CODES", $query, $text, $language, $start);
        if (self::isCourseDetailsQuery($text)) return self::observedDetectedIntent("GET_COURSE_DETAILS", $query, $text, $language, $start);
        if (self::isAcademicPerformanceQuery($text)) return self::observedDetectedIntent("GET_ACADEMIC_PERFORMANCE_SUMMARY", $query, $text, $language, $start);
        if (self::isSubjectAttendanceQuery($text)) return self::observedDetectedIntent("GET_SUBJECT_ATTENDANCE", $query, $text, $language, $start);
        if (self::hasAny($text, ["attendance", "attendence", "atendance", "attendance percentage", "overall attendance", "hajari", "hajarati", "hajri", "haazri", "hazri", "nanna attendance", "meri attendance", "attendance torisu", "attendance batao", "attendance bolo", "attendance nodu"])) return self::observedDetectedIntent("GET_ATTENDANCE", $query, $text, $language, $start);
        if (self::hasAny($text, ["usn", "u s n", "registration number", "university number", "my usn", "usn number"])) return self::observedDetectedIntent("GET_USN", $query, $text, $language, $start);
        if (self::hasAny($text, ["hall ticket", "hallticket", "admit card", "generate hall ticket", "hall ticket generated", "download hall ticket", "exam ticket",
            "hall ticket torisu", "hall ticket nodu", "hall ticket bandide", "hall ticket aagide",
            "hall ticket batao", "hall ticket dikhao", "mera hall ticket"])) return self::observedDetectedIntent("GET_HALLTICKET_STATUS", $query, $text, $language, $start);
        if (self::hasAny($text, [
            // status / general
            "certificate status", "competency certificate status", "digital competency certificate status",
            "digital competency status", "certificate issued", "certificate available", "certificate completed",
            "competency certificate", "digital competency certificate",
            // count / list
            "how many certificates", "certificate count", "my certificates", "list my certificates",
            "show my certificates", "all certificates", "certificate list", "certificates i have",
            "how many competency", "kitne certificate", "certificate kitne",
            // grade
            "certificate grade", "grade in certificate", "what grade certificate", "certificate mein grade",
            // download / access
            "download certificate", "download my certificate", "certificate download",
            "how to download certificate", "certificate kaise download", "certificate download maduvage",
            // semester / year / season filter
            "semester certificate", "certificate semester", "odd certificate", "even certificate",
            "2024 certificate", "2025 certificate", "certificate 2024", "certificate 2025",
            // subject-specific loose phrases
            "cyber security certificate", "cybersecurity certificate", "co curricular certificate",
            "co-curricular certificate", "technical skills certificate", "ethical hacking certificate",
            // Kanglish
            "nanna certificate", "certificate torisu", "certificate nodu", "certificate eshtu",
            "certificate sikkide", "certificate bandide", "certificate yavu", "yeshtu certificate",
            // Hinglish
            "mera certificate", "meri certificate", "certificate dikhao", "certificate batao",
            "certificate mil gaya", "certificate mila kya", "certificate aaya kya"
        ])) return self::observedDetectedIntent("GET_CERTIFICATE_STATUS", $query, $text, $language, $start);
        if (self::hasAny($text, ["final registration", "registration completed", "registration complete", "registration status", "registered or not", "am i registered", "have i registered", "course registration completed",
            // Kanglish
            "registration aagide", "registration agide", "registered aagidira", "registered hu",
            // Hinglish
            "registration ho gaya", "registration hua kya", "registered hu kya", "registration complete hua"])) return self::observedDetectedIntent("GET_FINAL_REGISTRATION_STATUS", $query, $text, $language, $start);
        if (self::isCollegeAddressQuery($text)) return self::observedDetectedIntent("GET_COLLEGE_ADDRESS", $query, $text, $language, $start);
        if (self::isLibraryQuery($text)) return self::observedDetectedIntent("GET_LIBRARY_INFO", $query, $text, $language, $start);
        if (self::isBusQuery($text)) return self::observedDetectedIntent("GET_BUS_INFO", $query, $text, $language, $start);
        if (self::isExamScheduleQuery($text)) return self::observedDetectedIntent("GET_EXAM_SCHEDULE", $query, $text, $language, $start);
        if (self::isHolidayQuery($text)) return self::observedDetectedIntent("GET_HOLIDAY_INFO", $query, $text, $language, $start);
        if (self::isAcademicCalendarQuery($text)) return self::observedDetectedIntent("GET_ACADEMIC_CALENDAR", $query, $text, $language, $start);
        if (self::isHostelInfoQuery($text)) return self::observedDetectedIntent("GET_HOSTEL_INFO", $query, $text, $language, $start);
        if (self::hasAny($text, ["faculty", "faculty details", "teacher details", "staff details", "professor", "hod details", "faculty contact", "teacher contact"])) return self::observedDetectedIntent("GET_FACULTY_DETAILS", $query, $text, $language, $start);

        return self::observedDetectedIntent("", $query, $text, $language, $start);
    }

    public static function handle($intent, $query, $language, $session) {
        $handleStart = LoggerService::nowMs();
        $intent = self::canonicalIntent($intent);
        $studentId = self::studentIdFromSession($session);
        self::$activeErpContext = [
            "start_ms" => $handleStart,
            "intent" => $intent,
            "query" => $query,
            "normalized_query" => self::normalizeText($query),
            "language" => $language,
            "user_id" => (int) ($session["user_id"] ?? 0),
            "student_id" => $studentId
        ];
        LoggerService::info("erp_query_handle_started", self::$activeErpContext);
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
            case "GET_PROFILE_SUMMARY":
                return self::payload(StudentController::getProfileSummary($studentId, $query, $language), $intent, $language, "profile_summary");
            case "GET_SGPA":
                return self::payload(StudentController::getSGPA($studentId, $query, $language), $intent, $language, "sgpa");
            case "GET_CGPA":
                return self::payload(StudentController::getCGPA($studentId, $query, $language), $intent, $language, "cgpa");
            case "GET_BACKLOG_STATUS":
                return self::payload(StudentController::getBacklogStatus($studentId, $query, $language), $intent, $language, "backlog_status");
            case "GET_SUBJECT_ATTENDANCE":
                return self::payload(StudentController::getSubjectAttendance($studentId, $query, $language), $intent, $language, "subject_attendance");
            case "GET_ATTENDANCE":
                return self::handleAttendanceQuery($studentId, $query, $language, $intent);
            case "GET_ACADEMIC_PERFORMANCE_SUMMARY":
                return self::payload(self::getAcademicPerformanceSummary($studentId, $language), $intent, $language, "academic_performance");
            case "GET_COURSE_DETAILS":
                return self::payload(self::getSubjects($studentId, $query, $language), $intent, $language, "subjects");
            case "GET_SUBJECT_CODES":
                $codesResult = self::getSubjectCodesWithVisual($studentId, $query, $language);
                return self::payload($codesResult["reply"], $intent, $language, "subject_codes",
                    isset($codesResult["visual"]) ? ["visual" => $codesResult["visual"]] : []);
            case "GET_USN":
                $usnResult = self::getUSNWithVisual($studentId, $language);
                return self::payload($usnResult["reply"], $intent, $language, "usn",
                    isset($usnResult["visual"]) ? ["visual" => $usnResult["visual"]] : []);
            case "GET_RESULT_STATUS":
                return self::payload(self::getResultStatus($studentId, $query, $language), $intent, $language, "result_status");
            case "GET_HALLTICKET_STATUS":
            case "GET_HALL_TICKET_STATUS":
                $htResult = self::getHallTicketWithVisual($studentId, $query, $language);
                return self::payload($htResult["reply"], "GET_HALLTICKET_STATUS", $language, "hallticket_status",
                    isset($htResult["visual"]) ? ["visual" => $htResult["visual"]] : []);
            case "GET_CERTIFICATE_STATUS":
                return self::payload(StudentController::getCertificateStatus($studentId, $query, $language), $intent, $language, "certificate_status");
            case "GET_FINAL_REGISTRATION_STATUS":
                return self::payload(FeeController::getFinalRegistrationStatus($studentId, $language), $intent, $language, "final_registration");
            case "GET_COLLEGE_ADDRESS":
                return self::payload(self::getCollegeAddress($language), $intent, $language, "college_address");
            case "GET_LIBRARY_INFO":
                return self::payload(self::getLibraryInfo($query, $language), $intent, $language, "library_info");
            case "GET_BUS_INFO":
                return self::payload(self::getBusInfo($query, $language), $intent, $language, "bus_info");
            case "GET_EXAM_SCHEDULE":
                return self::payload(self::getExamSchedule($studentId, $query, $language), $intent, $language, "exam_schedule");
            case "GET_HOLIDAY_INFO":
                return self::payload(self::getHolidayInfo($query, $language), $intent, $language, "holiday_info");
            case "GET_ACADEMIC_CALENDAR":
                return self::payload(self::getAcademicCalendar($query, $language), $intent, $language, "academic_calendar");
            case "GET_HOSTEL_INFO":
                return self::payload(self::getHostelInfo($query, $language, $studentId), $intent, $language, "hostel_info");
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

    private static function observedDetectedIntent($intent, $query, $normalizedQuery, $language, $startMs) {
        $latency = LoggerService::durationMs($startMs);
        LoggerService::info("erp_intent_detected", [
            "query" => $query,
            "normalized_query" => $normalizedQuery,
            "detected_intent" => $intent,
            "confidence" => $intent !== "" ? "rule_match" : "none",
            "selected_route" => $intent !== "" ? "database" : "fallback",
            "language" => $language,
            "latency_ms" => $latency
        ]);
        LoggerService::markPerformance("erp_intent_detection_latency", $latency, ["detected_intent" => $intent]);
        return $intent;
    }

    public static function getFeesBalance($studentId, $language = "en") {
        return FeeController::getFeeBalance($studentId, $language);
    }

    private static function handleAttendanceQuery($studentId, $query, $language, $intent) {
        $text = self::normalizeText($query);

        // Subject-specific: "attendance in DBMS", "OS attendance", "computer networks attendance"
        // Exclude generic words that are NOT subject names — show, my, overall, all, total, subject, etc.
        $genericWords = '/\b(show|my|all|overall|total|subject|subjects|wise|complete|full|summary|nanna|meri|mera|sab|sabhi|torisu|nodu)\b/u';
        $hasSubjectPhrase = (bool) preg_match('/\b(attendance|attendence|atendance)\s+(in|of|for)\s+\w/u', $text)
            || (bool) preg_match('/\b([a-z]{3,}(?:\s+[a-z]{3,})?)\s+(attendance|attendence|atendance)\b/u', $text)
                && !preg_match($genericWords, $text);

        if ($hasSubjectPhrase) {
            return self::payload(StudentController::getSubjectAttendance($studentId, $query, $language), $intent, $language, "attendance");
        }

        // All other attendance queries — show chart in voicebot panel
        $chart = self::getAttendanceChart($studentId, $query, $language);
        if (is_array($chart)) {
            return self::payload($chart["reply"], $intent, $language, "attendance_chart", ["visual" => $chart["visual"]]);
        }

        return self::payload(StudentController::getAttendance($studentId, $language), $intent, $language, "attendance");
    }

    public static function getAttendance($studentId, $query = "", $language = "en") {
        $text = self::normalizeText($query);
        $hasSubjectPhrase = (bool) preg_match('/\b(attendance|attendence|atendance)\s+(in|of|for)\b|\b[a-z]{2,}(?:\s+[a-z]{2,})?\s+(attendance|attendence|atendance)\b/u', $text);
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
        return self::getSubjectCodesWithVisual($studentId, $query, $language)["reply"];
    }

    public static function getSubjectCodesWithVisual($studentId, $query = "", $language = "en") {
        global $conn;
        $student = self::getStudent($studentId);
        if (!$student) return ["reply" => self::localize("I could not find your student details right now.", $language)];

        $semester = (int) ($student["semester"] ?? 0);
        $branch   = (string) ($student["branch"] ?? "");
        $stmt = $conn->prepare("SELECT course_code, course_title FROM courses WHERE semester = ? AND program = ? ORDER BY course_code");
        if (!$stmt) return ["reply" => self::localize("System error while fetching subject codes.", $language)];
        $stmt->bind_param("is", $semester, $branch);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = ["code" => $row["course_code"], "title" => $row["course_title"]];
        }
        $stmt->close();

        if (empty($rows)) return ["reply" => self::localize("I could not find subject codes for your current semester.", $language)];

        $spoken = implode("; ", array_map(fn($r) => "{$r['title']} — {$r['code']}", array_slice($rows, 0, 4)));
        $reply  = self::localize("Semester {$semester} subject codes: {$spoken}.", $language);

        $cards  = array_map(fn($r) => ["title" => $r["code"], "value" => $r["title"]], $rows);
        return [
            "reply"  => $reply,
            "visual" => ["type" => "info_card", "title" => "Sem {$semester} Subject Codes", "cards" => $cards]
        ];
    }

    public static function getUSNWithVisual($studentId, $language = "en") {
        global $conn;
        $student = self::getStudent($studentId);
        if (!$student) return ["reply" => self::localize("I could not find your USN right now.", $language)];

        $usn    = (string) ($student["usn"] ?? "");
        $name   = (string) ($student["full_name"] ?? $student["name"] ?? "");
        $branch = (string) ($student["branch"] ?? "");
        $sem    = (string) ($student["semester"] ?? "");

        if ($usn === "") return ["reply" => self::localize("Your USN is not updated in the ERP yet.", $language)];

        if ($language === "hi") $reply = "Aapka USN hai {$usn}.";
        elseif ($language === "kn") $reply = "Nimma USN {$usn} aagide.";
        else $reply = "Your USN is {$usn}.";

        return [
            "reply"  => $reply,
            "visual" => [
                "type"  => "info_card",
                "title" => "Student ID",
                "cards" => [
                    ["title" => "USN",      "value" => $usn],
                    ["title" => "Name",     "value" => $name],
                    ["title" => "Branch",   "value" => $branch],
                    ["title" => "Semester", "value" => "Sem {$sem}"],
                ]
            ]
        ];
    }

    public static function getHallTicketWithVisual($studentId, $query = "", $language = "en") {
        $reply  = StudentController::getHallTicketStatus($studentId, $query, $language);
        $student = self::getStudent($studentId);
        if (!$student) return ["reply" => $reply];

        $usn  = (string) ($student["usn"] ?? "");
        $name = (string) ($student["full_name"] ?? $student["name"] ?? "");
        $sem  = (string) ($student["semester"] ?? "");

        return [
            "reply"  => $reply,
            "visual" => [
                "type"  => "info_card",
                "title" => "Hall Ticket Info",
                "cards" => [
                    ["title" => "USN",       "value" => $usn],
                    ["title" => "Name",      "value" => $name],
                    ["title" => "Semester",  "value" => "Sem {$sem}"],
                    ["title" => "Exam Types","value" => "SEE  •  RESIT  •  RE-REG"],
                ]
            ]
        ];
    }

    public static function getTimetable($studentId, $query = "", $language = "en") {
        // Class timetable is not available in the GMU ERP student module.
        // Students should check their department notice board or ask their class coordinator.
        if ($language === "hi") return "Class timetable abhi ERP student portal mein available nahi hai. Apne department ke notice board ya class coordinator se timetable confirm karein.";
        if ($language === "kn") return "Class timetable ERP student portal nalli available illa. Nimma department notice board athava class coordinator nalli timetable confirm maadi.";
        return "Class timetable is not available in the ERP student portal. Please check your department notice board or contact your class coordinator for the schedule.";

        global $conn;
        $student = self::getStudent($studentId);
        if (!$student) return self::studentDataMissingReply($language);
        if (!self::tableHasColumns("class_timetable", ["program", "semester", "day_of_week", "start_time", "end_time", "course_id"])) {
            return self::erpDataNotUpdatedReply("class timetable", $language);
        }

        $day = self::requestedDayName($query);
        $program = (string) ($student["branch"] ?? "");
        $semester = (int) ($student["semester"] ?? 0);
        // Allow explicit semester override ("semester 3 timetable").
        $requestedSem = StudentController::inferRequestedSemester($query);
        if ($requestedSem) $semester = $requestedSem;
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
        // Exam timetable is published on the ERP Hall Ticket page and college notice board, not in the student module.
        if ($language === "hi") return "Exam timetable ERP student portal mein directly available nahi hai. Hall Ticket page check karein ya college notice board dekhein — wahan exact date aur time milega.";
        if ($language === "kn") return "Exam timetable ERP student portal nalli directly available illa. Hall Ticket page athava college notice board check maadi — adare exact date mattu time sigutta.";
        return "Exam timetable is not directly available in the ERP student portal. Please check the Hall Ticket page on the ERP or the college notice board for your exact exam dates and timings.";

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
    // ── Intent detectors ──────────────────────────────────────────────────────

    private static function isCollegeAddressQuery($text) {
        return self::hasAny($text, [
            "address", "location", "where is college", "college location", "college address",
            "how to reach", "how to come", "directions", "route", "campus location",
            "gmu address", "university address", "college kahan hai", "college kahan",
            "college location kya hai", "college ka address", "address kya hai",
            "college elli ide", "college elli", "college yalli ide", "address torisu",
            "address bolo", "address batao", "college hesaru", "campus elli",
            "davangere college", "pb road", "poona bangalore", "near dc office",
            "pincode", "pin code", "577006", "google maps", "maps"
        ]);
    }

    public static function getCollegeAddress($language = "en") {
        if ($language === "hi") {
            return "GM University P.B. Road yaani Poona-Bangalore Highway par, District Commissioner ke office ke paas, Davangere, Karnataka mein sthit hai. Pin code 577006 hai. "
                 . "57 acre ka campus city center se lagbhag 4 kilometer door hai. "
                 . "Directions ke liye college ki official website gmu.ac.in par Contact Us page dekhein.";
        }
        if ($language === "kn") {
            return "GM University P.B. Road alli, Davangere District Commissioner office hattira, Davangere, Karnataka nalli ide. Pin code 577006. "
                 . "57 acre campus city center inda sagala 4 kilometre doodale ide. "
                 . "Directions ge college official website gmu.ac.in nalli Contact Us page nodi.";
        }
        return "GM University is located on P.B. Road (Poona-Bangalore Highway), adjoining the District Commissioner's Office, Davangere - 577006, Karnataka. "
             . "The 57-acre campus is about 4 km from the city center. "
             . "For directions visit gmu.ac.in and check the Contact Us page.";
    }

    private static function isLibraryQuery($text) {
        return self::hasAny($text, [
            "library", "central library", "library card", "library book", "borrow book",
            "issue book", "return book", "library timing", "library time", "library hours",
            "library access", "how to use library", "library facility", "pustaka", "pusthaka",
            "library kaise use", "library card kaise", "book issue", "book return",
            "library nalli", "library hegide", "library torisu", "library batao",
            "library yavaga", "library open", "reading room", "library membership"
        ]);
    }

    public static function getLibraryInfo($query = "", $language = "en") {
        $text = self::normalizeText($query);

        $asksCard   = self::hasAny($text, ["card", "membership", "how to use", "access", "kaise use", "hegide"]);
        $asksBook   = self::hasAny($text, ["book", "borrow", "issue", "return", "pustaka", "pusthaka"]);

        if ($asksCard && !$asksBook) {
            if ($language === "hi") return "Library use karne ke liye aapko library card ki zaroorat hai. Library card college administration se issue hota hai. Card lekar Central Library mein jaayein aur books issue karwa sakte hain.";
            if ($language === "kn") return "Library upayogisalu nimage library card beku. Library card college administration nalli siguttade. Card tagondu Central Library ge hogi books issue maadikollabahadu.";
            return "To use the library you need a library card issued by the college administration. Carry your library card to the Central Library to access books and services.";
        }

        if ($asksBook && !$asksCard) {
            if ($language === "hi") return "Central Library mein jaake apna library card dikhayein aur book issue karwa sakte hain. Book use karne ke baad wapas library mein return karni hoti hai.";
            if ($language === "kn") return "Central Library ge hogi nimma library card tori books issue maadikollabahadu. Books upayogisida nantara library ge wapas return maadabeku.";
            return "Visit the Central Library with your library card to issue books. After use, return the books to the library.";
        }

        if ($language === "hi") {
            return "GM University mein Central Library ki facility available hai. "
                 . "Library use karne ke liye college se library card lena hota hai. "
                 . "Library card lekar Central Library mein jaayein, books issue karwa sakte hain aur use karne ke baad return karni hoti hai. "
                 . "Zyada jaankari ke liye library counter se sampark karein.";
        }
        if ($language === "kn") {
            return "GM University nalli Central Library facility ide. "
                 . "Library upayogisalu college ninda library card tagondbeku. "
                 . "Library card tagondu Central Library ge hogi books issue maadikollabahadu, upayogisida nantara wapas return maadabeku. "
                 . "Hechchu maahitige library counter nalli sampark maadi.";
        }
        return "GM University has a Central Library facility available to all students. "
             . "You need a library card issued by the college to access it. "
             . "Visit the Central Library with your library card to issue books and return them after use. "
             . "Contact the library counter for more details.";
    }

    private static function isBusQuery($text) {
        return self::hasAny($text, [
            // English
            "bus", "bus timing", "bus time", "bus schedule", "bus route", "bus facility",
            "college bus", "shuttle", "transport", "bus available", "bus service",
            "bus stop", "pickup", "drop", "morning bus", "evening bus",
            "bus pass", "bus fee", "what time bus", "when is bus", "when does bus",
            "bus information", "bus details", "bus number", "bus timings",
            "harihar bus", "davangere bus", "travel", "commute",
            // Hindi
            "bus kab", "bus kab aata", "bus kab hai", "bus ka time", "bus kya time",
            "bus batao", "bus bolo", "bus kahan", "bus jayegi", "bus chalti",
            "subah bus", "shaam bus", "wapas bus",
            // Kannada
            "bus yavaga", "bus hegide", "bus torisu", "bus eshtu", "bus beku",
            "bus ide", "bus yelli", "bus yen time", "beligge bus", "saanje bus"
        ]);
    }

    public static function getBusInfo($query = "", $language = "en") {
        $text = self::normalizeText($query);

        $asksEvening = self::hasAny($text, ["evening", "return", "drop", "saanje", "sanje", "shaam", "wapas"]);
        $asksMorning = self::hasAny($text, ["morning", "pickup", "subah", "beligge", "belagge"]);

        // GMU bus timings
        $morningTimings = "7:00 AM, 8:00 AM, and 9:00 AM";
        $eveningTimings = "4:30 PM and 6:00 PM";
        $routes = "Davangere city, Harihar, Ranebennur, and surrounding areas";
        $contact = "Transport Office: +91-8192-123456";

        if ($asksEvening && !$asksMorning) {
            if ($language === "hi") return "GMU college bus evening mein {$eveningTimings} ko chalti hai. Yeh {$routes} ke liye available hai. Transport office se route details lein.";
            if ($language === "kn") return "GMU college bus saanje {$eveningTimings} ge hogi. {$routes} ge available ide. Route details ge transport office sampark maadi.";
            return "GMU college bus runs in the evening at {$eveningTimings}, covering {$routes}. Contact the transport office for your specific route.";
        }

        if ($asksMorning && !$asksEvening) {
            if ($language === "hi") return "GMU college bus subah {$morningTimings} ko chalti hai aur {$routes} se pickup karti hai.";
            if ($language === "kn") return "GMU college bus beligge {$morningTimings} ge hogi mattu {$routes} inda pickup maaduttade.";
            return "GMU college bus runs in the morning at {$morningTimings}, picking up from {$routes}.";
        }

        if ($language === "hi") {
            return "GM University bus service {$routes} ke liye available hai. "
                 . "Morning buses: {$morningTimings}. "
                 . "Evening buses: {$eveningTimings}. "
                 . "Apna stop aur route confirm karne ke liye transport office se contact karein.";
        }
        if ($language === "kn") {
            return "GM University bus seva {$routes} ge available ide. "
                 . "Beligge buses: {$morningTimings}. "
                 . "Saanje buses: {$eveningTimings}. "
                 . "Nimma stop mattu route confirm maadalu transport office sampark maadi.";
        }
        return "GM University bus service is available for {$routes}. "
             . "Morning buses depart at {$morningTimings}. "
             . "Evening buses depart at {$eveningTimings}. "
             . "Contact the transport office to confirm your stop and route.";
    }

    private static function isExamScheduleQuery($text) {
        $hasExam = self::hasAny($text, [
            "see exam", "semester exam", "end exam", "final exam", "exam date", "exam schedule",
            "exam time", "exam timetable", "exam hall", "examination date", "examination schedule",
            "when is exam", "when exam", "exam kab hai", "exam kab", "exam dinanka",
            "see date", "see timetable", "see exam date", "see schedule",
            "pariksha", "pariksha date", "pariksha schedule", "pariksha kab",
            "exam yavaga", "exam yaavaga", "pariksha yaavaga", "exam hegide"
        ]);
        // exclude class timetable queries already handled by GET_TIMETABLE
        $isClassTimetable = self::hasAny($text, ["class timetable", "today class", "tomorrow class", "which class", "class schedule today"]);
        return $hasExam && !$isClassTimetable;
    }

    private static function isHolidayQuery($text) {
        // "is there class/college tomorrow/today", "kal class hai", "nale holiday ide"
        $classTomorrow = (bool) preg_match(
            '/\b(' .
            'class(es)?\s+(tomorrow|today|kal|aaj|nale)|' .
            '(tomorrow|kal|nale)\s+(class(es)?|holiday|bandh|chutti|raje)|' .
            'is\s+(there\s+)?(class(es)?|college|holiday)\s+(tomorrow|today)|' .
            'kal\s+(class|college|holiday|chutti|bandh)\s*(hai|he|ide|ade)?|' .
            'aaj\s+(class|college|holiday|chutti)\s*(hai|he|ide)?|' .
            'nale\s+(class|college|holiday|raje)\s*(ide|ade|he)?|' .
            'college\s+(tomorrow|kal|aaj|today)\s*(open|closed|bandh|hai)?' .
            ')\b/ui',
            $text
        );
        // specific festival names from GMU 2026 list
        $festivalNames = (bool) preg_match(
            '/\b(sankranti|makara|uttarayana|republic\s*day|ugadi|ramzan|khutub|basava|akshaya|' .
            'may\s*day|independence\s*day|vinayaka|ganesh|gandhi\s*jayanti|mahalaya|amavasye|' .
            'mahanavami|ayudha|vijayadasami|dussehra|dasara|balipadyami|deepavali|diwali|' .
            'christmas|shivaratri|valmiki|rajyotsava)\b/ui',
            $text
        );
        // any mention of the word holiday/holidays — catch-all so it never falls to the DB query
        $anyHoliday = (bool) preg_match('/\bholiday(s)?\b/ui', $text);
        return $classTomorrow || $festivalNames || $anyHoliday;
    }

    // ── GMU Annual Holiday List 2026 (hardcoded from official circular) ───────

    private static function gmu2026Holidays() {
        return [
            ["date" => "2026-01-15", "day" => "Thursday",  "name" => "Uttarayana Punyakala / Makara Sankranti Festival"],
            ["date" => "2026-01-26", "day" => "Monday",    "name" => "Republic Day"],
            ["date" => "2026-02-15", "day" => "Sunday",    "name" => "Maha Shivaratri (Sunday)"],
            ["date" => "2026-03-19", "day" => "Thursday",  "name" => "Ugadi Festival"],
            ["date" => "2026-03-21", "day" => "Saturday",  "name" => "Khutub-E-Ramzan"],
            ["date" => "2026-04-20", "day" => "Monday",    "name" => "Basava Jayanthi / Akshaya Tritiya"],
            ["date" => "2026-05-01", "day" => "Friday",    "name" => "May Day"],
            ["date" => "2026-08-15", "day" => "Saturday",  "name" => "Independence Day"],
            ["date" => "2026-09-14", "day" => "Monday",    "name" => "Varasiddhi Vinayaka Vrata"],
            ["date" => "2026-10-02", "day" => "Friday",    "name" => "Gandhi Jayanthi"],
            ["date" => "2026-10-10", "day" => "Saturday",  "name" => "Mahalaya Amavasye"],
            ["date" => "2026-10-20", "day" => "Tuesday",   "name" => "Mahanavami / Ayudha Pooja"],
            ["date" => "2026-10-21", "day" => "Wednesday", "name" => "Vijayadasami"],
            ["date" => "2026-11-01", "day" => "Sunday",    "name" => "Kannada Rajyothsava (Sunday)"],
            ["date" => "2026-11-08", "day" => "Sunday",    "name" => "Naraka Chaturdasi (Sunday)"],
            ["date" => "2026-11-10", "day" => "Tuesday",   "name" => "Balipadyami / Deepavali"],
            ["date" => "2026-12-25", "day" => "Friday",    "name" => "Christmas"],
        ];
    }

    public static function getHolidayInfo($query = "", $language = "en") {
        $text     = self::normalizeText($query);
        $holidays = self::gmu2026Holidays();
        $today    = date("Y-m-d");
        $todayTs  = strtotime($today);

        // ── "Is there class tomorrow / today?" ──────────────────────────────
        $askTomorrow = (bool) preg_match('/\b(tomorrow|kal|nale)\b/ui', $text);
        $askToday    = (bool) preg_match('/\b(today|aaj|indu|ee\s*dinavu)\b/ui', $text) && !$askTomorrow;
        $isClassQ    = (bool) preg_match('/\b(class(es)?|college|school)\b/ui', $text);

        if ($isClassQ || $askTomorrow || $askToday) {
            $checkDate = $askTomorrow ? date("Y-m-d", strtotime("+1 day")) : $today;
            $checkTs   = strtotime($checkDate);
            $dayOfWeek = (int) date("N", $checkTs); // 1=Mon ... 7=Sun
            $label     = $askTomorrow ? "Tomorrow" : "Today";
            $labelHi   = $askTomorrow ? "Kal"   : "Aaj";
            $labelKn   = $askTomorrow ? "Naale" : "Indu";
            $displayDate = date("d M Y", $checkTs);

            // Sunday → always no class
            if ($dayOfWeek === 7) {
                $msg = [
                    "en" => "{$label} ({$displayDate}) is a Sunday — no classes at GMU.",
                    "hi" => "{$labelHi} ({$displayDate}) Sunday hai — GMU mein class nahi hai.",
                    "kn" => "{$labelKn} ({$displayDate}) Bhanuvaara — GMU nalli class illa.",
                ];
                return $msg[$language] ?? $msg["en"];
            }

            // Check holiday list
            $matchedHoliday = null;
            foreach ($holidays as $h) {
                if ($h["date"] === $checkDate) { $matchedHoliday = $h; break; }
            }

            if ($matchedHoliday) {
                $hName = $matchedHoliday["name"];
                $msg = [
                    "en" => "{$label} ({$displayDate}) is a public holiday — {$hName}. No classes at GMU.",
                    "hi" => "{$labelHi} ({$displayDate}) {$hName} ki chutti hai — GMU mein class nahi hai.",
                    "kn" => "{$labelKn} ({$displayDate}) {$hName} — public holiday. GMU nalli class illa.",
                ];
                return $msg[$language] ?? $msg["en"];
            }

            // Saturday — check if it's a holiday; otherwise mention it may be a working day
            if ($dayOfWeek === 6) {
                $msg = [
                    "en" => "{$label} ({$displayDate}) is a Saturday and is not a listed public holiday — classes may be held. Check with your department to confirm.",
                    "hi" => "{$labelHi} ({$displayDate}) Saturday hai aur koi listed holiday nahi hai — class ho sakti hai. Department se confirm karein.",
                    "kn" => "{$labelKn} ({$displayDate}) Shanivaara — listed holiday alla, class irabahudhu. Department ninda confirm maadi.",
                ];
                return $msg[$language] ?? $msg["en"];
            }

            // Regular weekday — no holiday
            $dayName = date("l", $checkTs);
            $msg = [
                "en" => "{$label} ({$displayDate}) is a {$dayName} — regular classes are scheduled at GMU.",
                "hi" => "{$labelHi} ({$displayDate}) ko {$dayName} hai — GMU mein normal class hai.",
                "kn" => "{$labelKn} ({$displayDate}) {$dayName} — GMU nalli normal class ide.",
            ];
            return $msg[$language] ?? $msg["en"];
        }

        // ── Specific festival name search ────────────────────────────────────
        $festivalMap = [
            "sankranti" => "2026-01-15", "makara" => "2026-01-15", "uttarayana" => "2026-01-15",
            "republic"  => "2026-01-26",
            "ugadi"     => "2026-03-19",
            "ramzan"    => "2026-03-21", "khutub" => "2026-03-21",
            "basava"    => "2026-04-20", "akshaya" => "2026-04-20",
            "may day"   => "2026-05-01",
            "independence" => "2026-08-15",
            "vinayaka"  => "2026-09-14", "ganesh" => "2026-09-14",
            "gandhi"    => "2026-10-02",
            "mahalaya"  => "2026-10-10",
            "mahanavami" => "2026-10-20", "ayudha" => "2026-10-20",
            "vijayadasami" => "2026-10-21", "dussehra" => "2026-10-21", "dasara" => "2026-10-21",
            "deepavali" => "2026-11-10", "diwali" => "2026-11-10", "balipadyami" => "2026-11-10",
            "christmas" => "2026-12-25",
            "shivaratri" => "2026-02-15",
            "rajyotsava" => "2026-11-01",
        ];
        foreach ($festivalMap as $keyword => $date) {
            if (strpos($text, $keyword) !== false) {
                foreach ($holidays as $h) {
                    if ($h["date"] === $date) {
                        $d = date("d M Y", strtotime($date));
                        $msg = [
                            "en" => "{$h['name']} is on {$d} ({$h['day']}) — it's a GMU public holiday.",
                            "hi" => "{$h['name']} {$d} ({$h['day']}) ko hai — GMU mein is din chutti hai.",
                            "kn" => "{$h['name']} {$d} ({$h['day']}) nalli ide — GMU public holiday.",
                        ];
                        return $msg[$language] ?? $msg["en"];
                    }
                }
            }
        }

        // ── Next upcoming holiday ────────────────────────────────────────────
        $wantsNext = (bool) preg_match('/\b(next|upcoming|coming|aane\s*wala|bandhu\s*baruta)\b/ui', $text);
        if ($wantsNext) {
            foreach ($holidays as $h) {
                if ($h["date"] >= $today && $h["day"] !== "Sunday") {
                    $d = date("d M Y", strtotime($h["date"]));
                    $msg = [
                        "en" => "The next GMU holiday is {$h['name']} on {$d} ({$h['day']}).",
                        "hi" => "GMU mein agla holiday {$h['name']} hai — {$d} ({$h['day']}) ko.",
                        "kn" => "Munde GMU holiday: {$h['name']} — {$d} ({$h['day']}) nalli.",
                    ];
                    return $msg[$language] ?? $msg["en"];
                }
            }
        }

        // ── General holiday list query — direct to official source ───────────
        $msg = [
            "en" => "The full GMU holiday list is available on the college notice board and the official GMU website. You can also check with your department office for the latest circular.",
            "hi" => "GMU ki poori holiday list college notice board aur official GMU website par available hai. Latest circular ke liye apne department office se bhi pooch sakte ho.",
            "kn" => "GMU holiday list college notice board mattu official GMU website nalli siguttade. Latest circular ge nimma department office ninda check maadi.",
        ];
        return $msg[$language] ?? $msg["en"];
    }

    private static function isAcademicCalendarQuery($text) {
        return self::hasAny($text, [
            "academic calendar", "holiday list", "holidays", "holiday schedule",
            "when does semester start", "semester start date", "semester begin",
            "when does college reopen", "college reopen", "reopen date",
            "vacation", "summer vacation", "semester end date", "college calendar",
            "when is diwali holiday", "when is ugadi holiday", "when is holiday",
            "upcoming holiday", "next holiday", "holiday kab", "holiday list bolo",
            "semester kab shuru", "college kab khulega", "college bandh kab",
            "holiday yaavaga", "semester yavaga shuru", "college yavaga reopens",
            "rajyotsava holiday", "kannada rajyotsava", "republic day holiday",
            "gandhi jayanti holiday", "christmas holiday", "new year holiday"
        ]);
    }

    private static function isHostelInfoQuery($text) {
        $hasHostel = self::hasAny($text, ["hostel", "hostelu", "hostel room", "pg", "accommodation", "warden"]);
        $hasInfoSignal = self::hasAny($text, [
            "warden", "contact", "timing", "time", "gate", "fee", "fees", "charges",
            "mess", "food", "facilities", "wifi", "room", "allotment", "apply",
            "application", "how to apply", "available", "kab", "kaise", "info",
            "details", "torisu", "hegide", "yavaga", "batao", "bolo", "nodu"
        ]);
        // exclude hostel fee payment queries (already handled by GET_FEES_BALANCE)
        $isPayment = self::hasAny($text, ["pay hostel", "hostel fee pay", "hostel payment portal"]);
        return $hasHostel && ($hasInfoSignal || $hasHostel) && !$isPayment;
    }

    // ── Exam Schedule ─────────────────────────────────────────────────────────

    public static function getExamSchedule($studentId, $query = "", $language = "en") {
        global $conn;
        $text = self::normalizeText($query);

        // Extract semester from query; fall back to student's current semester
        $semester = self::extractSemesterFromText($text);
        if ($semester <= 0 && $studentId > 0) {
            $s = $conn->prepare("SELECT semester FROM students WHERE student_id = ? LIMIT 1");
            if ($s) {
                $s->bind_param("i", $studentId);
                $s->execute();
                $row = $s->get_result()->fetch_assoc();
                $s->close();
                $semester = (int) ($row["semester"] ?? 0);
            }
        }

        // Extract specific subject keyword
        $subjectKeyword = self::extractSubjectKeyword($text);

        $params = [];
        $types  = "";
        $where  = [];

        if ($semester > 0) {
            $where[]  = "es.semester = ?";
            $types   .= "i";
            $params[] = $semester;
        }
        if ($subjectKeyword !== "") {
            $where[]  = "(LOWER(es.course_title) LIKE ? OR LOWER(es.course_code) LIKE ?)";
            $types   .= "ss";
            $like     = "%" . $subjectKeyword . "%";
            $params[] = $like;
            $params[] = $like;
        }

        $whereClause = empty($where) ? "" : "WHERE " . implode(" AND ", $where);
        $stmt = $conn->prepare("
            SELECT es.course_code, es.course_title, es.exam_type, es.exam_date,
                   es.start_time, es.end_time, es.venue
            FROM exam_schedule es
            {$whereClause}
            ORDER BY es.exam_date, es.start_time
            LIMIT 8
        ");

        if (!$stmt) {
            return self::localize("Could not fetch exam schedule right now. Please check ERP or contact your department.", $language);
        }

        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        if (empty($rows)) {
            if ($language === "hi") return "SEE exams aamtaur par ODD semester mein December mein aur EVEN semester mein May mein hote hain. Exact dates ke liye ERP ka Hall Ticket page check karein ya college notice board dekhein.";
            if ($language === "kn") return "SEE exams sadhaaranavaagi ODD semester ge December nalli mattu EVEN semester ge May nalli nadeeyuttave. Exact dates ge ERP Hall Ticket page nodi athava college notice board nodi.";
            return "SEE exams are usually held in December for ODD semester and May for EVEN semester. Check the ERP Hall Ticket page or college notice board for exact dates.";
        }

        $parts = [];
        foreach ($rows as $r) {
            $title = $r["course_title"] ?? "";
            $date  = date("d M Y", strtotime($r["exam_date"]));
            $time  = date("h:i A", strtotime($r["start_time"])) . " to " . date("h:i A", strtotime($r["end_time"]));
            $venue = $r["venue"] ? ", " . $r["venue"] : "";
            $parts[] = "{$title}: {$date}, {$time}{$venue}";
        }
        $list = implode("; ", $parts);
        $semLabel = $semester > 0 ? " for Semester {$semester}" : "";

        if ($language === "hi") return "Aapka SEE exam schedule{$semLabel}: {$list}.";
        if ($language === "kn") return "Nimma SEE exam schedule{$semLabel}: {$list}.";
        return "Your SEE exam schedule{$semLabel}: {$list}.";
    }

    private static function extractSemesterFromText($text) {
        if (preg_match('/\b(\d)\s*(?:st|nd|rd|th)?\s*sem(?:ester)?\b/i', $text, $m)) return (int) $m[1];
        if (preg_match('/\bsem(?:ester)?\s*(\d)\b/i', $text, $m)) return (int) $m[1];
        return 0;
    }

    private static function extractSubjectKeyword($text) {
        $stopwords = ['exam', 'schedule', 'timetable', 'date', 'time', 'when', 'is', 'my', 'the',
                      'for', 'of', 'see', 'cie', 'semester', 'sem', 'show', 'tell', 'open'];
        $words = preg_split('/\s+/', strtolower(trim($text)));
        $keywords = array_filter($words, fn($w) => strlen($w) > 2 && !in_array($w, $stopwords));
        return implode(" ", array_slice(array_values($keywords), 0, 3));
    }

    // ── Academic Calendar ─────────────────────────────────────────────────────

    public static function getAcademicCalendar($query = "", $language = "en") {
        global $conn;
        $text = self::normalizeText($query);

        // Detect what the user is asking about
        $wantsHolidays     = self::hasAny($text, ["holiday", "holidays", "holiday list", "bandh", "छुट्टी", "ರಜೆ"]);
        $wantsSemesterDates = self::hasAny($text, ["semester start", "semester begin", "reopen", "semester end", "classes begin", "classes start", "classes end"]);
        $wantsVacation     = self::hasAny($text, ["vacation", "summer vacation", "break"]);
        $wantsUpcoming     = self::hasAny($text, ["next holiday", "upcoming holiday", "next break"]);

        // Specific festival/event names
        $eventKeyword = "";
        $festivals = ["diwali", "ugadi", "rajyotsava", "christmas", "new year", "republic day",
                      "gandhi jayanti", "ambedkar", "holi", "eid", "pongal"];
        foreach ($festivals as $f) {
            if (strpos($text, $f) !== false) { $eventKeyword = $f; break; }
        }

        if ($eventKeyword !== "") {
            $stmt = $conn->prepare("SELECT event_name, start_date, end_date FROM academic_calendar WHERE LOWER(event_name) LIKE ? LIMIT 3");
            if ($stmt) {
                $like = "%" . $eventKeyword . "%";
                $stmt->bind_param("s", $like);
                $stmt->execute();
                $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $stmt->close();
                if (!empty($rows)) {
                    $r    = $rows[0];
                    $name = $r["event_name"];
                    $date = date("d M Y", strtotime($r["start_date"]));
                    $end  = $r["end_date"] ? " to " . date("d M Y", strtotime($r["end_date"])) : "";
                    if ($language === "hi") return "{$name} ki chutti {$date}{$end} hai.";
                    if ($language === "kn") return "{$name} raje {$date}{$end} ide.";
                    return "{$name} holiday is on {$date}{$end}.";
                }
            }
        }

        $eventType = "";
        if ($wantsHolidays)      $eventType = "holiday";
        elseif ($wantsSemesterDates) $eventType = "semester";
        elseif ($wantsVacation)  $eventType = "vacation";

        $where = $eventType !== "" ? "WHERE event_type LIKE ?" : "WHERE start_date >= CURDATE()";
        $stmt  = $conn->prepare("SELECT event_name, event_type, start_date, end_date FROM academic_calendar {$where} ORDER BY start_date LIMIT 8");
        if (!$stmt) {
            return self::localize("Could not fetch academic calendar right now.", $language);
        }

        if ($eventType !== "") {
            $like = "%" . $eventType . "%";
            $stmt->bind_param("s", $like);
        }
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        if (empty($rows)) {
            if ($language === "hi") return "Academic calendar mein abhi koi entry available nahi hai.";
            if ($language === "kn") return "Academic calendar nalli ivaga yaavudu entry illa.";
            return "No academic calendar entries found right now.";
        }

        $parts = [];
        foreach ($rows as $r) {
            $name = $r["event_name"];
            $date = date("d M Y", strtotime($r["start_date"]));
            $end  = $r["end_date"] ? " to " . date("d M Y", strtotime($r["end_date"])) : "";
            $parts[] = "{$name}: {$date}{$end}";
        }
        $list = implode("; ", $parts);

        if ($language === "hi") return "Academic calendar: {$list}.";
        if ($language === "kn") return "Academic calendar: {$list}.";
        return "Academic calendar: {$list}.";
    }

    // ── Hostel Info ───────────────────────────────────────────────────────────

    public static function getHostelInfo($query = "", $language = "en", $studentId = 0) {
        global $conn;
        $text = self::normalizeText($query);

        // Check student's hostel application status first if they ask about allotment
        $asksAllotment = self::hasAny($text, ["allotment", "allotted", "status", "approved", "applied", "application", "room got", "room mila", "room sikkide"]);
        if ($asksAllotment && $studentId > 0) {
            $stmt = $conn->prepare("SELECT status, remarks FROM hostel_applications WHERE student_id = ? ORDER BY applied_at DESC LIMIT 1");
            if ($stmt) {
                $stmt->bind_param("i", $studentId);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if ($row) {
                    $status  = ucfirst(strtolower($row["status"]));
                    $remarks = $row["remarks"] ? " — " . $row["remarks"] : "";
                    if ($language === "hi") return "Aapki hostel application ka status: {$status}{$remarks}.";
                    if ($language === "kn") return "Nimma hostel application status: {$status}{$remarks}.";
                    return "Your hostel application status is {$status}{$remarks}.";
                }
                if ($language === "hi") return "Aapka koi hostel application nahi mila. ERP mein Registration → Hostel Application se apply kar sakte hain.";
                if ($language === "kn") return "Nimma hostel application sigalilla. ERP nalli Registration → Hostel Application nalli apply maadi.";
                return "No hostel application found for your account. You can apply via ERP → Registration → Hostel Application.";
            }
        }

        // Fetch the specific info key(s) based on what was asked
        $keyMap = [
            ["warden", ["warden_name", "warden_contact", "warden_email"]],
            ["contact",["warden_contact", "warden_email"]],
            ["mess",   ["mess_timing"]],
            ["food",   ["mess_timing"]],
            ["timing", ["hostel_timing", "mess_timing"]],
            ["gate",   ["hostel_timing"]],
            ["fee",    ["fee_per_year", "fee_includes"]],
            ["fees",   ["fee_per_year", "fee_includes"]],
            ["charges",["fee_per_year", "fee_includes"]],
            ["apply",  ["application_process"]],
            ["application", ["application_process"]],
            ["room",   ["room_types", "application_process"]],
            ["facilities", ["facilities"]],
            ["wifi",   ["facilities"]],
        ];

        $keys = [];
        foreach ($keyMap as [$keyword, $infoKeys]) {
            if (strpos($text, $keyword) !== false) {
                $keys = array_merge($keys, $infoKeys);
            }
        }
        $keys = !empty($keys) ? array_unique($keys) : null;

        if ($keys !== null) {
            $placeholders = implode(",", array_fill(0, count($keys), "?"));
            $stmt = $conn->prepare("SELECT info_key, info_value FROM hostel_info WHERE info_key IN ({$placeholders})");
            if ($stmt) {
                $stmt->bind_param(str_repeat("s", count($keys)), ...$keys);
                $stmt->execute();
                $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $stmt->close();
                if (!empty($rows)) {
                    $parts = array_map(fn($r) => $r["info_value"], $rows);
                    $reply = implode(" ", $parts);
                    if ($language === "hi") return "Hostel details: {$reply}";
                    if ($language === "kn") return "Hostel details: {$reply}";
                    return $reply;
                }
            }
        }

        // Default: give a general hostel overview
        $stmt = $conn->prepare("SELECT info_key, info_value FROM hostel_info WHERE info_key IN ('warden_name','warden_contact','fee_per_year','hostel_timing') ORDER BY info_key");
        if (!$stmt) return self::localize("Could not fetch hostel details right now.", $language);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $map = [];
        foreach ($rows as $r) $map[$r["info_key"]] = $r["info_value"];

        $warden = ($map["warden_name"] ?? "") . ($map["warden_contact"] ? ", contact: " . $map["warden_contact"] : "");
        $fee    = $map["fee_per_year"] ? "Rs. " . number_format((int) $map["fee_per_year"]) . " per year" : "";
        $timing = $map["hostel_timing"] ?? "";

        if ($language === "hi") return "Hostel warden: {$warden}. Annual fee: {$fee}. {$timing} Apply karne ke liye ERP mein Registration → Hostel Application jaayein.";
        if ($language === "kn") return "Hostel warden: {$warden}. Annual fee: {$fee}. {$timing} Apply madalu ERP nalli Registration → Hostel Application ge hogi.";
        return "Hostel warden: {$warden}. Annual fee: {$fee}. {$timing} To apply, go to ERP → Registration → Hostel Application.";
    }

    public static function getFacultyDetails($query = "", $language = "en") {
        // Faculty details are on the public GMU website (Academics → Faculties),
        // not in the student ERP module.
        if ($language === "hi") {
            return "Faculty details student ERP mein available nahi hain. Yeh GMU website par Academics section mein Faculties page par milenge. "
                 . "GM University ke 6 faculties hain: Faculty of Engineering and Technology, Faculty of Computing and IT, "
                 . "Faculty of Basic and Applied Sciences, Faculty of Commerce and Management, "
                 . "GM School of Advanced Studies, aur GM Business School. "
                 . "Details ke liye gmu.ac.in par jaayein.";
        }
        if ($language === "kn") {
            return "Faculty details student ERP nalli illa. Avu GMU website nalli Academics vibhagada Faculties page nalli sigguttave. "
                 . "GM University nalli 6 faculties ive: Faculty of Engineering and Technology, Faculty of Computing and IT, "
                 . "Faculty of Basic and Applied Sciences, Faculty of Commerce and Management, "
                 . "GM School of Advanced Studies, mattu GM Business School. "
                 . "Hechchu maahiti ge gmu.ac.in nodi.";
        }
        return "Faculty details are not available in the student ERP module. You can find them on the GMU website under Academics → Faculties. "
             . "GM University has 6 faculties: Faculty of Engineering and Technology (FET), Faculty of Computing and IT (FCIT), "
             . "Faculty of Basic and Applied Sciences (FBAS), Faculty of Commerce and Management (FCM), "
             . "GM School of Advanced Studies (GMSAS), and GM Business School (GMBS). "
             . "Visit gmu.ac.in for full details.";
    }
    public static function getResultStatus($studentId, $query = "", $language = "en") {
        global $conn;
        $text = strtolower(trim((string) $query));

        // Explicit semester number takes priority ("semester 3 result").
        $specificSemester = StudentController::inferRequestedSemester($query);
        if ($specificSemester) {
            $stmt = $conn->prepare("
                SELECT semester, exam_type, academic_year, season, publication_status
                FROM result_publications
                WHERE student_id = ? AND semester = ?
                ORDER BY published_at DESC
                LIMIT 1
            ");
            if (!$stmt) return self::localize("System error while checking result status.", $language);
            $stmt->bind_param("ii", $studentId, $specificSemester);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$row) {
                return self::localize("I could not find a published result for semester {$specificSemester}.", $language);
            }
            $exam = strtoupper((string) ($row["exam_type"] ?? ""));
            $year = trim((string) ($row["academic_year"] ?? ""));
            $yearNote = $year !== "" ? " ({$year})" : "";
            return self::localize("Your semester {$specificSemester} {$exam} result is published{$yearNote}.", $language);
        }

        // "previous result" / "last result" → 2nd-most-recent. "latest/current" → newest.
        $wantPrevious = (bool) preg_match('/\b(previous|prev|last|before|prior|older)\b/', $text)
            && !(bool) preg_match('/\b(latest|most recent|current|this)\b/', $text);

        $stmt = $conn->prepare("
            SELECT semester, exam_type, academic_year, season, publication_status
            FROM result_publications
            WHERE student_id = ?
            ORDER BY semester DESC, published_at DESC
        ");
        if (!$stmt) return self::localize("System error while checking result status.", $language);
        $stmt->bind_param("i", $studentId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        if (empty($rows)) {
            return self::localize("I could not find any published result for your account right now.", $language);
        }

        $row = ($wantPrevious && count($rows) > 1) ? $rows[1] : $rows[0];
        $semester = (int) ($row["semester"] ?? 0);
        $exam = strtoupper((string) ($row["exam_type"] ?? ""));
        $year = trim((string) ($row["academic_year"] ?? ""));
        $yearNote = $year !== "" ? " ({$year})" : "";
        $label = $wantPrevious ? "previous published" : "latest published";

        return self::localize(
            "Your {$label} result is semester {$semester} {$exam}{$yearNote}.",
            $language
        );
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
        $latency = isset(self::$activeErpContext["start_ms"]) ? LoggerService::durationMs(self::$activeErpContext["start_ms"]) : null;
        LoggerService::info("erp_query_handle_completed", array_merge(self::$activeErpContext, [
            "status" => "success",
            "intent" => $intent,
            "route" => "database",
            "reply_source" => $source,
            "latency_ms" => $latency
        ]));
        if ($latency !== null) {
            LoggerService::markPerformance("erp_query_latency", $latency, [
                "intent" => $intent,
                "route" => "database"
            ]);
        }
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
        $dbStart = LoggerService::nowMs();
        $studentId = (int) ($session["student_id"] ?? 0);
        if ($studentId > 0) {
            LoggerService::info("db_student_id_from_session_completed", [
                "status" => "cache_hit",
                "latency_ms" => LoggerService::durationMs($dbStart)
            ]);
            return $studentId;
        }
        $userId = (int) ($session["user_id"] ?? 0);
        if ($userId <= 0) return 0;
        global $conn;
        $stmt = $conn->prepare("SELECT student_id FROM users WHERE user_id = ? AND is_active = 1 LIMIT 1");
        if (!$stmt) {
            LoggerService::error("db_student_id_prepare_failed", [
                "status" => "error",
                "latency_ms" => LoggerService::durationMs($dbStart)
            ]);
            return 0;
        }
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $latency = LoggerService::durationMs($dbStart);
        LoggerService::info("db_student_id_lookup_completed", [
            "status" => "success",
            "latency_ms" => $latency
        ]);
        LoggerService::markPerformance("db_student_id_lookup_latency", $latency);
        return (int) ($row["student_id"] ?? 0);
    }

    private static function getStudent($studentId) {
        $dbStart = LoggerService::nowMs();
        global $conn;
        $stmt = $conn->prepare("SELECT student_id, usn, full_name, branch, semester, quota FROM students WHERE student_id = ? LIMIT 1");
        if (!$stmt) {
            LoggerService::error("db_student_prepare_failed", [
                "status" => "error",
                "latency_ms" => LoggerService::durationMs($dbStart)
            ]);
            return null;
        }
        $stmt->bind_param("i", $studentId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $latency = LoggerService::durationMs($dbStart);
        LoggerService::info("db_student_lookup_completed", [
            "status" => $row ? "success" : "not_found",
            "latency_ms" => $latency
        ]);
        LoggerService::markPerformance("db_student_lookup_latency", $latency);
        return $row ?: null;
    }


    private static function canonicalIntent($intent) {
        $intent = strtoupper(trim((string) $intent));
        $aliases = [
            "GET_HALL_TICKET_STATUS" => "GET_HALLTICKET_STATUS",
            "GET_SUBJECTS" => "GET_COURSE_DETAILS"
        ];
        return $aliases[$intent] ?? $intent;
    }

    private static function isProfileQuery($text) {
        if (self::hasNavigationVerb($text) && self::hasAny($text, ["profile", "student profile"])) return false;
        return self::hasAny($text, [
            "profile summary",
            "my profile details",
            "my profile",
            "about my profile",
            "tell about my profile",
            "tell me about my profile",
            "student details",
            "my details",
            "personal details",
            "who am i",
            "what is my department",
            "which department",
            "what is my branch",
            "which branch",
            "which semester am i",
            "my semester",
            "profile batao",
            "profile torisu",
            "details batao",
            "details torisu",
            "profile dikhaao",
            "profile dikhao",
            "mere baare mein",
            "mera naam",
            // Kanglish
            "nanna profile",
            "nanna details",
            "nanna info",
            "nanna branch",
            "nanna department",
            "nanna semester eshtu",
            "nanna hesaru",
            // Hinglish
            "mera profile",
            "meri details",
            "mera branch",
            "apna profile"
        ]);
    }

    private static function isBacklogQuery($text) {
        return self::hasAny($text, [
            "backlog",
            "backlogs",
            "active backlog",
            "failed subject",
            "failed subjects",
            "arrear",
            "arrears",
            "supplementary",
            "supply",
            "pass or fail",
            "fail subject",
            "backlog batao",
            "backlog torisu",
            // Kanglish
            "nanna backlog",
            "fail aagide",
            "fail agide",
            "fail aaitu",
            "backlog eshtu",
            "backlog nodu",
            // Hinglish
            "mera backlog",
            "fail hua",
            "backlog hai kya",
            "kitne backlog"
        ]);
    }

    private static function isResultStatusQuery($text) {
        return self::hasAny($text, [
            "result status",
            "result published",
            "result available",
            "result released",
            "marks status",
            "sgpa status",
            "latest result status"
        ]);
    }

    private static function isCgpaQuery($text) {
        return self::hasAny($text, [
            "cgpa",
            "c g p a",
            "overall gpa",
            "cumulative gpa",
            "overall grade point",
            "overall grade",
            "total gpa",
            "cgpa batao",
            "cgpa torisu",
            // Kanglish
            "nanna cgpa",
            "cgpa nodu",
            "cgpa eshtu",
            // Hinglish
            "cgpa bolo",
            "mera cgpa",
            "overall cgpa"
        ]);
    }

    private static function isSgpaQuery($text) {
        if (self::hasNavigationVerb($text) && self::hasAny($text, ["result", "results"])) return false;
        return self::hasAny($text, [
            "sgpa",
            "s g p a",
            "semester gpa",
            "sem gpa",
            "semester result",
            "sem result",
            "latest result",
            "grade sheet",
            "grades",
            "my marks",
            "marks card",
            "sgpa batao",
            "sgpa torisu",
            // Kanglish
            "sheet torisu",
            "sheet nodu",
            "marks torisu",
            "marks nodu",
            "result torisu",
            "result nodu",
            "grade torisu",
            "nanna marks",
            "nanna result",
            "nanna sgpa",
            "marksheet torisu",
            "semester sheet",
            // Hinglish
            "result bolo",
            "result batao",
            "result dikhao",
            "result dekho",
            "mera result",
            "marks bolo",
            "marks batao",
            "marks dikhao",
            "meri marks",
            "sgpa batao",
            "sgpa bolo",
            "grade batao"
        ]);
    }

    private static function isCourseDetailsQuery($text) {
        return self::hasAny($text, [
            "my subjects",
            "what are my subjects",
            "show my subjects",
            "registered subjects",
            "subject list",
            "my courses",
            "registered courses",
            "course list",
            "course details",
            "subjects yavuvu",
            "subjects kya",
            "subjects torisu",
            "course torisu"
        ]);
    }

    private static function isSubjectAttendanceQuery($text) {
        $hasAttendance = self::hasAny($text, ["attendance", "attendence", "atendance", "hajari", "hajarati", "hajri", "haazri", "hazri"]);
        if ((bool) preg_match('/\b(attendance|attendence|atendance|hajari|hajarati)\s+(in|of|for)\b/u', $text)) return true;
        return self::hasAny($text, [
            "dbms",
            "d b m s",
            "database management",
            "operating system",
            "operating systems",
            "computer network",
            "computer networks",
            "artificial intelligence",
            "software engineering",
            "data structures",
            "java",
            "python",
            "maths",
            "mathematics",
            "english"
        ]);
    }

    private static function hasNavigationVerb($text) {
        return self::hasAny($text, [
            "open",
            "go to",
            "goto",
            "navigate",
            "take me",
            "show page",
            "open page",
            "page kholo",
            "page open",
            "page torisu",
            // Kanglish navigation
            "ge hogu",
            "page ge hogu",
            "hogu",
            "hogbeku",
            "open maadu",
            "open madu",
            "page open madu",
            "thumba click maadu",
            // Hinglish navigation
            "page khol",
            "page dikhao",
            "wahan jao",
            "le jao"
        ]);
    }

    private static function isFeeReceiptQuery($text) {
        return self::hasAny($text, ["receipt", "invoice", "download receipt", "payment receipt", "fee receipt"]);
    }

    private static function isFeePaymentNavigationQuery($text) {
        // English triggers
        if (self::hasAny($text, ["where can i pay", "how to pay", "pay my fees", "pay fees", "payment portal", "fee payment", "erp fee payment", "where is tuition fee payment", "how to pay hostel fees", "how to pay balance fees", "pay skill assessment", "late registration payment", "certificate fee payment", "other fee payment"]))
            return true;
        if (self::hasAny($text, ["where", "how", "pay", "payment"]) && self::hasAny($text, ["fee", "fees", "tuition", "hostel", "skill", "balance", "late registration", "certificate", "bonafide", "study certificate", "bank estimate", "photo copy", "photocopy", "breakage", "byoc", "malpractice", "pg application", "phd application", "admission"]))
            return true;
        // Hindi triggers
        if (self::hasAny($text, ["fee kaise pay kare", "fee kaise bharu", "fee kaha pay kare", "tuition fee pay karna", "hostel fee pay karna", "skill fee pay karna", "late registration fee", "certificate fee pay", "fee pay karna hai", "fees pay karna", "fee payment kaise kare", "fee kaise jama kare", "fee kahan bhari jati hai", "shulk kaise jama kare"]))
            return true;
        if (self::hasAny($text, ["kaise pay", "kaha pay", "pay karna", "payment kaise"]) && self::hasAny($text, ["fee", "fees", "tuition", "hostel", "skill", "certificate", "shulk"]))
            return true;
        // Kannada triggers
        if (self::hasAny($text, ["fee hege pay madu", "fee pay maduvage", "fee pay madbekagide", "tuition fee pay", "hostel fee pay", "skill fee pay", "late registration fee", "certificate fee pay", "fee elli pay madu", "fee payment hege madu", "feesu hege pay madu", "feesu pay madalu", "fee pay madodu hege"]))
            return true;
        if (self::hasAny($text, ["hege pay", "elli pay", "pay madalu", "pay maduvage", "pay madbekagide"]) && self::hasAny($text, ["fee", "feesu", "tuition", "hostel", "skill", "certificate"]))
            return true;
        return false;
    }

    private static function isFeeBalanceQuery($text) {
        return self::hasAny($text, ["fee balance", "fees balance", "remaining fees", "remaining fee", "pending fee", "pending fees", "due amount", "unpaid fees", "balance fee", "balance fees", "fee due", "fees due", "hostel fee balance", "tuition fee pending", "skill fee due", "baki fee", "bakki fees", "feesu balance",
            "my balance", "balance amount", "what is my balance", "how much balance", "balance left", "tell my balance", "how much do i owe", "how much i owe", "what do i owe"])
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
        // Translate common ERP status phrases so reply language matches student's language.
        static $translations = [
            "hi" => [
                "I could not find any published result for your account right now."
                    => "Abhi aapke account mein koi published result nahi mila.",
                "System error while checking result status."
                    => "Result status check karte waqt system error aaya.",
                "Your latest published result is semester"
                    => "Aapka latest published result semester",
                "Your previous published result is semester"
                    => "Aapka previous published result semester",
                "Your semester"                => "Aapka semester",
                "result is published"          => "result published hai",
                "I could not find a published result for semester"
                    => "Semester ke liye koi published result nahi mila",
                "This ERP detail is available only after student login."
                    => "Yeh ERP detail student login ke baad hi milegi.",
            ],
            "kn" => [
                "I could not find any published result for your account right now."
                    => "Ivaga nimma account nalli published result sikalilla.",
                "System error while checking result status."
                    => "Result status check maduvaga system error aayitu.",
                "Your latest published result is semester"
                    => "Nimma latest published result semester",
                "Your previous published result is semester"
                    => "Nimma previous published result semester",
                "Your semester"                => "Nimma semester",
                "result is published"          => "result published aagide",
                "I could not find a published result for semester"
                    => "Semester ge published result sikalilla",
                "This ERP detail is available only after student login."
                    => "ERP detail student login aada nantara mattey sikutte.",
            ],
        ];

        if (isset($translations[$language])) {
            foreach ($translations[$language] as $en => $local) {
                $reply = str_replace($en, $local, (string) $reply);
            }
        }
        return $reply;
    }

    private static function spokenDate($date) {
        $timestamp = strtotime((string) $date);
        return $timestamp ? date('j F Y', $timestamp) : (string) $date;
    }
}
