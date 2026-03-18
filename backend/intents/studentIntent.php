<?php

class IntentService {

    private static $intentMap = [

        "GET_USN" => [
            "usn",
            "registration number",
            "university number"
        ],

        "GET_SGPA" => [
            "sgpa",
            "gpa",
            "semester gpa",
            "result",
            "semester result",
            "my result"
        ],
        "GET_CGPA" => [
            "cgpa",
            "overall gpa",
            "overall result",
            "cumulative gpa",
            "current cgpa"
        ],
        "GET_BACKLOG_STATUS" => [
            "backlog",
            "backlogs",
            "failed subject",
            "fail or pass",
            "pass or fail",
            "have i failed",
            "did i fail",
            "supplementary"
        ],

        "GET_FEES_BALANCE" => [
            "fee",
            "fees",
            "balance",
            "due",
            "pending amount",
            "amount due"
        ],
        "GET_FINAL_REGISTRATION_STATUS" => [
            "final registration",
            "registration status",
            "am i registered",
            "have i registered",
            "is my registration complete",
            "is my final registration complete",
            "registration completed",
            "registered or not"
        ],
        "GET_SUBJECT_ATTENDANCE" => [
    "attendance",
    "attendance percentage",
    "attendance in",
    "attendance of",
    "percentage in"
],

        "GET_COURSE_CODE" => [
            "course code",
            "subject code",
            "code of",
            "code for"
        ]
    ];

    public static function detectIntent($message) {

        $message = strtolower($message);

        foreach (self::$intentMap as $intent => $keywords) {
            foreach ($keywords as $keyword) {
                if (strpos($message, $keyword) !== false) {
                    return $intent;
                }
            }
        }

        return "UNKNOWN";
    }
}
