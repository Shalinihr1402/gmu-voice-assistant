<?php

require_once __DIR__ . "/cors.php";

session_start();

if (!isset($_SESSION['user_id']) && !isset($_SESSION['student_id'])) {
    http_response_code(401);
    header("Content-Type: application/json");
    echo json_encode(["error" => "Unauthorized access. Please login."]);
    exit();
}

require_once __DIR__ . "/config/env.php";

function getEnvValue($key) {
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

function normalizeKannadaNumberWords($spoken) {
    $spoken = str_replace(["-", ","], " ", (string) $spoken);
    $spoken = preg_replace('/\s+/u', ' ', (string) $spoken);
    return trim((string) $spoken);
}

function fallbackIntegerToKannadaWords($number) {
    $number = (int) $number;

    $units = [
        0 => "ಶೂನ್ಯ",
        1 => "ಒಂದು",
        2 => "ಎರಡು",
        3 => "ಮೂರು",
        4 => "ನಾಲ್ಕು",
        5 => "ಐದು",
        6 => "ಆರು",
        7 => "ಏಳು",
        8 => "ಎಂಟು",
        9 => "ಒಂಬತ್ತು",
        10 => "ಹತ್ತು",
        11 => "ಹನ್ನೊಂದು",
        12 => "ಹನ್ನೆರಡು",
        13 => "ಹದಿಮೂರು",
        14 => "ಹದಿನಾಲ್ಕು",
        15 => "ಹದಿನೈದು",
        16 => "ಹದಿನಾರು",
        17 => "ಹದಿನೇಳು",
        18 => "ಹದಿನೆಂಟು",
        19 => "ಹತ್ತೊಂಬತ್ತು"
    ];

    $tens = [
        2 => "ಇಪ್ಪತ್ತು",
        3 => "ಮೂವತ್ತು",
        4 => "ನಲವತ್ತು",
        5 => "ಐವತ್ತು",
        6 => "ಅರವತ್ತು",
        7 => "ಎಪ್ಪತ್ತು",
        8 => "ಎಂಬತ್ತು",
        9 => "ತೊಂಬತ್ತು"
    ];

    if ($number < 20) {
        return $units[$number];
    }

    if ($number < 100) {
        $tenPart = intdiv($number, 10);
        $unitPart = $number % 10;
        return $unitPart > 0 ? $tens[$tenPart] . " " . $units[$unitPart] : $tens[$tenPart];
    }

    if ($number < 1000) {
        $hundreds = intdiv($number, 100);
        $remainder = $number % 100;
        $prefix = $hundreds === 1 ? "ನೂರು" : fallbackIntegerToKannadaWords($hundreds) . " ನೂರು";
        return $remainder > 0 ? $prefix . " " . fallbackIntegerToKannadaWords($remainder) : $prefix;
    }

    if ($number < 100000) {
        $thousands = intdiv($number, 1000);
        $remainder = $number % 1000;
        $prefix = fallbackIntegerToKannadaWords($thousands) . " ಸಾವಿರ";
        return $remainder > 0 ? $prefix . " " . fallbackIntegerToKannadaWords($remainder) : $prefix;
    }

    if ($number < 10000000) {
        $lakhs = intdiv($number, 100000);
        $remainder = $number % 100000;
        $prefix = fallbackIntegerToKannadaWords($lakhs) . " ಲಕ್ಷ";
        return $remainder > 0 ? $prefix . " " . fallbackIntegerToKannadaWords($remainder) : $prefix;
    }

    if ($number < 1000000000) {
        $crores = intdiv($number, 10000000);
        $remainder = $number % 10000000;
        $prefix = fallbackIntegerToKannadaWords($crores) . " ಕೋಟಿ";
        return $remainder > 0 ? $prefix . " " . fallbackIntegerToKannadaWords($remainder) : $prefix;
    }

    return (string) $number;
}

function numberToKannadaWords($numberText) {
    $normalized = str_replace([",", " "], "", trim((string) $numberText));
    if ($normalized === "" || !is_numeric($normalized)) {
        return trim((string) $numberText);
    }

    if (class_exists('NumberFormatter')) {
        $formatter = new NumberFormatter('kn_IN', NumberFormatter::SPELLOUT);
        $spoken = $formatter->format((float) $normalized);
        if ($spoken !== false && $spoken !== null && $spoken !== "") {
            return normalizeKannadaNumberWords($spoken);
        }
    }

    if (strpos($normalized, ".") !== false) {
        $parts = explode(".", $normalized, 2);
        $integerPart = (int) ($parts[0] === "" ? 0 : $parts[0]);
        $fractionPart = preg_replace('/[^0-9]/', '', (string) ($parts[1] ?? ""));
        $spoken = fallbackIntegerToKannadaWords($integerPart);

        if ($fractionPart !== "") {
            $digitWords = array_map(function ($digit) {
                return fallbackIntegerToKannadaWords((int) $digit);
            }, str_split($fractionPart));

            $spoken .= " ದಶಮಾಂಶ " . implode(" ", $digitWords);
        }

        return normalizeKannadaNumberWords($spoken);
    }

    return normalizeKannadaNumberWords(fallbackIntegerToKannadaWords((int) $normalized));
}

function moneyToKannadaSpeech($amountText) {
    $normalized = str_replace([",", " "], "", trim((string) $amountText));
    if ($normalized === "" || !is_numeric($normalized)) {
        return trim((string) $amountText);
    }

    $amount = round((float) $normalized, 2);
    $rupees = (int) floor($amount);
    $paise = (int) round(($amount - $rupees) * 100);

    $parts = [];
    if ($rupees > 0) {
        $parts[] = numberToKannadaWords((string) $rupees) . " ರೂಪಾಯಿ";
    }

    if ($paise > 0) {
        $parts[] = numberToKannadaWords((string) $paise) . " ಪೈಸೆ";
    }

    if (empty($parts)) {
        return "ಶೂನ್ಯ ರೂಪಾಯಿ";
    }

    return implode(" ", $parts);
}

function prepareKannadaSpeechText($text) {
    $prepared = trim((string) $text);

    $spokenTerms = [
        '/\bGMU\b/u' => 'ಜಿ ಎಂ ಯು',
        '/\bUSN\b/u' => 'ಯು ಎಸ್ ಎನ್',
        '/\bSGPA\b/u' => 'ಎಸ್ ಜಿ ಪಿ ಎ',
        '/\bCGPA\b/u' => 'ಸಿ ಜಿ ಪಿ ಎ',
        '/\bDBMS\b/u' => 'ಡಿ ಬಿ ಎಂ ಎಸ್',
        '/\bAI\b/u' => 'ಎ ಐ',
        '/\bCN\b/u' => 'ಸಿ ಎನ್',
        '/\bOS\b/u' => 'ಓ ಎಸ್',
        '/\bHOD\b/u' => 'ಎಚ್ ಒ ಡಿ',
        '/\bERP\b/u' => 'ಇ ಆರ್ ಪಿ',
        '/\bSEE\b/u' => 'ಎಸ್ ಇ ಇ',
        '/\bRESIT\b/u' => 'ರೀ ಸಿಟ್',
        '/\bRE-REGISTRATION\b/u' => 'ರೀ ರಿಜಿಸ್ಟ್ರೇಶನ್',
        '/\bODD\b/u' => 'ಆಡ್',
        '/\bEVEN\b/u' => 'ಈವನ್'
    ];

    foreach ($spokenTerms as $pattern => $replacement) {
        $prepared = preg_replace($pattern, $replacement, $prepared);
    }

    $prepared = preg_replace_callback(
        '/\b([A-Z]{2,}[0-9][A-Z0-9-]*)\b/u',
        function ($matches) {
            $token = str_replace('-', ' ', $matches[1]);
            $chars = preg_split('//u', $token, -1, PREG_SPLIT_NO_EMPTY);
            return implode(' ', $chars);
        },
        $prepared
    );

    $prepared = preg_replace_callback(
        '/(?:₹|Rs\.?|rs\.?|ರೂ\.?|ರೂಪಾಯಿ)\s*([0-9][0-9,]*(?:\.[0-9]+)?)/u',
        function ($matches) {
            return moneyToKannadaSpeech($matches[1]);
        },
        $prepared
    );

    $prepared = preg_replace_callback(
        '/(?<![A-Za-z])\b([0-9][0-9,]*(?:\.[0-9]+)?)\b(?![A-Za-z])/u',
        function ($matches) {
            return numberToKannadaWords($matches[1]);
        },
        $prepared
    );

    $prepared = preg_replace('/\s+/u', ' ', (string) $prepared);
    return trim((string) $prepared);
}

function optimizeKannadaVoiceReply($text) {
    $text = trim((string) $text);
    if ($text === '') {
        return $text;
    }

    $compact = preg_replace('/\s+/u', ' ', $text);
    $compact = trim((string) $compact);

    if (preg_match('/GMU VoiceBot/u', $compact) || preg_match('/ವೃತ್ತಾಕಾರದ ಬಟನ್/u', $compact)) {
        return $compact;
    }

    $moneyMatches = [];
    preg_match_all('/(?:₹|Rs\.?|rs\.?|ರೂ\.?|ರೂಪಾಯಿ)\s*([0-9][0-9,]*(?:\.[0-9]+)?)/u', $compact, $moneyMatches);
    $amounts = $moneyMatches[1] ?? [];

    if (count($amounts) >= 3 && preg_match('/fee|fees|ಫೀಸ್|ಬಾಕಿ|balance|pending/iu', $compact)) {
        $dueAmount = end($amounts);
        if ($dueAmount !== false) {
            return "ನಿಮ್ಮ ಫೀಸ್ ವಿವರಗಳು ಲಭ್ಯವಿವೆ. ಈಗಿನ ಬಾಕಿ ಫೀಸ್ ರೂ. " . $dueAmount . ".";
        }
    }

    if (mb_strlen($compact, 'UTF-8') <= 220) {
        return $compact;
    }

    $parts = preg_split('/(?<=[.!?])\s+|,\s+/u', $compact, -1, PREG_SPLIT_NO_EMPTY);
    if (!$parts) {
        return mb_substr($compact, 0, 180, 'UTF-8');
    }

    $spoken = '';
    foreach ($parts as $part) {
        $candidate = trim($part);
        if ($candidate === '') {
            continue;
        }

        $next = $spoken === '' ? $candidate : $spoken . '. ' . $candidate;
        if (mb_strlen($next, 'UTF-8') > 220) {
            break;
        }

        $spoken = $next;
        if (substr_count($spoken, '.') >= 3) {
            break;
        }
    }

    if ($spoken === '') {
        $spoken = mb_substr($compact, 0, 220, 'UTF-8');
    }

    return rtrim($spoken, " .,") . ".";
}

$apiKey = getEnvValue("ELEVENLABS_API_KEY");
if (!$apiKey) {
    http_response_code(500);
    header("Content-Type: application/json");
    echo json_encode([
        "error" => "ElevenLabs API key is missing. Set ELEVENLABS_API_KEY in your server environment."
    ]);
    exit();
}

$isGetRequest = $_SERVER['REQUEST_METHOD'] === 'GET';
$input = [];

if ($isGetRequest) {
    $input = $_GET;
} else {
    $raw = file_get_contents("php://input");
    $input = json_decode($raw, true) ?: [];
}

$text = trim((string) ($input["text"] ?? ""));
$language = strtolower(trim((string) ($input["language"] ?? "kn")));

if ($text === "") {
    http_response_code(400);
    header("Content-Type: application/json");
    echo json_encode(["error" => "Text is required for speech synthesis."]);
    exit();
}

if (!in_array($language, ["kn", "kannada", "kn-in"], true)) {
    http_response_code(400);
    header("Content-Type: application/json");
    echo json_encode([
        "error" => "This ElevenLabs endpoint is reserved for Kannada TTS requests only."
    ]);
    exit();
}

$text = optimizeKannadaVoiceReply($text);
$text = prepareKannadaSpeechText($text);

$voiceId = getEnvValue("ELEVENLABS_KANNADA_VOICE_ID") ?: getEnvValue("ELEVENLABS_VOICE_ID");
if (!$voiceId) {
    http_response_code(500);
    header("Content-Type: application/json");
    echo json_encode([
        "error" => "ElevenLabs voice id is missing. Set ELEVENLABS_KANNADA_VOICE_ID in your server environment."
    ]);
    exit();
}

$modelId = getEnvValue("ELEVENLABS_TTS_MODEL_KANNADA") ?: getEnvValue("ELEVENLABS_TTS_MODEL") ?: "eleven_v3";
$outputFormat = getEnvValue("ELEVENLABS_OUTPUT_FORMAT") ?: "mp3_22050_32";
$optimizeLatency = trim((string) (getEnvValue("ELEVENLABS_OPTIMIZE_STREAMING_LATENCY") ?: "3"));

$payload = json_encode([
    "text" => $text,
    "model_id" => $modelId,
    "language_code" => "kn"
]);

$streamPath = $isGetRequest ? "/stream" : "";
$queryParams = [
    "output_format" => $outputFormat
];

if (stripos($modelId, "eleven_v3") === false && $optimizeLatency !== "") {
    $queryParams["optimize_streaming_latency"] = $optimizeLatency;
}

$url = "https://api.elevenlabs.io/v1/text-to-speech/" . rawurlencode($voiceId) . $streamPath
    . "?" . http_build_query($queryParams);

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "xi-api-key: " . $apiKey,
    "Content-Type: application/json",
    "Accept: audio/mpeg"
]);

if ($isGetRequest) {
    $statusCode = 200;
    $errorBuffer = "";
    $bodyStarted = false;

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header("Content-Type: audio/mpeg");
    header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
    header("Pragma: no-cache");
    header("X-Accel-Buffering: no");

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
    curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($curl, $headerLine) use (&$statusCode) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', trim($headerLine), $matches)) {
            $statusCode = (int) $matches[1];
        }
        return strlen($headerLine);
    });
    curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($curl, $chunk) use (&$statusCode, &$errorBuffer, &$bodyStarted) {
        if ($statusCode >= 400) {
            $errorBuffer .= $chunk;
            return strlen($chunk);
        }

        $bodyStarted = true;
        echo $chunk;
        flush();
        return strlen($chunk);
    });

    $ok = curl_exec($ch);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($ok === false && !$bodyStarted) {
        http_response_code(502);
        header("Content-Type: application/json");
        echo json_encode([
            "error" => $curlError ?: "Unable to reach ElevenLabs TTS."
        ]);
        exit();
    }

    if ($statusCode >= 400 && !$bodyStarted) {
        http_response_code($statusCode);
        header("Content-Type: application/json");
        $data = json_decode($errorBuffer, true);
        echo json_encode([
            "error" => $data["detail"]["message"] ?? $data["message"] ?? "ElevenLabs TTS failed."
        ]);
        exit();
    }

    exit();
}

curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

$response = curl_exec($ch);
$statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($response === false) {
    http_response_code(502);
    header("Content-Type: application/json");
    echo json_encode([
        "error" => $curlError ?: "Unable to reach ElevenLabs TTS."
    ]);
    exit();
}

if ($statusCode >= 400) {
    http_response_code($statusCode);
    header("Content-Type: application/json");
    $data = json_decode($response, true);
    echo json_encode([
        "error" => $data["detail"]["message"] ?? $data["message"] ?? "ElevenLabs TTS failed."
    ]);
    exit();
}

header("Content-Type: audio/mpeg");
header("Content-Length: " . strlen($response));
echo $response;
