<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Config;
use App\Support\Crypto;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class CryptoTest extends TestCase
{
    private function crypto(?string $kek = null): Crypto
    {
        return new Crypto(new Config([
            'app' => ['kek' => $kek ?? base64_encode(random_bytes(32))],
        ]));
    }

    public function testSealOpenRoundTrip(): void
    {
        $crypto = $this->crypto();
        $plain  = 'JBSWY3DPEHPK3PXP';

        $sealed = $crypto->seal($plain);

        self::assertNotSame($plain, $sealed);
        self::assertSame($plain, $crypto->open($sealed));
    }

    public function testCiphertextIsNotDeterministic(): void
    {
        $crypto = $this->crypto();

        self::assertNotSame($crypto->seal('same'), $crypto->seal('same'), '每次加密的 nonce 必须不同');
    }

    public function testTamperedCiphertextIsRejected(): void
    {
        $crypto = $this->crypto();
        $raw    = base64_decode($crypto->seal('secret'), true);
        $raw[strlen($raw) - 1] = chr(ord($raw[strlen($raw) - 1]) ^ 0x01);

        $this->expectException(RuntimeException::class);
        $crypto->open(base64_encode($raw));
    }

    public function testWrongKeyIsRejected(): void
    {
        $sealed = $this->crypto()->seal('secret');

        $this->expectException(RuntimeException::class);
        $this->crypto()->open($sealed);
    }

    public function testRejectsBadKek(): void
    {
        $this->expectException(RuntimeException::class);
        $this->crypto('too-short');
    }

    public function testDigestIsStableAndKeyed(): void
    {
        $a = new Crypto(new Config(['app' => ['kek' => base64_encode(str_repeat('a', 32))]]));
        $b = new Crypto(new Config(['app' => ['kek' => base64_encode(str_repeat('b', 32))]]));

        self::assertSame($a->digest('127.0.0.1'), $a->digest('127.0.0.1'));
        self::assertNotSame($a->digest('127.0.0.1'), $b->digest('127.0.0.1'), '不同密钥的摘要必须不同');
        self::assertSame(64, strlen($a->digest('127.0.0.1')));
    }
}
