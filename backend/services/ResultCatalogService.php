<?php

class ResultCatalogService
{
    public static function ensureCatalogReady(mysqli $conn): void
    {
        $createTableSql = "
            CREATE TABLE IF NOT EXISTS result_publications (
                publication_id INT AUTO_INCREMENT PRIMARY KEY,
                student_id INT NOT NULL,
                semester INT NOT NULL,
                exam_type VARCHAR(30) NOT NULL,
                academic_year VARCHAR(20) NOT NULL,
                season VARCHAR(10) NOT NULL,
                publication_status VARCHAR(20) NOT NULL DEFAULT 'PUBLISHED',
                published_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_result_publication (student_id, semester, exam_type, academic_year, season),
                CONSTRAINT fk_result_publications_student
                    FOREIGN KEY (student_id) REFERENCES students(student_id)
                    ON DELETE CASCADE
            ) ENGINE=InnoDB
        ";

        $conn->query($createTableSql);
        self::seedMissingPublications($conn);
    }

    public static function getPublishedSelections(mysqli $conn, int $studentId): array
    {
        $stmt = $conn->prepare("
            SELECT semester, exam_type, academic_year, season
            FROM result_publications
            WHERE student_id = ?
              AND publication_status = 'PUBLISHED'
            ORDER BY semester ASC, academic_year ASC, exam_type ASC, season ASC
        ");

        if (!$stmt) {
            return [];
        }

        $stmt->bind_param("i", $studentId);
        $stmt->execute();
        $result = $stmt->get_result();

        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = [
                "semester" => (int) $row["semester"],
                "exam" => strtoupper((string) $row["exam_type"]),
                "year" => (string) $row["academic_year"],
                "season" => strtoupper((string) $row["season"])
            ];
        }

        $stmt->close();
        return $rows;
    }

    public static function findPublishedSelection(
        mysqli $conn,
        int $studentId,
        int $semester,
        string $examType,
        string $academicYear,
        string $season
    ): ?array {
        $normalizedExam = strtoupper(trim($examType));
        $normalizedSeason = strtoupper(trim($season));
        $normalizedYear = trim($academicYear);

        $stmt = $conn->prepare("
            SELECT semester, exam_type, academic_year, season
            FROM result_publications
            WHERE student_id = ?
              AND semester = ?
              AND exam_type = ?
              AND academic_year = ?
              AND season = ?
              AND publication_status = 'PUBLISHED'
            LIMIT 1
        ");

        if (!$stmt) {
            return null;
        }

        $stmt->bind_param("iisss", $studentId, $semester, $normalizedExam, $normalizedYear, $normalizedSeason);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
            return null;
        }

        return [
            "semester" => (int) $row["semester"],
            "exam" => strtoupper((string) $row["exam_type"]),
            "year" => (string) $row["academic_year"],
            "season" => strtoupper((string) $row["season"])
        ];
    }

    public static function describeSelectionsForSemester(array $selections, int $semester): string
    {
        $matches = array_values(array_filter($selections, static function ($selection) use ($semester) {
            return (int) ($selection["semester"] ?? 0) === $semester;
        }));

        if (empty($matches)) {
            return "";
        }

        $labels = array_map(static function ($selection) {
            return sprintf(
                "%s %s %s",
                $selection["exam"] ?? "",
                $selection["year"] ?? "",
                $selection["season"] ?? ""
            );
        }, $matches);

        return implode(", ", $labels);
    }

    private static function seedMissingPublications(mysqli $conn): void
    {
        $semestersResult = $conn->query("
            SELECT student_id, semester, SUM(CASE WHEN grade_point <= 0 THEN 1 ELSE 0 END) AS backlog_count
            FROM results
            GROUP BY student_id, semester
            ORDER BY student_id ASC, semester ASC
        ");

        if (!$semestersResult) {
            return;
        }

        while ($row = $semestersResult->fetch_assoc()) {
            $studentId = (int) $row["student_id"];
            $semester = (int) $row["semester"];
            $backlogCount = (int) $row["backlog_count"];

            $selection = self::inferDefaultSelection($conn, $studentId, $semester);
            self::insertPublicationIfMissing(
                $conn,
                $studentId,
                $semester,
                "SEE",
                $selection["year"],
                $selection["season"]
            );

            if ($backlogCount > 0) {
                self::insertPublicationIfMissing(
                    $conn,
                    $studentId,
                    $semester,
                    "RESIT",
                    $selection["year"],
                    $selection["season"]
                );
            }
        }
    }

    private static function inferDefaultSelection(mysqli $conn, int $studentId, int $semester): array
    {
        $stmt = $conn->prepare("
            SELECT academic_year
            FROM hall_tickets
            WHERE student_id = ?
              AND semester = ?
            ORDER BY generated_at DESC, hall_ticket_id DESC
            LIMIT 1
        ");

        if ($stmt) {
            $stmt->bind_param("ii", $studentId, $semester);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($row && !empty($row["academic_year"])) {
                return [
                    "year" => (string) $row["academic_year"],
                    "season" => self::inferSeasonFromSemester($semester)
                ];
            }
        }

        $baseYear = 2024 + max(0, (int) floor(($semester - 1) / 2));
        return [
            "year" => sprintf("%d-%02d", $baseYear, ($baseYear + 1) % 100),
            "season" => self::inferSeasonFromSemester($semester)
        ];
    }

    private static function inferSeasonFromSemester(int $semester): string
    {
        return $semester % 2 === 0 ? "EVEN" : "ODD";
    }

    private static function insertPublicationIfMissing(
        mysqli $conn,
        int $studentId,
        int $semester,
        string $examType,
        string $academicYear,
        string $season
    ): void {
        $stmt = $conn->prepare("
            INSERT IGNORE INTO result_publications (
                student_id,
                semester,
                exam_type,
                academic_year,
                season,
                publication_status
            ) VALUES (?, ?, ?, ?, ?, 'PUBLISHED')
        ");

        if (!$stmt) {
            return;
        }

        $normalizedExam = strtoupper(trim($examType));
        $normalizedSeason = strtoupper(trim($season));
        $normalizedYear = trim($academicYear);

        $stmt->bind_param("iisss", $studentId, $semester, $normalizedExam, $normalizedYear, $normalizedSeason);
        $stmt->execute();
        $stmt->close();
    }
}
