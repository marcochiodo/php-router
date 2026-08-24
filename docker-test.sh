#!/bin/sh

# Runs the test suite inside a disposable php:8.5-cli-alpine container.
# No local PHP required.

docker run --rm \
    -v "$PWD":/usr/src/app \
    -w /usr/src/app \
    --name mrblue-php-router \
    php:8.5-cli-alpine \
    sh -c '
        set -e
        php composer.phar install --no-interaction
        vendor/bin/phpunit
    '
