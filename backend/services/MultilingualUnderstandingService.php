<?php

class MultilingualUnderstandingService {
    private static $lastMeta = [];

    public static function getLastMeta() {
        return self::$lastMeta;
    }

    public static function understand($message, $language = "en", $context = []) {
        $raw = self::normalizeUnicode(trim((string) $message));
        $normalized = self::normalizeText($raw);
        $normalized = self::normalizeSubjects($normalized);
        $normalized = self::normalizeAcademicIntents($normalized);
        $normalized = self::normalizeNavigation($normalized);
        $normalized = self::cleanup($normalized);

        $entities = self::extractEntities($normalized);
        $intentHints = self::detectIntentHints($normalized);
        $rewritten = self::buildRoutingText($normalized, $entities, $intentHints, $context);
        $hasUsefulSignal = !empty($intentHints) || !empty($entities["subject"]) || !empty($entities["target_page"]);

        self::$lastMeta = [
            "raw" => $raw,
            "normalized" => $normalized,
            "rewritten" => $rewritten,
            "language" => self::normalizeLanguage($language),
            "entities" => $entities,
            "intent_hints" => $intentHints,
            "has_useful_signal" => $hasUsefulSignal
        ];

        return self::$lastMeta;
    }

    private static function normalizeLanguage($language) {
        $language = strtolower(trim((string) $language));
        if (in_array($language, ["hi", "hindi", "hi-in"], true)) {
            return "hi";
        }
        if (in_array($language, ["kn", "kannada", "kn-in"], true)) {
            return "kn";
        }
        return "en";
    }

    private static function normalizeUnicode($text) {
        if (class_exists("Normalizer")) {
            $normalized = Normalizer::normalize((string) $text, Normalizer::FORM_C);
            if ($normalized !== false && $normalized !== null) {
                return $normalized;
            }
        }
        return (string) $text;
    }

    private static function normalizeText($text) {
        $text = self::normalizeUnicode($text);
        $text = mb_strtolower($text, "UTF-8");
        $text = str_replace(["’", "'", "`", "\""], " ", $text);
        $text = preg_replace('/[,.!?;:()\[\]{}]+/u', ' ', $text);
        $text = preg_replace('/\s+/u', ' ', (string) $text);
        return trim((string) $text);
    }

    private static function applyPatterns($text, $patterns) {
        foreach ($patterns as $pattern => $replacement) {
            $text = preg_replace($pattern, " " . $replacement . " ", $text);
        }
        return self::cleanup($text);
    }

    private static function normalizeSubjects($text) {
        $patterns = [
            '/\b(d\s*b\s*m\s*s|dbm\s*s|dbms|database\s+management\s+systems?|database\s+systems?)\b/u' => 'dbms',
            '/ಡಿಬಿಎಂಎಸ್|ಡಿ\s*ಬಿ\s*ಎಂ\s*ಎಸ್|ಡೇಟಾಬೇಸ್\s*ಮ್ಯಾನೇಜ್ಮೆಂಟ್/u' => 'dbms',
            '/\b(o\s*s|os|operating\s+systems?|oprating\s+systems?|opereting\s+systems?)\b/u' => 'operating systems',
            '/ಆಪರೇಟಿಂಗ್\s*ಸಿಸ್ಟಂ(?:ಸ್)?|ಅಪರೇಟಿಂಗ್\s*ಸಿಸ್ಟಂ(?:ಸ್)?/u' => 'operating systems',
            '/\b(c\s*n|cn|computer\s+networks?|computer\s+net\s*works?)\b/u' => 'computer networks',
            '/ಕಂಪ್ಯೂಟರ್\s*ನೆಟ್\s*ವರ್ಕ್ಸ್?|ನೆಟ್\s*ವರ್ಕ್ಸ್?/u' => 'computer networks',
            '/\b(a\s*i|ai|artificial\s+intelligence|artif(?:cial|ishal|ishial|icial)\s+intellig(?:ence|ance|ense)|rtifcial\s+intelligance)\b/u' => 'artificial intelligence',
            '/ಆರ್ಟಿ?ಫಿ?ಶಿ?ಯಲ್\s*ಇಂಟೆಲಿಜೆನ್ಸ್|ಆರ್ಟ್\S*\s*ಇಂಟೆ\S*|ಇಂಟೆಲಿಜೆನ್ಸ್|ಮುಷಿಲು\s*ಇಂಟೆ\S*/u' => 'artificial intelligence',
            '/\b(ada|design\s+and\s+analysis\s+of\s+algorithms?|analysis\s+of\s+algorithms?)\b/u' => 'design and analysis of algorithms',
            '/ಅಲ್ಗೊರಿದಮ್(?:ಸ್)?|ಅಡಾ/u' => 'design and analysis of algorithms',
            '/\b(java|python|software\s+engineering|web\s+technology|data\s+structures?)\b/u' => '$0'
        ];
        return self::applyPatterns($text, $patterns);
    }

    private static function normalizeAcademicIntents($text) {
        $patterns = [
            '/\b(attendence|atendance|attendanceu|attendance|hajari)\b|ಅಟೆಂಡೆನ್ಸ್|ಹಾಜರಿ|ಹಾಜರಾತಿ/u' => 'attendance',
            '/\b(resultu|result|results|marks|marksheet|grade\s*sheet|rijalt|resalt|sgpa)\b|ರಿಸಲ್ಟ್|ರಿಜಲ್ಟ್|ಫಲಿತಾಂಶ|ಮಾರ್ಕ್ಸ್|परिणाम|रिजल्ट/u' => 'result',
            '/\b(cgpa|overall\s+gpa|cumulative\s+gpa)\b/u' => 'cgpa',
            '/\b(sgpa|semester\s+gpa|semester\s+result)\b/u' => 'sgpa',
            '/\b(profile|profail|profle|who\s+am\s+i|department|branch)\b|ಪ್ರೊಫೈಲ್|विभाग|प्रोफाइल/u' => 'profile',
            '/\b(usn|u\s*s\s*n|registration\s+number|university\s+number|yu\s*es\s*en|uesn|yuesen|yusn|upsn)\b|ಯು\s*ಎಸ್\s*ಎನ್|ಯುಎಸ್‌ಎನ್|यूएसएन/u' => 'usn',
            '/\b(fee|fees|feesu|balance|due|pending\s+amount|payment)\b|ಫೀಸ್|ಫೀ|ಬಾಕಿ|ಬ್ಯಾಲೆನ್ಸ್|ಪೇಮೆಂಟ್|फीस|बकाया/u' => 'fee balance',
            '/\b(registration|register|rijistreshan|final\s+registration|course\s+registration)\b|ರಿಜಿಸ್ಟ್ರೇಷನ್|ನೋಂದಣಿ|रजिस्ट्रेशन/u' => 'registration',
            '/\b(hall\s*ticket|hallticket|admit\s*card|exam\s*ticket)\b|ಹಾಲ್\s*ಟಿಕೆಟ್|हाल\s*टिकट/u' => 'hall ticket',
            '/\b(certificate|certificates|competency|digital\s+certificate)\b|ಸರ್ಟಿಫಿಕೇಟ್|प्रमाणपत्र|सर्टिफिकेट/u' => 'certificate',
            '/\b(course\s+code|subject\s+code|code\s+(of|for)|codeu|kode)\b|ಕೋರ್ಸ್\s*ಕೋಡ್|ಸಬ್ಜೆಕ್ಟ್\s*ಕೋಡ್|ವಿಷಯದ\s*ಕೋಡ್|ಕೋಡ್/u' => 'course code',
            '/\\b(courses|subjects|course\\s+details|subject\\s+details|subject\\s+list|course\\s+list|registered\\s+subjects|registered\\s+courses)\\b|ಸಬ್ಜೆಕ್ಟ್\\s*ಲಿಸ್ಟ್|ವಿಷಯಗಳ/u' => 'course details',
            '/\b(backlog|backlogs|failed\s+subject|fail|supplementary)\b|ಬ್ಯಾಕ್ಲಾಗ್|ಫೇಲ್/u' => 'backlog',
            '/\b(dashboard|home|portal|page)\b|ಡ್ಯಾಶ್\s*ಬೋರ್ಡ್|ಹೋಮ್|ಪೋರ್ಟಲ್|ಪೇಜ್/u' => '$0',
            '/\b(grievance|complaint|issue|problem|receipt)\b|ಅಹವಾಲು|ದೂರು/u' => '$0'
        ];
        return self::applyPatterns($text, $patterns);
    }

    private static function normalizeNavigation($text) {
        $patterns = [
            '/\b(open|go|navigate|show|take\s+me|bring\s+me|visit|launch|hogu|tereyiri|tere|torisu|show\s+madi|open\s+madi)\b|ಹೋಗು|ಹೋಗಿ|ತೆರೆ|ತೆರೆಯಿರಿ|ತೋರಿಸು|ತೋರಿಸಿ|ಮಾಡು|ಮಾಡಿ|खोलो|दिखाओ/u' => 'open'
        ];
        return self::applyPatterns($text, $patterns);
    }

    private static function extractEntities($text) {
        $subject = "";
        $subjectMap = [
            'dbms' => 'database management systems',
            'operating systems' => 'operating systems',
            'computer networks' => 'computer networks',
            'artificial intelligence' => 'artificial intelligence',
            'design and analysis of algorithms' => 'design and analysis of algorithms',
            'java' => 'java',
            'python' => 'python',
            'software engineering' => 'software engineering',
            'web technology' => 'web technology',
            'data structures' => 'data structures'
        ];

        foreach ($subjectMap as $needle => $canonical) {
            if (strpos($text, $needle) !== false) {
                $subject = $canonical;
                break;
            }
        }

        $semester = null;
        if (preg_match('/\b(?:semester|sem)\s*(\d+)\b/u', $text, $matches)) {
            $semester = (int) $matches[1];
        }

        return [
            'subject' => $subject,
            'semester' => $semester,
            'target_page' => self::detectTargetPage($text)
        ];
    }

    private static function detectIntentHints($text) {
        $intentRules = [
            'GET_SUBJECT_ATTENDANCE' => ['attendance'],
            'GET_COURSE_CODE' => ['course code'],
            'GET_SGPA' => ['result', 'sgpa'],
            'GET_CGPA' => ['cgpa'],
            'GET_PROFILE_SUMMARY' => ['profile'],
            'GET_USN' => ['usn'],
            'GET_FEES_BALANCE' => ['fee balance'],
            'GET_FINAL_REGISTRATION_STATUS' => ['registration'],
            'GET_HALL_TICKET_STATUS' => ['hall ticket'],
            'GET_CERTIFICATE_STATUS' => ['certificate'],
            'GET_BACKLOG_STATUS' => ['backlog'],
            'GET_COURSE_DETAILS' => ['course details']
        ];

        $hints = [];
        foreach ($intentRules as $intent => $needles) {
            foreach ($needles as $needle) {
                if (strpos($text, $needle) !== false) {
                    $hints[] = $intent;
                    break;
                }
            }
        }

        if (strpos($text, 'open') !== false) {
            $hints[] = 'NAVIGATION';
        }

        return array_values(array_unique($hints));
    }

    private static function buildRoutingText($text, $entities, $intentHints, $context) {
        $subject = trim((string) ($entities['subject'] ?? ''));

        if ($subject !== '' && in_array('GET_SUBJECT_ATTENDANCE', $intentHints, true)) {
            return 'attendance in ' . $subject;
        }

        if ($subject !== '' && in_array('GET_COURSE_CODE', $intentHints, true)) {
            return 'course code for ' . $subject;
        }

        if ($subject !== '' && (in_array('GET_SGPA', $intentHints, true) || strpos($text, 'marks') !== false)) {
            return 'result for ' . $subject;
        }

        if ($subject !== '' && empty($intentHints)) {
            $lastIntent = $context['intent'] ?? '';
            if (in_array($lastIntent, ['GET_ATTENDANCE', 'GET_SUBJECT_ATTENDANCE'], true)) {
                return 'attendance in ' . $subject;
            }
            if ($lastIntent === 'GET_COURSE_CODE') {
                return 'course code for ' . $subject;
            }
        }

        // Navigation is intentionally not rewritten here.
        // This service only normalizes text and exposes entities/intent hints.
        // VapiToolService is the single source of truth for page routing.
        return $text;
    }

    private static function detectTargetPage($text) {
        $text = self::cleanup((string) $text);
        if ($text === '') {
            return '';
        }

        if (preg_match('/\b(come\s+back|go\s+back|return\s+home|go\s+home)\b/u', $text)) {
            return 'home';
        }
        if (preg_match('/\b(registration|register|registation|ragistration|rijistreshan)\b/u', $text)) {
            return 'registration';
        }
        if (preg_match('/\b(profile|profail|profle)\b/u', $text)) {
            return 'profile';
        }
        if (preg_match('/\b(payment|payment\s+portal|fee\s+payment|fees\s+payment)\b/u', $text)) {
            return 'payment';
        }
        if (preg_match('/\b(competency\s+certificate|digital\s+competency|digital\s+certificate|certificate)\b/u', $text)) {
            return 'certificate';
        }
        if (preg_match('/\b(student\s+dashboard|dashboard\s+page|main\s+dashboard|dashboard|dash\s*board|dashbourd|dashbord)\b/u', $text)) {
            return 'dashboard';
        }
        if (preg_match('/\b(result|results|marksheet|marks|result\s+page)\b/u', $text)) {
            return 'results';
        }
        if (preg_match('/\b(portal|role\s+portal)\b/u', $text)) {
            return 'portal';
        }

        return '';
    }
    private static function cleanup($text) {
        $text = preg_replace('/\b(please|kindly|dayavittu|swalpa|helu|heli|tilisi|tilsu|beku|andre|artha|nanna|nan|nana|my|ನನ್ನ|ನನಗೆ|ಹೇಳು|ಹೇಳಿ|ತಿಳಿಸಿ|ಬೇಕು|ದಯವಿಟ್ಟು)\b/u', ' ', (string) $text);
        $text = preg_replace('/\s+/u', ' ', (string) $text);
        return trim((string) $text);
    }
}
