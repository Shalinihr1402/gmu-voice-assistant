<?php

/**
 * ErpAdapter — THE ONLY FILE YOU CHANGE when connecting to a new ERP system.
 *
 * Every controller in this project calls methods on this class.
 * If your ERP changes its API, field names, or URL structure — edit ONLY here.
 *
 * HOW TO SWITCH FROM LOCAL DB → REAL ERP:
 *   1. Set ERP_MODE=api in backend/.env
 *   2. Set ERP_API_BASE_URL to your ERP's base URL
 *   3. Set ERP_API_KEY or ERP_API_TOKEN if required
 *   4. Edit each METHOD BELOW to match your ERP's actual endpoint and response field names
 *
 * Each method has a clearly marked section:
 *   [LOCAL DB]  — current implementation using local MySQL
 *   [ERP API]   — replace this section with your ERP's real API call + field mapping
 */

require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../config/env.php";

class ErpAdapter {

    // ─── Config ────────────────────────────────────────────────────────────────

    private static function mode(): string {
        $m = getenv("ERP_MODE") ?: ($_ENV["ERP_MODE"] ?? "local");
        return strtolower(trim($m));
    }

    private static function baseUrl(): string {
        return rtrim(getenv("ERP_API_BASE_URL") ?: ($_ENV["ERP_API_BASE_URL"] ?? ""), "/");
    }

    private static function apiKey(): string {
        return getenv("ERP_API_KEY") ?: ($_ENV["ERP_API_KEY"] ?? "");
    }

    // ─── HTTP helper ───────────────────────────────────────────────────────────

    /**
     * Make an HTTP GET or POST call to the real ERP API.
     * Returns decoded array on success, null on failure.
     */
    private static function erpCall(string $endpoint, array $params = [], string $method = "GET"): ?array {
        $url = self::baseUrl() . "/" . ltrim($endpoint, "/");
        $ch  = curl_init();

        $headers = ["Accept: application/json"];
        $key = self::apiKey();
        if ($key !== "") {
            // ── EDIT THIS ──────────────────────────────────────────────────
            // Change header name to match your ERP's auth scheme, e.g.:
            //   "Authorization: Bearer {$key}"
            //   "X-API-Key: {$key}"
            //   "token: {$key}"
            $headers[] = "Authorization: Bearer {$key}";
            // ──────────────────────────────────────────────────────────────
        }

        if ($method === "POST") {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params));
            $headers[] = "Content-Type: application/json";
        } else {
            if (!empty($params)) {
                $url .= "?" . http_build_query($params);
            }
        }

        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);

        $raw    = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false || $status < 200 || $status >= 300) return null;
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    // ══════════════════════════════════════════════════════════════════════════
    //  1. LOGIN / AUTHENTICATE
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Verify student credentials and return normalized student record.
     *
     * Returns array with keys:
     *   user_id, student_id, full_name, usn, branch, semester, email, quota, role
     * Returns null if credentials are wrong or student not found.
     */
    public static function login(string $loginId, string $password): ?array {
        if (self::mode() === "api") {
            // ── EDIT THIS BLOCK ────────────────────────────────────────────
            // Replace endpoint, field names, and param keys to match your ERP.
            //
            // Example — ERP with POST /api/auth/login:
            //   $data = self::erpCall("api/auth/login", [
            //       "username" => $loginId,   // or "usn", "aadhaar", "email"
            //       "password" => $password
            //   ], "POST");
            //
            // Then map ERP response fields → voicebot fields:
            //   $student = $data["student"] ?? $data["data"] ?? $data;
            //   return [
            //       "user_id"    => $student["id"]          ?? 0,
            //       "student_id" => $student["student_id"]  ?? $student["id"] ?? 0,
            //       "full_name"  => $student["name"]        ?? $student["full_name"] ?? "",
            //       "usn"        => $student["usn"]         ?? $student["reg_no"] ?? "",
            //       "branch"     => $student["branch"]      ?? $student["department"] ?? "",
            //       "semester"   => (int)($student["semester"] ?? $student["sem"] ?? 1),
            //       "email"      => $student["email"]       ?? "",
            //       "quota"      => $student["quota"]       ?? "GENERAL",
            //       "role"       => "student",
            //   ];
            // ──────────────────────────────────────────────────────────────
            return null; // remove this line once implemented
        }

        // [LOCAL DB] ─────────────────────────────────────────────────────────
        global $conn;
        $stmt = $conn->prepare("
            SELECT u.user_id, u.student_id, u.role_id,
                   s.full_name, s.usn, s.branch, s.semester, s.email, s.quota
            FROM users u
            LEFT JOIN students s ON s.student_id = u.student_id
            WHERE u.login_id = ? AND u.password_text = ? AND u.is_active = 1
        ");
        if (!$stmt) return null;
        $stmt->bind_param("ss", $loginId, $password);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) return null;
        return [
            "user_id"    => (int) $row["user_id"],
            "student_id" => (int) $row["student_id"],
            "full_name"  => (string) $row["full_name"],
            "usn"        => (string) $row["usn"],
            "branch"     => (string) $row["branch"],
            "semester"   => (int) $row["semester"],
            "email"      => (string) $row["email"],
            "quota"      => (string) $row["quota"],
            "role"       => "student",
        ];
    }

    // ══════════════════════════════════════════════════════════════════════════
    //  2. STUDENT PROFILE
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Returns array with keys:
     *   full_name, usn, branch, semester, email, mobile_no, quota, photo
     */
    public static function getProfile(int $studentId): ?array {
        if (self::mode() === "api") {
            // ── EDIT THIS BLOCK ────────────────────────────────────────────
            // $data = self::erpCall("api/students/{$studentId}/profile");
            // $s = $data["data"] ?? $data;
            // return [
            //     "full_name"  => $s["name"]        ?? "",
            //     "usn"        => $s["usn"]          ?? $s["reg_no"] ?? "",
            //     "branch"     => $s["branch"]       ?? $s["department"] ?? "",
            //     "semester"   => (int)($s["semester"] ?? 1),
            //     "email"      => $s["email"]        ?? "",
            //     "mobile_no"  => $s["mobile"]       ?? $s["phone"] ?? "",
            //     "quota"      => $s["quota"]        ?? "GENERAL",
            //     "photo"      => $s["photo_url"]    ?? null,
            // ];
            // ──────────────────────────────────────────────────────────────
            return null;
        }

        // [LOCAL DB] ─────────────────────────────────────────────────────────
        global $conn;
        $stmt = $conn->prepare("SELECT full_name, usn, branch, semester, email, mobile_no, quota, photo FROM students WHERE student_id = ?");
        if (!$stmt) return null;
        $stmt->bind_param("i", $studentId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }

    // ══════════════════════════════════════════════════════════════════════════
    //  3. ATTENDANCE
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Returns array of subjects:
     *   [ ["subject" => "DBMS", "total" => 40, "attended" => 34, "percentage" => 85.0], ... ]
     */
    public static function getAttendance(int $studentId, ?int $semester = null): array {
        if (self::mode() === "api") {
            // ── EDIT THIS BLOCK ────────────────────────────────────────────
            // $params = ["student_id" => $studentId];
            // if ($semester) $params["semester"] = $semester;
            // $data = self::erpCall("api/attendance", $params);
            // $rows = $data["data"] ?? $data["attendance"] ?? $data ?? [];
            // $result = [];
            // foreach ($rows as $row) {
            //     $result[] = [
            //         "subject"    => $row["subject_name"] ?? $row["course_name"] ?? "",
            //         "total"      => (int)($row["total_classes"]    ?? $row["total"] ?? 0),
            //         "attended"   => (int)($row["attended_classes"] ?? $row["attended"] ?? 0),
            //         "percentage" => (float)($row["attendance_percentage"] ?? $row["percentage"] ?? 0),
            //     ];
            // }
            // return $result;
            // ──────────────────────────────────────────────────────────────
            return [];
        }

        // [LOCAL DB] ─────────────────────────────────────────────────────────
        global $conn;
        $sql = "
            SELECT c.course_title AS subject, a.total_classes AS total,
                   a.attended_classes AS attended, a.percentage
            FROM attendance a
            JOIN courses c ON c.course_id = a.course_id
            WHERE a.student_id = ?
        ";
        $params = [$studentId];
        $types  = "i";
        if ($semester !== null) {
            $sql    .= " AND c.semester = ?";
            $params[] = $semester;
            $types   .= "i";
        }
        $stmt = $conn->prepare($sql);
        if (!$stmt) return [];
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }

    // ══════════════════════════════════════════════════════════════════════════
    //  4. RESULTS
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Returns array of subject results for a semester:
     *   [ ["subject" => "DBMS", "credits" => 4.0, "grade_point" => 8.0], ... ]
     */
    public static function getResults(int $studentId, int $semester): array {
        if (self::mode() === "api") {
            // ── EDIT THIS BLOCK ────────────────────────────────────────────
            // $data = self::erpCall("api/results/{$studentId}", ["semester" => $semester]);
            // $rows = $data["data"] ?? $data["results"] ?? $data ?? [];
            // $result = [];
            // foreach ($rows as $row) {
            //     $result[] = [
            //         "subject"     => $row["subject_name"] ?? $row["course_name"] ?? "",
            //         "credits"     => (float)($row["credits"] ?? 4.0),
            //         "grade_point" => (float)($row["grade_point"] ?? $row["gp"] ?? 0),
            //     ];
            // }
            // return $result;
            // ──────────────────────────────────────────────────────────────
            return [];
        }

        // [LOCAL DB] ─────────────────────────────────────────────────────────
        global $conn;
        $stmt = $conn->prepare("
            SELECT c.course_title AS subject, r.credits, r.grade_point
            FROM results r
            JOIN courses c ON c.course_id = r.course_id
            WHERE r.student_id = ? AND r.semester = ?
        ");
        if (!$stmt) return [];
        $stmt->bind_param("ii", $studentId, $semester);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }

    /**
     * Returns list of semesters that have published results for the student.
     * [ 1, 2, 3, 5 ]
     */
    public static function getPublishedSemesters(int $studentId): array {
        if (self::mode() === "api") {
            // ── EDIT THIS BLOCK ────────────────────────────────────────────
            // $data = self::erpCall("api/results/{$studentId}/published");
            // $rows = $data["data"] ?? $data["semesters"] ?? [];
            // return array_map("intval", array_column($rows, "semester"));
            // ──────────────────────────────────────────────────────────────
            return [];
        }

        // [LOCAL DB] ─────────────────────────────────────────────────────────
        global $conn;
        $stmt = $conn->prepare("
            SELECT DISTINCT semester FROM result_publications
            WHERE student_id = ? AND publication_status = 'PUBLISHED'
            ORDER BY semester
        ");
        if (!$stmt) return [];
        $stmt->bind_param("i", $studentId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return array_map(fn($r) => (int) $r["semester"], $rows);
    }

    // ══════════════════════════════════════════════════════════════════════════
    //  5. FEES
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Returns fee summary:
     *   [
     *     "rows"    => [ ["fee_type"=>"Program Fee","total"=>85000,"paid"=>50000], ... ],
     *     "total"   => 100500.00,
     *     "paid"    => 63000.00,
     *     "pending" => 37500.00,
     *   ]
     */
    public static function getFees(int $studentId, string $quota = "GENERAL"): array {
        if (self::mode() === "api") {
            // ── EDIT THIS BLOCK ────────────────────────────────────────────
            // $data = self::erpCall("api/fees/{$studentId}");
            // $rows = $data["data"] ?? $data["fees"] ?? [];
            // $result = [];
            // $totalAmt = 0; $paidAmt = 0;
            // foreach ($rows as $row) {
            //     $total = (float)($row["amount"]     ?? $row["total_fee"] ?? 0);
            //     $paid  = (float)($row["paid"]       ?? $row["amount_paid"] ?? 0);
            //     $totalAmt += $total; $paidAmt += $paid;
            //     $result[] = [
            //         "fee_type" => $row["fee_type"]    ?? $row["description"] ?? "",
            //         "total"    => $total,
            //         "paid"     => $paid,
            //     ];
            // }
            // return ["rows" => $result, "total" => $totalAmt, "paid" => $paidAmt, "pending" => $totalAmt - $paidAmt];
            // ──────────────────────────────────────────────────────────────
            return ["rows" => [], "total" => 0, "paid" => 0, "pending" => 0];
        }

        // [LOCAL DB] ─────────────────────────────────────────────────────────
        global $conn;
        $stmt = $conn->prepare("
            SELECT fs.fee_type, fs.total_fee AS total,
                   COALESCE(SUM(sp.amount_paid), 0) AS paid
            FROM fee_structure fs
            LEFT JOIN student_payments sp
                   ON sp.fee_id = fs.fee_id AND sp.student_id = ?
            WHERE fs.quota = ?
            GROUP BY fs.fee_id
        ");
        if (!$stmt) return ["rows" => [], "total" => 0, "paid" => 0, "pending" => 0];
        $stmt->bind_param("is", $studentId, $quota);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        $total = array_sum(array_column($rows, "total"));
        $paid  = array_sum(array_column($rows, "paid"));
        return ["rows" => $rows, "total" => $total, "paid" => $paid, "pending" => $total - $paid];
    }

    // ══════════════════════════════════════════════════════════════════════════
    //  6. HALL TICKET
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Returns:
     *   ["status" => "GENERATED"|"NOT_APPROVED"|"PENDING", "message" => "...", "generated_at" => "..."]
     */
    public static function getHallTicket(int $studentId, int $semester): ?array {
        if (self::mode() === "api") {
            // ── EDIT THIS BLOCK ────────────────────────────────────────────
            // $data = self::erpCall("api/hall-ticket/{$studentId}", ["semester" => $semester]);
            // $ht = $data["data"] ?? $data;
            // return [
            //     "status"       => strtoupper($ht["status"] ?? "PENDING"),
            //     "message"      => $ht["message"]      ?? $ht["remarks"] ?? "",
            //     "generated_at" => $ht["generated_at"] ?? $ht["issued_date"] ?? null,
            // ];
            // ──────────────────────────────────────────────────────────────
            return null;
        }

        // [LOCAL DB] ─────────────────────────────────────────────────────────
        global $conn;
        $stmt = $conn->prepare("
            SELECT status, status_message AS message, generated_at
            FROM hall_tickets WHERE student_id = ? AND semester = ?
            ORDER BY hall_ticket_id DESC LIMIT 1
        ");
        if (!$stmt) return null;
        $stmt->bind_param("ii", $studentId, $semester);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }

    // ══════════════════════════════════════════════════════════════════════════
    //  7. TIMETABLE / COURSES
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Returns enrolled courses for the student's current semester:
     *   [ ["code" => "CS501", "title" => "DBMS", "credits" => 4.0, "type" => "Core"], ... ]
     */
    public static function getCourses(int $studentId, int $semester): array {
        if (self::mode() === "api") {
            // ── EDIT THIS BLOCK ────────────────────────────────────────────
            // $data = self::erpCall("api/timetable/{$studentId}", ["semester" => $semester]);
            // $rows = $data["data"] ?? $data["courses"] ?? [];
            // $result = [];
            // foreach ($rows as $row) {
            //     $result[] = [
            //         "code"    => $row["course_code"] ?? $row["code"] ?? "",
            //         "title"   => $row["course_name"] ?? $row["title"] ?? "",
            //         "credits" => (float)($row["credits"] ?? 4.0),
            //         "type"    => $row["course_type"]  ?? $row["type"] ?? "Core",
            //     ];
            // }
            // return $result;
            // ──────────────────────────────────────────────────────────────
            return [];
        }

        // [LOCAL DB] ─────────────────────────────────────────────────────────
        global $conn;
        $stmt = $conn->prepare("
            SELECT course_code AS code, course_title AS title, credits, course_type AS type
            FROM courses WHERE semester = ?
        ");
        if (!$stmt) return [];
        $stmt->bind_param("i", $semester);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }

    // ══════════════════════════════════════════════════════════════════════════
    //  8. HOSTEL
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Returns:
     *   ["status" => "approved"|"pending"|"not_applied", "remarks" => "..."]
     */
    public static function getHostel(int $studentId): ?array {
        if (self::mode() === "api") {
            // ── EDIT THIS BLOCK ────────────────────────────────────────────
            // $data = self::erpCall("api/hostel/{$studentId}");
            // $h = $data["data"] ?? $data;
            // return [
            //     "status"  => strtolower($h["status"]  ?? "not_applied"),
            //     "remarks" => $h["remarks"] ?? $h["message"] ?? "",
            // ];
            // ──────────────────────────────────────────────────────────────
            return null;
        }

        // [LOCAL DB] ─────────────────────────────────────────────────────────
        global $conn;
        $stmt = $conn->prepare("SELECT status, remarks FROM hostel_applications WHERE student_id = ? ORDER BY application_id DESC LIMIT 1");
        if (!$stmt) return null;
        $stmt->bind_param("i", $studentId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: ["status" => "not_applied", "remarks" => ""];
    }

    // ══════════════════════════════════════════════════════════════════════════
    //  9. SUPPORT TICKETS
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Create a support ticket. Returns ticket code string or null on failure.
     */
    public static function createTicket(int $studentId, string $usn, string $issueType, string $description): ?string {
        if (self::mode() === "api") {
            // ── EDIT THIS BLOCK ────────────────────────────────────────────
            // $data = self::erpCall("api/support/tickets", [
            //     "student_id"  => $studentId,
            //     "usn"         => $usn,
            //     "issue_type"  => $issueType,
            //     "description" => $description,
            // ], "POST");
            // return $data["ticket_code"] ?? $data["ticket_id"] ?? null;
            // ──────────────────────────────────────────────────────────────
            return null;
        }

        // [LOCAL DB] ─────────────────────────────────────────────────────────
        global $conn;
        $code = "TKT" . strtoupper(substr(md5(uniqid()), 0, 8));
        $stmt = $conn->prepare("INSERT INTO support_tickets (ticket_code, student_id, usn, issue_type, issue_description) VALUES (?,?,?,?,?)");
        if (!$stmt) return null;
        $stmt->bind_param("sisss", $code, $studentId, $usn, $issueType, $description);
        $stmt->execute();
        $stmt->close();
        return $code;
    }

    /**
     * Get open tickets for a student.
     * Returns [ ["ticket_code" => "TKT...", "issue_type" => "...", "status" => "open", "created_at" => "..."], ... ]
     */
    public static function getTickets(int $studentId): array {
        if (self::mode() === "api") {
            // ── EDIT THIS BLOCK ────────────────────────────────────────────
            // $data = self::erpCall("api/support/tickets/{$studentId}");
            // $rows = $data["data"] ?? $data["tickets"] ?? [];
            // return array_map(fn($t) => [
            //     "ticket_code" => $t["ticket_code"] ?? $t["id"] ?? "",
            //     "issue_type"  => $t["issue_type"]  ?? $t["category"] ?? "",
            //     "status"      => $t["status"]      ?? "open",
            //     "created_at"  => $t["created_at"]  ?? $t["date"] ?? "",
            // ], $rows);
            // ──────────────────────────────────────────────────────────────
            return [];
        }

        // [LOCAL DB] ─────────────────────────────────────────────────────────
        global $conn;
        $stmt = $conn->prepare("SELECT ticket_code, issue_type, status, created_at FROM support_tickets WHERE student_id = ? ORDER BY created_at DESC LIMIT 5");
        if (!$stmt) return [];
        $stmt->bind_param("i", $studentId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }
}
