-- Migración 004: cuentas de earner. Contraseña para que el receptor pueda
-- loguearse/registrarse y reclamar (claim) sus badges de forma exclusiva.

ALTER TABLE earners
    ADD COLUMN password_hash VARCHAR(255) NULL AFTER token_hash;
