-- ============================================================
-- HexBadge Schema v1.0
-- MySQL 8.0 — UTF8MB4 — InnoDB
-- ============================================================

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- Empresas (tenants). Fuente de verdad de los datos del emisor.
CREATE TABLE IF NOT EXISTS companies (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uuid            CHAR(36) NOT NULL UNIQUE,
    name            VARCHAR(200) NOT NULL,
    issuer_url      VARCHAR(500) NULL,
    issuer_email    VARCHAR(255) NULL,
    linkedin_org_id VARCHAR(20) NULL,
    logo_filename   VARCHAR(255) NULL,
    smtp_host         VARCHAR(255) NULL,                -- SMTP propio (vacío = usa el global)
    smtp_port         SMALLINT UNSIGNED NULL,
    smtp_username     VARCHAR(255) NULL,
    smtp_password     VARCHAR(512) NULL,                -- cifrada (AES-256-GCM)
    smtp_encryption   VARCHAR(10) NULL,                 -- tls | ssl | none
    smtp_from_address VARCHAR(255) NULL,
    smtp_from_name    VARCHAR(255) NULL,
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Usuarios administradores del sistema
CREATE TABLE IF NOT EXISTS users (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uuid          CHAR(36) NOT NULL UNIQUE,            -- UUID v4 público
    name          VARCHAR(100) NOT NULL,
    email         VARCHAR(255) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,               -- password_hash(BCRYPT, cost=12)
    role          ENUM('superadmin','admin','issuer') NOT NULL DEFAULT 'issuer',
    company_id    INT UNSIGNED NULL,                   -- empresa del usuario (NULL = superadmin global)
    is_active     TINYINT(1) NOT NULL DEFAULT 1,
    totp_secret   VARCHAR(64) NULL,                    -- MFA TOTP (opcional)
    totp_enabled  TINYINT(1) NOT NULL DEFAULT 0,
    reset_token_hash CHAR(64) NULL,                   -- recuperación de contraseña (SHA-256)
    reset_expires DATETIME NULL,
    last_login_at DATETIME NULL,
    last_login_ip VARCHAR(45) NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (company_id) REFERENCES companies(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Empresas accesibles por un usuario (admin/issuer con acceso a >1 empresa).
-- users.company_id es la primaria; el conjunto completo vive acá. El scoping en
-- runtime sigue siendo de UNA empresa a la vez (switcher).
CREATE TABLE IF NOT EXISTS user_companies (
    user_id    INT UNSIGNED NOT NULL,
    company_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (user_id, company_id),
    FOREIGN KEY (user_id)    REFERENCES users(id)     ON DELETE CASCADE,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- API Keys para integración programática
CREATE TABLE IF NOT EXISTS api_keys (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id       INT UNSIGNED NOT NULL,
    key_hash      VARCHAR(255) NOT NULL UNIQUE,        -- SHA-256 hash de la key real
    key_prefix    VARCHAR(12) NOT NULL,                -- Primeros chars para identificación
    name          VARCHAR(100) NOT NULL,               -- Nombre descriptivo
    scopes        JSON NOT NULL,                       -- ["badges:read","badges:issue","bulk:issue"]
    last_used_at  DATETIME NULL,
    expires_at    DATETIME NULL,
    is_active     TINYINT(1) NOT NULL DEFAULT 1,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Templates de badges (lo que se diseña y publica)
CREATE TABLE IF NOT EXISTS badge_templates (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uuid            CHAR(36) NOT NULL UNIQUE,
    created_by      INT UNSIGNED NOT NULL,
    company_id      INT UNSIGNED NULL,                 -- empresa emisora (multitenancy)
    name            VARCHAR(200) NOT NULL,
    description     TEXT NOT NULL,
    criteria_text   TEXT NOT NULL,                     -- Texto de criterios de obtención
    criteria_url    VARCHAR(500) NULL,                 -- URL opcional de criterios
    image_filename  VARCHAR(255) NOT NULL,             -- Nombre del archivo en uploads/
    image_url       VARCHAR(500) NULL,                 -- URL pública de la imagen
    skills_tags     JSON NULL,                         -- ["pentesting","OWASP","web security"]
    issuer_name     VARCHAR(200) NOT NULL DEFAULT 'SecureHex',  -- legacy; el emisor real vive en companies
    issuer_url      VARCHAR(500) NOT NULL DEFAULT 'https://securehex.cl',
    issuer_email    VARCHAR(255) NULL,
    linkedin_org_id VARCHAR(20) NULL,                  -- Organization ID de LinkedIn del emisor (por template)
    certificate_filename VARCHAR(255) NULL,            -- Plantilla de certificado propia (imagen); NULL = sin diploma propio
    certificate_config   JSON NULL,                    -- Posiciones/estilos marcados sobre la plantilla propia
    certificate_template_id INT UNSIGNED NULL,         -- Si se usa una plantilla de diploma guardada (referencia viva); tiene prioridad sobre la propia
    expires_days    INT UNSIGNED NULL,                 -- NULL = no expira
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    is_public       TINYINT(1) NOT NULL DEFAULT 1,
    badges_issued   INT UNSIGNED NOT NULL DEFAULT 0,   -- Contador desnormalizado
    state           ENUM('draft','active','archived') NOT NULL DEFAULT 'draft',
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_cert_template (certificate_template_id),
    FOREIGN KEY (created_by) REFERENCES users(id),
    FOREIGN KEY (company_id) REFERENCES companies(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Plantillas de diplomas reutilizables (una empresa puede guardar varias y
-- referenciarlas desde sus acreditaciones; ver certificate_template_id arriba).
CREATE TABLE IF NOT EXISTS diploma_templates (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uuid           CHAR(36) NOT NULL UNIQUE,
    company_id     INT UNSIGNED NULL,                 -- NULL = global (superadmin)
    created_by     INT UNSIGNED NULL,
    name           VARCHAR(150) NOT NULL,
    image_filename VARCHAR(255) NULL,                 -- imagen base en uploads/certificates/
    config         JSON NULL,                         -- posiciones/estilos (mismo formato que certificate_config)
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_company (company_id),
    FOREIGN KEY (company_id) REFERENCES companies(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Receptores de badges (pueden o no ser usuarios del sistema)
CREATE TABLE IF NOT EXISTS earners (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uuid          CHAR(36) NOT NULL UNIQUE,
    email         VARCHAR(255) NOT NULL UNIQUE,
    first_name    VARCHAR(100) NOT NULL,
    last_name     VARCHAR(100) NOT NULL,
    display_name  VARCHAR(200) GENERATED ALWAYS AS (CONCAT(first_name, ' ', last_name)) STORED,
    profile_bio   TEXT NULL,
    profile_url   VARCHAR(500) NULL,                   -- Sitio web personal
    avatar_filename VARCHAR(255) NULL,                 -- Foto de perfil
    cover_filename  VARCHAR(255) NULL,                 -- Foto de portada
    linkedin_url  VARCHAR(500) NULL,
    instagram_url VARCHAR(500) NULL,
    x_url         VARCHAR(500) NULL,
    github_url    VARCHAR(500) NULL,
    token_hash    VARCHAR(255) NULL UNIQUE,            -- Hash del token para acceso a wallet
    password_hash VARCHAR(255) NULL,                   -- Cuenta del earner (login/registro) — bcrypt
    totp_secret   VARCHAR(64) NULL,                    -- 2FA TOTP (opcional)
    totp_enabled  TINYINT(1) NOT NULL DEFAULT 0,
    reset_token_hash CHAR(64) NULL,                   -- recuperación de contraseña (SHA-256)
    reset_expires DATETIME NULL,
    token_expires DATETIME NULL,
    is_verified   TINYINT(1) NOT NULL DEFAULT 0,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Badges emitidos (assertion Open Badges 2.0)
CREATE TABLE IF NOT EXISTS issued_badges (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uuid                CHAR(36) NOT NULL UNIQUE,      -- ID público de la assertion
    badge_template_id   INT UNSIGNED NOT NULL,
    earner_id           INT UNSIGNED NOT NULL,
    issued_by           INT UNSIGNED NOT NULL,         -- FK a users
    issued_via          ENUM('manual','csv','api') NOT NULL DEFAULT 'manual',
    issued_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at          DATETIME NULL,
    status              ENUM('pending','accepted','rejected','revoked') NOT NULL DEFAULT 'pending',
    revoked_at          DATETIME NULL,
    revoke_reason       VARCHAR(500) NULL,
    notification_sent   TINYINT(1) NOT NULL DEFAULT 0,
    notification_sent_at DATETIME NULL,
    accept_token        VARCHAR(255) NULL UNIQUE,      -- Token único para aceptar el badge
    accept_token_expires DATETIME NULL,
    accepted_at         DATETIME NULL,
    ob_assertion_json   JSON NULL,                     -- Open Badge assertion completa cacheada
    locale              VARCHAR(10) NOT NULL DEFAULT 'es',
    FOREIGN KEY (badge_template_id) REFERENCES badge_templates(id),
    FOREIGN KEY (earner_id) REFERENCES earners(id),
    FOREIGN KEY (issued_by) REFERENCES users(id),
    INDEX idx_earner (earner_id),
    INDEX idx_template (badge_template_id),
    INDEX idx_status (status),
    INDEX idx_uuid (uuid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Jobs de emisión masiva CSV
CREATE TABLE IF NOT EXISTS bulk_import_jobs (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uuid          CHAR(36) NOT NULL UNIQUE,
    user_id       INT UNSIGNED NOT NULL,
    template_id   INT UNSIGNED NOT NULL,
    filename_orig VARCHAR(255) NOT NULL,
    total_rows    INT UNSIGNED NOT NULL DEFAULT 0,
    processed     INT UNSIGNED NOT NULL DEFAULT 0,
    success_count INT UNSIGNED NOT NULL DEFAULT 0,
    error_count   INT UNSIGNED NOT NULL DEFAULT 0,
    status        ENUM('queued','processing','done','failed') NOT NULL DEFAULT 'queued',
    errors_json   JSON NULL,                           -- Lista de errores por fila
    started_at    DATETIME NULL,
    finished_at   DATETIME NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (template_id) REFERENCES badge_templates(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Log de auditoría inmutable
CREATE TABLE IF NOT EXISTS audit_logs (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED NULL,                     -- NULL si es acción de sistema/API
    company_id  INT UNSIGNED NULL,                     -- empresa del actor (multitenancy)
    api_key_id  INT UNSIGNED NULL,
    action      VARCHAR(100) NOT NULL,                 -- 'badge.issued', 'user.login', etc.
    entity_type VARCHAR(50) NULL,                      -- 'badge_template', 'issued_badge', etc.
    entity_id   VARCHAR(36) NULL,                      -- UUID de la entidad afectada
    ip_address  VARCHAR(45) NOT NULL,
    user_agent  VARCHAR(500) NULL,
    metadata    JSON NULL,                             -- Datos adicionales del evento
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user (user_id),
    INDEX idx_company (company_id),
    INDEX idx_action (action),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ajustes editables de la plataforma (clave-valor). Ej: configuración SMTP.
CREATE TABLE IF NOT EXISTS settings (
    setting_key   VARCHAR(100) NOT NULL PRIMARY KEY,
    setting_value TEXT NULL,
    is_encrypted  TINYINT(1) NOT NULL DEFAULT 0,
    updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Invitaciones de usuarios del panel (alta por invitación, no registro abierto)
CREATE TABLE IF NOT EXISTS user_invitations (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uuid        CHAR(36) NOT NULL UNIQUE,
    email       VARCHAR(255) NOT NULL,
    role        ENUM('superadmin','admin','issuer') NOT NULL DEFAULT 'issuer',
    company_id  INT UNSIGNED NULL,                     -- empresa primaria del futuro usuario (multitenancy)
    company_ids JSON NULL,                             -- conjunto completo de empresas (>1); null = solo la primaria
    token_hash  VARCHAR(255) NOT NULL UNIQUE,          -- SHA-256 del token enviado por email
    invited_by  INT UNSIGNED NOT NULL,
    expires_at  DATETIME NOT NULL,
    accepted_at DATETIME NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (invited_by) REFERENCES users(id),
    FOREIGN KEY (company_id) REFERENCES companies(id),
    INDEX idx_email (email),
    INDEX idx_token (token_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tipografías para certificados (built-in + subidas por el admin)
CREATE TABLE IF NOT EXISTS fonts (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name       VARCHAR(100) NOT NULL,
    file_path  VARCHAR(255) NOT NULL UNIQUE,
    is_builtin TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO fonts (name, file_path, is_builtin) VALUES
    ('Public Sans',                'lib/fonts/PublicSans-Regular.ttf',      1),
    ('Public Sans (negrita)',      'lib/fonts/PublicSans-Bold.ttf',         1),
    ('Playfair Display',           'lib/fonts/PlayfairDisplay-Regular.ttf', 1),
    ('Playfair Display (negrita)', 'lib/fonts/PlayfairDisplay-Bold.ttf',    1);

-- Rate limiting por IP
CREATE TABLE IF NOT EXISTS rate_limit_attempts (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    identifier  VARCHAR(255) NOT NULL,                 -- IP o "user:{id}"
    action      VARCHAR(100) NOT NULL,                 -- 'login', 'api', 'verify', etc.
    attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_identifier_action (identifier, action),
    INDEX idx_attempted (attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
