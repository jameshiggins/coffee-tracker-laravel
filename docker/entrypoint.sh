#!/usr/bin/env bash
# Boot script for the Fly machine.
# - Ensures the SQLite file exists on the mounted volume (/data)
# - Runs migrations idempotently
# - Caches config/routes/views for production
# - Hands off to Apache in the foreground
set -euo pipefail

DB_DIR="${DB_DIR:-/data}"
DB_FILE="${DB_FILE:-${DB_DIR}/database.sqlite}"

mkdir -p "${DB_DIR}"
if [ ! -f "${DB_FILE}" ]; then
    touch "${DB_FILE}"
fi
chown -R www-data:www-data "${DB_DIR}"
chmod 664 "${DB_FILE}"

cd /var/www/html

# Migrations are safe to re-run; --force skips the prod-confirm prompt.
php artisan migrate --force

# Production caches. Re-cache on every boot so config/routes match the deployed code.
php artisan config:cache
php artisan route:cache
php artisan view:cache

exec apache2-foreground
