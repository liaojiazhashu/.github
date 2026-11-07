<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Application\Work\TextDiff;
use PHPUnit\Framework\TestCase;

/**
 * 版本 diff 的验收点是「人能不能一眼看出改了哪句」。
 * 所以测的是段落级配对与行内收窄，而不是"算法复杂度"。
 */
final class TextDiffTest extends TestCase
{
    public function testParagraphsDropsBlankLinesAndNormalizesBreaks(): void
    {
        $text = "第一段\r\n\r\n第二段\r第三段\n\n\n第四段\n";

        $this->assertSame(['第一段', '第二段', '第三段', '第四段'], TextDiff::paragraphs($text));
    }

    public function testSingleHugeBlobIsChunked(): void
    {
        $text = str_repeat('一', 2000);

        $paragraphs = TextDiff::paragraphs($text);

        $this->assertGreaterThan(1, count($paragraphs), '整篇没有换行时必须切块，否则 diff 没有意义');
        $this->assertSame(2000, mb_strlen(implode('', $paragraphs), 'UTF-8'), '切块不能丢字');
    }

    public function testIdenticalTextsProduceOnlyKeep(): void
    {
        $text = "甲\n乙\n丙";
        $ops  = TextDiff::compare($text, $text);

        $this->assertCount(3, $ops);
        foreach ($ops as $op) {
            $this->assertSame('keep', $op['type']);
        }

        $stats = TextDiff::stats($ops);
        $this->assertSame(['kept' => 3, 'changed' => 0, 'added' => 0, 'removed' => 0, 'old_paragraphs' => 3, 'new_paragraphs' => 3], $stats);
    }

    public function testChangedParagraphIsPairedWithItsReplacement(): void
    {
        $old = "甲\n乙\n丙";
        $new = "甲\n乙（改）\n丙";

        $ops   = TextDiff::compare($old, $new);
        $stats = TextDiff::stats($ops);

        $this->assertSame(2, $stats['kept']);
        $this->assertSame(1, $stats['changed'], '删一段又加一段应被识别为改写，而不是"一删一增"');
        $this->assertSame(0, $stats['removed']);
        $this->assertSame(0, $stats['added']);

        $changed = array_values(array_filter($ops, static fn (array $op): bool => $op['type'] === 'del' && $op['other'] !== null));
        $this->assertSame('乙', $changed[0]['text']);
        $this->assertSame('乙（改）', $changed[0]['other']);
    }

    public function testAddedParagraphIsNotPaired(): void
    {
        $ops   = TextDiff::compare("甲\n丙", "甲\n乙\n丙");
        $stats = TextDiff::stats($ops);

        $this->assertSame(2, $stats['kept']);
        $this->assertSame(1, $stats['added']);
        $this->assertSame(0, $stats['changed']);
    }

    public function testRemovedParagraphIsNotPaired(): void
    {
        $ops   = TextDiff::compare("甲\n乙\n丙", "甲\n丙");
        $stats = TextDiff::stats($ops);

        $this->assertSame(2, $stats['kept']);
        $this->assertSame(1, $stats['removed']);
        $this->assertSame(0, $stats['changed']);
    }

    public function testInlineKeepsCommonPrefixAndSuffix(): void
    {
        $seg = TextDiff::inline('他走进那间空屋，什么也没有。', '他走进那间空屋，只看见一张桌子。');

        $this->assertSame('他走进那间空屋，', $seg['prefix']);
        $this->assertSame('什么也没有', $seg['old_mid']);
        $this->assertSame('只看见一张桌子', $seg['new_mid']);
        // 两侧都以句号收尾，公共后缀会被剥出来，而不是留在高亮里
        $this->assertSame('。', $seg['suffix']);
    }

    public function testInlineKeepsTrailingIdenticalPart(): void
    {
        $seg = TextDiff::inline('开头AAA结尾', '开头BBB结尾');

        $this->assertSame('开头', $seg['prefix']);
        $this->assertSame('AAA', $seg['old_mid']);
        $this->assertSame('BBB', $seg['new_mid']);
        $this->assertSame('结尾', $seg['suffix']);
    }

    /** 极端输入不能炸：空串、完全不同、只有空白差异 */
    public function testEdgeCases(): void
    {
        $this->assertSame([], TextDiff::compare('', ''));

        $ops = TextDiff::compare("甲\n乙", "丙\n丁");
        $this->assertSame(2, TextDiff::stats($ops)['changed']);

        $this->assertSame(0, TextDiff::stats(TextDiff::compare("甲\n乙", "甲   \n乙"))['changed'], '行尾空白被 trim 掉，不算改动');
    }
}
