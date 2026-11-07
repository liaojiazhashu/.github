<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Ulid;
use PHPUnit\Framework\TestCase;

final class UlidTest extends TestCase
{
    public function testFormat(): void
    {
        $ulid = Ulid::generate();

        self::assertSame(26, strlen($ulid));
        self::assertTrue(Ulid::isValid($ulid));
        // 排除 I / L / O / U，避免与 1 / 0 混淆
        self::assertSame(0, preg_match('/[ILOU]/', $ulid));
    }

    public function testIsLexicographicallySortableByTime(): void
    {
        $first = Ulid::generate();
        usleep(3000);
        $second = Ulid::generate();

        self::assertLessThan($second, $first, '先产生的 ULID 字典序必须更小');
    }

    public function testMonotonicWithinSameMillisecond(): void
    {
        $batch = [];
        for ($i = 0; $i < 500; $i++) {
            $batch[] = Ulid::generate();
        }

        $sorted = $batch;
        sort($sorted, SORT_STRING);
        self::assertSame($batch, $sorted, '同毫秒内连发 500 个也必须保持递增');
    }

    public function testUniqueness(): void
    {
        $seen = [];
        for ($i = 0; $i < 2000; $i++) {
            $seen[Ulid::generate()] = true;
        }

        self::assertCount(2000, $seen);
    }

    public function testRejectsInvalid(): void
    {
        self::assertFalse(Ulid::isValid('too-short'));
        self::assertFalse(Ulid::isValid('01ARZ3NDEKTSV4RRFFQ69G5FAI')); // 含字母 I
        self::assertFalse(Ulid::isValid(str_repeat('A', 25)));
    }
}
