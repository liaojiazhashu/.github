<?php

declare(strict_types=1);

/**
 * 重跑某一篇（或全部在架）作品的某个分析阶段。
 *
 * 三种典型用法：
 *   换提示词后只换关键词，不动下游 ——
 *     php -c php.ini bin/analysis-rerun.php --all
 *   改了一篇的正文，重跑 S1 并把下游一起带下去 ——
 *     php -c php.ini bin/analysis-rerun.php --work=CODE --cascade
 *   只重写终稿（S7，读者页文案都在这一步）——
 *     php -c php.ini bin/analysis-rerun.php --work=CODE --stage=S7_READER
 *
 * 为什么默认**不级联**：级联会连带重写建议与修改方向，那是另一个决定。
 * 先看清新关键词长什么样，再决定要不要往下推。
 *
 * 注意：本脚本会真调模型、真花钱（约 0.003 元/篇/阶段）。长任务请放后台跑，
 * 前台 120 秒会被掐断，留下的 running 记录用 analysis:reap --apply 收。
 */

use App\Application\Analysis\Pipeline;
use App\Infrastructure\Pdo\AnalysisRepository;
use App\Infrastructure\Pdo\WorkRepository;

$container = require dirname(__DIR__) . '/config/container.php';

set_time_limit(0); // --all 批量重跑一跑几十分钟，不能吃 php.ini 的 120s 上限

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
用法：php -c php.ini bin/analysis-rerun.php [--work=CODE | --all] [--stage=S1_INDEX] [--cascade]

  --work=CODE        只跑这一篇；不给地址则跑全部在架作品
  --stage=NAME       阶段名，默认 S1_INDEX（可选 S2_PLOT / S3_FORESHADOW /
                     S4_ADVICE / S5_REVISION / S7_READER）
  --cascade          连带把下游阶段一起重跑
  --help             看这段说明

TXT);
    exit(0);
}

/** @var Pipeline $pipeline */
$pipeline = $container->get(Pipeline::class);
/** @var AnalysisRepository $analyses */
$analyses = $container->get(AnalysisRepository::class);
/** @var WorkRepository $works */
$works = $container->get(WorkRepository::class);

$stage   = (string) ($options['stage'] ?? 'S1_INDEX');
$cascade = isset($options['cascade']);

$targets = [];
if (isset($options['work']) && $options['work'] !== '1') {
    $work = $works->findByCode((string) $options['work']);
    if ($work === null) {
        fwrite(STDERR, "找不到作品：{$options['work']}\n");
        exit(1);
    }
    $targets[] = $work;
} else {
    $targets = $works->paginate(['status' => 'published'], 1, 100)['rows'];
}

fwrite(STDOUT, sprintf("阶段 %s%s · 目标 %d 篇\n", $stage, $cascade ? '（级联）' : '', count($targets)));

$failed = 0;
foreach ($targets as $work) {
    $workId = (int) $work['id'];
    $latest = $analyses->latestForWork($workId);
    if ($latest === null) {
        fwrite(STDOUT, sprintf("· 《%s》没有分析记录，跳过\n", $work['title']));
        continue;
    }

    $start = microtime(true);
    try {
        $result = $pipeline->rerunStage((int) $latest['id'], $stage, $cascade, 1);
        $cost   = ((int) $result['cost_micro']) / 1_000_000;
        fwrite(STDOUT, sprintf(
            "· 《%s》 完成　%.1fs　%.3f 元　告警 %d 条\n",
            $work['title'],
            microtime(true) - $start,
            $cost,
            count($result['warnings'])
        ));
    } catch (Throwable $e) {
        $failed++;
        fwrite(STDERR, sprintf("· 《%s》 失败：%s\n", $work['title'], $e->getMessage()));
    }
}

// 有失败就非零退出，否则后台跑完看不出问题。
exit($failed === 0 ? 0 : 1);
