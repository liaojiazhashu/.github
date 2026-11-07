<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Application\Analysis\TermEncyclopedia;
use PHPUnit\Framework\TestCase;

/**
 * 词条百科：详情页四要素里的人工内容。
 * 结构由 bin/check-encyclopedia.php 连库校验，这里只测加载层行为。
 *
 * 构造时不传仓储（只读文件基线）：单测不该依赖数据库，而「回落文件」本身就是
 * 这次搬迁必须守住的行为 —— 库里有就优先用库，没有就必须还能读到文件。
 */
final class TermEncyclopediaTest extends TestCase
{
    private function encyclopedia(): TermEncyclopedia
    {
        return new TermEncyclopedia();
    }

    public function testFindCanonicalTerm(): void
    {
        // termId=0：跳过库查询，直接读文件基线
        $entry = $this->encyclopedia()->find(0, '人工智能');

        self::assertNotNull($entry);
        self::assertNotSame('', $entry['history']);
        self::assertNotEmpty($entry['classics']);
        self::assertLessThanOrEqual(3, count($entry['classics']));
        foreach ($entry['classics'] as $c) {
            foreach (['title', 'author', 'year'] as $k) {
                self::assertArrayHasKey($k, $c);
            }
            self::assertArrayNotHasKey('note', $c, '词条页只列不注：经典条目不允许再长出解释');
            self::assertGreaterThan(0, $c['year']);
        }
    }

    public function testFindNormalizesWhitespaceAndCase(): void
    {
        $enc = $this->encyclopedia();

        self::assertSame($enc->find(0, '人工智能'), $enc->find(0, ' 人工智能 '));
    }

    public function testUnknownTermReturnsNull(): void
    {
        self::assertNull($this->encyclopedia()->find(0, '不存在的词条名'));
    }

    public function testEveryTermHasAtMostThreeClassics(): void
    {
        // 直接读表：任何一个键都不得超过 3 部（历史原因扩容是最常见的漂移）
        $raw = require __DIR__ . '/../../resources/taxonomy/term_encyclopedia.php';
        foreach ($raw as $canonical => $entry) {
            self::assertLessThanOrEqual(
                3,
                count($entry['classics']),
                "「{$canonical}」的 classics 超过 3 部"
            );
        }
    }
}
