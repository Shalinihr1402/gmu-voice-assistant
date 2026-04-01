<?php

require_once __DIR__ . "/../config/db.php";

class KnowledgeBaseService {
    private const DEFAULT_LIMIT = 4;

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

    public static function getRelevantKnowledge($roleKey, $message, $limit = self::DEFAULT_LIMIT) {
        $limit = max(1, (int) $limit);

        $ragItems = self::getRelevantKnowledgeFromRag($roleKey, $message, $limit);
        if (!empty($ragItems)) {
            return $ragItems;
        }

        return self::getRelevantKnowledgeFromSql($roleKey, $message, $limit);
    }

    private static function getRelevantKnowledgeFromRag($roleKey, $message, $limit) {
        $ragUrl = rtrim((string) (self::getEnvValue("RAG_SERVICE_URL") ?: "http://127.0.0.1:5000"), "/");
        $payload = json_encode([
            "query" => trim((string) $message),
            "role" => trim((string) $roleKey),
            "top_k" => $limit
        ]);

        if (!$payload || trim((string) $message) === "") {
            return [];
        }

        $ch = curl_init($ragUrl . "/rag/retrieve");
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Content-Type: application/json"
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 6);

        $response = curl_exec($ch);
        $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $statusCode >= 400) {
            return [];
        }

        $data = json_decode($response, true);
        $items = $data["items"] ?? [];

        if (!is_array($items)) {
            return [];
        }

        $normalized = [];
        foreach ($items as $item) {
            $topic = trim((string) ($item["topic"] ?? ""));
            $content = trim((string) ($item["content"] ?? ""));

            if ($topic === "" || $content === "") {
                continue;
            }

            $normalized[] = [
                "topic" => $topic,
                "content" => $content,
                "source" => trim((string) ($item["source"] ?? "rag")),
                "score" => isset($item["score"]) ? (float) $item["score"] : null
            ];
        }

        return $normalized;
    }

    private static function getRelevantKnowledgeFromSql($roleKey, $message, $limit) {
        global $conn;

        $stmt = $conn->prepare("
            SELECT topic, content
            FROM knowledge_base
            WHERE audience_role IN (?, 'all')
            ORDER BY
                CASE
                    WHEN LOWER(topic) LIKE CONCAT('%', LOWER(?), '%') THEN 0
                    WHEN LOWER(content) LIKE CONCAT('%', LOWER(?), '%') THEN 1
                    ELSE 2
                END,
                kb_id ASC
            LIMIT ?
        ");

        if (!$stmt) {
            return [];
        }

        $stmt->bind_param("sssi", $roleKey, $message, $message, $limit);
        $stmt->execute();
        $result = $stmt->get_result();

        $items = [];
        while ($row = $result->fetch_assoc()) {
            $items[] = [
                "topic" => $row["topic"],
                "content" => $row["content"],
                "source" => "sql_fallback",
                "score" => null
            ];
        }

        $stmt->close();
        return $items;
    }
}
