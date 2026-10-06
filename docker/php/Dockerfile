# syntax=docker/dockerfile:1

# ---------------------------------------------------------------------------
# BUFFALO PHP image.
#
# Targets:
#   dev   - PHP-FPM for local development (Xdebug installed, off by default).
#   prod  - Self-contained image with vendor/ and built frontend assets.
#           Production configuration is finalised in Phase 9.
#
# Every base image is pinned to an exact version tag.
# ---------------------------------------------------------------------------

FROM composer:2.10.3 AS composer
FROM node:24.21.0-trixie-slim AS node-glibc

# ---------------------------------------------------------------------------
# base: PHP-FPM with the extensions BUFFALO needs, running as a non-root user.
# ---------------------------------------------------------------------------
FROM php:8.4.26-fpm-trixie AS base

ARG UID=1000
ARG GID=1000

ENV PHP_OPCACHE_VALIDATE_TIMESTAMPS=1 \
    COMPOSER_HOME=/tmp/composer \
    COMPOSER_CACHE_DIR=/tmp/composer/cache

# Runtime libraries stay installed; -dev headers and compilers are purged
# once the extensions are built.
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        libfcgi-bin \
        libfreetype6 \
        libicu76 \
        libjpeg62-turbo \
        libpng16-16t64 \
        libpq5 \
        libwebp7 \
        libzip5 \
        unzip; \
    buildDeps=" \
        libfreetype-dev \
        libicu-dev \
        libjpeg62-turbo-dev \
        libpng-dev \
        libpq-dev \
        libwebp-dev \
        libzip-dev \
        $PHPIZE_DEPS"; \
    apt-get install -y --no-install-recommends $buildDeps; \
    docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp; \
    docker-php-ext-install -j"$(nproc)" \
        bcmath \
        exif \
        gd \
        intl \
        opcache \
        pcntl \
        pdo_pgsql \
        pgsql \
        zip; \
    pecl install redis-6.3.0; \
    docker-php-ext-enable redis; \
    rm -rf /tmp/pear; \
    apt-get purge -y --auto-remove -o APT::AutoRemove::RecommendsImportant=false $buildDeps; \
    rm -rf /var/lib/apt/lists/*; \
    php -m | grep -qi '^redis$'

COPY --from=composer /usr/bin/composer /usr/local/bin/composer

COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-buffalo.ini
COPY docker/php/opcache.ini /usr/local/etc/php/conf.d/zz-opcache.ini
COPY docker/php/fpm-pool.conf /usr/local/etc/php-fpm.d/zz-buffalo.conf
COPY --chmod=0755 docker/php/entrypoint.sh /usr/local/bin/buffalo-entrypoint

# Non-root user matching the host developer's UID/GID so files written to the
# bind-mounted source tree keep the right ownership.
RUN set -eux; \
    # macOS hosts use GID 20, which already exists in Debian; reuse it.
    getent group "${GID}" > /dev/null || groupadd --gid "${GID}" buffalo; \
    useradd --uid "${UID}" --gid "${GID}" --create-home --shell /bin/bash buffalo; \
    mkdir -p /var/www/html /var/lib/buffalo/evidence /var/lib/buffalo/reports /var/lib/buffalo/quarantine /tmp/composer; \
    chown -R "${UID}:${GID}" /var/www/html /var/lib/buffalo /tmp/composer; \
    chmod 0750 /var/lib/buffalo/evidence /var/lib/buffalo/reports /var/lib/buffalo/quarantine

WORKDIR /var/www/html

HEALTHCHECK --interval=10s --timeout=5s --start-period=10s --retries=5 \
    CMD SCRIPT_NAME=/ping SCRIPT_FILENAME=/ping REQUEST_METHOD=GET \
        cgi-fcgi -bind -connect 127.0.0.1:9000 | grep -q pong || exit 1

EXPOSE 9000

# ---------------------------------------------------------------------------
# dev: adds Xdebug, disabled unless XDEBUG_MODE is set (e.g. XDEBUG_MODE=debug).
# ---------------------------------------------------------------------------
FROM base AS dev

# poppler-utils (pdftotext, dev and CI images only, never production): the
# report tests read the text of generated PDFs to check their markings.
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends poppler-utils $PHPIZE_DEPS; \
    pecl install xdebug-3.5.3; \
    docker-php-ext-enable xdebug; \
    rm -rf /tmp/pear; \
    apt-get purge -y --auto-remove -o APT::AutoRemove::RecommendsImportant=false $PHPIZE_DEPS; \
    rm -rf /var/lib/apt/lists/*

COPY docker/php/xdebug.ini /usr/local/etc/php/conf.d/zz-xdebug.ini

ENV XDEBUG_MODE=off

USER buffalo

CMD ["php-fpm"]

# ---------------------------------------------------------------------------
# ci: dev image plus Node.js, so scripts/ci.sh can run PHP and frontend checks
# in one container (`make ci`). Node comes from the Debian (glibc) image.
# ---------------------------------------------------------------------------
FROM dev AS ci

USER root
COPY --from=node-glibc /usr/local/bin/node /usr/local/bin/node
COPY --from=node-glibc /usr/local/lib/node_modules /usr/local/lib/node_modules
RUN set -eux; \
    ln -s ../lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm; \
    ln -s ../lib/node_modules/npm/bin/npx-cli.js /usr/local/bin/npx; \
    node --version; npm --version
USER buffalo

# ---------------------------------------------------------------------------
# vendor: production Composer dependencies and generated Wayfinder routes.
# ---------------------------------------------------------------------------
FROM base AS vendor

USER buffalo

COPY --chown=buffalo composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --no-scripts --no-autoloader --prefer-dist

COPY --chown=buffalo . .
RUN set -eux; \
    composer dump-autoload --no-dev --optimize --classmap-authoritative --no-interaction; \
    php artisan wayfinder:generate --with-form

# ---------------------------------------------------------------------------
# frontend: builds the Vite assets. Wayfinder output comes from the vendor
# stage because this image has no PHP.
# ---------------------------------------------------------------------------
FROM node:24.21.0-alpine3.24 AS frontend

WORKDIR /app

COPY package.json package-lock.json .npmrc ./
RUN npm ci --no-audit --no-fund

COPY . .
COPY --from=vendor /var/www/html/resources/js/actions resources/js/actions
COPY --from=vendor /var/www/html/resources/js/routes resources/js/routes
COPY --from=vendor /var/www/html/resources/js/wayfinder resources/js/wayfinder

ENV WAYFINDER_COMMAND=true
RUN npm run build

# ---------------------------------------------------------------------------
# prod: application code, production vendor/ and built assets.
# ---------------------------------------------------------------------------
FROM base AS prod

# Start-up checks in production (secrets, key fingerprints): Phase 8A.
ENTRYPOINT ["buffalo-entrypoint"]

ENV PHP_OPCACHE_VALIDATE_TIMESTAMPS=0 \
    APP_ENV=production \
    APP_DEBUG=false

COPY --from=vendor --chown=buffalo /var/www/html /var/www/html
COPY --from=frontend --chown=buffalo /app/public/build /var/www/html/public/build

USER buffalo

CMD ["php-fpm"]
