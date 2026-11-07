<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Application\Source\SourceApi;
use App\Application\Source\SourceCatalog;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * 内容源目录：期号解析、按期汇总、导出。
 *
 * 这里测的三件事都是「错了会静默出错」的：
 *   · 期号解析错 → 作品挂到错误的期次上，拆解排期跟着错；
 *   · 汇总口径错 → 运营按错数字排期，而页面上看不出哪里不对；
 *   · CSV 少了 BOM → 只有别人的 Excel 打开才乱码，本地一切正常。
 */
final class SourceCatalogTest extends TestCase
{
    private SourceCatalog $catalog;

    protected function setUp(): void
    {
        // SourceApi 的构造函数不发请求，可以安全地 new 一个占位对象
        $this->catalog = new SourceCatalog(new SourceApi('https://example.test/', 'k', ''), sys_get_temp_dir());
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>|null
     */
    private function normalize(array $row): ?array
    {
        $m = new ReflectionMethod(SourceCatalog::class, 'normalize');
        $m->setAccessible(true);

        /** @var array<string,mixed>|null $out */
        $out = $m->invoke($this->catalog, $row);

        return $out;
    }

    /**
     * @param array<string,mixed> $override
     * @return array<string,mixed>
     */
    private function row(array $override = []): array
    {
        return array_replace([
            'cid'         => 2460,
            'title'       => '失聪者',
            'status'      => 'publish',
            'isopen'      => 1,
            'isDelete'    => 0,
            'type'        => 'post',
            'created'     => 1788196795,
            'modified'    => 1791555208,
            'size'        => 17214,
            'views'       => 335,
            'likes'       => 1,
            'commentsNum' => 9,
            'authorId'    => 2102,
            'authorInfo'  => ['name' => '嘉黎'],
            'category'    => [['name' => '原创作品']],
            'active'      => [[
                'slug'          => 'PracticeWriting024',
                'name'          => '荒启科幻练笔第二十四期',
                'type'          => 'active',
                'mid'           => 98,
                'deadline'      => 1788217200,
                'anonymousTime' => 1789513200,
            ]],
        ], $override);
    }

    // ---------------- 中文期号 ----------------

    /**
     * @return list<array{0:string,1:int}>
     */
    public static function numbers(): array
    {
        return [
            ['一', 1],
            ['五', 5],
            ['九', 9],
            ['十', 10],
            ['十一', 11],
            ['十二', 12],
            ['二十', 20],
            ['二十三', 23],
            ['二十六', 26],
            ['26', 26],
        ];
    }

    /**
     * @dataProvider numbers
     */
    #[DataProvider('numbers')]
    public function testParsesChineseNumbers(string $input, int $want): void
    {
        self::assertSame($want, SourceCatalog::parseChineseNumber($input));
    }

    public function testUnknownGivesZeroNotGuess(): void
    {
        // 解析不出来必须给 0（当作非练笔），不能猜成 1 之类 ——
        // 猜错的后果是这篇文章被塞进某一期，而且没人会发现。
        self::assertSame(0, SourceCatalog::parseChineseNumber(''));
        self::assertSame(0, SourceCatalog::parseChineseNumber('第'));
        self::assertSame(0, SourceCatalog::parseChineseNumber('甲'));
    }

    // ---------------- 单篇归一化 ----------------

    public function testNormalizeExtractsIssueWordsAndAuthor(): void
    {
        $item = $this->normalize($this->row());
        self::assertNotNull($item);

        self::assertSame(24, $item['issueNo']);
        self::assertSame('第 24 期', $item['issueLabel']);
        self::assertSame(98, $item['practiceMid']);
        self::assertSame(17214, $item['wordCount']);
        // 字数与 size 同源：这是「不为一千八百篇各拉一次详情」的前提
        self::assertSame(17214, $item['size']);
        self::assertSame(1788217200, $item['endsAt']);
        self::assertSame(1789513200, $item['revealAt']);
        self::assertSame('嘉黎', $item['authorName']);
        self::assertSame(2102, $item['authorUid']);
    }

    public function testNonPracticeItemHasNoIssue(): void
    {
        $item = $this->normalize($this->row([
            'active' => [[
                'slug' => 'QunXingBei02',
                'name' => '第二届群星杯征文',
                'type' => 'active',
                'mid'  => 5,
            ]],
        ]));

        self::assertNotNull($item);
        self::assertSame(0, $item['issueNo']);
        self::assertSame('', $item['issueLabel']);
        self::assertFalse($this->catalog->isPractice($item));
    }

    public function testIssueIsReadFromNameNotSlug(): void
    {
        // slug 有 PricticeWrighting 这种双错拼（第 21 期真实存在），
        // 按 slug 解析会得到 0。名字里的中文数字才是唯一可靠来源。
        $item = $this->normalize($this->row([
            'active' => [[
                'slug' => 'PricticeWrighting',
                'name' => '荒启科幻练笔第二十一期',
                'type' => 'active',
                'mid'  => 88,
            ]],
        ]));

        self::assertNotNull($item);
        self::assertSame(21, $item['issueNo']);
    }

    // ---------------- 按期汇总 ----------------

    public function testIssueStatsCountsOnlyAvailableWords(): void
    {
        $items = [
            // 第 26 期：两篇可用，1200 字与 800 字
            $this->normalize($this->row(['cid' => 1, 'size' => 1200, 'active' => [[
                'slug' => 'PracticeWriting026', 'name' => '荒启科幻练笔第二十六期',
                'type' => 'active', 'mid' => 103, 'deadline' => 1796079600, 'anonymousTime' => 1797375600,
            ]]])) ,
            $this->normalize($this->row(['cid' => 2, 'size' => 800, 'authorId' => 2, 'authorInfo' => ['name' => '乙'], 'active' => [[
                'slug' => 'PracticeWriting026', 'name' => '荒启科幻练笔第二十六期',
                'type' => 'active', 'mid' => 103, 'deadline' => 1796079600, 'anonymousTime' => 1797375600,
            ]]])),
            // 第 26 期：一篇被删除，不该计入可用与字数
            $this->normalize($this->row(['cid' => 3, 'size' => 9999, 'isDelete' => 1, 'active' => [[
                'slug' => 'PracticeWriting026', 'name' => '荒启科幻练笔第二十六期',
                'type' => 'active', 'mid' => 103, 'deadline' => 1796079600, 'anonymousTime' => 1797375600,
            ]]])),
            // 第 25 期：一篇可用，匿名
            $this->normalize($this->row(['cid' => 4, 'size' => 3000, 'authorId' => 0, 'authorInfo' => ['name' => '匿名用户'], 'active' => [[
                'slug' => 'PracticeWriting025', 'name' => '荒启科幻练笔第二十五期',
                'type' => 'active', 'mid' => 99, 'deadline' => 1793523600, 'anonymousTime' => 1794819600,
            ]]])),
        ];

        /** @var list<array<string,mixed>> $items */
        $items = array_values(array_filter($items));

        $stats = $this->catalog->issueStats($items);
        self::assertCount(2, $stats);

        [$i25, $i26] = $stats;

        self::assertSame(25, $i25['no']);
        self::assertSame(1, $i25['ready']);
        self::assertSame(3000, $i25['words']);
        self::assertSame(1, $i25['anon'], '匿名篇要单独计数：揭晓前作者名拿不到');

        self::assertSame(26, $i26['no']);
        self::assertSame(3, $i26['total'], '参加数含已删除的那篇');
        self::assertSame(2, $i26['ready']);
        self::assertSame(2000, $i26['words'], '已删除篇的字数不能算进来');
        self::assertSame(1000, $i26['avg']);
        self::assertSame(2, $i26['authors']);
        self::assertSame(0, $i26['anon']);
    }

    public function testIssueStatsAndFilterAgree(): void
    {
        $items = [
            $this->normalize($this->row(['cid' => 11])),
            $this->normalize($this->row(['cid' => 12])),
            $this->normalize($this->row(['cid' => 13, 'active' => [[
                'slug' => 'PracticeWriting026', 'name' => '荒启科幻练笔第二十六期',
                'type' => 'active', 'mid' => 103, 'deadline' => 1796079600,
            ]]])),
        ];
        $items = array_values(array_filter($items));

        $only24 = $this->catalog->filterIssue($items, 24);
        self::assertCount(2, $only24);

        // 筛出来的篇数必须与汇总表那一行的「参加」一致，否则页面上两个数字打架
        $stats = $this->catalog->issueStats($items);
        self::assertSame(2, $stats[0]['total']);
    }

    public function testFilterIssueZeroMeansNoFilter(): void
    {
        $items = [$this->normalize($this->row(['cid' => 21]))];
        $items = array_values(array_filter($items));

        self::assertCount(1, $this->catalog->filterIssue($items, 0));
    }

    // ---------------- 导出 ----------------

    public function testCsvHasBomAndCrlf(): void
    {
        $item = $this->normalize($this->row(['cid' => 2460]));
        $csv  = $this->catalog->toCsv([$item]);

        self::assertStringStartsWith("\xEF\xBB\xBF", $csv, 'Excel 开中文 CSV 必须靠 BOM');
        self::assertStringContainsString("\r\n", $csv);
        self::assertStringNotContainsString("\n\r", $csv);
    }

    public function testCsvColumnsAreStable(): void
    {
        $item = $this->normalize($this->row());
        $csv  = $this->catalog->toCsv([$item]);

        $lines = explode("\r\n", trim($csv));
        self::assertGreaterThanOrEqual(2, count($lines));

        $head = str_getcsv(ltrim($lines[0], "\xEF\xBB\xBF"), ',', '"', '\\');
        self::assertSame(
            ['cid', '期号', '期次', '标题', '作者', '作者UID', '字数', '发布时间', '状态', '链接'],
            $head
        );

        $row = str_getcsv($lines[1], ',', '"', '\\');
        self::assertCount(count($head), $row, '每行列数必须与表头一致');
        self::assertSame('2460', $row[0]);
        self::assertSame('24', $row[1]);
        self::assertSame('第 24 期', $row[2]);
        self::assertSame('17214', $row[6]);
        self::assertSame('可用', $row[8]);
    }

    public function testCsvSaysWhyAnItemIsUnavailable(): void
    {
        $hidden = $this->normalize($this->row(['cid' => 31, 'isopen' => 0]));

        $csv = $this->catalog->toCsv([$hidden]);
        self::assertStringContainsString('未公开', $csv);

        $deleted = $this->normalize($this->row(['cid' => 32, 'isDelete' => 1]));
        self::assertStringContainsString('已删除', $this->catalog->toCsv([$deleted]));
    }

    public function testCsvQuotesTitlesWithComma(): void
    {
        // 标题里带逗号是常态（「南方见, 再见」），不转义会把一列拆成两列
        $item = $this->normalize($this->row(['title' => '南方见, 再见']));

        $csv  = $this->catalog->toCsv([$item]);
        $line = explode("\r\n", trim($csv))[1];
        $row  = str_getcsv($line, ',', '"', '\\');

        self::assertStringContainsString('"南方见, 再见"', $csv, '含逗号的单元格必须被引号包起来');
        self::assertSame('南方见, 再见', $row[3]);
        self::assertCount(10, $row);
    }
}
