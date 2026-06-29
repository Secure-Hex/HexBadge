-- Migración 009: SMTP propio por empresa (override del SMTP global).
-- Vacío = la empresa usa el SMTP global de la plataforma.

ALTER TABLE companies
    ADD COLUMN smtp_host         VARCHAR(255) NULL,
    ADD COLUMN smtp_port         SMALLINT UNSIGNED NULL,
    ADD COLUMN smtp_username     VARCHAR(255) NULL,
    ADD COLUMN smtp_password     VARCHAR(512) NULL,   -- cifrada (AES-256-GCM)
    ADD COLUMN smtp_encryption   VARCHAR(10) NULL,    -- tls | ssl | none
    ADD COLUMN smtp_from_address VARCHAR(255) NULL,
    ADD COLUMN smtp_from_name    VARCHAR(255) NULL;
