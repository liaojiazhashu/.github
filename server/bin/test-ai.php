<?php

declare(strict_types=1);

/**
 * DeepSeek 连通性与参数自检。
 *
 * 这个脚本存在的理由，是把 §7.1.1 那几条「反直觉的实测结论」变成随时可复现的检查，
 * 而不是躺在文档里当传闻：
 *   · 账号到底看得见哪几个模型；
 *   · max_tokens 给小了会不会返回空 content（思考 token 占额度）；
 *   · thinking 开/关、effort 档位对耗时与输出的影响。
 *
 * 用法：
 *   php -c php.ini bin/test-ai.php            # 默认：模型清单 + 一次真实索引词抽取
 *   php -c php.ini bin/test-ai.php --heavy    # 追加 max_tokens 阶梯与思考开关对照（会多次计费）
 *   php -c php.ini bin/test-ai.php --dry      # 只看配置，不发起任何调用
 */

use App\Infrastructure\Ai\AiException;
use App\Infrastructure\Ai\CostModel;
use App\Infrastructure\Ai\DeepSeekClient;
use App\Support\Config;

$container = require dirname(__DIR__) . '/config/container.php';

$options = [];
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--')) {
        $options[substr($arg, 2)] = true;
    }
}

/** @var Config $config */
$config = $container->get(Config::class);
/** @var DeepSeekClient $client */
$client = $container->get(DeepSeekClient::class);
/** @var CostModel $cost */
$cost = $container->get(CostModel::class);

fwrite(STDOUT, "=== 配置 ===\n");
fwrite(STDOUT, '  base_url      : ' . $config->string('ai.base_url') . "\n");
fwrite(STDOUT, '  model         : ' . $config->string('ai.model.default') . "\n");
fwrite(STDOUT, '  model(heavy)  : ' . $config->string('ai.model.heavy') . "\n");
fwrite(STDOUT, '  enabled       : ' . ($config->bool('ai.enabled') ? 'yes' : 'no') . "\n");
fwrite(STDOUT, '  api_key       : ' . ($client->isConfigured() ? '已配置（长度 ' . strlen($config->string('ai.api_key')) . '）' : '缺失') . "\n");
fwrite(STDOUT, '  timeout       : ' . $config->int('ai.timeout') . "s / 连接 " . $config->int('ai.connect_timeout') . "s\n");
fwrite(STDOUT, '  计时档位      : ' . $cost->peakLabel() . "\n");
fwrite(STDOUT, '  六阶段参数    :' . "\n");
foreach ((array) $config->get('ai.stage', []) as $code => $spec) {
    fwrite(STDOUT, sprintf(
        "    %-14s thinking=%-5s effort=%-5s max_tokens=%-6d temp=%.2f\n",
        $code,
        ($spec['thinking'] ?? false) ? 'on' : 'off',
        $spec['effort'] ?? '-',
        (int) $spec['max_tokens'],
        (float) $spec['temperature']
    ));
}

if (!$client->isConfigured()) {
    fwrite(STDERR, "\n未配置 DEEPSEEK_API_KEY，后续检查跳过。\n");
    exit(1);
}

if (isset($options['dry'])) {
    fwrite(STDOUT, "\n--dry：不发起调用。\n");
    exit(0);
}

fwrite(STDOUT, "\n=== 模型清单 ===\n");
try {
    foreach ($client->models() as $model) {
        fwrite(STDOUT, sprintf(
            "  %-20s 上下文 %s  最大输出 %s\n",
            $model['id'],
            $model['context'] > 0 ? number_format($model['context']) : '未知',
            $model['max_output'] > 0 ? number_format($model['max_output']) : '未知'
        ));
    }
} catch (AiException $e) {
    fwrite(STDERR, '  拉取失败：' . $e->getMessage() . "\n");
}

$text = "第七次日出\n\n探测船在第七个轨道周期收到同一段信号。工程师把它拆成三段，每一段都在说不同的谎。\n"
    . "她决定相信第三段，因为只有第三段提到了她的名字。倒计时结束时，信号源从引力波背景里浮出来，\n"
    . "像一枚被反复擦亮的旧硬币。\n";

$messages = [
    ['role' => 'system', 'content' => "你是拆解编辑。只输出 json。"],
    ['role' => 'user', 'content' => "给下面这段文字抽 5 个关键词，分 theme/tech/society/image/tone 五类，"
        . "输出 json：{\"terms\":[{\"canonical\":\"…\",\"category\":\"theme\"}]}\n\n" . $text],
];

/**
 * @return array{0: bool, 1: string} [是否成功, 一行说明]
 */
$probe = static function (array $opts, string $label) use ($client, $messages, $cost): array {
    $startedAt = microtime(true);
    try {
        $response = $client->chat($messages, $opts);
    } catch (AiException $e) {
        return [false, sprintf('%-26s 失败：%s', $label, $e->getMessage())];
    }
    $elapsed = round(microtime(true) - $startedAt, 1);

    $line = sprintf(
        '%-26s %5.1fs  out=%-5d 思考=%-5d 缓存命中=%-5d finish=%-8s 成本 %.5f 元  %s',
        $label,
        $elapsed,
        $response->completionTokens,
        $response->reasoningTokens,
        $response->cachedTokens,
        $response->finishReason !== '' ? $response->finishReason : '-',
        CostModel::toYuan($response->costMicro),
        $response->isEmpty() ? '空 content' : '有输出'
    );

    return [!$response->isEmpty(), $line];
};

fwrite(STDOUT, "\n=== 真实调用（索引词抽取） ===\n");
[$ok, $line] = $probe([
    'model'       => $config->string('ai.model.default'),
    'temperature' => 0.1,
    'max_tokens'  => 8192,
    'thinking'    => false,
    'json'        => true,
], 'S1 参数（思考关闭）');
fwrite(STDOUT, '  ' . $line . "\n");

if (!$ok) {
    fwrite(STDERR, "\n索引词抽取未成功，先修好这一关再看后面的对照。\n");
    exit(1);
}

if (!isset($options['heavy'])) {
    fwrite(STDOUT, "\n（加 --heavy 可看 max_tokens 阶梯与思考开关对照，会多次计费）\n");
    exit(0);
}

fwrite(STDOUT, "\n=== max_tokens 阶梯（验证「思考吃光额度会返回空 content」） ===\n");
foreach ([32, 400, 2048, 8192] as $maxTokens) {
    [$ok, $line] = $probe([
        'model'       => $config->string('ai.model.default'),
        'temperature' => 0.1,
        'max_tokens'  => $maxTokens,
        'thinking'    => true,
        'effort'      => 'high',
        'json'        => true,
    ], 'max_tokens=' . $maxTokens);
    fwrite(STDOUT, '  ' . $line . "\n");
}

fwrite(STDOUT, "\n=== 思考开关对照（同一提示词） ===\n");
foreach ([
    ['thinking' => true,  'effort' => 'high', 'label' => '思考开 + effort=high'],
    ['thinking' => true,  'effort' => 'low',  'label' => '思考开 + effort=low'],
    ['thinking' => true,  'effort' => 'max',  'label' => '思考开 + effort=max'],
    ['thinking' => false, 'effort' => null,   'label' => '思考关（本方案 S1 用它）'],
    ['thinking' => false, 'effort' => 'low',  'label' => '思考关 + effort=low（禁用）'],
] as $probeCase) {
    [$ok, $line] = $probe([
        'model'       => $config->string('ai.model.default'),
        'temperature' => 0.1,
        'max_tokens'  => 8192,
        'thinking'    => $probeCase['thinking'],
        'effort'      => $probeCase['effort'],
        'json'        => true,
    ], $probeCase['label']);
    fwrite(STDOUT, '  ' . $line . "\n");
}

fwrite(STDOUT, "\n判读：最后一行的「思考关 + effort=low」如果输出里的类别比前面少，就印证了该组合会丢类别。\n");
