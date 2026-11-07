<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Totp;
use PHPUnit\Framework\TestCase;

/**
 * RFC 6238 附录 B 的测试向量（SHA-1，密钥为 ASCII "12345678901234567890"）。
 * 6 位取 8 位结果的后 6 位。
 */
final class TotpTest extends TestCase
{
    private const SECRET_BASE32 = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

    public function testDecodesBase32(): void
    {
        self::assertSame('12345678901234567890', Totp::base32Decode(self::SECRET_BASE32));
    }

    public function testMatchesRfc6238Vectors(): void
    {
        $key = Totp::base32Decode(self::SECRET_BASE32);

        $cases = [
            59          => '287082',
            1111111109  => '081804',
            1111111111  => '050471',
            1234567890  => '005924',
            2000000000  => '279037',
        ];

        foreach ($cases as $time => $expected) {
            $step = intdiv($time, 30);
            self::assertSame($expected, Totp::code($key, $step), "T={$time} 的验证码不符");
        }
    }

    public function testRejectsWrongLengthAndNonDigits(): void
    {
        self::assertFalse(Totp::verify(self::SECRET_BASE32, '12345'));
        self::assertFalse(Totp::verify(self::SECRET_BASE32, 'abcdef'));
        self::assertFalse(Totp::verify(self::SECRET_BASE32, ''));
    }

    public function testAcceptsAdjacentWindow(): void
    {
        $key  = Totp::base32Decode(self::SECRET_BASE32);
        $now  = (int) floor(time() / 30);
        $code = Totp::code($key, $now + 1); // 客户端时钟快了 30 秒以内

        self::assertTrue(Totp::verify(self::SECRET_BASE32, $code, 1));
    }

    public function testGeneratedSecretIs20Bytes(): void
    {
        $secret = Totp::generateSecret();

        self::assertSame(32, strlen($secret), '160 bit 的 base32 应恰好 32 个字符');
        self::assertSame(20, strlen(Totp::base32Decode($secret)));
    }
}
