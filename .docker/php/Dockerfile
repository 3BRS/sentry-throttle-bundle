ARG PHP_VERSION=8.5

FROM php:${PHP_VERSION}-cli-alpine

COPY --from=mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/

RUN apk add --no-cache bash git make unzip \
    && install-php-extensions redis xdebug @composer
