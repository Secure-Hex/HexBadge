-- ============================================================
-- HexBadge — Puesta al día de PRODUCCIÓN (migraciones 002 → 013)
-- ============================================================
-- Seguro de correr en phpMyAdmin AUNQUE algunas migraciones ya estén
-- aplicadas: cada cambio se aplica sólo si falta (idempotente). No usa
-- DELIMITER. Seleccioná tu base (matiasti_hexbadge) y ejecutá todo.
--
-- IMPORTANTE: hacé un backup de la base antes de correr esto.
-- ============================================================

-- ---------- Tablas (idempotentes por IF NOT EXISTS) ----------

-- 002 — Invitaciones de usuarios
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
    INDEX idx_email (email),
    INDEX idx_token (token_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 003 — Ajustes (SMTP, etc.)
CREATE TABLE IF NOT EXISTS settings (
    setting_key   VARCHAR(100) NOT NULL PRIMARY KEY,
    setting_value TEXT NULL,
    is_encrypted  TINYINT(1) NOT NULL DEFAULT 0,
    updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 007 — Tipografías de certificados
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

-- 008 — Empresas (tenants)
CREATE TABLE IF NOT EXISTS companies (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uuid            CHAR(36) NOT NULL UNIQUE,
    name            VARCHAR(200) NOT NULL,
    issuer_url      VARCHAR(500) NULL,
    issuer_email    VARCHAR(255) NULL,
    linkedin_org_id VARCHAR(20) NULL,
    logo_filename   VARCHAR(255) NULL,
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- Columnas (se agregan sólo si faltan) ----------

-- 004 — earners.password_hash
SET @s := (SELECT IF(COUNT(*)=0,'ALTER TABLE earners ADD COLUMN password_hash VARCHAR(255) NULL','DO 0')
  FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='earners' AND COLUMN_NAME='password_hash');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- 006 — earners.totp_secret / totp_enabled
SET @s := (SELECT IF(COUNT(*)=0,'ALTER TABLE earners ADD COLUMN totp_secret VARCHAR(64) NULL','DO 0')
  FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='earners' AND COLUMN_NAME='totp_secret');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s := (SELECT IF(COUNT(*)=0,'ALTER TABLE earners ADD COLUMN totp_enabled TINYINT(1) NOT NULL DEFAULT 0','DO 0')
  FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='earners' AND COLUMN_NAME='totp_enabled');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- 005 — badge_templates.linkedin_org_id
SET @s := (SELECT IF(COUNT(*)=0,'ALTER TABLE badge_templates ADD COLUMN linkedin_org_id VARCHAR(20) NULL','DO 0')
  FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='badge_templates' AND COLUMN_NAME='linkedin_org_id');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- 007 — badge_templates.certificate_filename / certificate_config
SET @s := (SELECT IF(COUNT(*)=0,'ALTER TABLE badge_templates ADD COLUMN certificate_filename VARCHAR(255) NULL','DO 0')
  FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='badge_templates' AND COLUMN_NAME='certificate_filename');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s := (SELECT IF(COUNT(*)=0,'ALTER TABLE badge_templates ADD COLUMN certificate_config JSON NULL','DO 0')
  FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='badge_templates' AND COLUMN_NAME='certificate_config');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- 008 — company_id en users / badge_templates / user_invitations / audit_logs
SET @s := (SELECT IF(COUNT(*)=0,'ALTER TABLE users ADD COLUMN company_id INT UNSIGNED NULL','DO 0')
  FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='company_id');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s := (SELECT IF(COUNT(*)=0,'ALTER TABLE badge_templates ADD COLUMN company_id INT UNSIGNED NULL','DO 0')
  FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='badge_templates' AND COLUMN_NAME='company_id');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s := (SELECT IF(COUNT(*)=0,'ALTER TABLE user_invitations ADD COLUMN company_id INT UNSIGNED NULL','DO 0')
  FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='user_invitations' AND COLUMN_NAME='company_id');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s := (SELECT IF(COUNT(*)=0,'ALTER TABLE audit_logs ADD COLUMN company_id INT UNSIGNED NULL','DO 0')
  FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='audit_logs' AND COLUMN_NAME='company_id');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- Índice para filtrar auditoría por empresa
SET @s := (SELECT IF(COUNT(*)=0,'ALTER TABLE audit_logs ADD INDEX idx_company (company_id)','DO 0')
  FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='audit_logs' AND INDEX_NAME='idx_company');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- El email del emisor pasa a ser opcional (ahora vive en companies)
ALTER TABLE badge_templates MODIFY COLUMN issuer_email VARCHAR(255) NULL;

-- ---------- Migración de datos 008 (sólo si aún no hay empresas) ----------

INSERT INTO companies (uuid, name, issuer_url, issuer_email, linkedin_org_id)
SELECT UUID(), issuer_name, MIN(issuer_url), MIN(issuer_email), MIN(linkedin_org_id)
FROM badge_templates
WHERE issuer_name IS NOT NULL AND issuer_name <> ''
  AND NOT EXISTS (SELECT 1 FROM companies)
GROUP BY issuer_name;

UPDATE badge_templates bt
JOIN companies c ON c.name = bt.issuer_name
SET bt.company_id = c.id
WHERE bt.company_id IS NULL;

UPDATE users
SET company_id = (
    SELECT company_id FROM badge_templates
    WHERE company_id IS NOT NULL
    GROUP BY company_id ORDER BY COUNT(*) DESC LIMIT 1
)
WHERE role <> 'superadmin' AND company_id IS NULL;

-- ---------- Claves foráneas de empresa (sólo si faltan) ----------

SET @s := (SELECT IF(COUNT(*)=0,'ALTER TABLE users ADD CONSTRAINT fk_users_company FOREIGN KEY (company_id) REFERENCES companies(id)','DO 0')
  FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND CONSTRAINT_NAME='fk_users_company');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s := (SELECT IF(COUNT(*)=0,'ALTER TABLE badge_templates ADD CONSTRAINT fk_bt_company FOREIGN KEY (company_id) REFERENCES companies(id)','DO 0')
  FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='badge_templates' AND CONSTRAINT_NAME='fk_bt_company');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s := (SELECT IF(COUNT(*)=0,'ALTER TABLE user_invitations ADD CONSTRAINT fk_inv_company FOREIGN KEY (company_id) REFERENCES companies(id)','DO 0')
  FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='user_invitations' AND CONSTRAINT_NAME='fk_inv_company');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ---------- 009 — SMTP propio por empresa (sólo columnas que falten) ----------

SET @s := (SELECT IF(COUNT(*)=0,'ALTER TABLE companies ADD COLUMN smtp_host VARCHAR(255) NULL','DO 0')
  FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='companies' AND COLUMN_NAME='smtp_host');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s := (SELECT IF(COUNT(*)=0,'ALTER TABLE companies ADD COLUMN smtp_port SMALLINT UNSIGNED NULL','DO 0')
  FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='companies' AND COLUMN_NAME='smtp_port');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s := (SELECT IF(COUNT(*)=0,'ALTER TABLE companies ADD COLUMN smtp_username VARCHAR(255) NULL','DO 0')
  FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='companies' AND COLUMN_NAME='smtp_username');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s := (SELECT IF(COUNT(*)=0,'ALTER TABLE companies ADD COLUMN smtp_password VARCHAR(512) NULL','DO 0')
  FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='companies' AND COLUMN_NAME='smtp_password');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s := (SELECT IF(COUNT(*)=0,'ALTER TABLE companies ADD COLUMN smtp_encryption VARCHAR(10) NULL','DO 0')
  FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='companies' AND COLUMN_NAME='smtp_encryption');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s := (SELECT IF(COUNT(*)=0,'ALTER TABLE companies ADD COLUMN smtp_from_address VARCHAR(255) NULL','DO 0')
  FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='companies' AND COLUMN_NAME='smtp_from_address');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s := (SELECT IF(COUNT(*)=0,'ALTER TABLE companies ADD COLUMN smtp_from_name VARCHAR(255) NULL','DO 0')
  FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='companies' AND COLUMN_NAME='smtp_from_name');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ---------- 010 — perfil del receptor (sólo columnas que falten) ----------

SET @s := (SELECT IF(COUNT(*)=0,'ALTER TABLE earners ADD COLUMN avatar_filename VARCHAR(255) NULL','DO 0')
  FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='earners' AND COLUMN_NAME='avatar_filename');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s := (SELECT IF(COUNT(*)=0,'ALTER TABLE earners ADD COLUMN cover_filename VARCHAR(255) NULL','DO 0')
  FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='earners' AND COLUMN_NAME='cover_filename');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s := (SELECT IF(COUNT(*)=0,'ALTER TABLE earners ADD COLUMN linkedin_url VARCHAR(500) NULL','DO 0')
  FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='earners' AND COLUMN_NAME='linkedin_url');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s := (SELECT IF(COUNT(*)=0,'ALTER TABLE earners ADD COLUMN instagram_url VARCHAR(500) NULL','DO 0')
  FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='earners' AND COLUMN_NAME='instagram_url');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s := (SELECT IF(COUNT(*)=0,'ALTER TABLE earners ADD COLUMN x_url VARCHAR(500) NULL','DO 0')
  FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='earners' AND COLUMN_NAME='x_url');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s := (SELECT IF(COUNT(*)=0,'ALTER TABLE earners ADD COLUMN github_url VARCHAR(500) NULL','DO 0')
  FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='earners' AND COLUMN_NAME='github_url');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ---------- 011 — recuperación de contraseña (sólo columnas que falten) ----------

SET @s := (SELECT IF(COUNT(*)=0,'ALTER TABLE users ADD COLUMN reset_token_hash CHAR(64) NULL','DO 0')
  FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='reset_token_hash');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s := (SELECT IF(COUNT(*)=0,'ALTER TABLE users ADD COLUMN reset_expires DATETIME NULL','DO 0')
  FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='reset_expires');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s := (SELECT IF(COUNT(*)=0,'ALTER TABLE earners ADD COLUMN reset_token_hash CHAR(64) NULL','DO 0')
  FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='earners' AND COLUMN_NAME='reset_token_hash');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s := (SELECT IF(COUNT(*)=0,'ALTER TABLE earners ADD COLUMN reset_expires DATETIME NULL','DO 0')
  FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='earners' AND COLUMN_NAME='reset_expires');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ---------- 012 — plantillas de diplomas reutilizables ----------

CREATE TABLE IF NOT EXISTS diploma_templates (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uuid           CHAR(36) NOT NULL UNIQUE,
    company_id     INT UNSIGNED NULL,                 -- NULL = global (superadmin)
    created_by     INT UNSIGNED NULL,
    name           VARCHAR(150) NOT NULL,
    image_filename VARCHAR(255) NULL,                 -- imagen base en uploads/certificates/
    config         JSON NULL,                         -- posiciones/estilos (formato certificate_config)
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_company (company_id),
    FOREIGN KEY (company_id) REFERENCES companies(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- badge_templates.certificate_template_id (referencia a la plantilla de diploma)
SET @s := (SELECT IF(COUNT(*)=0,'ALTER TABLE badge_templates ADD COLUMN certificate_template_id INT UNSIGNED NULL','DO 0')
  FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='badge_templates' AND COLUMN_NAME='certificate_template_id');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s := (SELECT IF(COUNT(*)=0,'ALTER TABLE badge_templates ADD INDEX idx_cert_template (certificate_template_id)','DO 0')
  FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='badge_templates' AND INDEX_NAME='idx_cert_template');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ---------- 013 — un usuario con acceso a varias empresas ----------

CREATE TABLE IF NOT EXISTS user_companies (
    user_id    INT UNSIGNED NOT NULL,
    company_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (user_id, company_id),
    FOREIGN KEY (user_id)    REFERENCES users(id)     ON DELETE CASCADE,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Backfill: cada usuario con empresa primaria entra al pivote (idempotente)
INSERT IGNORE INTO user_companies (user_id, company_id)
SELECT id, company_id FROM users WHERE company_id IS NOT NULL;

-- user_invitations.company_ids (set completo de empresas al invitar)
SET @s := (SELECT IF(COUNT(*)=0,'ALTER TABLE user_invitations ADD COLUMN company_ids JSON NULL','DO 0')
  FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='user_invitations' AND COLUMN_NAME='company_ids');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ============================================================
-- Listo. Verificá con:  SELECT id, name FROM companies;
-- Luego, en el panel (como superadmin): revisá Empresas, ajustá los
-- datos del emisor y reasigná usuarios a su empresa si hace falta.
-- ============================================================
