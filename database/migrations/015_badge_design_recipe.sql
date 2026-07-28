-- Receta del diseñador de insignias integrado.
-- NULL = la imagen se subió; con valor = se diseñó dentro de la app.
ALTER TABLE badge_templates
  ADD COLUMN design_recipe JSON NULL AFTER image_url;
