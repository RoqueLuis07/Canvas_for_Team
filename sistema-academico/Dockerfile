# Dockerfile específico para Railway: un solo contenedor con el servidor
# embebido de Laravel — para el primer despliegue rápido en una URL nueva.
# El stack multi-contenedor (Nginx + PHP-FPM + MySQL + Redis) para Portainer
# vive en docker-compose.yml / docker/php/Dockerfile y no se toca acá.
FROM php:8.4-cli

RUN apt-get update && apt-get install -y \
    git unzip libzip-dev libpng-dev libonig-dev libsqlite3-dev libicu-dev \
 && docker-php-ext-install pdo_mysql pdo_sqlite zip gd intl mbstring \
 && pecl install redis \
 && docker-php-ext-enable redis

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html
COPY . .
RUN composer install --no-dev --optimize-autoloader --no-interaction \
 && mkdir -p database \
 && touch database/database.sqlite \
 && chmod -R 775 storage bootstrap/cache database

EXPOSE 8080
CMD php artisan migrate --force \
 && php artisan config:cache \
 && php artisan serve --host=0.0.0.0 --port=${PORT:-8080}
