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

        $out = self::classify($rows, $cidToUid, $authors, $active);
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

        $out = self::classify($rows, $cidToUid, $authors, []);

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

        $out = self::classify($rows, [1 => 10], $authors, $active);

        self::assertSame(1, $out['buckets']['manual']['works']);
        self::assertSame([2], $out['toRestore'], '只恢复本闸门自己写下的理由');
    }

    public function testAuthorWithFailedProbeIsUnknownEvenWhenRecentlyActiveInSnapshot(): void
    {
        // ok=false 的原始时间戳不可信：它可能是「上次成功查到的旧时间」。
        // 拿它判活跃会漏隐藏，拿它判不活跃会误隐藏 —— 一律不判。
        $authors = [10 => ['uid' => 10, 'name' => '阿查', 'ok' => false]];
        $active  = [];  // activeUids 不会收录 ok=false 的人

        $out = self::classify(
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

        $out = self::classify(
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
        $out = self::classify([], [], [], []);

        foreach (['keep', 'hide', 'anon', 'closed', 'unknown', 'manual'] as $key) {
            self::assertSame(0, $out['buckets'][$key]['works']);
            self::assertSame([], $out['buckets'][$key]['authors']);
        }
        self::assertSame([], $out['toHide']);
        self::assertSame([], $out['toHideAnon']);
        self::assertSame([], $out['toHideClosed']);
        self::assertSame([], $out['toRestore']);
        self::assertSame([], $out['unknown_reasons']);
    }

    // ---------------------------------------------------------------- 匿名规则

    public function testAnonymousWorkIsHiddenEvenWhenAuthorIsActive(): void
    {
        // 匿名是作品级事实，优先级高于活跃度：这位作者本月刚投过稿，
        // 他那篇显示成「匿名用户」的稿子仍然不能公开。
        $authors = [10 => ['uid' => 10, 'name' => '阿活', 'ok' => true]];
        $active  = [10 => ['name' => '阿活', 'last' => self::SINCE]];

        $out = self::classify(
            [$this->row(1, 1, 1)],
            [1 => 10],
            $authors,
            $active,
            [1 => true]
        );

        self::assertSame(1, $out['buckets']['anon']['works']);
        self::assertSame(0, $out['buckets']['keep']['works']);
        self::assertSame(0, $out['buckets']['hide']['works']);
        self::assertSame([1], $out['toHideAnon'], '匿名作品应当进匿名桶，不进活跃度桶');
        self::assertSame([], $out['toHide'], '两条规则的理由不同，不能混在 toHide 里');
    }

    public function testAnonymousRuleDoesNotTouchSameAuthorsRealNameWorks(): void
    {
        // 同一位作者的实名作品照常按活跃度判定：活跃则保留，停笔则收起。
        // 匿名那一篇单独收起，两者互不牵连。
        $authors = [
            10 => ['uid' => 10, 'name' => '阿活', 'ok' => true],
            20 => ['uid' => 20, 'name' => '阿歇', 'ok' => true],
        ];
        $active = [10 => ['name' => '阿活', 'last' => self::SINCE]];
        $cidToUid = [1 => 10, 2 => 10, 3 => 20];

        $rows = [
            $this->row(1, 1, 1),   // 阿活 · 匿名 → 收起
            $this->row(2, 2, 2),   // 阿活 · 实名 → 保留
            $this->row(3, 3, 3),   // 阿歇 · 实名、不活跃 → 收起（活跃度规则）
        ];

        $out = self::classify($rows, $cidToUid, $authors, $active, [1 => true]);

        self::assertSame([1], $out['toHideAnon']);
        self::assertSame([3], $out['toHide']);
        self::assertSame([2], $out['buckets']['keep']['ids'], '同作者的实名作品不受匿名规则影响');
    }

    public function testAnonymousWorkIsJudgedWithoutAuthorLookup(): void
    {
        // 匿名作品常挂在临时 uid 上（甚至 uid=0），按活跃度判会全落进「未判定」，
        // 永远收不起来。匿名只依赖 cid 与目录里的名字，所以排在那五个判定之前。
        $out = self::classify(
            [
                $this->row(1, 1, 1),   // uid 是 0
                $this->row(2, 2, 2),   // 快照里没这位作者
            ],
            [1 => 0, 2 => 99],
            [],
            [],
            [1 => true, 2 => true]
        );

        self::assertSame(2, $out['buckets']['anon']['works']);
        self::assertSame(0, $out['buckets']['unknown']['works'], '匿名不该被当成未判定');
        self::assertSame([1, 2], $out['toHideAnon']);
    }

    public function testAlreadyAnonHiddenWorkIsNotWrittenTwice(): void
    {
        $authors = [10 => ['uid' => 10, 'name' => '阿活', 'ok' => true]];
        $active  = [10 => ['name' => '阿活', 'last' => self::SINCE]];

        $out = self::classify(
            [$this->row(1, 1, 1, 'hidden', ActiveGate::ANON_REASON . '2026-11')],
            [1 => 10],
            $authors,
            $active,
            [1 => true]
        );

        self::assertSame(1, $out['buckets']['anon']['works']);
        self::assertSame(0, $out['buckets']['manual']['works'], '匿名规则收的不算人工隐藏');
        self::assertSame([], $out['toHideAnon'], '已经藏好的不重复写');
    }

    public function testManuallyHiddenAnonymousWorkIsLeftAlone(): void
    {
        // 人工隐藏排在匿名之前：结果都是「不公开」，但这条不该被闸门记账，
        // 否则整体回滚会把它一起放出来。
        $out = self::classify(
            [$this->row(1, 1, 1, 'hidden', '作者要求下架')],
            [1 => 10],
            [],
            [],
            [1 => true]
        );

        self::assertSame(1, $out['buckets']['manual']['works']);
        self::assertSame(0, $out['buckets']['anon']['works']);
        self::assertSame([], $out['toHideAnon']);
    }

    public function testAnonReasonSharesGatePrefixSoRollbackCoversBoth(): void
    {
        // 整体回滚只按 REASON_PREFIX 捞，所以匿名理由必须挂在同一个前缀下。
        // 这条断言是「一条命令全撤」的前提，前缀一改就失效。
        self::assertStringStartsWith(ActiveGate::REASON_PREFIX, ActiveGate::ANON_REASON);
        self::assertNotSame(ActiveGate::REASON_PREFIX, ActiveGate::ANON_REASON, '两类理由仍要能区分');
    }

    public function testEmptyAuthorNameIsNotTreatedAsAnonymous(): void
    {
        // 空名字最常见的原因是接口少给了 authorInfo.name（SourceCatalog 会写空串），
        // 也可能是同步被截断。那不是「确认匿名」，是「不知道」。
        // 若把它当匿名，一次字段抖动就会整片收掉公开区，且不报错。
        self::assertFalse(ActiveGate::isAnonymousItem(['authorName' => '']));
        self::assertFalse(ActiveGate::isAnonymousItem([]));
        self::assertFalse(ActiveGate::isAnonymousItem(['authorName' => '   ']));

        // 只有站方显式打的占位名才算
        self::assertTrue(ActiveGate::isAnonymousItem(['authorName' => '匿名用户']));
        self::assertTrue(ActiveGate::isAnonymousItem(['authorName' => ' 匿名用户 ']));

        // 真名当然不算
        self::assertFalse(ActiveGate::isAnonymousItem(['authorName' => '鸑鷟']));
    }

    public function testSameAnalysisReferencedByTwoWorksIsCountedOnce(): void
    {
        // hq_work.current_analysis_id 没有唯一约束。同一个拆解被两行引用时，
        // 只能按一行判：否则同一个 id 会既进 toHideAnon 又进 toHide，
        // 一次操作写两条审计、条数虚高一倍。
        $authors = [10 => ['uid' => 10, 'name' => '阿歇', 'ok' => true]];

        $rows = [
            $this->row(1, 7, 1),   // 匿名
            $this->row(2, 7, 2),   // 同一个拆解，另一行、实名、作者不活跃
        ];

        $out = self::classify($rows, [1 => 10, 2 => 10], $authors, [], [1 => true]);

        self::assertSame(1, $out['buckets']['anon']['works'] + $out['buckets']['hide']['works']);
        self::assertSame([7], $out['toHideAnon'], '按排在前面的那一行判，且只判一次');
        self::assertSame([], $out['toHide']);
    }

    public function testAnonHiddenWorkIsRestoredOnceItIsNoLongerAnonymous(): void
    {
        // 匿名作品后来揭晓成实名、作者又活跃 → 应当恢复可见。
        // 这是唯一能放出「曾被匿名规则收起」的作品的路径，必须钉住：
        // 「一律不公开」约束的是作品的**当前**状态，不是给它判了无期。
        $authors = [10 => ['uid' => 10, 'name' => '阿活', 'ok' => true]];
        $active  = [10 => ['name' => '阿活', 'last' => self::SINCE]];

        $out = self::classify(
            [$this->row(1, 1, 1, 'hidden', ActiveGate::ANON_REASON . '2026-10')],
            [1 => 10],
            $authors,
            $active,
            []   // 目录里已经不是匿名了
        );

        self::assertSame([1], $out['toRestore']);
        self::assertSame([], $out['toHideAnon'], '已藏好的不重复写');
        self::assertSame([], $out['buckets']['manual']['ids'], '这是闸门自己藏的，不算人工隐藏');
    }

    public function testAnonHiddenWorkStaysHiddenWhenAuthorIsStillInactive(): void
    {
        // 揭晓成实名但作者仍不活跃：结论还是「不公开」，只是理由归到活跃度那一类。
        // 这里不重写理由（已经藏住，重写要多一条无意义的 UPDATE），
        // 代价是 hidden_reason 会停在 anon-，属于审计措辞而非状态错误。
        $authors = [20 => ['uid' => 20, 'name' => '阿歇', 'ok' => true]];

        $out = self::classify(
            [$this->row(1, 1, 1, 'hidden', ActiveGate::ANON_REASON . '2026-10')],
            [1 => 20],
            $authors,
            [],
            []
        );

        self::assertSame([], $out['toRestore']);
        self::assertSame([], $out['toHide'], '已经藏好的不重复写');
        self::assertSame([], $out['toHideAnon']);
        self::assertSame(1, $out['buckets']['hide']['works']);
    }

    // ---------------------------------------------------------------- 源站撤稿

    public function testSourceClosedWorkIsHiddenEvenWhenAuthorIsActive(): void
    {
        // 作者本人这个月刚投过稿，但这篇在源站被改成未公开 / 被删了：
        // 拆解没有任何理由继续公开。
        $authors = [10 => ['uid' => 10, 'name' => '阿活', 'ok' => true]];
        $active  = [10 => ['name' => '阿活', 'last' => self::SINCE]];

        $out = self::classify(
            [$this->row(1, 1, 1), $this->row(2, 2, 2)],
            [1 => 10, 2 => 10],
            $authors,
            $active,
            [],
            [2 => true]   // 2 号 cid 已撤稿，1 号还开着
        );

        self::assertSame([2], $out['toHideClosed']);
        self::assertSame([1], $out['buckets']['keep']['ids']);
        self::assertSame(1, $out['buckets']['closed']['works']);
        self::assertSame([], $out['toHide'], '撤稿的理由与活跃度分开写');
    }

    public function testSourceClosedWorkIsJudgedWithoutAuthorLookup(): void
    {
        // 撤稿作品的 uid 可能是 0、作者也可能不在快照里。这两类若按活跃度判
        // 会落进「未判定」保持现状 —— 而它们恰恰是最该收起来的。
        $out = self::classify(
            [$this->row(1, 1, 1), $this->row(2, 2, 2)],
            [1 => 0, 2 => 99],
            [],
            [],
            [],
            [1 => true, 2 => true]
        );

        self::assertSame(2, $out['buckets']['closed']['works']);
        self::assertSame(0, $out['buckets']['unknown']['works'], '撤稿不该被当成未判定');
        self::assertSame([1, 2], $out['toHideClosed']);
    }

    public function testSourceClosedWorkAlreadyHiddenIsNotWrittenTwice(): void
    {
        // 上一轮因作者不活跃被收起，这一轮又撤稿了：结论都是「不公开」，
        // 不必再写一次理由，也不该进 toHideClosed 让改动条数虚高。
        $authors = [20 => ['uid' => 20, 'name' => '阿歇', 'ok' => true]];

        $out = self::classify(
            [$this->row(1, 1, 1, 'hidden', 'active-gate:2026-10')],
            [1 => 20],
            $authors,
            [],
            [],
            [1 => true]
        );

        self::assertSame(1, $out['buckets']['closed']['works']);
        self::assertSame([], $out['toHideClosed']);
        self::assertSame([], $out['buckets']['manual']['ids'], '闸门自己藏的不算人工隐藏');
    }

    public function testSourceReopenedAndActiveAuthorIsRestored(): void
    {
        // 源站恢复公开 + 作者仍活跃 = 三条全过关，放回。
        // 这是「对称性」那一半：撤稿是暂态，不是无期。
        $authors = [10 => ['uid' => 10, 'name' => '阿活', 'ok' => true]];
        $active  = [10 => ['name' => '阿活', 'last' => self::SINCE]];

        $out = self::classify(
            [$this->row(1, 1, 1, 'hidden', ActiveGate::CLOSED_REASON . '2026-10')],
            [1 => 10],
            $authors,
            $active,
            [],
            []   // 默认包装：1 号 cid 还开着
        );

        self::assertSame([1], $out['toRestore']);
        self::assertSame(1, $out['buckets']['keep']['works']);
    }

    public function testSourceReopenedButInactiveAuthorStaysHidden(): void
    {
        // 源站恢复了，但作者停笔了 —— 活跃度那一条不过关，仍然不公开。
        // 两条规矩是与关系，谁也不覆盖谁。
        $authors = [20 => ['uid' => 20, 'name' => '阿歇', 'ok' => true]];

        $out = self::classify(
            [$this->row(1, 1, 1, 'hidden', ActiveGate::CLOSED_REASON . '2026-10')],
            [1 => 20],
            $authors,
            [],
            [],
            []
        );

        self::assertSame([], $out['toRestore'], '源站恢复不等于可以公开');
        self::assertSame([], $out['toHide'], '已经藏好的不重复写');
        self::assertSame(1, $out['buckets']['hide']['works']);
    }

    public function testCidMissingFromCatalogIsUnknownNotClosed(): void
    {
        // 目录里根本没有这个 cid（同步不完整）是「不知道」，不是「已撤稿」。
        // 这两者混淆会让一次残缺同步收掉整片公开区。
        $out = self::classify(
            [$this->row(1, 1, 999)],
            [1 => 10],
            [10 => ['uid' => 10, 'name' => '阿活', 'ok' => true]],
            [],
            [],
            []
        );

        self::assertSame(0, $out['buckets']['closed']['works']);
        self::assertSame(1, $out['buckets']['unknown']['works']);
        self::assertSame(1, $out['unknown_reasons']['cid 不在目录里']);
        self::assertSame([], $out['toHideClosed']);
    }

    // ---------------------------------------------------------------- 口径同源

    public function testWindowComesFromOneConstant(): void
    {
        // 后台分类页、月采集、本闸门三处共用 AuthorActivity::WINDOW_MONTHS。
        // 任何一处另写一个数字，后台的「活跃作者」与公开区保留的作品就会分叉，
        // 而且两边都不报错 —— 所以这条用断言钉住。
        self::assertSame(AuthorActivity::WINDOW_MONTHS, ActiveGate::DEFAULT_MONTHS);

        $now = 1_792_000_000;
        self::assertSame(
            strtotime('-' . AuthorActivity::WINDOW_MONTHS . ' months', $now),
            AuthorActivity::since($now)
        );
    }

    public function testLabelsDoNotMentionTheOldOneYearWindow(): void
    {
        // 改口径之后残留「一年」的文案会直接告诉操作员错的判定线
        self::assertStringContainsString(AuthorActivity::WINDOW_LABEL, AuthorActivity::LABELS[AuthorActivity::ACTIVE]);
        foreach (AuthorActivity::LABELS as $label) {
            self::assertStringNotContainsString('一年', $label);
        }
    }

    // ---------------------------------------------------------------- 理由前缀

    public function testThreeReasonsAllShareTheGatePrefixAndStayDistinct(): void
    {
        // 整体回滚只按 REASON_PREFIX 捞（`LIKE 'active-gate:%'`）。任何一条理由
        // 没挂在这个前缀下，`--restore-all` 就漏掉它 —— 而它已经写进了 267 行的
        // hidden_reason，只能上后台一条条点。
        $r = ActiveGate::reasonsFor('2026-11');

        self::assertSame(['hide', 'anon', 'closed'], array_keys($r), 'apply() 依赖这三个键名');

        foreach ($r as $kind => $reason) {
            self::assertStringStartsWith(ActiveGate::REASON_PREFIX, $reason, $kind . ' 没挂在统一前缀下');
            self::assertStringContainsString('2026-11', $reason, $kind . ' 缺月份，两次操作会混成一条');
        }

        self::assertCount(3, array_unique($r), '三条理由必须互不相同，否则分项统计会串桶');
    }

    public function testEveryReasonRoundTripsBackToItsOwnBucket(): void
    {
        // 写下去的理由，必须能在读回来时认回原来的桶。这条断了不会报错：
        // 回滚页的「匿名 N 条、源站撤稿 N 条、作者不活跃 N 条」会算错，
        // 而人是看着这几个数字决定要不要全撤的 —— 错的数字比没有数字更糟。
        $r = ActiveGate::reasonsFor('2026-11');

        $byRule = ActiveGate::groupByRule([
            ['id' => 11, 'hidden_reason' => $r['anon']],
            ['id' => 22, 'hidden_reason' => $r['closed']],
            ['id' => 33, 'hidden_reason' => $r['hide']],
        ]);

        self::assertSame([11], $byRule['anon']);
        self::assertSame([22], $byRule['closed']);
        self::assertSame([33], $byRule['activity'], '不带分类后缀的那条属于活跃度');
    }

    public function testUnknownReasonFallsBackToActivity(): void
    {
        // 兜底归 activity：三类里只有它会在条件变化后被放回，归错的代价最小。
        // 2026-11-08 之前写的是 `active-gate:YYYY-MM`（没有分类后缀），走的正是
        // 这条路 —— 所以这个兜底同时是旧数据兼容层，删掉它会让历史记录失去归类。
        $byRule = ActiveGate::groupByRule([
            ['id' => 1, 'hidden_reason' => 'active-gate:2026-10'],
            ['id' => 2, 'hidden_reason' => 'active-gate:'],
            ['id' => 3, 'hidden_reason' => null],
        ]);

        self::assertSame([1, 2, 3], $byRule['activity']);
        self::assertSame([], $byRule['anon']);
        self::assertSame([], $byRule['closed']);
    }

    // ---------------------------------------------------------------- 目录索引

    public function testCatalogIndexDerivesUidAnonAndAvailability(): void
    {
        // 三张表来自同一次遍历，漏掉任何一张都会让闸门判错：
        // uid 漏 → 全落「未判定」；anon 漏 → 匿名稿不再收起；
        // available 漏 → 那篇被当成撤稿，作者再活跃也放不出来。
        $idx = ActiveGate::buildCatalogIndex([
            'meta'  => ['fetched_at' => 1_760_000_000],
            'items' => [
                ['cid' => 1, 'authorUid' => 10, 'authorName' => '阿活',
                 'type' => 'post', 'isDelete' => 0, 'isopen' => 1, 'status' => 'publish'],
                ['cid' => 2, 'authorUid' => 20, 'authorName' => AuthorActivity::ANON_PLACEHOLDER,
                 'type' => 'post', 'isDelete' => 0, 'isopen' => 1, 'status' => 'publish'],
            ],
        ]);

        self::assertSame([1 => 10, 2 => 20], $idx['uid']);
        self::assertSame([2 => true], $idx['anon']);
        self::assertSame([1 => true, 2 => true], $idx['available']);
        self::assertSame(1_760_000_000, $idx['fetched_at']);
    }

    public function testCatalogIndexTreatsClosedDeletedAndDraftAsUnavailable(): void
    {
        // 四种「读者打不开」的情形都要落进 available 的差集，少认一种，
        // 那篇拆解就会留在公开区 —— 它是撤稿收不起来的唯一来源。
        $base = ['authorUid' => 10, 'authorName' => '阿活',
                 'type' => 'post', 'isDelete' => 0, 'isopen' => 1, 'status' => 'publish'];

        $idx = ActiveGate::buildCatalogIndex(['items' => [
            ['cid' => 1] + $base,                      // 正常
            ['cid' => 2, 'isopen' => 0] + $base,       // 作者改成未公开
            ['cid' => 3, 'isDelete' => 1] + $base,     // 站方删除
            ['cid' => 4, 'status' => 'draft'] + $base, // 退回草稿
            ['cid' => 5, 'type' => 'comment'] + $base, // 不是投稿
        ]]);

        self::assertSame([1 => true], $idx['available']);
        self::assertCount(5, $idx['uid'], '不可用的也必须留在 uid 表里，差集才算得出来');
    }

    public function testCatalogIndexFallsBackToBuiltAtAndSkipsEntriesWithoutCid(): void
    {
        // meta 里两种时间戳都出现过（同步写的 fetched_at、早期构建写的 built_at）。
        // cid 缺失或为 0 的条目直接跳过：它们进 uid 表会变成 `0 => 0`，
        // 让「cid 不在目录里」这条保护失效。
        $idx = ActiveGate::buildCatalogIndex([
            'meta'  => ['built_at' => 1_750_000_000],
            'items' => [
                ['authorUid' => 10, 'authorName' => '阿活'],   // 没有 cid
                ['cid' => 0, 'authorUid' => 11],               // cid 为 0
                'not-an-array',
                ['cid' => 3, 'authorUid' => 13, 'authorName' => '阿三',
                 'type' => 'post', 'isDelete' => 0, 'isopen' => 1, 'status' => 'publish'],
            ],
        ]);

        self::assertSame([3 => 13], $idx['uid']);
        self::assertSame(1_750_000_000, $idx['fetched_at'], 'meta 没有 fetched_at 时退回 built_at');
    }

    // ---------------------------------------------------------------- 装配

    /**
     * classify() 的测试包装：默认「目录里每个 cid 在源站都还开着」。
     *
     * 真实的 classify() 要求显式传 available（少传就等于「全站撤稿」，会把
     * 全站拆解收光，所以那边是必填）。测试里绝大多数用例关心的是匿名与活跃度，
     * 不该每处都写一遍 available，所以在这里兜住默认。
     *
     * @param list<array<string,mixed>>      $rows
     * @param array<int,int>                 $cidToUid
     * @param array<int,array<string,mixed>> $authors
     * @param array<int,array{name:string,last:int}> $active
     * @param array<int,true>                $anon    标成匿名的 cid
     * @param array<int,true>                $closed  标成「源站已撤稿」的 cid
     * @return array<string,mixed>
     */
    private static function classify(
        array $rows,
        array $cidToUid,
        array $authors,
        array $active,
        array $anon = [],
        array $closed = []
    ): array {
        $available = [];
        foreach ($cidToUid as $cid => $_) {
            if (!isset($closed[$cid])) {
                $available[$cid] = true;
            }
        }

        return ActiveGate::classify($rows, $cidToUid, $authors, $active, $anon, $available);
    }

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
