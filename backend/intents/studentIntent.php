class IntentService {

    public static function detectIntent($message) {

        $message = strtolower($message);

        if (strpos($message, "usn") !== false) {
            return "GET_USN";
        }

        if (strpos($message, "sgpa") !== false) {
            return "GET_SGPA";
        }

        if (strpos($message, "balance") !== false || strpos($message, "fees") !== false) {
            return "GET_FEES_BALANCE";
        }

        if (strpos($message, "attendance") !== false) {
            return "GET_ATTENDANCE";
        }

        if (strpos($message, "course code") !== false) {
            return "GET_COURSE_CODE";
        }

        return "UNKNOWN";
    }
}
