-- Migración 006: TOTP (2FA opcional) para receptores. Los administradores
-- (tabla users) ya tienen totp_secret / totp_enabled desde el schema inicial.

ALTER TABLE earners
    ADD COLUMN totp_secret  VARCHAR(64) NULL AFTER password_hash,
    ADD COLUMN totp_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER totp_secret;
