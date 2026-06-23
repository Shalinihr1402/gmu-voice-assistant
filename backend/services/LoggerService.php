<?php

class LoggerService {
    private const MAX_FILE_BYTES = 5242880;
    private static $context = [];

    public static function setContext($context) {
        if (!is_array($context)) {
            return;
        }
        self::$context = array_merge(self::$context, self::sanitizeContext($context));
    }

    public static function getContext() {
        return self::$context;
    }

    public static function info($event, $context = []) {
        self::write("INFO", "app.log", $event, $context);
    }

    public static function warning($event, $context = []) {
        self::write("WARNING", "app.log", $event, $context);
    }

    public static function error($event, $context = []) {
        self::write("ERROR", "app.log", $event, $context);
    }

    public static function security($event, $context = []) {
        self::write("SECURITY", "security.log", $event, $context);
    }

    public static function performance($event, $context = []) {
        self::write("PERFORMANCE", "performance.log", $event, $context);
    }

    public static function voice($event, $context = []) {
        self::write("INFO", "voice.log", $event, $context);
    }

    public static function markPerformance($event, $durationMs, $context = []) {
        $context["latency_ms"] = (int) round((float) $durationMs);
        if ($durationMs >= 3000) {
            $context["status"] = $context["status"] ?? "very_slow";
            self::performance($event, $context);
        } elseif ($durationMs >= 1000) {
            $context["status"] = $context["status"] ?? "slow";
            self::performance($event, $context);
        }
    }

    public static function tokenHash($token) {
        $token = trim((string) $token);
        return $token === "" ? "" : hash("sha256", $token);
    }

    public static function nowMs() {
        return microtime(true) * 1000;
    }

    public static function durationMs($startMs) {
        return (int) round(self::nowMs() - (float) $startMs);
    }

    private static function write($level, $file, $event, $context) {
        $dir = self::logDir();
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $path = $dir . DIRECTORY_SEPARATOR . $file;
        self::rotateIfNeeded($path);

        $record = array_merge([
            "timestamp" => gmdate("c"),
            "level" => $level,
            "event" => (string) $event
        ], self::$context, self::sanitizeContext($context));

        $line = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($line === false) {
            $line = json_encode([
                "timestamp" => gmdate("c"),
                "level" => "ERROR",
                "event" => "logger_encode_failed"
            ]);
        }

        @file_put_contents($path, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    private static function sanitizeContext($context) {
        $safe = [];
        foreach ((array) $context as $key => $value) {
            $key = (string) $key;
            if ($key === "") {
                continue;
            }

            if (preg_match('/token$/i', $key) || stripos($key, "secret") !== false || stripos($key, "password") !== false) {
                $safe[$key . "_hash"] = self::tokenHash((string) $value);
                continue;
            }

            if (is_array($value)) {
                $safe[$key] = self::sanitizeContext($value);
            } elseif (is_object($value)) {
                $safe[$key] = "[object]";
            } elseif (is_bool($value) || is_int($value) || is_float($value) || $value === null) {
                $safe[$key] = $value;
            } else {
                $safe[$key] = self::truncate((string) $value);
            }
        }
        return $safe;
    }

    private static function truncate($value) {
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', ' ', $value);
        $value = trim(preg_replace('/\s+/u', ' ', (string) $value));
        return mb_strlen($value, "UTF-8") > 1200 ? mb_substr($value, 0, 1200, "UTF-8") . "..." : $value;
    }

    private static function logDir() {
        return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . "logs";
    }

    private static function rotateIfNeeded($path) {
        if (!is_file($path) || filesize($path) < self::MAX_FILE_BYTES) {
            return;
        }

        $rotated = $path . "." . date("Ymd_His");
        @rename($path, $rotated);
    }
}
