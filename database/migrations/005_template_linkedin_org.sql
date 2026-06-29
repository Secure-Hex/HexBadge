-- Migración 005: Organization ID de LinkedIn por template (el emisor cambia
-- según el badge, p.ej. SecureHex vs. Cámara Chilena de IA).

ALTER TABLE badge_templates
    ADD COLUMN linkedin_org_id VARCHAR(20) NULL AFTER issuer_email;
