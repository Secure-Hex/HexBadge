-- Migración 012: plantillas de diplomas reutilizables (referencia viva).

CREATE TABLE IF NOT EXISTS diploma_templates (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uuid           CHAR(36) NOT NULL UNIQUE,
    company_id     INT UNSIGNED NULL,                 -- NULL = global (superadmin)
    created_by     INT UNSIGNED NULL,
    name           VARCHAR(150) NOT NULL,
    image_filename VARCHAR(255) NULL,                 -- imagen base en uploads/certificates/
    config         JSON NULL,                         -- posiciones/estilos (mismo formato que certificate_config)
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_company (company_id),
    FOREIGN KEY (company_id) REFERENCES companies(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Referencia desde la acreditación a la plantilla de diploma (tiene prioridad
-- sobre la plantilla propia certificate_filename/certificate_config).
ALTER TABLE badge_templates
    ADD COLUMN certificate_template_id INT UNSIGNED NULL AFTER certificate_config,
    ADD INDEX idx_cert_template (certificate_template_id);
