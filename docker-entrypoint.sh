#!/bin/bash
set -e

cd /var/www/html

# storage may be a mounted volume that starts out owned by root
chown -R www-data:www-data storage bootstrap/cache 2>/dev/null || true

# Krayin only treats itself as installed when a .env file exists (and the database has users);
# without it, it shows the web installer, which runs migrate:fresh. The configuration comes from
# the container environment, so an empty file is enough and never overrides a real variable.
[ -f .env ] || touch .env
chown www-data:www-data .env 2>/dev/null || true

# Clear stale config cache so runtime env vars (APP_TIMEZONE etc.) take effect
runuser -u www-data -- php artisan config:clear 2>/dev/null || true

# Opt-in: apply pending migrations on start. Back up the database before turning this on.
if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
    runuser -u www-data -- php artisan migrate --force
fi

# cron does not inherit the container environment; give the scheduler a copy of it
sh -c 'export -p' > /etc/container-env.sh
chown root:www-data /etc/container-env.sh
chmod 640 /etc/container-env.sh

service cron start

# Queue worker (WhatsApp sending). Not needed while QUEUE_CONNECTION=sync.
if [ "${QUEUE_CONNECTION:-sync}" != "sync" ] && [ "${QUEUE_WORKER:-true}" = "true" ]; then
    /usr/local/bin/queue-worker.sh &
fi

exec apache2-foreground
