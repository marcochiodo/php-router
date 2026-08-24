#!/bin/sh

docker run -it --rm \
    -v "$PWD":/usr/src/app \
    -w /usr/src/app \
    --name mrblue-php-router \
    php:8.5-cli-alpine \
    sh