#!/bin/bash
# Keeps a queue worker running next to Apache. --max-time makes it exit every hour so
# memory is released, and the loop brings it straight back (also after a crash).
cd /var/www/html || exit 1

while true; do
    runuser -u www-data -- php artisan queue:work --sleep=3 --tries=1 --timeout=75 --max-time=3600 --no-interaction
    sleep 2
done
