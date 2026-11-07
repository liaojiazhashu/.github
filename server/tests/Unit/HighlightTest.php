<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Application\Library\Highlight;
use PHPUnit\Framework\TestCase;

/**
 * 检索高亮的切片。
 *
 * 这里要守住的核心不变式只有一条：**把 segments 的 text 拼回去必须等于原文**。
 * 只要这条成立，模板里循环输出就不可能丢字或重字；至于有没有命中，
 * 是次要的（最多高亮得不够好看）。
 */
final class HighlightTest extends TestCase
{
    private static function join(array $segments): string
    {
        return implode('', array_column($segments, 'text'));
    }

    public function testSplitsAroundSingleHit(): void
    {
        $segments = Highlight::segments('第七次日出', '日出');

        self::assertSame('第七次日出', self::join($segments));
        self::assertSame(['第七次', '日出'], array_column($segments, 'text'));
        self::assertSame([false, true], array_column($segments, 'hit'));
    }

    public function testHandlesMultipleHits(): void
    {
        $segments = Highlight::segments('日出而作，日入而息', '日');

        self::assertSame('日出而作，日入而息', self::join($segments));
        self::assertSame(2, count(array_filter(array_column($segments, 'hit'))));
    }

    public function testLeadingAndTrailingHits(): void
    {
        $segments = Highlight::segments('恒星的恒星', '恒星');

        self::assertSame('恒星的恒星', self::join($segments));
        self::assertSame(2, count(array_filter(array_column($segments, 'hit'))));
    }

    public function testIsCaseInsensitive(): void
    {
        // Kepler-1849c 这类英文名，读者不会记得大小写
        $segments = Highlight::segments('Kepler-1849c 是一颗 Kepler 行星', 'kepler');

        self::assertSame(2, count(array_filter(array_column($segments, 'hit'))));
    }

    public function testReturnsWholeTextWhenNeedleMissing(): void
    {
        $segments = Highlight::segments('蒲公英', '恒星');

        self::assertSame([['text' => '蒲公英', 'hit' => false]], $segments);
    }

    public function testEmptyNeedleReturnsWholeText(): void
    {
        self::assertSame([['text' => '蒲公英', 'hit' => false]], Highlight::segments('蒲公英', '   '));
    }

    public function testEmptyTextIsSafe(): void
    {
        self::assertSame([['text' => '', 'hit' => false]], Highlight::segments('', '日出'));
    }

    public function testStopsAtMaxHitsAndKeepsRemainder(): void
    {
        $text     = str_repeat('日', 100);
        $segments = Highlight::segments($text, '日', 3);

        // 命中上限只是不再切分，**剩下的字一个都不能丢**
        self::assertSame($text, self::join($segments));
        self::assertSame(3, count(array_filter(array_column($segments, 'hit'))));
    }

    public function testNeedleLongerThanTextIsNotAHit(): void
    {
        $segments = Highlight::segments('日出', '日出东方');

        self::assertSame('日出', self::join($segments));
        self::assertSame(0, count(array_filter(array_column($segments, 'hit'))));
    }

    public function testHtmlInQueryIsNotInterpreted(): void
    {
        // 高亮层不生成 HTML，所以这里只可能出现「命中」标记，绝不出现标签
        $segments = Highlight::segments('<script>alert(1)</script> 与恒星', '<script>');

        foreach ($segments as $segment) {
            self::assertArrayNotHasKey('html', $segment);
        }
        self::assertSame('<script>alert(1)</script> 与恒星', self::join($segments));
    }

    public function testExcerptCentersOnHit(): void
    {
        $text    = str_repeat('甲', 200) . '恒星观测' . str_repeat('乙', 200);
        $excerpt = Highlight::excerptAround($text, '恒星观测', 20);

        self::assertStringContainsString('恒星观测', $excerpt);
        self::assertStringStartsWith('…', $excerpt);
        self::assertStringEndsWith('…', $excerpt);
        // 半径 20 → 正文最多 40 字，加上两端的省略号
        self::assertLessThanOrEqual(42, mb_strlen($excerpt, 'UTF-8'));
    }

    public function testExcerptFallsBackToHeadWhenNoHit(): void
    {
        $excerpt = Highlight::excerptAround(str_repeat('甲', 200), '恒星', 20);

        // 没命中就取开头 2×radius 字，再加一个省略号
        self::assertStringEndsWith('…', $excerpt);
        self::assertSame(41, mb_strlen($excerpt, 'UTF-8'));
    }

    public function testShortTextIsReturnedAsIs(): void
    {
        self::assertSame('蒲公英', Highlight::excerptAround('蒲公英', '恒星', 48));
    }
}
