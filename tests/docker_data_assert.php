<?php

declare(strict_types=1);

require '/app/vendor/autoload.php';

function expectData(bool $valid, string $label): void
{
    if (!$valid) {
        throw new RuntimeException('Persistent data check failed: ' . $label);
    }
}

$explicit = ($argv[1] ?? '') === 'explicit';
if (!$explicit) {
    expectData(is_link('/app/.env'), 'env linked');
    expectData(is_link('/app/runtime'), 'runtime linked');
    expectData(is_link('/app/public/storage'), 'storage linked');
}
\Dotenv\Dotenv::createUnsafeMutable('/app')->load();
$auth = require '/app/config/auth.php';
foreach (['admin' => 'a', 'merchant' => 'b'] as $guard => $letter) {
    $oldSecret = str_repeat($letter, 64);
    $secret = (string) ($auth['guards'][$guard]['secret'] ?? '');
    expectData(hash_equals($oldSecret, $secret), $guard . ' effective original secret');
    // Exercise the same HMAC validation that produced the reported admin error.
    $check = new ReflectionMethod(\app\common\util\JwtTokenManager::class, 'assertHmacSecretLength');
    $check->invoke(new \app\common\util\JwtTokenManager(), $guard, $secret);
    $token = \Firebase\JWT\JWT::encode(['sub' => 'upgrade-fixture', 'exp' => time() + 60], $oldSecret, 'HS256');
    $claims = \Firebase\JWT\JWT::decode($token, new \Firebase\JWT\Key($secret, 'HS256'));
    expectData($claims->sub === 'upgrade-fixture', $guard . ' existing token verifies');
}
expectData(file_get_contents('/app/runtime/install.lock') === 'original-install-lock', 'install lock');
expectData(file_get_contents('/app/runtime/old.log') === 'original-log', 'old log');
expectData(file_get_contents('/app/runtime/storage/private/old.txt') === 'private-file', 'private file');
expectData(file_get_contents('/app/public/storage/uploads/old.txt') === 'public-file', 'public upload');
expectData(file_get_contents('/data/public/admin/index.html') === 'old-frontend-do-not-use', 'old frontend untouched');
expectData(is_file('/app/public/admin/index.html'), 'new frontend exists');
expectData(file_get_contents('/app/public/admin/index.html') !== 'old-frontend-do-not-use', 'new image frontend active');
expectData(\app\common\util\EpayPlatformKeyFile::privateKey() === 'original-private-key', 'original RSA location');
expectData(getenv('MPAY_DOCKER_SKIP_AUTO_RESTART') === '1', 'Docker restart guard');
expectData(!file_exists('/data/should-not-exist'), '.env is never sourced as shell');
file_put_contents('/app/runtime/after-upgrade.log', 'new-persistent-log');
file_put_contents('/app/public/storage/uploads/new.txt', 'new-persistent-upload');
echo "PASS: actual image config, admin/merchant JWT validation, RSA path, lock and storage\n";
