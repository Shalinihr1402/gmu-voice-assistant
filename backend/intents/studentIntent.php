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

        "GET_FEES_BALANCE" => [
            "fee",
            "fees",
            "balance",
            "due",
            "pending amount",
            "amount due"
        ],

        "GET_SUBJECT_ATTENDANCE" => [
    "attendance in",
    "attendance of",
    "subject attendance"
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
