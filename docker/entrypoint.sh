#!/bin/sh
set -eu

cd /var/www/html

if [ "$(id -u)" -eq 0 ]; then
    mkdir -p \
        database \
        storage/app/public \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
        bootstrap/cache

    if [ ! -f database/database.sqlite ]; then
        touch database/database.sqlite
    fi

    if ! chown -R app:app storage bootstrap/cache; then
        echo "Aviso: nao foi possivel ajustar o dono de storage ou bootstrap/cache." >&2
    fi

    if ! chown app:app database/database.sqlite; then
        echo "Aviso: nao foi possivel ajustar o dono de database/database.sqlite." >&2
    fi

    if ! chmod -R u+rwX storage bootstrap/cache; then
        echo "Aviso: nao foi possivel ajustar a permissao de storage ou bootstrap/cache." >&2
    fi

    if ! chmod u+rw database/database.sqlite; then
        echo "Aviso: nao foi possivel ajustar a permissao de database/database.sqlite." >&2
    fi

    exec runuser -u app -- /usr/local/bin/entrypoint.sh "$@"
fi

if [ ! -f .env ]; then
    cp .env.example .env
fi

if [ ! -f vendor/autoload.php ]; then
    composer install --no-interaction --prefer-dist
fi

if ! grep -Eq '^APP_KEY=.+' .env; then
    php artisan key:generate --ansi --force
fi

php artisan migrate --force --no-interaction

exec "$@"
