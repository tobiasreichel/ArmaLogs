-- Arma Reforger log collector schema
-- MySQL 8.0+ / MariaDB 10.5+

CREATE TABLE IF NOT EXISTS friends (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(64)  NOT NULL,
    token_hash      CHA DEFAULT CURRENT_TIMESTAMP,
    last_seen_at    DATETIME     NULL,
    is_active       TINYINT(1)   NOT NULL DEFAULT 1,
    note            VARCHAR(255) NULL,
    UNIQUE KEY uq_friends_name (name),
    KEY idx_friends_token (token_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS logs (
    id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    friend_id        INT UNSIGNED     NOT NULL,
    filename         VARCHA)    NOT NULL,
    file_size        BIGINT UNSIGNED  NOT NULL,
    content_sha256   CHAR(64)         NOT NULL,
    client_timestamp DATETIME         NULL,
    uploaded_at      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    session_id       VARCHAR(64)      NULL,
    metadata         JSON             NULL,
    storage_path     VARCHA KEY idx_logs_friend_uploaded (friend_id, uploaded_at),
    KEY idx_logs_session (session_id),
    KEY idx_logs_sha (content_sha256),
    CONSTRAINT fk_logs_friend FOREIGN KEY (friend_id) REFERENCES friends(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ad    created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_admin_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXIST,
    content_sha256  CHAR(64)      NULL,
    status          VARCHAR(16)   NOT NULL,
    message         VARCHAR(255)  NULL,
    remote_addr     VARCHAR(45)   NULL,
    created_at      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_upload_frienmb4 COLLATE=utf8mb4_unicode_ci;
