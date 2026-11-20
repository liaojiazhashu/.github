<?php

declare(strict_types=1);

/**
 * backfill.php —— 存量作品全量重跑补齐
 *
 * 背景：三批优化给拆解方案新增了字段，但存量 published 作品是旧提示词跑出来的，
 * 这些字段全空。本脚本把存量 published 作品按需重跑补齐，逐篇写日志、结尾汇总。
 *
 * 三个阶段（独立，可单独跑也可 all 顺序跑）：
 *   S7_READER   口味卡三件（五轴 axes / 内容提示 notes / 编辑判断 verdict）—— 所有 published 都跑
 *   S4_ADVICE   建议位置写法 at —— 只跑 tier=t3 且建议 at 为空的
 *   S5_REVISION 八维评分 dims —— 只跑 tier=t3 且 revision dims 为空的
 *
 * 用法：
 *   php bin/backfill.php --stage=S7_READER          # 只补口味卡
 *   php bin/backfill.php --stage=all                # 依次跑 S7 -> S4 -> S5（默认）
 *   php bin/backfill.php --stage=S4_ADVICE --dry    # 只列清单不真跑
 *   php bin/backfill.php --stage=S7_READER --limit=10   # 只跑前 10 篇（试跑用）
 *
 * 说明：
 *   - 真调模型、真花钱。S7 约 0.015 元/篇，S4/S5 约 0.05-0.11 元/篇。
 *   - 幂等：rerunStage 幂等；断点续跑可重复执行本脚本（已补齐的会再次花少量钱但结果一致，
 *     因为清单查询只挑「字段仍为空」的，跑完一篇它就不再进清单）。
 *   - 长任务放后台：nohup php bin/backfill.php --stage=all > storage/logs/backfill-$(date +%F-%H%M).log 2>&1 &
 *   - 单日预算护栏默认 100 元，全量三阶段预估 100-230 元，启动前应把 ai.daily_budget_yuan 调高。
 */

use App\Application\Analysis\Pipeline;
use App\Infrastructure\Pdo\AnalysisRepository;
use App\Infrastructure\Pdo\WorkRepository;

$container = require dirname(__DIR__) . '/config/container.php';

/** @var Pipeline $pipeline */
$pipeline = $container->get(Pipeline::class);

set_time_limit(0);

// ---- 参数解析 ----
$options = [];
foreach (array_slice($argv, 1) as $arg) {
    if (!str_starts_with($arg, '--')) {
        continue;
    }
    [$k, $v] = array_pad(explode('=', substr($arg, 2), 2), 2, '1');
    $options[$k] = $v;
}

if (isset($options['help'])) {
    fwrite(STDOUT, <<<TXT
用法：php bin/backfill.php [--stage=S7_READER|S4_ADVICE|S5_REVISION|all] [--dry] [--limit=N]

  --stage=NAME   阶段名；all = 依次跑 S7 -> S4 -> S5（默认）
  --dry          只列待补齐清单，不真调模型
  --limit=N      每阶段最多跑 N 篇（试跑/分批用）
  --help         看这段说明

TXT);
    exit(0);
}

$stageArg = (string) ($options['stage'] ?? 'all');
$dry      = isset($options['dry']);
$limit    = isset($options['limit']) ? max(1, (int) $options['limit']) : 0;

$stageList = $stageArg === 'all'
    ? ['S7_READER', 'S4_ADVICE', 'S5_REVISION']
    : [$stageArg];

$stageLabel = [
    'S7_READER'   => '口味卡三件',
    'S4_ADVICE'   => '位置写法 at',
    'S5_REVISION' => '八维评分 dims',
];

// ---- 待补齐清单查询 ----
function backlog(PDO $pdo, string $stage): array
{
    if ($stage === 'S7_READER') {
        // 所有 published 作品：reader_brief 缺行或 axes_json 为空即待补（t1/t2/t3 都含 S7）
        $sql = <<<SQL
SELECT w.id AS work_id, w.code, w.title, w.current_analysis_id AS analysis_id
FROM hq_work w
LEFT JOIN hq_reader_brief rb ON rb.analysis_id = w.current_analysis_id
WHERE w.status = 'published'
  AND w.deleted_at IS NULL
  AND w.current_analysis_id IS NOT NULL
  AND (rb.id IS NULL OR rb.axes_json IS NULL)
ORDER BY w.id
SQL;
    } elseif ($stage === 'S4_ADVICE') {
        // 只有 t3 才含 S4：tier=t3 且存在 at 为空的建议
        $sql = <<<SQL
SELECT w.id AS work_id, w.code, w.title, w.current_analysis_id AS analysis_id
FROM hq_work w
JOIN hq_analysis a ON a.id = w.current_analysis_id
WHERE w.status = 'published'
  AND w.deleted_at IS NULL
  AND a.tier = 't3'
  AND EXISTS (SELECT 1 FROM hq_suggestion sg WHERE sg.analysis_id = a.id AND sg.at IS NULL)
ORDER BY w.id
SQL;
    } else { // S5_REVISION
        // 只有 t3 才含 S5：tier=t3 且存在 dims 为空的 revision
        $sql = <<<SQL
SELECT w.id AS work_id, w.code, w.title, w.current_analysis_id AS analysis_id
FROM hq_work w
JOIN hq_analysis a ON a.id = w.current_analysis_id
WHERE w.status = 'published'
  AND w.deleted_at IS NULL
  AND a.tier = 't3'
  AND EXISTS (SELECT 1 FROM hq_revision r WHERE r.analysis_id = a.id AND r.dims_json IS NULL)
ORDER BY w.id
SQL;
    }
    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

// ---- 主流程 ----
/** @var PDO $pdo */
$pdo = $container->get(PDO::class);

$grand = ['ok' => 0, 'fail' => 0, 'cost' => 0.0];
$grandT0 = microtime(true);

foreach ($stageList as $stage) {
    $rows = backlog($pdo, $stage);
    if ($limit > 0) {
        $rows = array_slice($rows, 0, $limit);
    }
    $total = count($rows);
    $label = $stageLabel[$stage] ?? $stage;

    fwrite(STDOUT, sprintf(
        "[%s] === 阶段 %s（%s）：待补齐 %d 篇 ===\n",
        date('Y-m-d H:i:s'), $stage, $label, $total
    ));

    if ($dry) {
        foreach ($rows as $r) {
            fwrite(STDOUT, sprintf("  - %s 《%s》 analysis=%d\n", $r['code'], $r['title'], $r['analysis_id']));
        }
        continue;
    }

    $ok = 0;
    $fail = 0;
    $cost = 0.0;
    $t0 = microtime(true);

    foreach ($rows as $i => $r) {
        $start = microtime(true);
        try {
            $result = $pipeline->rerunStage((int) $r['analysis_id'], $stage, false, 1);
            $c = ((int) ($result['cost_micro'] ?? 0)) / 1_000_000;
            $cost += $c;
            $ok++;
            fwrite(STDOUT, sprintf(
                "[%s] %d/%d %s 《%s》 完成 %.1fs %.3f元 告警%d\n",
                date('H:i:s'),
                $i + 1,
                $total,
                $r['code'],
                $r['title'],
                microtime(true) - $start,
                $c,
                count($result['warnings'] ?? [])
            ));
        } catch (Throwable $e) {
            $fail++;
            fwrite(STDERR, sprintf(
                "[%s] %d/%d %s 《%s》 失败：%s\n",
                date('H:i:s'),
                $i + 1,
                $total,
                $r['code'],
                $r['title'],
                $e->getMessage()
            ));
        }
    }

    fwrite(STDOUT, sprintf(
        "[%s] === 阶段 %s 结束：成功 %d 失败 %d 耗时 %.0fs 成本 %.2f元 ===\n\n",
        date('Y-m-d H:i:s'), $stage, $ok, $fail, microtime(true) - $t0, $cost
    ));

    $grand['ok'] += $ok;
    $grand['fail'] += $fail;
    $grand['cost'] += $cost;
}

fwrite(STDOUT, sprintf(
    "[%s] === 全部结束：成功 %d 失败 %d 总耗时 %.0fs 总成本 %.2f元 ===\n",
    date('Y-m-d H:i:s'),
    $grand['ok'],
    $grand['fail'],
    microtime(true) - $grandT0,
    $grand['cost']
));

exit($grand['fail'] === 0 ? 0 : 1);
