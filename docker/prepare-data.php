<?php

declare(strict_types=1);

namespace MpayDocker;

use RuntimeException;
use Throwable;

/** Attach the v2.1.2 persistent layout without loading, rewriting or logging secrets. */
final class DataLayout
{
    /** @param list<string>|null $mounts Explicit mount points; injectable for filesystem tests. */
    public function __construct(
        private readonly string $app,
        private readonly string $data,
        private readonly ?array $mounts = null,
    ) {
    }

    /** @return list<string> Non-sensitive startup messages. */
    public function prepare(): array
    {
        if (!is_dir($this->data)) {
            if (is_link($this->data) || file_exists($this->data)) {
                throw new RuntimeException('数据目录不可用，拒绝回退到临时配置。');
            }
            $this->directory($this->app . '/runtime');
            $this->directory($this->app . '/public/storage');
            return ['未挂载 /data，使用原有 /app 显式挂载方式。'];
        }

        // Serialize initialization only. This does not touch the database or run migrations.
        $lock = @fopen($this->data . '/.mpay-layout.lock', 'c');
        if ($lock === false) {
            throw new RuntimeException('/data 无法创建启动锁，请检查挂载和写权限。');
        }
        try {
            if (!flock($lock, LOCK_EX)) {
                throw new RuntimeException('无法锁定 /data 初始化。');
            }
            return $this->attach();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @return list<string> */
    private function attach(): array
    {
        $messages = [];
        $env = $this->app . '/.env';
        $storedEnv = $this->data . '/config/.env';
        $runtime = $this->app . '/runtime';
        $storedRuntime = $this->data . '/runtime';
        $storage = $this->app . '/public/storage';
        $installed = is_file($storedRuntime . '/install.lock') || is_file($runtime . '/install.lock');

        // A direct /app bind remains authoritative, including the temporary workaround.
        if ($this->mounted($env)) {
            $this->readableFile($env, $installed);
            $messages[] = '保留 /app/.env 显式挂载。';
        } else {
            $this->directory($this->data . '/config');
            if (!file_exists($storedEnv) && !is_link($storedEnv)) {
                if ($installed) {
                    throw new RuntimeException('检测到安装锁但 /data/config/.env 缺失；请恢复原配置，禁止重新安装或自动换密钥。');
                }
                // Refuse to silently discard an unmounted local configuration.
                if (file_exists($env) || is_link($env)) {
                    throw new RuntimeException('/app/.env 已存在但未显式挂载；请先备份并统一配置来源。');
                }
                $oldMask = umask(0077);
                try {
                    $handle = @fopen($storedEnv, 'x');
                    if ($handle === false) {
                        throw new RuntimeException('无法创建首次安装使用的持久化 .env。');
                    }
                    fclose($handle);
                } finally {
                    umask($oldMask);
                }
                // Deliberately empty: only the existing installer generates JWT/RSA keys.
            }
            $this->readableFile($storedEnv, $installed);
            $this->link($env, $storedEnv, false);
            $messages[] = '已接入 /data/config/.env；保留原配置和 JWT 密钥。';
        }

        if ($this->mounted($runtime)) {
            if (!is_dir($runtime)) {
                throw new RuntimeException('/app/runtime 显式挂载不是目录。');
            }
            $messages[] = '保留 /app/runtime 显式挂载。';
        } else {
            $this->directory($storedRuntime);
            $this->link($runtime, $storedRuntime, true);
            $messages[] = '已接入 /data/runtime（安装锁、日志、私有文件）。';
        }

        if ($this->mounted($storage)) {
            if (!is_dir($storage)) {
                throw new RuntimeException('/app/public/storage 显式挂载不是目录。');
            }
            $messages[] = '保留 /app/public/storage 显式挂载。';
        } else {
            $source = $this->storageSource();
            $this->link($storage, $source, true);
            $messages[] = '已接入上传目录：' . $source . '；前端仍使用镜像内版本。';
        }
        return $messages;
    }

    /** Select one upload tree; never merge two populated trees or mount the old frontend. */
    private function storageSource(): string
    {
        $canonical = $this->data . '/public-storage';
        $legacy = $this->data . '/public/storage';
        foreach ([$canonical, $legacy] as $path) {
            if (is_link($path) && !is_dir($path)) {
                // The author's storage link may precede creation of its empty target.
                if ($path === $legacy && $this->linkTarget($path) === $canonical
                    && !file_exists($canonical) && !is_link($canonical)) {
                    $this->directory($canonical);
                } else {
                    throw new RuntimeException('上传目录存在失效链接，请恢复目标后重试：' . $path);
                }
            }
            if (file_exists($path) && !is_dir($path)) {
                throw new RuntimeException('上传路径不是目录：' . $path);
            }
        }

        if (is_dir($canonical) && is_dir($legacy)) {
            if (realpath($canonical) === realpath($legacy)) {
                return $this->safeStorage($canonical);
            }
            $first = !$this->emptyDirectory($canonical);
            $second = !$this->emptyDirectory($legacy);
            if ($first && $second) {
                throw new RuntimeException('/data/public-storage 与 /data/public/storage 均有数据且不是同一目录；请核对后显式挂载正确的 /app/public/storage，程序不会合并或删除。');
            }
            return $this->safeStorage($second ? $legacy : $canonical);
        }
        if (is_dir($legacy)) {
            return $this->safeStorage($legacy);
        }
        $this->directory($canonical);
        return $this->safeStorage($canonical);
    }

    private function safeStorage(string $path): string
    {
        $root = realpath($this->data);
        $resolved = realpath($path);
        // A malformed legacy symlink must never expose /data/config or runtime/private.
        if ($root === false || !in_array($resolved, [$root . '/public-storage', $root . '/public/storage'], true)) {
            throw new RuntimeException('上传目录链接指向非预期位置，拒绝公开该目录。');
        }
        return (string) $resolved;
    }

    private function linkTarget(string $path): string
    {
        $value = readlink($path);
        if ($value === false) {
            return '';
        }
        $parts = explode('/', str_starts_with($value, '/') ? $value : dirname($path) . '/' . $value);
        $normal = [];
        foreach ($parts as $part) {
            if ($part === '..') {
                array_pop($normal);
            } elseif ($part !== '' && $part !== '.') {
                $normal[] = $part;
            }
        }
        return '/' . implode('/', $normal);
    }

    /** Replace only an absent path, an empty image directory, or an identical file. */
    private function link(string $destination, string $source, bool $directory): void
    {
        if (realpath($destination) !== false && realpath($destination) === realpath($source)) {
            return;
        }
        if (is_link($destination)) {
            throw new RuntimeException('应用路径已有不同链接，拒绝覆盖：' . $destination);
        }
        if (file_exists($destination)) {
            if ($directory && is_dir($destination) && $this->emptyDirectory($destination)) {
                if (!@rmdir($destination)) {
                    throw new RuntimeException('无法替换空目录（可能是未识别的挂载点）：' . $destination);
                }
            } elseif (!$directory && is_file($destination)
                && hash_file('sha256', $destination) === hash_file('sha256', $source)) {
                if (!@unlink($destination)) {
                    throw new RuntimeException('无法接入相同的配置文件：' . $destination);
                }
            } else {
                throw new RuntimeException('应用路径已有不同文件或非空目录，请先备份并显式选择挂载；不会覆盖：' . $destination);
            }
        }
        if (!@symlink($source, $destination)) {
            throw new RuntimeException('无法创建持久化链接：' . $destination);
        }
        clearstatcache(true, $destination);
    }

    private function readableFile(string $path, bool $nonEmpty): void
    {
        if (!is_file($path) || !is_readable($path) || ($nonEmpty && filesize($path) === 0)) {
            throw new RuntimeException('配置文件缺失、不可读或已安装配置为空：' . $path);
        }
    }

    private function directory(string $path): void
    {
        if (is_dir($path)) {
            return;
        }
        if (is_link($path) || file_exists($path) || !@mkdir($path, 0775, true)) {
            throw new RuntimeException('目录不可用或无法创建：' . $path);
        }
    }

    private function emptyDirectory(string $path): bool
    {
        $entries = @scandir($path);
        if ($entries === false) {
            throw new RuntimeException('无法检查目录，拒绝覆盖：' . $path);
        }
        return count(array_diff($entries, ['.', '..'])) === 0;
    }

    private function mounted(string $path): bool
    {
        foreach ($this->mounts ?? self::mountPaths() as $mount) {
            if (($mount === $this->app || str_starts_with($mount, $this->app . '/'))
                && ($mount === $path || str_starts_with($path, $mount . '/'))) {
                return true;
            }
        }
        return false;
    }

    /** @return list<string> Linux mountinfo paths (including escaped spaces/backslashes). */
    public static function mountPaths(string $file = '/proc/self/mountinfo'): array
    {
        $lines = @file($file, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            throw new RuntimeException('无法读取挂载信息，拒绝猜测并覆盖现有目录。');
        }
        $paths = [];
        foreach ($lines as $line) {
            $parts = explode(' ', $line);
            if (isset($parts[4])) {
                $paths[] = preg_replace_callback('/\\\\([0-7]{3})/',
                    static fn (array $m): string => chr(octdec($m[1])), $parts[4]);
            }
        }
        return $paths;
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try {
        foreach ((new DataLayout(dirname(__DIR__), '/data'))->prepare() as $message) {
            fwrite(STDERR, '[MPAY 启动] ' . $message . PHP_EOL);
        }
    } catch (Throwable $error) {
        fwrite(STDERR, '[MPAY 启动失败] ' . $error->getMessage() . PHP_EOL);
        exit(1);
    }
}
