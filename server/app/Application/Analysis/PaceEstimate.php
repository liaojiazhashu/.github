<?php

declare(strict_types=1);

namespace App\Application\Analysis;

use PDO;

/**
 * 「一篇要花多少钱、多少时间」——用本库自己的历史实测，不拍脑袋。
 *
 * 这个数字出现在两个地方：拆解调度台的「还差多少」和一键拆解的「这次要花多少」。
 * 两处各写一条 SQL，换模型、换提示词之后必然一边新一边旧，运营看到的两个页面
 * 会给出不同的预算 —— 所以只留一份。
 *
 * 只统计**跑完的**分析（succeeded / published 且有起止时间）：
 * 失败的调用没有 finished_at，把它们算进平均会把时长拉低（失败往往很快），
 * 让人以为「一篇只要几秒」。
 */
final class PaceEstimate
{
    /**
     * 还没有实测样本时的经验值。
     *
     * 取数来自本库此前 500 篇批次的实际结果（0.118 元、约 84 秒/篇）。
     * 为什么需要兜底：刚清空数据库、或换模型后还没跑过一篇时，样本是 0，
     * 按公式算出来就是「约 0.00 元 / 0.0 小时」—— 那个数字不是「免费」，
     * 是「不知道」。让人按一个看起来免费的估算按下按钮，比不显示更糟。
     */
    private const FALLBACK_YUAN = 0.12;

    private const FALLBACK_MINUTES = 1.4;

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return array{yuan:float, minutes:float, samples:int}
     */
    public function perWork(): array
    {
        $row = $this->pdo->query(
            "SELECT COUNT(*) AS n, COALESCE(SUM(cost_micro), 0) AS cost,
                    COALESCE(AVG(TIMESTAMPDIFF(SECOND, started_at, finished_at)), 0) AS secs
               FROM hq_analysis
              WHERE status IN ('succeeded','published') AND started_at IS NOT NULL AND finished_at IS NOT NULL"
        )->fetch() ?: [];

        $n = (int) ($row['n'] ?? 0);
        if ($n === 0) {
            return ['yuan' => 0.0, 'minutes' => 0.0, 'samples' => 0];
        }

        return [
            'yuan'    => round(((int) $row['cost'] / 1e6) / $n, 3),
            'minutes' => round(((float) $row['secs']) / 60, 2),
            'samples' => $n,
        ];
    }

    /**
     * @return array{per_work_yuan:float, per_work_minutes:float, cost_yuan:float, hours:float, samples:int, estimated:bool}
     */
    public function forWorks(int $works): array
    {
        $pace  = $this->perWork();
        $works = max(0, $works);

        // 没有样本时用经验值，并明确告诉调用方「这是估的」—— 页面上要标出来，
        // 否则「样本 0 篇」配一个非零金额，看起来像是算出来的
        $estimated = $pace['samples'] === 0;
        $yuan      = $estimated ? self::FALLBACK_YUAN : $pace['yuan'];
        $minutes   = $estimated ? self::FALLBACK_MINUTES : $pace['minutes'];

        return [
            'per_work_yuan'    => $yuan,
            'per_work_minutes' => $minutes,
            'cost_yuan'        => round($yuan * $works, 2),
            'hours'            => round($minutes * $works / 60, 1),
            'samples'          => $pace['samples'],
            'estimated'        => $estimated,
        ];
    }
}
