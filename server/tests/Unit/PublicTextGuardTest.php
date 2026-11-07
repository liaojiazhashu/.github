<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Application\Library\PublicTextGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * 公开文本守卫的不变式。
 *
 * 这道守卫存在的理由：可见性过滤收口在 SQL 的 JOIN 常量里，能挡「结构化引用」
 * （关联表、计数、列表、检索），挡不住**模型写进句子里的词**。一句「串起冷冻休眠、
 * 世代飞船与人工生态圈」里的「世代飞船」不在任何关联表里，只改查询条件它照旧出现在页面上。
 *
 * 测的是**遮蔽规则**，不碰数据库：构造器分成「取数」和「算规则」两段，
 * 这里直接喂规则那段。规则里的每条边界（同名作品、可见词条名、单字词形）
 * 以前都是靠人肉在页面上看出来的，钉进测试才不会改一处又破一处。
 */
final class PublicTextGuardTest extends TestCase
{
    /**
     * @param list<string> $hidden 隐藏词条的标准名
     * @param array<string, list<string>> $aliases 标准名 => 归并到它名下的别名
     * @param list<string> $visibleNames 仍公开的名称（可见词条名 + 可见作品标题）
     */
    private function guard(array $hidden, array $aliases = [], array $visibleNames = []): PublicTextGuard
    {
        $hiddenTerms = [];
        $flat = [];
        foreach ($hidden as $i => $canonical) {
            $hiddenTerms[] = ['id' => $i + 1, 'canonical' => $canonical];
            foreach ($aliases[$canonical] ?? [] as $alias) {
                $flat[] = $alias;
            }
        }

        return new PublicTextGuard($hiddenTerms, $flat, $visibleNames);
    }

    public function testNothingHiddenMeansNoWork(): void
    {
        $guard = $this->guard([], [], ['黑洞']);

        self::assertFalse($guard->active());
        self::assertSame('一段完全正常的文字', $guard->text('一段完全正常的文字'));
        self::assertFalse($guard->isMasked('黑洞'));
    }

    public function testHiddenCanonicalIsMasked(): void
    {
        $guard = $this->guard(['世代飞船']);

        self::assertTrue($guard->active());
        self::assertSame(
            '串起冷冻休眠、＊＊＊＊与人工生态圈',
            $guard->text('串起冷冻休眠、世代飞船与人工生态圈')
        );
        // 等长替换：四个字换四个星号，排版宽度不变
        self::assertSame(4, mb_substr_count($guard->text('世代飞船'), PublicTextGuard::MASK));
    }

    public function testAliasIsMaskedToo(): void
    {
        // 只遮标准名等于没遮：正文里写「冷冻仓飞船」和写「世代飞船」是同一件事
        $guard = $this->guard(['世代飞船'], ['世代飞船' => ['冷冻仓飞船', '移民船']]);

        self::assertSame('＊＊＊＊＊派清缴发射场', $guard->text('冷冻仓飞船派清缴发射场'));
        self::assertSame('追那艘＊＊＊', $guard->text('追那艘移民船'));
    }

    public function testLongerFormWinsNoLeftover(): void
    {
        // 「世代飞船项目」是别名，「世代飞船」是标准名。先替换短的会留下「＊＊＊＊项目」，
        // 那半截不但难看，还等于把词的后半截露在外面
        $guard = $this->guard(['世代飞船'], ['世代飞船' => ['世代飞船项目']]);

        self::assertSame('＊＊＊＊＊＊', $guard->text('世代飞船项目'));
    }

    public function testWorkTitleWithSameNameIsNotMasked(): void
    {
        // 词条叫「蒲公英」，作品也叫《蒲公英》—— 同名是巧合，不是泄漏。
        // 因为隐藏一个词条就把作品改名，读者会找不到这篇
        $guard = $this->guard(['世代飞船'], ['世代飞船' => ['蒲公英']], ['蒲公英']);

        self::assertNotContains('蒲公英', $guard->forms());
        self::assertSame('《蒲公英》这篇的两百根分支', $guard->text('《蒲公英》这篇的两百根分支'));
    }

    public function testVisibleTermNameContainingHiddenFormIsSpared(): void
    {
        // 隐藏「世代飞船」，而「冷冻仓世代飞船」是一条仍挂着的独立词条。
        // 把它打成「冷冻仓＊＊＊＊」，等于把一条可见词条的名字截断
        $guard = $this->guard(['世代飞船'], [], ['冷冻仓世代飞船']);

        self::assertSame(
            '收「冷冻仓世代飞船」不收「＊＊＊＊」',
            $guard->text('收「冷冻仓世代飞船」不收「世代飞船」')
        );
    }

    public function testSingleCharFormIsIgnored(): void
    {
        // 单字词形会命中任何句子里偶然的同字，噪声大于信号
        $guard = $this->guard(['光'], ['光' => ['光']]);

        self::assertSame('光', $guard->text('光'));
        self::assertFalse($guard->active());
    }

    public function testStripTermEntriesIsExactMatch(): void
    {
        // 产物里可能同时有「世代飞船」和「冷冻仓世代飞船」。后者是独立词条，
        // 不能因为包含前者就被一起删掉
        $guard = $this->guard(['世代飞船']);

        $kept = $guard->stripTermEntries([
            ['canonical' => '世代飞船', 'definition' => '应删'],
            ['canonical' => '冷冻仓世代飞船', 'definition' => '应留'],
            ['canonical' => '黑洞', 'definition' => '应留'],
        ]);

        self::assertSame(['冷冻仓世代飞船', '黑洞'], array_column($kept, 'canonical'));
    }

    public function testArtifactsTreeIsMaskedDeeply(): void
    {
        $guard = $this->guard(['世代飞船']);

        $out = $guard->artifacts([
            'index_card' => ['one_liner' => '追上前去35号目的地的世代飞船'],
            'beats' => [['seq' => 1, 'detail' => '成为世代飞船执行船长'], ['seq' => 2, 'detail' => '无关内容']],
            'terms' => [['canonical' => '世代飞船', 'definition' => '整条删']],
        ]);

        // 键名与结构必须原样保留：模板读的是 $artifacts['beats'][1]['detail']
        self::assertSame('追上前去35号目的地的＊＊＊＊', $out['index_card']['one_liner']);
        self::assertSame('成为＊＊＊＊执行船长', $out['beats'][0]['detail']);
        self::assertSame('无关内容', $out['beats'][1]['detail']);
        self::assertSame(1, $out['beats'][0]['seq']);
        self::assertSame([], $out['terms']);
    }

    public function testRowsMaskOnlySummaryFields(): void
    {
        $guard = $this->guard(['世代飞船']);

        $rows = $guard->rows([
            ['title' => '世代飞船的故事', 'one_liner' => '追那艘世代飞船'],
        ]);

        // 标题是作品的身份证，不参与遮蔽；摘要要遮
        self::assertSame('世代飞船的故事', $rows[0]['title']);
        self::assertSame('追那艘＊＊＊＊', $rows[0]['one_liner']);
    }

    public function testIsMaskedRecognisesHiddenForms(): void
    {
        $guard = $this->guard(['世代飞船'], ['世代飞船' => ['移民船']]);

        // 检索用它决定「要不要拿这个词去翻原文」：搜的正是被隐藏的词时，答案是不该
        self::assertTrue($guard->isMasked('世代飞船'));
        self::assertTrue($guard->isMasked('移民船'));
        self::assertFalse($guard->isMasked('飞船内舱'));
        self::assertFalse($guard->isMasked(''));
    }

    /** @return array<string, array{0:string, 1:string}> */
    public static function untouched(): array
    {
        return [
            '标题里的同名作品不动' => ['《蒲公英》', '《蒲公英》'],
            '无关句子原样返回' => ['主角走进实验室，看见货架上泡着玻璃罐。', '主角走进实验室，看见货架上泡着玻璃罐。'],
            '已遮蔽过的文本不会被二次破坏' => ['＊＊＊＊', '＊＊＊＊'],
        ];
    }

    #[DataProvider('untouched')]
    public function testTextLeavesUnrelatedContentAlone(string $input, string $expected): void
    {
        $guard = $this->guard(['世代飞船'], ['世代飞船' => ['蒲公英']], ['蒲公英']);

        self::assertSame($expected, $guard->text($input));
    }
}
