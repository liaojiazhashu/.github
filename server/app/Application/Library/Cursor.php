<?php

declare(strict_types=1);

namespace App\Application\Library;

/**
 * 列表游标（keyset 分页）。
 *
 * 为什么把游标藏进 URL 而不是用 `?page=3`：
 * 页数分页要靠 OFFSET，而 MySQL 5.6 上 `LIMIT 100000, 20` 会扫掉前十万行；
 * 更要紧的是「翻页时新入馆一篇」会让同一篇作品在第 2、3 页各出现一次。
 * keyset 的语义是「从这一行之后继续」，天然没有这两个问题。
 *
 * 游标是 base64url 的 JSON（排序键 + id），对读者不透明，也不含任何私有数据。
 */
final class Cursor
{
    private const MAX_LENGTH = 512;

    /**
     * @param array{k:string|int, id:int} $cursor
     */
    public static function encode(array $cursor): string
    {
        $json = (string) json_encode(['k' => $cursor['k'], 'i' => $cursor['id']], JSON_UNESCAPED_UNICODE);

        return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }

    /**
     * @return array{k:string|int, id:int}|null 非法游标一律当「第一页」，不报错
     */
    public static function decode(string $raw): ?array
    {
        $raw = trim($raw);
        if ($raw === '' || strlen($raw) > self::MAX_LENGTH) {
            return null;
        }

        $padded = strtr($raw, '-_', '+/');
        $padded .= str_repeat('=', (4 - strlen($padded) % 4) % 4);

        $json = base64_decode($padded, true);
        if ($json === false) {
            return null;
        }

        $data = json_decode($json, true);
        if (!is_array($data) || !array_key_exists('k', $data) || !isset($data['i'])) {
            return null;
        }

        $key = $data['k'];
        if (!is_string($key) && !is_int($key)) {
            return null;
        }

        $id = (int) $data['i'];
        if ($id <= 0) {
            return null;
        }

        return ['k' => $key, 'id' => $id];
    }
}
