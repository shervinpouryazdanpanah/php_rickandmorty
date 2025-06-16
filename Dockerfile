FROM php:8.0-fpm

RUN apt-get update && apt-get install -y \
    libzip-dev \
    zip \
    unzip \
    curl \
    && docker-php-ext-install pdo pdo_mysql

WORKDIR /app
COPY . /app
