# Production image (docs/deploy.md). One container runs nginx and php-fpm.
# The host nginx owns 80/443 and proxies to this container on 127.0.0.1.
#
# PHP is pinned here (CLAUDE.md §3: 8.4 or later, fixed by the image).

# ---------- 1. Resolve PHP dependencies ----------
# Run composer with the same PHP as the app: the official composer image ships
# another PHP, and composer.lock is checked against the platform.
FROM php:8.4.27-cli-alpine AS vendor

RUN apk add --no-cache git unzip

# Take only the binary from the official image (Docker verifies the digest;
# curl | php installers do not).
COPY --from=composer:2.9 /usr/bin/composer /usr/local/bin/composer

WORKDIR /app
COPY composer.json composer.lock symfony.lock ./
# No scripts yet: the application is not copied in at this point.
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction

COPY . .
RUN composer dump-autoload --optimize --no-dev --classmap-authoritative

# ---------- 2. Runtime ----------
FROM php:8.4.27-fpm-alpine AS runtime

# sqlite: the `sqlite3 .backup` step of docs/deploy.md. pdo_sqlite, ctype,
# iconv and opcache are already built into the official image.
RUN apk add --no-cache nginx supervisor sqlite

RUN { \
        echo 'memory_limit = 128M'; \
        echo 'opcache.enable = 1'; \
        echo 'opcache.validate_timestamps = 0'; \
        echo 'expose_php = Off'; \
    } > /usr/local/etc/php/conf.d/99-app.ini

# php-fpm swallows the workers' stderr unless told otherwise; the JSON logs
# (Monolog php://stderr) must reach `docker logs`. The official image already
# sets clear_env = no, so the container environment reaches PHP.
#
# listen: the official image listens on 0.0.0.0:9000, so anything on the same
# Docker network could send FastCGI directly and bypass the nginx rules (any
# .php file, PHP_VALUE). Only the nginx in this container needs it. The file
# name sorts after the image's own zz-docker.conf so that this value wins.
RUN { \
        echo '[www]'; \
        echo 'listen = 127.0.0.1:9000'; \
        echo 'catch_workers_output = yes'; \
        echo 'decorate_workers_output = no'; \
    } > /usr/local/etc/php-fpm.d/zzz-app.conf

WORKDIR /var/www/html

# The code stays root-owned: a file-write flaw in PHP must not be able to
# change it. Only var/ is writable by www-data (below).
COPY --from=vendor /app /var/www/html
RUN chmod 755 /var/www/html

# nginx workers run as www-data; the Alpine package creates these directories
# for the nginx user, and a request body over the buffer size then fails with
# "Permission denied".
RUN chown -R www-data:www-data /var/lib/nginx /var/log/nginx

COPY docker/production/nginx.conf       /etc/nginx/nginx.conf
COPY docker/production/supervisord.conf /etc/supervisord.conf
COPY docker/production/entrypoint.sh    /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

# var/data holds the SQLite file and is a volume in compose.prod.yml.
RUN mkdir -p var/data var/cache var/log var/share \
    && chown -R www-data:www-data var

EXPOSE 80

# /about is a static page: it does not need the database or the mailer.
HEALTHCHECK --interval=30s --timeout=5s --start-period=40s --retries=3 \
    CMD wget -qO- http://127.0.0.1/about > /dev/null || exit 1

ENTRYPOINT ["entrypoint"]
CMD ["supervisord", "-c", "/etc/supervisord.conf"]
