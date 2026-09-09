# ============================================================
# KoudMain — Image PHP 8.2 + Apache pour Render
# ============================================================
FROM php:8.2-apache

# Extensions nécessaires (PDO PostgreSQL + MySQL pour transition)
RUN apt-get update && apt-get install -y \
        libpq-dev \
        libzip-dev \
        unzip \
    && docker-php-ext-install \
        pdo \
        pdo_pgsql \
        pdo_mysql \
        zip \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*

# Document root
ENV APACHE_DOCUMENT_ROOT=/var/www/html
WORKDIR /var/www/html

# Copie du code
COPY . /var/www/html/

# Permissions sûres
RUN chown -R www-data:www-data /var/www/html \
    && find /var/www/html -type d -exec chmod 755 {} \; \
    && find /var/www/html -type f -exec chmod 644 {} \;

# Configuration Apache : autoriser .htaccess + headers de sécurité
RUN sed -i 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/000-default.conf \
    && sed -i '/<Directory \/var\/www\/>/,/<\/Directory>/ s/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf
EXPOSE 80

# Script de démarrage qui adapte Apache au port fourni par Render
COPY docker-entrypoint.sh /usr/local/bin/
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["apache2-foreground"]