
-- ==================================================
-- CLASSMATE QR ATTENDANCE SYSTEM
-- Database: classmate_db
-- MySQL / MariaDB
-- ==================================================

CREATE DATABASE IF NOT EXISTS classmate_db
CHARACTER SET utf8mb4
COLLATE utf8mb4_unicode_ci;

USE classmate_db;


-- ==================================================
-- 1. USERS
-- ==================================================

CREATE TABLE IF NOT EXISTS users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    student_code VARCHAR(40) NULL UNIQUE,

    full_name VARCHAR(120) NOT NULL,

    email VARCHAR(190) NOT NULL UNIQUE,

    password_hash VARCHAR(255) NOT NULL,

    role ENUM('admin','student')
        NOT NULL DEFAULT 'student',

    is_active TINYINT(1)
        NOT NULL DEFAULT 1,

    created_at TIMESTAMP
        NOT NULL DEFAULT CURRENT_TIMESTAMP

) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;


-- ==================================================
-- 2. ATTENDANCE SESSIONS
-- ==================================================

CREATE TABLE IF NOT EXISTS attendance_sessions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    title VARCHAR(150) NOT NULL,

    class_name VARCHAR(80) NOT NULL,

    starts_at DATETIME NOT NULL,

    late_after DATETIME NOT NULL,

    ends_at DATETIME NOT NULL,

    token_hash CHAR(64) NOT NULL UNIQUE,

    status ENUM('open','closed')
        NOT NULL DEFAULT 'open',

    created_by BIGINT UNSIGNED NOT NULL,

    created_at TIMESTAMP
        NOT NULL DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_session_status (status, ends_at),

    INDEX idx_session_creator (created_by),

    CONSTRAINT fk_session_admin
        FOREIGN KEY (created_by)
        REFERENCES users(id)
        ON DELETE RESTRICT
        ON UPDATE CASCADE

) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;


-- ==================================================
-- 3. ATTENDANCE
-- ==================================================

CREATE TABLE IF NOT EXISTS attendance (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    session_id BIGINT UNSIGNED NOT NULL,

    student_id BIGINT UNSIGNED NOT NULL,

    status ENUM(
        'present',
        'late',
        'absent',
        'permission'
    ) NOT NULL,

    checked_in_at DATETIME NULL,

    note VARCHAR(500) NULL,

    source ENUM('qr','admin')
        NOT NULL DEFAULT 'qr',

    created_at TIMESTAMP
        NOT NULL DEFAULT CURRENT_TIMESTAMP,

    updated_at TIMESTAMP
        NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_session_student (
        session_id,
        student_id
    ),

    INDEX idx_attendance_student (
        student_id,
        checked_in_at
    ),

    INDEX idx_attendance_session (
        session_id
    ),

    CONSTRAINT fk_att_session
        FOREIGN KEY (session_id)
        REFERENCES attendance_sessions(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE,

    CONSTRAINT fk_att_student
        FOREIGN KEY (student_id)
        REFERENCES users(id)
        ON DELETE RESTRICT
        ON UPDATE CASCADE

) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;


-- ==================================================
-- 4. ATTENDANCE LOGS
-- ==================================================

CREATE TABLE IF NOT EXISTS attendance_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    attendance_id BIGINT UNSIGNED NOT NULL,

    changed_by BIGINT UNSIGNED NOT NULL,

    old_status VARCHAR(20) NOT NULL,

    new_status VARCHAR(20) NOT NULL,

    note VARCHAR(500) NULL,

    created_at TIMESTAMP
        NOT NULL DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_log_attendance (attendance_id),

    INDEX idx_log_user (changed_by),

    CONSTRAINT fk_log_att
        FOREIGN KEY (attendance_id)
        REFERENCES attendance(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE,

    CONSTRAINT fk_log_user
        FOREIGN KEY (changed_by)
        REFERENCES users(id)
        ON DELETE RESTRICT
        ON UPDATE CASCADE

) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;


-- ==================================================
-- 5. DATABASE VERIFICATION
-- ==================================================

SHOW TABLES;

DESCRIBE users;
DESCRIBE attendance_sessions;
DESCRIBE attendance;
DESCRIBE attendance_logs;


-- ==================================================
-- 6. CHECK DATABASE RECORDS
-- ==================================================

SELECT * FROM users;

SELECT * FROM attendance_sessions;

SELECT * FROM attendance;

SELECT * FROM attendance_logs;
