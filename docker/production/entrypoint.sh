#!/bin/sh
# Runs bin/release (settings check, migrations, cache) as the web user, then
# hands over to supervisord. If bin/release fails the container exits, so a bad
# configuration never starts serving (Issue #6).
set -eu

# A volume mounted over var/data starts out owned by root. Fix ownership of
# everything under var/ as root, and drop the old cache: files left by a
# `docker compose exec` run as root would otherwise make bin/release (www-data)
# fail, and `restart: unless-stopped` would then loop until the container is
# recreated.
mkdir -p var/data var/cache var/log var/share
rm -rf var/cache/prod
chown -R www-data:www-data var

echo "[entrypoint] running bin/release"
# `su` without `-` keeps the container environment (busybox). bin/release checks
# APP_ENV / APP_DEBUG itself, so a lost environment fails here, not silently.
su -s /bin/sh www-data -c '/var/www/html/bin/release'

echo "[entrypoint] starting"
exec "$@"
