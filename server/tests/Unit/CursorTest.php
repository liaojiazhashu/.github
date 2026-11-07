<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Application\Library\Cursor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * keyset 分页游标。
 *
 * 这层的全部风险都在「decode 拿到脏数据」：游标就在 URL 里，谁都能改。
 * 原则是**一律当第一页**，绝不抛错 —— 一个手改坏的链接不该给读者 500。
 */
final class CursorTest extends TestCase
{
    public function testRoundTripsStringKey(): void
    {
        $decoded = Cursor::decode(Cursor::encode(['k' => '2026-10-28 22:00:00', 'id' => 42]));

        self::assertSame(['k' => '2026-10-28 22:00:00', 'id' => 42], $decoded);
    }

    public function testRoundTripsIntKey(): void
    {
        $decoded = Cursor::decode(Cursor::encode(['k' => 1200, 'id' => 7]));

        self::assertSame(['k' => 1200, 'id' => 7], $decoded);
    }

    public function testEncodedCursorIsUrlSafe(): void
    {
        $token = Cursor::encode(['k' => '蒲公英·第一段/二', 'id' => 1]);

        // base64 的 + / = 会破坏查询串，这里必须是 base64url
        self::assertDoesNotMatchRegularExpression('/[+\/=]/', $token);
    }

    public function testPreservesUtf8Key(): void
    {
        // JSON_UNESCAPED_UNICODE：中文标题直接进游标，不转成 \uXXXX 才不会被误判为超长
        $token = Cursor::encode(['k' => '宇宙尽头', 'id' => 3]);

        self::assertStringContainsString('宇宙尽头', (string) base64_decode(
            strtr($token, '-_', '+/') . str_repeat('=', (4 - strlen($token) % 4) % 4)
        ));
        self::assertSame(['k' => '宇宙尽头', 'id' => 3], Cursor::decode($token));
    }

    #[DataProvider('garbageProvider')]
    public function testGarbageDecodesToNull(string $raw): void
    {
        self::assertNull(Cursor::decode($raw));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function garbageProvider(): array
    {
        return [
            '空串'            => [''],
            '只有空白'        => ['   '],
            '不是 base64'     => ['!!!not-base64!!!'],
            'base64 但不是 JSON' => [rtrim(strtr(base64_encode('hello'), '+/', '-_'), '=')],
            'JSON 但不是对象' => [rtrim(strtr(base64_encode('"x"'), '+/', '-_'), '=')],
            '缺 id'           => [rtrim(strtr(base64_encode('{"k":"a"}'), '+/', '-_'), '=')],
            '缺 k'            => [rtrim(strtr(base64_encode('{"i":3}'), '+/', '-_'), '=')],
            'id 为 0'         => [rtrim(strtr(base64_encode('{"k":"a","i":0}'), '+/', '-_'), '=')],
            'id 为负数'       => [rtrim(strtr(base64_encode('{"k":"a","i":-5}'), '+/', '-_'), '=')],
            'k 是数组'        => [rtrim(strtr(base64_encode('{"k":["a"],"i":3}'), '+/', '-_'), '=')],
        ];
    }

    public function testOverlongTokenIsRejected(): void
    {
        // 512 字符上限：防止有人拿一个几十 KB 的游标让 base64 解码和哈希拖慢请求
        self::assertNull(Cursor::decode(str_repeat('A', 600)));
    }
}
