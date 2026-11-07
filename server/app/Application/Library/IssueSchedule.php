<?php

declare(strict_types=1);

namespace App\Application\Library;

use DateTimeImmutable;

/**
 * 期次日历（练笔活动规则 4.1）。
 *
 * 规则原文是「每年 9 期」，月份周期不等长：
 * 1-2 月 / 3 月 / 4 月 / 5-6 月 / 7 月 / 8 月 / 9-10 月 / 11 月 / 12 月。
 * 所以第 1 期是 2024-01-01 起，第 25 期是 2026-09-01 起 —— 24 / 9 = 2 年整，位置 6（9-10 月）。
 *
 * 两件事只有写进代码才不会再错：
 *   1. **期次和月份不是等长的**，任何「每 6 周」「每期 45 天」的算法都会漂；
 *   2. **边界在早上 7 点**，不是零点 —— 读者在截稿日 06:59 投的稿还算这一期。
 *
 * 这个类不碰数据库。它只回答「第 N 期该是哪段时间」，
 * 由 bin/console.php library:issue-dates 负责把结果写回 hq_issue。
 */
final class IssueSchedule
{
    /** 一期活动的起点：第 1 期从 2024-01-01 07:00 开始 */
    public const FIRST_YEAR = 2024;

    /** 截稿时刻（当天早上 7 点） */
    public const CUTOFF_HOUR = 7;

    /**
     * 9 个期的 [起始月, 结束月) —— 结束月是「下一期的起始月」，不含。
     * 写成 [起始月, 次月) 而不是 [起始月, 结束月]，是为了让相邻两期首尾严丝合缝。
     */
    private const SLOTS = [
        [1, 3],   // 1-2 月
        [3, 4],   // 3 月
        [4, 5],   // 4 月
        [5, 7],   // 5-6 月
        [7, 8],   // 7 月
        [8, 9],   // 8 月
        [9, 11],  // 9-10 月
        [11, 12], // 11 月
        [12, 13], // 12 月（跨年）
    ];

    /** 一期活动的月份周期（给人看的标签） */
    private const SLOT_LABELS = [
        '1-2 月', '3 月', '4 月', '5-6 月', '7 月', '8 月', '9-10 月', '11 月', '12 月',
    ];

    /**
     * 第 N 期的起止时间。
     *
     * @return array{no:int,start:DateTimeImmutable,end:DateTimeImmutable,months:string}
     */
    public static function period(int $no): array
    {
        if ($no < 1) {
            throw new \InvalidArgumentException('期次从 1 开始：' . $no);
        }

        $offset = $no - 1;
        $year   = self::FIRST_YEAR + intdiv($offset, count(self::SLOTS));
        $slot   = $offset % count(self::SLOTS);

        [$from, $to] = self::SLOTS[$slot];

        $start = self::at($year, $from);
        // 12 月的下一期起始落在次年 1 月，这里是跨年唯一要处理的地方
        $end = $to > 12 ? self::at($year + 1, $to - 12) : self::at($year, $to);

        return [
            'no'     => $no,
            'start'  => $start,
            'end'    => $end,
            'months' => self::SLOT_LABELS[$slot],
        ];
    }

    /** 指定时刻属于第几期（用于「当前是第几期」这类展示，不参与业务判定） */
    public static function noAt(DateTimeImmutable $at): int
    {
        $year   = (int) $at->format('Y');
        $offset = ($year - self::FIRST_YEAR) * count(self::SLOTS);

        // 先按月份粗定位：从当年最后一个 slot 往前找，第一个够得着的就是它
        $no = $offset;
        for ($slot = count(self::SLOTS) - 1; $slot >= 0; $slot--) {
            [$from] = self::SLOTS[$slot];
            if ((int) $at->format('n') >= $from) {
                $no = $offset + $slot + 1;
                break;
            }
        }

        // 再校正 1 月 1 日 07:00 之前的几小时：月份说是新一期，时刻还属上一期
        if ($no >= 1 && $at < self::period($no)['start']) {
            $no--;
        }

        return max(1, $no);
    }

    /** 统一的时刻构造：当月 1 日 07:00 */
    private static function at(int $year, int $month): DateTimeImmutable
    {
        return new DateTimeImmutable(sprintf('%04d-%02d-01 %02d:00:00', $year, $month, self::CUTOFF_HOUR));
    }
}
