<?php

class ConversationContextService {
    private const RECENT_MESSAGE_LIMIT = 10;
    private const SUMMARY_CHAR_LIMIT = 1800;

    private static function ensureSessionState() {
        if (!isset($_SESSION["voicebot_history"]) || !is_array($_SESSION["voicebot_history"])) {
            $_SESSION["voicebot_history"] = [];
        }

        if (!isset($_SESSION["voicebot_summary"]) || !is_string($_SESSION["voicebot_summary"])) {
            $_SESSION["voicebot_summary"] = "";
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
            "recent_messages" => self::getRecentMessages()
        ];
    }

    public static function saveTurn($userMessage, $assistantReply) {
        self::ensureSessionState();

        $_SESSION["voicebot_history"][] = [
            "role" => "user",
            "text" => trim((string) $userMessage)
        ];
        $_SESSION["voicebot_history"][] = [
            "role" => "assistant",
            "text" => trim((string) $assistantReply)
        ];

        self::compactHistoryIfNeeded();
    }

    public static function clear() {
        $_SESSION["voicebot_history"] = [];
        $_SESSION["voicebot_summary"] = "";
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
