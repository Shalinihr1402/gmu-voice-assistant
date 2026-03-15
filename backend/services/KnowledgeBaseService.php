<?php

require_once __DIR__ . "/../config/db.php";

class KnowledgeBaseService {

    public static function getRelevantKnowledge($roleKey, $message, $limit = 3) {
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
            $items[] = $row;
        }

        $stmt->close();
        return $items;
    }
}
