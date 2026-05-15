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

# Background scheduler. Laravel's schedule:work loops every minute and
# dispatches the jobs defined in app/Console/Kernel.php:
#   - roasters:import-all  daily 11:00 UTC (inventory/price refresh)
#   - alerts:restock       daily 14:00 UTC (wishlist back-in-stock emails)
# Wrapped in a restart loop so a crash doesn't silently kill auto-refresh
# (the whole point of "working automatically"). Runs as www-data so writes
# to the SQLite volume match the Apache worker ownership.
#
# NOTE: this requires the Fly machine to stay up 24/7 — see
# auto_stop_machines = 'off' in fly.toml. If the machine slept, the
# scheduler would sleep with it and the 11:00 UTC import would be missed.
(
  while true; do
    su -s /bin/sh -c "php /var/www/html/artisan schedule:work" www-data || true
    echo "[entrypoint] schedule:work exited unexpectedly; restarting in 5s" >&2
    sleep 5
  done
) &

exec apache2-foreground
