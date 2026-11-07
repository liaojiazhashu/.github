<?php

declare(strict_types=1);

namespace App\Application\Analysis;

use App\Support\Config;

/**
 * 单个阶段的执行参数。
 *
 * 把「哪个阶段开思考、给多少 max_tokens」收在一处，是因为这几个值不是随便定的：
 * 思考 token 占 max_tokens 额度（§7.1.1③），给小了会返回空 content；
 * 而 `thinking=disabled` 时再传 effort 会丢类别（§7.1.1④）。
 */
final class StageSpec
{
    /**
     * 流水线固定顺序（**唯一来源**）。
     *
     * 这里用常量而不是只留 order() 一个方法，是因为 StageDependency::ORDER 是
     * 常量上下文（Pipeline 里 array_search/foreach 直接吃它），没法调用方法。
     * 于是让 StageDependency::ORDER = StageSpec::ORDER（常量表达式引用），
     * 两份字面量收成一份 —— 以前这两处各自抄了一遍，改一处忘一处时
     * 「stage 记录的写入顺序」与「从哪一步开始补跑」会错开。
     *
     * S7_READER 排在最后：它引用 S1 的关键词与 S2 的主线，但它自己不改任何分析结论，
     * 所以放在链尾最安全 —— 重跑前面任何一步都不会污染已经写好的读者视角以外的数据。
     * （没有 S6：S6 是纯代码组装，不是模型阶段，见 RESERVED_LABELS。）
     *
     * @var list<string>
     */
    public const ORDER = ['S1_INDEX', 'S2_PLOT', 'S3_FORESHADOW', 'S4_ADVICE', 'S5_REVISION', 'S7_READER'];

    /**
     * 阶段代号 → 中文名（**唯一来源**）。
     *
     * 曾经有四份口径：这里的 order()、StageDependency::ORDER、TwigFactory 里
     * 一份 stage_label 的数组副本、config/ai.php 的 ai.stage.*.label。其中
     * TwigFactory 那份漏了 S7_READER，后果是后台「执行记录」表
     * （resources/views/admin/works/analysis.twig 里 stage_code|stage_label）
     * 把 S7_READER 这个机器代号直接显示给运营看。
     *
     * 现在模板过滤器与 StageSpec::fromConfig 都查这里，键集合必须与 order() 完全一致，
     * bin/check-consistency.php 每次都会核对（多一个少一个都报错）。
     *
     * @var array<string, string>
     */
    public const LABELS = [
        'S1_INDEX'      => '索引词',
        'S2_PLOT'       => '剧情主线',
        'S3_FORESHADOW' => '伏笔台账',
        'S4_ADVICE'     => '建议',
        'S5_REVISION'   => '修改方向',
        'S7_READER'     => '读者视角',
    ];

    /**
     * 不在流水线里、但历史上出现过该代号，所以标签仍要查得到。
     *
     * S6_ASSEMBLE：预留，暂无 stage 记录。它是「把五份分析写成四件产出」的纯代码
     * 组装步骤（Pipeline::runWork），不是模型阶段，因此不在 ORDER 里；
     * 但 001_init.sql 的列注释里留着这个代号，将来若有人回填历史记录也要能显示中文。
     *
     * 之所以单独一个常量而不是塞进 LABELS：LABELS 的键集合要和 ORDER 严格相等，
     * 自检脚本才能「多一个少一个都报错」，混在一起这条断言就废了。
     *
     * @var array<string, string>
     */
    public const RESERVED_LABELS = [
        'S6_ASSEMBLE' => '组装归档',
    ];

    public function __construct(
        public readonly string $code,
        public readonly string $label,
        public readonly string $promptFile,
        public readonly bool $thinking,
        public readonly ?string $effort,
        public readonly int $maxTokens,
        public readonly float $temperature,
    ) {
    }

    public static function fromConfig(Config $config, string $code): self
    {
        $raw = $config->get('ai.stage.' . $code);
        if (!is_array($raw)) {
            throw new \RuntimeException('未定义的阶段：' . $code);
        }

        $thinking = (bool) ($raw['thinking'] ?? false);

        return new self(
            code:        $code,
            // 以 LABELS 为准：config 的 label 只是兜底（给「先在 config 里加阶段、
            // 还没登记 LABELS」的过渡期用，正常路径走不到）。
            // 两份都写死时以常量为准，理由见 LABELS 的注释 —— 界面上同一件事只能有一种叫法。
            label:       self::LABELS[$code] ?? (string) ($raw['label'] ?? $code),
            promptFile:  (string) ($raw['prompt'] ?? ''),
            thinking:    $thinking,
            // disabled + effort 的组合会丢类别，这里直接掐掉，不靠调用方自觉
            effort:      $thinking ? (($raw['effort'] ?? null) ?: null) : null,
            maxTokens:   (int) ($raw['max_tokens'] ?? 8192),
            temperature: (float) ($raw['temperature'] ?? 0.2),
        );
    }

    /**
     * @return list<string> 流水线固定顺序（内容见 ORDER 常量）
     */
    public static function order(): array
    {
        return self::ORDER;
    }

    /**
     * 阶段代号 → 中文名。
     *
     * 查不到就原样返回代号：宁可让界面露出机器名，也不要显示一片空白 ——
     * 空白会被当成「模板坏了」，露出代号至少还能看出是哪个阶段。
     */
    public static function label(string $code): string
    {
        return self::LABELS[$code] ?? self::RESERVED_LABELS[$code] ?? $code;
    }

    /** 阶段参数指纹：参与 input_hash，参数一改就必须重算 */
    public function signature(): string
    {
        return implode('|', [
            $this->code,
            $this->thinking ? 'think' : 'nothink',
            (string) $this->effort,
            (string) $this->maxTokens,
            (string) $this->temperature,
        ]);
    }
}
