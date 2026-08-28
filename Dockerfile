FROM composer:2 AS dependencies

WORKDIR /build
COPY composer.json ./
RUN composer install --no-dev --prefer-dist --no-interaction --no-progress --optimize-autoloader

FROM php:8.4-cli

WORKDIR /app
COPY --from=dependencies /build/vendor /app/vendor
COPY public /app/public

EXPOSE 8080
CMD ["php", "-S", "0.0.0.0:8080", "-t", "public"]
