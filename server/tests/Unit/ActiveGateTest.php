<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Application\Library\ActiveGate;
use App\Application\Source\AuthorActivity;
use App\Application\Source\SourceApi;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * 活跃度闸门：四分类互斥、边界取含、未知不隐藏、人工隐藏不恢复。
 *
 * 这几条都属于「错了不会报错」的类型：多隐藏一篇，读者只看到 404；
 * 少恢复一篇，作者只看到自己的拆解一直不出现。没有一条会抛异常。
 *
 * 分类是纯函数，这里直接喂数组，不装配 PDO、不碰数据库 ——
 * 验的是判定口径，不是 MySQL 方言。
 */
final class ActiveGateTest extends TestCase
{
    /** 判定阈值固定成 2025-11-01 00:00:00，避免用例随执行时间漂移 */
    private const SINCE = 1762012800;

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/hq-gate-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0o775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            if (is_file($f)) {
                @unlink($f);
            }
        }
        @rmdir($this->dir);
    }

    // ---------------------------------------------------------------- 活跃集合

    public function testActiveUidsUsesRawTimestampsAndIgnoresState(): void
    {
        $this->snapshot([
            // 正好在阈值上：算活跃（边界取含）
            ['uid' => 100, 'name' => '甲', 'latest_post_at' => self::SINCE, 'latest_review_at' => 0,
             'ok' => true, 'state' => AuthorActivity::ZOMBIE],
            // 差一秒：不算
            ['uid' => 101, 'name' => '乙', 'latest_post_at' => self::SINCE - 1, 'latest_review_at' => 0,
             'ok' => true, 'state' => AuthorActivity::ACTIVE],
            // 接口没查到：不能算活跃（由调用方归「未判定」）
            ['uid' => 102, 'name' => '丙', 'latest_post_at' => self::SINCE + 86400, 'latest_review_at' => 0,
             'ok' => false, 'state' => AuthorActivity::UNKNOWN],
            // 匿名：state 不可用，但原始时间戳有效，照样参与
            ['uid' => 103, 'name' => '匿名用户', 'latest_post_at' => 0, 'latest_review_at' => self::SINCE + 50,
             'ok' => true, 'state' => AuthorActivity::ANON],
            // 投稿与评稿取较晚的那个
            ['uid' => 104, 'name' => '丁', 'latest_post_at' => self::SINCE - 86400,
             'latest_review_at' => self::SINCE + 10, 'ok' => true, 'state' => AuthorActivity::ZOMBIE],
            // 什么都没动过
            ['uid' => 105, 'name' => '戊', 'latest_post_at' => 0, 'latest_review_at' => 0,
             'ok' => true, 'state' => AuthorActivity::ZOMBIE],
        ]);

        $active = $this->activity()->activeUids(self::SINCE);

        self::assertSame([100, 103, 104], array_keys($active));
        self::assertSame(self::SINCE, $active[100]['last']);
        self::assertSame(self::SINCE + 50, $active[103]['last']);
        self::assertSame(self::SINCE + 10, $active[104]['last']);
        self::assertSame('甲', $active[100]['name']);
    }

    public function testActiveUidsThrowsWhenSnapshotMissing(): void
    {
        // 返回空集合等于「所有人都不活跃」，下游会据此收掉全站拆解
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('活跃度快照不存在');

        $this->activity()->activeUids(self::SINCE);
    }

    // ---------------------------------------------------------------- 四分类

    public function testClassifySeparatesFourBuckets(): void
    {
        $authors = [
            10 => ['uid' => 10, 'name' => '阿活', 'ok' => true],
            20 => ['uid' => 20, 'name' => '阿歇', 'ok' => true],
            30 => ['uid' => 30, 'name' => '阿查', 'ok' => false],
        ];
        $active = [10 => ['name' => '阿活', 'last' => self::SINCE]];

        // cid → 作者；8 号指向快照里没有的 40，9 号指向 uid=0
        $cidToUid = [1 => 10, 2 => 20, 3 => 20, 4 => 10, 5 => 10, 6 => 20, 7 => 30, 8 => 40, 9 => 0, 10 => 10];

        $rows = [
            $this->row(1, 1, 1),                                      // 保留
            $this->row(2, 2, 2),                                      // 应当隐藏
            $this->row(3, 3, 3, 'hidden', 'active-gate:2026-09'),      // 已藏，不重复隐藏
            $this->row(4, 4, 4, 'hidden', 'active-gate:2026-09'),      // 保留且要恢复
            $this->row(5, 5, 5, 'hidden', '作者要求下架'),              // 人工隐藏
            $this->row(6, 6, 6, 'hidden', null),                       // 人工隐藏（无理由）
            $this->row(7, 7, 7),                                       // ok=false → 未判定
            $this->row(8, 8, 8),                                       // 快照没这人 → 未判定
            $this->row(9, 9, 9),                                       // uid=0 → 未判定
            $this->row(10, 10, 999),                                   // cid 不在目录 → 未判定
            $this->row(11, 11, 0),                                     // 没 cid → 未判定
            $this->row(16, 16, 3),                                     // 同一作者的又一篇，验证作者去重
        ];

        $out = ActiveGate::classify($rows, $cidToUid, $authors, $active);
        $b   = $out['buckets'];

        self::assertSame(2, $b['keep']['works']);
        self::assertCount(1, $b['keep']['authors'], 'keep 的作者数应按人去重');
        self::assertSame([1, 4], $b['keep']['ids']);

        self::assertSame(3, $b['hide']['works']);
        self::assertCount(1, $b['hide']['authors']);
        self::assertSame('阿歇', $b['hide']['authors'][20]['name']);
        self::assertSame(3, $b['hide']['authors'][20]['works']);

        self::assertSame(5, $b['unknown']['works']);
        self::assertSame(2, $b['manual']['works']);
        self::assertCount(2, $b['manual']['authors']);

        // 已 hidden 的不进 toHide；人工隐藏的两个都不进 toRestore
        self::assertSame([2, 16], $out['toHide']);
        self::assertSame([4], $out['toRestore']);
    }

    public function testUnknownNeverGetsHiddenOrRestored(): void
    {
        $authors = [30 => ['uid' => 30, 'name' => '阿查', 'ok' => false]];
        $cidToUid = [7 => 30, 8 => 40, 9 => 0, 10 => 10];

        $rows = [
            $this->row(7, 7, 7),                    // ok=false
            $this->row(8, 8, 8),                    // 快照里没有
            $this->row(9, 9, 9),                    // uid=0
            $this->row(10, 10, 999),                // cid 不在目录
            $this->row(11, 11, 0),                  // 没有 cid
            // 未判定但曾被闸门藏过：同样不动 —— 判不了就不该替它做决定
            $this->row(12, 12, 7, 'hidden', 'active-gate:2026-08'),
        ];

        $out = ActiveGate::classify($rows, $cidToUid, $authors, []);

        self::assertSame(6, $out['buckets']['unknown']['works']);
        self::assertSame([], $out['toHide'], '未判定一律不隐藏');
        self::assertSame([], $out['toRestore'], '未判定一律不恢复');
        self::assertSame(2, $out['unknown_reasons']['接口没查到（ok=false）']);
        self::assertSame(1, $out['unknown_reasons']['快照里没有这位作者']);
        self::assertSame(1, $out['unknown_reasons']['目录里没有作者 uid']);
        self::assertSame(1, $out['unknown_reasons']['cid 不在目录里']);
        self::assertSame(1, $out['unknown_reasons']['作品没有 source_cid']);
    }

    public function testManuallyHiddenWorkOfActiveAuthorIsNeverRestored(): void
    {
        $authors = [10 => ['uid' => 10, 'name' => '阿活', 'ok' => true]];
        $active  = [10 => ['name' => '阿活', 'last' => self::SINCE]];

        $rows = [
            $this->row(1, 1, 1, 'hidden', '涉及版权'),
            $this->row(2, 2, 1, 'hidden', 'active-gate:2026-10'),
        ];

        $out = ActiveGate::classify($rows, [1 => 10], $authors, $active);

        self::assertSame(1, $out['buckets']['manual']['works']);
        self::assertSame([2], $out['toRestore'], '只恢复本闸门自己写下的理由');
    }

    public function testAuthorWithFailedProbeIsUnknownEvenWhenRecentlyActiveInSnapshot(): void
    {
        // ok=false 的原始时间戳不可信：它可能是「上次成功查到的旧时间」。
        // 拿它判活跃会漏隐藏，拿它判不活跃会误隐藏 —— 一律不判。
        $authors = [10 => ['uid' => 10, 'name' => '阿查', 'ok' => false]];
        $active  = [];  // activeUids 不会收录 ok=false 的人

        $out = ActiveGate::classify(
            [$this->row(1, 1, 1)],
            [1 => 10],
            $authors,
            $active
        );

        self::assertSame(1, $out['buckets']['unknown']['works']);
        self::assertSame([], $out['toHide']);
    }

    public function testGateHiddenWorkIsNeitherHiddenAgainNorTreatedAsManual(): void
    {
        $authors = [20 => ['uid' => 20, 'name' => '阿歇', 'ok' => true]];

        $out = ActiveGate::classify(
            [$this->row(1, 1, 1, 'hidden', 'active-gate:2026-07')],
            [1 => 20],
            $authors,
            []
        );

        self::assertSame(1, $out['buckets']['hide']['works']);
        self::assertSame(0, $out['buckets']['manual']['works']);
        self::assertSame([], $out['toHide'], '已经藏好的不重复写');
    }

    public function testEmptyInputProducesEmptyPlan(): void
    {
        $out = ActiveGate::classify([], [], [], []);

        foreach (['keep', 'hide', 'unknown', 'manual'] as $key) {
            self::assertSame(0, $out['buckets'][$key]['works']);
            self::assertSame([], $out['buckets'][$key]['authors']);
        }
        self::assertSame([], $out['toHide']);
        self::assertSame([], $out['toRestore']);
        self::assertSame([], $out['unknown_reasons']);
    }

    // ---------------------------------------------------------------- 装配

    private function activity(): AuthorActivity
    {
        return new AuthorActivity(new SourceApi('https://example.test/', 'k', ''), $this->dir);
    }

    /**
     * @param list<array<string,mixed>> $authors
     */
    private function snapshot(array $authors, int $builtAt = 1_760_000_000): void
    {
        file_put_contents(
            $this->dir . '/activity.json',
            json_encode(
                ['meta' => ['built_at' => $builtAt], 'authors' => $authors],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            )
        );
    }

    /**
     * 一行查询结果的形状与 ActiveGate::rows() 的 SELECT 别名一致。
     *
     * @return array<string,mixed>
     */
    private function row(
        int $workId,
        int $analysisId,
        int $cid,
        string $analysisVisibility = 'visible',
        ?string $reason = null
    ): array {
        return [
            'work_id'             => $workId,
            'analysis_id'         => $analysisId,
            'source_cid'          => $cid,
            'analysis_visibility' => $analysisVisibility,
            'hidden_reason'       => $reason,
            'analysis_status'     => 'published',
        ];
    }
}
