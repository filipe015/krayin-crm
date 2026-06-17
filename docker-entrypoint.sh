#!/bin/bash
set -e

# Clear stale config cache so runtime env vars (APP_TIMEZONE etc.) take effect
php /var/www/html/artisan config:clear 2>/dev/null || true

exec apache2-foreground
