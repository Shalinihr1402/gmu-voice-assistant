<?php

require_once __DIR__ . "/../../config/db.php";

class FeeController {

    public static function getFeeBalance($student_id) {
        global $conn;

        // 1️⃣ Get student's quota
        $quotaStmt = $conn->prepare("
            SELECT quota 
            FROM students 
            WHERE student_id = ?
        ");

        if (!$quotaStmt) {
            return "System error while fetching student details.";
        }

        $quotaStmt->bind_param("i", $student_id);
        $quotaStmt->execute();
        $quotaResult = $quotaStmt->get_result()->fetch_assoc();
        $quotaStmt->close();

        if (!$quotaResult) {
            return "Student record not found.";
        }

        $quota = $quotaResult['quota'];

        // 2️⃣ Get fee structure for that quota
        $feeStmt = $conn->prepare("
            SELECT fee_id, fee_type, total_fee
            FROM fee_structure
            WHERE quota = ?
        ");

        if (!$feeStmt) {
            return "System error while fetching fee structure.";
        }

        $feeStmt->bind_param("s", $quota);
        $feeStmt->execute();
        $feeResult = $feeStmt->get_result();

        $programFee = 0;
        $skillFee = 0;
        $totalFee = 0;

        while ($row = $feeResult->fetch_assoc()) {

            $totalFee += (float)$row['total_fee'];

            if (stripos($row['fee_type'], "program") !== false) {
                $programFee += (float)$row['total_fee'];
            }

            if (stripos($row['fee_type'], "skill") !== false) {
                $skillFee += (float)$row['total_fee'];
            }
        }

        $feeStmt->close();

        // 3️⃣ Get total amount paid by student
        $paidStmt = $conn->prepare("
            SELECT IFNULL(SUM(amount_paid),0) AS paid
            FROM student_payments
            WHERE student_id = ?
        ");

        if (!$paidStmt) {
            return "System error while fetching payment details.";
        }

        $paidStmt->bind_param("i", $student_id);
        $paidStmt->execute();
        $paidResult = $paidStmt->get_result()->fetch_assoc();
        $paidStmt->close();

        $totalPaid = (float)$paidResult['paid'];
        $balance = $totalFee - $totalPaid;

        if ($balance < 0) {
            $balance = 0;
        }

        // 4️⃣ Generate natural response
        $reply = "Here is your fee summary. ";

        $reply .= "Your program fee is ₹" . number_format($programFee, 2) . ". ";

        if ($skillFee > 0) {
            $reply .= "Your skill development fee is ₹" . number_format($skillFee, 2) . ". ";
        }

        $reply .= "Your total academic fee is ₹" . number_format($totalFee, 2) . ". ";

        $reply .= "You have paid ₹" . number_format($totalPaid, 2) . ". ";

        if ($balance > 0) {
            $reply .= "Your remaining balance is ₹" . number_format($balance, 2) . ".";
        } else {
            $reply .= "You have cleared all your fees. Well done.";
        }

        return $reply;
    }
}
