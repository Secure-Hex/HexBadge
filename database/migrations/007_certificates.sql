-- Migración 007: certificados/diplomas por template + tipografías.

ALTER TABLE badge_templates
    ADD COLUMN certificate_filename VARCHAR(255) NULL AFTER linkedin_org_id,
    ADD COLUMN certificate_config   JSON NULL         AFTER certificate_filename;

-- Tipografías disponibles para los certificados (built-in + subidas por el admin).
CREATE TABLE IF NOT EXISTS fonts (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name       VARCHAR(100) NOT NULL,
    file_path  VARCHAR(255) NOT NULL UNIQUE,   -- relativa a la raíz del proyecto
    is_builtin TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO fonts (name, file_path, is_builtin) VALUES
    ('Public Sans',            'lib/fonts/PublicSans-Regular.ttf',      1),
    ('Public Sans (negrita)',  'lib/fonts/PublicSans-Bold.ttf',         1),
    ('Playfair Display',       'lib/fonts/PlayfairDisplay-Regular.ttf', 1),
    ('Playfair Display (negrita)', 'lib/fonts/PlayfairDisplay-Bold.ttf', 1);
