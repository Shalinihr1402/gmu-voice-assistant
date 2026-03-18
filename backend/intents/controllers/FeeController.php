<?php

require_once __DIR__ . "/../../config/db.php";

class FeeController {

    private static function getFeeRows($student_id) {
        global $conn;

        $quotaStmt = $conn->prepare("
            SELECT quota
            FROM students
            WHERE student_id = ?
        ");

        if (!$quotaStmt) {
            return [
                "error" => "System error while fetching student details."
            ];
        }

        $quotaStmt->bind_param("i", $student_id);
        $quotaStmt->execute();
        $quotaResult = $quotaStmt->get_result()->fetch_assoc();
        $quotaStmt->close();

        if (!$quotaResult) {
            return [
                "error" => "Student record not found."
            ];
        }

        $quota = $quotaResult['quota'];

        $stmt = $conn->prepare("
            SELECT
                fs.fee_id,
                fs.fee_type,
                fs.total_fee,
                IFNULL(SUM(sp.amount_paid), 0) AS paid
            FROM fee_structure fs
            LEFT JOIN student_payments sp
                ON fs.fee_id = sp.fee_id
                AND sp.student_id = ?
            WHERE fs.quota = ?
            GROUP BY fs.fee_id, fs.fee_type, fs.total_fee
        ");

        if (!$stmt) {
            return [
                "error" => "System error while fetching fee structure."
            ];
        }

        $stmt->bind_param("is", $student_id, $quota);
        $stmt->execute();
        $result = $stmt->get_result();

        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $row['total_fee'] = (float) $row['total_fee'];
            $row['paid'] = (float) $row['paid'];
            $row['balance'] = max(0, $row['total_fee'] - $row['paid']);
            $rows[] = $row;
        }

        $stmt->close();

        return [
            "rows" => $rows
        ];
    }

    public static function getFeeBalance($student_id) {
        $feeData = self::getFeeRows($student_id);
        if (isset($feeData["error"])) {
            return $feeData["error"];
        }

        $programFee = 0;
        $skillFee = 0;
        $totalFee = 0;
        $totalPaid = 0;
        foreach ($feeData["rows"] as $row) {
            $totalFee += $row['total_fee'];
            $totalPaid += $row['paid'];

            if (stripos($row['fee_type'], "program") !== false) {
                $programFee += $row['total_fee'];
            }

            if (stripos($row['fee_type'], "skill") !== false) {
                $skillFee += $row['total_fee'];
            }
        }
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

    public static function getFinalRegistrationStatus($student_id) {
        $feeData = self::getFeeRows($student_id);
        if (isset($feeData["error"])) {
            return $feeData["error"];
        }

        $rows = $feeData["rows"];
        if (empty($rows)) {
            return "I could not find your registration payment details.";
        }

        $pendingItems = [];
        $totalBalance = 0;

        foreach ($rows as $row) {
            if ($row["balance"] > 0) {
                $pendingItems[] = $row["fee_type"] . " balance of Rs. " . number_format($row["balance"], 2);
                $totalBalance += $row["balance"];
            }
        }

        if ($totalBalance <= 0) {
            return "Your course registration is complete and your final registration is also completed successfully. There is no pending fee balance.";
        }

        $pendingSummary = implode(", ", array_slice($pendingItems, 0, 3));

        return "Your course registration is complete, but your final registration is still pending because you have an outstanding balance of Rs. " . number_format($totalBalance, 2) . ". Pending items include " . $pendingSummary . ". Please clear the balance to complete final registration.";
    }
}
