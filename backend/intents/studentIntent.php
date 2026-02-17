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
            "semester gpa",
            "gpa",
            "grade point"
        ],

        "GET_FEES_BALANCE" => [
            "fee",
            "fees",
            "balance",
            "due",
            "pending amount",
            "amount due"
        ],

        "GET_ATTENDANCE" => [
            "attendance",
            "present percentage",
            "attendance percentage"
        ],

        "GET_COURSE_CODE" => [
            "course code",
            "subject code",
            "code"
        ],

        "GET_SUBJECTS" => [
            "subjects",
            "courses",
            "registered subjects"
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
