<?php

class VapiSessionService {
    private const TOKEN_TTL_SECONDS = 3600;

    private static function storageDir() {
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . "gmu_vapi_sessions";
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        return $dir;
    }

    private static function tokenPath($token) {
        $safeToken = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) $token);
        return self::storageDir() . DIRECTORY_SEPARATOR . $safeToken . ".json";
    }

    public static function createForCurrentSession($userId, $language = "en") {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            throw new RuntimeException("PHP session must be active before creating a Vapi token.");
        }

        $language = strtolower(trim((string) $language));
        if (!in_array($language, ["en", "hi", "kn"], true)) {
            $language = "en";
        }

        $token = bin2hex(random_bytes(24));
        $payload = [
            "token" => $token,
            "session_id" => session_id(),
            "user_id" => (int) $userId,
            "student_id" => isset($_SESSION["student_id"]) ? (int) $_SESSION["student_id"] : null,
            "language" => $language,
            "created_at" => time(),
            "expires_at" => time() + self::TOKEN_TTL_SECONDS
        ];

        file_put_contents(self::tokenPath($token), json_encode($payload, JSON_UNESCAPED_SLASHES));
        return $payload;
    }

    public static function resolve($token) {
        $token = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) $token);
        if ($token === "") {
            return null;
        }

        $path = self::tokenPath($token);
        if (!is_file($path)) {
            return null;
        }

        $payload = json_decode((string) file_get_contents($path), true);
        if (!is_array($payload) || (int) ($payload["expires_at"] ?? 0) < time()) {
            @unlink($path);
            return null;
        }

        return $payload;
    }

    public static function updateLanguage($token, $language) {
        $token = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) $token);
        $language = strtolower(trim((string) $language));
        if ($token === "" || !in_array($language, ["en", "hi", "kn"], true)) {
            return false;
        }

        $path = self::tokenPath($token);
        if (!is_file($path)) {
            return false;
        }

        $payload = json_decode((string) file_get_contents($path), true);
        if (!is_array($payload) || (int) ($payload["expires_at"] ?? 0) < time()) {
            @unlink($path);
            return false;
        }

        $payload["language"] = $language;
        $payload["language_updated_at"] = time();
        file_put_contents($path, json_encode($payload, JSON_UNESCAPED_SLASHES));
        return true;
    }

    public static function latestValidSession() {
        $dir = self::storageDir();
        $files = glob($dir . DIRECTORY_SEPARATOR . "*.json") ?: [];
        usort($files, function ($a, $b) {
            return filemtime($b) <=> filemtime($a);
        });

        foreach ($files as $file) {
            $payload = json_decode((string) file_get_contents($file), true);
            if (is_array($payload) && (int) ($payload["expires_at"] ?? 0) >= time()) {
                return $payload;
            }
            @unlink($file);
        }

        return null;
    }
    public static function getTtlSeconds() {
        return self::TOKEN_TTL_SECONDS;
    }
}


