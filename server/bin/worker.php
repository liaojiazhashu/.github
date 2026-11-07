<?php

declare(strict_types=1);

/**
 * 队列 worker：抢占任务 → 执行 → 记结果。
 *
 * 本机没有 pcntl（Windows 版 PHP 不带），所以不用信号处理优雅退出，
 * 改用「跑够 N 个任务 / 跑够 N 秒就退」——这两种退出时机都落在任务边界上，
 * 不会把任务砍在半路（真被强杀也没关系，心跳超时后会被 salvage 回队列）。
 *
 * 用法：
 *   php -c php.ini bin/worker.php                        # 常驻，Ctrl+C 结束
 *   php -c php.ini bin/worker.php --once                 # 处理一条就退（适合计划任务）
 *   php -c php.ini bin/worker.php --max-jobs=20 --max-seconds=3600
 *   php -c php.ini bin/worker.php --name=w2 --stale=900
 */

use App\Application\Analysis\AnalysisCanceled;
use App\Application\Analysis\AnalysisException;
use App\Application\Analysis\Pipeline;
use App\Application\Analysis\PublishService;
use App\Application\Library\VisibilityService;
use App\Application\Source\SourceIngest;
use App\Infrastructure\Ai\AiException;
use App\Infrastructure\Pdo\JobRepository;
use App\Infrastructure\Pdo\SettingRepository;

$container = require dirname(__DIR__) . '/config/container.php';

// CLI 的 max_execution_time 沿用 php.ini 的 120s —— 对常驻 worker 意味着
// 跑满两分钟就被砍。任务边界靠心跳与僵尸回收兜底，但正常路径不该触发它。
set_time_limit(0);

$options = [];
foreach (array_slice($argv, 1) as $arg) {
    if (!str_starts_with($arg, '--')) {
        continue;
    }
    [$k, $v] = array_pad(explode('=', substr($arg, 2), 2), 2, '1');
    $options[$k] = $v;
}

/** @var JobRepository $jobs */
$jobs = $container->get(JobRepository::class);
/** @var Pipeline $pipeline */
$pipeline = $container->get(Pipeline::class);
/** @var SourceIngest $ingest */
$ingest = $container->get(SourceIngest::class);
/** @var SettingRepository $settings */
$settings = $container->get(SettingRepository::class);
/** @var VisibilityService $visibility */
$visibility = $container->get(VisibilityService::class);

$name       = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string) ($options['name'] ?? 'w' . getmypid())) ?: 'worker';
$once       = isset($options['once']);
$maxJobs    = max(1, (int) ($options['max-jobs'] ?? 1_000_000));
$maxSeconds = max(1, (int) ($options['max-seconds'] ?? 86400));
$idleSleep  = max(1, (int) ($options['idle'] ?? 2));
$stale      = max(60, (int) ($options['stale'] ?? 900));

$startedAt = time();
$handled   = 0;

$salvaged = $jobs->reclaimZombies($stale);
if ($salvaged > 0) {
    fwrite(STDOUT, sprintf("[%s] 回收僵尸任务 %d 条\n", $name, $salvaged));
}

fwrite(STDOUT, sprintf("[%s] 启动（once=%s max_jobs=%d max_seconds=%d）\n", $name, $once ? 'yes' : 'no', $maxJobs, $maxSeconds));

while (true) {
    if ($handled >= $maxJobs || (time() - $startedAt) >= $maxSeconds) {
        fwrite(STDOUT, sprintf("[%s] 达到退出条件，共处理 %d 条\n", $name, $handled));
        exit(0);
    }

    // 闸门：后台按下「暂停」后，worker 不再领新任务，但排队中的一条都不丢。
    // 检查点放在 claim 之前而不是任务内部 —— 暂停的语义是「先别再往下走了」，
    // 不是「把正在跑的这一篇砍掉」（砍掉的话，已经花掉的钱就白花了）。
    if ($settings->bool('analysis.paused', false)) {
        if ($once) {
            fwrite(STDOUT, sprintf("[%s] 闸门已暂停，本轮不领任务\n", $name));
            exit(0);
        }
        sleep(max(3, $idleSleep));
        continue;
    }

    $job = $jobs->claim($name);
    if ($job === null) {
        if ($once) {
            fwrite(STDOUT, sprintf("[%s] 队列为空\n", $name));
            exit(0);
        }
        sleep($idleSleep);
        continue;
    }

    $jobId = (int) $job['id'];
    $handled++;

    fwrite(STDOUT, sprintf("[%s] 领取任务 #%d %s（第 %d 次）\n", $name, $jobId, $job['type'], (int) $job['attempts']));
    $jobs->log($jobId, 'info', 'worker 开始执行', ['worker' => $name]);

    try {
        $payload = json_decode((string) $job['payload_json'], true);
        if (!is_array($payload)) {
            throw new RuntimeException('任务负载不是合法 JSON');
        }

        $type = (string) $job['type'];

        if ($type === 'source.ingest') {
            // 抓一篇官网正文 → 入馆（work + 原文版本 + 作者 + 期次），然后**立刻排拆解**。
            // 拆解不在这条任务里直接跑：抓取是秒级、拆解是分钟级，混在一条任务里的话，
            // 一次抓取抖动重试会把已经花掉钱的拆解一起判失败。
            $ingested = $ingest->ingest(
                (int) ($payload['cid'] ?? 0),
                (int) ($payload['issue_no'] ?? 0),
                (int) ($payload['created_by'] ?? 0)
            );

            $jobs->enqueue('analysis.run', [
                'work_id'      => $ingested['work_id'],
                'work_code'    => $ingested['code'],
                'force'        => false,
                'created_by'   => $payload['created_by'] ?? null,
                'auto_publish' => (bool) ($payload['auto_publish'] ?? false),
                'keep_hidden'  => (bool) ($payload['keep_hidden'] ?? false),
            ], 8, 0, 3, isset($job['batch_id']) && (int) $job['batch_id'] > 0 ? (int) $job['batch_id'] : null);

            $jobs->log($jobId, 'info', '已入馆，接着排拆解', [
                'work_id' => $ingested['work_id'],
                'cid'     => (int) ($payload['cid'] ?? 0),
                'chars'   => $ingested['chars'],
                'created' => $ingested['created'],
            ]);

            $result = [
                'work_id' => $ingested['work_id'],
                'status'  => $ingested['created'] ? 'ingested' : 'already',
                'chars'   => $ingested['chars'],
            ];
        } else {
            $result = match ($type) {
                'analysis.run' => $pipeline->runWork((int) ($payload['work_id'] ?? 0), [
                    'force'       => (bool) ($payload['force'] ?? false),
                    'model'       => $payload['model'] ?? null,
                    'created_by'  => $payload['created_by'] ?? null,
                    'on_progress' => static function (string $stage, string $label) use ($jobs, $jobId, $name): void {
                        // 每进入一个阶段更新一次心跳：单阶段可能跑两三分钟，不更新会被误判僵尸
                        $jobs->heartbeat($jobId);
                        $jobs->log($jobId, 'info', '阶段开始：' . $label, ['stage' => $stage, 'worker' => $name]);

                        // 停止只能发生在**阶段边界**：一个阶段内部的写入是原子的，
                        // 在阶段之间退出不会留下半份产物；而模型请求一旦发出就收不回来，
                        // 硬杀进程还会留下 running 僵尸等心跳超时回收。
                        if ($jobs->isCancelRequested($jobId)) {
                            throw AnalysisCanceled::byOperator();
                        }
                    },
                ]),
                default => throw new RuntimeException('未知任务类型：' . $type),
            };
        }

        $jobs->complete($jobId, $result);
        $jobs->log($jobId, 'info', '执行成功', [
            'analysis_id' => $result['analysis_id'] ?? null,
            'status'      => $result['status'] ?? null,
            'reused'      => $result['reused'] ?? false,
        ]);

        // 「拆完不公开」：把作品置为 hidden。走 VisibilityService 而不是一句 UPDATE，
        // 是因为它连带失效页面缓存、重写 sitemap —— 少一样就会出现「已经藏了但前台还看得见」。
        // 这一步排在自动上架**之前**，并且 keep_hidden 一旦为真就不上架：
        // 两者同时勾是自相矛盾的，按「不公开」优先，宁可不展示也不误公开。
        if ($type === 'analysis.run' && !empty($payload['keep_hidden'])
            && in_array((string) ($result['status'] ?? ''), ['succeeded', 'published'], true)
            && (int) ($payload['work_id'] ?? 0) > 0) {
            try {
                $visibility->hide(
                    VisibilityService::WORK,
                    [(int) $payload['work_id']],
                    null,
                    '一键拆解：本批不对外公开'
                );
                $jobs->log($jobId, 'info', '已置为不公开（只在后台保留拆解结果）');
            } catch (Throwable $e) {
                $jobs->log($jobId, 'warn', '置为不公开失败（拆解结果仍在，请在后台手工隐藏）：' . $e->getMessage());
            }
        }

        // 一键拆解里勾了「拆完自动上架」的，在这里补上架这一步。
        // 上架失败**不判任务失败**：分析已经跑完、钱已经花了，把它退回队列重跑一遍
        // 等于再付一次；上架是几次 UPDATE，失败多半是数据状态不对，记一条 warn 让人来看。
        if ($type === 'analysis.run' && !empty($payload['auto_publish']) && empty($payload['keep_hidden'])
            && in_array((string) ($result['status'] ?? ''), ['succeeded', 'published'], true)
            && (int) ($result['analysis_id'] ?? 0) > 0) {
            try {
                $actorId = (int) ($payload['created_by'] ?? 0);

                // 上架只有一条路：状态 / 快照 / 审计 / 检索索引与缓存 / sitemap 五步
                // 全在 PublishService 里。以前这段自己拼，漏了检索索引 —— 2026-11-02
                // 发现 worker 自动上架的 604 篇全都没进 hq_search_keyword，站内检索
                // 事实上只覆盖 18 篇，而界面只会说「没有找到」，看不出是自己坏了。
                $container->get(PublishService::class)->publish(
                    (int) $result['analysis_id'],
                    (int) ($payload['work_id'] ?? 0),
                    $actorId ?: null,
                    // mark_edited 显式 false：发布不是编辑。以前吃默认 true，
                    // 自动上架的每一篇都被记成 source=mixed，后台显示
                    // 「模型生成 + 人工调整」，而实际上一个字都没人动过。
                    ['note' => '一键拆解自动上架', 'mark_edited' => false]
                );

                $jobs->log($jobId, 'info', '已自动上架', ['analysis_id' => (int) $result['analysis_id']]);
            } catch (Throwable $e) {
                $jobs->log($jobId, 'warn', '自动上架失败（拆解结果仍在后台，可手动上架）：' . $e->getMessage());
            }
        }

        fwrite(STDOUT, sprintf("[%s] #%d 完成（%s）\n", $name, $jobId, (string) ($result['status'] ?? 'ok')));
    } catch (AnalysisCanceled $e) {
        // 运营主动停的：置 canceled，不重试、不告警。放在 AnalysisException 之前，
        // 两者都是运行时异常，但取消必须是「正常结局」而不是「硬失败」。
        $jobs->markCanceled($jobId, $e->getMessage());
        $jobs->log($jobId, 'warn', '已按要求停止', ['worker' => $name]);
        fwrite(STDOUT, sprintf("[%s] #%d 已停止\n", $name, $jobId));
    } catch (AnalysisException $e) {
        // 还没走到模型那一步就确定不行（正文太短 / 无版本 / 作品不存在）：
        // 换个时间重跑结果一样，直接判失败，不烧 max_attempts
        $outcome = $jobs->failHard($jobId, $e->getMessage());
        $jobs->log($jobId, 'error', sprintf('%s（%s）→ %s', $e->getMessage(), $e->errorCode, $outcome), [
            'code' => $e->errorCode,
        ]);
        fwrite(STDERR, sprintf("[%s] #%d 失败：%s → %s\n", $name, $jobId, $e->getMessage(), $outcome));
    } catch (AiException $e) {
        // 预算超限、凭据失效这类问题重试也没用，直接判失败，避免把队列烧空
        $retryable = $e->retryable || $e->errorCode === 'AI_GATE_FAILED' || $e->errorCode === 'AI_EMPTY_CONTENT';
        $outcome   = $retryable ? $jobs->fail($jobId, $e->getMessage(), 60) : $jobs->failHard($jobId, $e->getMessage());

        $jobs->log($jobId, 'error', sprintf('%s（%s）→ %s', $e->getMessage(), $e->errorCode, $outcome), [
            'code' => $e->errorCode, 'http' => $e->httpCode,
        ]);

        fwrite(STDERR, sprintf("[%s] #%d 失败：%s → %s\n", $name, $jobId, $e->getMessage(), $outcome));
    } catch (Throwable $e) {
        $outcome = $jobs->fail($jobId, $e->getMessage(), 120);
        $jobs->log($jobId, 'error', '未预期异常：' . $e->getMessage(), ['type' => get_class($e)]);

        fwrite(STDERR, sprintf("[%s] #%d 异常：%s → %s\n", $name, $jobId, $e->getMessage(), $outcome));
    }

    if ($once) {
        exit(0);
    }
}
