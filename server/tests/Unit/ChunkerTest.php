<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Application\Analysis\Chunker;
use PHPUnit\Framework\TestCase;

final class ChunkerTest extends TestCase
{
    private const TEXT = "第一段。这句话说明了背景。\n\n第二段。这里出现了转折，主角开始怀疑记录的真实性。\n\n第三段。终局。\n";

    public function testShortTextIsOneChunk(): void
    {
        $chunker = new Chunker(12000, 300);
        $chunks  = $chunker->split(self::TEXT);

        self::assertCount(1, $chunks);
        self::assertSame(self::TEXT, $chunks[0]['text']);
        self::assertSame(1, $chunks[0]['total']);
        self::assertSame(0, $chunks[0]['start']);
    }

    public function testChunksCoverWholeTextWithoutGaps(): void
    {
        $text    = str_repeat("这是一句话，用来凑长度。", 400);   // 约 4400 字
        $chunker = new Chunker(1000, 100);
        $chunks  = $chunker->split($text);

        self::assertGreaterThan(1, count($chunks));

        // 第一块从 0 开始，最后一块结束在全文末尾，且相邻块首尾相接或重叠
        self::assertSame(0, $chunks[0]['start']);
        self::assertSame(mb_strlen($text), $chunks[count($chunks) - 1]['end']);

        foreach ($chunks as $i => $chunk) {
            self::assertLessThanOrEqual(1000 + 200, mb_strlen($chunk['text']), '单块不应明显超过上限');
            if ($i > 0) {
                self::assertLessThanOrEqual($chunks[$i - 1]['end'], $chunk['start'], '相邻块必须相接或重叠，不能有空洞');
            }
        }
    }

    public function testDoesNotCutInsideQuotes(): void
    {
        // 整段是一句话（没有句末标点可切），只能硬切；引号恰好跨过切点。
        // 不做保护就会把「…」劈成两半：前一块只有左引号，后一块只有右引号。
        $quoted = '「' . str_repeat('话', 480) . '」' . str_repeat('尾', 30) . '。';
        self::assertSame(513, mb_strlen($quoted));

        $chunks = (new Chunker(500, 300))->split($quoted);
        self::assertCount(2, $chunks);

        // 切点被推到引号闭合之后：第一块应当成对
        self::assertSame(1, substr_count($chunks[0]['text'], '「'));
        self::assertSame(1, substr_count($chunks[0]['text'], '」'));
    }

    public function testOverlapCarriesPreviousContext(): void
    {
        $text    = implode("\n\n", array_map(static fn (int $i): string => "第{$i}段。" . str_repeat('内容', 100), range(1, 30)));
        $chunker = new Chunker(600, 200);
        $chunks  = $chunker->split($text);

        self::assertGreaterThan(1, count($chunks));
        // 重叠 = 后一块的起点落在前一块的区间内
        self::assertLessThan($chunks[0]['end'], $chunks[1]['start'], '第二块起点应前移到重叠区');
        self::assertGreaterThan($chunks[0]['start'], $chunks[1]['start']);
    }

    public function testRejectsTooSmallChunkSize(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Chunker(100, 10);
    }

    public function testNeedsSplitComparesAgainstThreshold(): void
    {
        $chunker = new Chunker();
        self::assertFalse($chunker->needsSplit(8000, 20000));
        self::assertTrue($chunker->needsSplit(20001, 20000));
    }
}
