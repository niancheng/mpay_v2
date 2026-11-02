<?php

declare(strict_types=1);

require dirname(__DIR__) . '/docker/prepare-data.php';

use MpayDocker\DataLayout;

$root = sys_get_temp_dir() . '/mpay-layout-tests-' . bin2hex(random_bytes(6));
mkdir($root, 0700);
$checks = 0;
$cases = 0;

function check(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) {
        throw new RuntimeException($message);
    }
    ++$checks;
}

function fixture(bool $withData = true): array
{
    global $root, $cases;
    $base = $root . '/' . ++$cases;
    $app = $base . '/app';
    $data = $base . '/data';
    mkdir($app . '/runtime', 0775, true);
    mkdir($app . '/public/storage', 0775, true);
    file_put_contents($app . '/public/index.html', 'new-image-frontend');
    if ($withData) {
        mkdir($data . '/config', 0775, true);
        mkdir($data . '/runtime', 0775, true);
        file_put_contents($data . '/config/.env', "AUTH_ADMIN_JWT_SECRET=" . str_repeat('a', 64) . "\nAUTH_MERCHANT_JWT_SECRET=" . str_repeat('b', 64) . "\n");
        file_put_contents($data . '/config/epay-platform-private.pem', 'private-fixture-never-rotate');
        file_put_contents($data . '/runtime/install.lock', '{"installed":true}');
        file_put_contents($data . '/runtime/old.log', 'keep-old-log');
    }
    return [$app, $data];
}

function rejected(callable $operation, string $contains): void
{
    try {
        $operation();
    } catch (RuntimeException $error) {
        check(str_contains($error->getMessage(), $contains), 'Wrong rejection: ' . $error->getMessage());
        return;
    }
    throw new RuntimeException('Expected rejection: ' . $contains);
}

function removeFixture(string $path): void
{
    if (is_link($path) || is_file($path)) {
        unlink($path);
    } elseif (is_dir($path)) {
        foreach (new DirectoryIterator($path) as $entry) {
            if (!$entry->isDot()) {
                removeFixture($entry->getPathname());
            }
        }
        rmdir($path);
    }
}

try {
    // User's original one-volume deployment, including the author's storage symlink.
    [$app, $data] = fixture();
    mkdir($data . '/public-storage/uploads', 0775, true);
    mkdir($data . '/public', 0775, true);
    symlink($data . '/public-storage', $data . '/public/storage');
    file_put_contents($data . '/public-storage/uploads/old.png', 'keep-upload');
    file_put_contents($data . '/public/index.html', 'old-frontend-not-for-use');
    $envHash = hash_file('sha256', $data . '/config/.env');
    $keyHash = hash_file('sha256', $data . '/config/epay-platform-private.pem');
    $layout = new DataLayout($app, $data, []);
    $messages = $layout->prepare();
    check(is_link($app . '/.env'), 'Attach env');
    check(realpath($app . '/.env') === realpath($data . '/config/.env'), 'Exact original config');
    check(realpath($app . '/runtime') === realpath($data . '/runtime'), 'Original runtime');
    check(is_file($app . '/runtime/install.lock'), 'Original install lock');
    check(file_get_contents($app . '/runtime/old.log') === 'keep-old-log', 'Logs survive');
    check(file_get_contents($app . '/public/storage/uploads/old.png') === 'keep-upload', 'Uploads survive');
    check(file_get_contents($app . '/public/index.html') === 'new-image-frontend', 'Never replace frontend');
    check(!str_contains(implode("\n", $messages), str_repeat('a', 64)), 'No JWT in logs');
    $layout->prepare();
    check(hash_file('sha256', $data . '/config/.env') === $envHash, 'Repeated startup preserves JWT');
    check(hash_file('sha256', $data . '/config/epay-platform-private.pem') === $keyHash, 'Preserve RSA');
    file_put_contents($app . '/runtime/new.log', 'new-log');
    check(is_file($data . '/runtime/new.log'), 'New logs persist');
    file_put_contents($app . '/public/storage/new.txt', 'new-upload');
    check(is_file($data . '/public-storage/new.txt'), 'New uploads persist');

    // Relative storage link, including .. resolution.
    [$app, $data] = fixture();
    mkdir($data . '/public-storage', 0775, true);
    mkdir($data . '/public', 0775, true);
    symlink('../public-storage', $data . '/public/storage');
    (new DataLayout($app, $data, []))->prepare();
    check(realpath($app . '/public/storage') === realpath($data . '/public-storage'), 'Relative storage link');

    // Independent directories: only one contains data.
    foreach ([true, false] as $canonicalWins) {
        [$app, $data] = fixture();
        mkdir($data . '/public-storage', 0775, true);
        mkdir($data . '/public/storage', 0775, true);
        $chosen = $canonicalWins ? $data . '/public-storage' : $data . '/public/storage';
        file_put_contents($chosen . '/old.txt', 'preserve');
        (new DataLayout($app, $data, []))->prepare();
        check(realpath($app . '/public/storage') === realpath($chosen), 'Choose only populated tree');
        check(file_get_contents($app . '/public/storage/old.txt') === 'preserve', 'Content preserved');
    }

    [$app, $data] = fixture();
    mkdir($data . '/public/storage', 0775, true);
    file_put_contents($data . '/public/storage/old.txt', 'legacy');
    (new DataLayout($app, $data, []))->prepare();
    check(file_get_contents($app . '/public/storage/old.txt') === 'legacy', 'Legacy-only directory');
    check(!file_exists($data . '/public-storage'), 'Do not create misleading second tree');

    [$app, $data] = fixture();
    mkdir($data . '/public', 0775, true);
    symlink('../public-storage', $data . '/public/storage');
    (new DataLayout($app, $data, []))->prepare();
    check(is_dir($data . '/public-storage'), 'Initialize known empty symlink target');

    // Do not guess when both trees hold data.
    [$app, $data] = fixture();
    mkdir($data . '/public-storage', 0775, true);
    mkdir($data . '/public/storage', 0775, true);
    file_put_contents($data . '/public-storage/a', 'a');
    file_put_contents($data . '/public/storage/b', 'b');
    rejected(fn () => (new DataLayout($app, $data, []))->prepare(), '均有数据');
    check(is_file($data . '/public-storage/a') && is_file($data . '/public/storage/b'), 'Conflict changes neither tree');

    // Explicit mounts override automatic linking, even when readonly or containing files.
    [$app, $data] = fixture();
    file_put_contents($app . '/.env', 'explicit-mounted-config');
    file_put_contents($app . '/runtime/explicit', 'runtime');
    file_put_contents($app . '/public/storage/explicit', 'storage');
    chmod($app . '/.env', 0444);
    (new DataLayout($app, $data, [$app . '/.env', $app . '/runtime', $app . '/public/storage']))->prepare();
    check(file_get_contents($app . '/.env') === 'explicit-mounted-config', 'Explicit env wins');
    check(!is_link($app . '/runtime') && is_file($app . '/runtime/explicit'), 'Explicit runtime preserved');
    check(!is_link($app . '/public/storage') && is_file($app . '/public/storage/explicit'), 'Explicit storage preserved');

    [$app, $data] = fixture(false);
    file_put_contents($app . '/.env', 'direct-env');
    (new DataLayout($app, $data, []))->prepare();
    check(file_get_contents($app . '/.env') === 'direct-env', 'No-data deployment unchanged');
    check(!is_dir($data), 'Do not invent /data');

    // First install: create an empty persistent file; installer remains the key generator.
    [$app, $data] = fixture(false);
    mkdir($data, 0775, true);
    (new DataLayout($app, $data, []))->prepare();
    check(is_link($app . '/.env') && filesize($app . '/.env') === 0, 'Fresh persistent config');
    check((fileperms($data . '/config/.env') & 0777) === 0600, 'New config private mode');
    file_put_contents($app . '/.env', 'installer-generated-config');
    check(file_get_contents($data . '/config/.env') === 'installer-generated-config', 'Installer writes through link');
    check(!is_file($data . '/runtime/install.lock'), 'No automatic install lock');
    check(!is_file($data . '/config/epay-platform-private.pem'), 'No automatic RSA generation');
    (new DataLayout($app, $data, []))->prepare();
    check(file_get_contents($data . '/config/.env') === 'installer-generated-config', 'Fresh second startup no reset');

    foreach (['missing', 'empty', 'directory', 'dangling'] as $badEnv) {
        [$app, $data] = fixture();
        unlink($data . '/config/.env');
        if ($badEnv === 'empty') { touch($data . '/config/.env'); }
        if ($badEnv === 'directory') { mkdir($data . '/config/.env'); }
        if ($badEnv === 'dangling') { symlink($data . '/absent', $data . '/config/.env'); }
        rejected(fn () => (new DataLayout($app, $data, []))->prepare(), $badEnv === 'missing' ? '安装锁' : '配置文件');
        check(is_file($data . '/runtime/install.lock'), 'Missing config never resets installed state');
    }

    [$app, $data] = fixture();
    file_put_contents($app . '/.env', 'unmounted-different-config');
    rejected(fn () => (new DataLayout($app, $data, []))->prepare(), '不会覆盖');
    check(file_get_contents($app . '/.env') === 'unmounted-different-config', 'Conflicting local env preserved');

    [$app, $data] = fixture();
    copy($data . '/config/.env', $app . '/.env');
    (new DataLayout($app, $data, []))->prepare();
    check(is_link($app . '/.env'), 'Identical config can link safely');

    [$app, $data] = fixture();
    file_put_contents($app . '/runtime/unbacked.log', 'must-not-delete');
    rejected(fn () => (new DataLayout($app, $data, []))->prepare(), '不会覆盖');
    check(file_get_contents($app . '/runtime/unbacked.log') === 'must-not-delete', 'No destructive runtime conversion');

    [$app, $data] = fixture();
    file_put_contents($app . '/public/storage/unbacked.png', 'must-not-delete');
    rejected(fn () => (new DataLayout($app, $data, []))->prepare(), '不会覆盖');
    check(file_get_contents($app . '/public/storage/unbacked.png') === 'must-not-delete', 'No destructive upload conversion');

    [$app, $data] = fixture();
    mkdir($data . '/public', 0775, true);
    symlink($data . '/config', $data . '/public/storage');
    rejected(fn () => (new DataLayout($app, $data, []))->prepare(), '非预期位置');
    check(!is_link($app . '/public/storage'), 'Never expose configuration as uploads');

    [$app, $data] = fixture();
    mkdir($data . '/public', 0775, true);
    symlink($data . '/missing-other', $data . '/public/storage');
    rejected(fn () => (new DataLayout($app, $data, []))->prepare(), '失效链接');

    // Parse Linux octal escapes without mistaking similarly prefixed paths for mounts.
    $mountinfo = $root . '/mountinfo';
    file_put_contents($mountinfo, "42 20 1:2 / /app/.env ro - ext4 /dev/root rw\n43 20 1:2 / /app/a\\040b rw - ext4 /dev/root rw\n");
    check(DataLayout::mountPaths($mountinfo) === ['/app/.env', '/app/a b'], 'Mountinfo escaped paths');
    [$app, $data] = fixture();
    file_put_contents($app . '/.env', 'conflict');
    rejected(fn () => (new DataLayout($app, $data, [$app . '/.env-other']))->prepare(), '不会覆盖');

    echo "PASS: $checks filesystem checks across $cases fixtures\n";
} finally {
    removeFixture($root);
}
