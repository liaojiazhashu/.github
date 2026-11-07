<?php

declare(strict_types=1);

/**
 * 开机恢复：把强制关机 / 重启砍在 running 的任务退回队列。
 *
 * 为什么需要它：worker 的僵尸回收判据是 `locked_at` 超过 600 秒，而刚开机时
 * 被中断的任务才几十秒，回收不到 —— 会卡住十几分钟且没人管。开机这一刻不存在
 * 活着的 worker，所以 running 一定全是死的，可以无条件退回。
 *
 * 安全闸：如果最近 60 秒内还有任务被领取，说明已经有活 worker 在跑（说明本脚本
 * 被误在运行期调用），此时一条都不动，直接退出。
 *
 * 调用：由计划任务 HQLib-Boot-Recover 在开机时执行一次，早于三个 worker。
 *      人工确认真没有 worker 在跑时，可加 --force 跳过安全闸。
 */

require dirname(__DIR__) . '/config/bootstrap.php';

use App\Support\Config;
use App\Support\Db;

$log = static function (string $line): void {
    fwrite(STDOUT, '[boot-recover] ' . $line . PHP_EOL);
};

try {
    $config = new Config(require dirname(__DIR__) . '/config/settings.php');
    $pdo    = Db::connect($config);
    $pdo->query('SELECT 1');
} catch (Throwable $e) {
    // MySQL 还没起来：退出码 2 让外层 bat 稍后重试；worker 侧另有 600 秒级回收兜底。
    $log('数据库暂不可用，稍后重试：' . $e->getMessage());
    exit(2);
}

// 安全闸：有活 worker 就别动（--force 可跳过，仅供人工确认后使用）
$force  = in_array('--force', array_slice($argv, 1), true);
$active = (int) $pdo->query(
    "SELECT COUNT(*) FROM hq_job
      WHERE status = 'running' AND locked_at > DATE_SUB(NOW(), INTERVAL 60 SECOND)"
)->fetchColumn();

if ($active > 0 && !$force) {
    $log('检测到 ' . $active . ' 条 60 秒内领取的任务，判定存在活 worker，放弃恢复。');
    exit(0);
}

if ($active > 0) {
    $log('--force 已指定，忽略 ' . $active . ' 条近期任务的保护。');
}

$pdo->beginTransaction();

try {
    // 1) 停在 running 的分析：进程已随关机消失，标失败（产物仍在 stage 表里）
    $analyses = $pdo->prepare(
        "UPDATE hq_analysis
            SET status = 'failed',
                error_code = 'ANALYSIS_INTERRUPTED',
                error_msg = '主机重启，进程中断（boot-recover 判定）',
                finished_at = NOW(),
                updated_at = NOW()
          WHERE status = 'running'"
    );
    $analyses->execute();
    $analysisCount = $analyses->rowCount();

    // 2) 作品状态：有成功阶段就留 analyzed（产物还能看），一个阶段都没跑完退回 imported
    $works = $pdo->prepare(
        "UPDATE hq_work w
            SET status = CASE
                    WHEN EXISTS (
                        SELECT 1
                          FROM hq_analysis a
                          JOIN hq_analysis_stage s ON s.analysis_id = a.id
                         WHERE a.work_id = w.id AND s.status = 'succeeded'
                    ) THEN 'analyzed'
                    ELSE 'imported'
                END,
                updated_at = NOW()
          WHERE w.status = 'analyzing'"
    );
    $works->execute();
    $workCount = $works->rowCount();

    // 3) 任务退回队列（attempts 归零，让它立刻可被重新领取）
    $jobs = $pdo->prepare(
        "UPDATE hq_job
            SET status = 'queued', locked_by = NULL, locked_at = NULL,
                attempts = 0, last_error = '主机重启，已退回队列（boot-recover）',
                updated_at = NOW()
          WHERE status = 'running'"
    );
    $jobs->execute();
    $jobCount = $jobs->rowCount();

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    $log('恢复失败已回滚：' . $e->getMessage());
    exit(1);
}

$log(sprintf('完成：分析 %d 条标失败，作品 %d 条回退，任务 %d 条退回队列。', $analysisCount, $workCount, $jobCount));
exit(0);
