#!/bin/bash
set -e

cd /app

# PORT injetado pelo Railway (default 8080 em testes locais)
export PORT="${PORT:-8080}"

# Symlink do storage publico (idempotente)
php artisan storage:link --force 2>/dev/null || true

# Pastas e permissoes (o volume do Railway monta em storage/app/public)
mkdir -p storage/app/public storage/framework/cache storage/framework/sessions \
         storage/framework/views storage/logs bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache 2>/dev/null || true

# Config fresh a cada arranque (variaveis de ambiente mudam entre deploys)
php artisan config:clear >/dev/null 2>&1 || true

# Gerar config do Nginx com a PORT correta
envsubst '$PORT' < /etc/nginx/http.d/default.conf.template > /etc/nginx/http.d/default.conf

# Arrancar PHP-FPM em background e Nginx em foreground (PID 1 = nginx)
php-fpm -D
exec nginx -g 'daemon off;'
