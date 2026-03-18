DROP DATABASE IF EXISTS gmu_voice_assistant;
CREATE DATABASE IF NOT EXISTS gmu_voice_assistant;
USE gmu_voice_assistant;

CREATE TABLE roles (
    role_id INT AUTO_INCREMENT PRIMARY KEY,
    role_key VARCHAR(50) NOT NULL UNIQUE,
    role_name VARCHAR(100) NOT NULL
) ENGINE=InnoDB;

CREATE TABLE departments (
    department_id INT AUTO_INCREMENT PRIMARY KEY,
    department_code VARCHAR(20) NOT NULL UNIQUE,
    department_name VARCHAR(120) NOT NULL
) ENGINE=InnoDB;

CREATE TABLE students (
    student_id INT AUTO_INCREMENT PRIMARY KEY,
    aadhaar_number VARCHAR(20) NOT NULL UNIQUE,
    full_name VARCHAR(120) NOT NULL,
    usn VARCHAR(30) NOT NULL UNIQUE,
    branch VARCHAR(100) NOT NULL,
    semester INT NOT NULL,
    email VARCHAR(120) NOT NULL UNIQUE,
    mobile_no VARCHAR(20) DEFAULT NULL,
    quota VARCHAR(50) NOT NULL,
    photo VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE staff_members (
    staff_id INT AUTO_INCREMENT PRIMARY KEY,
    employee_code VARCHAR(30) NOT NULL UNIQUE,
    full_name VARCHAR(120) NOT NULL,
    email VARCHAR(120) NOT NULL UNIQUE,
    mobile_no VARCHAR(20) DEFAULT NULL,
    department_id INT DEFAULT NULL,
    designation VARCHAR(100) NOT NULL,
    photo VARCHAR(255) DEFAULT NULL,
    FOREIGN KEY (department_id) REFERENCES departments(department_id)
        ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE users (
    user_id INT AUTO_INCREMENT PRIMARY KEY,
    login_id VARCHAR(120) NOT NULL UNIQUE,
    password_text VARCHAR(120) NOT NULL,
    role_id INT NOT NULL,
    student_id INT DEFAULT NULL,
    staff_id INT DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    last_login_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (role_id) REFERENCES roles(role_id)
        ON DELETE RESTRICT,
    FOREIGN KEY (student_id) REFERENCES students(student_id)
        ON DELETE CASCADE,
    FOREIGN KEY (staff_id) REFERENCES staff_members(staff_id)
        ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE courses (
    course_id INT AUTO_INCREMENT PRIMARY KEY,
    program VARCHAR(100) NOT NULL,
    semester INT NOT NULL,
    course_code VARCHAR(30) NOT NULL UNIQUE,
    course_title VARCHAR(150) NOT NULL,
    course_type VARCHAR(50) NOT NULL,
    credits DECIMAL(4,1) NOT NULL DEFAULT 4.0
) ENGINE=InnoDB;

CREATE TABLE fee_structure (
    fee_id INT AUTO_INCREMENT PRIMARY KEY,
    quota VARCHAR(50) NOT NULL,
    fee_type VARCHAR(100) NOT NULL,
    total_fee DECIMAL(10,2) NOT NULL DEFAULT 0.00
) ENGINE=InnoDB;

CREATE TABLE student_payments (
    payment_id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    fee_id INT NOT NULL,
    amount_paid DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    paid_on DATE DEFAULT NULL,
    FOREIGN KEY (student_id) REFERENCES students(student_id)
        ON DELETE CASCADE,
    FOREIGN KEY (fee_id) REFERENCES fee_structure(fee_id)
        ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE results (
    result_id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    semester INT NOT NULL,
    course_id INT NOT NULL,
    credits DECIMAL(4,1) NOT NULL DEFAULT 4.0,
    grade_point DECIMAL(4,2) NOT NULL,
    FOREIGN KEY (student_id) REFERENCES students(student_id)
        ON DELETE CASCADE,
    FOREIGN KEY (course_id) REFERENCES courses(course_id)
        ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE attendance (
    attendance_id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    course_id INT NOT NULL,
    total_classes INT NOT NULL DEFAULT 0,
    attended_classes INT NOT NULL DEFAULT 0,
    percentage DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    FOREIGN KEY (student_id) REFERENCES students(student_id)
        ON DELETE CASCADE,
    FOREIGN KEY (course_id) REFERENCES courses(course_id)
        ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE hall_tickets (
    hall_ticket_id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    semester INT NOT NULL,
    academic_year VARCHAR(20) NOT NULL,
    exam_type VARCHAR(30) NOT NULL,
    status VARCHAR(30) NOT NULL,
    status_message VARCHAR(255) DEFAULT NULL,
    generated_at DATETIME DEFAULT NULL,
    FOREIGN KEY (student_id) REFERENCES students(student_id)
        ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE knowledge_base (
    kb_id INT AUTO_INCREMENT PRIMARY KEY,
    audience_role VARCHAR(50) NOT NULL,
    topic VARCHAR(150) NOT NULL,
    content TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE query_logs (
    query_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    user_query TEXT NOT NULL,
    response_text TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id)
        ON DELETE CASCADE
) ENGINE=InnoDB;

INSERT INTO roles (role_id, role_key, role_name) VALUES
    (1, 'student', 'Student'),
    (2, 'teacher', 'Teacher'),
    (3, 'hod', 'Head of Department'),
    (4, 'director', 'Director'),
    (5, 'dean', 'Dean'),
    (6, 'registrar', 'Registrar'),
    (7, 'management', 'Management');

INSERT INTO departments (department_id, department_code, department_name) VALUES
    (1, 'CSE', 'Computer Science'),
    (2, 'ADMIN', 'Administration'),
    (3, 'ACADEMICS', 'Academics'),
    (4, 'MGMT', 'Management');

INSERT INTO students (
    student_id,
    aadhaar_number,
    full_name,
    usn,
    branch,
    semester,
    email,
    mobile_no,
    quota,
    photo
) VALUES (
    1,
    '123412341234',
    'Aarav Kulkarni',
    'GMU22CSE001',
    'Computer Science',
    5,
    'aarav.kulkarni@gmu.edu',
    '9876543210',
    'GENERAL',
    NULL
);

INSERT INTO staff_members (
    staff_id,
    employee_code,
    full_name,
    email,
    mobile_no,
    department_id,
    designation,
    photo
) VALUES
    (1, 'GMU-T001', 'Dr. Meera Nair', 'teacher@gmu.ac.in', '9000000001', 1, 'Assistant Professor', NULL),
    (2, 'GMU-H001', 'Dr. Ravi Shankar', 'hod@gmu.ac.in', '9000000002', 1, 'Head of Department', NULL),
    (3, 'GMU-D001', 'Dr. Priya Menon', 'director@gmu.ac.in', '9000000003', 2, 'Director', NULL),
    (4, 'GMU-DE001', 'Dr. Kiran Rao', 'dean@gmu.ac.in', '9000000004', 3, 'Dean', NULL),
    (5, 'GMU-R001', 'Mr. Suresh Kumar', 'registrar@gmu.ac.in', '9000000005', 2, 'Registrar', NULL),
    (6, 'GMU-M001', 'Ms. Ananya Shah', 'management@gmu.ac.in', '9000000006', 4, 'Management', NULL);

INSERT INTO users (
    user_id,
    login_id,
    password_text,
    role_id,
    student_id,
    staff_id,
    is_active
) VALUES
    (1, '123412341234', '123456', 1, 1, NULL, 1),
    (2, 'teacher@gmu.ac.in', '123456', 2, NULL, 1, 1),
    (3, 'hod@gmu.ac.in', '123456', 3, NULL, 2, 1),
    (4, 'director@gmu.ac.in', '123456', 4, NULL, 3, 1),
    (5, 'dean@gmu.ac.in', '123456', 5, NULL, 4, 1),
    (6, 'registrar@gmu.ac.in', '123456', 6, NULL, 5, 1),
    (7, 'management@gmu.ac.in', '123456', 7, NULL, 6, 1);

INSERT INTO courses (
    course_id,
    program,
    semester,
    course_code,
    course_title,
    course_type,
    credits
) VALUES
    (1, 'Computer Science', 1, 'CS101', 'Programming Fundamentals', 'Core', 4.0),
    (2, 'Computer Science', 1, 'CS102', 'Mathematics for Computing', 'Core', 4.0),
    (3, 'Computer Science', 1, 'CS103', 'Digital Logic', 'Core', 3.0),
    (4, 'Computer Science', 2, 'CS201', 'Data Structures', 'Core', 4.0),
    (5, 'Computer Science', 2, 'CS202', 'Discrete Mathematics', 'Core', 4.0),
    (6, 'Computer Science', 2, 'CS203', 'Computer Organization', 'Core', 3.0),
    (7, 'Computer Science', 3, 'CS301', 'Object Oriented Programming', 'Core', 4.0),
    (8, 'Computer Science', 3, 'CS302', 'Design and Analysis of Algorithms', 'Core', 4.0),
    (9, 'Computer Science', 3, 'CS303', 'Probability and Statistics', 'Core', 3.0),
    (10, 'Computer Science', 4, 'CS401', 'Java Programming', 'Core', 4.0),
    (11, 'Computer Science', 4, 'CS402', 'Software Engineering', 'Core', 4.0),
    (12, 'Computer Science', 4, 'CS403', 'Microprocessors', 'Core', 3.0),
    (13, 'Computer Science', 5, 'CS501', 'Database Management Systems', 'Core', 4.0),
    (14, 'Computer Science', 5, 'CS502', 'Operating Systems', 'Core', 4.0),
    (15, 'Computer Science', 5, 'CS503', 'Computer Networks', 'Core', 3.0),
    (16, 'Computer Science', 5, 'CS5L1', 'DBMS Laboratory', 'Lab', 1.5),
    (17, 'Computer Science', 5, 'CS5E1', 'Artificial Intelligence', 'Elective', 3.0);

INSERT INTO fee_structure (
    fee_id,
    quota,
    fee_type,
    total_fee
) VALUES
    (1, 'GENERAL', 'Program Fee', 85000.00),
    (2, 'GENERAL', 'Skill Development Fee', 12000.00),
    (3, 'GENERAL', 'Examination Fee', 3500.00);

INSERT INTO student_payments (
    payment_id,
    student_id,
    fee_id,
    amount_paid,
    paid_on
) VALUES
    (1, 1, 1, 50000.00, '2026-01-10'),
    (2, 1, 2, 12000.00, '2026-01-10'),
    (3, 1, 3, 1000.00, '2026-01-15');

INSERT INTO attendance (
    attendance_id,
    student_id,
    course_id,
    total_classes,
    attended_classes,
    percentage
) VALUES
    (1, 1, 1, 40, 34, 85.00),
    (2, 1, 2, 42, 33, 78.57),
    (3, 1, 3, 38, 27, 71.05),
    (4, 1, 4, 20, 18, 90.00),
    (5, 1, 5, 30, 26, 86.67);

INSERT INTO hall_tickets (
    hall_ticket_id,
    student_id,
    semester,
    academic_year,
    exam_type,
    status,
    status_message,
    generated_at
) VALUES
    (1, 1, 2, '2024-25', 'SEE', 'NOT_APPROVED', 'Eligibility list not approved. Please contact your HOD.', NULL),
    (2, 1, 5, '2025-26', 'CIE', 'GENERATED', 'Your CIE hall ticket is ready for download.', '2026-03-01 10:00:00');

INSERT INTO results (
    result_id,
    student_id,
    semester,
    course_id,
    credits,
    grade_point
) VALUES
    (1, 1, 1, 1, 4.0, 8.0),
    (2, 1, 1, 2, 4.0, 7.0),
    (3, 1, 1, 3, 3.0, 8.0),
    (4, 1, 2, 4, 4.0, 7.0),
    (5, 1, 2, 5, 4.0, 6.0),
    (6, 1, 2, 6, 3.0, 0.0),
    (7, 1, 3, 7, 4.0, 8.0),
    (8, 1, 3, 8, 4.0, 5.0),
    (9, 1, 3, 9, 3.0, 7.0),
    (10, 1, 4, 10, 4.0, 6.0),
    (11, 1, 4, 11, 4.0, 0.0),
    (12, 1, 4, 12, 3.0, 6.0),
    (13, 1, 5, 13, 4.0, 9.0),
    (14, 1, 5, 14, 4.0, 8.0),
    (15, 1, 5, 15, 3.0, 8.0),
    (16, 1, 5, 16, 1.5, 9.0),
    (17, 1, 5, 17, 3.0, 9.0);

INSERT INTO knowledge_base (kb_id, audience_role, topic, content) VALUES
    (1, 'student', 'Profile Access', 'Students can use the portal to view profile, course registration, payment summary, SGPA, and attendance.'),
    (2, 'teacher', 'Teacher Access', 'Teachers can review subject information, attendance trends, and future academic workflow integrations.'),
    (3, 'hod', 'Department Monitoring', 'HOD users can monitor department-level academic data and issue tracking workflows.'),
    (4, 'director', 'University Overview', 'Director users can review institutional summaries and escalated academic concerns.'),
    (5, 'dean', 'Academic Oversight', 'Dean users can use the assistant for academic planning and compliance checks.'),
    (6, 'registrar', 'Records Management', 'Registrar users can use the assistant for records-related operations and verification.'),
    (7, 'management', 'Management Insights', 'Management users can use the assistant for high-level institutional insights.');
