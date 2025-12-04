#!/bin/sh
set -e

# install dependencies
composer install --prefer-dist --no-progress --no-interaction

# Wait for db to be ready
until php wait-for-db.php; do
    echo 'Waiting for DB...'
    sleep 2
done

exec "$@"
