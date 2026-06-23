<?php

require_once __DIR__ . "/VapiAssistantConfigService.php";
require_once __DIR__ . "/LoggerService.php";

class VapiSecurityService {
    private const MAX_BODY_BYTES = 65536;
    private const MAX_QUERY_CHARS = 1000;
    private const RATE_WINDOW_SECONDS = 60;
    private const RATE_MAX_REQUESTS = 30;
    private const REPLAY_TTL_SECONDS = 600;

    private static function storageDir() {
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . "gmu_vapi_security";
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        return $dir;
    }

    private static function safeKey($value) {
        $hash = hash("sha256", (string) $value);
        return preg_replace('/[^a-zA-Z0-9_-]/', '', $hash);
    }

    public static function validateRawWebhookRequest($rawBody) {
        if (($_SERVER["REQUEST_METHOD"] ?? "") !== "POST") {
            LoggerService::security("webhook_method_rejected", [
                "status" => "rejected",
                "error_message" => "Method not allowed"
            ]);
            return self::failure(405, "Method not allowed.");
        }

        if (strlen((string) $rawBody) > self::MAX_BODY_BYTES) {
            LoggerService::security("webhook_payload_too_large", [
                "status" => "rejected",
                "payload_size_bytes" => strlen((string) $rawBody)
            ]);
            return self::failure(413, "Webhook payload is too large.");
        }

        $secret = self::webhookSigningSecret();
        if ($secret !== "" && !self::hasValidSignature((string) $rawBody, $secret)) {
            LoggerService::security("webhook_signature_invalid", [
                "status" => "rejected",
                "error_message" => "Invalid webhook signature"
            ]);
            return self::failure(401, "Invalid webhook signature.");
        }

        return ["ok" => true];
    }

    public static function validateQuery($query) {
        $query = trim((string) $query);
        if ($query === "") {
            LoggerService::security("tool_query_empty", [
                "status" => "rejected"
            ]);
            return self::failure(400, "Please repeat your question.");
        }

        if (mb_strlen($query, "UTF-8") > self::MAX_QUERY_CHARS) {
            LoggerService::security("tool_query_too_long", [
                "status" => "rejected",
                "query_length" => mb_strlen($query, "UTF-8")
            ]);
            return self::failure(413, "Your question is too long. Please ask a shorter ERP question.");
        }

        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $query)) {
            LoggerService::security("tool_query_control_characters", [
                "status" => "rejected"
            ]);
            return self::failure(400, "Your question contains unsupported characters.");
        }

        return ["ok" => true];
    }

    public static function validateToolExecution($sessionToken, $toolCallId) {
        $token = trim((string) $sessionToken);
        if ($token === "") {
            LoggerService::security("tool_session_token_missing", [
                "status" => "rejected"
            ]);
            return self::failure(401, "Your secure voice session expired. Please refresh the page and try again.");
        }

        $rateResult = self::checkRateLimit($token);
        if (!$rateResult["ok"]) {
            return $rateResult;
        }

        return self::checkReplay($token, $toolCallId);
    }

    public static function sanitizeReply($reply) {
        $reply = (string) $reply;
        $reply = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', ' ', $reply);
        $reply = trim(preg_replace('/\s+/u', ' ', (string) $reply));
        if (mb_strlen($reply, "UTF-8") > 1600) {
            $reply = mb_substr($reply, 0, 1600, "UTF-8") . "...";
        }
        return $reply;
    }

    private static function webhookSigningSecret() {
        return VapiAssistantConfigService::getEnvValue("VAPI_WEBHOOK_SIGNING_SECRET", "");
    }

    private static function hasValidSignature($rawBody, $secret) {
        $provided = trim((string) ($_SERVER["HTTP_X_GMU_SIGNATURE"] ?? $_SERVER["HTTP_X_VAPI_SIGNATURE"] ?? ""));
        if ($provided === "") {
            return false;
        }

        $provided = preg_replace('/^sha256=/i', '', $provided);
        $expected = hash_hmac("sha256", $rawBody, $secret);
        return hash_equals($expected, $provided);
    }

    private static function checkRateLimit($sessionToken) {
        $path = self::storageDir() . DIRECTORY_SEPARATOR . "rate_" . self::safeKey($sessionToken) . ".json";
        $now = time();
        $payload = is_file($path) ? json_decode((string) file_get_contents($path), true) : [];
        if (!is_array($payload) || (int) ($payload["window_start"] ?? 0) < ($now - self::RATE_WINDOW_SECONDS)) {
            $payload = ["window_start" => $now, "count" => 0];
        }

        $payload["count"] = (int) ($payload["count"] ?? 0) + 1;
        file_put_contents($path, json_encode($payload, JSON_UNESCAPED_SLASHES), LOCK_EX);

        if ($payload["count"] > self::RATE_MAX_REQUESTS) {
            LoggerService::security("tool_rate_limit_exceeded", [
                "status" => "rejected",
                "request_count" => $payload["count"],
                "window_seconds" => self::RATE_WINDOW_SECONDS
            ]);
            return self::failure(429, "Too many voice requests. Please wait a minute and try again.");
        }

        return ["ok" => true];
    }

    private static function checkReplay($sessionToken, $toolCallId) {
        $toolCallId = trim((string) $toolCallId);
        if ($toolCallId === "") {
            return ["ok" => true];
        }

        $path = self::storageDir() . DIRECTORY_SEPARATOR . "replay_" . self::safeKey($sessionToken . ":" . $toolCallId) . ".json";
        $now = time();
        if (is_file($path)) {
            $payload = json_decode((string) file_get_contents($path), true);
            if (is_array($payload) && (int) ($payload["expires_at"] ?? 0) >= $now) {
                LoggerService::security("tool_replay_detected", [
                    "status" => "rejected",
                    "tool_call_id" => $toolCallId
                ]);
                return self::failure(409, "Duplicate voice request ignored.");
            }
        }

        file_put_contents($path, json_encode(["expires_at" => $now + self::REPLAY_TTL_SECONDS], JSON_UNESCAPED_SLASHES), LOCK_EX);
        return ["ok" => true];
    }

    private static function failure($status, $message) {
        return [
            "ok" => false,
            "status" => (int) $status,
            "message" => (string) $message
        ];
    }
}
