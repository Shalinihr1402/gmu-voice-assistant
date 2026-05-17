<?php

class IntentService {
    private const DATABASE_ROUTE = "database";
    private const LLM_ROUTE = "llm";

    private static $intentPriority = [
        "GET_COURSE_CODE" => 120,
        "GET_SUBJECT_ATTENDANCE" => 110,
        "GET_ATTENDANCE" => 100,
        "GET_FINAL_REGISTRATION_STATUS" => 100,
        "GET_HALL_TICKET_STATUS" => 100,
        "GET_CERTIFICATE_STATUS" => 98,
        "GET_PROFILE_SUMMARY" => 95,
        "GET_FEES_BALANCE" => 90,
        "GET_BACKLOG_STATUS" => 90,
        "GET_CGPA" => 90,
        "GET_SGPA" => 85,
        "GET_COURSE_DETAILS" => 70,
        "GET_USN" => 65
    ];

    private static $intentMap = [
        "GET_USN" => [
            "usn",
            "my usn",
            "registration number",
            "university number"
        ],
        "GET_PROFILE_SUMMARY" => [
            "profile",
            "who am i",
            "do you know who i am",
            "my profile",
            "tell me about my profile",
            "student profile",
            "which semester am i in",
            "what semester am i in",
            "my semester",
            "which department am i from",
            "what department am i from",
            "my department",
            "my branch",
            "what am i studying"
        ],
        "GET_SGPA" => [
            "sgpa",
            "gpa",
            "semester gpa",
            "result",
            "semester result",
            "my result",
            "my sgpa",
            "score",
            "marks",
            "grade"
        ],
        "GET_CGPA" => [
            "cgpa",
            "overall gpa",
            "overall result",
            "cumulative gpa",
            "current cgpa"
        ],
        "GET_BACKLOG_STATUS" => [
            "backlog",
            "backlogs",
            "failed subject",
            "fail or pass",
            "pass or fail",
            "have i failed",
            "did i fail",
            "supplementary"
        ],
        "GET_FEES_BALANCE" => [
            "fee",
            "fees",
            "fee balance",
            "balance",
            "due",
            "pending amount",
            "amount due"
        ],
        "GET_FINAL_REGISTRATION_STATUS" => [
            "final registration",
            "registration status",
            "am i registered",
            "have i registered",
            "is my registration complete",
            "is my final registration complete",
            "registration completed",
            "registered or not"
        ],
        "GET_HALL_TICKET_STATUS" => [
            "hall ticket",
            "hallticket",
            "admission ticket",
            "ticket generated",
            "is my hall ticket generated",
            "my hall ticket status",
            "can i download hall ticket"
        ],
        "GET_CERTIFICATE_STATUS" => [
            "certificate",
            "certificates",
            "competency certificate",
            "digital competency certificate",
            "competence certificate",
            "certificate status",
            "which certificate is available",
            "what certificates are available",
            "can i download certificate"
        ],
        "GET_COURSE_DETAILS" => [
            "subject",
            "subjects",
            "course",
            "courses",
            "my subjects",
            "my courses",
            "what subjects do i have",
            "what courses do i have",
            "subject details",
            "course details",
            "registered subjects",
            "registered courses"
        ],
        "GET_ATTENDANCE" => [
            "my attendance",
            "overall attendance",
            "attendance percentage",
            "attendance status"
        ],
        "GET_SUBJECT_ATTENDANCE" => [
            "attendance in",
            "attendance of",
            "percentage in",
            "subject attendance"
        ],
        "GET_COURSE_CODE" => [
            "course code",
            "subject code",
            "code of",
            "code for",
            "what is the course of",
            "which course is"
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

    private static function normalizeIntentText($message) {
        $message = strtolower(trim((string) $message));
        $message = preg_replace('/\b(scores|score|marks|mark|grades|grading)\b/ui', " result ", $message);
        $message = self::canonicalizeHindiIntentTerms($message);
        $message = self::canonicalizeKannadaIntentTerms($message);
        $message = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $message);
        $message = preg_replace('/\s+/', ' ', (string) $message);
        return trim((string) $message);
    }

    private static function containsAny($message, $needles) {
        foreach ($needles as $needle) {
            if ($needle !== "" && strpos($message, $needle) !== false) {
                return true;
            }
        }

        return false;
    }

    private static function isSubjectAttendanceQuery($normalizedMessage, $rawMessage) {
        if (strpos($normalizedMessage, "attendance") === false) {
            return false;
        }

        if (self::containsAny($normalizedMessage, [
            "subject attendance",
            "subject wise attendance",
            "attendance in",
            "attendance of",
            "attendance for",
            "attendance related to",
            "particular subject",
            "individual subject",
            "course subject attendance"
        ])) {
            return true;
        }

        if (preg_match('/\b(cs\d+[a-z0-9]*|dbms|os|cn|ai)\b/i', $normalizedMessage)) {
            return true;
        }

        if (preg_match('/Ã Â²ÂµÃ Â²Â¿Ã Â²Â·Ã Â²Â¯|Ã Â²Â¸Ã Â²Â¬Ã Â³ÂÃ Â²Å“Ã Â³â€ Ã Â²â€¢Ã Â³ÂÃ Â²Å¸Ã Â³Â|Ã Â²â€¢Ã Â³â€¹Ã Â²Â°Ã Â³ÂÃ Â²Â¸Ã Â³Â|Ã Â²â€™Ã Â²â€šÃ Â²Â¦Ã Â³Â\s+Ã Â²ÂµÃ Â²Â¿Ã Â²Â·Ã Â²Â¯|Ã Â²ÂªÃ Â²Â°Ã Â³ÂÃ Â²Å¸Ã Â²Â¿Ã Â²â€¢Ã Â³ÂÃ Â²Â¯Ã Â³ÂÃ Â²Â²Ã Â²Â°Ã Â³Â/u', $rawMessage)) {
            return true;
        }

        if (self::containsAny($normalizedMessage, [
            "overall attendance",
            "my attendance",
            "attendance percentage",
            "attendance status",
            "total attendance"
        ])) {
            return false;
        }

        return false;
    }

    private static function isCertificateQuery($normalizedMessage, $rawMessage) {
        $hasCertificateWord =
            self::containsAny($normalizedMessage, [
                "certificate",
                "certificates",
                "competency certificate",
                "digital competency certificate",
                "digital certificate",
                "competence certificate",
                "certification"
            ]) ||
            preg_match('/à¤¸à¤°à¥à¤Ÿà¤¿à¤«à¤¿à¤•à¥‡à¤Ÿ|à¤¸à¤°à¥à¤Ÿà¥€à¤«à¤¿à¤•à¥‡à¤Ÿ|à¤ªà¥à¤°à¤®à¤¾à¤£à¤ªà¤¤à¥à¤°/u', $rawMessage) ||
            preg_match('/à²¸à²°à³à²Ÿà²¿à²«à²¿à²•à³‡à²Ÿà³|à²ªà³à²°à²®à²¾à²£à²ªà²¤à³à²°/u', $rawMessage);

        if ($hasCertificateWord) {
            return true;
        }

        $hasCertificateContext =
            self::containsAny($normalizedMessage, [
                "competency",
                "digital competency",
                "earned certificate",
                "my certificate list",
                "available certificate",
                "download certificate",
                "certificate status",
                "technical skills certificate",
                "co curricular certificate"
            ]);

        if (!$hasCertificateContext) {
            return false;
        }

        return self::containsAny($normalizedMessage, [
            "show",
            "tell",
            "list",
            "available",
            "download",
            "status",
            "got",
            "received",
            "earned",
            "have",
            "my",
            "which",
            "what"
        ]);
    }

    private static function canonicalizeHindiIntentTerms($message) {
        $replacements = [
            '/à¤«à¤¾à¤‡à¤¨à¤²|à¤…à¤‚à¤¤à¤¿à¤®/u' => ' final ',
            '/à¤°à¤œà¤¿à¤¸à¥à¤Ÿà¥à¤°à¥‡à¤¶à¤¨|à¤°à¤œà¤¿à¤¸à¥à¤Ÿà¥à¤°à¥‡à¤¸à¤¨|à¤°à¥‡à¤œà¤¿à¤¸à¥à¤Ÿà¥à¤°à¥‡à¤¶à¤¨|à¤ªà¤‚à¤œà¥€à¤•à¤°à¤£|à¤ªà¤‚à¤œà¥€à¤¯à¤¨/u' => ' registration ',
            '/à¤¹à¥‰à¤²\s*à¤Ÿà¤¿à¤•à¤Ÿ|à¤¹à¤¾à¤²\s*à¤Ÿà¤¿à¤•à¤Ÿ|à¤à¤¡à¤®à¤¿à¤Ÿ\s*à¤•à¤¾à¤°à¥à¤¡|à¤ªà¥à¤°à¤µà¥‡à¤¶\s*à¤ªà¤¤à¥à¤°/u' => ' hall ticket ',
            '/à¤¸à¥à¤Ÿà¥‡à¤Ÿà¤¸|à¤¸à¥à¤¥à¤¿à¤¤à¤¿|à¤¹à¤¾à¤²à¤¤/u' => ' status ',
            '/à¤ªà¥à¤°à¥‹à¤«à¤¾à¤‡à¤²|à¤ªà¥à¤°à¥‹à¤«à¤¼à¤¾à¤‡à¤²|à¤ªà¥à¤°à¥‹à¥žà¤¾à¤‡à¤²|à¤®à¥‡à¤°à¥‡\s+à¤¬à¤¾à¤°à¥‡|à¤®à¥‡à¤°à¤¾\s+à¤ªà¥à¤°à¥‹à¤«à¤¾à¤‡à¤²|à¤®à¥‡à¤°à¥€\s+à¤ªà¥à¤°à¥‹à¤«à¤¾à¤‡à¤²/u' => ' profile ',
            '/à¤«à¥€à¤¸|à¤«à¥€|à¤¶à¥à¤²à¥à¤•|à¤¬à¤•à¤¾à¤¯à¤¾/u' => ' fee balance due ',
            '/à¤…à¤Ÿà¥‡à¤‚à¤¡à¥‡à¤‚à¤¸|à¤…à¤Ÿà¥‡à¤‚à¤¡à¥‡à¤‚à¤¸|à¤‰à¤ªà¤¸à¥à¤¥à¤¿à¤¤à¤¿|à¤¹à¤¾à¤œà¤¿à¤°à¥€/u' => ' attendance ',
            '/à¤°à¤¿à¤œà¤²à¥à¤Ÿ|à¤°à¤¿à¥›à¤²à¥à¤Ÿ|à¤°à¥‡à¤œà¤²à¥à¤Ÿ|à¤°à¤¿à¤œà¤²|à¤°à¥‡à¤œà¤²|à¤°à¤œà¤²|à¤ªà¤°à¤¿à¤£à¤¾à¤®|à¤¨à¤¤à¥€à¤œà¤¾/u' => ' result ',
            '/à¤à¤¸à¤œà¥€à¤ªà¥€à¤|à¤à¤¸\s*à¤œà¥€\s*à¤ªà¥€\s*à¤/u' => ' sgpa ',
            '/à¤¸à¥€à¤œà¥€à¤ªà¥€à¤|à¤¸à¥€\s*à¤œà¥€\s*à¤ªà¥€\s*à¤/u' => ' cgpa ',
            '/à¤¬à¥ˆà¤•à¤²à¥‰à¤—|à¤¬à¥‡à¤•à¤²à¥‰à¤—|à¤¸à¤ªà¥à¤²à¥€à¤®à¥‡à¤‚à¤Ÿà¤°à¥€/u' => ' backlog ',
            '/à¤«à¥‡à¤²|à¤…à¤¸à¤«à¤²/u' => ' fail ',
            '/à¤ªà¤¾à¤¸|à¤‰à¤¤à¥à¤¤à¥€à¤°à¥à¤£/u' => ' pass ',
            '/à¤•à¥‹à¤°à¥à¤¸|à¤•à¥‹à¤°à¥à¤¸à¥‡à¤¸|à¤¸à¤¬à¥à¤œà¥‡à¤•à¥à¤Ÿ|à¤¸à¤¬à¥à¤œà¥‡à¤•à¥à¤Ÿà¥à¤¸|à¤µà¤¿à¤·à¤¯/u' => ' course subject ',
            '/à¤•à¥‹à¤¡/u' => ' code ',
            '/à¤¯à¥‚à¤à¤¸à¤à¤¨|à¤¯à¥‚\s*à¤à¤¸\s*à¤à¤¨/u' => ' usn ',
            '/à¤®à¥ˆà¤‚\s+à¤•à¥Œà¤¨/u' => ' who am i ',
            '/à¤¸à¥‡à¤®à¥‡à¤¸à¥à¤Ÿà¤°/u' => ' semester ',
            '/à¤¬à¥à¤°à¤¾à¤‚à¤š|à¤µà¤¿à¤­à¤¾à¤—|à¤¡à¤¿à¤ªà¤¾à¤°à¥à¤Ÿà¤®à¥‡à¤‚à¤Ÿ/u' => ' branch department ',
            '/à¤•à¤¿à¤¤à¤¨à¥€|à¤•à¤¿à¤¤à¤¨à¤¾/u' => ' how much ',
            '/à¤ªà¥‚à¤°à¤¾|à¤ªà¥‚à¤°à¥à¤£|à¤•à¤®à¥à¤ªà¥à¤²à¥€à¤Ÿ|à¤•à¤‚à¤ªà¥à¤²à¥€à¤Ÿ/u' => ' complete ',
            '/à¤ªà¥‡à¤‚à¤¡à¤¿à¤‚à¤—|à¤²à¤‚à¤¬à¤¿à¤¤/u' => ' pending ',
            '/à¤•à¥à¤¯à¤¾/u' => ' ',
            '/à¤®à¥‡à¤°à¤¾|à¤®à¥‡à¤°à¥€|à¤®à¥‡à¤°à¥‡|à¤…à¤ªà¤¨à¤¾|à¤…à¤ªà¤¨à¥€|à¤†à¤ªà¤•à¤¾|à¤†à¤ªà¤•à¥€/u' => ' my '
        ];

        $message = preg_replace(
            array_keys($replacements),
            array_values($replacements),
            (string) $message
        );
        $message = preg_replace('/\b(usn|u\s*s\s*n|yu\s*es\s*en|uesn|yuesen|yusn|upsn|usm|usf|u\s*s\s*m|u\s*s\s*f)\b/u', ' usn ', (string) $message);

        $message = preg_replace('/à¤¸à¤°à¥à¤Ÿà¤¿à¤«à¤¿à¤•à¥‡à¤Ÿ|à¤¸à¤°à¥à¤Ÿà¥€à¤«à¤¿à¤•à¥‡à¤Ÿ|à¤ªà¥à¤°à¤®à¤¾à¤£à¤ªà¤¤à¥à¤°/u', ' certificate ', (string) $message);
        $message = preg_replace('/à¤¡à¤¿à¤œà¤¿à¤Ÿà¤²/u', ' digital ', (string) $message);
        $message = preg_replace('/à¤¡à¤¾à¤‰à¤¨à¤²à¥‹à¤¡/u', ' download ', (string) $message);
        $message = preg_replace('/à¤‰à¤ªà¤²à¤¬à¥à¤§/u', ' available ', (string) $message);

        return $message;
    }

    private static function canonicalizeKannadaIntentTerms($message) {
        $replacements = [
            '/\b(nanna|nan|nanage|nanna\s+bagge|nanna\s+profile|nimma)\b/u' => ' my ',
            '/\b(dayavittu|swalpa|please)\b|à²¦à²¯à²µà²¿à²Ÿà³à²Ÿà³/u' => ' ',
            '/\b(enu|yenu|yen|helu|heli|tilsu|tilisi|torisu|torisi|beku|please tell)\b/u' => ' ',
            '/\b(profail|profle)\b|à²ªà³à²°à³Šà²«à³ˆà²²à³/u' => ' profile ',
            '/\b(semesteru|semister|sem)\b|à²¸à³†à²®à²¿à²¸à³à²Ÿà²°à³/u' => ' semester ',
            '/\b(departmentu|departmente|branchu|vibhaga)\b|à²µà²¿à²­à²¾à²—|à²¡à²¿à²ªà²¾à²°à³à²Ÿà³â€Œà²®à³†à²‚à²Ÿà³|à²¬à³à²°à²¾à²‚à²šà³/u' => ' branch department ',
            '/\b(feesu|feesu|fee|fi|baki|bakki|balanceu|balance|due|fees balance|fee balance)\b|à²¶à³à²²à³à²•|à²«à³€à²¸à³|à²«à³€|à²¬à²¾à²•à²¿|à²¬à³à²¯à²¾à²²à³†à²¨à³à²¸à³/u' => ' fee balance due ',
            '/\b(attendence|atendance|attendanceu|attendance|hajari)\b|à²¹à²¾à²œà²°à²¾à²¤à²¿|à²¹à²¾à²œà²°à²¿|à²…à²Ÿà³†à²‚à²¡à³†à²¨à³à²¸à³|à²…à²Ÿà³†à²‚à²¡à³†à²¨à³à²¸à³/u' => ' attendance ',
            '/\b(resultu|result|rijalt|resalt|phalithaansha|marks card)\b|à²«à²²à²¿à²¤à²¾à²‚à²¶|à²°à²¿à²¸à²²à³à²Ÿà³|à²°à²¿à²œà²²à³à²Ÿà³|à²®à²¾à²°à³à²•à³à²¸à³/u' => ' result ',
            '/\b(backlogu|back)\b|à²¬à³à²¯à²¾à²•à³à²²à²¾à²—à³/u' => ' backlog ',
            '/\b(faila|fail)\b|à²«à³‡à²²à³/u' => ' fail ',
            '/\b(passa|pass)\b|à²ªà²¾à²¸à³/u' => ' pass ',
            '/\b(courseu|coursu|subjectu|vishaya)\b|à²•à³‹à²°à³à²¸à³|à²¸à²¬à³à²œà³†à²•à³à²Ÿà³|à²µà²¿à²·à²¯/u' => ' course subject ',
            '/\b(codeu|kode)\b|à²•à³‹à²¡à³/u' => ' code ',
            '/\b(usn|yu es en|u s n|yu esn|uesn|yuesen|yusn|upsn)\b|à²¯à³à²Žà²¸à³â€Œà²Žà²¨à³|à²¯à³ à²Žà²¸à³ à²Žà²¨à³|à²¯à³à²Žà²¸à³à²Žà²¨à³|à²¯à³à²ªà²¿à²Žà²¸à²¨à³|à²¯à³ à²ªà²¿ à²Žà²¸à³ à²Žà²¨à³/u' => ' usn ',
            '/\b(sgpa|esjipie|s j p a)\b|à²Žà²¸à³â€Œà²œà²¿à²ªà²¿à²Ž|à²Žà²¸à³ à²œà²¿à²ªà²¿à²Ž/u' => ' sgpa ',
            '/\b(cgpa|sijipie|c j p a)\b|à²¸à²¿à²œà²¿à²ªà²¿à²Ž|à²¸à²¿ à²œà²¿à²ªà²¿à²Ž/u' => ' cgpa ',
            '/\b(final)\b|à²«à³ˆà²¨à²²à³|à²…à²‚à²¤à²¿à²®/u' => ' final ',
            '/\b(registrationu|rijistreshan|registrashan|regis tration|rijis tration|rijis treshan|rejistration)\b|à²°à²¿à²œà²¿à²¸à³à²Ÿà³à²°à³‡à²¶à²¨à³|à²°à²¿à²œà²¿à²¸à³ à²Ÿà³à²°à³‡à²¶à²¨à³|à²¨à³‹à²‚à²¦à²£à²¿/u' => ' registration ',
            '/\b(hallticket|hall\s*ticketu|haal ticket|hal ticket|all ticket|al ticket)\b|à²¹à²¾à²²à³\s*à²Ÿà²¿à²•à³†à²Ÿà³|à²†à²²à³\s*à²Ÿà²¿à²•à³†à²Ÿà³|à²…à²²à³\s*à²Ÿà²¿à²•à³†à²Ÿà³/u' => ' hall ticket ',
            '/\b(statusu)\b|à²¸à³à²¥à²¿à²¤à²¿/u' => ' status ',
            '/\b(yestu|eshtu|yeshtu|how much)\b|à²Žà²·à³à²Ÿà³/u' => ' how much ',
            '/\b(completea|completeda|complyta)\b|à²ªà³‚à²°à³à²£|à²•à²‚à²ªà³à²²à³€à²Ÿà³/u' => ' complete ',
            '/\b(pendinga|pending)\b|à²ªà³†à²‚à²¡à²¿à²‚à²—à³/u' => ' pending ',
            '/\b(naanu\s+yaaru|nanu\s+yaaru)\b|à²¨à²¾à²¨à³\s+à²¯à²¾à²°à³/u' => ' who am i ',
            '/\b(yava\s+semester|which\s+semester)\b|à²¯à²¾à²µ\s+à²¸à³†à²®à²¿à²¸à³à²Ÿà²°à³/u' => ' which semester ',
            '/\b(yava\s+department|yava\s+branch)\b|à²¯à²¾à²µ\s+à²µà²¿à²­à²¾à²—/u' => ' which department ',
            '/\b(heli|helu|tilisi|tilsu|torisu|show madi|open madi)\b|à²¹à³‡à²³à²¿|à²¹à³‡à²³à³|à²¤à²¿à²³à²¿à²¸à²¿|à²¤à³‹à²°à²¿à²¸à³/u' => ' '
        ];

        $message = preg_replace(
            array_keys($replacements),
            array_values($replacements),
            (string) $message
        );
        $message = preg_replace('/\b(usn|u\s*s\s*n|yu\s*es\s*en|uesn|yuesen|yusn|upsn|usm|usf|u\s*s\s*m|u\s*s\s*f)\b/u', ' usn ', (string) $message);

        $message = preg_replace('/à²¸à²°à³à²Ÿà²¿à²«à²¿à²•à³‡à²Ÿà³|à²ªà³à²°à²®à²¾à²£à²ªà²¤à³à²°/u', ' certificate ', (string) $message);
        $message = preg_replace('/à²¡à²¿à²œà²¿à²Ÿà²²à³/u', ' digital ', (string) $message);
        $message = preg_replace('/à²¡à³Œà²¨à³à²²à³‹à²¡à³|à²¡à³Œà²¨à³â€Œà²²à³‹à²¡à³/u', ' download ', (string) $message);
        $message = preg_replace('/à²²à²­à³à²¯/u', ' available ', (string) $message);

        $message = str_replace(
            [
                'course subject registration',
                'course subject status',
                'result status',
                'attendance status',
                'fee balance due status',
                'hall ticket status',
                'final registration status',
                'course registration status'
            ],
            [
                'course registration',
                'course details',
                'result',
                'attendance',
                'fee balance',
                'hall ticket',
                'final registration',
                'course registration'
            ],
            $message
        );

        return $message;
    }

    public static function classifyIntent($message, $userContext = []) {
        $message = trim((string) $message);
        $roleKey = strtolower(trim((string) ($userContext["role_key"] ?? "student")));

        if ($roleKey !== "student") {
            return [
                "route" => self::LLM_ROUTE,
                "intent" => "ROLE_AWARE_ASSIST",
                "confidence" => "medium",
                "source" => "role_policy"
            ];
        }

        $normalizedMessage = self::normalizeIntentText($message);
        $rawMessage = strtolower(trim((string) $message));

        if (self::isSubjectAttendanceQuery($normalizedMessage, $rawMessage)) {
            return [
                "route" => self::DATABASE_ROUTE,
                "intent" => "GET_SUBJECT_ATTENDANCE",
                "confidence" => "high",
                "source" => "subject_attendance_fast_path"
            ];
        }

        $fallbackIntent = self::detectIntentFallback($message);
        if ($fallbackIntent !== "UNKNOWN") {
            return [
                "route" => self::DATABASE_ROUTE,
                "intent" => $fallbackIntent,
                "confidence" => "medium",
                "source" => "keyword_fallback_fast"
            ];
        }

        $aiClassification = self::classifyWithAi($message);
        if ($aiClassification !== null) {
            if (
                $aiClassification["route"] === self::DATABASE_ROUTE &&
                $aiClassification["confidence"] === "low"
            ) {
                return [
                    "route" => self::LLM_ROUTE,
                    "intent" => "UNKNOWN",
                    "confidence" => "low",
                    "source" => "ai_classifier"
                ];
            }

            return $aiClassification;
        }

        return [
            "route" => self::LLM_ROUTE,
            "intent" => "UNKNOWN",
            "confidence" => "low",
            "source" => "keyword_fallback"
        ];
    }

    private static function classifyWithAi($message) {
        $apiKey = self::getEnvValue("GEMINI_API_KEY") ?: self::getEnvValue("GOOGLE_API_KEY");
        if (!$apiKey) {
            return null;
        }

        $model = self::getEnvValue("INTENT_CLASSIFIER_MODEL") ?: "gemini-2.5-flash";
        $allowedIntents = implode(", ", array_keys(self::$intentMap));
        $prompt = "Classify the user query for routing in a university assistant. "
            . "Return only JSON with keys route, intent, confidence. "
            . "route must be either database or llm. "
            . "intent must be one of {$allowedIntents}, ROLE_AWARE_ASSIST, or UNKNOWN. "
            . "Use database only when the query is a short factual student portal lookup that directly matches one database handler. "
            . "Use llm for ambiguous, conversational, multi-part, reasoning-heavy, or open-ended queries. "
            . "confidence must be high, medium, or low. "
            . "Query: " . $message;

        $payload = json_encode([
            "contents" => [[
                "role" => "user",
                "parts" => [[
                    "text" => $prompt
                ]]
            ]],
            "generationConfig" => [
                "temperature" => 0.1,
                "topP" => 0.8,
                "maxOutputTokens" => 100
            ]
        ]);

        $url = "https://generativelanguage.googleapis.com/v1beta/models/" . rawurlencode($model) . ":generateContent";
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "x-goog-api-key: " . $apiKey,
            "Content-Type: application/json"
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 4);

        $response = curl_exec($ch);
        $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $statusCode >= 400) {
            return null;
        }

        $data = json_decode($response, true);
        $parts = $data["candidates"][0]["content"]["parts"] ?? [];
        $text = "";
        foreach ($parts as $part) {
            $text .= (string) ($part["text"] ?? "");
        }

        $parsed = self::parseClassificationJson($text);
        if ($parsed === null) {
            return null;
        }

        $route = $parsed["route"] ?? self::LLM_ROUTE;
        $intent = $parsed["intent"] ?? "UNKNOWN";
        $confidence = $parsed["confidence"] ?? "low";

        $validRoute = in_array($route, [self::DATABASE_ROUTE, self::LLM_ROUTE], true);
        $validIntent = $intent === "UNKNOWN" || $intent === "ROLE_AWARE_ASSIST" || isset(self::$intentMap[$intent]);
        $validConfidence = in_array($confidence, ["high", "medium", "low"], true);

        if (!$validRoute || !$validIntent || !$validConfidence) {
            return null;
        }

        if ($route === self::DATABASE_ROUTE && !isset(self::$intentMap[$intent])) {
            return null;
        }

        return [
            "route" => $route,
            "intent" => $intent,
            "confidence" => $confidence,
            "source" => "ai_classifier"
        ];
    }

    private static function parseClassificationJson($text) {
        $text = trim((string) $text);
        if ($text === "") {
            return null;
        }

        if (preg_match('/\{.*\}/s', $text, $matches)) {
            $text = $matches[0];
        }

        $data = json_decode($text, true);
        return is_array($data) ? $data : null;
    }

    public static function detectIntent($message) {
        return self::detectIntentFallback($message);
    }

    private static function detectIntentFallback($message) {
        $rawMessage = strtolower(trim((string) $message));
        $message = self::normalizeIntentText($message);

        if ($message === "") {
            return "UNKNOWN";
        }

        if (
            preg_match('/à²¯à³\s*à²Ž\s*à²¸\s*à²Žà²¨à³/u', $rawMessage) ||
            preg_match('/à²¯à³\s*à²ªà²¿\s*à²Ž\s*à²¸\s*à²Žà²¨à³/u', $rawMessage) ||
            preg_match('/à²¯à³\s*à²ªà²¿\s*à²Žà²¸à³\s*à²Žà²¨à³/u', $rawMessage) ||
            preg_match('/à²¯à³\s*à²Žà²¸à³\s*à²Žà²¨à³/u', $rawMessage) ||
            preg_match('/\by\s*u\s*s\s*n\b/u', $rawMessage)
        ) {
            return "GET_USN";
        }

        if (
            preg_match('/à²¹à²¾à²²à³\s*à²Ÿà²¿à²•à³†à²Ÿà³/u', $rawMessage) ||
            preg_match('/à²†à²²à³\s*à²Ÿà²¿à²•à³†à²Ÿà³/u', $rawMessage) ||
            preg_match('/à²…à²²à³\s*à²Ÿà²¿à²•à³†à²Ÿà³/u', $rawMessage)
        ) {
            return "GET_HALL_TICKET_STATUS";
        }

        if (
            preg_match('/à²«à³ˆà²¨à²²à³\s*à²°à²¿à²œà²¿/u', $rawMessage) ||
            preg_match('/à²°à²¿à²œà²¿\s*à²¸à³à²Ÿà³à²°à³‡/u', $rawMessage) ||
            preg_match('/à²¨à³‹à²‚à²¦à²£à²¿/u', $rawMessage)
        ) {
            return "GET_FINAL_REGISTRATION_STATUS";
        }

        if (
            preg_match('/à²«à³€à²¸à³|à²«à³€\s|à²¬à²¾à²•à²¿|à²¬à³à²¯à²¾à²²à³†à²¨à³à²¸à³/u', $rawMessage)
        ) {
            return "GET_FEES_BALANCE";
        }

        if (
            preg_match('/à²…à²Ÿà³†à²‚à²¡|à²¹à²¾à²œà²°/u', $rawMessage)
        ) {
            return "GET_ATTENDANCE";
        }

        if (
            preg_match('/à²°à²¿à²¸à²²|à²°à²¿à²œà²²|à²«à²²à²¿à²¤à²¾à²‚à²¶|à²Žà²¸à³\s*à²œà²¿\s*à²ªà²¿\s*à²Ž/u', $rawMessage)
        ) {
            return "GET_SGPA";
        }

        if (
            preg_match('/à²¬à³à²¯à²¾à²•à³\s*(à²²à²¾à²—à³|à²²à³‹à²—à³|à²²à²¾à²•à³)(à³à²¸à³|à²¸à³)?|à²¬à³à²¯à²¾à²•à³?(à²²à²¾à²—à³|à²²à³‹à²—à³|à²²à²¾à²•à³)(à³à²¸à³|à²¸à³)?|à²«à³‡à²²à³|à²¸à²ªà³à²²à²¿à²®à³†à²‚à²Ÿà²°à²¿/u', $rawMessage) ||
            preg_match('/\b(backlog|backlogs|fail|failed|supplementary|supply)\b/', $rawMessage) ||
            preg_match('/à²¬à³à²¯à²¾.*(à²²à²¾à²—|à²²à³‹à²—|à²²à²¾à²•à³)/u', $rawMessage)
        ) {
            return "GET_BACKLOG_STATUS";
        }

        if (self::containsAny($message, ["usn", "my usn"])) {
            return "GET_USN";
        }

        if (self::isCertificateQuery($message, $rawMessage)) {
            return "GET_CERTIFICATE_STATUS";
        }

        if (self::containsAny($message, ["hall ticket", "hallticket"])) {
            return "GET_HALL_TICKET_STATUS";
        }

        if (self::containsAny($message, [
            "final registration",
            "registration status",
            "registered or not",
            "registration complete",
            "registration pending",
            "course registration"
        ])) {
            return "GET_FINAL_REGISTRATION_STATUS";
        }

        if (self::containsAny($message, [
            "fee balance",
            "fees balance",
            "pending amount",
            "amount due",
            "balance due",
            "fee",
            "fees"
        ])) {
            return "GET_FEES_BALANCE";
        }

        if (self::containsAny($message, [
            "profile",
            "who am i",
            "my semester",
            "which semester",
            "what semester",
            "my department",
            "which department",
            "my branch",
            "what am i studying"
        ])) {
            return "GET_PROFILE_SUMMARY";
        }

        if (self::containsAny($message, ["cgpa", "overall gpa", "cumulative gpa"])) {
            return "GET_CGPA";
        }

        if (self::containsAny($message, [
            "sgpa",
            "semester cgpa",
            "semester result",
            "my result",
            "result",
            "score",
            "marks",
            "grade"
        ])) {
            return "GET_SGPA";
        }

        if (self::containsAny($message, ["backlog", "failed subject", "supplementary", "fail"])) {
            return "GET_BACKLOG_STATUS";
        }

        if (
            preg_match('/\b(course|subject)\s+code\b/', $message) ||
            preg_match('/\bcode\s+(of|for)\b/', $message) ||
            preg_match('/\bwhat\s+is\s+the\s+course\s+of\b/', $message) ||
            preg_match('/\bwhich\s+course\s+is\b/', $message) ||
            preg_match('/\b(particular|specific)\s+(course|subject)\b/', $message) ||
            (strpos($message, "code") !== false && preg_match('/\b(dbms|os|cn|ai|course|subject)\b/', $message)) ||
            preg_match('/à²•à³‹à²¡à³/u', $rawMessage) ||
            preg_match('/course code|subject code/', $message)
        ) {
            return "GET_COURSE_CODE";
        }

        if (self::containsAny($message, [
            "course subject",
            "my courses",
            "my subjects",
            "course details",
            "subject details",
            "registered subjects",
            "registered courses",
            "course"
        ])) {
            return "GET_COURSE_DETAILS";
        }

        if (strpos($message, "attendance") !== false) {
            if (preg_match('/\battendance\s+(?:in|of|for)\b/', $message)) {
                return "GET_SUBJECT_ATTENDANCE";
            }

            $overallAttendanceHints = [
                "my attendance",
                "overall attendance",
                "attendance percentage",
                "attendance status",
                "total attendance",
                "attendance"
            ];

            foreach ($overallAttendanceHints as $hint) {
                if (strpos($message, $hint) !== false) {
                    return "GET_ATTENDANCE";
                }
            }

            return "GET_ATTENDANCE";
        }

        if (self::containsAny($message, [
            "student portal",
            "semester",
            "department",
            "branch",
            "result",
            "attendance",
            "registration",
            "hall ticket",
            "fee",
            "usn",
            "profile"
        ])) {
            return "GET_PROFILE_SUMMARY";
        }

        $bestIntent = "UNKNOWN";
        $bestScore = 0;

        foreach (self::$intentMap as $intent => $keywords) {
            $score = self::$intentPriority[$intent] ?? 50;

            foreach ($keywords as $keyword) {
                if (strpos($message, $keyword) !== false) {
                    $keywordWords = array_values(array_filter(explode(" ", $keyword)));
                    $scoreBoost = max(1, count($keywordWords)) * 10;

                    if ($score + $scoreBoost > $bestScore) {
                        $bestScore = $score + $scoreBoost;
                        $bestIntent = $intent;
                    }
                }
            }
        }

        return $bestIntent;
    }
}

