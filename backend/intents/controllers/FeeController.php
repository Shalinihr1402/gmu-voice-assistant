<?php

require_once __DIR__ . "/../../config/db.php";

class FeeController {

    private static function normalizeLanguage($language) {
        $normalized = strtolower(trim((string) $language));
        if (in_array($normalized, ["kn", "kannada", "kn-in"], true)) return "kn";
        if (in_array($normalized, ["hi", "hindi", "hi-in"], true)) return "hi";
        return "en";
    }

    private static function isKannada($language) {
        return self::normalizeLanguage($language) === "kn";
    }

    private static function isHindi($language) {
        return self::normalizeLanguage($language) === "hi";
    }

    private static function money($amount) {
        return number_format((float) $amount, 2);
    }

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

        $quota = $quotaResult["quota"];

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
            $row["total_fee"] = (float) $row["total_fee"];
            $row["paid"] = (float) $row["paid"];
            $row["balance"] = max(0, $row["total_fee"] - $row["paid"]);
            $rows[] = $row;
        }

        $stmt->close();

        return [
            "rows" => $rows
        ];
    }

    public static function getFeeBalance($student_id, $language = "en") {
        $feeData = self::getFeeRows($student_id);
        if (isset($feeData["error"])) {
            if (self::isKannada($language)) return "Fee details taruvaga system problem aayitu. Dayavittu swalpa time aadmele try maadi.";
            if (self::isHindi($language)) return "Fee details laate waqt system problem hua. Kripya thodi der baad try kijiye.";
            return $feeData["error"];
        }

        $programFee = 0;
        $skillFee = 0;
        $totalFee = 0;
        $totalPaid = 0;

        foreach ($feeData["rows"] as $row) {
            $totalFee += $row["total_fee"];
            $totalPaid += $row["paid"];

            if (stripos($row["fee_type"], "program") !== false) {
                $programFee += $row["total_fee"];
            }

            if (stripos($row["fee_type"], "skill") !== false) {
                $skillFee += $row["total_fee"];
            }
        }

        $balance = max(0, $totalFee - $totalPaid);
        $program = self::money($programFee);
        $skill = self::money($skillFee);
        $total = self::money($totalFee);
        $paid = self::money($totalPaid);
        $due = self::money($balance);

        if (self::isHindi($language)) {
            $reply = "Aapka total academic fee Rs. {$total} hai. Aapne Rs. {$paid} pay kiya hai. ";
            if ($skillFee > 0) {
                $reply .= "Isme program fee Rs. {$program} aur skill development fee Rs. {$skill} hai. ";
            }
            if ($balance > 0) {
                return $reply . "Aapka pending fee balance Rs. {$due} hai.";
            }
            return $reply . "Aapki fees clear hai. Pending balance Rs. 0.00 hai.";
        }

        if (self::isKannada($language)) {
            $reply = "Nimma total academic fee Rs. {$total}. Neevu Rs. {$paid} pay madiddiri. ";
            if ($skillFee > 0) {
                $reply .= "Idaralli program fee Rs. {$program} mattu skill development fee Rs. {$skill}. ";
            }
            if ($balance > 0) {
                return $reply . "Nimma pending fee balance Rs. {$due} ide.";
            }
            return $reply . "Nimma fees clear ide. Pending balance Rs. 0.00.";
        }

        $reply = "Here is your fee summary. Your program fee is Rs. {$program}. ";
        if ($skillFee > 0) {
            $reply .= "Your skill development fee is Rs. {$skill}. ";
        }
        $reply .= "Your total academic fee is Rs. {$total}. You have paid Rs. {$paid}. ";

        if ($balance > 0) {
            return $reply . "Your remaining balance is Rs. {$due}.";
        }
        return $reply . "You have cleared all your fees. Pending balance is Rs. 0.00.";
    }
    public static function getFinalRegistrationStatus($student_id, $language = "en") {
        $feeData = self::getFeeRows($student_id);
        if (isset($feeData["error"])) {
            return self::isKannada($language)
                ? "ಫೈನಲ್ ರಿಜಿಸ್ಟ್ರೇಷನ್ ಮಾಹಿತಿ ತರುತ್ತಿರುವಾಗ ಸಿಸ್ಟಮ್ ದೋಷ ಉಂಟಾಯಿತು."
                : $feeData["error"];
        }

        $rows = $feeData["rows"];
        if (empty($rows)) {
            return self::isKannada($language)
                ? "ನಿಮ್ಮ ರಿಜಿಸ್ಟ್ರೇಷನ್ ಪಾವತಿ ವಿವರಗಳು ಸಿಗಲಿಲ್ಲ."
                : "I could not find your registration payment details.";
        }

        $pendingItems = [];
        $totalBalance = 0;

        foreach ($rows as $row) {
            if ($row["balance"] > 0) {
                if (self::isKannada($language)) {
                    $pendingItems[] = $row["fee_type"] . " ಬಾಕಿ ರೂ. " . number_format($row["balance"], 2);
                } else {
                    $pendingItems[] = $row["fee_type"] . " balance of Rs. " . number_format($row["balance"], 2);
                }

                $totalBalance += $row["balance"];
            }
        }

        if ($totalBalance <= 0) {
            return self::isKannada($language)
                ? "ನಿಮ್ಮ ಕೋರ್ಸ್ ರಿಜಿಸ್ಟ್ರೇಷನ್ ಮತ್ತು ಫೈನಲ್ ರಿಜಿಸ್ಟ್ರೇಷನ್ ಯಶಸ್ವಿಯಾಗಿ ಪೂರ್ಣಗೊಂಡಿವೆ. ಯಾವುದೇ ಫೀಸ್ ಬಾಕಿ ಇಲ್ಲ."
                : "Your course registration is complete and your final registration is also completed successfully. There is no pending fee balance.";
        }

        $pendingSummary = implode(", ", array_slice($pendingItems, 0, 3));

        if (self::isKannada($language)) {
            return "ನಿಮ್ಮ ಕೋರ್ಸ್ ರಿಜಿಸ್ಟ್ರೇಷನ್ ಪೂರ್ಣಗೊಂಡಿದೆ, ಆದರೆ ನಿಮ್ಮ ಫೈನಲ್ ರಿಜಿಸ್ಟ್ರೇಷನ್ ಇನ್ನೂ ಬಾಕಿಯಿದೆ. ಕಾರಣ, ರೂ. "
                . number_format($totalBalance, 2)
                . " ಫೀಸ್ ಬಾಕಿಯಿದೆ. ಬಾಕಿ ಅಂಶಗಳಲ್ಲಿ "
                . $pendingSummary
                . " ಸೇರಿವೆ. ಫೈನಲ್ ರಿಜಿಸ್ಟ್ರೇಷನ್ ಪೂರ್ಣಗೊಳಿಸಲು ದಯವಿಟ್ಟು ಈ ಬಾಕಿಯನ್ನು ಪಾವತಿಸಿ.";
        }

        return "Your course registration is complete, but your final registration is still pending because you have an outstanding balance of Rs. " . number_format($totalBalance, 2) . ". Pending items include " . $pendingSummary . ". Please clear the balance to complete final registration.";
    }

    private static function detectPaymentFeeType($query) {
        $q = strtolower((string) $query);

        // Hostel
        if (preg_match('/\b(hostel|hostal|hostl)\b/', $q)) return "hostel";

        // Skill assessment
        if (preg_match('/\b(skill\s*assessment|skill\s*fee|skill\s*development)\b/', $q)) return "skill_assessment";

        // Late registration
        if (preg_match('/\b(late\s*reg(istration)?|late\s*fee)\b/', $q)) return "late_registration";

        // Bonafide / study / bank estimate certificate
        if (preg_match('/\b(bonafide|study\s*cert(ificate)?|bank\s*estimate|certificate)\b/', $q)) return "certificate";

        // Misc: photocopy, breakage, byoc, malpractice, pg application, phd, admission
        if (preg_match('/\b(photocopy|photo\s*copy|breakage|byoc|malpractice|pg\s*application|phd\s*application|ph\.d|admission\s*fee)\b/', $q)) return "misc";

        // Tuition (default when "tuition", "college fee", "academic fee", or generic "how to pay")
        if (preg_match('/\b(tuition|college\s*fee|academic\s*fee)\b/', $q)) return "tuition";

        return "generic";
    }

    public static function getFeePaymentNavigation($query, $language = "en") {
        $type = self::detectPaymentFeeType($query);
        $hi = self::isHindi($language);
        $kn = self::isKannada($language);

        switch ($type) {
            case "hostel":
                if ($hi) return "Hostel fee pay karne ke liye Registration → Payment → Hostel Fee pe jaiye, apna USN ya Aadhaar number enter kariye, aur payment proceed kijiye.";
                if ($kn) return "Hostel fee pay madalu Registration → Payment → Hostel Fee ge hogi, nimma USN athava Aadhaar number enter madi, payment proceed madi.";
                return "To pay the Hostel fee, go to Registration → Payment → Hostel Fee, enter your USN or Aadhaar number, and proceed to payment.";

            case "skill_assessment":
                if ($hi) return "Skill Assessment fee ke liye Registration → Payment → Skill/Late-Registration/Other Fee mein jaiye, Skill-Assessment select kijiye aur payment proceed kijiye.";
                if ($kn) return "Skill Assessment fee pay madalu Registration → Payment → Skill/Late-Registration/Other Fee ge hogi, Skill-Assessment select madi aur payment proceed madi.";
                return "To pay the Skill Assessment fee, go to Registration → Payment → Skill/Late-Registration/Other Fee, select Skill-Assessment and proceed to payment.";

            case "late_registration":
                if ($hi) return "Late Registration fee ke liye Registration → Payment → Skill/Late-Registration/Other Fee mein jaiye, Late-Registration select kijiye aur payment proceed kijiye.";
                if ($kn) return "Late Registration fee pay madalu Registration → Payment → Skill/Late-Registration/Other Fee ge hogi, Late-Registration select madi aur payment proceed madi.";
                return "To pay the Late Registration fee, go to Registration → Payment → Skill/Late-Registration/Other Fee, select Late-Registration and proceed to payment.";

            case "certificate":
                if ($hi) return "Certificate fee ke liye Registration → Payment → Skill/Late-Registration/Other Fee mein jaiye, required certificate option select kijiye aur payment proceed kijiye.";
                if ($kn) return "Certificate fee pay madalu Registration → Payment → Skill/Late-Registration/Other Fee ge hogi, bekaadda certificate option select madi aur payment proceed madi.";
                return "To pay certificate-related fees, go to Registration → Payment → Skill/Late-Registration/Other Fee, select the required certificate option, and proceed to payment.";

            case "misc":
                if ($hi) return "Miscellaneous fee ke liye Registration → Payment → Skill/Late-Registration/Other Fee mein jaiye, required fee option select kijiye aur payment proceed kijiye.";
                if ($kn) return "Miscellaneous fee pay madalu Registration → Payment → Skill/Late-Registration/Other Fee ge hogi, bekaadda fee option select madi aur payment proceed madi.";
                return "To pay miscellaneous fees, go to Registration → Payment → Skill/Late-Registration/Other Fee, select the required fee option, and proceed to payment.";

            case "tuition":
            default:
                if ($hi) return "Tuition fee pay karne ke liye Registration → Payment → College/Tuition Fee mein jaiye aur payment proceed kijiye.";
                if ($kn) return "Tuition fee pay madalu Registration → Payment → College/Tuition Fee ge hogi aur payment proceed madi.";
                return "To pay the Tuition fee, go to Registration → Payment → College/Tuition Fee and proceed to payment.";
        }
    }

    public static function answerFeeQuery($student_id, $query, $language = "en", $mode = "balance") {
        switch ($mode) {
            case "fee_info":
            case "balance":
                return self::getFeeBalance($student_id, $language);
            case "payment_navigation":
                return self::getFeePaymentNavigation($query, $language);
            case "receipt":
                if (self::isHindi($language)) {
                    return "Fee receipt ke liye Payment Portal open kijiye aur Download Receipt option choose kijiye.";
                }
                if (self::isKannada($language)) {
                    return "Fee receipt ge Payment Portal open madi mattu Download Receipt option choose madi.";
                }
                return "To download your fee receipt, open Payment Portal and choose Download Receipt.";
            case "grievance":
                if (self::isHindi($language)) {
                    return "Payment grievance ke liye Registration page open kijiye, Payment par click kijiye, phir Payment Grievance choose karke details submit kijiye.";
                }
                if (self::isKannada($language)) {
                    return "Payment grievance ge Registration page open madi, Payment click madi, Payment Grievance choose madi details submit madi.";
                }
                return "To raise a payment grievance, go to Registration, click Payment, choose Payment Grievance, and submit the details.";
            default:
                return self::getFeeBalance($student_id, $language);
        }
    }
}

