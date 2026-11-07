<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Text;
use PHPUnit\Framework\TestCase;

final class TextTest extends TestCase
{
    public function testCountsChineseCharsAndLatinWords(): void
    {
        // 8 个汉字（观测站里有台设备）+ 数字 3 + 拉丁词 Sputnik
        self::assertSame(10, Text::countChars('观测站里有 3 台 Sputnik 设备'));
    }

    public function testIgnoresPunctuationAndWhitespace(): void
    {
        // 关键回归：\p{Han} 会把「，」「。」「、」「！」算成汉字，必须排除
        self::assertSame(4, Text::countChars("一，二。\n三、四！"));
    }

    public function testCountsSupplementaryPlaneHan(): void
    {
        // 扩展 B 区汉字（U+20000 起）也应计入
        self::assertSame(2, Text::countChars("\u{20000}\u{20001}"));
    }

    public function testNormalizeNameFoldsWidthWhitespaceAndCase(): void
    {
        self::assertSame('lihua', Text::normalizeName('  ＬｉＨｕａ '));
        self::assertSame('张三', Text::normalizeName("张 三"));
    }

    public function testUtf8PassesThrough(): void
    {
        $result = Text::toUtf8("标题\n正文");

        self::assertSame('UTF-8', $result['encoding']);
        self::assertSame("标题\n正文", $result['text']);
    }

    public function testStripsBom(): void
    {
        $result = Text::toUtf8("\xEF\xBB\xBF第一行");

        self::assertSame('UTF-8', $result['encoding']);
        self::assertSame('第一行', $result['text']);
    }

    public function testDetectsGbkAndConverts(): void
    {
        $gbk = (string) mb_convert_encoding('潮汐图书馆的盐度记录', 'GB18030', 'UTF-8');
        $result = Text::toUtf8($gbk);

        self::assertSame('GB18030', $result['encoding']);
        self::assertSame('潮汐图书馆的盐度记录', $result['text']);
    }

    public function testNormalizeBodyCollapsesBlankLines(): void
    {
        self::assertSame("甲\n\n乙", Text::normalizeBody("\r\n甲\r\n\r\n\r\n\r\n乙\r\n"));
    }

    public function testExcerptAddsEllipsisOnlyWhenTruncated(): void
    {
        self::assertSame('短句', Text::excerpt('短句', 10));
        self::assertSame('一二三…', Text::excerpt('一二三四五', 3));
    }

    // ---------------------------------------------------------------- 检索用

    public function testGramsProducesAdjacentPairs(): void
    {
        self::assertSame(['恒星', '星观', '观测'], Text::grams('恒星观测'));
    }

    public function testGramsStripsPunctuationAndSpace(): void
    {
        // 「·」「，」不能参与组词，否则「恒星·观测」会生出一个永远没人搜的 gram
        self::assertSame(['恒星', '星观', '观测'], Text::grams('恒星 · 观测'));
    }

    public function testGramsDeduplicates(): void
    {
        // 去重保留首次出现的位置：银-河 出现两次只留一条，中间还会生出 河-银
        self::assertSame(['银河', '河银', '河图'], Text::grams('银河银河图'));
    }

    public function testGramsReturnsEmptyForTooShortText(): void
    {
        self::assertSame([], Text::grams(''));
        self::assertSame([], Text::grams('星'));
        self::assertSame([], Text::grams(' · '));
    }

    public function testDistanceCountsCharactersNotBytes(): void
    {
        // 回归：内置 levenshtein() 按字节算，一个汉字会被算成 3 次编辑，
        // 于是「恒星观测」和「恒星观察」的距离会变成 6 而不是 1，
        // 相近词建议就会彻底失效。
        self::assertSame(1, Text::distance('恒星观测', '恒星观察'));
        self::assertSame(2, Text::distance('恒星观测', '恒星考查'));
    }

    public function testDistanceOfIdenticalStringsIsZero(): void
    {
        self::assertSame(0, Text::distance('蒲公英', '蒲公英'));
    }

    public function testDistanceHandlesEmptySide(): void
    {
        self::assertSame(3, Text::distance('', '蒲公英'));
        self::assertSame(3, Text::distance('蒲公英', ''));
        self::assertSame(0, Text::distance('', ''));
    }

    public function testDistanceIsSymmetric(): void
    {
        self::assertSame(
            Text::distance('空间站的一天', '空间站的一夜'),
            Text::distance('空间站的一夜', '空间站的一天')
        );
    }
}
