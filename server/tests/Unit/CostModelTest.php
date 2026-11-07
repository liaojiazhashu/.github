<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Infrastructure\Ai\CostModel;
use App\Support\Config;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

/**
 * 成本换算听起来简单，但它同时是**预算熔断的输入**：
 * 算小了，护栏形同虚设；算大了，正常任务会被误杀。
 * 所以这里断言的是具体数字，而不是「大于 0」。
 */
final class CostModelTest extends TestCase
{
    private function model(): CostModel
    {
        // 用真实配置文件，避免测试里抄一份单价、改配置后测试还绿着
        return new CostModel(new Config(['ai' => require dirname(__DIR__, 2) . '/config/ai.php']));
    }

    private function at(string $time): DateTimeImmutable
    {
        return new DateTimeImmutable($time, new DateTimeZone('Asia/Shanghai'));
    }

    public function testPeakWindowOnWeekday(): void
    {
        $model = $this->model();

        self::assertTrue($model->isPeak($this->at('2026-10-28 09:00:00')));   // 周三 上午场
        self::assertTrue($model->isPeak($this->at('2026-10-28 11:59:00')));
        self::assertFalse($model->isPeak($this->at('2026-10-28 12:00:00')));  // 午休
        self::assertTrue($model->isPeak($this->at('2026-10-28 14:00:00')));   // 下午场
        self::assertFalse($model->isPeak($this->at('2026-10-28 18:00:00')));  // 六点后
    }

    public function testWeekendIsOffPeak(): void
    {
        $model = $this->model();

        self::assertFalse($model->isPeak($this->at('2026-10-31 10:00:00')));  // 周六
        self::assertFalse($model->isPeak($this->at('2026-11-01 15:00:00')));  // 周日
    }

    public function testFlashCostOffPeak(): void
    {
        // 未命中 1000 tokens × 1.0 元/百万 + 输出 500 × 4.0 = 1000 + 2000 = 3000 微元
        self::assertSame(3000, $this->model()->costMicro('deepseek-flash', 1000, 0, 500, $this->at('2026-10-28 22:00:00')));
    }

    public function testFlashCostPeakIsDouble(): void
    {
        self::assertSame(6000, $this->model()->costMicro('deepseek-flash', 1000, 0, 500, $this->at('2026-10-28 10:00:00')));
    }

    public function testCachedTokensUseHitPrice(): void
    {
        // 命中 400 × 0.02 = 8；未命中 600 × 1.0 = 600；输出 500 × 4.0 = 2000
        self::assertSame(2608, $this->model()->costMicro('deepseek-flash', 1000, 400, 500, $this->at('2026-10-28 22:00:00')));
    }

    public function testCachedTokensCannotExceedPromptTokens(): void
    {
        $normal  = $this->model()->costMicro('deepseek-flash', 1000, 1000, 0, $this->at('2026-10-28 22:00:00'));
        $capped  = $this->model()->costMicro('deepseek-flash', 1000, 5000, 0, $this->at('2026-10-28 22:00:00'));

        self::assertSame(20, $normal);        // 1000 × 0.02
        self::assertSame($normal, $capped);   // 多报的命中数被夹掉
    }

    public function testProIsMuchMoreExpensiveThanFlash(): void
    {
        $model  = $this->model();
        $when   = $this->at('2026-10-28 10:00:00');
        $flash  = $model->costMicro('deepseek-flash', 10000, 0, 1000, $when);
        $pro    = $model->costMicro('deepseek-v4-pro', 10000, 0, 1000, $when);

        self::assertGreaterThan($flash * 4, $pro);
    }

    public function testUnknownModelIsNotSilentlyFree(): void
    {
        // 未知模型返回 0 而不是瞎猜：0 会让熔断看起来「从没花钱」，所以必须有测试盯着调用方
        self::assertSame(0, $this->model()->costMicro('gpt-不存在', 1000, 0, 1000));
    }

    public function testToYuan(): void
    {
        self::assertSame(0.003, CostModel::toYuan(3000));
        self::assertSame(0.0657, CostModel::toYuan(65700));
    }
}
