<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Application\Analysis\StageDependency;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * 级联规则错了的后果不是报错，而是「页面上自相矛盾」——
 * 剧情换了两处，建议还在引用旧节点。所以这里逐条断言每个阶段的级联集合。
 */
final class StageDependencyTest extends TestCase
{
    public function testOrderIsTheRerunnableStages(): void
    {
        $this->assertSame(
            ['S1_INDEX', 'S2_PLOT', 'S3_FORESHADOW', 'S4_ADVICE', 'S5_REVISION', 'S7_READER'],
            StageDependency::ORDER
        );

        // S6 是纯代码组装，不该出现在可重跑清单里
        $this->assertFalse(StageDependency::isStage('S6_ASSEMBLE'));
    }

    public function testCascadeTableOnlyCoversKnownStages(): void
    {
        $this->assertSame(StageDependency::ORDER, array_keys(StageDependency::CASCADE));

        foreach (StageDependency::CASCADE as $stage => $dependents) {
            foreach ($dependents as $dependent) {
                $this->assertTrue(StageDependency::isStage($dependent), $stage . ' 级联到了未知阶段 ' . $dependent);
                $this->assertNotSame($stage, $dependent, '级联表里不该出现自己');
            }
        }
    }

    public function testCascadePointsForwardOnly(): void
    {
        foreach (StageDependency::CASCADE as $stage => $dependents) {
            $selfIndex = (int) array_search($stage, StageDependency::ORDER, true);
            foreach ($dependents as $dependent) {
                $dependentIndex = (int) array_search($dependent, StageDependency::ORDER, true);
                $this->assertGreaterThan($selfIndex, $dependentIndex, $stage . ' 只能级联到它之后的阶段');
            }
        }
    }

    public function testPlanWithoutCascadeIsJustTheStage(): void
    {
        foreach (StageDependency::ORDER as $stage) {
            $this->assertSame([$stage], StageDependency::plan($stage, false));
        }
    }

    public function testPlanIsSortedByPipelineOrder(): void
    {
        // S1 的级联集合是 {S1, S4, S5, S7}，乱序返回会让执行顺序错
        $this->assertSame(['S1_INDEX', 'S4_ADVICE', 'S5_REVISION', 'S7_READER'], StageDependency::plan('S1_INDEX', true));
        $this->assertSame(['S2_PLOT', 'S3_FORESHADOW', 'S4_ADVICE', 'S5_REVISION', 'S7_READER'], StageDependency::plan('S2_PLOT', true));
        $this->assertSame(['S3_FORESHADOW', 'S5_REVISION'], StageDependency::plan('S3_FORESHADOW', true));
        $this->assertSame(['S4_ADVICE', 'S5_REVISION'], StageDependency::plan('S4_ADVICE', true));
        $this->assertSame(['S5_REVISION'], StageDependency::plan('S5_REVISION', true));
        $this->assertSame(['S7_READER'], StageDependency::plan('S7_READER', true));
    }

    public function testPlanReachesEndOnlyWhenRevisionIsIncluded(): void
    {
        $this->assertTrue(StageDependency::reachesEnd(StageDependency::plan('S1_INDEX', true)));
        $this->assertTrue(StageDependency::reachesEnd(StageDependency::plan('S3_FORESHADOW', true)));
        $this->assertFalse(StageDependency::reachesEnd(StageDependency::plan('S1_INDEX', false)));
    }

    public function testUnknownStageIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        StageDependency::plan('S6_ASSEMBLE', true);
    }
}
