FROM php:8.3-apache

# Installe l'extension PostgreSQL
RUN apt-get update && apt-get install -y libpq-dev \
    && docker-php-ext-install pdo pdo_pgsql

# Active le module rewrite (utile pour beaucoup de projets)
RUN a2enmod rewrite

# Copie tout ton code dans le serveur
COPY . /var/www/html/

# Donne les bons droits
RUN chown -R www-data:www-data /var/www/html