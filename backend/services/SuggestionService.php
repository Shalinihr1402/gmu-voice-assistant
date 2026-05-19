<?php

class SuggestionService {
    private static function normalizeLanguage($language) {
        $language = strtolower(trim((string) $language));
        if (in_array($language, ["hi", "hindi", "hi-in"], true)) {
            return "hi";
        }
        return in_array($language, ["kn", "kannada", "kn-in"], true) ? "kn" : "en";
    }

    private static function createAction($labelEn, $labelHi, $labelKn, $promptEn, $promptHi, $promptKn, $language) {
        $language = self::normalizeLanguage($language);
        return [
            "label" => $language === "hi" ? $labelHi : ($language === "kn" ? $labelKn : $labelEn),
            "prompt" => $language === "hi" ? $promptHi : ($language === "kn" ? $promptKn : $promptEn)
        ];
    }

    private static function createSuggestion($textEn, $textHi, $textKn, $actions, $language, $priority = "medium") {
        $language = self::normalizeLanguage($language);
        return [
            "text" => $language === "hi" ? $textHi : ($language === "kn" ? $textKn : $textEn),
            "quick_actions" => $actions,
            "priority" => $priority
        ];
    }

    private static function parseFirstPercentage($reply) {
        if (preg_match('/(\d+(?:\.\d+)?)\s*percent/i', $reply, $matches)) {
            return (float) $matches[1];
        }

        if (preg_match('/(\d+(?:\.\d+)?)\s*%/i', $reply, $matches)) {
            return (float) $matches[1];
        }

        return null;
    }

    private static function parseSubjectFromAttendanceReply($reply) {
        if (preg_match('/attendance (?:in|for|of)\s+([A-Za-z][A-Za-z0-9\s&()-]+)/i', $reply, $matches)) {
            return trim((string) $matches[1]);
        }

        if (preg_match('/for\s+([A-Za-z][A-Za-z0-9\s&()-]+),\s+you attended/i', $reply, $matches)) {
            return trim((string) $matches[1]);
        }

        return "";
    }

    public static function build($intent, $reply, $language = "en", $context = []) {
        $language = self::normalizeLanguage($language);
        $reply = trim((string) $reply);
        $intent = trim((string) $intent);

        if ($reply === "") {
            return null;
        }

        switch ($intent) {
            case "GET_FEES_BALANCE":
                if (stripos($reply, "remaining balance") !== false || stripos($reply, "pending") !== false || stripos($reply, "outstanding") !== false || stripos($reply, "pending fee balance") !== false || stripos($reply, "?????") !== false || stripos($reply, "??????") !== false || stripos($reply, "ಬಾಕಿ") !== false || stripos($reply, "balance Rs. 0.00") === false && preg_match("/Rs\.\s*(?!0\.00)[0-9,]+\.\d{2}/", $reply)) {
                    return self::createSuggestion(
                        "Your fee is still pending. Do you want to see the fee breakup or open the payment portal?",
                        "आपकी फीस अभी लंबित है। क्या आप फीस का पूरा breakup देखना चाहते हैं या payment portal खोलना चाहते हैं?",
                        "ನಿಮ್ಮ fee ಇನ್ನೂ ಬಾಕಿಯಿದೆ. Fee breakup ನೋಡಬೇಕೆ ಅಥವಾ payment portal ತೆರೆಯಬೇಕೆ?",
                        [
                            self::createAction("Fee breakup", "Fee breakup", "Fee breakup", "Show my fee breakup", "मेरा fee breakup दिखाइए", "ನನ್ನ fee breakup ತೋರಿಸಿ", $language),
                            self::createAction("Open payment", "Payment खोलें", "Payment ತೆರೆಯಿರಿ", "Open payment portal", "Payment portal खोलिए", "Payment portal ತೆರೆಯಿರಿ", $language)
                        ],
                        $language,
                        "high"
                    );
                }

                return self::createSuggestion(
                    "Your fees look clear. Do you want to check your registration status now?",
                    "आपकी फीस clear दिख रही है। क्या आप अभी registration status देखना चाहते हैं?",
                    "ನಿಮ್ಮ fees clear ಆಗಿವೆ ಎಂದು ಕಾಣುತ್ತಿದೆ. ಈಗ registration status ನೋಡಬೇಕೆ?",
                    [
                        self::createAction("Registration status", "Registration status", "Registration status", "Check my registration status", "मेरा registration status बताइए", "ನನ್ನ registration status ತಿಳಿಸಿ", $language)
                    ],
                    $language,
                    "medium"
                );

            case "GET_ATTENDANCE":
                $overall = self::parseFirstPercentage($reply);
                if ($overall !== null && $overall < 75) {
                    return self::createSuggestion(
                        "Your attendance is low. Do you want subject-wise attendance details?",
                        "आपकी attendance कम है। क्या आप subject-wise attendance details देखना चाहते हैं?",
                        "ನಿಮ್ಮ attendance ಕಡಿಮೆ ಇದೆ. Subject-wise attendance details ನೋಡಬೇಕೆ?",
                        [
                            self::createAction("Subject-wise", "Subject-wise", "Subject-wise", "Show my subject-wise attendance", "मेरी subject-wise attendance दिखाइए", "ನನ್ನ subject-wise attendance ತೋರಿಸಿ", $language)
                        ],
                        $language,
                        "high"
                    );
                }

                return self::createSuggestion(
                    "Do you want subject-wise attendance details too?",
                    "क्या आप subject-wise attendance details भी देखना चाहते हैं?",
                    "Subject-wise attendance details ಕೂಡ ನೋಡಬೇಕೆ?",
                    [
                        self::createAction("Subject-wise", "Subject-wise", "Subject-wise", "Show my subject-wise attendance", "मेरी subject-wise attendance दिखाइए", "ನನ್ನ subject-wise attendance ತೋರಿಸಿ", $language)
                    ],
                    $language,
                    "medium"
                );

            case "GET_SUBJECT_ATTENDANCE":
                $subject = $context["subject"] ?? self::parseSubjectFromAttendanceReply($reply);
                $percentage = self::parseFirstPercentage($reply);
                if ($percentage !== null && $percentage < 75) {
                    return self::createSuggestion(
                        "Your attendance is low" . ($subject !== "" ? " in {$subject}" : "") . ". Do you want your overall attendance too?",
                        "आपकी attendance" . ($subject !== "" ? " {$subject} में" : "") . " कम है। क्या आप overall attendance भी देखना चाहते हैं?",
                        "ನಿಮ್ಮ attendance" . ($subject !== "" ? " {$subject} ನಲ್ಲಿ" : "") . " ಕಡಿಮೆ ಇದೆ. Overall attendance ಕೂಡ ನೋಡಬೇಕೆ?",
                        [
                            self::createAction("Overall attendance", "Overall attendance", "Overall attendance", "Show my overall attendance", "मेरी overall attendance दिखाइए", "ನನ್ನ overall attendance ತೋರಿಸಿ", $language)
                        ],
                        $language,
                        "high"
                    );
                }

                return self::createSuggestion(
                    "Do you want attendance for another subject as well?",
                    "क्या आप किसी और subject की attendance भी देखना चाहते हैं?",
                    "ಇನ್ನೊಂದು subject attendance ಕೂಡ ನೋಡಬೇಕೆ?",
                    [
                        self::createAction("Other subjects", "Other subjects", "Other subjects", "Show my subject-wise attendance", "मेरी subject-wise attendance दिखाइए", "ನನ್ನ subject-wise attendance ತೋರಿಸಿ", $language)
                    ],
                    $language,
                    "medium"
                );

            case "GET_SGPA":
            case "GET_CGPA":
                if (stripos($reply, "backlog") !== false || stripos($reply, "fail") !== false) {
                    return self::createSuggestion(
                        "Do you want to see which subjects are in backlog?",
                        "क्या आप देखना चाहते हैं कि कौन से subjects backlog में हैं?",
                        "ಯಾವ subjects backlog ನಲ್ಲಿ ಇವೆ ಎಂದು ನೋಡಬೇಕೆ?",
                        [
                            self::createAction("Check backlogs", "Backlogs देखें", "Backlogs ನೋಡಿ", "Do I have any backlogs", "क्या मेरे backlogs हैं", "ನನಗೆ backlog ಇದೆಯೆ", $language)
                        ],
                        $language,
                        "high"
                    );
                }

                return self::createSuggestion(
                    "Do you want to check your overall CGPA too?",
                    "क्या आप अपनी overall CGPA भी देखना चाहते हैं?",
                    "ನಿಮ್ಮ overall CGPA ಕೂಡ ನೋಡಬೇಕೆ?",
                    [
                        self::createAction("Check CGPA", "CGPA देखें", "CGPA ನೋಡಿ", "What is my CGPA", "मेरा CGPA क्या है", "ನನ್ನ CGPA ಎಷ್ಟು", $language)
                    ],
                    $language,
                    "medium"
                );

            case "GET_BACKLOG_STATUS":
                if (stripos($reply, "backlog") !== false && stripos($reply, "no active backlog") === false && stripos($reply, "do not have any active backlog") === false) {
                    return self::createSuggestion(
                        "You may have backlog-related issues. Do you want to check your latest result details?",
                        "आपके backlog-related issues हो सकते हैं। क्या आप latest result details देखना चाहते हैं?",
                        "ನಿಮಗೆ backlog-related issue ಇರಬಹುದು. Latest result details ನೋಡಬೇಕೆ?",
                        [
                            self::createAction("Latest result", "Latest result", "Latest result", "Show my latest result", "मेरा latest result दिखाइए", "ನನ್ನ latest result ತೋರಿಸಿ", $language)
                        ],
                        $language,
                        "high"
                    );
                }
                break;

            case "GET_FINAL_REGISTRATION_STATUS":
                if (stripos($reply, "pending") !== false || stripos($reply, "outstanding balance") !== false || stripos($reply, "ಬಾಕಿ") !== false) {
                    return self::createSuggestion(
                        "Your registration is not fully clear yet. Do you want to check the pending fee amount?",
                        "आपका registration अभी पूरा clear नहीं है। क्या आप pending fee amount देखना चाहते हैं?",
                        "ನಿಮ್ಮ registration ಇನ್ನೂ clear ಆಗಿಲ್ಲ. Pending fee amount ನೋಡಬೇಕೆ?",
                        [
                            self::createAction("Pending fees", "Pending fees", "Pending fees", "What is my fee balance", "मेरी pending fees क्या हैं", "ನನ್ನ pending fees ಎಷ್ಟು", $language)
                        ],
                        $language,
                        "high"
                    );
                }

                return self::createSuggestion(
                    "Your registration looks complete. Do you want to check your hall ticket status next?",
                    "आपका registration complete दिख रहा है। क्या आप next hall ticket status देखना चाहते हैं?",
                    "ನಿಮ್ಮ registration complete ಆಗಿದೆ ಎಂದು ಕಾಣುತ್ತಿದೆ. Next hall ticket status ನೋಡಬೇಕೆ?",
                    [
                        self::createAction("Hall ticket", "Hall ticket", "Hall ticket", "Check my hall ticket status", "मेरा hall ticket status बताइए", "ನನ್ನ hall ticket status ತಿಳಿಸಿ", $language)
                    ],
                    $language,
                    "medium"
                );

            case "GET_HALL_TICKET_STATUS":
                if (stripos($reply, "not") !== false || stripos($reply, "pending") !== false || stripos($reply, "unable") !== false) {
                    return self::createSuggestion(
                        "Do you want me to check whether fees or registration is blocking your hall ticket?",
                        "क्या आप चाहते हैं कि मैं check करूं कि fees या registration hall ticket को block कर रहे हैं?",
                        "Fees ಅಥವಾ registration ನಿಮ್ಮ hall ticket ಅನ್ನು block ಮಾಡುತ್ತಿದೆಯೇ ಎಂದು check ಮಾಡಬೇಕೆ?",
                        [
                            self::createAction("Check registration", "Registration check", "Registration check", "Check my registration status", "मेरा registration status check कीजिए", "ನನ್ನ registration status check ಮಾಡಿ", $language),
                            self::createAction("Check fees", "Fees check", "Fees check", "Check my fee balance", "मेरी fee balance check कीजिए", "ನನ್ನ fee balance check ಮಾಡಿ", $language)
                        ],
                        $language,
                        "high"
                    );
                }
                break;

            case "GET_CERTIFICATE_STATUS":
                if (stripos($reply, "available") !== false || stripos($reply, "ಲಭ್ಯ") !== false || stripos($reply, "उपलब्ध") !== false) {
                    return self::createSuggestion(
                        "Your certificate is available. Do you want to open the certificate page now?",
                        "आपका certificate उपलब्ध है। क्या आप अभी certificate page खोलना चाहते हैं?",
                        "ನಿಮ್ಮ certificate ಲಭ್ಯ ಇದೆ. ಈಗ certificate page ತೆರೆಯಬೇಕೆ?",
                        [
                            self::createAction("Open certificate", "Certificate खोलें", "Certificate ತೆರೆಯಿರಿ", "Open certificate page", "Certificate page खोलिए", "Certificate page ತೆರೆಯಿರಿ", $language)
                        ],
                        $language,
                        "medium"
                    );
                }
                break;

            case "GET_EXAM_READINESS":
                if (stripos($reply, "needs attention") !== false || stripos($reply, "not clearly ready") !== false || stripos($reply, "risk") !== false) {
                    return self::createSuggestion(
                        "Your exam readiness has some issues. Do you want to check the exact blocking reason?",
                        "आपकी exam readiness में कुछ issues हैं। क्या आप exact blocking reason देखना चाहते हैं?",
                        "ನಿಮ್ಮ exam readiness ನಲ್ಲಿ ಕೆಲವು issues ಇವೆ. Exact blocking reason ನೋಡಬೇಕೆ?",
                        [
                            self::createAction("Check fees", "Fees check", "Fees check", "What is my fee balance", "मेरी fee balance क्या है", "ನನ್ನ fee balance ಎಷ್ಟು", $language),
                            self::createAction("Check hall ticket", "Hall ticket check", "Hall ticket check", "Check my hall ticket status", "मेरा hall ticket status बताइए", "ನನ್ನ hall ticket status ತಿಳಿಸಿ", $language)
                        ],
                        $language,
                        "high"
                    );
                }
                break;
        }

        return null;
    }
}

