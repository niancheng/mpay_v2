#!/usr/bin/env bash
set -euo pipefail
image=${1:-mpay:ci}
tests=$(cd "$(dirname "$0")" && pwd)
root=$(mktemp -d)
# Fresh /data files are created by container root; remove them through an isolated
# cleanup mount instead of assuming the host runner can unlink root-owned files.
cleanup() {
    docker run --rm --platform "${MPAY_TEST_PLATFORM:-linux/amd64}" \
        --mount "type=bind,src=$root,dst=/test-cleanup" --entrypoint sh "$image" \
        -c 'find /test-cleanup -mindepth 1 -maxdepth 1 -exec rm -rf -- {} +' >/dev/null 2>&1 || true
    rmdir "$root" 2>/dev/null || true
}
trap 'status=$?; cleanup; exit "$status"' EXIT
fixture="$root/original"
mkdir -p "$fixture/config" "$fixture/runtime/storage/private" "$fixture/public-storage/uploads" "$fixture/public/admin"
printf 'AUTH_ADMIN_JWT_SECRET=%s\nAUTH_MERCHANT_JWT_SECRET=%s\n' "$(printf 'a%.0s' {1..64})" "$(printf 'b%.0s' {1..64})" > "$fixture/config/.env"
printf "UNTRUSTED_VALUE='\$(touch /data/should-not-exist)'\n" >> "$fixture/config/.env"
printf original-private-key > "$fixture/config/epay-platform-private.pem"
printf original-install-lock > "$fixture/runtime/install.lock"
printf original-log > "$fixture/runtime/old.log"
printf private-file > "$fixture/runtime/storage/private/old.txt"
printf public-file > "$fixture/public-storage/uploads/old.txt"
printf old-frontend-do-not-use > "$fixture/public/admin/index.html"
ln -s /data/public-storage "$fixture/public/storage"
chmod 600 "$fixture/config/.env"
(cd "$fixture" && sha256sum config/.env config/epay-platform-private.pem runtime/install.lock runtime/old.log runtime/storage/private/old.txt public-storage/uploads/old.txt public/admin/index.html) > "$root/before.sha"

# Only /data is mounted, exactly like the author's v2.1.2 deployment.
# Each docker run creates a different container, covering recreation as well as restart.
for attempt in 1 2; do
    docker run --rm --platform "${MPAY_TEST_PLATFORM:-linux/amd64}" --mount "type=bind,src=$fixture,dst=/data" \
        --mount "type=bind,src=$tests/docker_data_assert.php,dst=/tmp/check-data.php,readonly" \
        "$image" php /tmp/check-data.php
    (cd "$fixture" && sha256sum --check "$root/before.sha")
done
test "$(cat "$fixture/runtime/after-upgrade.log")" = new-persistent-log
test "$(cat "$fixture/public-storage/uploads/new.txt")" = new-persistent-upload

# Keep compatibility with the explicitly mounted workaround, including readonly .env.
docker run --rm --platform "${MPAY_TEST_PLATFORM:-linux/amd64}" --mount "type=bind,src=$fixture,dst=/data" \
    --mount "type=bind,src=$fixture/config/.env,dst=/app/.env,readonly" \
    --mount "type=bind,src=$fixture/runtime,dst=/app/runtime" \
    --mount "type=bind,src=$fixture/public-storage,dst=/app/public/storage" \
    --mount "type=bind,src=$tests/docker_data_assert.php,dst=/tmp/check-data.php,readonly" \
    "$image" php /tmp/check-data.php explicit
(cd "$fixture" && sha256sum --check "$root/before.sha")

# Installed but missing configuration: reject, never silently regenerate secrets.
mv "$fixture/config/.env" "$root/saved-env"
if docker run --rm --platform "${MPAY_TEST_PLATFORM:-linux/amd64}" --mount "type=bind,src=$fixture,dst=/data" "$image" true > "$root/missing.log" 2>&1; then
    echo 'FAIL: accepted installed data without its configuration'; exit 1
fi
grep -q '安装锁' "$root/missing.log"
test ! -e "$fixture/config/.env"
mv "$root/saved-env" "$fixture/config/.env"

# Ambiguous independent upload trees: reject without modifying either.
rm "$fixture/public/storage"
mkdir "$fixture/public/storage"
printf conflicting-upload > "$fixture/public/storage/conflict.txt"
if docker run --rm --platform "${MPAY_TEST_PLATFORM:-linux/amd64}" --mount "type=bind,src=$fixture,dst=/data" "$image" true > "$root/conflict.log" 2>&1; then
    echo 'FAIL: accepted ambiguous upload trees'; exit 1
fi
grep -q '均有数据' "$root/conflict.log"
test "$(cat "$fixture/public/storage/conflict.txt")" = conflicting-upload
(cd "$fixture" && sha256sum --check "$root/before.sha")

# New empty data volume remains installable and does not auto-generate keys or a lock.
mkdir "$root/fresh"
docker run --rm --platform "${MPAY_TEST_PLATFORM:-linux/amd64}" --mount "type=bind,src=$root/fresh,dst=/data" "$image" php -r '
    if (!is_link("/app/.env") || filesize("/app/.env") !== 0 || is_file("/app/runtime/install.lock")) exit(1);
    if (is_file("/data/config/epay-platform-private.pem")) exit(2);
    file_put_contents("/app/.env", "installer-writes-persist\n");
'
test "$(cat "$root/fresh/config/.env")" = installer-writes-persist
echo 'PASS: real Docker /data upgrade, recreation, readonly explicit mounts, failure guards and fresh install layout'
