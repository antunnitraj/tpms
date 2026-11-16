#!/bin/sh
# Generate APP_KEY on first start and keep it in the storage volume, so
# `docker compose up` works without any configuration. An APP_KEY passed in
# the environment always wins.
if [ -n "${APP_KEY:-}" ]; then
    exit 0
fi

KEY_FILE=/var/www/html/storage/app/.app_key

if [ ! -s "$KEY_FILE" ]; then
    mkdir -p "$(dirname "$KEY_FILE")"
    php -r 'echo "base64:".base64_encode(random_bytes(32));' > "$KEY_FILE"
    chmod 600 "$KEY_FILE"
    echo "🔑 Generated an application key (stored in the storage volume)."
fi

echo "APP_KEY=$(cat "$KEY_FILE")" > /var/www/html/.env
