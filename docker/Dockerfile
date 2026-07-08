# Imagen de la app HexBadge: PHP 8.3 + Apache con las extensiones requeridas.
FROM php:8.3-apache

# Dependencias del sistema para gd (PNG/JPEG/FreeType) y utilidades.
RUN apt-get update && apt-get install -y --no-install-recommends \
        libpng-dev \
        libjpeg62-turbo-dev \
        libfreetype6-dev \
        libwebp-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql gd \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Apache: habilitar mod_rewrite y mod_headers (para .htaccess y headers).
RUN a2enmod rewrite headers

# Dev con bind-mount: alinear www-data al uid/gid del host (1000) para que los
# archivos que escribe la app (.env, uploads, logs) sean propiedad del usuario
# del host y no de root. Los workers de Apache corren como www-data.
RUN usermod -u 1000 www-data && groupmod -g 1000 www-data

# Document root parametrizable por servicio (admin vs earner). Cada app
# expone solo su carpeta public/. Por defecto el panel admin; el servicio
# earner sobrescribe APACHE_DOCUMENT_ROOT en docker-compose.yml.
ENV APACHE_DOCUMENT_ROOT=/var/www/html/apps/admin/public
RUN sed -ri 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' \
        /etc/apache2/sites-available/000-default.conf \
        /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

# Permitir que los .htaccess del proyecto tengan efecto.
RUN printf '<Directory ${APACHE_DOCUMENT_ROOT}>\n    AllowOverride All\n    Require all granted\n</Directory>\n' \
        > /etc/apache2/conf-available/hexbadge.conf \
    && a2enconf hexbadge

WORKDIR /var/www/html
