<?php

class SttService {
    private $conn;
    private $apiKey;

    public function __construct($conn, $apiKey) {
        $this->conn = $conn;
        $this->apiKey = $apiKey;
    }

    public function transcribeUploadedAudio($file, $requestedLanguage = "en", $studentId = null) {
        if (!isset($file["tmp_name"]) || !is_uploaded_file($file["tmp_name"])) {
            throw new InvalidArgumentException("Audio file is missing.", 400);
        }

        $audioPath = $file["tmp_name"];
        $normalizedAudioPath = null;

        try {
            $normalizedAudioPath = $this->normalizeAudioForAsr($audioPath);
            $audioPath = $normalizedAudioPath;
        } catch (Throwable $e) {
            $this->debugLog("audio_normalization_failed", ["error" => $e->getMessage()]);
        }

        $mimeType = mime_content_type($audioPath) ?: "audio/wav";
        $audioData = file_get_contents($audioPath);

        $this->debugLog("audio_capture_received", [
            "source_size_bytes" => (int) ($file["size"] ?? 0),
            "normalized_path_used" => $normalizedAudioPath !== null,
            "mime_type" => $mimeType,
            "audio_size_bytes" => strlen((string) $audioData)
        ]);

        if ($audioData === false || $audioData === "") {
            throw new RuntimeException("Uploaded audio is empty.", 400);
        }

        if (strlen($audioData) < 2048) {
            return ["transcript" => ""];
        }

        $languageConfig = $this->resolveLanguageConfig($requestedLanguage);
        $primaryLanguage = $languageConfig["language"];
        $model = $languageConfig["model"];
        $keyterms = $studentId ? $this->getTranscriptionKeyterms((int) $studentId) : [];

        $primary = $this->callDeepgramListen($audioData, $mimeType, $model, $primaryLanguage, $keyterms);
        $data = $this->decodeDeepgramResponse($primary);
        $selectedLanguage = $primaryLanguage;
        $selectedReason = "primary";
        $selectedScore = 0;
        $selectedConfidence = 0.0;
        $selectedMeanConfidence = 0.0;

        $primaryAlternative = $this->extractDeepgramAlternative($data);
        $primaryRaw = $this->normalizeUnicodeText((string) ($primaryAlternative["transcript"] ?? ""));
        $primaryConfidence = (float) ($primaryAlternative["confidence"] ?? 0.0);
        $primaryMeanConfidence = $this->meanWordConfidenceFromAlternative($primaryAlternative);
        $primaryScore = $this->academicSignalScore($primaryRaw, $studentId);
        $selectedScore = $primaryScore;
        $selectedConfidence = $primaryConfidence;
        $selectedMeanConfidence = $primaryMeanConfidence;

        if ($this->shouldTryFallback($languageConfig, $primaryScore, $primaryConfidence, $primaryMeanConfidence)) {
            foreach ($languageConfig["fallbacks"] as $fallbackLanguage) {
                $fallback = $this->callDeepgramListen($audioData, $mimeType, $model, $fallbackLanguage, $keyterms);
                if ($fallback["response"] === false || (int) ($fallback["status_code"] ?? 0) >= 400) {
                    $this->debugLog("stt_fallback_failed", [
                        "language" => $fallbackLanguage,
                        "status_code" => $fallback["status_code"] ?? 0,
                        "error" => $fallback["curl_error"] ?? ""
                    ]);
                    continue;
                }

                $fallbackData = json_decode((string) $fallback["response"], true);
                $fallbackAlternative = $this->extractDeepgramAlternative($fallbackData);
                $fallbackRaw = $this->normalizeUnicodeText((string) ($fallbackAlternative["transcript"] ?? ""));
                $fallbackScore = $this->academicSignalScore($fallbackRaw, $studentId);
                $fallbackConfidence = (float) ($fallbackAlternative["confidence"] ?? 0.0);
                $fallbackMeanConfidence = $this->meanWordConfidenceFromAlternative($fallbackAlternative);

                if ($fallbackScore > $selectedScore || ($fallbackScore === $selectedScore && $fallbackMeanConfidence > $selectedMeanConfidence)) {
                    $data = $fallbackData;
                    $selectedLanguage = $fallbackLanguage;
                    $selectedReason = $languageConfig["id"] . "_mixed_fallback";
                    $selectedScore = $fallbackScore;
                    $selectedConfidence = $fallbackConfidence;
                    $selectedMeanConfidence = $fallbackMeanConfidence;
                }
            }
        }

        $this->debugLog("stt_selection", [
            "ui_language" => $languageConfig["id"],
            "selected_language" => $selectedLanguage,
            "reason" => $selectedReason,
            "model" => $model,
            "score" => $selectedScore,
            "confidence" => $selectedConfidence,
            "mean_word_confidence" => $selectedMeanConfidence
        ]);

        $alternative = $this->extractDeepgramAlternative($data);
        $rawTranscript = $this->normalizeUnicodeText((string) ($alternative["transcript"] ?? ""));
        $transcript = $languageConfig["id"] === "kn"
            ? $this->postProcessKannadaTranscript($rawTranscript, $studentId)
            : $rawTranscript;
        $transcriptConfidence = (float) ($alternative["confidence"] ?? 0.0);
        $words = is_array($alternative["words"] ?? null) ? $alternative["words"] : [];
        $meanWordConfidence = $this->meanWordConfidenceFromAlternative($alternative);

        return [
            "transcript" => $transcript,
            "raw_transcript" => $rawTranscript,
            "deepgram_language" => $selectedLanguage,
            "stt_selection_reason" => $selectedReason,
            "deepgram_model" => $model,
            "transcript_confidence" => $transcriptConfidence,
            "mean_word_confidence" => $meanWordConfidence,
            "low_confidence_words" => $this->lowConfidenceWords($words)
        ];
    }

    private function resolveLanguageConfig($requestedLanguage) {
        $language = strtolower(trim((string) $requestedLanguage));
        if (in_array($language, ["kn", "kn-in", "kannada"], true)) {
            return [
                "id" => "kn",
                "language" => "kn",
                "model" => getenv("DEEPGRAM_STT_MODEL_KANNADA") ?: getenv("DEEPGRAM_STT_MODEL") ?: "nova-3",
                "fallbacks" => ["multi", "en-IN"]
            ];
        }

        if (in_array($language, ["hi", "hi-in", "hindi"], true)) {
            return [
                "id" => "hi",
                "language" => "hi",
                "model" => getenv("DEEPGRAM_STT_MODEL_HINDI") ?: getenv("DEEPGRAM_STT_MODEL") ?: "nova-3",
                "fallbacks" => ["multi", "en-IN"]
            ];
        }

        return [
            "id" => "en",
            "language" => in_array($language, ["en-in", "en-us", "en-gb"], true) ? $language : "en",
            "model" => getenv("DEEPGRAM_STT_MODEL_ENGLISH") ?: getenv("DEEPGRAM_STT_MODEL") ?: "nova-3",
            "fallbacks" => []
        ];
    }

    private function shouldTryFallback($config, $score, $confidence, $meanConfidence) {
        if (empty($config["fallbacks"])) {
            return false;
        }
        return $score < 2 || $confidence < 0.72 || ($meanConfidence > 0 && $meanConfidence < 0.78);
    }

    private function normalizeAudioForAsr($inputPath) {
        $ffmpeg = getenv("FFMPEG_PATH") ?: "ffmpeg";
        $outputPath = tempnam(sys_get_temp_dir(), "asr_norm_");
        if ($outputPath === false) {
            throw new RuntimeException("Unable to allocate temp audio file.");
        }

        $wavPath = $outputPath . ".wav";
        @unlink($outputPath);

        $command = sprintf(
            '%s -y -i %s -ac 1 -ar 16000 -sample_fmt s16 -af %s %s 2>&1',
            escapeshellcmd($ffmpeg),
            escapeshellarg($inputPath),
            escapeshellarg('highpass=f=120,lowpass=f=3800,afftdn,loudnorm=I=-16:TP=-1.5:LRA=11'),
            escapeshellarg($wavPath)
        );

        exec($command, $output, $exitCode);
        if ($exitCode !== 0 || !file_exists($wavPath)) {
            throw new RuntimeException("FFmpeg normalization failed: " . implode("\n", $output));
        }

        return $wavPath;
    }

    private function callDeepgramListen($audioData, $mimeType, $model, $language, $keyterms) {
        $queryParams = [
            "model=" . rawurlencode($model),
            "language=" . rawurlencode($language),
            "smart_format=true",
            "punctuate=true",
            "utterances=true"
        ];

        foreach ($keyterms as $keyterm) {
            $queryParams[] = "keyterm=" . rawurlencode($keyterm);
        }

        $listenUrl = "https://api.deepgram.com/v1/listen?" . implode("&", $queryParams);
        $ch = curl_init($listenUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $audioData);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: Token " . $this->apiKey,
            "Content-Type: " . ($mimeType ?: "audio/webm")
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $response = curl_exec($ch);
        $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        return [
            "response" => $response,
            "status_code" => $statusCode,
            "curl_error" => $curlError,
            "language" => $language
        ];
    }

    private function decodeDeepgramResponse($result) {
        if ($result["response"] === false) {
            throw new RuntimeException($result["curl_error"] ?: "Unable to reach Deepgram.", 502);
        }

        $data = json_decode((string) $result["response"], true);
        if (($result["status_code"] ?? 0) >= 400) {
            $message = $data["err_msg"] ?? $data["message"] ?? "Deepgram transcription failed.";
            throw new RuntimeException($message, (int) $result["status_code"]);
        }

        return is_array($data) ? $data : [];
    }

    private function extractDeepgramAlternative($data) {
        return is_array($data) ? ($data["results"]["channels"][0]["alternatives"][0] ?? []) : [];
    }

    private function meanWordConfidenceFromAlternative($alternative) {
        $words = is_array($alternative["words"] ?? null) ? $alternative["words"] : [];
        if (empty($words)) {
            return 0.0;
        }

        $sum = 0.0;
        foreach ($words as $wordEntry) {
            $sum += (float) ($wordEntry["confidence"] ?? 0.0);
        }
        return $sum / count($words);
    }

    private function lowConfidenceWords($words) {
        return array_values(array_map(
            function ($wordEntry) {
                return [
                    "word" => $wordEntry["word"] ?? "",
                    "confidence" => (float) ($wordEntry["confidence"] ?? 0.0),
                    "start" => (float) ($wordEntry["start"] ?? 0.0),
                    "end" => (float) ($wordEntry["end"] ?? 0.0)
                ];
            },
            array_filter($words, function ($wordEntry) {
                return (float) ($wordEntry["confidence"] ?? 1.0) < 0.75;
            })
        ));
    }

    private function getTranscriptionKeyterms($studentId) {
        $keyterms = [
            "GM University", "GMU", "course code", "subject code", "payment options",
            "payment portal", "backlog", "backlogs", "registration", "attendance",
            "result", "nanna result", "result helu", "result heli", "hall ticket",
            "semester", "Computer Science", "DBMS", "Operating Systems",
            "Computer Networks", "Artificial Intelligence", "home page", "dashboard",
            "profile", "faculty details"
        ];

        if (!$studentId || !$this->conn) {
            return $this->dedupeKeyterms($keyterms);
        }

        $studentStmt = $this->conn->prepare("SELECT branch, semester FROM students WHERE student_id = ?");
        if (!$studentStmt) {
            return $this->dedupeKeyterms($keyterms);
        }

        $studentStmt->bind_param("i", $studentId);
        $studentStmt->execute();
        $student = $studentStmt->get_result()->fetch_assoc();
        $studentStmt->close();

        if (!$student) {
            return $this->dedupeKeyterms($keyterms);
        }

        $branch = $this->normalizeKeytermValue($student["branch"] ?? "");
        $semester = (int) ($student["semester"] ?? 0);
        if ($branch !== "") {
            $keyterms[] = $branch;
        }

        if ($branch !== "" && $semester > 0) {
            $courseStmt = $this->conn->prepare("SELECT course_title, course_code FROM courses WHERE program = ? AND semester = ? ORDER BY course_code ASC");
            if ($courseStmt) {
                $courseStmt->bind_param("si", $branch, $semester);
                $courseStmt->execute();
                $result = $courseStmt->get_result();
                while ($row = $result->fetch_assoc()) {
                    $courseTitle = $this->normalizeKeytermValue($row["course_title"] ?? "");
                    $courseCode = $this->normalizeKeytermValue($row["course_code"] ?? "");
                    $shortName = $this->buildShortCourseName($courseTitle);
                    if ($courseTitle !== "") {
                        $keyterms[] = $courseTitle;
                    }
                    if ($courseCode !== "") {
                        $keyterms[] = $courseCode;
                    }
                    if ($shortName !== "" && strlen($shortName) >= 2) {
                        $keyterms[] = $shortName;
                    }
                }
                $courseStmt->close();
            }
        }

        return $this->dedupeKeyterms($keyterms);
    }

    private function normalizeKeytermValue($value) {
        $value = trim((string) $value);
        $value = preg_replace('/\s+/', ' ', $value);
        return trim((string) $value);
    }

    private function buildShortCourseName($courseTitle) {
        $words = preg_split('/[^a-z0-9]+/i', strtolower((string) $courseTitle));
        $words = array_values(array_filter($words, function ($word) {
            return $word !== "";
        }));

        $shortName = "";
        foreach ($words as $word) {
            $shortName .= strtoupper($word[0]);
        }
        return $shortName;
    }

    private function dedupeKeyterms($keyterms) {
        $cleaned = [];
        foreach ($keyterms as $term) {
            $term = $this->normalizeKeytermValue($term);
            if ($term !== "") {
                $cleaned[strtolower($term)] = $term;
            }
        }
        return array_slice(array_values($cleaned), 0, 40);
    }

    private function normalizeUnicodeText($text) {
        if (class_exists('Normalizer')) {
            $normalized = Normalizer::normalize((string) $text, Normalizer::FORM_C);
            if ($normalized !== false && $normalized !== null) {
                return $normalized;
            }
        }
        return (string) $text;
    }

    private function postProcessKannadaTranscript($transcript, $studentId) {
        $text = $this->normalizeUnicodeText(trim((string) $transcript));
        if ($text === "") {
            return $text;
        }

        $replacements = [
            'nanna attendance issue' => 'my attendance',
            'nanna attendance eshtu' => 'my attendance',
            'nanna attendance yestu' => 'my attendance',
            'nanna result' => 'my result',
            'nana result' => 'my result',
            'opereting system' => 'operating systems',
            'oprating system' => 'operating systems',
            'operating system' => 'operating systems',
            'computer network' => 'computer networks',
            'dbm s' => 'dbms',
            'd b m s' => 'dbms',
            'course cod' => 'course code',
            'profile code' => 'course code',
            'profail code' => 'course code',
            'attendence' => 'attendance',
            'atendance' => 'attendance',
            'artifishal intelligence' => 'artificial intelligence',
            'artifishial intelligence' => 'artificial intelligence',
            'artificial intelligance' => 'artificial intelligence'
        ];

        foreach ($replacements as $from => $to) {
            $text = preg_replace('/\b' . preg_quote($from, '/') . '\b/iu', $to, $text);
        }

        $patterns = [
            '/ಆರ್ಟ\S*\s*ಇಂಟೆ\S*|ಇಂಟೆಲಿಜೆನ್ಸ್/u' => ' artificial intelligence ',
            '/ಆಪರೇಟಿಂಗ್\s*ಸಿಸ್ಟಂ(?:ಸ್)?|ಅಪರೇಟಿಂಗ್\s*ಸಿಸ್ಟಂ(?:ಸ್)?/u' => ' operating systems ',
            '/ಡಿಬಿಎಂಎಸ್|ಡಿ\s*ಬಿ\s*ಎಂ\s*ಎಸ್/u' => ' dbms ',
            '/ಕಂಪ್ಯೂಟರ್\s*ನೆಟ್\s*ವರ್ಕ್ಸ್?|ನೆಟ್\s*ವರ್ಕ್ಸ್?/u' => ' computer networks ',
            '/ಕೋರ್ಸ್\s*ಕೋಡ್|ಸಬ್ಜೆಕ್ಟ್\s*ಕೋಡ್|ವಿಷಯದ\s*ಕೋಡ್|ಕೋಡ್/u' => ' course code ',
            '/\x{0CB9}\x{0CCB}\x{0CAE}\x{0CCD}\s*\x{0CAA}\x{0CC7}\x{0C9C}\x{0CCD}/u' => ' home page ',
            '/\x{0CAA}\x{0CC7}\x{0C9C}\x{0CCD}/u' => ' page ',
            '/\x{0CB9}\x{0CCB}\x{0C97}\x{0CC1}|\x{0CB9}\x{0CCB}\x{0C97}\x{0CBF}|\x{0CA4}\x{0CC6}\x{0CB0}\x{0CC6}|\x{0CA4}\x{0CC6}\x{0CB0}\x{0CC6}\x{0CAF}\x{0CBF}\x{0CB0}\x{0CBF}/u' => ' go ',
            '/\x{0CB0}\x{0CBF}\x{0C9C}\x{0CBF}\x{0CB8}\x{0CCD}\x{0C9F}\x{0CCD}\x{0CB0}\x{0CC7}\x{0CB7}\x{0CA8}\x{0CCD}|\x{0CA8}\x{0CCB}\x{0C82}\x{0CA6}\x{0CA3}\x{0CBF}/u' => ' registration ',
            '/\x{0CB9}\x{0CBE}\x{0CB2}\x{0CCD}\s*\x{0C9F}\x{0CBF}\x{0C95}\x{0CC6}\x{0C9F}\x{0CCD}/u' => ' hall ticket ',
            '/\x{0C85}\x{0C9F}\x{0CC6}\x{0C82}\x{0CA1}\x{0CC6}\x{0CA8}\x{0CCD}\x{0CB8}\x{0CCD}|\x{0CB9}\x{0CBE}\x{0C9C}\x{0CB0}\x{0CBF}/u' => ' attendance ',
            '/\x{0CB0}\x{0CBF}\x{0CB8}\x{0CB2}\x{0CCD}\x{0C9F}\x{0CCD}|\x{0CAB}\x{0CB2}\x{0CBF}\x{0CA4}\x{0CBE}\x{0C82}\x{0CB6}/u' => ' result ',
            '/\x{0CAB}\x{0CC0}\x{0CB8}\x{0CCD}|\x{0CAA}\x{0CC7}\x{0CAE}\x{0CC6}\x{0C82}\x{0C9F}\x{0CCD}/u' => ' fee ',
            '/\x{0CA1}\x{0CCD}\x{0CAF}\x{0CBE}\x{0CB6}\x{0CCD}\s*\x{0CAC}\x{0CCB}\x{0CB0}\x{0CCD}\x{0CA1}\x{0CCD}/u' => ' dashboard ',
            '/\x{0CAA}\x{0CCD}\x{0CB0}\x{0CCA}\x{0CAB}\x{0CC8}\x{0CB2}\x{0CCD}/u' => ' profile ',
            '/\x{0CB8}\x{0CC6}\x{0CAE}\x{0CBF}\x{0CB8}\x{0CCD}\x{0C9F}\x{0CB0}\x{0CCD}/u' => ' semester '
        ];

        $text = preg_replace(array_keys($patterns), array_values($patterns), $text);
        return preg_replace('/\s+/u', ' ', trim((string) $text));
    }

    private function academicSignalScore($text, $studentId) {
        $text = strtolower($this->postProcessKannadaTranscript((string) $text, $studentId));
        $score = 0;
        $strongSignals = [
            "attendance", "result", "marks", "sgpa", "cgpa", "profile", "usn",
            "fee", "fees", "balance", "registration", "hall ticket", "certificate",
            "course code", "subject code", "backlog", "dashboard", "payment",
            "home page", "page", "portal", "faculty", "faculty details",
            "dbms", "operating systems", "computer networks", "artificial intelligence",
            "design and analysis", "java", "python"
        ];

        foreach ($strongSignals as $signal) {
            if (strpos($text, $signal) !== false) {
                $score += 2;
            }
        }

        if (preg_match('/\b(ai|os|cn|ada)\b/u', $text)) {
            $score += 2;
        }

        return $score;
    }

    private function debugLog($message, $context = []) {
        $enabled = getenv("VOICEBOT_DEBUG");
        if ($enabled === false || $enabled === "" || strtolower((string) $enabled) === "false") {
            return;
        }

        $line = "[voicebot] " . $message;
        if (!empty($context)) {
            $encoded = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($encoded !== false) {
                $line .= " " . $encoded;
            }
        }
        error_log($line);
    }
}
