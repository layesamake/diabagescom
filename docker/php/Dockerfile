# DIABA GESCOM — image applicative unique (PHP-FPM) et image Nginx associée.
#
# Cibles :
#   dev    : développement local (code monté en volume, composer + node inclus)
#   app    : image immuable pour dev.diaba.cloud et diaba.cloud (web, worker, scheduler)
#   nginx  : serveur HTTP interne servant public/ et relayant vers PHP-FPM
#
# Aucun secret n'est copié dans les images : la configuration provient des
# variables d'environnement injectées au démarrage (voir .dockerignore).

ARG PHP_VERSION=8.5
ARG NGINX_VERSION=1.29
ARG COMPOSER_VERSION=2.9

FROM composer:${COMPOSER_VERSION} AS composer

# ---------------------------------------------------------------------------
# base : PHP-FPM + extensions requises (OPcache est intégré depuis PHP 8.5)
# ---------------------------------------------------------------------------
FROM php:${PHP_VERSION}-fpm-alpine AS base

RUN set -eux; \
    apk add --no-cache icu-libs libpq libzip fcgi tini; \
    apk add --no-cache --virtual .build-deps $PHPIZE_DEPS icu-dev postgresql-dev libzip-dev linux-headers; \
    docker-php-ext-install -j"$(nproc)" bcmath intl pcntl pdo_pgsql pgsql zip; \
    pecl install redis; \
    docker-php-ext-enable redis; \
    apk del .build-deps; \
    rm -rf /tmp/pear ~/.pearrc

COPY docker/php/conf.d/app.ini "$PHP_INI_DIR/conf.d/zz-app.ini"
COPY docker/php/php-fpm.d/zz-app.conf /usr/local/etc/php-fpm.d/zz-app.conf
COPY --chmod=0755 docker/php/healthcheck.sh /usr/local/bin/php-fpm-healthcheck

WORKDIR /var/www/html
ENTRYPOINT ["/sbin/tini", "--"]
CMD ["php-fpm"]

# ---------------------------------------------------------------------------
# dev : outillage complet, code monté depuis l'hôte
# ---------------------------------------------------------------------------
FROM base AS dev

RUN set -eux; \
    apk add --no-cache git nodejs npm postgresql-client; \
    mv "$PHP_INI_DIR/php.ini-development" "$PHP_INI_DIR/php.ini"

COPY --from=composer /usr/bin/composer /usr/bin/composer
ENV COMPOSER_HOME=/tmp/composer

# ---------------------------------------------------------------------------
# vendor : dépendances PHP de production (installation verrouillée)
# ---------------------------------------------------------------------------
FROM base AS vendor

COPY --from=composer /usr/bin/composer /usr/bin/composer
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --no-progress

# ---------------------------------------------------------------------------
# assets : build Vite (Wayfinder a besoin de PHP pour générer les routes typées)
# ---------------------------------------------------------------------------
FROM vendor AS assets

RUN apk add --no-cache nodejs npm
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY . .
RUN set -eux; \
    composer dump-autoload --no-dev --optimize --no-scripts; \
    cp .env.example .env; \
    php artisan key:generate --force; \
    npm run build; \
    rm -f .env

# ---------------------------------------------------------------------------
# app : image de production immuable
# ---------------------------------------------------------------------------
FROM base AS app

RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY docker/php/conf.d/opcache-prod.ini "$PHP_INI_DIR/conf.d/zz-opcache-prod.ini"

COPY --chown=www-data:www-data . .
COPY --from=vendor --chown=www-data:www-data /var/www/html/vendor ./vendor
COPY --from=assets --chown=www-data:www-data /var/www/html/public/build ./public/build
COPY --from=composer /usr/bin/composer /usr/bin/composer

RUN set -eux; \
    composer dump-autoload --no-dev --optimize --classmap-authoritative; \
    rm /usr/bin/composer; \
    php artisan package:discover --ansi; \
    mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache; \
    chown -R www-data:www-data storage bootstrap/cache

ARG APP_VERSION=unknown
ENV APP_VERSION=${APP_VERSION}
LABEL org.opencontainers.image.title="diaba-gescom" \
      org.opencontainers.image.source="https://github.com/layesamake/diabagescom" \
      org.opencontainers.image.revision="${APP_VERSION}"

USER www-data
HEALTHCHECK --interval=15s --timeout=5s --start-period=20s --retries=3 CMD ["php-fpm-healthcheck"]

# ---------------------------------------------------------------------------
# nginx : fichiers statiques + proxy FastCGI vers le service "app"
# ---------------------------------------------------------------------------
FROM nginx:${NGINX_VERSION}-alpine AS nginx

COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY docker/nginx/security-headers.conf /etc/nginx/snippets/security-headers.conf
COPY --from=app /var/www/html/public /var/www/html/public
HEALTHCHECK --interval=15s --timeout=5s --retries=3 CMD ["wget", "-q", "-O", "/dev/null", "http://127.0.0.1:8080/nginx-health"]
