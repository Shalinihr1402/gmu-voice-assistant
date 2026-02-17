<?php

require_once __DIR__ . "/../../config/db.php";

class FeeController {

    public static function getFeeBalance($student_id) {
        global $conn;

        $sql = "
            SELECT 
                f.total_fee,
                IFNULL(SUM(p.amount_paid),0) AS paid
            FROM students s
            JOIN fee_structure f ON s.quota = f.quota
            LEFT JOIN student_payments p ON s.student_id = p.student_id
            WHERE s.student_id=?
            GROUP BY f.total_fee
        ";

        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $student_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();

        if (!$row) {
            return "No fee information found.";
        }

        $balance = $row['total_fee'] - $row['paid'];

        return "Your remaining fee balance is ₹" . $balance;
    }
}
