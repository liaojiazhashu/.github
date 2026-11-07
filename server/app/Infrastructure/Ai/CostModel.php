<?php

declare(strict_types=1);

namespace App\Infrastructure\Ai;

use App\Support\Config;
use DateTimeImmutable;

/**
 * 成本换算。单价集中在 config/ai.php，不写死在这里。
 *
 * `cost_micro` 单位是**百万分之一元**（微元）。换算之所以能省掉一次除法：
 *   元 = tokens / 1e6 × 单价        （单价是 元/百万 tokens）
 *   微元 = 元 × 1e6 = tokens × 单价
 * 所以 cost_micro = 命中数×命中价 + 未命中数×未命中价 + 输出数×输出价，直接是整数。
 */
final class CostModel
{
    public function __construct(private readonly Config $config)
    {
    }

    /**
     * 是否为高峰时段：周一至周五 9:00–12:00、14:00–18:00，其余半价。
     *
     * 注意：法定节假日不建模（官方按节假日除外计算），因此高峰期估算略偏高——
     * 宁高勿低，用来做预算熔断才安全。
     */
    public function isPeak(?DateTimeImmutable $now = null): bool
    {
        $now ??= new DateTimeImmutable('now', new \DateTimeZone('Asia/Shanghai'));

        $weekdays = (array) $this->config->get('ai.peak_hours.weekdays', [1, 2, 3, 4, 5]);
        if (!in_array((int) $now->format('N'), array_map('intval', $weekdays), true)) {
            return false;
        }

        $hour = (int) $now->format('G');
        foreach ((array) $this->config->get('ai.peak_hours.ranges', []) as $range) {
            [$from, $to] = [(int) ($range[0] ?? 0), (int) ($range[1] ?? 0)];
            if ($hour >= $from && $hour < $to) {
                return true;
            }
        }

        return false;
    }

    public function costMicro(
        string $model,
        int $promptTokens,
        int $cachedTokens,
        int $completionTokens,
        ?DateTimeImmutable $now = null
    ): int {
        $price = $this->config->get('ai.price.' . $model);
        if (!is_array($price)) {
            // 未知模型：宁可算不出成本，也不要算成 0（0 会让预算熔断彻底失效）
            return 0;
        }

        $idx  = $this->isPeak($now) ? 1 : 0;
        $hit  = (float) ($price['hit'][$idx] ?? 0);
        $miss = (float) ($price['miss'][$idx] ?? 0);
        $out  = (float) ($price['out'][$idx] ?? 0);

        $cachedTokens = max(0, min($cachedTokens, $promptTokens));
        $missTokens   = max(0, $promptTokens - $cachedTokens);

        return (int) round($cachedTokens * $hit + $missTokens * $miss + $completionTokens * $out);
    }

    /** 微元 → 元的展示值 */
    public static function toYuan(int $costMicro): float
    {
        return round($costMicro / 1_000_000, 4);
    }

    /** 当前生效的单价表（后台「AI 用量」页要显示，避免看到数字却没有依据） */
    public function priceTable(): array
    {
        return (array) $this->config->get('ai.price', []);
    }

    public function peakLabel(?DateTimeImmutable $now = null): string
    {
        return $this->isPeak($now) ? '高峰（单价 ×2）' : '空闲（单价 ×1）';
    }
}
