<?php

require_once __DIR__ . "/../config/db.php";

class KnowledgeBaseService {
    private const DEFAULT_LIMIT = 4;

    public static function getRelevantKnowledge($roleKey, $message, $limit = self::DEFAULT_LIMIT) {
        return self::getRelevantKnowledgeFromSql($roleKey, $message, max(1, (int) $limit));
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

