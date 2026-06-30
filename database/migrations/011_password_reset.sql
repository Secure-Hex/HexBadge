-- Migración 011: recuperación de contraseña (admin + earner).
-- Token de un solo uso, hasheado (SHA-256), con expiración corta.

ALTER TABLE users
    ADD COLUMN reset_token_hash CHAR(64) NULL,
    ADD COLUMN reset_expires    DATETIME NULL;

ALTER TABLE earners
    ADD COLUMN reset_token_hash CHAR(64) NULL,
    ADD COLUMN reset_expires    DATETIME NULL;
