<?php

require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/KnowledgeBaseService.php";
require_once __DIR__ . "/ConversationContextService.php";

class LlmService {
    private static $lastReplyMeta = [
        "source" => "unknown"
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

    private static function getStudentName($student_id) {
        global $conn;

        $stmt = $conn->prepare("
            SELECT full_name
            FROM students
            WHERE student_id = ?
        ");

        if (!$stmt) {
            return null;
        }

        $stmt->bind_param("i", $student_id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $result["full_name"] ?? null;
    }

    private static function clearConversationHistory() {
        ConversationContextService::clear();
    }

    private static function buildKnowledgeContextBlock($knowledgeItems) {
        if (empty($knowledgeItems)) {
            return "";
        }

        $lines = [];
        foreach ($knowledgeItems as $index => $item) {
            $topic = trim((string) ($item["topic"] ?? ""));
            $content = trim((string) ($item["content"] ?? ""));
            $source = trim((string) ($item["source"] ?? ("context_" . ($index + 1))));

            if ($topic === "" || $content === "") {
                continue;
            }

            $lines[] = "[" . $source . "] " . $topic . ": " . $content;
        }

        if (empty($lines)) {
            return "";
        }

        return "Retrieved university context:\n" . implode("\n", $lines);
    }

    private static function buildMemoryContextBlock($contextPayload) {
        $summary = trim((string) ($contextPayload["summary"] ?? ""));
        if ($summary === "") {
            return "";
        }

        return "Conversation memory summary:\n" . $summary;
    }

    private static function buildPreferenceContextBlock($contextPayload) {
        $preferences = $contextPayload["preferences"] ?? [];
        if (!is_array($preferences) || empty($preferences)) {
            return "";
        }

        $lines = [];
        $preferredLanguage = trim((string) ($preferences["preferred_language"] ?? ""));
        $lastTopic = trim((string) ($preferences["last_topic"] ?? ""));
        $lastIntent = trim((string) ($preferences["last_intent"] ?? ""));
        $lastSubject = trim((string) ($preferences["last_subject"] ?? ""));
        $lastSemester = $preferences["last_semester"] ?? null;
        $lastExamType = trim((string) ($preferences["last_exam_type"] ?? ""));

        if ($preferredLanguage !== "") {
            $lines[] = "Preferred reply language: {$preferredLanguage}.";
        }

        if ($lastTopic !== "") {
            $lines[] = "Last topic discussed: {$lastTopic}.";
        }

        if ($lastIntent !== "") {
            $lines[] = "Last resolved intent: {$lastIntent}.";
        }

        if ($lastSubject !== "") {
            $lines[] = "Last subject mentioned: {$lastSubject}.";
        }

        if ($lastSemester !== null && $lastSemester !== "") {
            $lines[] = "Last semester referenced: {$lastSemester}.";
        }

        if ($lastExamType !== "") {
            $lines[] = "Last exam type referenced: {$lastExamType}.";
        }

        if (empty($lines)) {
            return "";
        }

        return "Remembered user preferences and recent context:\n" . implode(" ", $lines);
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

    private static function buildSystemPrompt($userContext, $knowledgeItems, $contextPayload = [], $language = "en") {
        $language = self::normalizeLanguage($language);
        $roleName = $userContext["role_name"] ?? "User";
        $roleKey = $userContext["role_key"] ?? "student";
        $userName = $userContext["full_name"] ?? null;
        $unitName = $userContext["unit_name"] ?? null;
        $branchName = $userContext["branch_name"] ?? null;
        $semester = $userContext["semester"] ?? null;
        $designation = $userContext["designation"] ?? null;

        $identitySummary = [];
        $identitySummary[] = "The current user role is {$roleName} ({$roleKey}).";
        $identitySummary[] = $userName ? "The current user's name is {$userName}." : "The current user's name is not available.";
        if ($roleKey === "student" && $branchName) {
            $identitySummary[] = "Their branch or department is {$branchName}.";
        }
        if ($roleKey === "student" && $semester) {
            $identitySummary[] = "They are currently in semester {$semester}.";
        }
        if ($unitName) {
            $identitySummary[] = "Their unit is {$unitName}.";
        }
        if ($designation) {
            $identitySummary[] = "Their designation is {$designation}.";
        }

        $knowledgeSummary = self::buildKnowledgeContextBlock($knowledgeItems);
        $memorySummary = self::buildMemoryContextBlock($contextPayload);
        $preferenceSummary = self::buildPreferenceContextBlock($contextPayload);

        $capabilitySummary = "You help with profile, fees, attendance, results, CGPA, backlog status, course details, and registration status.";

        $responseLanguageRules = $language === "hi"
            ? "Reply in simple, natural Hindi in Devanagari script for voice. Avoid Hinglish unless a university term such as USN or course code must stay unchanged. Keep answers short, usually 1 to 3 sentences."
            : ($language === "kn"
                ? "Reply in simple, natural Kannada for voice. Kanglish is okay for university words like profile, fees, attendance, result, course, and registration. Keep answers short, usually 1 to 3 sentences."
                : "Reply in warm, natural spoken English. Keep answers short, usually 1 to 3 sentences.");

        $responseRules = $responseLanguageRules . " 
               Give clear, accurate, and helpful answers in a natural conversational tone. 
               Answer directly, then explain briefly if needed. 
              Do not be overly short—clarity is more important than brevity. 
              If unsure, say so honestly instead of guessing. 
              Use simple, human-like language.";
        $examples = $language === "hi"
            ? "Examples: 'मैं GMU VoiceBot हूं, आपका university assistant.' 'हां, आप Aarav Kulkarni हैं, GM University में 5th semester Computer Science student.' Jokes छोटे और साफ रखें."
            : ($language === "kn"
                ? "Examples: 'Naanu GMU VoiceBot, nimma university assistant.' 'Haudu, neevu Aarav Kulkarni, GM University nalli 5th semester Computer Science student.'"
                : "Examples: 'I am GMU VoiceBot, your university assistant.' 'Yes, I know who you are. You are Aarav Kulkarni, a 5th semester Computer Science student at GM University.' Keep jokes short and clean.");

        $prompt = "You are GMU VoiceBot, a role-aware university assistant for GM University. Sound clear, helpful, and human for voice conversations. Continue naturally across follow-up questions. " . $capabilitySummary . " " . $responseRules . " " . $examples . " " . implode(" ", $identitySummary);

        if ($memorySummary !== "") {
            $prompt .= "\n\n" . $memorySummary;
        }

        if ($preferenceSummary !== "") {
            $prompt .= "\n\n" . $preferenceSummary;
        }

        if ($knowledgeSummary !== "") {
            $prompt .= "\n\n" . $knowledgeSummary;
        }

        return $prompt;
    }

    private static function isValidHostedReply($reply) {
        $reply = trim((string) $reply);

        if ($reply === "") {
            return false;
        }

        if (strlen($reply) < 8) {
            return false;
        }

        $lower = strtolower($reply);
        $badReplies = [
            "i understand you",
            "i understand you'",
            "i understand",
            "okay",
            "ok"
        ];

        if (in_array($lower, $badReplies, true)) {
            return false;
        }

        if (substr($reply, -1) === "'" || substr($reply, -1) === "\"" || substr($reply, -1) === ",") {
            return false;
        }

        if (substr_count($reply, "'") % 2 !== 0 || substr_count($reply, "\"") % 2 !== 0) {
            return false;
        }

        return true;
    }

    private static function finalizeHostedReply($reply) {
        $reply = trim((string) $reply);

        if ($reply === "") {
            return null;
        }

        $reply = preg_replace('/\s+/', ' ', $reply);
        $reply = self::polishHostedReply($reply);

        if (!preg_match('/[.!?]$/', $reply)) {
            $reply .= ".";
        }

        return self::isValidHostedReply($reply) ? $reply : null;
    }

    private static function polishHostedReply($reply) {
        $reply = trim((string) $reply);

        if ($reply === "") {
            return "";
        }

        $replacements = [
            '/\bYes,\s+([A-Z][a-z]+),\s+I do\.\s*/' => 'Yes, ',
            '/\bYou are\s+([A-Z][a-z]+(?:\s+[A-Z][a-z]+)*)\s*,\s*a student from the ([A-Za-z ]+) unit\./' => 'You are $1 from $2.',
            '/\bIs there something\.$/' => 'How can I help you today?',
            '/\bHow may I assist you\?/i' => 'How can I help you?',
            '/\bkindly note\b/i' => 'please note',
            '/\byou may proceed\b/i' => 'you can go ahead',
        ];

        foreach ($replacements as $pattern => $replacement) {
            $reply = preg_replace($pattern, $replacement, $reply);
        }

        $reply = preg_replace('/\s+/', ' ', $reply);
        return trim($reply);
    }

    private static function getProviderOrder() {
        $preferred = strtolower(trim((string) (self::getEnvValue("LLM_PROVIDER") ?: "")));

        if ($preferred === "gemini") {
            return ["gemini"];
        }

        if ($preferred === "openai") {
            return ["openai"];
        }

        return ["gemini"];
    }

    private static function matchesAnyPattern($message, $patterns) {
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $message)) {
                return true;
            }
        }

        return false;
    }

    private static function logModelFailure($provider, $stage, $message, $language = "en", $userContext = [], $details = []) {
        $payload = [
            "event" => "llm_failure",
            "provider" => (string) $provider,
            "stage" => (string) $stage,
            "language" => self::normalizeLanguage($language),
            "role" => $userContext["role_key"] ?? "student",
            "user_id" => $userContext["student_id"] ?? ($userContext["user_id"] ?? null),
            "message_preview" => mb_substr(trim((string) $message), 0, 180, "UTF-8"),
            "details" => $details
        ];

        error_log("GMU_VOICEBOT_LLM_FAILURE " . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private static function detectFallbackTopic($message) {
        $message = mb_strtolower(trim((string) $message), "UTF-8");

        $topicPatterns = [
            "attendance" => [
                '/\battendance\b|\battendence\b|\batendance\b|\bhajari\b|अटेंडेंस|उपस्थिति|ಹಾಜರಿ|ಅಟೆಂಡೆನ್ಸ್/iu'
            ],
            "result" => [
                '/\bresult\b|\bsgpa\b|\bcgpa\b|\bgpa\b|परिणाम|रिजल्ट|ಎಸ್‌ಜಿಪಿಎ|ಸಿಜಿಪಿಎ|ಫಲಿತಾಂಶ|ರಿಸಲ್ಟ್/iu'
            ],
            "fees" => [
                '/\bfee\b|\bfees\b|\bbalance\b|\bdue\b|\bpending amount\b|फीस|शुल्क|बाकाया|ಫೀಸ್|ಶುಲ್ಕ|ಬಾಕಿ/iu'
            ],
            "registration" => [
                '/\bregistration\b|\bfinal registration\b|\bregistered\b|रजिस्ट्रेशन|पंजीकरण|ನೋಂದಣಿ|ರಿಜಿಸ್ಟ್ರೇಶನ್/iu'
            ],
            "hall_ticket" => [
                '/\bhall\s*ticket\b|\bhallticket\b|\badmit\s*card\b|हॉल\s*टिकट|प्रवेश\s*पत्र|ಹಾಲ್\s*ಟಿಕೆಟ್/iu'
            ],
            "profile" => [
                '/\bprofile\b|\bwho am i\b|\bsemester\b|\bdepartment\b|\bbranch\b|\busn\b|प्रोफाइल|सेमेस्टर|विभाग|यूएसएन|ಪ್ರೊಫೈಲ್|ಸೆಮಿಸ್ಟರ್|ವಿಭಾಗ|ಯುಎಸ್ಎನ್/iu'
            ],
            "course" => [
                '/\bcourse\b|\bsubject\b|\bcourse code\b|\bsubject code\b|\bcode\b|कोर्स|विषय|कोड|ಕೋರ್ಸ್|ವಿಷಯ|ಕೋಡ್/iu'
            ],
            "certificate" => [
                '/\bcertificate\b|\bcertificates\b|सर्टिफिकेट|प्रमाणपत्र|ಸರ್ಟಿಫಿಕೆಟ್|ಪ್ರಮಾಣಪತ್ರ/iu'
            ]
        ];

        foreach ($topicPatterns as $topic => $patterns) {
            if (self::matchesAnyPattern($message, $patterns)) {
                return $topic;
            }
        }

        return null;
    }

    private static function buildTopicGuidanceReply($topic, $language, $role) {
        $language = self::normalizeLanguage($language);

        $replies = [
            "en" => [
                "attendance" => "It sounds like you are asking about attendance. You can ask: what is my overall attendance, or what is my attendance in DBMS?",
                "result" => "It looks like this is about results. You can ask: what is my SGPA, what is my CGPA, or do I have any backlogs?",
                "fees" => "This seems related to fees. You can ask: what is my fee balance, how much amount is due, or is any payment pending?",
                "registration" => "This sounds like a registration query. You can ask: is my final registration complete, or what is my registration status?",
                "hall_ticket" => "This looks like a hall ticket query. You can ask: is my hall ticket generated, or can I download my hall ticket?",
                "profile" => "This seems related to your student profile. You can ask: what is my USN, which semester am I in, or what is my department?",
                "course" => "It sounds like you are asking about courses. You can ask: what are my subjects, or what is the course code for DBMS?",
                "certificate" => "This seems related to certificates. You can ask: what certificates are available, or can I download my certificate?"
            ],
            "hi" => [
                "attendance" => "Lagta hai aap attendance ke baare mein pooch rahe hain. Aap pooch sakte hain: meri overall attendance kya hai, ya DBMS mein meri attendance kitni hai?",
                "result" => "Yeh result se juda hua sawaal lag raha hai. Aap pooch sakte hain: mera SGPA kya hai, mera CGPA kya hai, ya kya mere backlogs hain?",
                "fees" => "Yeh fees se related query lag rahi hai. Aap pooch sakte hain: mera fee balance kya hai, kitni amount due hai, ya koi payment pending hai kya?",
                "registration" => "Yeh registration se related lag raha hai. Aap pooch sakte hain: mera final registration complete hai kya, ya mera registration status kya hai?",
                "hall_ticket" => "Yeh hall ticket query lag rahi hai. Aap pooch sakte hain: mera hall ticket generate hua hai kya, ya kya main hall ticket download kar sakta hoon?",
                "profile" => "Yeh aapke student profile se related lag raha hai. Aap pooch sakte hain: mera USN kya hai, main kis semester mein hoon, ya mera department kya hai?",
                "course" => "Lagta hai aap courses ya subjects ke baare mein pooch rahe hain. Aap pooch sakte hain: mere subjects kya hain, ya DBMS ka course code kya hai?",
                "certificate" => "Yeh certificate se related lag raha hai. Aap pooch sakte hain: kaun se certificates available hain, ya kya main apna certificate download kar sakta hoon?"
            ],
            "kn" => [
                "attendance" => "Idu attendance bagge iruva prashne anta toruttide. Neevu kelabahudu: nanna overall attendance eshtu, athava DBMS nalli nanna attendance eshtu?",
                "result" => "Idu result bagge iruva prashne anta toruttide. Neevu kelabahudu: nanna SGPA eshtu, nanna CGPA eshtu, athava nanage backlog ideya?",
                "fees" => "Idu fees sambandhita prashne anta toruttide. Neevu kelabahudu: nanna fee balance eshtu, eshtu amount due ide, athava yavude payment pending ideya?",
                "registration" => "Idu registration bagge iruva prashne anta toruttide. Neevu kelabahudu: nanna final registration complete aagideya, athava nanna registration status yenu?",
                "hall_ticket" => "Idu hall ticket query anta toruttide. Neevu kelabahudu: nanna hall ticket generate aagideya, athava nanu hall ticket download madabahuda?",
                "profile" => "Idu nimma student profile ge sambandhisida prashne anta toruttide. Neevu kelabahudu: nanna USN yenu, nanu yaava semester nalli iddini, athava nanna department yenu?",
                "course" => "Idu course athava subject bagge iruva prashne anta toruttide. Neevu kelabahudu: nanna subjects yavuvu, athava DBMS course code yenu?",
                "certificate" => "Idu certificate bagge iruva prashne anta toruttide. Neevu kelabahudu: yaava certificates available ive, athava nanu nanna certificate download madabahuda?"
            ]
        ];

        if (isset($replies[$language][$topic])) {
            return $replies[$language][$topic];
        }

        return self::getRoleHelpMessage($role, $language);
    }

    private static function detectEntityHint($message) {
        $message = mb_strtolower(trim((string) $message), "UTF-8");

        $entityPatterns = [
            "dbms" => [
                '/\bdbms\b|\bdatabase\b|\bdatabase management\b|ಡಿಬಿಎಂಎಸ್|डेटाबेस/u'
            ],
            "software" => [
                '/\bsoftware\b|\bsoftware engineering\b|\bse subject\b|\bse attendance\b/u'
            ],
            "os" => [
                '/\bos\b|\boperating system\b|\boperating systems\b|ಆಪರೇಟಿಂಗ್|ऑपरेटिंग/u'
            ],
            "cn" => [
                '/\bcn\b|\bcomputer network\b|\bcomputer networks\b|ನೆಟ್ವರ್ಕ್|नेटवर्क/u'
            ],
            "network" => [
                '/\bnetwork\b|\bnetworks\b|\bnetworking\b/u'
            ],
            "ai" => [
                '/\bai\b|\bartificial intelligence\b|ಕೃತಕ ಬುದ್ಧಿಮತ್ತೆ|आर्टिफिशियल इंटेलिजेंस/u'
            ],
            "fees" => [
                '/\bfee\b|\bfees\b|\bbalance\b|\bdue\b|ಫೀಸ್|फीस/u'
            ],
            "result" => [
                '/\bresult\b|\bsgpa\b|\bcgpa\b|\bgrade\b|\bmarks\b|ರಿಸಲ್ಟ್|रिजल्ट/u'
            ],
            "attendance" => [
                '/\battendance\b|\battendence\b|\batendance\b|ಹಾಜರಿ|अटेंडेंस/u'
            ],
            "profile" => [
                '/\bprofile\b|\busn\b|\bsemester\b|\bdepartment\b|ಪ್ರೊಫೈಲ್|प्रोफाइल/u'
            ],
            "certificate" => [
                '/\bcertificate\b|\bcertificates\b|ಸರ್ಟಿಫಿಕೇಟ್|सर्टिफिकेट/u'
            ],
            "receipt" => [
                '/\breceipt\b|\breceipts\b|\bpayment receipt\b|\bfee receipt\b/u'
            ],
            "grievance" => [
                '/\bgrievance\b|\bcomplaint\b|\braise issue\b/u'
            ],
            "hall_ticket" => [
                '/\bhall\s*ticket\b|\bhallticket\b|\badmit\s*card\b/u'
            ],
            "payment" => [
                '/\bpayment\b|\breceipt\b|\bgrievance\b|ಪಾವತಿ|पेमेंट/u'
            ]
        ];

        foreach ($entityPatterns as $entity => $patterns) {
            if (self::matchesAnyPattern($message, $patterns)) {
                return $entity;
            }
        }

        return null;
    }

    private static function buildEntityGuidanceReply($entity, $language) {
        $language = self::normalizeLanguage($language);

        $replies = [
            "en" => [
                "dbms" => "If you mean DBMS, I can help with DBMS attendance, the DBMS course code, or your subject-wise attendance. You can ask: what is my attendance in DBMS, or what is the course code for DBMS?",
                "software" => "If you mean Software Engineering, I can help with attendance, course code, or subject details. You can ask: what is my attendance in Software Engineering, or what is the course code for Software Engineering?",
                "os" => "If you mean Operating Systems, I can help with attendance, course code, or subject details. You can ask: what is my attendance in OS, or what is the course code for OS?",
                "cn" => "If you mean Computer Networks, I can help with attendance, course code, or subject details. You can ask: what is my attendance in CN, or what is the course code for CN?",
                "network" => "If you mean Networks, I can help with network subject attendance, course code, or subject details. You can ask: what is my attendance in Computer Networks, or what is the course code for CN?",
                "ai" => "If you mean Artificial Intelligence, I can help with attendance, course code, or subject details. You can ask: what is my attendance in AI, or what is the course code for AI?",
                "fees" => "This seems related to fees or payment. You can ask: what is my fee balance, show my fee breakup, or open the payment portal.",
                "result" => "This seems related to results. You can ask: show my 4th semester result, what is my SGPA, or do I have any backlogs?",
                "attendance" => "This seems related to attendance. You can ask: what is my overall attendance, show my subject-wise attendance graph, or what is my attendance in DBMS?",
                "profile" => "This seems related to your profile. You can ask: what is my USN, which semester am I in, or tell me about my profile.",
                "certificate" => "This seems related to certificates. You can ask: what certificates are available, or open my certificate page.",
                "payment" => "This seems related to payment help. You can ask: open payment portal, download receipt, or apply payment grievance.",
                "receipt" => "This seems related to receipts. You can ask: download my payment receipt, show my latest receipt, or open the receipt page.",
                "grievance" => "This seems related to grievances. You can ask: apply payment grievance, check my grievance status, or open the grievance page.",
                "hall_ticket" => "This seems related to hall ticket help. You can ask: is my hall ticket generated, can I download my hall ticket, or open the hall ticket page."
            ],
            "hi" => [
                "dbms" => "अगर आपका मतलब DBMS है, तो मैं DBMS attendance, course code, या subject-wise attendance में मदद कर सकता हूँ। आप पूछ सकते हैं: DBMS में मेरी attendance क्या है, या DBMS का course code क्या है?",
                "os" => "अगर आपका मतलब Operating Systems है, तो मैं attendance, course code, या subject details में मदद कर सकता हूँ। आप पूछ सकते हैं: OS में मेरी attendance क्या है, या OS का course code क्या है?",
                "cn" => "अगर आपका मतलब Computer Networks है, तो मैं attendance, course code, या subject details में मदद कर सकता हूँ। आप पूछ सकते हैं: CN में मेरी attendance क्या है, या CN का course code क्या है?",
                "ai" => "अगर आपका मतलब Artificial Intelligence है, तो मैं attendance, course code, या subject details में मदद कर सकता हूँ। आप पूछ सकते हैं: AI में मेरी attendance क्या है, या AI का course code क्या है?",
                "fees" => "यह fees या payment से जुड़ा लग रहा है। आप पूछ सकते हैं: मेरी fee balance क्या है, fee breakup दिखाइए, या payment portal खोलिए।",
                "result" => "यह result से जुड़ा लग रहा है। आप पूछ सकते हैं: मेरा 4th semester result दिखाइए, मेरा SGPA क्या है, या क्या मेरे backlogs हैं?",
                "attendance" => "यह attendance से जुड़ा लग रहा है। आप पूछ सकते हैं: मेरी overall attendance क्या है, subject-wise attendance graph दिखाइए, या DBMS में मेरी attendance क्या है?",
                "profile" => "यह आपकी profile से जुड़ा लग रहा है। आप पूछ सकते हैं: मेरा USN क्या है, मैं किस semester में हूँ, या मेरी profile बताइए।",
                "certificate" => "यह certificate से जुड़ा लग रहा है। आप पूछ सकते हैं: कौन से certificates available हैं, या certificate page खोलिए।",
                "payment" => "यह payment help से जुड़ा लग रहा है। आप पूछ सकते हैं: payment portal खोलिए, receipt download कीजिए, या payment grievance apply कीजिए।"
            ],
            "kn" => [
                "dbms" => "Nimma artha DBMS andre, nanu DBMS attendance, course code, athava subject-wise attendance bagge sahaya madabahudu. Neevu kelabahudu: DBMS nalli nanna attendance eshtu, athava DBMS course code yenu?",
                "os" => "Nimma artha Operating Systems andre, nanu attendance, course code, athava subject details bagge sahaya madabahudu. Neevu kelabahudu: OS nalli nanna attendance eshtu, athava OS course code yenu?",
                "cn" => "Nimma artha Computer Networks andre, nanu attendance, course code, athava subject details bagge sahaya madabahudu. Neevu kelabahudu: CN nalli nanna attendance eshtu, athava CN course code yenu?",
                "ai" => "Nimma artha Artificial Intelligence andre, nanu attendance, course code, athava subject details bagge sahaya madabahudu. Neevu kelabahudu: AI nalli nanna attendance eshtu, athava AI course code yenu?",
                "fees" => "Idu fees athava payment ge sambandhisida prashne anisutte. Neevu kelabahudu: nanna fee balance eshtu, fee breakup torisu, athava payment portal tereyiri.",
                "result" => "Idu result ge sambandhisida prashne anisutte. Neevu kelabahudu: nanna 4th semester result torisu, nanna SGPA eshtu, athava nanage backlog ideya?",
                "attendance" => "Idu attendance ge sambandhisida prashne anisutte. Neevu kelabahudu: nanna overall attendance eshtu, subject-wise attendance graph torisu, athava DBMS nalli nanna attendance eshtu?",
                "profile" => "Idu nimma profile ge sambandhisida prashne anisutte. Neevu kelabahudu: nanna USN yenu, nanu yaava semester nalli iddini, athava nanna profile heli.",
                "certificate" => "Idu certificate ge sambandhisida prashne anisutte. Neevu kelabahudu: yaava certificates available ive, athava certificate page tereyiri.",
                "payment" => "Idu payment help ge sambandhisida prashne anisutte. Neevu kelabahudu: payment portal tereyiri, receipt download madi, athava payment grievance apply madi."
            ]
        ];

        if (isset($replies[$language][$entity])) {
            return $replies[$language][$entity];
        }

        $extendedReplies = [
            "en" => [
                "software" => "If you mean Software Engineering, I can help with attendance, course code, or subject details. You can ask: what is my attendance in Software Engineering, or what is the course code for Software Engineering?",
                "network" => "If you mean Networks, I can help with network subject attendance, course code, or subject details. You can ask: what is my attendance in Computer Networks, or what is the course code for CN?",
                "receipt" => "This seems related to receipts. You can ask: download my payment receipt, show my latest receipt, or open the receipt page.",
                "grievance" => "This seems related to grievances. You can ask: apply payment grievance, check my grievance status, or open the grievance page.",
                "hall_ticket" => "This seems related to hall ticket help. You can ask: is my hall ticket generated, can I download my hall ticket, or open the hall ticket page."
            ],
            "hi" => [
                "software" => "Agar aapka matlab Software Engineering hai, to main attendance, course code, ya subject details mein madad kar sakta hoon. Aap pooch sakte hain: Software Engineering mein meri attendance kya hai, ya iska course code kya hai?",
                "network" => "Agar aap network subject ke baare mein pooch rahe hain, to main Computer Networks attendance, course code, ya subject details mein madad kar sakta hoon. Aap pooch sakte hain: Computer Networks mein meri attendance kya hai, ya CN ka course code kya hai?",
                "receipt" => "Yeh receipt se juda lag raha hai. Aap pooch sakte hain: meri payment receipt download kijiye, latest receipt dikhaiye, ya receipt page kholiye.",
                "grievance" => "Yeh grievance se juda lag raha hai. Aap pooch sakte hain: payment grievance apply kijiye, grievance status batayiye, ya grievance page kholiye.",
                "hall_ticket" => "Yeh hall ticket help se juda lag raha hai. Aap pooch sakte hain: mera hall ticket generate hua hai kya, hall ticket download kijiye, ya hall ticket page kholiye."
            ],
            "kn" => [
                "software" => "Nimma artha Software Engineering andre, nanu attendance, course code, athava subject details bagge sahaya madabahudu. Neevu kelabahudu: Software Engineering nalli nanna attendance eshtu, athava adara course code yenu?",
                "network" => "Nimma artha network subject andre, nanu Computer Networks attendance, course code, athava subject details bagge sahaya madabahudu. Neevu kelabahudu: Computer Networks nalli nanna attendance eshtu, athava CN course code yenu?",
                "receipt" => "Idu receipt ge sambandhisida prashne anisutte. Neevu kelabahudu: nanna payment receipt download madi, latest receipt torisu, athava receipt page tereyiri.",
                "grievance" => "Idu grievance ge sambandhisida prashne anisutte. Neevu kelabahudu: payment grievance apply madi, grievance status heli, athava grievance page tereyiri.",
                "hall_ticket" => "Idu hall ticket help ge sambandhisida prashne anisutte. Neevu kelabahudu: nanna hall ticket generate aagideya, hall ticket download madi, athava hall ticket page tereyiri."
            ]
        ];

        return $extendedReplies[$language][$entity] ?? null;
    }

    private static function localFallback($message, $userContext = null, $language = "en") {
        $message = strtolower(trim($message));
        $role = $userContext["role_key"] ?? "student";
        $roleName = $userContext["role_name"] ?? "user";

        if (self::normalizeLanguage($language) === "hi") {
            $patterns = [
                "/\bhow are you\b|कैसे हो|आप कैसे हैं/" => "मैं ठीक हूं। मैं आपकी क्या मदद कर सकता हूं?",
                "/\bwho are you\b|आप कौन हैं|तुम कौन हो/" => "मैं GMU VoiceBot हूं। मैं आपकी यूनिवर्सिटी जानकारी और role-based assistance में मदद कर सकता हूं।",
                "/\b(family|father|mother|parents|brother|sister|wife|husband)\b|परिवार|पिता|माता|भाई|बहन/" => "मेरे पास आपके परिवार की निजी जानकारी नहीं है। मुझे सिर्फ आपके यूनिवर्सिटी अकाउंट में उपलब्ध प्रोफाइल जानकारी पता है।",
                "/\b(hello|hi|hey)\b|नमस्ते|हेलो/" => "नमस्ते। मैं आपकी क्या मदद कर सकता हूं?",
                "/\b(bye|goodbye|see you)\b|बाय|अलविदा/" => "ठीक है। जब भी यूनिवर्सिटी से जुड़ी मदद चाहिए, मैं यहां हूं।",
                "/\b(thank you|thanks)\b|धन्यवाद|शुक्रिया/" => "आपका स्वागत है।",
                "/\b(help|assist|support)\b|मदद|सहायता/" => self::getRoleHelpMessage($role, "hi")
            ];

            foreach ($patterns as $pattern => $reply) {
                if (preg_match($pattern, $message)) {
                    return $reply;
                }
            }

            $topic = self::detectFallbackTopic($message);
            if ($topic !== null) {
                return self::buildTopicGuidanceReply($topic, "hi", $role);
            }

            $entityHint = self::detectEntityHint($message);
            if ($entityHint !== null) {
                $entityReply = self::buildEntityGuidanceReply($entityHint, "hi");
                if ($entityReply) {
                    return $entityReply;
                }
            }

            return self::getRoleHelpMessage($role, "hi");
        }

        if (self::normalizeLanguage($language) === "kn") {
            $patterns = [
                "/\bhow are you\b|hegiddira|hegiddiya|ಹೇಗಿದ್ದೀರಾ|ಹೇಗಿದ್ದೀಯಾ/u" => "Naanu chennagiddini. Nimage enu sahaya beku?",
                "/\bwho are you\b|neevu yaaru|ನೀವು ಯಾರು/u" => "Naanu GMU VoiceBot. Naanu nimma university mahiti mattu role-based sahayadalli sahaya madabahudu.",
                "/\b(family|father|mother|parents|brother|sister|wife|husband)\b|kutumba|ತಂದೆ|ತಾಯಿ|ಕುಟುಂಬ/u" => "Nimma kutumbada vaiyaktika mahiti nanage illa. Nimma university account nalli iruva profile mahiti matra nanage gothu.",
                "/\b(hello|hi|hey)\b|namaskara|ನಮಸ್ಕಾರ/u" => "Namaskara. Nimage enu sahaya beku?",
                "/\b(bye|goodbye|see you)\b|matte sigona|ಬೈ/u" => "Sari. University sambandhita sahaya beku andre naanu illiddini.",
                "/\b(thank you|thanks)\b|dhanyavada|ಧನ್ಯವಾದ/u" => "Swagata.",
                "/\b(help|assist|support)\b|sahaya|ಸಹಾಯ/u" => self::getRoleHelpMessage($role, "kn")
            ];

            foreach ($patterns as $pattern => $reply) {
                if (preg_match($pattern, $message)) {
                    return $reply;
                }
            }

            $topic = self::detectFallbackTopic($message);
            if ($topic !== null) {
                return self::buildTopicGuidanceReply($topic, "kn", $role);
            }

            $entityHint = self::detectEntityHint($message);
            if ($entityHint !== null) {
                $entityReply = self::buildEntityGuidanceReply($entityHint, "kn");
                if ($entityReply) {
                    return $entityReply;
                }
            }

            return self::getRoleHelpMessage($role, "kn");
        }

        $patterns = [
            "/\bhow are you\b/" => "I am doing well. How can I help you today?",
            "/\bwho are you\b/" => "I am GMU VoiceBot. I can help with university information and role-based assistance for {$roleName} users.",
            "/\b(family|father|mother|parents|brother|sister|wife|husband)\b/" => "I do not have personal information about your family. I only know the profile details available in your university account.",
            "/\b(joke|funny|laugh|make me laugh)\b/" => "Here is one. Why did the student bring a ladder to class? Because the grades were too high.",
            "/\b(marriage|address|wedding)\b/" => "I cannot know your personal future, but I can help you with your academic details.",
            "/\b(love|girlfriend|boyfriend)\b/" => "I am better with university questions than love advice, but I am here to help however I can.",
            "/\b(hello|hi|hey)\b/" => "Hello. How can I help you today?",
            "/\b(good morning|good afternoon|good evening)\b/" => "Hello. How can I help you today?",
            "/\b(bye|goodbye|see you)\b/" => "Goodbye. If you need anything else about your {$role} access or university tasks, I am here to help.",
            "/\b(help|assist|support)\b/" => self::getRoleHelpMessage($role),

            "/\b(thank you|thanks)\b/" => "You are welcome. Happy to help."
        ];  

        foreach ($patterns as $pattern => $reply) {
            if (preg_match($pattern, $message)) {
                return $reply;
            }
        }

        $topic = self::detectFallbackTopic($message);
        if ($topic !== null) {
            return self::buildTopicGuidanceReply($topic, "en", $role);
        }

        $entityHint = self::detectEntityHint($message);
        if ($entityHint !== null) {
            $entityReply = self::buildEntityGuidanceReply($entityHint, "en");
            if ($entityReply) {
                return $entityReply;
            }
        }

        return self::getRoleHelpMessage($role);
    }

    public static function getLastReplyMeta() {
        return self::$lastReplyMeta;
    }

    public static function setLastReplyMeta($source) {
        self::$lastReplyMeta = [
            "source" => trim((string) $source) !== "" ? trim((string) $source) : "unknown"
        ];
    }

    private static function getRoleHelpMessage($role, $language = "en") {
        if (self::normalizeLanguage($language) === "hi") {
            $helpMap = [
                "student" => "मैं आपकी student profile, fees, attendance, results और course details में मदद कर सकता हूं।",
                "teacher" => "मैं teacher-related university information, department context और general guidance में मदद कर सकता हूं।",
                "hod" => "मैं department-level academic information और university assistant support में मदद कर सकता हूं।",
                "director" => "मैं director-level university information और institutional guidance में मदद कर सकता हूं।",
                "dean" => "मैं academic oversight information और university assistant support में मदद कर सकता हूं।",
                "registrar" => "मैं records guidance और university assistant support में मदद कर सकता हूं।",
                "management" => "मैं management-level university information और strategic guidance में मदद कर सकता हूं।"
            ];

            return ($helpMap[$role] ?? "मैं university information और role-based assistance में मदद कर सकता हूं।") . " आप क्या जानना चाहेंगे?";
        }

        if (self::normalizeLanguage($language) === "kn") {
            $helpMap = [
                "student" => "ನಾನು ನಿಮ್ಮ ವಿದ್ಯಾರ್ಥಿ ಪ್ರೊಫೈಲ್, ಫೀಸ್, ಅಟೆಂಡೆನ್ಸ್, ರಿಸಲ್ಟ್ ಮತ್ತು ಕೋರ್ಸ್ ವಿವರಗಳ ಬಗ್ಗೆ ಸಹಾಯ ಮಾಡಬಹುದು.",
                "teacher" => "ನಾನು ಶಿಕ್ಷಕರಿಗೆ ಸಂಬಂಧಿಸಿದ ವಿಶ್ವವಿದ್ಯಾಲಯ ಮಾಹಿತಿ, ವಿಭಾಗದ ಮಾಹಿತಿ ಮತ್ತು ಸಾಮಾನ್ಯ ಮಾರ್ಗದರ್ಶನದಲ್ಲಿ ಸಹಾಯ ಮಾಡಬಹುದು.",
                "hod" => "ನಾನು ವಿಭಾಗ ಮಟ್ಟದ ಶೈಕ್ಷಣಿಕ ಮಾಹಿತಿ ಮತ್ತು ವಿಶ್ವವಿದ್ಯಾಲಯ ಸಹಾಯದಲ್ಲಿ ನೆರವಾಗಬಹುದು.",
                "director" => "ನಾನು ನಿರ್ದೇಶಕರ ಮಟ್ಟದ ವಿಶ್ವವಿದ್ಯಾಲಯ ಮಾಹಿತಿ ಮತ್ತು ಸಂಸ್ಥೆಯ ಮಾರ್ಗದರ್ಶನದಲ್ಲಿ ಸಹಾಯ ಮಾಡಬಹುದು.",
                "dean" => "ನಾನು ಶೈಕ್ಷಣಿಕ ಮೇಲ್ವಿಚಾರಣೆ ಮಾಹಿತಿ ಮತ್ತು ವಿಶ್ವವಿದ್ಯಾಲಯ ಸಹಾಯದಲ್ಲಿ ನೆರವಾಗಬಹುದು.",
                "registrar" => "ನಾನು ದಾಖಲೆಗಳಿಗೆ ಸಂಬಂಧಿಸಿದ ಮಾರ್ಗದರ್ಶನ ಮತ್ತು ವಿಶ್ವವಿದ್ಯಾಲಯ ಸಹಾಯದಲ್ಲಿ ನೆರವಾಗಬಹುದು.",
                "management" => "ನಾನು ಮ್ಯಾನೇಜ್ಮೆಂಟ್ ಮಟ್ಟದ ವಿಶ್ವವಿದ್ಯಾಲಯ ಮಾಹಿತಿ ಮತ್ತು ಕಾರ್ಯತಂತ್ರದ ಮಾರ್ಗದರ್ಶನದಲ್ಲಿ ಸಹಾಯ ಮಾಡಬಹುದು."
            ];

            return ($helpMap[$role] ?? "ನಾನು ವಿಶ್ವವಿದ್ಯಾಲಯದ ಮಾಹಿತಿ ಮತ್ತು ಪಾತ್ರ ಆಧಾರಿತ ಸಹಾಯದಲ್ಲಿ ನೆರವಾಗಬಹುದು.") . " ನಿಮಗೆ ಏನು ತಿಳಿದುಕೊಳ್ಳಬೇಕು?";
        }

        $helpMap = [
            "student" => "I can help you with your student profile, fees, attendance, results, and course details.",
            "teacher" => "I can help you with teacher-related university information, department context, and general assistant guidance.",
            "hod" => "I can help you with department-level academic information, role guidance, and university assistant support.",
            "director" => "I can help you with director-level university information, escalations, and institutional guidance.",
            "dean" => "I can help you with dean-level academic oversight information and university assistant support.",
            "registrar" => "I can help you with registrar-related records guidance and university assistant support.",
            "management" => "I can help you with management-level university information and strategic guidance."
        ];

        return ($helpMap[$role] ?? "I can help you with university information and role-based assistance.") . " What would you like to know?";
    }

    public static function translateReply($reply, $targetLanguage = "en", $userContext = []) {
        $targetLanguage = self::normalizeLanguage($targetLanguage);
        $reply = trim((string) $reply);

        if ($reply === "") {
            return "";
        }

        if ($targetLanguage === "en") {
            $translationRequest = "Convert this university voicebot answer into simple natural English for voice. Keep names, numbers, USN, course codes, fee amounts, and university terms exactly unchanged. Reply only with the converted answer: " . $reply;
        } elseif ($targetLanguage === "kn") {
            $translationRequest = "Convert this university voicebot answer into simple Kannada or Kanglish for voice. Keep names, numbers, USN, course codes, fee amounts, and university terms exactly unchanged. Reply only with the converted answer: " . $reply;
        } else {
            $translationRequest = "Convert this university voicebot answer into simple Hindi in Devanagari script for voice. Keep names, numbers, USN, course codes, fee amounts, and essential university terms exactly unchanged. Reply only with the converted answer: " . $reply;
        }

        foreach (self::getProviderOrder() as $provider) {
            $translatedReply = null;

            if ($provider === "gemini") {
                $translatedReply = self::callGemini($translationRequest, $userContext, [], $targetLanguage);
            }

            if ($provider === "openai") {
                $translatedReply = self::callOpenAI($translationRequest, $userContext, [], $targetLanguage);
            }

            if ($translatedReply) {
                self::$lastReplyMeta = [
                    "source" => $provider . "_translated_reply"
                ];
                return $translatedReply;
            }
        }

        self::$lastReplyMeta = [
            "source" => "local_translated_reply"
        ];

        if ($targetLanguage === "hi") {
            return self::localTranslateToHindi($reply);
        }

        if ($targetLanguage === "kn") {
            return self::localTranslateToKannada($reply);
        }

        return $reply;
    }

    private static function callOpenAI($message, $userContext, $knowledgeItems, $language = "en") {
        $apiKey = self::getEnvValue("OPENAI_API_KEY");

        if (!$apiKey) {
            return null;
        }

        $model = self::getEnvValue("OPENAI_MODEL") ?: "gpt-4.1-mini";
        $contextPayload = ConversationContextService::getContextPayload();
        $systemPrompt = self::buildSystemPrompt($userContext, $knowledgeItems, $contextPayload, $language);

        $input = [[
            "role" => "system",
            "content" => [[
                "type" => "input_text",
                "text" => $systemPrompt
            ]]
        ]];

        foreach ($contextPayload["recent_messages"] ?? [] as $historyItem) {
            $historyRole = $historyItem["role"] ?? "user";
            $historyText = trim((string) ($historyItem["text"] ?? ""));
            if ($historyText === "") {
                continue;
            }

            $input[] = [
                "role" => $historyRole,
                "content" => [[
                    "type" => "input_text",
                    "text" => $historyText
                ]]
            ];
        }

        $input[] = [
            "role" => "user",
            "content" => [[
                "type" => "input_text",
                "text" => $message
            ]]
        ];

        $payload = json_encode([
            "model" => $model,
            "input" => $input,
            "max_output_tokens" => 70
        ]);

        $ch = curl_init("https://api.openai.com/v1/responses");
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer " . $apiKey,
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

        if (isset($data["output_text"])) {
            $reply = self::finalizeHostedReply($data["output_text"]);
            if ($reply) {
                return $reply;
            }
        }

        if (!isset($data["output"]) || !is_array($data["output"])) {
            return null;
        }

        foreach ($data["output"] as $item) {
            if (($item["type"] ?? "") !== "message" || !isset($item["content"])) {
                continue;
            }

            foreach ($item["content"] as $content) {
                $text = $content["text"] ?? "";

                $reply = self::finalizeHostedReply($text);
                if ($reply) {
                    return $reply;
                }
            }
        }

        return null;
    }

    private static function callGemini($message, $userContext, $knowledgeItems, $language = "en") {
        $apiKey = self::getEnvValue("GEMINI_API_KEY") ?: self::getEnvValue("GOOGLE_API_KEY");

        if (!$apiKey) {
            self::logModelFailure("gemini", "missing_api_key", $message, $language, $userContext);
            return null;
        }

        $model = self::getEnvValue("GEMINI_MODEL") ?: "gemini-2.5-flash";
        $contextPayload = ConversationContextService::getContextPayload();
        $systemPrompt = self::buildSystemPrompt($userContext, $knowledgeItems, $contextPayload, $language);

        $contents = [];
        foreach ($contextPayload["recent_messages"] ?? [] as $historyItem) {
            $historyRole = ($historyItem["role"] ?? "user") === "assistant" ? "model" : "user";
            $historyText = trim((string) ($historyItem["text"] ?? ""));
            if ($historyText === "") {
                continue;
            }

            $contents[] = [
                "role" => $historyRole,
                "parts" => [[
                    "text" => $historyText
                ]]
            ];
        }

        $contents[] = [
            "role" => "user",
            "parts" => [[
                "text" => $message
            ]]
        ];

        $payload = json_encode([
            "system_instruction" => [
                "parts" => [[
                    "text" => $systemPrompt
                ]]
            ],
            "contents" => $contents,
            "generationConfig" => [
                "temperature" => 0.55,
                "topP" => 0.9,
                "maxOutputTokens" => 200
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
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            self::logModelFailure("gemini", "curl_exec_failed", $message, $language, $userContext, [
                "model" => $model,
                "curl_error" => $curlError
            ]);
            return null;
        }

        if ($statusCode >= 400) {
            self::logModelFailure("gemini", "http_error", $message, $language, $userContext, [
                "model" => $model,
                "status_code" => $statusCode,
                "response_preview" => mb_substr(trim((string) $response), 0, 300, "UTF-8")
            ]);
            return null;
        }

        $data = json_decode($response, true);
        $parts = $data["candidates"][0]["content"]["parts"] ?? null;

        if (!is_array($parts)) {
            self::logModelFailure("gemini", "missing_parts", $message, $language, $userContext, [
                "model" => $model,
                "status_code" => $statusCode,
                "response_preview" => mb_substr(trim((string) $response), 0, 300, "UTF-8")
            ]);
            return null;
        }

        $texts = [];
        foreach ($parts as $part) {
            $text = trim((string) ($part["text"] ?? ""));
            if ($text !== "") {
                $texts[] = $text;
            }
        }

        if (empty($texts)) {
            self::logModelFailure("gemini", "empty_texts", $message, $language, $userContext, [
                "model" => $model,
                "status_code" => $statusCode
            ]);
            return null;
        }

        $finalReply = self::finalizeHostedReply(implode(" ", $texts));

        if ($finalReply === null) {
            self::logModelFailure("gemini", "invalid_final_reply", $message, $language, $userContext, [
                "model" => $model,
                "raw_reply_preview" => mb_substr(trim(implode(" ", $texts)), 0, 300, "UTF-8")
            ]);
        }

        return $finalReply;
    }

    private static function callPythonFallback($message) {
        $data = json_encode(["message" => $message]);

        $ch = curl_init("http://127.0.0.1:5000/chat");
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json"]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);

        $response = curl_exec($ch);
        curl_close($ch);

        if (!$response) {
            return null;
        }

        $pythonReply = json_decode($response, true);
        $reply = $pythonReply["reply"] ?? null;

        if (!$reply || strtolower(trim($reply)) === "sorry, i could not understand.") {
            return null;
        }

        return trim($reply);
    }

    private static function localTranslateToHindi($reply) {
        $reply = trim((string) $reply);

        if ($reply === "") {
            return "";
        }

        $reply = str_replace(["â‚¹", "₹"], "Rs. ", $reply);
        $reply = preg_replace('/\s+/', ' ', $reply);

        $patternMap = [
            '/^Your USN is ([A-Z0-9]+)\.$/' => 'आपका USN $1 है।',
            '/^You are currently studying in the (\d+)(?:st|nd|rd|th) semester\.$/' => 'आप अभी $1वें सेमेस्टर में पढ़ रहे हैं।',
            '/^You are from the (.+) department\.$/' => 'आप $1 विभाग से हैं।',
            '/^Your overall attendance is ([0-9.]+) percent\.$/' => 'आपकी कुल उपस्थिति $1 प्रतिशत है।',
            '/^The course code for (.+) is ([A-Z0-9-]+)\.$/' => '$1 का कोर्स कोड $2 है।',
            '/^(.+) has course code ([A-Z0-9-]+)\. It is a (.+) course with ([0-9.]+) credits? in semester (\d+)\.$/' => '$1 का कोर्स कोड $2 है। यह $5वें सेमेस्टर का $3 कोर्स है, जिसमें $4 क्रेडिट हैं।',
            '/^In semester (\d+), your subjects are (.+)\.$/' => '$1वें सेमेस्टर में आपके विषय हैं: $2।',
            '/^In semester (\d+), your SGPA is ([0-9.]+)\. You have earned ([0-9.]+) credits\. Your result status is fail because you still have ([0-9.]+) backlogs?, including (.+)\.$/' => '$1वें सेमेस्टर में आपका SGPA $2 है। आपने $3 क्रेडिट अर्जित किए हैं। आपका परिणाम अनुत्तीर्ण है क्योंकि आपके अभी $4 बैकलॉग हैं, जिनमें $5 शामिल हैं।',
            '/^In semester (\d+), your SGPA is ([0-9.]+)\. You have earned ([0-9.]+) credits\. You passed with outstanding performance\.$/' => '$1वें सेमेस्टर में आपका SGPA $2 है। आपने $3 क्रेडिट अर्जित किए हैं। आपने शानदार प्रदर्शन के साथ सफलता प्राप्त की है।',
            '/^In semester (\d+), your SGPA is ([0-9.]+)\. You have earned ([0-9.]+) credits\. You passed with excellent performance\.$/' => '$1वें सेमेस्टर में आपका SGPA $2 है। आपने $3 क्रेडिट अर्जित किए हैं। आपने बेहतरीन प्रदर्शन के साथ सफलता प्राप्त की है।',
            '/^In semester (\d+), your SGPA is ([0-9.]+)\. You have earned ([0-9.]+) credits\. You passed with good performance\.$/' => '$1वें सेमेस्टर में आपका SGPA $2 है। आपने $3 क्रेडिट अर्जित किए हैं। आपने अच्छे प्रदर्शन के साथ सफलता प्राप्त की है।',
            '/^In semester (\d+), your SGPA is ([0-9.]+)\. You have earned ([0-9.]+) credits\. You passed with satisfactory performance\.$/' => '$1वें सेमेस्टर में आपका SGPA $2 है। आपने $3 क्रेडिट अर्जित किए हैं। आपने संतोषजनक प्रदर्शन के साथ सफलता प्राप्त की है।',
            '/^In semester (\d+), your SGPA is ([0-9.]+)\. You have earned ([0-9.]+) credits\. You passed, but you should improve next semester\.$/' => '$1वें सेमेस्टर में आपका SGPA $2 है। आपने $3 क्रेडिट अर्जित किए हैं। आप पास हो गए हैं, लेकिन अगले सेमेस्टर में और बेहतर करने की जरूरत है।',
            '/^In semester (\d+), your SGPA is ([0-9.]+)\. You have earned ([0-9.]+) credits\. (.+)\.$/' => '$1वें सेमेस्टर में आपका SGPA $2 है। आपने $3 क्रेडिट अर्जित किए हैं। $4।',
            '/^Your current CGPA is ([0-9.]+), calculated across ([0-9.]+) semesters?\. You currently have ([0-9.]+) uncleared backlogs?\.$/' => 'आपका वर्तमान CGPA $1 है, जो $2 सेमेस्टर के आधार पर निकाला गया है। आपके अभी $3 अनक्लियर बैकलॉग हैं।',
            '/^Your current CGPA is ([0-9.]+), calculated across ([0-9.]+) semesters?\. You do not have any current backlog\.$/' => 'आपका वर्तमान CGPA $1 है, जो $2 सेमेस्टर के आधार पर निकाला गया है। आपका अभी कोई बैकलॉग नहीं है।',
            '/^Your attendance in (.+) is ([0-9.]+) percent\. You attended ([0-9.]+) out of ([0-9.]+) classes\.$/' => '$1 में आपकी उपस्थिति $2 प्रतिशत है। आपने $4 में से $3 कक्षाएं अटेंड की हैं।',
            '/^Your attendance in (.+) is ([0-9.]+) percent\. You attended ([0-9.]+) out of ([0-9.]+) classes\. Warning: Your attendance is below the required 75 percent\.$/' => '$1 में आपकी उपस्थिति $2 प्रतिशत है। आपने $4 में से $3 कक्षाएं अटेंड की हैं। चेतावनी: आपकी उपस्थिति आवश्यक 75 प्रतिशत से कम है।',
            '/^You passed semester ([0-9.]+) and you do not have any backlog in that semester\.$/' => 'आप $1वें सेमेस्टर में पास हैं और उस सेमेस्टर में आपका कोई बैकलॉग नहीं है।',
            '/^In semester ([0-9.]+), you have ([0-9.]+) backlogs?\. The uncleared subjects? (?:are|is) (.+)\.$/' => '$1वें सेमेस्टर में आपके $2 बैकलॉग हैं। अनक्लियर विषय हैं: $3।',
            '/^You currently have ([0-9.]+) backlogs?\. Uncleared subjects are (.+)\.$/' => 'आपके अभी $1 बैकलॉग हैं। अनक्लियर विषय हैं: $2।',
            '/^Your (.+) hall ticket for semester ([0-9.]+) in ([0-9-]+) has been generated successfully\. You can download it from the hall ticket section\.$/' => '$2वें सेमेस्टर, $3 के लिए आपका $1 hall ticket सफलतापूर्वक तैयार हो गया है। आप इसे hall ticket section से डाउनलोड कर सकते हैं।',
            '/^Your (.+) hall ticket for semester ([0-9.]+) in ([0-9-]+) is not generated yet\. (.+)$/' => '$2वें सेमेस्टर, $3 के लिए आपका $1 hall ticket अभी तैयार नहीं हुआ है। $4',
            '/^Your (.+) hall ticket for semester ([0-9.]+) in ([0-9-]+) is not available right now\. (.+)$/' => '$2वें सेमेस्टर, $3 के लिए आपका $1 hall ticket अभी उपलब्ध नहीं है। $4',
            '/^I found a hall ticket record for your (.+) exam, but the current status needs manual verification\. Please contact the exam section\.$/' => 'मुझे आपके $1 exam का hall ticket record मिला है, लेकिन मौजूदा स्थिति के लिए manual verification चाहिए। कृपया exam section से संपर्क करें।'
        ];

        $patternMap['/^In semester (\d+), your SGPA is ([0-9.]+)\. You have earned ([0-9.]+) credits\. You passed with outstanding performance\.$/'] = 'ನಿಮ್ಮ $1ನೇ ಸೆಮಿಸ್ಟರ್ SGPA $2. ನೀವು $3 ಕ್ರೆಡಿಟ್ ಗಳಿಸಿದ್ದೀರಿ. ನೀವು ಅತ್ಯುತ್ತಮ ಪ್ರದರ್ಶನದೊಂದಿಗೆ ಪಾಸ್ ಆಗಿದ್ದೀರಿ.';
        $patternMap['/^In semester (\d+), your SGPA is ([0-9.]+)\. You have earned ([0-9.]+) credits\. You passed with excellent performance\.$/'] = 'ನಿಮ್ಮ $1ನೇ ಸೆಮಿಸ್ಟರ್ SGPA $2. ನೀವು $3 ಕ್ರೆಡಿಟ್ ಗಳಿಸಿದ್ದೀರಿ. ನೀವು ಅತ್ಯುತ್ತಮ ಪ್ರದರ್ಶನದೊಂದಿಗೆ ಪಾಸ್ ಆಗಿದ್ದೀರಿ.';
        $patternMap['/^In semester (\d+), your SGPA is ([0-9.]+)\. You have earned ([0-9.]+) credits\. You passed with good performance\.$/'] = 'ನಿಮ್ಮ $1ನೇ ಸೆಮಿಸ್ಟರ್ SGPA $2. ನೀವು $3 ಕ್ರೆಡಿಟ್ ಗಳಿಸಿದ್ದೀರಿ. ನೀವು ಉತ್ತಮ ಪ್ರದರ್ಶನದೊಂದಿಗೆ ಪಾಸ್ ಆಗಿದ್ದೀರಿ.';
        $patternMap['/^In semester (\d+), your SGPA is ([0-9.]+)\. You have earned ([0-9.]+) credits\. You passed with satisfactory performance\.$/'] = 'ನಿಮ್ಮ $1ನೇ ಸೆಮಿಸ್ಟರ್ SGPA $2. ನೀವು $3 ಕ್ರೆಡಿಟ್ ಗಳಿಸಿದ್ದೀರಿ. ನೀವು ತೃಪ್ತಿಕರ ಪ್ರದರ್ಶನದೊಂದಿಗೆ ಪಾಸ್ ಆಗಿದ್ದೀರಿ.';
        $patternMap['/^In semester (\d+), your SGPA is ([0-9.]+)\. You have earned ([0-9.]+) credits\. You passed, but you should improve next semester\.$/'] = 'ನಿಮ್ಮ $1ನೇ ಸೆಮಿಸ್ಟರ್ SGPA $2. ನೀವು $3 ಕ್ರೆಡಿಟ್ ಗಳಿಸಿದ್ದೀರಿ. ನೀವು ಪಾಸ್ ಆಗಿದ್ದೀರಿ, ಆದರೆ ಮುಂದಿನ ಸೆಮಿಸ್ಟರ್‌ನಲ್ಲಿ ಇನ್ನಷ್ಟು ಉತ್ತಮಪಡಿಸಬೇಕು.';
        $patternMap['/^In semester (\d+), your SGPA is ([0-9.]+)\. You have earned ([0-9.]+) credits\. Your result status is fail because you still have ([0-9.]+) backlogs?, including (.+)\.$/'] = 'ನಿಮ್ಮ $1ನೇ ಸೆಮಿಸ್ಟರ್ SGPA $2. ನೀವು $3 ಕ್ರೆಡಿಟ್ ಗಳಿಸಿದ್ದೀರಿ. ನಿಮ್ಮ ಫಲಿತಾಂಶ ಫೇಲ್ ಆಗಿದೆ, ಏಕೆಂದರೆ ಇನ್ನೂ $4 ಬ್ಯಾಕ್‌ಲಾಗ್ ಇದೆ. ಒಳಗೊಂಡ ವಿಷಯಗಳು: $5.';
        $patternMap['/^Here is your fee summary\. Your program fee is Rs\. ([0-9,]+\.[0-9]{2})\. Your skill development fee is Rs\. ([0-9,]+\.[0-9]{2})\. Your total academic fee is Rs\. ([0-9,]+\.[0-9]{2})\. You have paid Rs\. ([0-9,]+\.[0-9]{2})\. Your remaining balance is Rs\. ([0-9,]+\.[0-9]{2})\.$/'] = 'ಇದು ನಿಮ್ಮ ಫೀಸ್ ವಿವರ. ನಿಮ್ಮ ಪ್ರೋಗ್ರಾಂ ಫೀಸ್ Rs. $1. ನಿಮ್ಮ ಸ್ಕಿಲ್ ಡೆವಲಪ್ಮೆಂಟ್ ಫೀಸ್ Rs. $2. ನಿಮ್ಮ ಒಟ್ಟು ಅಕಾಡೆಮಿಕ್ ಫೀಸ್ Rs. $3. ನೀವು Rs. $4 ಪಾವತಿಸಿದ್ದೀರಿ. ನಿಮ್ಮ ಬಾಕಿ ಮೊತ್ತ Rs. $5.';
        $patternMap['/^Here is your fee summary\. Your program fee is Rs\. ([0-9,]+\.[0-9]{2})\. Your total academic fee is Rs\. ([0-9,]+\.[0-9]{2})\. You have paid Rs\. ([0-9,]+\.[0-9]{2})\. Your remaining balance is Rs\. ([0-9,]+\.[0-9]{2})\.$/'] = 'ಇದು ನಿಮ್ಮ ಫೀಸ್ ವಿವರ. ನಿಮ್ಮ ಪ್ರೋಗ್ರಾಂ ಫೀಸ್ Rs. $1. ನಿಮ್ಮ ಒಟ್ಟು ಅಕಾಡೆಮಿಕ್ ಫೀಸ್ Rs. $2. ನೀವು Rs. $3 ಪಾವತಿಸಿದ್ದೀರಿ. ನಿಮ್ಮ ಬಾಕಿ ಮೊತ್ತ Rs. $4.';

        $patternMap['/^In semester (\d+), your SGPA is ([0-9.]+)\. You have earned ([0-9.]+) credits\. You passed with excellent performance\.$/'] = 'ನಿಮ್ಮ $1ನೇ ಸೆಮಿಸ್ಟರ್ SGPA $2. ನೀವು $3 ಕ್ರೆಡಿಟ್ ಗಳಿಸಿದ್ದೀರಿ. ನೀವು ಅತ್ಯುತ್ತಮ ಪ್ರದರ್ಶನದೊಂದಿಗೆ ಪಾಸ್ ಆಗಿದ್ದೀರಿ.';
        $patternMap['/^In semester (\d+), your SGPA is ([0-9.]+)\. You have earned ([0-9.]+) credits\. You passed with outstanding performance\.$/'] = 'ನಿಮ್ಮ $1ನೇ ಸೆಮಿಸ್ಟರ್ SGPA $2. ನೀವು $3 ಕ್ರೆಡಿಟ್ ಗಳಿಸಿದ್ದೀರಿ. ನೀವು ಅತ್ಯುತ್ತಮ ಪ್ರದರ್ಶನದೊಂದಿಗೆ ಪಾಸ್ ಆಗಿದ್ದೀರಿ.';
        $patternMap['/^In semester (\d+), your SGPA is ([0-9.]+)\. You have earned ([0-9.]+) credits\. You passed with good performance\.$/'] = 'ನಿಮ್ಮ $1ನೇ ಸೆಮಿಸ್ಟರ್ SGPA $2. ನೀವು $3 ಕ್ರೆಡಿಟ್ ಗಳಿಸಿದ್ದೀರಿ. ನೀವು ಉತ್ತಮ ಪ್ರದರ್ಶನದೊಂದಿಗೆ ಪಾಸ್ ಆಗಿದ್ದೀರಿ.';
        $patternMap['/^In semester (\d+), your SGPA is ([0-9.]+)\. You have earned ([0-9.]+) credits\. You passed with satisfactory performance\.$/'] = 'ನಿಮ್ಮ $1ನೇ ಸೆಮಿಸ್ಟರ್ SGPA $2. ನೀವು $3 ಕ್ರೆಡಿಟ್ ಗಳಿಸಿದ್ದೀರಿ. ನೀವು ತೃಪ್ತಿಕರ ಪ್ರದರ್ಶನದೊಂದಿಗೆ ಪಾಸ್ ಆಗಿದ್ದೀರಿ.';
        $patternMap['/^In semester (\d+), your SGPA is ([0-9.]+)\. You have earned ([0-9.]+) credits\. You passed, but you should improve next semester\.$/'] = 'ನಿಮ್ಮ $1ನೇ ಸೆಮಿಸ್ಟರ್ SGPA $2. ನೀವು $3 ಕ್ರೆಡಿಟ್ ಗಳಿಸಿದ್ದೀರಿ. ನೀವು ಪಾಸ್ ಆಗಿದ್ದೀರಿ, ಆದರೆ ಮುಂದಿನ ಸೆಮಿಸ್ಟರ್‌ನಲ್ಲಿ ಇನ್ನಷ್ಟು ಉತ್ತಮಪಡಿಸಬೇಕು.';
        $patternMap['/^In semester (\d+), your SGPA is ([0-9.]+)\. You have earned ([0-9.]+) credits\. Your result status is fail because you still have ([0-9.]+) backlogs?, including (.+)\.$/'] = 'ನಿಮ್ಮ $1ನೇ ಸೆಮಿಸ್ಟರ್ SGPA $2. ನೀವು $3 ಕ್ರೆಡಿಟ್ ಗಳಿಸಿದ್ದೀರಿ. ನಿಮ್ಮ ಫಲಿತಾಂಶ ಫೇಲ್ ಆಗಿದೆ, ಏಕೆಂದರೆ ಇನ್ನೂ $4 ಬ್ಯಾಕ್‌ಲಾಗ್ ಇದೆ. ಒಳಗೊಂಡ ವಿಷಯಗಳು: $5.';
        $patternMap['/^Here is your fee summary\. Your program fee is Rs\. ([0-9,]+\.[0-9]{2})\. Your skill development fee is Rs\. ([0-9,]+\.[0-9]{2})\. Your total academic fee is Rs\. ([0-9,]+\.[0-9]{2})\. You have paid Rs\. ([0-9,]+\.[0-9]{2})\. Your remaining balance is Rs\. ([0-9,]+\.[0-9]{2})\.$/'] = 'ಇದು ನಿಮ್ಮ ಫೀಸ್ ವಿವರ. ನಿಮ್ಮ ಪ್ರೋಗ್ರಾಂ ಫೀಸ್ Rs. $1. ನಿಮ್ಮ ಸ್ಕಿಲ್ ಡೆವಲಪ್ಮೆಂಟ್ ಫೀಸ್ Rs. $2. ನಿಮ್ಮ ಒಟ್ಟು ಅಕಾಡೆಮಿಕ್ ಫೀಸ್ Rs. $3. ನೀವು Rs. $4 ಪಾವತಿಸಿದ್ದೀರಿ. ನಿಮ್ಮ ಬಾಕಿ ಮೊತ್ತ Rs. $5.';
        $patternMap['/^Here is your fee summary\. Your program fee is Rs\. ([0-9,]+\.[0-9]{2})\. Your total academic fee is Rs\. ([0-9,]+\.[0-9]{2})\. You have paid Rs\. ([0-9,]+\.[0-9]{2})\. Your remaining balance is Rs\. ([0-9,]+\.[0-9]{2})\.$/'] = 'ಇದು ನಿಮ್ಮ ಫೀಸ್ ವಿವರ. ನಿಮ್ಮ ಪ್ರೋಗ್ರಾಂ ಫೀಸ್ Rs. $1. ನಿಮ್ಮ ಒಟ್ಟು ಅಕಾಡೆಮಿಕ್ ಫೀಸ್ Rs. $2. ನೀವು Rs. $3 ಪಾವತಿಸಿದ್ದೀರಿ. ನಿಮ್ಮ ಬಾಕಿ ಮೊತ್ತ Rs. $4.';
        $patternMap['/^Here is your fee summary\. Your program fee is Rs\. ([0-9,]+\.[0-9]{2})\. Your skill development fee is Rs\. ([0-9,]+\.[0-9]{2})\. Your total academic fee is Rs\. ([0-9,]+\.[0-9]{2})\. You have paid Rs\. ([0-9,]+\.[0-9]{2})\. You have cleared all your fees\. Well done\.$/'] = 'ಇದು ನಿಮ್ಮ ಫೀಸ್ ವಿವರ. ನಿಮ್ಮ ಪ್ರೋಗ್ರಾಂ ಫೀಸ್ Rs. $1. ನಿಮ್ಮ ಸ್ಕಿಲ್ ಡೆವಲಪ್ಮೆಂಟ್ ಫೀಸ್ Rs. $2. ನಿಮ್ಮ ಒಟ್ಟು ಅಕಾಡೆಮಿಕ್ ಫೀಸ್ Rs. $3. ನೀವು Rs. $4 ಪಾವತಿಸಿದ್ದೀರಿ. ನಿಮ್ಮ ಎಲ್ಲಾ ಫೀಸ್ ಪಾವತಿಸಲಾಗಿದೆ.';
        $patternMap['/^Here is your fee summary\. Your program fee is Rs\. ([0-9,]+\.[0-9]{2})\. Your total academic fee is Rs\. ([0-9,]+\.[0-9]{2})\. You have paid Rs\. ([0-9,]+\.[0-9]{2})\. You have cleared all your fees\. Well done\.$/'] = 'ಇದು ನಿಮ್ಮ ಫೀಸ್ ವಿವರ. ನಿಮ್ಮ ಪ್ರೋಗ್ರಾಂ ಫೀಸ್ Rs. $1. ನಿಮ್ಮ ಒಟ್ಟು ಅಕಾಡೆಮಿಕ್ ಫೀಸ್ Rs. $2. ನೀವು Rs. $3 ಪಾವತಿಸಿದ್ದೀರಿ. ನಿಮ್ಮ ಎಲ್ಲಾ ಫೀಸ್ ಪಾವತಿಸಲಾಗಿದೆ.';

        foreach ($patternMap as $pattern => $replacement) {
            if (preg_match($pattern, $reply)) {
                return preg_replace($pattern, $replacement, $reply);
            }
        }

        $replacements = [
            "Here is your profile summary." => "यह आपकी प्रोफाइल का सारांश है।",
            "Your name is " => "आपका नाम ",
            "You are from " => "आप ",
            "You are in the " => "आप ",
            " semester." => "वें सेमेस्टर में हैं।",
            " How can I help you today?" => " मैं आपकी क्या मदद कर सकता हूं?",
            "You are " => "आप ",
            "a student at GM University" => "GM University के छात्र हैं",
            "student at GM University" => "GM University के छात्र हैं",
            "Your course registration is complete and your final registration is also completed successfully. There is no pending fee balance." => "आपका कोर्स पंजीकरण और अंतिम पंजीकरण सफलतापूर्वक पूरा हो चुका है। कोई बकाया शुल्क शेष नहीं है।",
            "Your course registration is complete, but your final registration is still pending because you have an outstanding balance of Rs. " => "आपका कोर्स पंजीकरण पूरा है, लेकिन आपका अंतिम पंजीकरण अभी लंबित है क्योंकि आपके ऊपर Rs. ",
            ". Pending items include " => " की बकाया राशि है। लंबित मदों में ",
            " balance of Rs. " => " की राशि Rs. ",
            ". Please clear the balance to complete final registration." => " शामिल हैं। अंतिम पंजीकरण पूरा करने के लिए कृपया यह राशि जमा करें।",
            "I could not find your registration payment details." => "मुझे आपकी पंजीकरण भुगतान जानकारी नहीं मिली।",
            "Here is your fee summary." => "यह आपकी शुल्क जानकारी है।",
            "Your program fee is Rs. " => "आपकी प्रोग्राम फीस Rs. ",
            "Your skill development fee is Rs. " => "आपकी skill development फीस Rs. ",
            "Your total academic fee is Rs. " => "आपकी कुल शैक्षणिक फीस Rs. ",
            "You have paid Rs. " => "आपने Rs. ",
            "Your remaining balance is Rs. " => "आपकी शेष बकाया राशि Rs. ",
            "You have cleared all your fees. Well done." => "आपकी सारी फीस जमा हो चुकी है। बहुत अच्छा।",
            "No result information found." => "कोई परिणाम जानकारी नहीं मिली।",
            "In semester " => "सेमेस्टर ",
            ", your SGPA is " => " में आपका SGPA ",
            ". You have earned " => " है। आपने ",
            " credits. Your result status is fail because you still have " => " क्रेडिट अर्जित किए हैं। आपका परिणाम अनुत्तीर्ण है क्योंकि आपके अभी ",
            " backlog, including " => " बैकलॉग है, जिसमें ",
            " backlogs, including " => " बैकलॉग हैं, जिनमें ",
            "You passed with outstanding performance." => "आपने शानदार प्रदर्शन के साथ सफलता प्राप्त की है।",
            "You passed with excellent performance." => "आपने बेहतरीन प्रदर्शन के साथ सफलता प्राप्त की है।",
            "You passed with good performance." => "आपने अच्छे प्रदर्शन के साथ सफलता प्राप्त की है।",
            "You passed with satisfactory performance." => "आपने संतोषजनक प्रदर्शन के साथ सफलता प्राप्त की है।",
            "You passed, but you should improve next semester." => "आप पास हो गए हैं, लेकिन अगले सेमेस्टर में और बेहतर करने की जरूरत है।",
            "I could not find enough result data to calculate your CGPA." => "मुझे आपका CGPA निकालने के लिए पर्याप्त परिणाम जानकारी नहीं मिली।",
            " You currently have " => " आपके अभी ",
            " uncleared backlog." => " अनक्लियर बैकलॉग है।",
            " uncleared backlogs." => " अनक्लियर बैकलॉग हैं।",
            " You do not have any current backlog." => " आपका अभी कोई बैकलॉग नहीं है।",
            "You do not have any current backlog. Your available result records show that you have passed all cleared semesters." => "आपका अभी कोई बैकलॉग नहीं है। उपलब्ध परिणाम अभिलेख बताते हैं कि आपने सभी clear किए गए सेमेस्टर पास कर लिए हैं।",
            "I could not find any hall ticket status for your account right now." => "मुझे अभी आपके खाते के लिए कोई hall ticket स्थिति नहीं मिली।",
            "Please check again later." => "कृपया बाद में फिर से जांच करें।",
            "Please contact your HOD or the exam section." => "कृपया अपने HOD या परीक्षा विभाग से संपर्क करें।",
            "I could not find your semester and branch details." => "मुझे आपकी सेमेस्टर और शाखा की जानकारी नहीं मिली।",
            "I could not find any course details for your current semester." => "मुझे आपके वर्तमान सेमेस्टर के लिए कोई कोर्स जानकारी नहीं मिली।",
            "I could not find that course code. Please say the subject name more clearly." => "मुझे वह कोर्स कोड नहीं मिला। कृपया विषय का नाम थोड़ा और साफ बोलें।",
            "Please tell me the subject name. For example, you can ask about " => "कृपया विषय का नाम बताइए। उदाहरण के लिए आप पूछ सकते हैं ",
            "Please tell me the subject name for which you want attendance." => "कृपया उस विषय का नाम बताइए जिसके लिए आप उपस्थिति पूछना चाहते हैं।",
            "I could not find attendance for that subject." => "मुझे उस विषय की उपस्थिति नहीं मिली।",
            "Attendance data not found." => "उपस्थिति की जानकारी नहीं मिली।",
            "System error while fetching attendance." => "उपस्थिति की जानकारी लेते समय system error आया।",
            "System error while fetching course details." => "कोर्स की जानकारी लेते समय system error आया।",
            "System error while checking hall ticket status." => "Hall ticket स्थिति जांचते समय system error आया।",
            "System error while checking backlog status." => "बैकलॉग स्थिति जांचते समय system error आया।",
            "System error while fetching CGPA." => "CGPA की जानकारी लेते समय system error आया।",
            "System error while fetching result." => "परिणाम की जानकारी लेते समय system error आया।",
            "I could not find your student profile right now." => "मुझे अभी आपकी छात्र प्रोफाइल नहीं मिली।",
            "I could not find your semester details right now." => "मुझे अभी आपकी सेमेस्टर जानकारी नहीं मिली।",
            "I could not find your department details right now." => "मुझे अभी आपके विभाग की जानकारी नहीं मिली।"
        ];

        $replacements["You have cleared all your fees. Well done."] = "ನಿಮ್ಮ ಎಲ್ಲಾ ಫೀಸ್ ಪಾವತಿಸಲಾಗಿದೆ.";
        $replacements["No result information found."] = "ಫಲಿತಾಂಶದ ಮಾಹಿತಿ ಸಿಗಲಿಲ್ಲ.";
        $replacements["Please check again later."] = "ದಯವಿಟ್ಟು ನಂತರ ಮತ್ತೆ ಪರಿಶೀಲಿಸಿ.";
        $replacements["Please contact your HOD or the exam section."] = "ದಯವಿಟ್ಟು ನಿಮ್ಮ HOD ಅಥವಾ exam section ಅನ್ನು ಸಂಪರ್ಕಿಸಿ.";

        $translated = strtr($reply, $replacements);
        $translated = preg_replace('/\s+/', ' ', $translated);
        return trim((string) $translated);
    }

    private static function localTranslateToKannada($reply) {
        $reply = trim((string) $reply);

        if ($reply === "") {
            return "";
        }

        $reply = str_replace(["Ã¢â€šÂ¹", "â‚¹", "₹"], "Rs. ", $reply);
        $reply = preg_replace('/\s+/', ' ', $reply);

        $patternMap = [
            '/^Your USN is ([A-Z0-9]+)\.$/' => 'ನಿಮ್ಮ USN $1.',
            '/^You are currently studying in the (\d+)(?:st|nd|rd|th) semester\.$/' => 'ನೀವು ಈಗ $1ನೇ ಸೆಮಿಸ್ಟರ್‌ನಲ್ಲಿ ಓದುತ್ತಿದ್ದೀರಿ.',
            '/^You are from the (.+) department\.$/' => 'ನೀವು $1 ವಿಭಾಗದವರು.',
            '/^Your overall attendance is ([0-9.]+) percent\.$/' => 'ನಿಮ್ಮ ಒಟ್ಟು attendance $1 ಪ್ರತಿಶತವಾಗಿದೆ.',
            '/^The course code for (.+) is ([A-Z0-9-]+)\.$/' => '$1 ವಿಷಯದ course code $2.',
            '/^In semester (\d+), your subjects are (.+)\.$/' => '$1ನೇ ಸೆಮಿಸ್ಟರ್‌ನಲ್ಲಿ ನಿಮ್ಮ subjects: $2.',
            '/^Your current CGPA is ([0-9.]+), calculated across ([0-9.]+) semesters?\. You currently have ([0-9.]+) uncleared backlogs?\.$/' => 'ನಿಮ್ಮ current CGPA $1. ಇದು $2 ಸೆಮಿಸ್ಟರ್ ಆಧಾರದಲ್ಲಿ ಲೆಕ್ಕಿಸಲಾಗಿದೆ. ನಿಮಗೆ ಈಗ $3 uncleared backlogಗಳಿವೆ.',
            '/^Your current CGPA is ([0-9.]+), calculated across ([0-9.]+) semesters?\. You do not have any current backlog\.$/' => 'ನಿಮ್ಮ current CGPA $1. ಇದು $2 ಸೆಮಿಸ್ಟರ್ ಆಧಾರದಲ್ಲಿ ಲೆಕ್ಕಿಸಲಾಗಿದೆ. ನಿಮಗೆ ಈಗ ಯಾವುದೇ backlog ಇಲ್ಲ.',
            '/^You currently have ([0-9.]+) backlogs?\. Uncleared subjects are (.+)\.$/' => 'ನಿಮಗೆ ಈಗ $1 backlogಗಳಿವೆ. ಉಳಿದಿರುವ subjects: $2.',
            '/^You passed semester ([0-9.]+) and you do not have any backlog in that semester\.$/' => 'ನೀವು $1ನೇ ಸೆಮಿಸ್ಟರ್ ಪಾಸ್ ಆಗಿದ್ದೀರಿ ಮತ್ತು ಆ ಸೆಮಿಸ್ಟರ್‌ನಲ್ಲಿ backlog ಇಲ್ಲ.',
            '/^Your attendance in (.+) is ([0-9.]+) percent\. You attended ([0-9.]+) out of ([0-9.]+) classes\.$/' => '$1 ವಿಷಯದಲ್ಲಿ ನಿಮ್ಮ attendance $2 ಪ್ರತಿಶತ. ನೀವು $4 classesಗಳಲ್ಲಿ $3 classes attend ಮಾಡಿದ್ದೀರಿ.',
            '/^Your attendance in (.+) is ([0-9.]+) percent\. You attended ([0-9.]+) out of ([0-9.]+) classes\. Warning: Your attendance is below the required 75 percent\.$/' => '$1 ವಿಷಯದಲ್ಲಿ ನಿಮ್ಮ attendance $2 ಪ್ರತಿಶತ. ನೀವು $4 classesಗಳಲ್ಲಿ $3 classes attend ಮಾಡಿದ್ದೀರಿ. ಎಚ್ಚರಿಕೆ: ನಿಮ್ಮ attendance 75 ಪ್ರತಿಶತಕ್ಕಿಂತ ಕಡಿಮೆ ಇದೆ.',
            '/^Your course registration is complete and your final registration is also completed successfully\. There is no pending fee balance\.$/' => 'ನಿಮ್ಮ course registration complete ಆಗಿದೆ ಮತ್ತು final registration ಕೂಡ ಯಶಸ್ವಿಯಾಗಿ ಪೂರ್ಣವಾಗಿದೆ. ಯಾವುದೇ pending fee balance ಇಲ್ಲ.',
            '/^Your course registration is complete, but your final registration is still pending because you have an outstanding balance of Rs\. ([0-9.,]+)\. Pending items include (.+)\. Please clear the balance to complete final registration\.$/' => 'ನಿಮ್ಮ course registration complete ಆಗಿದೆ, ಆದರೆ final registration ಇನ್ನೂ pending ಇದೆ, ಏಕೆಂದರೆ ನಿಮ್ಮಲ್ಲಿ Rs. $1 ಬಾಕಿಯಿದೆ. Pending items: $2. Final registration complete ಮಾಡಲು ದಯವಿಟ್ಟು balance clear ಮಾಡಿ.',
            '/^I could not find your registration payment details\.$/' => 'ನಿಮ್ಮ registration payment details ಸಿಗಲಿಲ್ಲ.',
            '/^I could not find your student profile right now\.$/' => 'ಈಗ ನಿಮ್ಮ student profile ಸಿಗಲಿಲ್ಲ.',
            '/^I could not find your semester details right now\.$/' => 'ಈಗ ನಿಮ್ಮ semester details ಸಿಗಲಿಲ್ಲ.',
            '/^I could not find your department details right now\.$/' => 'ಈಗ ನಿಮ್ಮ department details ಸಿಗಲಿಲ್ಲ.',
            '/^I could not find any course details for your current semester\.$/' => 'ನಿಮ್ಮ current semesterಗೆ course details ಸಿಗಲಿಲ್ಲ.',
            '/^I could not find any hall ticket status for your account right now\.$/' => 'ಈಗ ನಿಮ್ಮ accountಗೆ hall ticket status ಸಿಗಲಿಲ್ಲ.',
            '/^Your (.+) hall ticket for semester ([0-9.]+) in ([0-9-]+) has been generated successfully\. You can download it from the hall ticket section\.$/' => '$2ನೇ ಸೆಮಿಸ್ಟರ್, $3ಕ್ಕೆ ನಿಮ್ಮ $1 hall ticket ಯಶಸ್ವಿಯಾಗಿ generate ಆಗಿದೆ. ನೀವು ಅದನ್ನು hall ticket sectionನಲ್ಲಿ download ಮಾಡಬಹುದು.'
        ];

        foreach ($patternMap as $pattern => $replacement) {
            if (preg_match($pattern, $reply)) {
                return preg_replace($pattern, $replacement, $reply);
            }
        }

        $replacements = [
            "Here is your profile summary." => "ಇದು ನಿಮ್ಮ profile summary.",
            "Your name is " => "ನಿಮ್ಮ ಹೆಸರು ",
            "You are from " => "ನೀವು ",
            "You are in the " => "ನೀವು ",
            " semester." => "ನೇ ಸೆಮಿಸ್ಟರ್‌ನಲ್ಲಿ ಇದ್ದೀರಿ.",
            "Your USN is " => "ನಿಮ್ಮ USN ",
            "How can I help you today?" => "ಇಂದು ನಾನು ನಿಮಗೆ ಹೇಗೆ ಸಹಾಯ ಮಾಡಬಹುದು?",
            "I can help you with your student profile, fees, attendance, results, and course details." => "ನಾನು ನಿಮ್ಮ student profile, fees, attendance, results ಮತ್ತು course details ಬಗ್ಗೆ ಸಹಾಯ ಮಾಡಬಹುದು.",
            "Here is your fee summary." => "ಇದು ನಿಮ್ಮ fee summary.",
            "Your program fee is Rs. " => "ನಿಮ್ಮ program fee Rs. ",
            "Your skill development fee is Rs. " => "ನಿಮ್ಮ skill development fee Rs. ",
            "Your total academic fee is Rs. " => "ನಿಮ್ಮ total academic fee Rs. ",
            "You have paid Rs. " => "ನೀವು Rs. ",
            "Your remaining balance is Rs. " => "ನಿಮ್ಮ remaining balance Rs. ",
            "You do not have any pending fee balance." => "ನಿಮಗೆ ಯಾವುದೇ pending fee balance ಇಲ್ಲ.",
            "No result information found." => "Result ಮಾಹಿತಿ ಸಿಗಲಿಲ್ಲ.",
            "Please check again later." => "ದಯವಿಟ್ಟು ನಂತರ ಮತ್ತೆ ಪರಿಶೀಲಿಸಿ.",
            "Please contact your HOD or the exam section." => "ದಯವಿಟ್ಟು ನಿಮ್ಮ HOD ಅಥವಾ exam section ಅನ್ನು ಸಂಪರ್ಕಿಸಿ."
        ];

        $translated = strtr($reply, $replacements);
        $translated = preg_replace('/\s+/', ' ', $translated);
        return trim((string) $translated);
    }

    public static function getReply($message, $userContext = [], $language = "en") {
        $language = self::normalizeLanguage($language);
        self::$lastReplyMeta = [
            "source" => "unknown"
        ];
        $baseMeta = [
            "intent" => "LLM_ASSIST",
            "route" => "llm",
            "language" => $language,
            "effective_message" => trim((string) $message)
        ];

        if (!isset($_SESSION)) {
            self::clearConversationHistory();
        }

        $studentId = $userContext["student_id"] ?? null;
        $roleKey = $userContext["role_key"] ?? "student";
        $studentName = $studentId ? self::getStudentName($studentId) : null;
        if ($studentName && empty($userContext["full_name"])) {
            $userContext["full_name"] = $studentName;
        }

        $knowledgeItems = KnowledgeBaseService::getRelevantKnowledge($roleKey, $message);

        foreach (self::getProviderOrder() as $provider) {
            $reply = null;

            if ($provider === "gemini") {
                $reply = self::callGemini($message, $userContext, $knowledgeItems, $language);
            }

            if ($provider === "openai") {
                $reply = self::callOpenAI($message, $userContext, $knowledgeItems, $language);
            }

            if ($reply) {
                self::$lastReplyMeta = [
                    "source" => $provider
                ];
                ConversationContextService::saveTurn($message, $reply, $baseMeta);
                return $reply;
            }
        }

        $pythonReply = self::callPythonFallback($message);
        if ($pythonReply) {
            self::$lastReplyMeta = [
                "source" => "python_fallback"
            ];
            ConversationContextService::saveTurn($message, $pythonReply, $baseMeta);
            return $pythonReply;
        }

        self::$lastReplyMeta = [
            "source" => "local_fallback"
        ];
        $fallbackReply = self::localFallback($message, $userContext, $language);
        ConversationContextService::saveTurn($message, $fallbackReply, $baseMeta);
        return $fallbackReply;
    }

    public static function adaptReplyLanguage($reply, $language = "en", $userContext = []) {
        $language = self::normalizeLanguage($language);
        $reply = trim((string) $reply);

        if ($reply === "" || $language === "en") {
            return $reply;
        }

        $translatedReply = self::translateReply($reply, $language, $userContext);
        $meta = self::getLastReplyMeta();
        self::$lastReplyMeta = [
            "source" => str_replace("_translated_reply", "_translated_db", $meta["source"] ?? "translated_db")
        ];
        return $translatedReply;
    }
}
