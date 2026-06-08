-- ============================================================
--  EARMS — Defense & Evaluation + Storage Microservice
--  Complete database schema (no seed data).
--  Load with:  mysql -u <user> -p <database> < earms_schema.sql
--  Production databases start empty: user accounts arrive from the
--  IAM Service and projects from the Project Workflow Service.
-- ============================================================
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS notifications;
DROP TABLE IF EXISTS auth_sessions;
DROP TABLE IF EXISTS audit_logs;
DROP TABLE IF EXISTS defense_scores;
DROP TABLE IF EXISTS defense_recordings;
DROP TABLE IF EXISTS defense_materials;
DROP TABLE IF EXISTS files;
DROP TABLE IF EXISTS defense_participants;
DROP TABLE IF EXISTS defenses;
DROP TABLE IF EXISTS projects;
DROP TABLE IF EXISTS users;

-- ---- Users (local mirror of IAM accounts) --------------------------------
CREATE TABLE users (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    name          VARCHAR(120) NOT NULL,
    email         VARCHAR(160),
    password_hash VARCHAR(255) NULL,            -- used only in 'standalone' auth mode
    role          VARCHAR(40)  NOT NULL,        -- student | supervisor | coordinator | internal_examiner | external_examiner | exam_officer
    department    VARCHAR(120),
    is_active     TINYINT(1) NOT NULL DEFAULT 1,
    last_login    DATETIME NULL,
    created_at    DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---- Projects (local mirror of Project Workflow Service) -----------------
CREATE TABLE projects (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    title         VARCHAR(255) NOT NULL,
    student_id    INT,
    supervisor_id INT,
    department    VARCHAR(120),
    status        VARCHAR(20) DEFAULT 'in_progress',  -- in_progress | completed
    created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_proj_student FOREIGN KEY (student_id)    REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_proj_super   FOREIGN KEY (supervisor_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---- Defenses ------------------------------------------------------------
CREATE TABLE defenses (
    id                   INT AUTO_INCREMENT PRIMARY KEY,
    reference            VARCHAR(40) UNIQUE,
    project_id           INT NOT NULL,
    student_id           INT NOT NULL,
    supervisor_id        INT,
    external_examiner_id INT,
    scheduled_at         DATETIME,
    venue                VARCHAR(160),
    mode                 VARCHAR(20) DEFAULT 'physical',   -- physical | virtual
    status               VARCHAR(20) DEFAULT 'scheduled',  -- scheduled | ongoing | completed | cancelled
    meeting_url          VARCHAR(255),
    aggregate_score      DECIMAL(6,2),
    final_grade          VARCHAR(4),
    result_status        VARCHAR(10),                      -- pass | fail
    published            TINYINT(1) DEFAULT 0,
    finalized            TINYINT(1) DEFAULT 0,
    sent_to_exam_officer TINYINT(1) DEFAULT 0,
    created_at           DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at           DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_def_project (project_id),
    INDEX idx_def_status (status),
    CONSTRAINT fk_def_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    CONSTRAINT fk_def_student FOREIGN KEY (student_id) REFERENCES users(id)    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---- Defense participants ------------------------------------------------
CREATE TABLE defense_participants (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    defense_id  INT NOT NULL,
    user_id     INT NOT NULL,
    role        VARCHAR(30) NOT NULL,             -- student | supervisor | internal_examiner | external_examiner
    attendance  VARCHAR(12) DEFAULT 'pending',    -- pending | present | absent
    created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_def_user (defense_id, user_id),
    INDEX idx_part_def (defense_id),
    CONSTRAINT fk_part_def  FOREIGN KEY (defense_id) REFERENCES defenses(id) ON DELETE CASCADE,
    CONSTRAINT fk_part_user FOREIGN KEY (user_id)    REFERENCES users(id)    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---- Storage: files (Storage microservice) -------------------------------
CREATE TABLE files (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    file_uid      VARCHAR(40) UNIQUE,
    project_id    INT,
    defense_id    INT,
    name          VARCHAR(255) NOT NULL,
    original_name VARCHAR(255) NULL,
    file_type     VARCHAR(30),                    -- document | slides | dataset | recording | feedback | image | video
    mime          VARCHAR(120),
    size_bytes    BIGINT DEFAULT 0,
    access_level  VARCHAR(30) DEFAULT 'department_only',
    version       INT DEFAULT 1,
    storage_path  VARCHAR(255),
    checksum      CHAR(64) NULL,
    is_stored     TINYINT(1) NOT NULL DEFAULT 0,
    uploaded_by   INT,
    is_deleted    TINYINT(1) DEFAULT 0,
    created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_file_project (project_id),
    INDEX idx_file_defense (defense_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---- Defense materials (presentation files per session) ------------------
CREATE TABLE defense_materials (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    defense_id  INT NOT NULL,
    file_id     INT NOT NULL,
    version     INT DEFAULT 1,
    uploaded_by INT,
    created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_mat_def (defense_id),
    CONSTRAINT fk_mat_def  FOREIGN KEY (defense_id) REFERENCES defenses(id) ON DELETE CASCADE,
    CONSTRAINT fk_mat_file FOREIGN KEY (file_id)    REFERENCES files(id)    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---- Defense recordings --------------------------------------------------
CREATE TABLE defense_recordings (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    defense_id    INT NOT NULL,
    file_id       INT,
    status        VARCHAR(12) DEFAULT 'recording',   -- recording | stopped | saved
    duration_sec  INT DEFAULT 0,
    size_bytes    BIGINT DEFAULT 0,
    storage_path  VARCHAR(255),
    started_at    DATETIME,
    stopped_at    DATETIME,
    created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_rec_def (defense_id),
    CONSTRAINT fk_rec_def FOREIGN KEY (defense_id) REFERENCES defenses(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---- Defense scores (rubric-based) ---------------------------------------
CREATE TABLE defense_scores (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    defense_id       INT NOT NULL,
    evaluator_id     INT NOT NULL,
    evaluator_role   VARCHAR(30) NOT NULL,        -- supervisor | internal_examiner | external_examiner
    content_quality  DECIMAL(5,2) DEFAULT 0,      -- /30
    presentation     DECIMAL(5,2) DEFAULT 0,      -- /25
    originality      DECIMAL(5,2) DEFAULT 0,      -- /25
    defense_response DECIMAL(5,2) DEFAULT 0,      -- /20
    total            DECIMAL(6,2) DEFAULT 0,      -- /100
    comments         TEXT,
    locked           TINYINT(1) DEFAULT 0,
    created_at       DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_def_eval (defense_id, evaluator_id),
    INDEX idx_score_def (defense_id),
    CONSTRAINT fk_score_def  FOREIGN KEY (defense_id)   REFERENCES defenses(id) ON DELETE CASCADE,
    CONSTRAINT fk_score_eval FOREIGN KEY (evaluator_id) REFERENCES users(id)    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---- Audit logs ----------------------------------------------------------
CREATE TABLE audit_logs (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    defense_id  INT,
    action      VARCHAR(60) NOT NULL,
    detail      VARCHAR(255),
    actor       VARCHAR(120),
    created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_audit_def (defense_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---- Server-side auth sessions (standalone mode) -------------------------
CREATE TABLE auth_sessions (
    id          VARCHAR(64) PRIMARY KEY,
    user_id     INT NOT NULL,
    ip          VARCHAR(45),
    user_agent  VARCHAR(255),
    created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
    expires_at  DATETIME NOT NULL,
    INDEX idx_sess_user (user_id),
    INDEX idx_sess_exp (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---- Notifications (Notification microservice hook) ----------------------
CREATE TABLE notifications (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    defense_id  INT NULL,
    user_id     INT NULL,
    channel     VARCHAR(12) NOT NULL DEFAULT 'email',   -- email | inapp
    recipient   VARCHAR(190),
    subject     VARCHAR(190),
    body        TEXT,
    event       VARCHAR(60),
    status      VARCHAR(12) NOT NULL DEFAULT 'queued',  -- queued | sent | failed
    created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
    sent_at     DATETIME NULL,
    INDEX idx_notif_def (defense_id),
    INDEX idx_notif_user (user_id),
    INDEX idx_notif_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
