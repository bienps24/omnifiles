#!/bin/sh
# One image, two Railway services:
#   web    (default)      → migrations + bot setup, then Apache on $PORT
#   worker (ROLE=worker)  → background worker
set -e
cd /var/www/app

if [ "$ROLE" = "worker" ]; then
    exec php bin/worker.php
fi

# PHP needs Apache's prefork MPM; make sure it is the only one enabled
rm -f /etc/apache2/mods-enabled/mpm_event.load /etc/apache2/mods-enabled/mpm_event.conf \
      /etc/apache2/mods-enabled/mpm_worker.load /etc/apache2/mods-enabled/mpm_worker.conf
[ -e /etc/apache2/mods-enabled/mpm_prefork.load ] || a2enmod mpm_prefork >/dev/null

PORT="${PORT:-8080}"
sed -ri "s/^Listen .*/Listen ${PORT}/" /etc/apache2/ports.conf
sed -ri "s/<VirtualHost \*:[0-9]+>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/000-default.conf

php bin/setup.php || echo "setup failed, starting web anyway"
exec apache2-foreground
