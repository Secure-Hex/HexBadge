-- Migración 002: invitaciones de usuarios (alta por invitación).
-- Para instalaciones creadas antes de incorporar esta tabla al schema.

CREATE TABLE IF NOT EXISTS user_invitations (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uuid        CHAR(36) NOT NULL UNIQUE,
    email       VARCHAR(255) NOT NULL,
    role        ENUM('superadmin','admin','issuer') NOT NULL DEFAULT 'issuer',
    token_hash  VARCHAR(255) NOT NULL UNIQUE,
    invited_by  INT UNSIGNED NOT NULL,
    expires_at  DATETIME NOT NULL,
    accepted_at DATETIME NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (invited_by) REFERENCES users(id),
    INDEX idx_email (email),
    INDEX idx_token (token_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
