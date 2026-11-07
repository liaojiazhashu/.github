<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Application\Source\AuthorActivity;
use App\Application\Source\SourceApi;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * 作者活跃度分类：判定规则、匿名剥离、CSV 口径。
 *
 * 这几条都属于「错了不会报错，只是名单悄悄少一个人」的类型：
 *   · 匿名按作者判 → 39 个既有匿名作品又有实名作品的作者，整批被丢掉；
 *   · 查不到当成「没评过稿」→ 天天评稿的人被判僵尸；
 *   · 草稿算投稿 → 站方测试稿混进「可用」。
 *
 * 所以判定的边界要一条条钉死。
 */
final class AuthorActivityTest extends TestCase
{
    private AuthorActivity $activity;

    protected function setUp(): void
    {
        $this->activity = new AuthorActivity(
            new SourceApi('https://example.test/', 'k', ''),
            sys_get_temp_dir()
        );
    }

    /** 判定阈值统一用这个：2025-11-01 00:00:00 */
    private const SINCE = 1762012800;

    /**
     * @param array<string,mixed> $override
     * @return array<string,mixed>
     */
    private function author(array $override = []): array
    {
        return array_replace([
            'uid'               => 1969,
            'name'              => '雨幕ava',
            'posts'             => 5,
            'latest_post_at'    => 0,
            'review_total'      => 0,
            'latest_review_at'  => 0,
            'comment_total'     => 0,
            'latest_comment_at' => 0,
            'checked_at'        => 0,
            'ok'                => true,
            'comment_probed'    => 1,
            'state'             => AuthorActivity::UNKNOWN,
        ], $override);
    }

    public function testRecentPostMakesAuthorActive(): void
    {
        $state = $this->activity->judge($this->author([
            'latest_post_at' => self::SINCE + 86400,
        ]), self::SINCE);

        self::assertSame(AuthorActivity::ACTIVE, $state);
    }

    public function testRecentReviewMakesAuthorActiveEvenWithoutPosts(): void
    {
        // 只评稿不投稿的人也是活的 —— 这正是「评稿行为」要算进来的原因
        $state = $this->activity->judge($this->author([
            'latest_post_at'   => self::SINCE - 86400 * 400,
            'latest_review_at' => self::SINCE + 86400,
        ]), self::SINCE);

        self::assertSame(AuthorActivity::ACTIVE, $state);
    }

    public function testAuthorWithNothingInRangeIsZombie(): void
    {
        $state = $this->activity->judge($this->author([
            'latest_post_at'   => self::SINCE - 86400,
            'latest_review_at' => self::SINCE - 86400,
        ]), self::SINCE);

        self::assertSame(AuthorActivity::ZOMBIE, $state);
    }

    public function testExactlyOnTheBoundaryCountsAsInRange(): void
    {
        // 边界取「含」：正好一年前那一天投的稿，仍算近一年内。
        // 取「不含」会让阈值附近的人莫名变僵尸，且每年漂移一天。
        $state = $this->activity->judge($this->author([
            'latest_post_at' => self::SINCE,
        ]), self::SINCE);

        self::assertSame(AuthorActivity::ACTIVE, $state);
    }

    public function testFailedLookupIsUnknownNotZombie(): void
    {
        // 接口挂了却判僵尸，会静默地把活跃作者从名单里抹掉
        $state = $this->activity->judge($this->author([
            'ok'             => false,
            'latest_post_at' => self::SINCE - 86400 * 400,
        ]), self::SINCE);

        self::assertSame(AuthorActivity::UNKNOWN, $state);
    }

    public function testAnonAndRevokedAreSkippedBeforeJudging(): void
    {
        self::assertSame(
            AuthorActivity::ANON,
            $this->activity->judge($this->author(['name' => '匿名用户', 'ok' => false]), self::SINCE)
        );
        self::assertSame(
            AuthorActivity::GONE,
            $this->activity->judge($this->author(['name' => '用户已注销', 'ok' => false]), self::SINCE)
        );
    }

    public function testCommentOnlyIsSeparateFromActive(): void
    {
        // 只发短评、没评过稿：按「投稿 + 评稿」口径算僵尸，
        // 但单独标出来，不跟真僵尸混在一起
        $state = $this->activity->judge($this->author([
            'latest_post_at'    => self::SINCE - 86400,
            'latest_review_at'  => self::SINCE - 86400,
            'latest_comment_at' => self::SINCE + 86400,
        ]), self::SINCE);

        self::assertSame(AuthorActivity::COMMENT_ONLY, $state);
    }

    public function testCommentOnlyFallsBackToZombieWhenCommentsNotProbed(): void
    {
        // 关掉评论探针时不能凭空冒出「仅评论」这一类
        $state = $this->activity->judge($this->author([
            'latest_comment_at' => self::SINCE + 86400,
        ]), self::SINCE, false);

        self::assertSame(AuthorActivity::ZOMBIE, $state);
    }

    public function testAnonIsJudgedPerWorkNotPerAuthor(): void
    {
        // 同一位作者：这一期匿名，往期实名。匿名的作品要单独剥出来，
        // 实名的照常按作者分类 —— 否则会把人认出来，也会让篇数对不上。
        $authors = [$this->author([
            'uid'              => 2102,
            'name'             => '嘉黎',
            'latest_post_at'   => self::SINCE + 86400,
            'state'            => AuthorActivity::ACTIVE,
        ])];

        $items = [
            ['cid' => 1, 'title' => '失聪者', 'authorUid' => 2102, 'authorName' => '嘉黎', 'wordCount' => 100],
            ['cid' => 2, 'title' => 'Le Grand Bleu', 'authorUid' => 2102, 'authorName' => '匿名用户', 'wordCount' => 200],
        ];

        $tagged = $this->activity->tagItems($items, $authors);

        self::assertSame(AuthorActivity::ACTIVE, $tagged[0]['activity']);
        self::assertSame(AuthorActivity::ANON, $tagged[1]['activity']);
    }

    public function testSummaryCountsAnonWorksSeparately(): void
    {
        $authors = [$this->author([
            'uid'  => 2102,
            'name' => '嘉黎',
            'state' => AuthorActivity::ACTIVE,
        ])];

        $tagged = $this->activity->tagItems([
            ['cid' => 1, 'authorUid' => 2102, 'authorName' => '嘉黎', 'wordCount' => 100],
            ['cid' => 2, 'authorUid' => 2102, 'authorName' => '匿名用户', 'wordCount' => 200],
        ], $authors);

        $sum = $this->activity->summarize($tagged);

        self::assertSame(1, $sum[AuthorActivity::ACTIVE]['works']);
        self::assertSame(1, $sum[AuthorActivity::ANON]['works']);
        self::assertSame(300, $sum[AuthorActivity::ACTIVE]['words'] + $sum[AuthorActivity::ANON]['words']);
    }

    public function testCollectPostsTakesRealNameEvenIfLatestWorkIsAnon(): void
    {
        // 名字取「最近一篇实名」的，不能被最近那篇匿名稿覆盖掉。
        // 第一版就是按「最近一篇」取名字，结果这批作者全被判成匿名。
        $m = new ReflectionMethod(AuthorActivity::class, 'collectPosts');
        $m->setAccessible(true);

        $items = [
            ['authorUid' => 7, 'authorName' => '老王', 'type' => 'post', 'isDelete' => 0, 'created' => 1000],
            ['authorUid' => 7, 'authorName' => '匿名用户', 'type' => 'post', 'isDelete' => 0, 'created' => 2000],
        ];

        /** @var array<int,array{name:string,posts:int,latest:int}> $out */
        $out = $m->invoke($this->activity, $items);

        self::assertSame('老王', $out[7]['name']);
        self::assertSame(2, $out[7]['posts']);
        self::assertSame(2000, $out[7]['latest']);
    }

    public function testCollectPostsIgnoresDraftsAndRecycleBin(): void
    {
        // 草稿与回收站都不算「投过稿」
        $m = new ReflectionMethod(AuthorActivity::class, 'collectPosts');
        $m->setAccessible(true);

        $items = [
            ['authorUid' => 8, 'authorName' => '甲', 'type' => 'post_draft', 'isDelete' => 0, 'created' => 3000],
            ['authorUid' => 8, 'authorName' => '甲', 'type' => 'post', 'isDelete' => 1, 'created' => 3000],
            ['authorUid' => 8, 'authorName' => '甲', 'type' => 'post', 'isDelete' => 0, 'created' => 1000],
        ];

        /** @var array<int,array{name:string,posts:int,latest:int}> $out */
        $out = $m->invoke($this->activity, $items);

        self::assertSame(1, $out[8]['posts']);
        self::assertSame(1000, $out[8]['latest']);
    }

    public function testAuthorWithOnlyAnonWorksIsAnon(): void
    {
        $m = new ReflectionMethod(AuthorActivity::class, 'collectPosts');
        $m->setAccessible(true);

        /** @var array<int,array{name:string,posts:int,latest:int}> $out */
        $out = $m->invoke($this->activity, [
            ['authorUid' => 9, 'authorName' => '匿名用户', 'type' => 'post', 'isDelete' => 0, 'created' => 5000],
        ]);

        self::assertSame('匿名用户', $out[9]['name']);
    }

    public function testWorksCsvCarriesBomAndCrlf(): void
    {
        // 与 SourceCatalog 一致：BOM 防 Excel 乱码，CRLF 防个别版本读成一行
        $authors = [$this->author(['uid' => 5, 'name' => '乙', 'state' => AuthorActivity::ACTIVE])];
        $tagged  = $this->activity->tagItems([
            ['cid' => 1, 'title' => '含,逗号的标题', 'authorUid' => 5, 'authorName' => '乙',
             'issueNo' => 22, 'issueLabel' => '第 22 期', 'wordCount' => 1200, 'created' => 1790000000],
        ], $authors);

        $csv = $this->activity->worksCsv($tagged);

        self::assertStringStartsWith("\xEF\xBB\xBF", $csv);
        self::assertStringContainsString("\r\n", $csv);

        $lines = explode("\r\n", trim(substr($csv, 3)));
        self::assertCount(2, $lines);

        $row = str_getcsv($lines[1], ',', '"', '\\');
        self::assertSame('含,逗号的标题', $row[3]);
        self::assertSame('活跃（近一年有投稿或评稿）', $row[11]);
    }

    public function testAuthorsCsvSkipsAnonWorks(): void
    {
        $authors = [$this->author(['uid' => 6, 'name' => '丙', 'state' => AuthorActivity::ACTIVE])];
        $tagged  = $this->activity->tagItems([
            ['cid' => 1, 'authorUid' => 6, 'authorName' => '丙'],
            ['cid' => 2, 'authorUid' => 6, 'authorName' => '匿名用户'],
        ], $authors);

        $csv  = $this->activity->authorsCsv($tagged);
        $rows = array_map(
            static fn (string $l): array => str_getcsv($l, ',', '"', '\\'),
            explode("\r\n", trim(substr($csv, 3)))
        );

        self::assertCount(2, $rows);            // 表头 + 1 位作者
        self::assertSame('1', $rows[1][3]);     // 可用作品数只算实名那篇
    }
}
