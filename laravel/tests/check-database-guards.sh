#!/bin/sh
set -eu

# This script constructs a deliberately stale cache only in the runner's disposable /tmp.
test "${APP_CONFIG_CACHE:-}" = /tmp/config.php
php tests/artisan.php env > /dev/null

reject() {
    if "$@" > /tmp/donqer-rejected-command.log 2>&1; then
        cat /tmp/donqer-rejected-command.log
        echo 'ERROR: an unsafe testing command returned success.' >&2
        exit 1
    fi
    # Require our safety rejection, rather than accepting an unrelated command failure.
    if ! grep -m 1 'Testing database safety:' /tmp/donqer-rejected-command.log; then
        cat /tmp/donqer-rejected-command.log
        exit 1
    fi
}

reject env DB_HOST=postgres php artisan migrate:fresh --force
reject env DB_DATABASE=donqer_lab php artisan db:wipe --force
reject env DB_CONNECTION=sqlite php artisan migrate:fresh --force
reject env DB_URL=pgsql://other-host/other-db php artisan migrate:fresh --force
reject env APP_ENV=local php tests/artisan.php migrate:fresh --force
reject php artisan migrate:fresh --database=sqlite --force

env APP_ENV=local php artisan config:cache > /tmp/donqer-cache-command.log 2>&1
reject env APP_ENV=testing php artisan migrate:fresh --force
reject sh -c 'unset APP_ENV; php artisan --env=testing migrate:fresh --force'
rm /tmp/config.php
