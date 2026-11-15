FROM php:8.3-cli-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git \
        unzip \
        libonig-dev \
        libsqlite3-dev \
        libzip-dev \
    && if ! php -m | grep -qi '^mbstring$'; then docker-php-ext-install mbstring; fi \
    && if ! php -m | grep -qi '^pdo_sqlite$'; then docker-php-ext-install pdo_sqlite; fi \
    && if ! php -m | grep -qi '^zip$'; then docker-php-ext-install zip; fi \
    && command -v runuser >/dev/null \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2.10.3 /usr/bin/composer /usr/bin/composer

ARG UID=1000
ARG GID=1000

RUN groupadd --gid "${GID}" --non-unique app \
    && useradd --uid "${UID}" --gid "${GID}" --non-unique --create-home --shell /bin/sh app

WORKDIR /var/www/html

COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 8000

ENTRYPOINT ["entrypoint.sh"]
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
