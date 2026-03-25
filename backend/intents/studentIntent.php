<?php

class IntentService {

    private static $intentMap = [

        "GET_USN" => [
            "usn",
            "registration number",
            "university number"
        ],
        "GET_PROFILE_SUMMARY" => [
            "who am i",
            "do you know who i am",
            "my profile",
            "tell me about my profile",
            "student profile",
            "which semester am i in",
            "what semester am i in",
            "my semester",
            "which department am i from",
            "what department am i from",
            "my department",
            "my branch",
            "what am i studying"
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
        "GET_HALL_TICKET_STATUS" => [
            "hall ticket",
            "hallticket",
            "admission ticket",
            "ticket generated",
            "is my hall ticket generated",
            "my hall ticket status",
            "can i download hall ticket"
        ],
        "GET_COURSE_DETAILS" => [
            "subject",
            "subjects",
            "course",
            "courses",
            "my subjects",
            "my courses",
            "what subjects do i have",
            "what courses do i have",
            "subject details",
            "course details",
            "registered subjects",
            "registered courses"
        ],
        "GET_ATTENDANCE" => [
            "my attendance",
            "overall attendance",
            "attendance percentage",
            "attendance status"
        ],
        "GET_SUBJECT_ATTENDANCE" => [
            "attendance in",
            "attendance of",
            "percentage in",
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

        if (strpos($message, "attendance") !== false) {
            $overallAttendanceHints = [
                "my attendance",
                "overall attendance",
                "attendance percentage",
                "attendance status",
                "total attendance"
            ];

            foreach ($overallAttendanceHints as $hint) {
                if (strpos($message, $hint) !== false) {
                    return "GET_ATTENDANCE";
                }
            }

            if (preg_match('/\b[a-z0-9&(). -]+\s+attendance\b/', $message) || preg_match('/\battendance\s+(?:in|of|for)\b/', $message)) {
                return "GET_SUBJECT_ATTENDANCE";
            }
        }

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
