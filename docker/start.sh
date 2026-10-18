#!/bin/sh
# Container start: listen on Render's $PORT, migrate, cache config, start the
# queue worker (Excel imports) and then Apache in the foreground.
set -e

PORT="${PORT:-8080}"
sed -i "s/^Listen .*/Listen ${PORT}/" /etc/apache2/ports.conf
sed -i "s/<VirtualHost \*:[0-9]*>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/000-default.conf

# Only the pending migrations; each one skips itself on a database built from schema.sql.
php artisan migrate --force

php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

chown -R www-data:www-data storage bootstrap/cache

# Restarts the worker whenever it stops (it exits every hour to free memory).
su -s /bin/sh www-data -c '
    while true; do
        php artisan queue:work --tries=1 --sleep=3 --max-time=3600 || sleep 5
    done
' &

exec apache2-foreground
