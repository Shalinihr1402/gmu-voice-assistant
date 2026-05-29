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
    public static function answerFeeQuery($student_id, $query = "", $language = "en", $mode = "auto") {
        $text = self::normalizeFeeText($query);
        $feeType = self::detectFeeType($text);
        $intent = $mode === "auto" ? self::detectFeeIntent($text) : $mode;

        if ($intent === "payment_navigation") return self::paymentNavigationReply($feeType, $language);
        if ($intent === "receipt") return self::receiptReply($language);
        if ($intent === "grievance") return self::grievanceReply($text, $language);
        if ($intent === "fee_info" && $feeType === "general" && self::isUnclearFeeInfoQuery($text)) return self::feeTypeClarification($language);

        $feeData = self::getFeeRows($student_id);
        if (isset($feeData["error"])) {
            if (self::isKannada($language)) return "Fee details taruvaga system problem aayitu. Dayavittu swalpa time aadmele try maadi.";
            if (self::isHindi($language)) return "Fee details laate waqt system problem hua. Kripya thodi der baad try kijiye.";
            return $feeData["error"];
        }

        $rows = self::bucketFeeRows($feeData["rows"] ?? []);
        if ($intent === "fee_info") return self::feeInfoReply($rows, $feeType, $language);
        return self::feeBalanceReply($rows, $feeType, $language);
    }

    private static function normalizeFeeText($query) {
        $text = strtolower(trim((string) $query));
        $text = preg_replace('/[^a-z0-9\s]+/u', ' ', $text);
        $text = preg_replace('/\s+/u', ' ', (string) $text);
        return trim((string) $text);
    }

    private static function detectFeeIntent($text) {
        if (preg_match('/\b(receipt|invoice|download receipt|payment receipt|fee receipt)\b/u', $text)) return "receipt";
        if (preg_match('/\b(grievance|complaint|failed|deducted|not updated|transaction issue|grievance result|complaint status)\b/u', $text)) return "grievance";
        if (preg_match('/\b(where|how|pay|payment portal|payment location|where can i pay|how to pay)\b/u', $text) && preg_match('/\b(fee|fees|tuition|hostel|skill|balance|amount)\b/u', $text)) return "payment_navigation";
        if (preg_match('/\b(balance|pending|remaining|due|unpaid|left|baki|bakki)\b/u', $text)) return "balance";
        if (preg_match('/\b(amount|structure|details|detail|total|what is|what are|fees)\b/u', $text)) return "fee_info";
        return "balance";
    }

    private static function detectFeeType($text) {
        if (preg_match('/\b(hostel|hostel fee|hostel fees)\b/u', $text)) return "hostel";
        if (preg_match('/\b(skill assessment|skill assessment fee|assessment fee)\b/u', $text)) return "skill_assessment";
        if (preg_match('/\b(late registration|late registration fee|late fee)\b/u', $text)) return "late_registration";
        if (preg_match('/\b(bonafide|bonafide certificate|study certificate|bank estimate|bank estimate certificate|certificate fee|certificate fees|certificate)\b/u', $text)) return "certificate";
        if (preg_match('/\b(photo copy|photocopy|breakage|breakage fine|byoc|byoc exam|malpractice|malpractice fee|pg application|ph d application|phd application|admission fee|miscellaneous|other fee|other fees)\b/u', $text)) return "misc";
        if (preg_match('/\b(skill|skill fee|skill fees)\b/u', $text)) return "skill";
        if (preg_match('/\b(tuition|college fee|college fees|academic fee|program fee|course fee)\b/u', $text)) return "tuition";
        return "general";
    }

    private static function isUnclearFeeInfoQuery($text) {
        return (bool) preg_match('/^(what are my fees|what is my fees|what are my fee|my fees|fees|fee details|what fee)$/u', $text);
    }

    private static function bucketFeeRows($rows) {
        $buckets = [
            "tuition" => ["total" => 0.0, "paid" => 0.0, "label" => "Tuition Fee", "exists" => false],
            "skill" => ["total" => 0.0, "paid" => 0.0, "label" => "Skill Fee", "exists" => false],
            "hostel" => ["total" => 0.0, "paid" => 0.0, "label" => "Hostel Fee", "exists" => false],
            "other" => ["total" => 0.0, "paid" => 0.0, "label" => "Other Fee", "exists" => false]
        ];

        foreach ($rows as $row) {
            $name = strtolower((string) ($row["fee_type"] ?? ""));
            $key = "other";
            if (strpos($name, "hostel") !== false) $key = "hostel";
            elseif (strpos($name, "skill") !== false || strpos($name, "late") !== false || strpos($name, "other") !== false) $key = "skill";
            elseif (strpos($name, "program") !== false || strpos($name, "tuition") !== false || strpos($name, "college") !== false || strpos($name, "academic") !== false) $key = "tuition";

            $buckets[$key]["total"] += (float) ($row["total_fee"] ?? 0);
            $buckets[$key]["paid"] += (float) ($row["paid"] ?? 0);
            $buckets[$key]["exists"] = true;
        }

        foreach ($buckets as $key => $bucket) {
            $buckets[$key]["balance"] = max(0, $bucket["total"] - $bucket["paid"]);
        }
        return $buckets;
    }

    private static function feeBalanceReply($rows, $feeType, $language) {
        if ($feeType !== "general") {
            if (empty($rows[$feeType]["exists"])) return self::notApplicableFeeReply($feeType, $language);
            return "Your " . strtolower($rows[$feeType]["label"]) . " balance is Rs. " . self::money($rows[$feeType]["balance"]) . ".";
        }

        $parts = [];
        $totalPaid = 0.0;
        $totalBalance = 0.0;
        foreach (["tuition", "skill", "hostel", "other"] as $key) {
            if (empty($rows[$key]["exists"])) continue;
            if ($key === "hostel" && $rows[$key]["total"] <= 0) continue;
            $parts[] = $rows[$key]["label"] . ": Rs. " . self::money($rows[$key]["total"]) . ", Balance: Rs. " . self::money($rows[$key]["balance"]);
            $totalPaid += $rows[$key]["paid"];
            $totalBalance += $rows[$key]["balance"];
        }

        if (empty($parts)) return "I could not find fee details for your account right now.";
        return "Here is your fee balance summary. " . implode(". ", $parts) . ". Paid Amount: Rs. " . self::money($totalPaid) . ". Remaining Balance: Rs. " . self::money($totalBalance) . ".";
    }

    private static function feeInfoReply($rows, $feeType, $language) {
        if ($feeType !== "general") {
            if (empty($rows[$feeType]["exists"])) return self::notApplicableFeeReply($feeType, $language);
            return "Your " . strtolower($rows[$feeType]["label"]) . " amount is Rs. " . self::money($rows[$feeType]["total"]) . ".";
        }

        $parts = [];
        foreach (["tuition", "skill", "hostel", "other"] as $key) {
            if (empty($rows[$key]["exists"])) continue;
            if ($key === "hostel" && $rows[$key]["total"] <= 0) continue;
            $parts[] = $rows[$key]["label"] . ": Rs. " . self::money($rows[$key]["total"]);
        }
        if (empty($parts)) return "I could not find fee details for your account right now.";
        return "Here is your fee structure. " . implode(". ", $parts) . ".";
    }

    private static function paymentNavigationReply($feeType, $language) {
        $responses = [
            "tuition" => "To pay the Tuition fee, go to Registration -> Payment -> College/Tuition Fee and proceed to payment.",
            "hostel" => "To pay the Hostel fee, go to Registration -> Payment -> Hostel Fee, enter your USN or Aadhaar number, and proceed to payment.",
            "skill_assessment" => "To pay the Skill Assessment fee, go to Registration -> Payment -> Skill/Late-Registration/Other Fee -> select Skill-Assessment and proceed to payment.",
            "late_registration" => "To pay the Late Registration fee, go to Registration -> Payment -> Skill/Late-Registration/Other Fee -> select Late-Registration and proceed to payment.",
            "certificate" => "To pay certificate-related fees, go to Registration -> Payment -> Skill/Late-Registration/Other Fee, select the required certificate option, and proceed to payment.",
            "misc" => "To pay other miscellaneous fees, go to Registration -> Payment -> Skill/Late-Registration/Other Fee, select the required fee option, and proceed to payment.",
            "skill" => "To pay the Skill Assessment fee, go to Registration -> Payment -> Skill/Late-Registration/Other Fee -> select Skill-Assessment and proceed to payment.",
            "general" => "To pay fees, go to Registration -> Payment, select the required fee option, and proceed to payment."
        ];
        return $responses[$feeType] ?? $responses["general"];
    }

    private static function receiptReply($language) {
        return "To download your fee receipt, go to: Registration -> Payment -> Download Receipt.";
    }

    private static function grievanceReply($text, $language) {
        if (preg_match('/\b(status|result|track|check|history)\b/u', $text)) return "To check grievance result, go to: Registration -> Payment -> Grievance Result.";
        return "To raise a payment grievance, go to: Registration -> Payment -> Payment Grievance.";
    }

    private static function feeTypeClarification($language) {
        return "Do you mean tuition fee, hostel fee, or skill fee?";
    }

    private static function notApplicableFeeReply($feeType, $language) {
        if ($feeType === "hostel") return "Hostel fee is not applicable or not updated for your account.";
        if ($feeType === "skill") return "Skill fee is not applicable or not updated for your account.";
        return "This fee category is not updated for your account.";
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
}

