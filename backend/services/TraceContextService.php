<?php

require_once __DIR__ . "/LoggerService.php";

class TraceContextService {
    public static function initialize($payload = []) {
        $context = [
            "request_id" => self::firstNonEmpty([
                $_SERVER["HTTP_X_GMU_REQUEST_ID"] ?? "",
                self::extract($payload, ["request_id", "message.request_id", "message.call.assistantOverrides.variableValues.request_id"])
            ], self::id("req")),
            "trace_id" => self::firstNonEmpty([
                $_SERVER["HTTP_X_GMU_TRACE_ID"] ?? "",
                self::extract($payload, ["trace_id", "message.trace_id", "message.call.assistantOverrides.variableValues.trace_id"])
            ], self::id("trace")),
            "call_id" => self::firstNonEmpty([
                $_SERVER["HTTP_X_GMU_CALL_ID"] ?? "",
                self::extract($payload, ["call_id", "message.call.id", "message.callId", "message.call.assistantOverrides.variableValues.call_id"])
            ], ""),
            "tool_execution_id" => self::firstNonEmpty([
                self::extract($payload, ["tool_execution_id", "message.toolCalls.0.id", "message.toolCallList.0.id", "message.toolCall.id"])
            ], "")
        ];

        LoggerService::setContext($context);
        return $context;
    }

    public static function id($prefix) {
        try {
            $random = bin2hex(random_bytes(8));
        } catch (Throwable $e) {
            $random = str_replace(".", "", uniqid("", true));
        }
        return $prefix . "_" . date("YmdHis") . "_" . $random;
    }

    public static function set($context) {
        LoggerService::setContext($context);
    }

    private static function firstNonEmpty($values, $fallback) {
        foreach ($values as $value) {
            if (is_array($value)) {
                foreach ($value as $nestedValue) {
                    $nestedValue = trim((string) $nestedValue);
                    if ($nestedValue !== "") {
                        return $nestedValue;
                    }
                }
                continue;
            }

            $value = trim((string) $value);
            if ($value !== "") {
                return $value;
            }
        }
        return $fallback;
    }

    private static function extract($payload, $paths) {
        $values = [];
        foreach ($paths as $path) {
            $current = $payload;
            foreach (explode(".", $path) as $part) {
                if (is_array($current) && array_key_exists($part, $current)) {
                    $current = $current[$part];
                } else {
                    $current = "";
                    break;
                }
            }
            if (is_scalar($current)) {
                $values[] = (string) $current;
            }
        }
        return $values;
    }
}
