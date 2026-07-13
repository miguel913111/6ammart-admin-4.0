# ============================================================
# 6amMart 4.0 (NEXFOOD) — Deploy Railway
# Nginx + PHP-FPM (multi-worker) em vez de "php artisan serve"
# ============================================================

# ---------- Stage 1: dependencias PHP (composer) ----------
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-interaction --prefer-dist --ignore-platform-reqs
COPY . .
RUN composer dump-autoload --optimize --no-dev

# ---------- Stage 2: runtime (Nginx + PHP-FPM) ----------
FROM php:8.2-fpm-alpine

# Extensoes PHP necessarias (composer: curl, gd, json, simplexml, zip + Laravel)
RUN apk add --no-cache \
        nginx bash curl gettext \
        icu-libs libzip libpng libjpeg-turbo libwebp freetype oniguruma libcurl \
    && apk add --no-cache --virtual .build-deps \
        icu-dev libzip-dev libpng-dev libjpeg-turbo-dev libwebp-dev freetype-dev oniguruma-dev curl-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_mysql mbstring gd zip bcmath intl exif opcache curl \
    && apk del .build-deps

# Config PHP
RUN { \
      echo 'upload_max_filesize=20M'; \
      echo 'post_max_size=20M'; \
      echo 'memory_limit=512M'; \
      echo 'max_execution_time=60'; \
      echo 'opcache.enable=1'; \
      echo 'opcache.validate_timestamps=0'; \
    } > /usr/local/etc/php/conf.d/zz-railway.ini

# Pool PHP-FPM (mais workers que o default de 5)
COPY docker/php-fpm-pool.conf /usr/local/etc/php-fpm.d/zz-railway.conf

# Nginx
COPY docker/nginx.conf.template /etc/nginx/http.d/default.conf.template
RUN rm -f /etc/nginx/http.d/default.conf

# Codigo da aplicacao
WORKDIR /app
COPY --from=vendor /app /app

# Permissoes Laravel
RUN mkdir -p storage/app/public storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

# Entrypoint
COPY docker/start.sh /start.sh
RUN chmod +x /start.sh

EXPOSE 8080
CMD ["/start.sh"]
