#!/bin/bash
set -e
cd /var/www/html

# Regenerate package discovery (composer scripts were skipped during build)
php artisan package:discover --ansi || true

# Ensure writable runtime dirs
chown -R www-data:www-data storage bootstrap/cache 2>/dev/null || true

exec "$@"
