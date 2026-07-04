-- Migración 013: un usuario (admin/issuer) puede tener acceso a VARIAS empresas.
-- Antes cada usuario tenía un único users.company_id. Ahora ese valor pasa a ser
-- la empresa "primaria" (default del switcher, dueña de sus API keys) y el conjunto
-- completo vive en la tabla pivote user_companies. El superadmin sigue siendo global
-- (sin filas acá). El scoping en runtime sigue siendo de UNA empresa a la vez.

CREATE TABLE IF NOT EXISTS user_companies (
    user_id    INT UNSIGNED NOT NULL,
    company_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (user_id, company_id),
    FOREIGN KEY (user_id)    REFERENCES users(id)     ON DELETE CASCADE,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Backfill: cada usuario con empresa asignada obtiene esa empresa en el pivote.
INSERT IGNORE INTO user_companies (user_id, company_id)
SELECT id, company_id FROM users WHERE company_id IS NOT NULL;

-- Las invitaciones pueden traer varias empresas (JSON con la lista de ids).
-- company_id se conserva como la primaria (primera del set) para BC.
ALTER TABLE user_invitations
    ADD COLUMN company_ids JSON NULL AFTER company_id;
