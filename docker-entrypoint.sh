#!/bin/sh
set -e

echo "🚀 [KADRO] Starting KADRO Production Server..."

# Ensure storage directories exist
mkdir -p /var/www/html/storage/app/manga-private \
         /var/www/html/storage/app/public \
         /var/www/html/storage/framework/cache/data \
         /var/www/html/storage/framework/sessions \
         /var/www/html/storage/framework/views \
         /var/www/html/storage/logs \
         /var/log/supervisor \
         /var/log/nginx

# Ensure correct file permissions
chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

# Bind to Railway dynamic PORT if provided
PORT="${PORT:-80}"
sed -i "s/listen 80;/listen $PORT;/g" /etc/nginx/nginx.conf
sed -i "s/listen \[::\]:80;/listen \[::\]:$PORT;/g" /etc/nginx/nginx.conf

# Run storage symlink
php artisan storage:link --force || true

# Cache configs, routes, and views for high performance
echo "⚡ [KADRO] Optimizing application caches..."
php artisan config:cache || true
php artisan route:cache || true
php artisan view:cache || true

# Run database migrations
if [ -n "$DATABASE_URL" ] || [ -n "$DB_HOST" ]; then
    echo "📦 [KADRO] Running database migrations..."
    php artisan migrate --force || echo "⚠️ Migration encountered an issue, proceeding..."

    # Ensure admin user exists
    ADMIN_EMAIL="${PLATFORM_ADMIN_EMAIL:-admin@kadro.local}"
    ADMIN_PASS="${PLATFORM_ADMIN_PASSWORD:-AdminPassword123!}"
    echo "👤 [KADRO] Ensuring platform admin account ($ADMIN_EMAIL)..."
    php artisan kadro:create-admin --email="$ADMIN_EMAIL" --password="$ADMIN_PASS" || true

    # Auto-seed popular Arabic manga and manhwa on fresh install if empty
    if [ "$AUTO_SYNC_INITIAL_MANGA" = "true" ] || [ "$AUTO_SYNC_INITIAL_MANGA" = "1" ]; then
        echo "📚 [KADRO] Ingesting initial popular Arabic manga & manhwa library..."
        php artisan kadro:sync-popular --count=6 --chapters=3 || true
    fi
fi

echo "✅ [KADRO] Ready! Launching Nginx, PHP-FPM, and Queue Worker..."
exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf
