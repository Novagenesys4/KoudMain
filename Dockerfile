# syntax=docker/dockerfile:1
#
# KoudMain en production : Apache + PHP 8.4, image construite en trois étages (le résultat final ne contient ni Node,
# ni Composer, ni les dépendances de développement). Aucun secret n'entre dans l'image : tout se règle par variables
# d'environnement au démarrage (Render > Environment). Voir DEPLOIEMENT.md.

# ---------------------------------------------------------------- 1. Dépendances PHP (sans les outils de test)
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
# --no-scripts / --no-autoloader : le code n'est pas encore là. L'image finale relance l'autoloader et la découverte des paquets.
RUN composer install --no-dev --no-interaction --no-progress --no-scripts --no-autoloader --prefer-dist --ignore-platform-reqs

# ---------------------------------------------------------------- 2. Fichiers du navigateur (Vite : CSS, JS, React, Motion)
FROM node:25-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY vite.config.js ./
# Tailwind lit les classes dans les vues, mais aussi dans le code PHP (menus, statuts...) : on copie ce qu'il doit parcourir.
COPY resources ./resources
COPY app ./app
COPY config ./config
COPY routes ./routes
COPY --from=vendor /app/vendor/laravel/framework/src/Illuminate/Pagination/resources ./vendor/laravel/framework/src/Illuminate/Pagination/resources
RUN npm run build

# ---------------------------------------------------------------- 3. Image finale : Apache + PHP
FROM php:8.4-apache AS final

ARG DEBIAN_FRONTEND=noninteractive

# Extensions : PostgreSQL (Supabase), GD avec JPEG et WebP (redimensionnement des photos), intl, zip, opcache.
RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev libpng-dev libjpeg-dev libwebp-dev libzip-dev libicu-dev curl \
    && docker-php-ext-configure gd --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql pgsql gd intl zip opcache \
    && a2enmod rewrite headers deflate expires filter \
    && rm -rf /var/lib/apt/lists/*

# Réglages PHP (production) et Apache. Le port vient de la variable PORT (Render la fixe) : 8080 par défaut.
COPY docker/php/koudmain.ini /usr/local/etc/php/conf.d/koudmain.ini
COPY docker/apache/koudmain.conf /etc/apache2/sites-available/000-default.conf
COPY docker/apache/mpm_prefork.conf /etc/apache2/mods-available/mpm_prefork.conf
ENV PORT=8080 \
    APACHE_MAX_WORKERS=8 \
    APACHE_DOCUMENT_ROOT=/var/www/html/public \
    APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=json
RUN sed -ri 's/^Listen 80$/Listen ${PORT}/' /etc/apache2/ports.conf

WORKDIR /var/www/html

# Le code de l'application, puis les dépendances et les fichiers construits.
COPY --chown=www-data:www-data . .
COPY --from=vendor --chown=www-data:www-data /app/vendor ./vendor
COPY --from=assets --chown=www-data:www-data /app/public/build ./public/build
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

RUN mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache public/uploads \
    && COMPOSER_ALLOW_SUPERUSER=1 composer dump-autoload --no-dev --optimize --classmap-authoritative --no-interaction \
    && rm -f /usr/local/bin/composer \
    && chown -R www-data:www-data storage bootstrap/cache public/uploads \
    && chmod -R ug+rwX storage bootstrap/cache public/uploads

COPY docker/entrypoint.sh /usr/local/bin/koudmain-entrypoint
RUN chmod +x /usr/local/bin/koudmain-entrypoint

EXPOSE 8080
HEALTHCHECK --interval=30s --timeout=5s --start-period=90s --retries=3 CMD curl -fsS "http://127.0.0.1:${PORT}/up" > /dev/null || exit 1

ENTRYPOINT ["koudmain-entrypoint"]
CMD ["apache2-foreground"]
