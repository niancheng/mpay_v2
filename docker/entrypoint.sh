#!/bin/sh
set -eu
cd /app
# PHP handles files as data, never as shell input. Existing secrets are not regenerated.
php /app/docker/prepare-data.php
if [ -d /data ]; then
    : "${EPAY_PLATFORM_KEY_DIR:=/data/config}"
    export EPAY_PLATFORM_KEY_DIR
fi
: "${MPAY_DOCKER_SKIP_AUTO_RESTART:=1}"
export MPAY_DOCKER_SKIP_AUTO_RESTART
# No automatic installation, migrations, database writes or recursive chmod/chown.
exec "$@"
