FROM php:8.4-fpm

# libreoffice-writer: conversión docx→PDF para el módulo de Actas (ver
# comentario equivalente en el Dockerfile de Railway, raíz del proyecto).
RUN apt-get update && apt-get install -y --no-install-recommends \
    git unzip libzip-dev libpng-dev libonig-dev libicu-dev \
    libreoffice-writer fonts-dejavu \
 && docker-php-ext-install pdo_mysql zip gd intl mbstring \
 && pecl install redis \
 && docker-php-ext-enable redis \
 && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html
COPY . .
RUN composer install --no-dev --optimize-autoloader --no-interaction \
 && chown -R www-data:www-data storage bootstrap/cache \
 && chmod -R 775 storage bootstrap/cache

COPY docker/php/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 9000
ENTRYPOINT ["entrypoint.sh"]
CMD ["php-fpm"]
