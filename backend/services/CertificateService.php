<?php

class CertificateService {
    private const DEFAULT_CERTIFICATE_URL = "https://erp.gmit.info/gmu_ac/output/ac_sdtcd_form_list.php";
    private const FALLBACK_CERTIFICATES = [
        [
            "academic_year" => "2024-25",
            "season" => "ODD",
            "program" => "MCA",
            "sem" => "1",
            "code" => "HG24TCCYS1",
            "subject" => "Fundamentals of Cyber Security",
            "grade" => "O",
            "date" => "12-06-2025",
            "status" => "available",
            "download_url" => "",
            "action_label" => "Certificate"
        ],
        [
            "academic_year" => "2024-25",
            "season" => "EVEN",
            "program" => "MCA",
            "sem" => "2",
            "code" => "HG24TCCYS2",
            "subject" => "Cybersecurity Essentials - Ethical Hacking (Stage 2)",
            "grade" => "A+",
            "date" => "24-10-2025",
            "status" => "available",
            "download_url" => "",
            "action_label" => "Certificate"
        ],
        [
            "academic_year" => "2024-25",
            "season" => "EVEN",
            "program" => "MCA",
            "sem" => "2",
            "code" => "HG24SATC02",
            "subject" => "Co-curricular Activities",
            "grade" => "A+",
            "date" => "02-04-2026",
            "status" => "available",
            "download_url" => "",
            "action_label" => "Certificate"
        ],
        [
            "academic_year" => "2025-26",
            "season" => "ODD",
            "program" => "MCA",
            "sem" => "3",
            "code" => "HG24TCESIE",
            "subject" => "Technical Skills",
            "grade" => "O",
            "date" => "11-04-2026",
            "status" => "available",
            "download_url" => "",
            "action_label" => "Certificate"
        ],
        [
            "academic_year" => "2025-26",
            "season" => "ODD",
            "program" => "MCA",
            "sem" => "3",
            "code" => "HG24SATC02",
            "subject" => "Co-curricular Activities",
            "grade" => "A",
            "date" => "11-04-2026",
            "status" => "available",
            "download_url" => "",
            "action_label" => "Certificate"
        ]
    ];

    private static function getEnvValue($key) {
        $value = getenv($key);

        if ($value !== false && $value !== "") {
            return $value;
        }

        if (isset($_SERVER[$key]) && $_SERVER[$key] !== "") {
            return $_SERVER[$key];
        }

        if (isset($_ENV[$key]) && $_ENV[$key] !== "") {
            return $_ENV[$key];
        }

        return null;
    }

    private static function getCertificateUrl() {
        return self::getEnvValue("GMU_ERP_CERTIFICATE_URL") ?: self::DEFAULT_CERTIFICATE_URL;
    }

    private static function getCookieHeader() {
        if (!empty($_SESSION["erp_cookie_header"])) {
            return trim((string) $_SESSION["erp_cookie_header"]);
        }

        $cookie = self::getEnvValue("GMU_ERP_COOKIE");
        return $cookie ? trim((string) $cookie) : "";
    }

    private static function absoluteUrl($baseUrl, $relativeUrl) {
        if ($relativeUrl === "") {
            return "";
        }

        if (preg_match('/^https?:\/\//i', $relativeUrl)) {
            return $relativeUrl;
        }

        $baseParts = parse_url($baseUrl);
        if (!$baseParts || empty($baseParts["scheme"]) || empty($baseParts["host"])) {
            return $relativeUrl;
        }

        $root = $baseParts["scheme"] . "://" . $baseParts["host"];
        if (!empty($baseParts["port"])) {
            $root .= ":" . $baseParts["port"];
        }

        if (strpos($relativeUrl, "/") === 0) {
            return $root . $relativeUrl;
        }

        $path = $baseParts["path"] ?? "/";
        $dir = rtrim(str_replace("\\", "/", dirname($path)), "/");
        return $root . ($dir ? $dir : "") . "/" . ltrim($relativeUrl, "/");
    }

    private static function normalizeHeader($text) {
        $text = strtolower(trim((string) $text));
        $text = preg_replace('/\s+/u', ' ', $text);
        return trim((string) $text);
    }

    private static function normalizeLookupText($text) {
        $text = strtolower(trim((string) $text));
        $text = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text);
        $text = preg_replace('/\s+/u', ' ', (string) $text);
        return trim((string) $text);
    }

    private static function normalizeStatus($label) {
        $normalized = self::normalizeLookupText($label);

        if ($normalized === "") {
            return "available";
        }

        if (strpos($normalized, "available") !== false || strpos($normalized, "certificate") !== false || strpos($normalized, "download") !== false) {
            return "available";
        }

        if (strpos($normalized, "pending") !== false) {
            return "pending";
        }

        if (strpos($normalized, "not available") !== false || strpos($normalized, "unavailable") !== false) {
            return "unavailable";
        }

        return $normalized;
    }

    public static function fetchCertificates() {
        $cookieHeader = self::getCookieHeader();

        if ($cookieHeader === "") {
            return [
                "status" => "error",
                "message" => "ERP session cookie is missing. Set GMU_ERP_COOKIE or store erp_cookie_header in the PHP session before fetching certificates.",
                "records" => []
            ];
        }

        $url = self::getCertificateUrl();
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Cookie: " . $cookieHeader,
            "User-Agent: GMU-VoiceBot/1.0"
        ]);

        $html = curl_exec($ch);
        $effectiveUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($html === false || $curlError) {
            return [
                "status" => "error",
                "message" => $curlError ?: "Unable to reach ERP certificate page.",
                "records" => []
            ];
        }

        if ($statusCode >= 400) {
            return [
                "status" => "error",
                "message" => "ERP returned HTTP " . $statusCode . " while fetching certificates.",
                "records" => []
            ];
        }

        if (
            stripos((string) $effectiveUrl, "login.php") !== false ||
            stripos($html, "Login into your account") !== false
        ) {
            return [
                "status" => "error",
                "message" => "ERP session is not authenticated. Please log into the ERP certificate page first.",
                "records" => []
            ];
        }

        $parsed = self::parseCertificateTable($html, $effectiveUrl ?: $url);
        if ($parsed["status"] !== "success") {
            return $parsed;
        }

        return [
            "status" => "success",
            "records" => $parsed["records"]
        ];
    }

    public static function parseCertificateTable($html, $baseUrl = "") {
        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $loaded = $dom->loadHTML($html);
        libxml_clear_errors();

        if (!$loaded) {
            return [
                "status" => "error",
                "message" => "Unable to parse ERP certificate HTML.",
                "records" => []
            ];
        }

        $xpath = new DOMXPath($dom);
        $tables = $xpath->query("//table");
        $records = [];

        foreach ($tables as $table) {
            $headerCells = $xpath->query(".//tr[1]/th | .//tr[1]/td", $table);
            $headers = [];

            foreach ($headerCells as $cell) {
                $headers[] = self::normalizeHeader($cell->textContent);
            }

            if (!in_array("subject", $headers, true) || !in_array("code", $headers, true)) {
                continue;
            }

            $indexMap = [
                "action" => array_search("action", $headers, true),
                "academic_year" => array_search("academic year", $headers, true),
                "season" => array_search("season", $headers, true),
                "program" => array_search("program", $headers, true),
                "sem" => array_search("sem", $headers, true),
                "code" => array_search("code", $headers, true),
                "subject" => array_search("subject", $headers, true),
                "usn" => array_search("usn", $headers, true),
                "name" => array_search("name", $headers, true),
                "grade" => array_search("grade", $headers, true),
                "date" => array_search("date", $headers, true)
            ];

            $rows = $xpath->query(".//tr[position()>1]", $table);
            foreach ($rows as $row) {
                $cells = $xpath->query("./td", $row);
                if ($cells->length === 0) {
                    continue;
                }

                $getCellText = function ($index) use ($cells) {
                    if ($index === false || $index === null || $index >= $cells->length) {
                        return "";
                    }

                    return trim((string) $cells->item($index)->textContent);
                };

                $downloadUrl = "";
                $actionLabel = "";
                if ($indexMap["action"] !== false && $indexMap["action"] < $cells->length) {
                    $actionCell = $cells->item($indexMap["action"]);
                    $actionLink = $actionCell->getElementsByTagName("a")->item(0);
                    if ($actionLink) {
                        $downloadUrl = self::absoluteUrl($baseUrl, trim((string) $actionLink->getAttribute("href")));
                        $actionLabel = trim((string) $actionLink->textContent);
                    } else {
                        $actionButton = $actionCell->getElementsByTagName("button")->item(0);
                        if ($actionButton) {
                            $actionLabel = trim((string) $actionButton->textContent);
                        } else {
                            $actionLabel = trim((string) $actionCell->textContent);
                        }
                    }
                }

                $subject = $getCellText($indexMap["subject"]);
                $code = $getCellText($indexMap["code"]);
                if ($subject === "" && $code === "") {
                    continue;
                }

                $records[] = [
                    "academic_year" => $getCellText($indexMap["academic_year"]),
                    "season" => $getCellText($indexMap["season"]),
                    "program" => $getCellText($indexMap["program"]),
                    "sem" => $getCellText($indexMap["sem"]),
                    "code" => $code,
                    "subject" => $subject,
                    "usn" => $getCellText($indexMap["usn"]),
                    "name" => $getCellText($indexMap["name"]),
                    "grade" => $getCellText($indexMap["grade"]),
                    "date" => $getCellText($indexMap["date"]),
                    "status" => self::normalizeStatus($actionLabel),
                    "download_url" => $downloadUrl,
                    "action_label" => $actionLabel
                ];
            }

            if (!empty($records)) {
                break;
            }
        }

        if (empty($records)) {
            return [
                "status" => "error",
                "message" => "No certificate rows were found in the ERP table.",
                "records" => []
            ];
        }

        return [
            "status" => "success",
            "records" => $records
        ];
    }

    public static function getFallbackCertificates() {
        return self::FALLBACK_CERTIFICATES;
    }

    public static function extractRequestedSubject($message) {
        $normalized = self::normalizeLookupText($message);
        if ($normalized === "") {
            return "";
        }

        $cleaned = preg_replace(
            '/\b(my|me|show|tell|which|what|is|are|the|a|an|certificate|certificates|competency|competence|competent|digital|download|available|to|for|of|status|can|i|have|list|name|names|named|all|any|give|display)\b/u',
            ' ',
            $normalized
        );
        $cleaned = preg_replace('/\s+/u', ' ', (string) $cleaned);
        $cleaned = trim((string) $cleaned);

        if ($cleaned === "") {
            return "";
        }

        $tokens = array_values(array_filter(explode(' ', $cleaned), function ($token) {
            return trim((string) $token) !== "";
        }));

        if (empty($tokens)) {
            return "";
        }

        $genericRemainders = [
            "list",
            "name",
            "names",
            "competent",
            "competency",
            "certificate",
            "certificates"
        ];

        $meaningfulTokens = array_values(array_filter($tokens, function ($token) use ($genericRemainders) {
            return !in_array($token, $genericRemainders, true);
        }));

        return empty($meaningfulTokens) ? "" : implode(' ', $meaningfulTokens);
    }

    public static function matchCertificatesBySubject($records, $message) {
        $requestedSubject = self::extractRequestedSubject($message);
        if ($requestedSubject === "") {
            return [];
        }

        $matches = [];
        foreach ($records as $record) {
            $subject = self::normalizeLookupText($record["subject"] ?? "");
            $code = self::normalizeLookupText($record["code"] ?? "");

            if (
                $subject === $requestedSubject ||
                $code === $requestedSubject ||
                strpos($subject, $requestedSubject) !== false ||
                strpos($requestedSubject, $subject) !== false ||
                ($code !== "" && strpos($requestedSubject, $code) !== false)
            ) {
                $matches[] = $record;
            }
        }

        return $matches;
    }
}
