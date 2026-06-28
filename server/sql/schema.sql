-- ArmaLogs schema
-- MySQL 8.0+ / MariaDB 10.5+

CREATE TABLE IF NOT EXISTS admin_users (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username        VARCHAR(64)  NOT NULL,
    password_hash   CHAR(255)    NOT NULL,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_admin_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS friend_requests (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name          VARCHAR(64)  NOT NULL,
    hostname      VARCHAR(255) NULL,
    token_hash    CHAR(64)     NOT NULL,
    status        VARCHAR(16)  NOT NULL DEFAULT 'pending',
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    decided_at    DATETIME     NULL,
    UNIQUE KEY uq_friend_requests_name (name),
    KEY idx_friend_requests_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS friends (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(64)  NOT NULL,
    token_hash      CHAR(64)     NOT NULL,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen_at    DATETIME     NULL,
    is_active       TINYINT(1)   NOT NULL DEFAULT 1,
    note            VARCHAR(255) NULL,
    UNIQUE KEY uq_friends_name (name),
    KEY idx_friends_token (token_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sessions (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    friend_id       INT UNSIGNED NOT NULL,
    session_id      VARCHAR(64)  NOT NULL,
    client_hostname VARCHAR(255) NULL,
    started_at      DATETIME     NULL,
    uploaded_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    log_count       INT UNSIGNED NOT NULL DEFAULT 0,
    total_bytes     BIGINT UNSIGNED NOT NULL DEFAULT 0,
    UNIQUE KEY uq_sessions_session (friend_id, session_id),
    KEY idx_sessions_friend_uploaded (friend_id, uploaded_at),
    CONSTRAINT fk_sessions_friend FOREIGN KEY (friend_id) REFERENCES friends(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS logs (
    id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_id       BIGINT UNSIGNED  NOT NULL,
    friend_id        INT UNSIGNED     NOT NULL,
    filename         VARCHAR(255)     NOT NULL,
    file_size        BIGINT UNSIGNED  NOT NULL,
    content_sha256   CHAR(64)         NOT NULL,
    client_timestamp DATETIME         NULL,
    uploaded_at      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    metadata         JSON             NULL,
    storage_path     VARCHAR(512)     NOT NULL,
    KEY idx_logs_session (session_id),
    KEY idx_logs_friend_uploaded (friend_id, uploaded_at),
    KEY idx_logs_sha (content_sha256),
    CONSTRAINT fk_logs_session FOREIGN KEY (session_id) REFERENCES sessions(id) ON DELETE CASCADE,
    CONSTRAINT fk_logs_friend FOREIGN KEY (friend_id) REFERENCES friends(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS reports (
    id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    friend_id     INT UNSIGNED     NULL,
    session_id    BIGINT UNSIGNED  NULL,
    log_ids       JSON             NOT NULL,
    title         VARCHAR(255)     NOT NULL,
    summary       TEXT             NOT NULL,
    findings      JSON             NOT NULL,
    model         VARCHAR(64)      NOT NULL,
    created_at    DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_reports_friend (friend_id),
    KEY idx_reports_session (session_id),
    KEY idx_reports_created (created_at),
    CONSTRAINT fk_reports_friend FOREIGN KEY (friend_id) REFERENCES friends(id) ON DELETE SET NULL,
    CONSTRAINT fk_reports_session FOREIGN KEY (session_id) REFERENCES sessions(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS upload_queue (
    id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    friend_id     INT UNSIGNED NOT NULL,
    session_id    VARCHAR(64)  NOT NULL,
    filename      VARCHAR(255) NOT NULL,
    file_size     BIGINT UNSIGNED NOT NULL,
    content_sha256 CHAR(64)    NOT NULL,
    status        VARCHAR(16)  NOT NULL DEFAULT 'pending',
    message       VARCHAR(255) NULL,
    remote_addr   VARCHAR(45)  NULL,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME     NULL ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_upload_friend_status (friend_id, status),
    KEY idx_upload_sha (content_sha256),
    CONSTRAINT fk_upload_friend FOREIGN KEY (friend_id) REFERENCES friends(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
