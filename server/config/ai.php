<?php

declare(strict_types=1);

/**
 * AI 相关配置的唯一来源（规划文档 §7.1「所有取值集中在 config/ai.php」）。
 *
 * 为什么单独一个文件而不是塞进 settings.php：
 *   1. 价格与模型名会变，改动频率远高于数据库/会话配置，单独成文件便于对账时一眼看全；
 *   2. 六阶段的 thinking / effort / max_tokens 是一张表，放一起才能看出「机械阶段 8k、
 *      判断阶段 32k」这条实测结论（§7.1.1③）；
 *   3. 界面上要显示当前单价，需要程序可读的结构，不能写在注释里。
 *
 * 键路径：ai.model.default / ai.stage.S2_PLOT.max_tokens / ai.price.deepseek-flash.miss_peak
 */

$env = static function (string $key, ?string $default = null): ?string {
    $value = $_ENV[$key] ?? getenv($key);
    if ($value === false || $value === null || $value === '') {
        return $default;
    }
    return is_string($value) ? trim($value) : $default;
};

$bool = static fn (string $key, bool $default): bool => match (strtolower((string) $env($key, $default ? '1' : '0'))) {
    '1', 'true', 'yes', 'on'  => true,
    '0', 'false', 'no', 'off' => false,
    default                   => $default,
};

return [
    // 总开关：关掉即全线停止（应急刹车，§7.4 成本护栏第 3 条）
    'enabled' => $bool('AI_ENABLED', false),

    'base_url'        => rtrim((string) $env('AI_BASE_URL', 'https://api.deepseek.com'), '/'),
    'api_key'         => (string) $env('DEEPSEEK_API_KEY', ''),

    // 出站白名单（§11.13 SSRF）：解析后的 host 必须逐字命中，不做后缀匹配
    'host_allow'      => ['api.deepseek.com'],

    // 生产环境的 CA 包；为空则用系统默认。Extras 里带的那份用于 Windows 绿色版 PHP
    'cafile'          => (string) $env('AI_CAFILE', ''),

    'connect_timeout' => (int) $env('AI_CONNECT_TIMEOUT', '15'),
    'timeout'         => (int) $env('AI_TIMEOUT', '300'),

    // 单阶段 HTTP/传输层最多尝试次数（闸门不通过的重试另算，见 gate_retry）
    'retry_max'       => (int) $env('AI_RETRY_MAX', '3'),
    'retry_backoff'   => [5, 20, 60],       // 秒，按尝试序号取
    'gate_retry_max'  => 2,                 // 闸门不通过时把失败原因回灌给模型，最多 2 次（§6.4 G1）

    'model' => [
        'default' => (string) $env('AI_MODEL', 'deepseek-flash'),
        'heavy'   => (string) $env('AI_MODEL_HEAVY', 'deepseek-v4-pro'),
    ],

    'temperature'     => (float) $env('AI_TEMPERATURE', '0.2'),
    'max_output'      => (int) $env('AI_MAX_OUTPUT_TOKENS', '32000'),
    'max_concurrency' => max(1, (int) $env('AI_MAX_CONCURRENCY', '2')),

    'budget' => [
        'daily_yuan'   => (float) $env('AI_DAILY_BUDGET_YUAN', '100'),
        'per_work_yuan' => (float) $env('AI_PER_WORK_BUDGET_YUAN', '3'),
    ],

    /**
     * 价格：元 / 百万 tokens，格式 [空闲, 高峰]。
     * 高峰时段 = 周一至周五 9:00–12:00、14:00–18:00（法定节假日除外），其余半价。
     * 对账以 hq_ai_call 的 token 数与 DeepSeek 账单双向核对，不要把这里当唯一真相。
     */
    'price' => [
        'deepseek-flash' => [
            'hit'  => [0.02, 0.04],
            'miss' => [1.0, 2.0],
            'out'  => [4.0, 8.0],
        ],
        'deepseek-v4-pro' => [
            'hit'  => [0.15, 0.30],
            'miss' => [4.5, 9.0],
            'out'  => [13.5, 27.0],
        ],
    ],

    'peak_hours' => [
        'weekdays' => [1, 2, 3, 4, 5],       // ISO-8601 的 N
        'ranges'   => [[9, 12], [14, 18]],   // 左闭右开
    ],

    /**
     * 六阶段执行参数。
     *
     * ⚠️ max_tokens 的取值依据 §7.1.1③ 的实测：两个模型都是思考型，reasoning token
     * **占用 max_tokens 额度**，给小了会返回空 content + finish_reason=length。
     * 因此附录 §6.5 早期写的「S3 3072」不能用，一律按「期望输出 + 思考量」给：
     * 机械阶段 8k，判断阶段 32k。
     *
     * thinking=false 只用于「看得见就能标」的机械活；`disabled + effort=low` 会丢类别，
     * 所以 thinking=false 时不传 effort（§7.1.1④ 明令禁用该组合）。
     */
    'stage' => [
        'S1_INDEX' => [
            'prompt'      => 's1_index',
            'label'       => '索引词',
            'thinking'    => false,
            'effort'      => null,
            'max_tokens'  => 8192,
            'temperature' => 0.1,
        ],
        'S2_PLOT' => [
            'prompt'      => 's2_plot',
            'label'       => '剧情主线',
            'thinking'    => true,
            'effort'      => (string) $env('AI_EFFORT_DEEP', 'high'),
            'max_tokens'  => 32768,
            'temperature' => 0.2,
        ],
        'S3_FORESHADOW' => [
            'prompt'      => 's3_foreshadow',
            'label'       => '伏笔台账',
            'thinking'    => true,
            'effort'      => (string) $env('AI_EFFORT_DEEP', 'high'),
            'max_tokens'  => 16384,
            'temperature' => 0.2,
        ],
        'S4_ADVICE' => [
            'prompt'      => 's4_advice',
            'label'       => '建议',
            'thinking'    => true,
            'effort'      => (string) $env('AI_EFFORT_DEEP', 'high'),
            'max_tokens'  => 32768,
            'temperature' => 0.25,
        ],
        'S5_REVISION' => [
            'prompt'      => 's5_revision',
            'label'       => '修改方向',
            'thinking'    => true,
            'effort'      => (string) $env('AI_EFFORT_DEEP', 'high'),
            'max_tokens'  => 32768,
            'temperature' => 0.2,
        ],
        // 读者视角。编号跳过 S6 是历史原因：S6 从来不是模型阶段，
        // 而是「把上面五步组装成四件产出」的纯代码步骤（见 Pipeline::runWork）。
        // 给新阶段重编号会改掉库里已有的 S6_ASSEMBLE 记录，得不偿失。
        'S7_READER' => [
            'prompt'      => 's7_reader',
            'label'       => '读者视角',
            'thinking'    => false,
            'effort'      => null,
            'max_tokens'  => 6144,
            // 比分析类阶段略高：这一块要写「像人话」的判断，太低会写成模板腔
            'temperature' => 0.45,
        ],
    ],

    // v2：关键词从「五类 30 个」收成「三类 5 个 + 百科定义」，并新增读者视角阶段。
    // 旧分析仍可用 v1 复现：提示词文件按版本留档，不覆盖。
    // v3：关键词改为「科幻类型通行词 + 受控词表」。v1/v2 保留不删 ——
    // v4：加「科幻强关联」第一道闸门 + 退役词反向清单；数量由「最多 5 个」
    //     改为「5 是上限不是配额」，允许交空数组。v1–v3 全部留档。
    'prompt_version' => (string) $env('AI_PROMPT_VERSION', 'v4'),

    // 正文超过这个字数才切块（比 1M 上下文小得多，切块是为了成本与 JSON 稳定性，§6.3）
    'chunk_threshold' => 20000,
    'chunk_max_chars' => 12000,
    'chunk_overlap'   => 300,

    // 阶段产物落盘目录（只存模型响应，不存请求全文，§7.5）
    'artifact_dir'    => dirname(__DIR__) . '/storage/artifacts',
];
