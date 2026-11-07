<?php

declare(strict_types=1);

/**
 * 批量回归（M3 验收口径：20 篇通过率 ≥ 95%）。
 *
 * 与 analyze.php 的分工：analyze.php 是「跑一篇看看到底哪里出问题」的调试工具，
 * 这个脚本是「一次跑一批、给一个通过率、留一份可对比的报告」的验收工具。
 * 差别在于它把结果分成四类而不是两种 —— 正文太短被拦下是**预期行为**，
 * 不该混进失败里把通过率拉低；相反，被复用（没真正调模型）也不该算通过，
 * 因为那样的"绿"证明不了任何事。
 *
 * 用法：
 *   php -c php.ini bin/regress.php --limit=20
 *   php -c php.ini bin/regress.php --limit=20 --reuse      # 允许幂等复用（便宜，但验证力弱）
 *   php -c php.ini bin/regress.php --limit=20 --threshold=0.9
 *
 * 产物：storage/reports/regression-<时间戳>.json（逐篇明细，可与上一轮 diff）
 */

use App\Application\Analysis\AnalysisException;
use App\Application\Analysis\Pipeline;
use App\Infrastructure\Ai\AiException;
use App\Infrastructure\Ai\CostModel;
use App\Infrastructure\Pdo\WorkRepository;

$container = require dirname(__DIR__) . '/config/container.php';

$options = [];
foreach (array_slice($argv, 1) as $arg) {
    if (!str_starts_with($arg, '--')) {
        continue;
    }
    [$k, $v] = array_pad(explode('=', substr($arg, 2), 2), 2, '1');
    $options[$k] = $v;
}

$limit     = max(1, min(100, (int) ($options['limit'] ?? 20)));
$threshold = (float) ($options['threshold'] ?? 0.95);
$model     = isset($options['model']) && $options['model'] !== '1' ? (string) $options['model'] : null;
$force     = !isset($options['reuse']);
$asJson    = isset($options['json']);

/** @var Pipeline $pipeline */
$pipeline = $container->get(Pipeline::class);
/** @var WorkRepository $works */
$works = $container->get(WorkRepository::class);

$rows = isset($options['work']) && $options['work'] !== '1'
    ? array_values(array_filter([$works->findByCode((string) $options['work'])]))
    : $works->paginate([], 1, $limit)['rows'];

if ($rows === []) {
    fwrite(STDOUT, "库里没有作品可回归。\n");
    exit(0);
}

$report = [
    'started_at' => date('c'),
    'limit'      => $limit,
    'force'      => $force,
    'model'      => $model ?? 'default',
    'threshold'  => $threshold,
    'items'      => [],
];

$costTotal   = 0;
$okCount     = 0;
$reusedCount = 0;
$rejectCount = 0;
$failCount   = 0;
/** @var array<string, array{ok:int, bad:int}> $stageStats */
$stageStats  = [];

fwrite(STDOUT, sprintf(
    "回归开始：%d 篇，%s，通过线 %.0f%%\n",
    count($rows),
    $force ? '强制重跑（不复用）' : '允许复用',
    $threshold * 100
));

foreach ($rows as $n => $row) {
    $workId = (int) $row['id'];
    $title  = (string) $row['title'];
    $chars  = (int) $row['word_count'];
    $label  = sprintf('[%2d/%2d] #%-3d %-16s %5d 字', $n + 1, count($rows), $workId, $title, $chars);
    fwrite(STDOUT, $label . ' … ');

    $started = microtime(true);
    $item    = [
        'work_id'    => $workId,
        'code'       => (string) $row['code'],
        'title'      => $title,
        'chars'      => $chars,
        'outcome'    => '',
        'analysis'   => null,
        'status'     => null,
        'cost_micro' => 0,
        'elapsed'    => 0.0,
        'error'      => null,
        'warnings'   => [],
        'stages'     => [],
    ];

    try {
        $result = $pipeline->runWork($workId, ['force' => $force, 'model' => $model]);

        $costTotal += (int) $result['cost_micro'];
        $item['analysis']   = $result['code'];
        $item['status']     = $result['status'];
        $item['cost_micro'] = (int) $result['cost_micro'];
        $item['warnings']   = $result['warnings'];
        $item['stages']     = array_map(
            static fn (array $s): array => [
                'stage'  => (string) $s['stage_code'],
                'status' => (string) $s['status'],
                'in'     => (int) $s['input_tokens'],
                'out'    => (int) $s['output_tokens'],
                'cached' => (int) $s['cached_tokens'],
                'ms'     => (int) $s['latency_ms'],
                'error'  => $s['error_msg'] ?? null,
            ],
            $result['stages']
        );

        foreach ($item['stages'] as $stage) {
            $stageStats[$stage['stage']] ??= ['ok' => 0, 'bad' => 0];
            $stage['status'] === 'succeeded'
                ? $stageStats[$stage['stage']]['ok']++
                : $stageStats[$stage['stage']]['bad']++;
        }

        if ($result['reused']) {
            $item['outcome'] = 'reused';
            $reusedCount++;
            fwrite(STDOUT, sprintf("复用  %s\n", $result['code']));
        } elseif (in_array($result['status'], ['succeeded', 'published'], true)) {
            $item['outcome'] = 'succeeded';
            $okCount++;
            fwrite(STDOUT, sprintf(
                "通过  %s  ¥%.4f  %ds\n",
                $result['code'],
                CostModel::toYuan((int) $result['cost_micro']),
                (int) round(microtime(true) - $started)
            ));
        } else {
            $item['outcome'] = 'failed';
            $item['error']   = '状态为 ' . $result['status'];
            $failCount++;
            fwrite(STDOUT, sprintf("失败  状态 %s  ¥%.4f\n", $result['status'], CostModel::toYuan((int) $result['cost_micro'])));
        }
    } catch (AnalysisException $e) {
        if ($e->errorCode === AnalysisException::TEXT_TOO_SHORT) {
            $item['outcome'] = 'rejected';
            $item['error']   = $e->getMessage();
            $rejectCount++;
            fwrite(STDOUT, "拦下（正文过短，零费用）\n");
        } else {
            $item['outcome'] = 'failed';
            $item['error']   = $e->getMessage();
            $failCount++;
            fwrite(STDOUT, '失败  ' . $e->getMessage() . "\n");
        }
    } catch (AiException $e) {
        $item['outcome'] = 'failed';
        $item['error']   = $e->errorCode . '：' . $e->getMessage();
        $failCount++;
        fwrite(STDOUT, '失败  ' . $item['error'] . "\n");
    } catch (Throwable $e) {
        $item['outcome'] = 'failed';
        $item['error']   = $e->getMessage();
        $failCount++;
        fwrite(STDOUT, '失败  ' . $item['error'] . "\n");
    }

    $item['elapsed'] = round(microtime(true) - $started, 1);
    $report['items'][] = $item;
}

$judged   = count($rows) - $rejectCount;
$passRate = $judged > 0 ? $okCount / $judged : 0.0;

$report['finished_at']  = date('c');
$report['summary'] = [
    'total'      => count($rows),
    'succeeded'  => $okCount,
    'reused'     => $reusedCount,
    'rejected'   => $rejectCount,
    'failed'     => $failCount,
    'judged'     => $judged,
    'pass_rate'  => round($passRate, 4),
    'cost_micro' => $costTotal,
    'stages'     => $stageStats,
];

$dir = dirname(__DIR__) . '/storage/reports';
if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
    fwrite(STDERR, "报告目录创建失败：{$dir}\n");
} else {
    $path = $dir . '/regression-' . date('Ymd-His') . '.json';
    file_put_contents(
        $path,
        json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
    );
    @chmod($path, 0640);
    fwrite(STDOUT, "\n报告：{$path}\n");
}

if ($asJson) {
    fwrite(STDOUT, json_encode($report['summary'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
}

fwrite(STDOUT, sprintf(
    "\n合计 %d 篇：通过 %d / 复用 %d / 拦下 %d / 失败 %d\n通过率 %.1f%%（分母 %d = 总篇数 - 被拦下的 %d 篇）\n成本 ¥%.4f\n",
    count($rows),
    $okCount,
    $reusedCount,
    $rejectCount,
    $failCount,
    $passRate * 100,
    $judged,
    $rejectCount,
    CostModel::toYuan($costTotal)
));

if ($stageStats !== []) {
    fwrite(STDOUT, "\n分阶段：\n");
    foreach ($stageStats as $stage => $stat) {
        fwrite(STDOUT, sprintf("  %-14s 成功 %2d / 失败 %2d\n", $stage, $stat['ok'], $stat['bad']));
    }
}

if ($failCount > 0) {
    fwrite(STDOUT, "\n失败明细：\n");
    foreach ($report['items'] as $item) {
        if ($item['outcome'] === 'failed') {
            fwrite(STDOUT, sprintf("  #%d 《%s》 %s\n", $item['work_id'], $item['title'], (string) $item['error']));
        }
    }
}

exit($passRate + 1e-9 >= $threshold ? 0 : 1);
