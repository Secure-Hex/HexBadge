-- Migración 003: ajustes editables (clave-valor), p.ej. configuración SMTP.

CREATE TABLE IF NOT EXISTS settings (
    setting_key   VARCHAR(100) NOT NULL PRIMARY KEY,
    setting_value TEXT NULL,
    is_encrypted  TINYINT(1) NOT NULL DEFAULT 0,
    updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
