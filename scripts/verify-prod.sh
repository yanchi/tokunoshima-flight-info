#!/usr/bin/env bash
#
# Production-equivalent smoke test (docs/deploy.md).
#
# Starts the real image through compose.prod.yml and exercises what unit tests
# cannot: the entrypoint (bin/release), nginx + php-fpm, file ownership on the
# volume, and the container environment reaching PHP. No external access: the
# mailer DSN is a dummy, the ODPT key is left empty, and nothing here sends or
# fetches.
#
set -euo pipefail

cd "$(dirname "$0")/.."

# A project name of its own, so a mix-up can never touch the production volume.
readonly PROJECT='tkn-verify'
readonly PORT="${VERIFY_PORT:-18098}"
readonly IMAGE='tokunoshima-flight-info:verify'
readonly BASE="http://127.0.0.1:${PORT}"

ENV_FILE="$(mktemp -t tkn-verify-env.XXXXXX)"
readonly ENV_FILE

PASSED=0
FAILED=0

ok() { # ok <label> <actual> <expected>
    if [ "$2" = "$3" ]; then
        printf '  ok  %-52s %s\n' "$1" "$2"; PASSED=$((PASSED + 1))
    else
        printf '  NG  %-52s %s (expected: %s)\n' "$1" "$2" "$3"; FAILED=$((FAILED + 1))
    fi
}

contains() { # contains <label> <haystack> <needle>
    case "$2" in
        *"$3"*) printf '  ok  %-52s %s\n' "$1" "$3"; PASSED=$((PASSED + 1)) ;;
        *) printf '  NG  %-52s does not contain: %s\n' "$1" "$3"; FAILED=$((FAILED + 1)) ;;
    esac
}

lacks() { # lacks <label> <haystack> <needle>
    case "$2" in
        *"$3"*) printf '  NG  %-52s contains: %s\n' "$1" "$3"; FAILED=$((FAILED + 1)) ;;
        *) printf '  ok  %-52s absent: %s\n' "$1" "$3"; PASSED=$((PASSED + 1)) ;;
    esac
}

compose() { docker compose -p "$PROJECT" -f compose.prod.yml --env-file "$ENV_FILE" "$@"; }
code() { curl -s -o /dev/null -w '%{http_code}' "$@"; }

cleanup() {
    local status=$?
    # -v is fine here only because this is the throwaway project.
    compose down -v --remove-orphans >/dev/null 2>&1 || true
    rm -f "$ENV_FILE"
    exit "$status"
}
trap cleanup EXIT

cat > "$ENV_FILE" <<EOT
APP_IMAGE=${IMAGE}
APP_PORT=${PORT}
APP_SECRET=$(openssl rand -hex 32)
CONTACT_RECIPIENT=operator@example.com
CONTACT_FROM=site@example.com
MAILER_DSN=smtp://user:password@smtp.example.com:587
EOT

compose down -v --remove-orphans >/dev/null 2>&1 || true

echo '== build'
docker build -q -f docker/production/Dockerfile -t "$IMAGE" . >/dev/null

echo '== nothing that must stay out of the image is in it'
leaks="$(docker run --rm --network none --entrypoint sh "$IMAGE" -c \
    'for p in docs tests .git .claude .agents .codex AGENTS.md CLAUDE.md .env.local .env.local.php .env.production access-token.txt; do [ -e "/var/www/html/$p" ] && echo "$p"; done; true')"
ok 'no docs/, tests/, .git, .env.local or token file' "$leaks" ''
ok 'application code is root-owned (not writable by www-data)' \
    "$(docker run --rm --network none --entrypoint sh "$IMAGE" -c 'stat -c %U /var/www/html/src /var/www/html/vendor /var/www/html/public/index.php /var/www/html/.env | sort -u')" 'root'

echo '== a bad configuration must not start (Issue #6)'
set +e
bad_out="$(docker run --rm -e APP_ENV=prod -e APP_DEBUG=0 -e APP_SECRET=x \
    -e DATABASE_URL=sqlite:////var/www/html/var/data/x.db \
    -e CONTACT_RECIPIENT=a@example.com -e CONTACT_FROM= -e MAILER_DSN='null://null' \
    "$IMAGE" 2>&1)"
bad_status=$?
set -e
ok 'empty CONTACT_FROM / null mailer: container exits' "$([ "$bad_status" -ne 0 ] && echo nonzero || echo "$bad_status")" 'nonzero'
contains 'the check names the settings' "$bad_out" 'CONTACT_FROM'

set +e
dev_out="$(docker run --rm -e APP_ENV=dev "$IMAGE" 2>&1)"
dev_status=$?
set -e
ok 'APP_ENV=dev: container exits' "$([ "$dev_status" -ne 0 ] && echo nonzero || echo "$dev_status")" 'nonzero'
contains 'APP_ENV not prod is refused' "$dev_out" 'APP_ENV=prod'

echo '== start through compose.prod.yml'
compose up -d >/dev/null
healthy=no
for _ in $(seq 1 40); do
    if [ "$(docker inspect -f '{{.State.Health.Status}}' "${PROJECT}-app-1" 2>/dev/null || echo none)" = healthy ]; then
        healthy=yes; break
    fi
    sleep 3
done
ok 'container becomes healthy' "$healthy" 'yes'
if [ "$healthy" != yes ]; then
    compose logs --tail=40 app 2>&1 | sed 's/^/    /'
    exit 1
fi

echo '== pages'
ok 'GET /' "$(code "$BASE/")" '200'
ok 'GET /about' "$(code "$BASE/about")" '200'
ok 'GET /sources' "$(code "$BASE/sources")" '200'
ok 'GET /contact' "$(code "$BASE/contact")" '200'
ok 'GET /flights?date=bad -> 400' "$(code "$BASE/flights?date=bad")" '400'

echo '== no leaks'
body404="$(curl -s "$BASE/no-such-page")"
ok 'GET /no-such-page' "$(code "$BASE/no-such-page")" '404'
lacks '404 page shows no exception' "$body404" 'Exception'
lacks '404 page shows no stack trace' "$body404" 'Stack Trace'
ok 'GET /.env is not served' "$(code "$BASE/.env")" '403'
ok 'GET /index.php directly is not served' "$(code "$BASE/index.php")" '404'
ok 'GET /composer.json is not served' "$(code "$BASE/composer.json")" '404'
headers="$(curl -sI "$BASE/")"
lacks 'no X-Powered-By header' "$headers" 'X-Powered-By'
lacks 'no nginx version in Server header' "$headers" 'nginx/'

echo '== environment and ownership inside the container'
# These two read the environment of the `exec` CLI process. That php-fpm gets the
# environment too is shown indirectly: /contact answers 200 only when the
# CONTACT_* settings reach PHP (it answers 503 otherwise). Whether the client
# address is taken from X-Forwarded-For is not tested here.
ok 'APP_ENV is prod (CLI environment)' "$(compose exec -T app printenv APP_ENV)" 'prod'
ok 'php-fpm listens on 127.0.0.1 only, not on all interfaces' \
    "$(compose exec -T app netstat -ltn | grep -c '0.0.0.0:9000' | tr -d '\r')" '0'
ok 'php-fpm is listening on 127.0.0.1:9000' \
    "$(compose exec -T app netstat -ltn | grep -c '127.0.0.1:9000' | tr -d '\r')" '1'
ok 'SYMFONY_TRUSTED_PROXIES is set (CLI environment)' "$(compose exec -T app php -r 'echo getenv("SYMFONY_TRUSTED_PROXIES");')" 'REMOTE_ADDR'
ok 'SQLite file is owned by www-data' "$(compose exec -T app stat -c %U /var/www/html/var/data/tkn.db)" 'www-data'
ok 'migrations created the timetable table' \
    "$(compose exec -T app sqlite3 /var/www/html/var/data/tkn.db "select count(*) from sqlite_master where name='timetable_flight'" | tr -d '\r')" '1'
ok 'app:check-deploy-config passes' \
    "$(compose exec -T -u www-data app php bin/console app:check-deploy-config >/dev/null 2>&1 && echo pass || echo fail)" 'pass'
ok 'cron path: sync command exists' \
    "$(compose exec -T -u www-data app php bin/console list app:timetable 2>/dev/null | grep -c 'app:timetable:sync' | tr -d '\r')" '1'

echo '== the database survives recreating the container'
compose exec -T -u www-data app sqlite3 /var/www/html/var/data/tkn.db \
    "create table verify_marker (v text); insert into verify_marker values ('kept')"
# down WITHOUT -v: the volume stays, the container is created anew
compose down >/dev/null
compose up -d >/dev/null
wait_healthy() {
    for _ in $(seq 1 40); do
        if [ "$(docker inspect -f '{{.State.Health.Status}}' "${PROJECT}-app-1" 2>/dev/null || echo none)" = healthy ]; then
            return 0
        fi
        sleep 3
    done
    return 1
}
ok 'healthy after recreating the container' "$(wait_healthy && echo yes || echo no)" 'yes'
ok 'the row written before is still there' \
    "$(compose exec -T app sqlite3 /var/www/html/var/data/tkn.db 'select v from verify_marker' | tr -d '\r')" 'kept'

echo '== root-owned leftovers (a `compose exec` without -u) must not break the next start'
compose exec -T app sh -c 'mkdir -p var/cache/prod && touch var/cache/prod/root-owned var/log/root-owned.log'
compose restart app >/dev/null
ok 'healthy after restart with root-owned files in var/' "$(wait_healthy && echo yes || echo no)" 'yes'
ok 'GET / after restart' "$(code "$BASE/")" '200'

echo
printf 'passed: %d / failed: %d\n' "$PASSED" "$FAILED"
[ "$FAILED" -eq 0 ]
