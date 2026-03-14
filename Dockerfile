FROM php:8.4-cli-alpine

RUN apk add --no-cache \
    git \
    libxml2-dev \
    nodejs \
    npm

RUN docker-php-ext-install xmlreader

RUN git config --global --add safe.directory /app

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

ENV COMPOSER_ALLOW_SUPERUSER=1

WORKDIR /app
