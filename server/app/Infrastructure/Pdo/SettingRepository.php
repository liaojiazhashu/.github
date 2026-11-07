<?php

declare(strict_types=1);

namespace App\Infrastructure\Pdo;

use PDO;

/**
 * 系统配置读写（hq_setting）。只放**非密钥**的运行时开关与预算，
 * 密钥一律在 .env（数据库里出现密钥就等于备份包里出现密钥）。
 */
final class SettingRepository
{
    /** @var array<string, string> 单次请求内缓存，避免同一请求反复读同一行 */
    private array $cache = [];

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function get(string $key, ?string $default = null): ?string
    {
        if (array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }

        $stmt = $this->pdo->prepare('SELECT v FROM hq_setting WHERE k = :k LIMIT 1');
        $stmt->execute([':k' => $key]);
        $value = $stmt->fetchColumn();

        if ($value === false) {
            return $default;
        }

        return $this->cache[$key] = (string) $value;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $value = $this->get($key);
        if ($value === null) {
            return $default;
        }

        return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
    }

    public function float(string $key, float $default = 0.0): float
    {
        $value = $this->get($key);

        return $value === null || !is_numeric($value) ? $default : (float) $value;
    }

    public function int(string $key, int $default = 0): int
    {
        $value = $this->get($key);

        return $value === null || !is_numeric($value) ? $default : (int) $value;
    }

    public function set(string $key, string $value): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO hq_setting (k, v, updated_at) VALUES (:k, :v, NOW())
             ON DUPLICATE KEY UPDATE v = VALUES(v), updated_at = NOW()'
        );
        $stmt->execute([':k' => $key, ':v' => $value]);

        $this->cache[$key] = $value;
    }
}
