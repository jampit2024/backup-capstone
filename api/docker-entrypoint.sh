#!/bin/sh
set -eu

cd /var/www/html

# These commands boot Laravel and therefore must run after Render injects
# runtime secrets such as APP_KEY and PASSKEY_ENCRYPTION_KEY.
php artisan package:discover --ansi
php artisan config:cache --ansi

exec "$@"
