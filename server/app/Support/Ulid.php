<?php

declare(strict_types=1);

namespace App\Support;

/**
 * ULID（Crockford Base32，26 字符，字典序 = 时间序）。
 *
 * 为什么不用自增 ID 对外：一是暴露业务量，二是并发插入的时序在 URL 上可推算。
 * 为什么不用 UUIDv4：随机串做聚簇索引会打散 InnoDB 页，写入放大。
 *
 * 单调性：同一毫秒内随机部分递增，保证同毫秒内生成的 ID 仍可按字典序排序。
 */
final class Ulid
{
    private const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    private static int $lastMs = 0;

    private static string $lastRandom = '';

    public static function generate(): string
    {
        $ms = (int) floor(microtime(true) * 1000);

        if ($ms === self::$lastMs && self::$lastRandom !== '') {
            self::$lastRandom = self::increment(self::$lastRandom);
        } else {
            self::$lastMs     = $ms;
            self::$lastRandom = self::encodeRandom();
        }

        return self::encodeTime($ms) . self::$lastRandom;
    }

    public static function isValid(string $value): bool
    {
        return preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $value) === 1;
    }

    private static function encodeTime(int $ms): string
    {
        $out = '';
        for ($i = 0; $i < 10; $i++) {
            $out = self::ALPHABET[$ms % 32] . $out;
            $ms  = intdiv($ms, 32);
        }
        return $out;
    }

    private static function encodeRandom(): string
    {
        $bytes = random_bytes(16);
        $out   = '';
        for ($i = 0; $i < 16; $i++) {
            $out .= self::ALPHABET[ord($bytes[$i]) % 32];
        }
        return $out;
    }

    private static function increment(string $random): string
    {
        $chars = str_split($random);
        for ($i = 15; $i >= 0; $i--) {
            $pos = strpos(self::ALPHABET, $chars[$i]);
            if ($pos === false) {
                break;
            }
            if ($pos < 31) {
                $chars[$i] = self::ALPHABET[$pos + 1];
                return implode('', $chars);
            }
            $chars[$i] = self::ALPHABET[0];
        }
        // 整串溢出（同毫秒跑满 32^16 次，不可能）：退化为新随机串
        return self::encodeRandom();
    }
}
