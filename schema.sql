
CREATE DATABASE IF NOT EXISTS `alsarmini_swap`
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
 
USE `alsarmini_swap`;

-- ---------------------------------------------------------------------
-- 1) جداول المستخدمين ومحاولات الدخول
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    full_name     VARCHAR(255) NOT NULL,
    email         VARCHAR(191) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role          ENUM('admin','user') NOT NULL DEFAULT 'user',
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_login_at DATETIME NULL
) ENGINE=InnoDB CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_attempts (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email        VARCHAR(191) NOT NULL,
    ip_address   VARCHAR(45) NOT NULL,
    attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_attempts_email (email, attempted_at),
    INDEX idx_attempts_ip (ip_address, attempted_at)
) ENGINE=InnoDB CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
 
-- ---------------------------------------------------------------------
-- 1) جدول الطلاب
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS students (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id             INT UNSIGNED NOT NULL,
    university_id       VARCHAR(191) NOT NULL,
    full_name           VARCHAR(255) NOT NULL,
    original_category   VARCHAR(100) NOT NULL,
    current_category    VARCHAR(100) NOT NULL,
    extra_fields        TEXT NOT NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                            ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_students_user FOREIGN KEY (user_id) REFERENCES users(id),
    UNIQUE KEY uq_students_user_univ (user_id, university_id)
) ENGINE=InnoDB CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
 
-- ---------------------------------------------------------------------
-- 2) جدول طلبات التبديل
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS swap_requests (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED NOT NULL,
    student_id      INT UNSIGNED NOT NULL,
    from_category   VARCHAR(100) NOT NULL,
    to_category     VARCHAR(100) NOT NULL,
    status          ENUM('pending','matched','cancelled') NOT NULL DEFAULT 'pending',
    notes           VARCHAR(500) NOT NULL DEFAULT '',
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    matched_at      DATETIME NULL,
    match_id        INT UNSIGNED NULL,
    CONSTRAINT fk_requests_student
        FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    CONSTRAINT fk_requests_user
        FOREIGN KEY (user_id) REFERENCES users(id),
    INDEX idx_requests_status (status),
    INDEX idx_requests_route (from_category, to_category, status),
    INDEX idx_requests_user (user_id, status)
) ENGINE=InnoDB CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
 
-- ---------------------------------------------------------------------
-- 3) جدول المطابقات
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS matches (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED NOT NULL,
    request_a_id    INT UNSIGNED NOT NULL,
    request_b_id    INT UNSIGNED NOT NULL,
    matched_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    status          ENUM('active','undone') NOT NULL DEFAULT 'active',
    match_type      ENUM('auto','direct') NOT NULL DEFAULT 'auto',
    CONSTRAINT fk_matches_request_a
        FOREIGN KEY (request_a_id) REFERENCES swap_requests(id),
    CONSTRAINT fk_matches_request_b
        FOREIGN KEY (request_b_id) REFERENCES swap_requests(id),
    CONSTRAINT fk_matches_user
        FOREIGN KEY (user_id) REFERENCES users(id),
    INDEX idx_matches_user (user_id, status)
) ENGINE=InnoDB CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
 
-- ---------------------------------------------------------------------
-- 4) جدول سجل تاريخ تغييرات فئة كل طالب
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS category_history (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    student_id      INT UNSIGNED NOT NULL,
    old_category    VARCHAR(100) NOT NULL,
    new_category    VARCHAR(100) NOT NULL,
    reason          ENUM('match','undo','manual_edit') NOT NULL,
    request_id      INT UNSIGNED NULL,
    match_id        INT UNSIGNED NULL,
    changed_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_history_student
        FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    CONSTRAINT fk_history_request
        FOREIGN KEY (request_id) REFERENCES swap_requests(id),
    CONSTRAINT fk_history_match
        FOREIGN KEY (match_id) REFERENCES matches(id),
    INDEX idx_history_student (student_id),
    INDEX idx_history_match (match_id)
) ENGINE=InnoDB CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
 
-- ---------------------------------------------------------------------
-- 5) جدول سجل عمليات استيراد الإكسل
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS import_batches (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED NOT NULL,
    file_name       VARCHAR(255) NOT NULL,
    inserted_count  INT UNSIGNED NOT NULL DEFAULT 0,
    updated_count   INT UNSIGNED NOT NULL DEFAULT 0,
    error_count     INT UNSIGNED NOT NULL DEFAULT 0,
    errors_json     TEXT NOT NULL,
    imported_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_batches_user FOREIGN KEY (user_id) REFERENCES users(id),
    INDEX idx_batches_user (user_id)
) ENGINE=InnoDB CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
 