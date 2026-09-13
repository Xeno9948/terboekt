#!/bin/sh
set -eu
php backend/bin/migrate.php
php backend/bin/sync-calendars.php || true
port="${PORT:-8080}"
exec php -S "0.0.0.0:${port}" -t public public/router.php
