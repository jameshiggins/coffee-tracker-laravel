# syntax=docker/dockerfile:1
# Laravel + Apache + SQLite, with persistent SQLite stored on a Fly volume at /data.
# The application code stays under /var/www/html; the database file is kept off-image.

FROM composer:2 AS composer
FROM php:8.2-apache

# System dependencies + PHP extensions Laravel needs
RUN apt-get update && apt-get install -y --no-install-recommends \
        libsqlite3-dev \
        libzip-dev \
        libonig-dev \
        libxml2-dev \
        libicu-dev \
        libpng-dev \
        libjpeg-dev \
        libfreetype6-dev \
        git \
        curl \
        unzip \
        ca-certificates \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" pdo pdo_sqlite mbstring zip exif pcntl bcmath gd intl \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer /usr/bin/composer /usr/bin/composer

# Apache: document root -> public/, port 8080 (matches fly.toml internal_port)
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public \
    APACHE_PORT=8080
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
    && sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf \
    && sed -ri "s/Listen 80\$/Listen 8080/" /etc/apache2/ports.conf \
    && sed -ri "s/<VirtualHost \*:80>/<VirtualHost *:8080>/" /etc/apache2/sites-available/000-default.conf \
    && a2enmod rewrite

WORKDIR /var/www/html

# Install PHP deps first (better cache if app code changes but composer files don't)
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --no-scripts --prefer-dist --optimize-autoloader

# App code
COPY . .

# Finish composer install (now that artisan + app are present)
RUN composer dump-autoload --optimize \
    && chown -R www-data:www-data storage bootstrap/cache

# Entrypoint: prep SQLite on /data, run migrations, cache config, start Apache
COPY docker/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

EXPOSE 8080

CMD ["/usr/local/bin/entrypoint"]
