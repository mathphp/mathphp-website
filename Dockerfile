FROM composer:2 AS dependencies

WORKDIR /build
COPY composer.json composer.lock ./
RUN composer install --no-dev --prefer-dist --no-interaction --no-progress --optimize-autoloader

FROM composer:2

WORKDIR /app
ARG BUILD_ID=units-refresh
RUN echo "MathPHP website build ${BUILD_ID}"
COPY --from=dependencies /build/vendor /app/vendor
COPY public /app/public
COPY docker-entrypoint.sh /app/docker-entrypoint.sh
RUN chmod 0755 /app/docker-entrypoint.sh

EXPOSE 8080
ENTRYPOINT ["/app/docker-entrypoint.sh"]
