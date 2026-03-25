<?php

require_once __DIR__ . "/../config/db.php";

class UserService {

    public static function getCurrentUserContext($userId) {
        global $conn;

        $stmt = $conn->prepare("
            SELECT
                u.user_id,
                u.login_id,
                r.role_key,
                r.role_name,
                u.student_id,
                u.staff_id,
                COALESCE(s.full_name, sm.full_name) AS full_name,
                COALESCE(s.email, sm.email) AS email,
                COALESCE(s.mobile_no, sm.mobile_no) AS mobile_no,
                s.branch AS branch_name,
                s.semester AS semester,
                COALESCE(s.branch, d.department_name) AS unit_name,
                sm.designation
            FROM users u
            INNER JOIN roles r ON r.role_id = u.role_id
            LEFT JOIN students s ON s.student_id = u.student_id
            LEFT JOIN staff_members sm ON sm.staff_id = u.staff_id
            LEFT JOIN departments d ON d.department_id = sm.department_id
            WHERE u.user_id = ? AND u.is_active = 1
        ");

        if (!$stmt) {
            return null;
        }

        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $result ?: null;
    }
}
