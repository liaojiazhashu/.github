<?php

declare(strict_types=1);

namespace App\Application\Source;

use App\Application\Pipeline\OneClick;
use RuntimeException;

/**
 * 每月 2 日的固定动作：把「第二十五期起、非匿名公开发表、作者近期活跃」的作品
 * 采进银河图书馆并送进拆解流水线。
 *
 * 「近期」的长度不写在这里，取 `AuthorActivity::WINDOW_MONTHS`（当前两个月），
 * 与后台「作者分类」、公开区活跃度闸门同一个数字。改窗口只改那一处。
 *
 * ## 为什么要单独一层，而不是直接排一条 `pipeline:oneclick` 命令
 *
 * 因为这条定时任务有**三个前置条件**，任何一个没满足都会静默跑偏：
 *
 * 1. **目录要新**。官网每期都在变（撤稿、改公开状态、补匿名），目录旧一天，
 *    就有一天的作品被漏掉，而且不会报错 —— 只是「这一期少了两篇」。
 * 2. **活跃度要新**。活跃判定有个滑动时间窗，那个窗口每天都在往前挪。
 *    用两个月前的快照筛「活跃」，等于把已经沉寂的人当成活跃、把新人当成僵尸。
 * 3. **范围要按「期次」收，不能按时间收**。第 25 期是这条线的起点，写死在这里；
 *    用「最近三个月」这类写法，遇到加期、提前截稿就会错，而且错得看不出来。
 *
 * ## 为什么宁可不跑，也不要在前置条件不满足时硬跑
 *
 * 一次错误的派发是**要花钱**的：每篇约 0.1 元、九十秒。目录为空时硬跑，
 * 结果不是「0 篇」而是「把范围判成空，然后照常返回成功」，看起来一切正常。
 * 所以这里对「目录为空」「一期都没有」这类情况直接抛异常，让计划任务的日志里
 * 留下一条红色记录，而不是一条绿色的 0。
 */
final class ActiveCollect
{
    /** 这条线的起点：第二十五期。需求原话是「从第二十五期起」 */
    public const FROM_ISSUE = 25;

    /** 活跃度快照超过这个时长就重建（秒）。一天：窗口天天在滑，再旧就该重查 */
    private const ACTIVITY_TTL = 86_400;

    public function __construct(
        private readonly SourceCatalog $catalog,
        private readonly AuthorActivity $activity,
        private readonly OneClick $oneClick,
    ) {
    }

    /**
     * 列出这条线覆盖的期次（>= 起点，且目录里真的有作品）。
     *
     * @return list<int>
     */
    public function issues(int $fromIssue = self::FROM_ISSUE): array
    {
        $catalog = $this->catalog->load();
        if ($catalog === null) {
            throw new RuntimeException('还没有目录快照，先跑一次 source:sync');
        }

        $issues = [];
        foreach (($catalog['items'] ?? []) as $item) {
            $no = (int) ($item['issueNo'] ?? 0);
            if ($no >= $fromIssue) {
                $issues[$no] = true;
            }
        }

        if ($issues === []) {
            throw new RuntimeException(sprintf('目录里没有第 %d 期及以后的作品，先确认 source:sync 的内容', $fromIssue));
        }

        $out = array_keys($issues);
        sort($out);

        return $out;
    }

    /**
     * 刷新活跃度快照（必要时）。
     *
     * @return array{rebuilt:bool, built_at:int, authors:int}
     */
    public function refreshActivity(bool $force = false, ?callable $onUser = null): array
    {
        $builtAt = $this->activity->builtAt();
        $stale   = $builtAt <= 0 || $builtAt < time() - self::ACTIVITY_TTL;

        if (!$force && !$stale) {
            return ['rebuilt' => false, 'built_at' => $builtAt, 'authors' => 0];
        }

        $catalog = $this->catalog->load();
        if ($catalog === null) {
            throw new RuntimeException('还没有目录快照，先跑一次 source:sync');
        }

        // 时间窗以执行时刻为界，不做任何整点对齐 —— 对齐到月初会让
        // 每月 2 日跑的那次实际上是在看「上个月 1 日到现在」，差的正好是那两天。
        // 长度取 AuthorActivity::WINDOW_MONTHS，与后台分类页同一个数字。
        $since  = AuthorActivity::since();
        $result = $this->activity->build(
            $catalog['items'] ?? [],
            $since,
            true,
            $force,
            $onUser
        );

        $active = 0;
        foreach (($result['authors'] ?? []) as $a) {
            if ((string) ($a['state'] ?? '') === AuthorActivity::ACTIVE) {
                ++$active;
            }
        }

        return [
            'rebuilt'  => true,
            'built_at' => (int) ($result['meta']['built_at'] ?? time()),
            'authors'  => count($result['authors'] ?? []),
            'active'   => $active,
        ];
    }

    /**
     * 算出这一次会派什么，不动任何东西。
     *
     * @return array{issues:list<int>, scope:array<string,mixed>, plan:array<string,mixed>, activity:array<string,mixed>}
     */
    public function plan(int $fromIssue = self::FROM_ISSUE, int $limit = 500, bool $refreshActivity = false): array
    {
        $issues   = $this->issues($fromIssue);
        $activity = $this->refreshActivity($refreshActivity);

        $scope = OneClick::normalizeScope([
            'issues'       => $issues,
            'author_state' => AuthorActivity::ACTIVE,
            'stage'        => 'todo',
            'limit'        => $limit,
        ]);

        return [
            'issues'   => $issues,
            'scope'    => $scope,
            'plan'     => $this->oneClick->resolve($scope),
            'activity' => $activity,
        ];
    }

    /**
     * 真正派活。计划为空时**不建批次** —— 空批次会在后台留下一行「0 篇」，
     * 三个月后没人分得清那是「本来就没有新作品」还是「跑挂了」。
     *
     * @param array<string,mixed> $scope
     * @return array<string,mixed>
     */
    public function launch(array $scope, bool $autoPublish, string $note, int $actorId): array
    {
        return $this->oneClick->launch($scope, [
            'auto_publish' => $autoPublish,
            'keep_hidden'  => !$autoPublish,
            'note'         => $note,
        ], $actorId);
    }
}
