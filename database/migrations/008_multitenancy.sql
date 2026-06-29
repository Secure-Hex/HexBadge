-- Migración 008: multitenancy (aislamiento por Empresa).

-- Empresas (tenants). Fuente de verdad de los datos del emisor.
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

-- Columna de empresa en las entidades scopeadas (NULL en users = superadmin global).
ALTER TABLE users            ADD COLUMN company_id INT UNSIGNED NULL AFTER role;
ALTER TABLE badge_templates  ADD COLUMN company_id INT UNSIGNED NULL AFTER created_by;
ALTER TABLE user_invitations ADD COLUMN company_id INT UNSIGNED NULL AFTER role;
ALTER TABLE audit_logs       ADD COLUMN company_id INT UNSIGNED NULL AFTER user_id;

-- El email del emisor deja de poblarse desde el template (ahora vive en la empresa).
ALTER TABLE badge_templates MODIFY COLUMN issuer_email VARCHAR(255) NULL;

-- --- Migración de datos: preservar los emisores reales existentes ---
-- Una empresa por cada nombre de emisor distinto.
INSERT INTO companies (uuid, name, issuer_url, issuer_email, linkedin_org_id)
SELECT UUID(), issuer_name, MIN(issuer_url), MIN(issuer_email), MIN(linkedin_org_id)
FROM badge_templates
WHERE issuer_name IS NOT NULL AND issuer_name <> ''
GROUP BY issuer_name;

-- Vincular cada template a su empresa.
UPDATE badge_templates bt
JOIN companies c ON c.name = bt.issuer_name
SET bt.company_id = c.id;

-- Usuarios no-superadmin: asignar a la empresa con más templates (reasignar a mano si hace falta).
UPDATE users
SET company_id = (
    SELECT company_id FROM badge_templates
    WHERE company_id IS NOT NULL
    GROUP BY company_id ORDER BY COUNT(*) DESC LIMIT 1
)
WHERE role <> 'superadmin' AND company_id IS NULL;

-- Claves foráneas.
ALTER TABLE users            ADD CONSTRAINT fk_users_company  FOREIGN KEY (company_id) REFERENCES companies(id);
ALTER TABLE badge_templates  ADD CONSTRAINT fk_bt_company     FOREIGN KEY (company_id) REFERENCES companies(id);
ALTER TABLE user_invitations ADD CONSTRAINT fk_inv_company    FOREIGN KEY (company_id) REFERENCES companies(id);
ALTER TABLE audit_logs       ADD CONSTRAINT fk_audit_company  FOREIGN KEY (company_id) REFERENCES companies(id);
