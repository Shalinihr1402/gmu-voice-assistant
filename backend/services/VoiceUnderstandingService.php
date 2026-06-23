<?php

require_once __DIR__ . "/MultilingualUnderstandingService.php";

class VoiceUnderstandingService {
    private static $lastResult = [];
    private static $lastNavigation = "";
    private static $lastNavigationTime = 0.0;
    private static $lastTranscript = "";
    private static $lastTranscriptTime = 0.0;

    private const NAVIGATION_PAGES = [
        "home" => ["home", "main page", "\xE0\xB2\xB9\xE0\xB3\x8B\xE0\xB2\xAE\xE0\xB3\x8D", "\xE0\xA4\xB9\xE0\xA5\x8B\xE0\xA4\xAE"],
        "dashboard" => ["dashboard", "dash board", "dashbord", "\xE0\xB2\xA1\xE0\xB3\x8D\xE0\xB2\xAF\xE0\xB2\xBE\xE0\xB2\xB6\xE0\xB3\x8D", "\xE0\xA4\xA1\xE0\xA5\x88\xE0\xA4\xB6\xE0\xA4\xAC\xE0\xA5\x8B\xE0\xA4\xB0\xE0\xA5\x8D\xE0\xA4\xA1"],
        "registration" => ["registration", "register", "registration page", "\xE0\xB2\xA8\xE0\xB3\x8B\xE0\xB2\x82\xE0\xB2\xA6\xE0\xB2\xA3\xE0\xB2\xBF", "\xE0\xA4\xB0\xE0\xA4\x9C\xE0\xA4\xBF\xE0\xA4\xB8\xE0\xA5\x8D\xE0\xA4\x9F\xE0\xA5\x8D\xE0\xA4\xB0\xE0\xA5\x87\xE0\xA4\xB6\xE0\xA4\xA8"],
        "profile" => ["profile", "profail", "profile page", "\xE0\xB2\xAA\xE0\xB3\x8D\xE0\xB2\xB0\xE0\xB3\x8A\xE0\xB2\xAB\xE0\xB3\x88\xE0\xB2\xB2\xE0\xB3\x8D", "\xE0\xA4\xAA\xE0\xA5\x8D\xE0\xA4\xB0\xE0\xA5\x8B\xE0\xA4\xAB\xE0\xA4\xBE\xE0\xA4\x87\xE0\xA4\xB2"],
        "payment" => ["payment", "payment page", "payment portal", "fee payment", "\xE0\xB2\xAA\xE0\xB3\x87\xE0\xB2\xAE\xE0\xB3\x86\xE0\xB2\x82\xE0\xB2\x9F\xE0\xB3\x8D", "\xE0\xA4\xAA\xE0\xA5\x87\xE0\xA4\xAE\xE0\xA5\x87\xE0\xA4\x82\xE0\xA4\x9F"],
        "results" => ["result page", "results", "marks page", "\xE0\xB2\xAB\xE0\xB2\xB2\xE0\xB2\xBF\xE0\xB2\xA4\xE0\xB2\xBE\xE0\xB2\x82\xE0\xB2\xB6", "\xE0\xA4\xB0\xE0\xA4\xBF\xE0\xA4\x9C\xE0\xA4\xB2\xE0\xA5\x8D\xE0\xA4\x9F"],
        "certificate" => ["certificate", "certificate page", "competency", "\xE0\xB2\xB8\xE0\xB2\xB0\xE0\xB3\x8D\xE0\xB2\x9F\xE0\xB2\xBF\xE0\xB2\xAB\xE0\xB2\xBF\xE0\xB2\x95\xE0\xB3\x87\xE0\xB2\x9F\xE0\xB3\x8D", "\xE0\xA4\xB8\xE0\xA4\xB0\xE0\xA5\x8D\xE0\xA4\x9F\xE0\xA4\xBF\xE0\xA4\xAB\xE0\xA4\xBF\xE0\xA4\x95\xE0\xA5\x87\xE0\xA4\x9F"],
        "portal" => ["portal", "role portal", "\xE0\xB2\xAA\xE0\xB3\x8B\xE0\xB2\xB0\xE0\xB3\x8D\xE0\xB2\x9F\xE0\xB2\xB2\xE0\xB3\x8D", "\xE0\xA4\xAA\xE0\xA5\x8B\xE0\xA4\xB0\xE0\xA5\x8D\xE0\xA4\x9F\xE0\xA4\xB2"]
    ];

    public static function getLastResult() {
        return self::$lastResult;
    }

    public static function understand($message, $language = "en", $context = [], $sttMeta = []) {
        $rawTranscript = trim((string) ($sttMeta["raw_transcript"] ?? $message));
        $correctedTranscript = trim((string) ($sttMeta["corrected_transcript"] ?? $message));
        $sourceText = trim((string) ($correctedTranscript !== "" ? $correctedTranscript : $message));
        $language = self::normalizeLanguage($language);

        $legacy = MultilingualUnderstandingService::understand($sourceText, $language, $context);
        $normalized = self::normalizeText((string) ($legacy["normalized"] ?? $sourceText));
        $normalized = self::expandCodeMixedTerms($normalized);
        $transcriptState = self::rememberTranscript($normalized);
        $entities = self::mergeEntities($legacy["entities"] ?? [], self::extractEntities($normalized));
        $intentRanking = self::rankIntent($normalized, $entities, $context);
        $navigation = self::detectNavigation($normalized);

        if ($navigation["target_page"] !== "" && !$transcriptState["duplicate"]) {
            $intentRanking = [
                "intent" => "OPEN_PAGE",
                "route" => "navigation",
                "confidence" => max($intentRanking["confidence"], $navigation["confidence"]),
                "source" => "voice_understanding_navigation"
            ];
            $entities["target_page"] = $navigation["target_page"];
        }

        $routingText = self::buildRoutingText($normalized, $entities, $intentRanking, (string) ($legacy["rewritten"] ?? ""));
        $voiceConfidence = self::combineConfidence($intentRanking["confidence"], $sttMeta, $legacy);
        $shouldClarify = self::shouldClarify($voiceConfidence, $intentRanking, $sttMeta, $normalized);

        self::$lastResult = [
            "raw_transcript" => $rawTranscript,
            "normalized_text" => $normalized,
            "intent" => $intentRanking["intent"],
            "entities" => $entities,
            "confidence" => $voiceConfidence,
            "route" => $intentRanking["route"],
            "should_clarify" => $shouldClarify,
            "routing_text" => $routingText,
            "normalized" => $normalized,
            "rewritten" => $routingText,
            "intent_hints" => array_values(array_filter([$intentRanking["intent"] === "UNKNOWN" ? null : $intentRanking["intent"]])),
            "has_useful_signal" => ($intentRanking["intent"] ?? "UNKNOWN") !== "UNKNOWN" || !empty($entities["subject"]) || !empty($entities["target_page"]),
            "language" => $language,
            "legacy" => $legacy,
            "debug" => [
                "intent_source" => $intentRanking["source"],
                "stt_confidence" => (float) ($sttMeta["transcript_confidence"] ?? 0),
                "mean_word_confidence" => (float) ($sttMeta["mean_word_confidence"] ?? 0),
                "low_confidence_word_count" => count(is_array($sttMeta["low_confidence_words"] ?? null) ? $sttMeta["low_confidence_words"] : []),
                "navigation" => $navigation,
                "transcript_state" => $transcriptState
            ]
        ];

        return self::$lastResult;
    }

    private static function normalizeLanguage($language) {
        $language = strtolower(trim((string) $language));
        if (in_array($language, ["kn", "kn-in", "kannada"], true)) {
            return "kn";
        }
        if (in_array($language, ["hi", "hi-in", "hindi"], true)) {
            return "hi";
        }
        return "en";
    }

    private static function normalizeText($text) {
        if (class_exists("Normalizer")) {
            $unicode = Normalizer::normalize((string) $text, Normalizer::FORM_C);
            if ($unicode !== false && $unicode !== null) {
                $text = $unicode;
            }
        }

        $text = mb_strtolower((string) $text, "UTF-8");
        $text = preg_replace('/[^\p{L}\p{M}\p{N}\s\-]+/u', ' ', $text);
        $text = preg_replace('/\s+/u', ' ', (string) $text);
        return trim((string) $text);
    }

    private static function expandCodeMixedTerms($text) {
        $patterns = [
            '/\b(hogu|hogi|tereyiri|tere|torisu|torisi|open madi|open madu|show madi|khol|kholo|dikhao|dikhaiye|batao|batado|jao|chalo)\b/u' => ' open ',
            '/\b(helu|heli|helri|tilisi|tilsu|batao|bataye|tell|say)\b/u' => ' tell ',
            '/\b(attendence|atendance|attendanceu|hajari)\b/u' => ' attendance ',
            '/\b(resultu|rijalt|resalt|marks card)\b/u' => ' result ',
            '/\b(feesu|fee balance|baki|bakki|due amount)\b/u' => ' fee balance ',
            '/\b(rijistreshan|registrashan|registation)\b/u' => ' registration ',
            '/\b(hallticket|haal ticket|all ticket)\b/u' => ' hall ticket ',
            '/\b(d\s*b\s*m\s*s|dbm\s*s)\b/u' => ' dbms ',
            '/\b(o\s*s|oprating|opereting)\b/u' => ' operating systems ',
            '/\b(c\s*n)\b/u' => ' computer networks ',
            '/\b(a\s*i|artifishal|artifishial|intelligance)\b/u' => ' artificial intelligence ',
            '/\b(u\s*s\s*n|yu\s*es\s*en|yuesen|yusn|upsn)\b/u' => ' usn ',
            '/\x{0CB9}\x{0CCB}\x{0C97}\x{0CC1}|\x{0CA4}\x{0CC6}\x{0CB0}\x{0CC6}|\x{0CA4}\x{0CCB}\x{0CB0}\x{0CBF}\x{0CB8}\x{0CC1}/u' => ' open ',
            '/\x{0CB9}\x{0CC7}\x{0CB3}\x{0CC1}|\x{0CB9}\x{0CC7}\x{0CB3}\x{0CBF}|\x{0CA4}\x{0CBF}\x{0CB3}\x{0CBF}\x{0CB8}\x{0CBF}/u' => ' tell ',
            '/\x{0C85}\x{0C9F}\x{0CC6}\x{0C82}\x{0CA1}\x{0CC6}\x{0CA8}\x{0CCD}\x{0CB8}\x{0CCD}|\x{0CB9}\x{0CBE}\x{0C9C}\x{0CB0}\x{0CBF}/u' => ' attendance ',
            '/\x{0CB0}\x{0CBF}\x{0CB8}\x{0CB2}\x{0CCD}\x{0C9F}\x{0CCD}|\x{0CAB}\x{0CB2}\x{0CBF}\x{0CA4}\x{0CBE}\x{0C82}\x{0CB6}/u' => ' result ',
            '/\x{0CB0}\x{0CBF}\x{0C9C}\x{0CBF}\x{0CB8}\x{0CCD}\x{0C9F}\x{0CCD}\x{0CB0}\x{0CC7}\x{0CB7}\x{0CA8}\x{0CCD}|\x{0CA8}\x{0CCB}\x{0C82}\x{0CA6}\x{0CA3}\x{0CBF}/u' => ' registration ',
            '/\x{0CA1}\x{0CBF}\x{0CAC}\x{0CBF}\x{0C8E}\x{0C82}\x{0C8E}\x{0CB8}\x{0CCD}/u' => ' dbms ',
            '/\x{0CA1}\x{0CC0}\x{0CAC}\x{0CC0}\x{0C8E}\x{0CAE}\x{0C8E}\x{0CB8}\x{0CCD}/u' => ' dbms ',
            '/\x{0C95}\x{0CCA}\x{0CB2}\x{0CCB}|\x{0CA6}\x{0CBF}\x{0C96}\x{0CBE}\x{0C93}|\x{0CAC}\x{0CA4}\x{0CBE}\x{0C93}/u' => ' tell '
        ];

        $text = preg_replace(array_keys($patterns), array_values($patterns), (string) $text);
        $text = preg_replace('/\s+/u', ' ', (string) $text);
        return trim((string) $text);
    }

    private static function mergeEntities($base, $extra) {
        $merged = is_array($base) ? $base : [];
        foreach ($extra as $key => $value) {
            if (($merged[$key] ?? null) === "" || ($merged[$key] ?? null) === null || !array_key_exists($key, $merged)) {
                $merged[$key] = $value;
            }
        }
        return $merged;
    }

    private static function extractEntities($text) {
        $subject = "";
        $subjects = [
            "dbms" => "database management systems",
            "database management systems" => "database management systems",
            "operating systems" => "operating systems",
            "computer networks" => "computer networks",
            "artificial intelligence" => "artificial intelligence",
            "design and analysis of algorithms" => "design and analysis of algorithms",
            "java" => "java",
            "python" => "python",
            "software engineering" => "software engineering",
            "web technology" => "web technology",
            "data structures" => "data structures"
        ];

        foreach ($subjects as $needle => $canonical) {
            if (strpos($text, $needle) !== false) {
                $subject = $canonical;
                break;
            }
        }

        $semester = null;
        if (preg_match('/\b(?:semester|sem)\s*(\d{1,2})\b/u', $text, $matches)) {
            $semester = (int) $matches[1];
        }

        return [
            "subject" => $subject,
            "semester" => $semester
        ];
    }

    private static function rankIntent($text, $entities, $context) {
        $scores = [
            "GET_SUBJECT_ATTENDANCE" => self::score($text, ["attendance"]) + (!empty($entities["subject"]) ? 35 : 0),
            "GET_COURSE_CODE" => self::score($text, ["course code", "subject code", " code "]) + (!empty($entities["subject"]) ? 25 : 0),
            "GET_ATTENDANCE" => self::score($text, ["attendance", "overall"]),
            "GET_SGPA" => self::score($text, ["sgpa", "result", "marks"]),
            "GET_CGPA" => self::score($text, ["cgpa", "overall gpa"]),
            "GET_PROFILE_SUMMARY" => self::score($text, ["profile", "who am i", "department", "branch"]),
            "GET_FACULTY_DETAILS" => self::score($text, ["faculty", "faculty details", "teacher details", "staff details", "professor"]),
            "GET_USN" => self::score($text, ["usn", "registration number", "university number"]),
            "GET_FEES_BALANCE" => self::score($text, ["fee balance", "fees", "fee", "due"]),
            "GET_FINAL_REGISTRATION_STATUS" => self::score($text, ["registration status", "final registration", "registration"]),
            "GET_HALL_TICKET_STATUS" => self::score($text, ["hall ticket", "admit card"]),
            "GET_CERTIFICATE_STATUS" => self::score($text, ["certificate", "competency"]),
            "GET_BACKLOG_STATUS" => self::score($text, ["backlog", "failed", "supplementary"]),
            "GET_COURSE_DETAILS" => self::score($text, ["course details", "subject list", "subjects", "courses"])
        ];

        $lastIntent = (string) ($context["intent"] ?? "");
        if (!empty($entities["subject"]) && in_array($lastIntent, ["GET_ATTENDANCE", "GET_SUBJECT_ATTENDANCE"], true)) {
            $scores["GET_SUBJECT_ATTENDANCE"] += 18;
        }

        arsort($scores);
        $intent = (string) array_key_first($scores);
        $score = (int) ($scores[$intent] ?? 0);

        if ($score <= 0) {
            return ["intent" => "UNKNOWN", "route" => "llm", "confidence" => 0.25, "source" => "voice_understanding_no_match"];
        }

        $route = $intent === "GET_FACULTY_DETAILS" ? "llm" : "database";

        return [
            "intent" => $intent,
            "route" => $route,
            "confidence" => min(0.95, 0.45 + ($score / 100)),
            "source" => "voice_understanding_ranker"
        ];
    }

    private static function score($text, $needles) {
        $score = 0;
        $padded = " " . $text . " ";
        foreach ($needles as $needle) {
            if (strpos($padded, $needle) !== false) {
                $score += 35 + (substr_count($needle, " ") * 5);
            }
        }
        return $score;
    }

    private static function detectNavigation($text) {
        $text = self::normalizeText($text);
        if ($text === "") {
            return ["target_page" => "", "confidence" => 0.0, "reason" => "empty"];
        }

        $explicitHome = self::isExplicitHomeNavigation($text);
        $hasVerb = $explicitHome || self::hasNavigationVerb($text);
        if (!$hasVerb) {
            return ["target_page" => "", "confidence" => 0.0, "reason" => "missing_navigation_verb"];
        }

        $bestPage = $explicitHome ? "home" : "";
        $bestScore = $explicitHome ? 120 : 0;
        $pagePriority = ["registration", "certificate", "payment", "results", "profile", "dashboard", "portal"];

        foreach ($pagePriority as $page) {
            if ($page === "certificate" && self::isCertificateStatusQuery($text)) {
                continue;
            }

            $score = self::scorePageMatch($text, $page, self::NAVIGATION_PAGES[$page] ?? []);
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestPage = $page;
            }
        }

        if ($bestPage === "" || $bestScore < 40) {
            return ["target_page" => "", "confidence" => 0.0, "reason" => "missing_target_page"];
        }

        if (!self::canNavigate($bestPage, $text)) {
            return ["target_page" => "", "confidence" => 0.0, "reason" => "navigation_cooldown"];
        }

        return [
            "target_page" => $bestPage,
            "confidence" => min(0.95, 0.62 + ($bestScore / 200)),
            "reason" => "strict_navigation_match"
        ];
    }

    private static function hasNavigationVerb($text) {
        return (bool) preg_match('/\b(open|go\s+to|go|goto|navigate|show|visit|launch|take\s+me|bring\s+me|kholo|khol|dikhao|dikhaiye|jao|chalo|torisu|torisi|hogu|hogi|open\s+madi|open\s+madu|show\s+madi|tere|tereyiri)\b/u', $text);
    }

    private static function isExplicitHomeNavigation($text) {
        return (bool) preg_match('/\b(come\s+back|go\s+back|return\s+home|go\s+home)\b/u', $text);
    }

    private static function isCertificateStatusQuery($text) {
        return (bool) preg_match('/\b(certificate\s+(status|issue|problem|details?|information)|certificate\s+(not|missing|pending)|competency\s+(status|issue|problem))\b/u', $text)
            && !preg_match('/\b(open|go\s+to|navigate|show\s+page|certificate\s+page|take\s+me|kholo|torisu|hogu|open\s+madi)\b/u', $text);
    }

    private static function scorePageMatch($text, $page, $terms) {
        $score = 0;
        $padded = " " . $text . " ";
        foreach ($terms as $term) {
            $term = trim(mb_strtolower((string) $term, "UTF-8"));
            if ($term === "" || in_array($term, ["page", "portal"], true)) {
                continue;
            }

            $pattern = '/\b' . preg_quote($term, '/') . '\b/u';
            if (preg_match($pattern, $padded)) {
                $score += 40 + (substr_count($term, " ") * 10);
            } elseif (strpos($term, " ") !== false && strpos($text, $term) !== false) {
                $score += 35;
            }
        }

        if ($page === "dashboard" && !preg_match('/\b(student\s+dashboard|dashboard\s+page|open\s+dashboard|go\s+to\s+dashboard|navigate\s+dashboard|dashboard)\b/u', $text)) {
            $score = 0;
        }

        return $score;
    }

    private static function canNavigate($page, $text) {
        $signature = $page . "|" . self::normalizeText($text);
        $now = microtime(true);
        if (self::$lastNavigation === $signature && ($now - self::$lastNavigationTime) < 3.0) {
            return false;
        }
        self::$lastNavigation = $signature;
        self::$lastNavigationTime = $now;
        return true;
    }

    private static function rememberTranscript($text) {
        $signature = self::normalizeText($text);
        $now = microtime(true);
        $duplicate = $signature !== "" && self::$lastTranscript === $signature && ($now - self::$lastTranscriptTime) < 3.0;
        self::$lastTranscript = $signature;
        self::$lastTranscriptTime = $now;
        return ["signature" => $signature, "duplicate" => $duplicate];
    }
    private static function buildRoutingText($text, $entities, $intentRanking, $legacyRewrite) {
        $intent = $intentRanking["intent"] ?? "UNKNOWN";
        $subject = trim((string) ($entities["subject"] ?? ""));

        if ($intent === "OPEN_PAGE" && !empty($entities["target_page"])) {
            return $text;
        }
        if ($intent === "GET_SUBJECT_ATTENDANCE" && $subject !== "") {
            return "attendance in " . $subject;
        }
        if ($intent === "GET_COURSE_CODE" && $subject !== "") {
            return "course code for " . $subject;
        }
        if ($intent === "GET_SGPA" && !empty($entities["semester"])) {
            return "sgpa semester " . (int) $entities["semester"];
        }
        return $text;
    }

    private static function combineConfidence($intentConfidence, $sttMeta, $legacy) {
        $intentConfidence = (float) $intentConfidence;
        $sttConfidence = (float) ($sttMeta["transcript_confidence"] ?? 0);
        $meanWordConfidence = (float) ($sttMeta["mean_word_confidence"] ?? 0);

        if ($sttConfidence <= 0 && $meanWordConfidence <= 0) {
            return round($intentConfidence, 2);
        }

        $speechConfidence = max($sttConfidence, $meanWordConfidence);
        if (!empty($legacy["has_useful_signal"])) {
            $speechConfidence = max($speechConfidence, 0.7);
        }

        return round(min(0.98, ($intentConfidence * 0.65) + ($speechConfidence * 0.35)), 2);
    }

    private static function shouldClarify($confidence, $intentRanking, $sttMeta, $text) {
        if (($intentRanking["route"] ?? "") === "navigation" && !empty($intentRanking["intent"])) {
            return $confidence < 0.55;
        }

        $lowWords = is_array($sttMeta["low_confidence_words"] ?? null) ? $sttMeta["low_confidence_words"] : [];
        if ($confidence < 0.45) {
            return true;
        }
        if (($intentRanking["intent"] ?? "UNKNOWN") === "UNKNOWN" && count($lowWords) >= 3) {
            return true;
        }
        return trim($text) === "";
    }
}




