<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

/**
 * 只读配置容器：点号取键，缺键返回默认值。
 */
final class Config
{
    /** @param array<string, mixed> $items */
    public function __construct(private readonly array $items)
    {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $value = $this->items;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }
        return $value;
    }

    public function string(string $key, string $default = ''): string
    {
        $value = $this->get($key, $default);
        return is_scalar($value) ? (string) $value : $default;
    }

    public function int(string $key, int $default = 0): int
    {
        $value = $this->get($key, $default);
        return is_numeric($value) ? (int) $value : $default;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $value = $this->get($key, $default);
        return is_bool($value) ? $value : (bool) $default;
    }

    /**
     * @return list<string>
     */
    public function list(string $key): array
    {
        $value = $this->get($key, []);
        if (!is_array($value)) {
            return [];
        }
        return array_values(array_map(static fn ($v): string => (string) $v, $value));
    }

    public function require(string $key): string
    {
        $value = $this->string($key);
        if ($value === '') {
            throw new RuntimeException(sprintf('缺少必需配置：%s', $key));
        }
        return $value;
    }
}
