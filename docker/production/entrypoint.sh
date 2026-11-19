#!/bin/sh
# Runs bin/release (settings check, migrations, cache) as the web user, then
# hands over to supervisord. If bin/release fails the container exits, so a bad
# configuration never starts serving (Issue #6).
set -eu

# A volume mounted over var/data starts out owned by root.
mkdir -p var/data var/cache var/log var/share
chown www-data:www-data var var/data var/cache var/log var/share

echo "[entrypoint] running bin/release"
# `su` without `-` keeps the container environment (busybox). bin/release checks
# APP_ENV / APP_DEBUG itself, so a lost environment fails here, not silently.
su -s /bin/sh www-data -c '/var/www/html/bin/release'

echo "[entrypoint] starting"
exec "$@"
