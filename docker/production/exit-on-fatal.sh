#!/bin/sh
# supervisord event listener: when nginx or php-fpm gives up (FATAL after the
# start retries), stop supervisord. The container then exits and
# `restart: unless-stopped` starts it again; without this, supervisord stays
# alive with a dead web server and the container is never restarted.
#
# Protocol: print READY, read a header line, read the payload (len:N bytes),
# answer RESULT 2 / OK.
while :; do
    printf 'READY\n'
    read -r header || exit 0
    len=${header##*len:}
    dd bs=1 count="$len" of=/dev/null 2>/dev/null
    kill -TERM "$(cat /run/supervisord.pid)"
    printf 'RESULT 2\nOK'
done
