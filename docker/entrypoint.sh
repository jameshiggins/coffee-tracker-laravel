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

# Data-safety: snapshot the SQLite DB BEFORE migrating. The only irreplaceable
# data (user tastings) lives in this single file on the volume; a bad or
# irreversible migration is otherwise unrecoverable — an image rollback does
# NOT revert a schema change already applied to the volume. Keep the 7 most
# recent snapshots. Guarded so a backup hiccup never blocks boot.
if [ -s "${DB_FILE}" ]; then
    BACKUP_DIR="${DB_DIR}/backups"
    mkdir -p "${BACKUP_DIR}"
    if cp "${DB_FILE}" "${BACKUP_DIR}/database-$(date +%Y%m%d-%H%M%S).sqlite"; then
        echo "[entrypoint] DB snapshot written before migrate."
    else
        echo "[entrypoint] WARNING: pre-migrate DB snapshot failed." >&2
    fi
    # Prune to the 7 most recent snapshots (|| true so pipefail can't block boot).
    ls -1t "${BACKUP_DIR}"/database-*.sqlite 2>/dev/null | tail -n +8 | xargs -r rm -f || true
fi

# Migrations are safe to re-run; --force skips the prod-confirm prompt.
php artisan migrate --force

# Production caches. Re-cache on every boot so config/routes match the deployed code.
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Seed the scheduler heartbeat so GET /up doesn't false-alarm in the brief
# gap before schedule:work's first tick. The scheduler then refreshes it
# every few minutes; if it dies, the value goes stale and /up flips to 503.
php artisan ops:heartbeat scheduler.tick || true

# Background queue worker. Processes the `database` queue (QUEUE_CONNECTION):
# roaster imports dispatched from the admin console + queued transactional
# mail (digests, restock alerts). Without it, those jobs would pile up unrun.
# Same restart-loop + www-data ownership as the scheduler below.
(
  while true; do
    su -s /bin/sh -c "php /var/www/html/artisan queue:work --queue=default --sleep=3 --tries=3 --max-time=3600" www-data || true
    echo "[entrypoint] queue:work exited unexpectedly; restarting in 5s" >&2
    sleep 5
  done
) &

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
