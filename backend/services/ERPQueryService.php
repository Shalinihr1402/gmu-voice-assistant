<?php

require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../intents/controllers/StudentController.php";
require_once __DIR__ . "/../intents/controllers/FeeController.php";

class ERPQueryService {
    public static function detectIntent($query, $language = "en") {
        $text = self::normalizeText($query);
        if ($text === "") return "";

        if (self::isPaymentGrievanceQuery($text)) return "GET_PAYMENT_GRIEVANCE";
        if (self::isGrievanceProcessQuery($text)) return "GET_GRIEVANCE_PROCESS";
        if (self::hasAny($text, ["last working day", "last class day", "working day last", "last day of class", "classes end", "college last working", "kone working day", "last working dinanka"])) return "GET_LAST_WORKING_DAY";
        if (self::isFeeDeadlineQuery($text)) return "GET_LAST_DATE_FEES";
        if (self::hasAny($text, ["hostel fee", "hostel fees", "hostel balance", "hostel amount", "hostel charge", "hostel shulk", "hostel feesu"])) return "GET_HOSTEL_FEES";
        if (self::hasAny($text, ["tuition fee", "tuition fees", "college fee", "program fee", "academic fee", "course fee", "tuition balance", "tuition amount"])) return "GET_TUITION_FEES";
        if (self::hasAny($text, ["fee balance", "fees balance", "fee pending", "fees pending", "pending fee", "pending fees", "amount due", "balance fee", "balance fees", "fee due", "fees due", "my fees", "my fee", "baki fee", "bakki fees", "feesu balance"])) return "GET_FEES_BALANCE";
        if (self::hasAny($text, ["subject code", "subject codes", "course code", "course codes", "codes of subjects", "code for subject", "code of subject"])) return "GET_SUBJECT_CODES";
        if (self::hasAny($text, ["my subjects", "what are my subjects", "show my subjects", "registered subjects", "subject list", "my courses", "registered courses", "course list", "subjects yavuvu", "subjects kya", "subjects torisu"])) return "GET_SUBJECTS";
        if (self::hasAny($text, ["attendance", "attendence", "atendance", "attendance percentage", "overall attendance", "hajari", "hajarati"])) return "GET_ATTENDANCE";
        if (self::hasAny($text, ["usn", "u s n", "registration number", "university number", "my usn", "usn number"])) return "GET_USN";
        if (self::hasAny($text, ["result status", "result published", "result available", "result released", "marks status", "sgpa status", "latest result status"])) return "GET_RESULT_STATUS";
        if (self::hasAny($text, ["hall ticket", "hallticket", "admit card", "generate hall ticket", "hall ticket generated", "download hall ticket", "exam ticket"])) return "GET_HALLTICKET_STATUS";
        if (self::hasAny($text, ["final registration", "registration completed", "registration complete", "registration status", "registered or not", "am i registered", "have i registered", "course registration completed"])) return "GET_FINAL_REGISTRATION_STATUS";

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
                return self::payload(self::getFeesBalance($studentId, $language), $intent, $language, "fees_balance");
            case "GET_ATTENDANCE":
                return self::payload(self::getAttendance($studentId, $query, $language), $intent, $language, "attendance");
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
            case "GET_FINAL_REGISTRATION_STATUS":
                return self::payload(FeeController::getFinalRegistrationStatus($studentId, $language), $intent, $language, "final_registration");
            case "GET_HOSTEL_FEES":
                return self::payload(self::getHostelFees($studentId, $language), $intent, $language, "hostel_fees");
            case "GET_TUITION_FEES":
                return self::payload(self::getTuitionFees($studentId, $language), $intent, $language, "tuition_fees");
            case "GET_LAST_DATE_FEES":
                return self::payload(self::getFeeDeadline($language), $intent, $language, "fee_deadline");
            case "GET_LAST_WORKING_DAY":
                return self::payload(self::getLastWorkingDay($language), $intent, $language, "last_working_day");
            case "GET_GRIEVANCE_PROCESS":
                return self::payload(self::grievanceProcessReply($language), $intent, $language, "grievance_process");
            case "GET_PAYMENT_GRIEVANCE":
                return self::payload(self::paymentGrievanceReply($language), $intent, $language, "payment_grievance");
        }

        return null;
    }

    public static function getFeesBalance($studentId, $language = "en") {
        return FeeController::getFeeBalance($studentId, $language);
    }

    public static function getAttendance($studentId, $query = "", $language = "en") {
        $text = self::normalizeText($query);
        $hasSubjectPhrase = (bool) preg_match('/\b(attendance|attendence|atendance)\s+(in|of|for)\b|\b(dbms|database management|operating systems|computer networks|artificial intelligence|software engineering|java|data structures)\b/u', $text);
        if ($hasSubjectPhrase) {
            return StudentController::getSubjectAttendance($studentId, $query, $language);
        }
        return StudentController::getAttendance($studentId, $language);
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

    private static function payload($reply, $intent, $language, $source) {
        error_log("ERP QUERY RESPONSE: intent={$intent}; source={$source}");
        return [
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
    }

    private static function requiresStudent($intent) {
        return !in_array($intent, ["GET_LAST_DATE_FEES", "GET_LAST_WORKING_DAY", "GET_GRIEVANCE_PROCESS", "GET_PAYMENT_GRIEVANCE"], true);
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

    private static function isFeeDeadlineQuery($text) {
        return self::hasAny($text, ["last date", "deadline", "due date", "when pay", "pay by", "kab tak", "akhri tarikh", "last dinanka"]) && self::hasAny($text, ["fee", "fees", "tuition", "payment", "shulk", "feesu"]);
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