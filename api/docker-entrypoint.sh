#!/bin/sh
set -eu

cd /var/www/html

ca_file="${MYSQL_ATTR_SSL_CA:-}"
ca_configured=false
ca_exists=false
ca_readable=false
ca_parseable=false

if [ -n "$ca_file" ]; then
    ca_configured=true

    if [ -f "$ca_file" ]; then
        ca_exists=true
    fi

    if runuser -u www-data -- test -r "$ca_file"; then
        ca_readable=true
    fi

    if [ "$ca_readable" = true ] && runuser -u www-data -- php -r '
        $contents = @file_get_contents($argv[1]);
        $certificate = $contents === false ? false : @openssl_x509_read($contents);
        exit($certificate === false ? 1 : 0);
    ' "$ca_file"; then
        ca_parseable=true
    fi
fi

printf '%s\n' "[tls-ca-check] configured=$ca_configured exists=$ca_exists readable_by_www_data=$ca_readable x509_parseable=$ca_parseable" >&2

if [ "$ca_configured" != true ] || [ "$ca_exists" != true ] || [ "$ca_readable" != true ] || [ "$ca_parseable" != true ]; then
    exit 1
fi

# These commands boot Laravel and therefore must run after Render injects
# runtime secrets such as APP_KEY and PASSKEY_ENCRYPTION_KEY.
php artisan package:discover --ansi
php artisan config:cache --ansi

exec "$@"
