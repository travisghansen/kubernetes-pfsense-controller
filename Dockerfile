FROM php:8.2-cli-alpine

ARG TARGETPLATFORM
ARG BUILDPLATFORM

RUN echo "I am running build on $BUILDPLATFORM, building for $TARGETPLATFORM"

RUN \
    apk add --no-cache bzip2-dev \
    && docker-php-ext-install bz2 pcntl bcmath \
    && apk add --no-cache yaml-dev \
    && apk add --no-cache --virtual .phpize-deps $PHPIZE_DEPS \
    && pecl install yaml \
    && docker-php-ext-enable yaml \
    && apk del .phpize-deps

COPY releases/docker.phar /usr/local/bin/kubernetes-pfsense-controller

CMD ["kubernetes-pfsense-controller"]
