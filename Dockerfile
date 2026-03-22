FROM php:8.4-cli-alpine

RUN apk add --no-cache \
    bash \
    git \
    libxml2-dev \
    nodejs \
    npm \
    php84-pecl-pcov && \
    echo "extension=/usr/lib/php84/modules/pcov.so" > /usr/local/etc/php/conf.d/pcov.ini && \
    echo "pcov.enabled=0" >> /usr/local/etc/php/conf.d/pcov.ini

RUN docker-php-ext-install xmlreader

RUN git config --global --add safe.directory /app

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

ENV COMPOSER_ALLOW_SUPERUSER=1

WORKDIR /app
