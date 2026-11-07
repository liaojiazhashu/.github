<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Application\Pipeline\OneClick;
use App\Application\Source\AuthorActivity;
use App\Application\Source\SourceIngest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * 一键拆解：范围收敛与正文清洗。
 *
 * 这两个都是**错了不会报错**的地方：
 *   · 范围收敛把「三期」收敛成一期 → 少拆两期，页面上显示的一切都正常；
 *   · 正文清洗把段落粘成一句 → 拆解质量下降，但任务照样成功、照样扣钱。
 * 所以它们必须在这里被钉住，而不是靠跑一次 1300 篇去发现。
 */
final class OneClickScopeTest extends TestCase
{
    /**
     * @param array<string,mixed> $raw
     * @return array<string,mixed>
     */
    private static function scope(array $raw): array
    {
        return OneClick::normalizeScope($raw);
    }

    public function testIssuesAreDedupedAndSorted(): void
    {
        $scope = self::scope(['issues' => ['3', 1, '3', 0, -2, 26]]);

        $this->assertSame([1, 3, 26], $scope['issues']);
    }

    public function testSingleIssueFromStringIsAccepted(): void
    {
        // 表单只有一期时，浏览器会把 issues[] 退化成普通字段而不是数组
        $this->assertSame([7], self::scope(['issues' => '7'])['issues']);
    }

    public function testIssueOutOfRangeIsKeptButBoundedByNothingElse(): void
    {
        // 期次号不在这里做 1..26 的裁剪：馆里将来会有第 30 期，
        // 裁掉会变成「什么都没选」——那比多一个查不到的值更危险（静默变成全部）
        $this->assertSame([99], self::scope(['issues' => [99]])['issues']);
    }

    public function testUnknownAuthorStateFallsBackToAll(): void
    {
        $this->assertSame('all', self::scope(['author_state' => 'zombieee'])['author_state']);
        $this->assertSame(AuthorActivity::ZOMBIE, self::scope(['author_state' => 'zombie'])['author_state']);
        $this->assertSame(AuthorActivity::ACTIVE, self::scope(['author_state' => 'active'])['author_state']);
    }

    public function testUnknownStageFallsBackToTodo(): void
    {
        // 默认必须是「还没拆的」：默认值选成 all，一次误点就把已上架的全重跑一遍
        $this->assertSame('todo', self::scope([])['stage']);
        $this->assertSame('todo', self::scope(['stage' => 'everything'])['stage']);
        $this->assertSame('failed', self::scope(['stage' => 'failed'])['stage']);
    }

    public function testWordRangeIsSwappedWhenReversed(): void
    {
        $scope = self::scope(['min_words' => 20000, 'max_words' => 3000]);

        $this->assertSame(3000, $scope['min_words']);
        $this->assertSame(20000, $scope['max_words']);
    }

    public function testLimitIsClampedToBatchCeiling(): void
    {
        $this->assertSame(OneClick::MAX_TARGETS, self::scope(['limit' => 99999])['limit']);
        $this->assertSame(1, self::scope(['limit' => 0])['limit']);
        $this->assertSame(1, self::scope(['limit' => -5])['limit']);
    }

    public function testKeywordIsTrimmedAndCapped(): void
    {
        $scope = self::scope(['keyword' => '  ' . str_repeat('虫', 100) . '  ']);

        $this->assertSame(60, mb_strlen($scope['keyword']));
        $this->assertSame('', self::scope(['keyword' => '   '])['keyword']);
    }

    public function testForceIsBoolean(): void
    {
        $this->assertFalse(self::scope([])['force']);
        $this->assertTrue(self::scope(['force' => '1'])['force']);
        $this->assertTrue(self::scope(['force' => true])['force']);
    }

    /**
     * 正文清洗：块级标签换行、脚本样式剔除、实体解码顺序。
     *
     * @return list<array{0:string, 1:string}>
     */
    public static function htmlCases(): array
    {
        return [
            '段落不粘连' => ['<p>第一段</p><p>第二段</p>', "第一段\n第二段"],
            'br 换行'    => ['a<br>b', "a\nb"],
            '脚本剔除'   => ['<p>正文</p><script>var a = 1;</script>', '正文'],
            '样式剔除'   => ['<style>.x{color:red}</style><p>正文</p>', '正文'],
            '实体解码'   => ['&lt;p&gt;不是标签&lt;/p&gt;', '<p>不是标签</p>'],
            'nbsp 变空格'=> ["前\xC2\xA0后", '前 后'],
        ];
    }

    #[DataProvider('htmlCases')]
    public function testHtmlToText(string $html, string $expected): void
    {
        $this->assertSame($expected, SourceIngest::htmlToText($html));
    }

    public function testHtmlToTextKeepsChinesePunctuation(): void
    {
        // 正文里的标点是拆解的依据之一（对话、停顿），不能在清洗阶段被抹掉
        $this->assertSame('他说：「走。」', SourceIngest::htmlToText('<p>他说：「走。」</p>'));
    }
}
