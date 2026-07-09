-- Migración 014: multi-correo del receptor y soporte de fusión de wallets.
--
-- Un earner puede tener varios correos (earner_emails); cada badge recuerda el
-- correo por el que se emitió (issued_badges.recipient_email); earners.merged_into_id
-- marca una cuenta fusionada dentro de otra; earner_merges registra el ciclo de
-- vida de cada fusión (verificación por email → activa → revertida) y guarda lo
-- necesario para deshacerla.

CREATE TABLE IF NOT EXISTS earner_emails (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    earner_id  INT UNSIGNED NOT NULL,
    email      VARCHAR(255) NOT NULL UNIQUE,
    is_primary TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (earner_id) REFERENCES earners(id),
    INDEX idx_earner (earner_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS earner_merges (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    target_earner_id  INT UNSIGNED NOT NULL,        -- wallet que absorbe (destino)
    source_email      VARCHAR(255) NOT NULL,        -- correo a vincular/fusionar
    source_earner_id  INT UNSIGNED NULL,            -- earner del correo, si existe
    verify_token_hash CHAR(64) NOT NULL,            -- SHA-256 del token de verificación
    verify_expires    DATETIME NOT NULL,
    moved_badge_ids   JSON NULL,                    -- ids de issued_badges movidos (para revertir)
    profile_choices   JSON NULL,                    -- campos de perfil elegidos del origen
    source_snapshot   JSON NULL,                    -- perfil previo (para revertir)
    revert_token_hash CHAR(64) NULL,
    revert_expires    DATETIME NULL,
    status            ENUM('pending','active','reverted','expired') NOT NULL DEFAULT 'pending',
    created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (target_earner_id) REFERENCES earners(id),
    INDEX idx_target (target_earner_id),
    INDEX idx_verify (verify_token_hash),
    INDEX idx_revert (revert_token_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE earners
    ADD COLUMN merged_into_id INT UNSIGNED NULL,
    ADD COLUMN merged_at      DATETIME NULL;

ALTER TABLE issued_badges
    ADD COLUMN recipient_email VARCHAR(255) NULL;

-- Backfill: cada earner aporta su email actual como correo primario.
INSERT INTO earner_emails (earner_id, email, is_primary)
SELECT id, email, 1 FROM earners
WHERE NOT EXISTS (SELECT 1 FROM earner_emails ee WHERE ee.earner_id = earners.id);

-- Backfill: recipient_email = correo del dueño actual de cada badge.
UPDATE issued_badges ib
JOIN earners e ON e.id = ib.earner_id
SET ib.recipient_email = e.email
WHERE ib.recipient_email IS NULL;
