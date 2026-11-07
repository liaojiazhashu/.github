<?php

declare(strict_types=1);

/**
 * 单篇拆解（同步直跑，不入队）。
 *
 * 用途：
 *   · 调提示词时反复迭代一篇，改完立刻看结果；
 *   · 排查某一篇为什么失败（队列里只留一行错误，这里能看到逐阶段过程）；
 *   · 批量回归脚本的底座。
 *
 * 用法：
 *   php -c php.ini bin/analyze.php --work=01M61EM0NHY29ZY1ZBT5VMFGWH
 *   php -c php.ini bin/analyze.php --work=... --force            # 忽略幂等复用，强制重跑
 *   php -c php.ini bin/analyze.php --work=... --model=deepseek-v4-pro
 *   php -c php.ini bin/analyze.php --all --limit=5               # 按 id 顺序跑待分析作品
 */

use App\Application\Analysis\Pipeline;
use App\Infrastructure\Ai\AiException;
use App\Infrastructure\Ai\CostModel;
use App\Infrastructure\Pdo\WorkRepository;

$container = require dirname(__DIR__) . '/config/container.php';

set_time_limit(0); // --all 批量时一跑几十分钟，不能吃 php.ini 的 120s 上限

$options = [];
foreach (array_slice($argv, 1) as $arg) {
    if (!str_starts_with($arg, '--')) {
        continue;
    }
    [$k, $v] = array_pad(explode('=', substr($arg, 2), 2), 2, '1');
    $options[$k] = $v;
}

/** @var Pipeline $pipeline */
$pipeline = $container->get(Pipeline::class);
/** @var WorkRepository $works */
$works = $container->get(WorkRepository::class);

$force = isset($options['force']);
$model = $options['model'] ?? null;

/** @var list<array{id:int, code:string, title:string}> $targets */
$targets = [];

if (isset($options['work']) && $options['work'] !== '1') {
    $work = $works->findByCode((string) $options['work']);
    if ($work === null) {
        fwrite(STDERR, "找不到作品：{$options['work']}\n");
        exit(1);
    }
    $targets[] = ['id' => (int) $work['id'], 'code' => (string) $work['code'], 'title' => (string) $work['title']];
} elseif (isset($options['all'])) {
    $limit = max(1, (int) ($options['limit'] ?? 5));
    $rows  = $works->paginate(['status' => 'imported'], 1, $limit)['rows'];
    foreach ($rows as $row) {
        $targets[] = ['id' => (int) $row['id'], 'code' => (string) $row['code'], 'title' => (string) $row['title']];
    }
} else {
    fwrite(STDERR, "用法：php -c php.ini bin/analyze.php --work=<作品code> [--force] [--model=...] [--json]\n");
    fwrite(STDERR, "      php -c php.ini bin/analyze.php --all [--limit=5]\n");
    exit(1);
}

if ($targets === []) {
    fwrite(STDOUT, "没有待分析的作品。\n");
    exit(0);
}

$asJson   = isset($options['json']);
$failed   = 0;
$totalCost = 0;

foreach ($targets as $n => $target) {
    fwrite(STDOUT, sprintf("\n[%d/%d] %s  《%s》\n", $n + 1, count($targets), $target['code'], $target['title']));

    $startedAt = microtime(true);

    try {
        $result = $pipeline->runWork($target['id'], [
            'force'      => $force,
            'model'      => $model,
            'on_progress' => static function (string $stage, string $label): void {
                fwrite(STDOUT, sprintf("  → %-14s %s\n", $stage, $label));
            },
        ]);

        $totalCost += (int) $result['cost_micro'];
        $elapsed    = round(microtime(true) - $startedAt, 1);

        if ($asJson) {
            fwrite(STDOUT, json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
        } else {
            fwrite(STDOUT, sprintf(
                "  完成：%s  成本 %.4f 元  耗时 %ss%s\n",
                $result['status'],
                CostModel::toYuan((int) $result['cost_micro']),
                $elapsed,
                $result['reused'] ? '（复用，未调用模型）' : ''
            ));

            foreach ($result['stages'] as $stage) {
                fwrite(STDOUT, sprintf(
                    "    %-14s %-10s in=%-6d out=%-6d cached=%-5d %dms\n",
                    $stage['stage_code'],
                    $stage['status'],
                    (int) $stage['input_tokens'],
                    (int) $stage['output_tokens'],
                    (int) $stage['cached_tokens'],
                    (int) $stage['latency_ms']
                ));
            }

            if ($result['warnings'] !== []) {
                fwrite(STDOUT, "  告警：\n");
                foreach (array_slice($result['warnings'], 0, 12) as $warning) {
                    fwrite(STDOUT, '    · ' . $warning . "\n");
                }
                if (count($result['warnings']) > 12) {
                    fwrite(STDOUT, sprintf("    · …另有 %d 条\n", count($result['warnings']) - 12));
                }
            }
        }
    } catch (AiException $e) {
        $failed++;
        fwrite(STDERR, sprintf("  失败：%s（%s）\n", $e->getMessage(), $e->errorCode));
    } catch (Throwable $e) {
        $failed++;
        fwrite(STDERR, sprintf("  失败：%s\n", $e->getMessage()));
    }
}

if (count($targets) > 1) {
    fwrite(STDOUT, sprintf(
        "\n合计 %d 篇，失败 %d 篇，成本 %.4f 元\n",
        count($targets),
        $failed,
        CostModel::toYuan($totalCost)
    ));
}

exit($failed > 0 ? 1 : 0);
