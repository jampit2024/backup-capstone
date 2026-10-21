FROM composer:2 AS vendor

WORKDIR /app

COPY . .

RUN composer install \
    --no-dev \
    --no-interaction \
    --no-progress \
    --no-scripts \
    --prefer-dist \
    --optimize-autoloader

FROM php:8.4-apache

ENV APACHE_DOCUMENT_ROOT=/var/www/html/public

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libcurl4-openssl-dev \
        libicu-dev \
        libonig-dev \
        libxml2-dev \
        libzip-dev \
        passwd \
        util-linux \
    && docker-php-ext-install -j"$(nproc)" \
        curl \
        intl \
        mbstring \
        opcache \
        pdo_mysql \
        xml \
        zip \
    && if getent group 1000 >/dev/null; then \
        usermod -a -G 1000 www-data; \
    else \
        groupadd --gid 1000 render-secrets \
        && usermod -a -G render-secrets www-data; \
    fi \
    && a2enmod headers rewrite \
    && sed -i 's/^Listen 80$/Listen 10000/' /etc/apache2/ports.conf \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html

COPY --chown=www-data:www-data . .
COPY --from=vendor --chown=www-data:www-data /app/vendor ./vendor
COPY apache.conf /etc/apache2/sites-available/000-default.conf
COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint

RUN mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod +x /usr/local/bin/docker-entrypoint

EXPOSE 10000

ENTRYPOINT ["/usr/local/bin/docker-entrypoint"]
CMD ["apache2-foreground"]
