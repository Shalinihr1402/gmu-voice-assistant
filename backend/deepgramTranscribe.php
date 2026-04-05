<?php
header("Access-Control-Allow-Origin: http://localhost:3000");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

session_start();

if (!isset($_SESSION['student_id']) && !isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(["error" => "Unauthorized access. Please login."]);
    exit();
}

require_once __DIR__ . "/config/db.php";

$apiKey = getenv("DEEPGRAM_API_KEY");

if (!$apiKey && isset($_SERVER["DEEPGRAM_API_KEY"])) {
    $apiKey = $_SERVER["DEEPGRAM_API_KEY"];
}

if (!$apiKey && isset($_ENV["DEEPGRAM_API_KEY"])) {
    $apiKey = $_ENV["DEEPGRAM_API_KEY"];
}

if (!$apiKey) {
    http_response_code(500);
    echo json_encode([
        "error" => "Deepgram API key is missing. Set DEEPGRAM_API_KEY in your server environment."
    ]);
    exit();
}

if (!isset($_FILES["audio"]) || $_FILES["audio"]["error"] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(["error" => "Audio file is missing."]);
    exit();
}

$audioPath = $_FILES["audio"]["tmp_name"];
$mimeType = mime_content_type($audioPath);
$audioData = file_get_contents($audioPath);

if ($audioData === false || $audioData === "") {
    http_response_code(400);
    echo json_encode(["error" => "Uploaded audio is empty."]);
    exit();
}

function normalizeKeytermValue($value) {
    $value = trim((string) $value);
    $value = preg_replace('/\s+/', ' ', $value);
    return trim((string) $value);
}

function buildShortCourseName($courseTitle) {
    $words = preg_split('/[^a-z0-9]+/i', strtolower((string) $courseTitle));
    $words = array_values(array_filter($words, function ($word) {
        return $word !== "";
    }));

    if (empty($words)) {
        return "";
    }

    $shortName = "";
    foreach ($words as $word) {
        $shortName .= strtoupper($word[0]);
    }

    return $shortName;
}

function getTranscriptionKeyterms($studentId) {
    global $conn;

    $keyterms = [
        "GM University",
        "GMU",
        "course code",
        "subject code",
        "registration",
        "attendance",
        "hall ticket",
        "semester",
        "Computer Science"
    ];

    $studentStmt = $conn->prepare("
        SELECT branch, semester
        FROM students
        WHERE student_id = ?
    ");

    if ($studentStmt) {
        $studentStmt->bind_param("i", $studentId);
        $studentStmt->execute();
        $student = $studentStmt->get_result()->fetch_assoc();
        $studentStmt->close();

        if ($student) {
            $branch = normalizeKeytermValue($student["branch"] ?? "");
            $semester = (int) ($student["semester"] ?? 0);

            if ($branch !== "") {
                $keyterms[] = $branch;
            }

            if ($branch !== "" && $semester > 0) {
                $courseStmt = $conn->prepare("
                    SELECT course_title, course_code
                    FROM courses
                    WHERE program = ? AND semester = ?
                    ORDER BY course_code ASC
                ");

                if ($courseStmt) {
                    $courseStmt->bind_param("si", $branch, $semester);
                    $courseStmt->execute();
                    $result = $courseStmt->get_result();

                    while ($row = $result->fetch_assoc()) {
                        $courseTitle = normalizeKeytermValue($row["course_title"] ?? "");
                        $courseCode = normalizeKeytermValue($row["course_code"] ?? "");
                        $shortName = buildShortCourseName($courseTitle);

                        if ($courseTitle !== "") {
                            $keyterms[] = $courseTitle;
                        }
                        if ($courseCode !== "") {
                            $keyterms[] = $courseCode;
                        }
                        if ($shortName !== "" && strlen($shortName) >= 3) {
                            $keyterms[] = $shortName;
                        }
                    }

                    $courseStmt->close();
                }
            }
        }
    }

    $cleaned = [];
    foreach ($keyterms as $term) {
        $term = normalizeKeytermValue($term);
        if ($term === "") {
            continue;
        }

        $cleaned[strtolower($term)] = $term;
    }

    return array_slice(array_values($cleaned), 0, 40);
}

// Treat extremely short clips as silence instead of surfacing a noisy 400.
if (strlen($audioData) < 2048) {
    echo json_encode([
        "transcript" => ""
    ]);
    exit();
}

$queryParams = [
    "model=nova-3",
    "language=en-US",
    "smart_format=true",
    "punctuate=true"
];

if (isset($_SESSION['student_id'])) {
    foreach (getTranscriptionKeyterms((int) $_SESSION['student_id']) as $keyterm) {
        $queryParams[] = "keyterm=" . rawurlencode($keyterm);
    }
}

$listenUrl = "https://api.deepgram.com/v1/listen?" . implode("&", $queryParams);

$ch = curl_init($listenUrl);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $audioData);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Authorization: Token " . $apiKey,
    "Content-Type: " . ($mimeType ?: "audio/webm")
]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

$response = curl_exec($ch);
$statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($response === false) {
    http_response_code(502);
    echo json_encode([
        "error" => $curlError ?: "Unable to reach Deepgram."
    ]);
    exit();
}

$data = json_decode($response, true);

if ($statusCode >= 400) {
    $message = $data["err_msg"] ?? $data["message"] ?? "Deepgram transcription failed.";

    if ($statusCode === 400) {
        echo json_encode([
            "transcript" => "",
            "warning" => $message
        ]);
        exit();
    }

    http_response_code($statusCode);
    echo json_encode([
        "error" => $message
    ]);
    exit();
}

$transcript = $data["results"]["channels"][0]["alternatives"][0]["transcript"] ?? "";

echo json_encode([
    "transcript" => $transcript
]);
