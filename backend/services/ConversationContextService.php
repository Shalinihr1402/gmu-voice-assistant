<?php

class ConversationContextService {
    private const RECENT_MESSAGE_LIMIT = 10;
    private const SUMMARY_CHAR_LIMIT = 1800;

    private static function ensureSessionWritable() {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
    }

    private static function defaultPreferences() {
        return [
            "preferred_language" => "en",
            "last_topic" => "",
            "last_intent" => "",
            "last_subject" => "",
            "last_semester" => null,
            "last_exam_type" => ""
        ];
    }

    private static function inferTopicFromMeta($meta) {
        $intent = trim((string) ($meta["intent"] ?? ""));

        $topicMap = [
            "GET_USN" => "usn",
            "GET_PROFILE_SUMMARY" => "profile",
            "GET_SGPA" => "sgpa",
            "GET_CGPA" => "cgpa",
            "GET_BACKLOG_STATUS" => "backlog",
            "GET_FEES_BALANCE" => "fees",
            "GET_FINAL_REGISTRATION_STATUS" => "registration",
            "GET_HALL_TICKET_STATUS" => "hall ticket",
            "GET_CERTIFICATE_STATUS" => "certificate",
            "GET_COURSE_DETAILS" => "course details",
            "GET_ATTENDANCE" => "attendance",
            "GET_SUBJECT_ATTENDANCE" => "subject attendance",
            "GET_COURSE_CODE" => "course code",
            "GET_EXAM_READINESS" => "exam readiness",
            "LLM_ASSIST" => "general help"
        ];

        if (isset($topicMap[$intent])) {
            return $topicMap[$intent];
        }

        return trim((string) ($meta["topic"] ?? ""));
    }

    private static function updatePreferencesFromMeta($meta) {
        self::ensureSessionState();

        if (!is_array($meta) || empty($meta)) {
            return;
        }

        $preferences = $_SESSION["voicebot_preferences"];
        $language = trim((string) ($meta["language"] ?? ""));
        $topic = self::inferTopicFromMeta($meta);
        $intent = trim((string) ($meta["intent"] ?? ""));
        $subject = trim((string) ($meta["subject"] ?? ""));
        $semester = $meta["semester"] ?? null;
        $examType = trim((string) ($meta["exam_type"] ?? ""));

        if ($language !== "") {
            $preferences["preferred_language"] = $language;
        }

        if ($topic !== "") {
            $preferences["last_topic"] = $topic;
        }

        if ($intent !== "") {
            $preferences["last_intent"] = $intent;
        }

        if ($subject !== "") {
            $preferences["last_subject"] = $subject;
        }

        if ($semester !== null && $semester !== "") {
            $preferences["last_semester"] = $semester;
        }

        if ($examType !== "") {
            $preferences["last_exam_type"] = $examType;
        }

        $_SESSION["voicebot_preferences"] = $preferences;
    }

    private static function ensureSessionState() {
        self::ensureSessionWritable();

        if (!isset($_SESSION["voicebot_history"]) || !is_array($_SESSION["voicebot_history"])) {
            $_SESSION["voicebot_history"] = [];
        }

        if (!isset($_SESSION["voicebot_summary"]) || !is_string($_SESSION["voicebot_summary"])) {
            $_SESSION["voicebot_summary"] = "";
        }

        if (!isset($_SESSION["voicebot_last_context"]) || !is_array($_SESSION["voicebot_last_context"])) {
            $_SESSION["voicebot_last_context"] = [];
        }

        if (!isset($_SESSION["voicebot_preferences"]) || !is_array($_SESSION["voicebot_preferences"])) {
            $_SESSION["voicebot_preferences"] = self::defaultPreferences();
        } else {
            $_SESSION["voicebot_preferences"] = array_merge(
                self::defaultPreferences(),
                $_SESSION["voicebot_preferences"]
            );
        }
    }

    public static function getRecentMessages() {
        self::ensureSessionState();
        return $_SESSION["voicebot_history"];
    }

    public static function getSummary() {
        self::ensureSessionState();
        return trim($_SESSION["voicebot_summary"]);
    }

    public static function getContextPayload() {
        self::ensureSessionState();

        return [
            "summary" => self::getSummary(),
            "recent_messages" => self::getRecentMessages(),
            "last_context" => self::getLastResolvedContext(),
            "preferences" => self::getPreferences()
        ];
    }

    public static function saveTurn($userMessage, $assistantReply, $meta = []) {
        self::ensureSessionState();

        $_SESSION["voicebot_history"][] = [
            "role" => "user",
            "text" => trim((string) $userMessage)
        ];
        $_SESSION["voicebot_history"][] = [
            "role" => "assistant",
            "text" => trim((string) $assistantReply),
            "meta" => is_array($meta) ? $meta : []
        ];

        if (is_array($meta) && !empty($meta)) {
            self::setLastResolvedContext($meta);
            self::updatePreferencesFromMeta($meta);
        }

        self::compactHistoryIfNeeded();
        @session_write_close();
    }

    public static function getLastResolvedContext() {
        self::ensureSessionState();
        return $_SESSION["voicebot_last_context"];
    }

    public static function setLastResolvedContext($context) {
        self::ensureSessionState();
        $_SESSION["voicebot_last_context"] = is_array($context) ? $context : [];

        if (is_array($context) && !empty($context)) {
            self::updatePreferencesFromMeta($context);
        }

        @session_write_close();
    }

    public static function getPreferences() {
        self::ensureSessionState();
        return $_SESSION["voicebot_preferences"];
    }

    public static function clear() {
        $_SESSION["voicebot_history"] = [];
        $_SESSION["voicebot_summary"] = "";
        $_SESSION["voicebot_last_context"] = [];
        $_SESSION["voicebot_preferences"] = self::defaultPreferences();
    }

    private static function compactHistoryIfNeeded() {
        $history = $_SESSION["voicebot_history"];
        $overflowCount = count($history) - self::RECENT_MESSAGE_LIMIT;

        if ($overflowCount <= 0) {
            return;
        }

        $overflowMessages = array_slice($history, 0, $overflowCount);
        $_SESSION["voicebot_history"] = array_slice($history, -self::RECENT_MESSAGE_LIMIT);
        $_SESSION["voicebot_summary"] = self::mergeSummary(
            $_SESSION["voicebot_summary"],
            self::summarizeMessages($overflowMessages)
        );
    }

    private static function summarizeMessages($messages) {
        $summaryLines = [];
        $pendingUser = null;

        foreach ($messages as $message) {
            $role = $message["role"] ?? "user";
            $text = self::normalizeSummaryText($message["text"] ?? "");

            if ($text === "") {
                continue;
            }

            if ($role === "user") {
                $pendingUser = $text;
                continue;
            }

            if ($role === "assistant") {
                if ($pendingUser !== null) {
                    $summaryLines[] = "User asked: {$pendingUser} Assistant answered: {$text}";
                    $pendingUser = null;
                } else {
                    $summaryLines[] = "Assistant answered: {$text}";
                }
            }
        }

        if ($pendingUser !== null) {
            $summaryLines[] = "User asked: {$pendingUser}";
        }

        return implode(" ", $summaryLines);
    }

    private static function mergeSummary($existingSummary, $newSummary) {
        $existingSummary = trim((string) $existingSummary);
        $newSummary = trim((string) $newSummary);

        if ($newSummary === "") {
            return $existingSummary;
        }

        $combined = trim($existingSummary . " " . $newSummary);
        $combined = preg_replace('/\s+/', ' ', $combined);

        if (strlen($combined) <= self::SUMMARY_CHAR_LIMIT) {
            return $combined;
        }

        return substr($combined, -self::SUMMARY_CHAR_LIMIT);
    }

    private static function normalizeSummaryText($text) {
        $text = trim((string) $text);
        $text = preg_replace('/\s+/', ' ', $text);

        if (strlen($text) <= 220) {
            return $text;
        }

        return rtrim(substr($text, 0, 217)) . "...";
    }
}
