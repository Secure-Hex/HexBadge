-- Migración 010: perfil enriquecido del receptor.
-- Foto de perfil, foto de portada y enlaces a redes sociales.
-- (profile_bio y profile_url ya existían; profile_url = sitio web.)

ALTER TABLE earners
    ADD COLUMN avatar_filename VARCHAR(255) NULL,
    ADD COLUMN cover_filename  VARCHAR(255) NULL,
    ADD COLUMN linkedin_url    VARCHAR(500) NULL,
    ADD COLUMN instagram_url   VARCHAR(500) NULL,
    ADD COLUMN x_url           VARCHAR(500) NULL,
    ADD COLUMN github_url      VARCHAR(500) NULL;
