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

COPY --from=composer:latest@sha256:0c8e663f14c94d07b3c45b175c51f5a14718508f21fe4dd7e54f09488dd0ed71 /usr/bin/composer /usr/bin/composer

ENV COMPOSER_ALLOW_SUPERUSER=1

WORKDIR /app
