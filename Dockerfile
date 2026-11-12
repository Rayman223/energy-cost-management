# syntax=docker/dockerfile:1
FROM composer:2@sha256:5190d9b445add2fa26090108273cb1ef1e1af98175201c737b85b3c4f90fbf7c AS composer
FROM dunglas/frankenphp:1-php8.4-bookworm@sha256:30061df7626971d597e45962269dfa7c077d972e96d1c4baf7c9259d5f7ecd55 AS base
RUN install-php-extensions pdo_mysql curl mbstring intl simplexml opcache \
    && apt-get update && apt-get install -y --no-install-recommends bash supervisor util-linux \
    && rm -rf /var/lib/apt/lists/* \
    && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
WORKDIR /app
FROM base AS build
ENV COMPOSER_ALLOW_SUPERUSER=1
COPY --from=composer /usr/bin/composer /usr/local/bin/composer
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --prefer-dist --no-interaction --no-progress --no-autoloader
COPY app app
RUN composer dump-autoload --no-dev --optimize --no-scripts && composer check-platform-reqs --no-dev
FROM base AS production
ARG EC_COMMIT
LABEL org.opencontainers.image.revision=$EC_COMMIT
ENV EC_BUILD_COMMIT=$EC_COMMIT TZ=UTC XDG_CONFIG_HOME=/app/var/caddy/config XDG_DATA_HOME=/app/var/caddy/data
COPY --from=build /app /app
COPY VERSION VERSION
COPY releases releases
COPY deploy /app/deploy
COPY deploy/Caddyfile /etc/caddy/Caddyfile
COPY deploy/php.ini /usr/local/etc/php/conf.d/zz-energy-cost.ini
COPY deploy/supervisord.conf /etc/supervisor/energy-cost.conf
RUN mkdir -p /app/var /app/logs /app/backups /app/app/config \
    && chmod -R u=rwX,go=rX /app \
    && chown www-data:www-data /app/var /app/logs /app/backups \
    && (setcap -r /usr/local/bin/frankenphp || true)
USER www-data
EXPOSE 8080
HEALTHCHECK --interval=15s --timeout=5s --start-period=30s --retries=4 \
    CMD php -r 'exit(@file_get_contents("http://127.0.0.1:8080/healthz") === "ok\n" ? 0 : 1);'
ENTRYPOINT ["bash", "/app/deploy/entrypoint.sh"]
CMD ["supervisord", "-c", "/etc/supervisor/energy-cost.conf"]
