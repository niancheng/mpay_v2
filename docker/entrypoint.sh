#!/bin/sh
set -eu
cd /app
mkdir -p runtime public/storage
# Do not run migrations, create an install lock, or overwrite the user's .env automatically.
# Upgrades must stop old processes, back up MySQL, then explicitly run `php webman migrate`.
exec "$@"
