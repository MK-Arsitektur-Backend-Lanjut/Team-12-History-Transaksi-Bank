FROM php:8.3-fpm AS base

RUN apt-get update \
    && DEBIAN_FRONTEND=noninteractive apt-get install -y \
        git \
        unzip \
        libzip-dev \
        libpng-dev \
        libicu-dev \
        sqlite3 \
        libsqlite3-dev \
        libonig-dev \
    && docker-php-ext-install pdo pdo_mysql pdo_sqlite zip intl opcache \
    && pecl install redis \
    && docker-php-ext-enable redis

COPY docker/php/zz-docker.conf /usr/local/etc/php-fpm.d/zz-custom.conf
COPY docker/php/opcache.ini /usr/local/etc/php/conf.d/opcache.ini


# install composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# copy composer files first for layer caching
COPY composer.json composer.lock ./

RUN composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader --no-scripts

# copy application
COPY . /var/www/html

RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache || true

EXPOSE 9000
CMD ["php-fpm"]
