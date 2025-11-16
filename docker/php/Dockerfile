FROM php:8.2-fpm

# Устанавливаем расширения
RUN docker-php-ext-install pdo pdo_mysql

# Composer
COPY --from=composer:2.6 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html
