<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Infrastructure\Pdo\WorkRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * 一次分析被中止（失败 / 运营停止 / 进程中断）之后，作品该回到哪一格。
 *
 * 这条不变式以前是漏的：停止分支根本没回收作品状态，作品永久停在 analyzing。
 * 后果不是「显示难看」而是「再也找不到」——后台一直显示正在分析，而再次入队时
 * 又会被 BatchDispatcher 的「正在分析」规则静默跳过，全程没有一处报错。
 *
 * 四个分支各有一个具体的失败模式，逐个钉住：
 *   · 重跑已发布作品被停 → 必须回 published，回 imported 等于把它从馆藏里弄丢；
 *   · 跑过半截   → 回 analyzed，产物还能看（与 analysis:reap 同口径）；
 *   · 一步没跑   → 回 imported，可以重新发起；
 *   · 首次运行被停（指针为空）→ 回 imported，不能因为指针为空就判成 published。
 */
final class AbortStatusTest extends TestCase
{
    /** @return array<string, array{0: ?int, 1: int, 2: ?string, 3: int, 4: string}> */
    public static function branches(): array
    {
        return [
            '重跑已发布作品被停：指针在别处且已发布' => [9, 12, 'published', 2, 'published'],
            '重跑已发布作品被停、且这次一步没跑'     => [9, 12, 'published', 0, 'published'],
            '首次运行被停：指针为空'                 => [null, 12, null, 0, 'imported'],
            '首次运行被停：指针仍是自己'             => [12, 12, 'failed', 0, 'imported'],
            '指针指向另一条但只是 succeeded'         => [9, 12, 'succeeded', 0, 'imported'],
            '跑过半截：有成功阶段'                   => [null, 12, null, 1, 'analyzed'],
            '跑过半截但指针在别处（非发布）'         => [9, 12, 'failed', 3, 'analyzed'],
            '指针为 0（脏数据）按空处理'             => [0, 12, null, 0, 'imported'],
        ];
    }

    #[DataProvider('branches')]
    public function testDecideStatusAfterAbort(
        ?int $pointer,
        int $analysisId,
        ?string $pointerStatus,
        int $doneStages,
        string $expected
    ): void {
        self::assertSame(
            $expected,
            WorkRepository::decideStatusAfterAbort($pointer, $analysisId, $pointerStatus, $doneStages)
        );
    }

    /** 判定结果必须落在 hq_work.status 的枚举里，否则 UPDATE 会静默截断成空串。 */
    public function testResultAlwaysInEnum(): void
    {
        $allowed = ['draft', 'imported', 'analyzing', 'analyzed', 'published', 'archived'];

        foreach (self::branches() as [$pointer, $analysisId, $pointerStatus, $doneStages, ]) {
            $got = WorkRepository::decideStatusAfterAbort($pointer, $analysisId, $pointerStatus, $doneStages);
            self::assertContains($got, $allowed);
        }
    }

    /** 中止之后绝不能还是 analyzing —— 那正是这条不变式要防的那个状态。 */
    public function testNeverReturnsAnalyzing(): void
    {
        foreach (self::branches() as [$pointer, $analysisId, $pointerStatus, $doneStages, ]) {
            self::assertNotSame(
                'analyzing',
                WorkRepository::decideStatusAfterAbort($pointer, $analysisId, $pointerStatus, $doneStages)
            );
        }
    }
}
