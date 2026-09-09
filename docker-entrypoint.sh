#!/bin/bash
set -e

# Render injecte la variable PORT. On redirige Apache dessus.
if [ -n "$PORT" ]; then
    sed -i "s/Listen 80/Listen ${PORT}/" /etc/apache2/ports.conf
    sed -i "s/:80/:${PORT}/" /etc/apache2/sites-available/000-default.conf
fi

exec "$@"