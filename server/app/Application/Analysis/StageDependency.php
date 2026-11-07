<?php

declare(strict_types=1);

namespace App\Application\Analysis;

use InvalidArgumentException;

/**
 * 阶段依赖：改哪一步，后面哪些步骤的结论会跟着失真。
 *
 * 为什么需要它：六阶段不是六个独立函数，后一步都吃前一步的产出 ——
 * S4 的建议引用索引词与剧情节点，S5 的修改方向建立在 S4 的建议之上。
 * 只重跑 S2 而留着旧的 S4/S5，页面上就会出现「剧情节点换了三处，建议还在夸旧版本第 7 节」
 * 这种自相矛盾的结果，比不重跑更糟。
 *
 * 级联**默认关闭**（由人勾选）：重跑一步可能只花几秒钟，级联四步是四倍的钱与四倍的等待，
 * 而"我只是想换个说法重新抽一次索引词"完全不值得把建议也重写一遍。
 */
final class StageDependency
{
    /**
     * 流水线顺序（S6 是纯代码组装，不在可重跑范围内）。
     *
     * **引用 StageSpec::ORDER，不要在这里再抄一份字面量。**
     * 两个常量原来各写一遍同样的六个代号，改一处忘一处时，
     * Pipeline 用 ORDER 决定「从哪一步开始补跑」、用 StageSpec::order() 决定
     * 写哪些 stage 记录，两套顺序一错开就会漏跑或重复跑。
     * bin/check-consistency.php 会核一遍（合并之后这条退化成自反检查，仍然保留）。
     */
    public const ORDER = StageSpec::ORDER;

    /**
     * 阶段 → 重跑它之后**必须**跟着重跑的阶段（按 ORDER 排序，不含自身）。
     *
     * 依据的是"谁的输入里含它"：
     *   · S1 索引词 → S4（建议点名具体词）、S5（修改方向建立在建议上）、S7（读者页要列这几个词）；
     *   · S2 剧情 → S3（伏笔要挂到节点上）、S4、S5、S7（看点与画面都引自节点）；
     *   · S3 伏笔 → S5（修改方向的"埋/收"那几条引用台账）；S4 不读伏笔，故不级联；
     *   · S4 建议 → S5；S7 不读建议（那是作者向内容，读者页不该引用），故不级联；
     *   · S5、S7 是链尾，不级联任何东西。
     */
    public const CASCADE = [
        'S1_INDEX'      => ['S4_ADVICE', 'S5_REVISION', 'S7_READER'],
        'S2_PLOT'       => ['S3_FORESHADOW', 'S4_ADVICE', 'S5_REVISION', 'S7_READER'],
        'S3_FORESHADOW' => ['S5_REVISION'],
        'S4_ADVICE'     => ['S5_REVISION'],
        'S5_REVISION'   => [],
        'S7_READER'     => [],
    ];

    public static function isStage(string $code): bool
    {
        return in_array($code, self::ORDER, true);
    }

    /**
     * 本次要跑哪些阶段（含被点名的那个），按流水线顺序返回。
     *
     * @return list<string>
     */
    public static function plan(string $stage, bool $cascade): array
    {
        if (!self::isStage($stage)) {
            throw new InvalidArgumentException('未知阶段：' . $stage);
        }

        $codes = array_merge([$stage], $cascade ? self::CASCADE[$stage] : []);
        $codes = array_values(array_unique($codes));

        // 按 ORDER 归一，避免调用方拿到一个乱序的执行列表
        $sorted = array_values(array_filter(self::ORDER, static fn (string $code): bool => in_array($code, $codes, true)));

        return $sorted;
    }

    /**
     * 级联是否会重写产物（跑到 S5 / S7 才需要重新组装）。
     *
     * S7 虽然只产读者视角，但它和 S5 一样直接喂给组装步骤，所以它变了也必须重装。
     *
     * @param list<string> $plan
     */
    public static function reachesEnd(array $plan): bool
    {
        return in_array('S5_REVISION', $plan, true) || in_array('S7_READER', $plan, true);
    }
}
