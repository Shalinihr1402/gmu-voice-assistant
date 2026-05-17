<?php

/**
 * Credentialed CORS for the React SPA. Reflects Origin when it matches local dev hosts
 * or entries in GMU_CORS_ORIGINS (comma-separated). Avoids hard-coding :3000 only,
 * which breaks the app when served from http://localhost, another port, or XAMPP.
 */
if (!function_exists("gmu_apply_cors_headers")) {
    function gmu_apply_cors_headers() {
        static $applied = false;
        if ($applied) {
            return;
        }
        $applied = true;

        $origin = isset($_SERVER["HTTP_ORIGIN"]) ? trim((string) $_SERVER["HTTP_ORIGIN"]) : "";
        $allowOrigin = "";

        if ($origin !== "" && preg_match("#^https?://(localhost|127\\.0\\.0\\.1)(:\\d+)?$#i", $origin)) {
            $allowOrigin = $origin;
        } else {
            $extra = getenv("GMU_CORS_ORIGINS");
            if ($origin !== "" && is_string($extra) && $extra !== "") {
                foreach (explode(",", $extra) as $candidate) {
                    $candidate = trim($candidate);
                    if ($candidate !== "" && strcasecmp($origin, $candidate) === 0) {
                        $allowOrigin = $origin;
                        break;
                    }
                }
            }
        }

        if ($allowOrigin === "") {
            $allowOrigin = "http://localhost:3000";
        }

        header("Access-Control-Allow-Origin: {$allowOrigin}");
        header("Access-Control-Allow-Credentials: true");
        header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
        header("Access-Control-Allow-Headers: Content-Type");
    }

    function gmu_cors_handle_preflight() {
        if (($_SERVER["REQUEST_METHOD"] ?? "") === "OPTIONS") {
            http_response_code(200);
            exit();
        }
    }
}

gmu_apply_cors_headers();
gmu_cors_handle_preflight();
