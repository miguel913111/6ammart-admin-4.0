#!/bin/bash
set -e

# Wait a moment for environment variables to be available
sleep 2

# Ensure storage and cache directories exist and are writable
mkdir -p /var/www/html/storage/app/public \
         /var/www/html/storage/framework/cache \
         /var/www/html/storage/framework/sessions \
         /var/www/html/storage/framework/testing \
         /var/www/html/storage/framework/views \
         /var/www/html/storage/logs \
         /var/www/html/bootstrap/cache

chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache
chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache

# Create symbolic link for public storage
php artisan storage:link 2>/dev/null || true

# Cache Laravel configuration, routes and views
php artisan config:cache 2>/dev/null || true
php artisan route:cache 2>/dev/null || true
php artisan view:cache 2>/dev/null || true

# Run migrations (only if database is configured)
php artisan migrate --force 2>/dev/null || true

# Start Apache in foreground
exec apache2-foreground
