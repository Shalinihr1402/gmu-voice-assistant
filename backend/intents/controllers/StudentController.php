require_once "../config/database.php";

class StudentController {

    public static function getUSN($student_id) {
        global $conn;
        $sql = "SELECT usn FROM students WHERE student_id=?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $student_id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();

        return "Your USN is " . $result['usn'];
    }

    public static function getSGPA($student_id) {
        global $conn;
        $sql = "SELECT sgpa FROM results WHERE student_id=? ORDER BY semester DESC LIMIT 1";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $student_id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();

        return "Your latest SGPA is " . $result['sgpa'];
    }
}
