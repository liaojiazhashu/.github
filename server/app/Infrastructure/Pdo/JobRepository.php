<?php

declare(strict_types=1);

namespace App\Infrastructure\Pdo;

use App\Support\Alert;
use PDO;

/**
 * 异步任务队列（hq_job / hq_job_log）。
 *
 * 不用 Redis、不用 SKIP LOCKED（5.6 没有）：抢占是一条 `UPDATE ... ORDER BY ... LIMIT 1`，
 * 靠 InnoDB 行锁保证多个 worker 不会拿到同一条（§6.10）。
 * 「取回刚抢到的那条」不能靠 `locked_by`——同名 worker 并发时会串，
 * 所以每次抢占生成一个一次性令牌，用它定位。
 */
final class JobRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @param array<string,mixed> $payload */
    public function enqueue(
        string $type,
        array $payload,
        int $priority = 5,
        int $delaySeconds = 0,
        int $maxAttempts = 3,
        ?int $batchId = null,
    ): int {
        // INTERVAL 里的秒数直接内联：它已经过 (int) + 上下界夹取，且原生预处理下
        // `INTERVAL ? SECOND` 的类型推断不稳定，内联比传参安全
        $delay = max(0, min(86400, $delaySeconds));

        // work_id 单独成列（006 迁移）：1300 篇批跑时「这篇在队列里吗」是最高频的查询，
        // 靠 LIKE 扫 payload_json 既走不了索引，也依赖载荷的键序。
        // batch_id（010）同理：「我点的这一批跑到哪了」要按批次分组统计。
        $workId = isset($payload['work_id']) ? (int) $payload['work_id'] : 0;

        $stmt = $this->pdo->prepare(
            'INSERT INTO hq_job (type, work_id, batch_id, payload_json, status, priority, attempts, max_attempts, available_at, created_at, updated_at)
             VALUES (:type, :work_id, :batch_id, :payload, \'queued\', :priority, 0, :max, DATE_ADD(NOW(), INTERVAL ' . $delay . ' SECOND), NOW(), NOW())'
        );
        $stmt->execute([
            ':type'     => $type,
            ':work_id'  => $workId > 0 ? $workId : null,
            ':batch_id' => $batchId !== null && $batchId > 0 ? $batchId : null,
            ':payload'  => json_encode($payload, JSON_UNESCAPED_UNICODE),
            ':priority' => max(1, min(100, $priority)),
            ':max'      => max(1, min(10, $maxAttempts)),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * 抢占一条任务。原子性由单条 UPDATE 保证。
     *
     * @return array<string,mixed>|null
     */
    public function claim(string $worker): ?array
    {
        $token = substr($worker, 0, 40) . ':' . bin2hex(random_bytes(6));

        $stmt = $this->pdo->prepare(
            'UPDATE hq_job
                SET status = \'running\', locked_by = :token, locked_at = NOW(), attempts = attempts + 1, updated_at = NOW()
              WHERE status = \'queued\' AND available_at <= NOW()
              ORDER BY priority DESC, id ASC
              LIMIT 1'
        );
        $stmt->execute([':token' => $token]);

        if ($stmt->rowCount() === 0) {
            return null;
        }

        $stmt = $this->pdo->prepare('SELECT * FROM hq_job WHERE locked_by = :token AND status = \'running\' LIMIT 1');
        $stmt->execute([':token' => $token]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    public function heartbeat(int $jobId): void
    {
        $this->pdo->prepare('UPDATE hq_job SET locked_at = NOW() WHERE id = :id AND status = \'running\'')
            ->execute([':id' => $jobId]);
    }

    /** @param array<string,mixed>|null $result */
    public function complete(int $jobId, ?array $result = null): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE hq_job
                SET status = \'succeeded\', locked_by = NULL, locked_at = NULL,
                    result_json = :result, updated_at = NOW()
              WHERE id = :id'
        );
        $stmt->execute([
            ':result' => $result === null ? null : json_encode($result, JSON_UNESCAPED_UNICODE),
            ':id'     => $jobId,
        ]);
    }

    /**
     * 失败：还没到 max_attempts 就退回队列并延后重试，否则置 failed。
     *
     * 退避放在 available_at 上而不是 sleep：worker 不该为一个任务堵住整条队列。
     */
    public function fail(int $jobId, string $error, int $retryDelaySeconds = 60): string
    {
        $stmt = $this->pdo->prepare('SELECT attempts, max_attempts FROM hq_job WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $jobId]);
        $row = $stmt->fetch() ?: ['attempts' => 0, 'max_attempts' => 3];

        $attempts = (int) $row['attempts'];
        $max      = (int) $row['max_attempts'];
        $message  = mb_substr($error, 0, 500);

        if ($attempts < $max) {
            $delay = max(1, min(3600, $retryDelaySeconds));
            $stmt  = $this->pdo->prepare(
                'UPDATE hq_job
                    SET status = \'queued\', locked_by = NULL, locked_at = NULL, last_error = :err,
                        available_at = DATE_ADD(NOW(), INTERVAL ' . $delay . ' SECOND), updated_at = NOW()
                  WHERE id = :id'
            );
            $stmt->execute([':err' => $message, ':id' => $jobId]);

            return 'retry';
        }

        $stmt = $this->pdo->prepare(
            'UPDATE hq_job
                SET status = \'failed\', locked_by = NULL, locked_at = NULL, last_error = :err, updated_at = NOW()
              WHERE id = :id'
        );
        $stmt->execute([':err' => $message, ':id' => $jobId]);

        // 重试用尽才告警：还在退避中的失败不是事故，是正常节奏。冷却期在 Alert 里。
        Alert::send('error', '任务重试用尽，已置 failed', [
            'job_id'   => $jobId,
            'attempts' => $attempts . '/' . $max,
            'error'    => mb_substr($message, 0, 200),
        ]);

        return 'failed';
    }

    /** 一次性判失败：预算超限、凭据失效这类问题重试也没用，不占用 max_attempts */
    public function failHard(int $jobId, string $error): string
    {
        $stmt = $this->pdo->prepare(
            'UPDATE hq_job
                SET status = \'failed\', locked_by = NULL, locked_at = NULL, last_error = :err,
                    attempts = max_attempts, updated_at = NOW()
              WHERE id = :id'
        );
        $stmt->execute([':err' => mb_substr($error, 0, 500), ':id' => $jobId]);

        // 预算超限、凭据失效这类问题重试也没用 —— 这恰恰是最需要人立刻知道的一类
        Alert::send('critical', '任务硬失败（不重试）', [
            'job_id' => $jobId,
            'error'  => mb_substr($error, 0, 200),
        ]);

        return 'failed';
    }

    /** 僵尸回收：worker 被 kill 掉时任务会一直停在 running，靠心跳超时判定 */
    public function reclaimZombies(int $staleSeconds = 600): int
    {
        $sec  = max(60, min(86400, $staleSeconds));
        $stmt = $this->pdo->prepare(
            'UPDATE hq_job
                SET status = \'queued\', locked_by = NULL, locked_at = NULL,
                    last_error = \'worker 心跳超时，已退回队列\', updated_at = NOW()
              WHERE status = \'running\' AND locked_at < DATE_SUB(NOW(), INTERVAL ' . $sec . ' SECOND)'
        );
        $stmt->execute();

        return $stmt->rowCount();
    }

    /**
     * 按状态计数。
     *
     * type 非空时只统计这一类任务：概览页的「拆解失败」必须只认 analysis.run，
     * 否则入馆失败会被算进拆解失败里，点进去却在失败列表里找不到（列表是按类型筛的）。
     * 为空时保持全表语义，给「队列整体是否在动」这类判断用。
     *
     * @return array{queued:int, running:int, failed:int, succeeded:int}
     */
    public function counts(?string $type = null): array
    {
        if ($type === null) {
            $rows = $this->pdo->query('SELECT status, COUNT(*) AS n FROM hq_job GROUP BY status')->fetchAll();
        } else {
            $stmt = $this->pdo->prepare('SELECT status, COUNT(*) AS n FROM hq_job WHERE type = :type GROUP BY status');
            $stmt->execute([':type' => $type]);
            $rows = $stmt->fetchAll();
        }
        $out  = ['queued' => 0, 'running' => 0, 'failed' => 0, 'succeeded' => 0];
        foreach ($rows as $row) {
            $out[(string) $row['status']] = (int) $row['n'];
        }

        return $out;
    }

    /** @return list<array<string,mixed>> */
    public function recent(int $limit = 20): array
    {
        $limit = min(100, max(1, $limit));

        return $this->pdo->query(
            'SELECT id, type, work_id, status, priority, attempts, max_attempts, available_at, locked_by,
                    cancel_requested, last_error, created_at, updated_at
               FROM hq_job ORDER BY id DESC LIMIT ' . $limit
        )->fetchAll();
    }

    // ------------------------------------------------------------ 停止与重试

    /**
     * 请求停止一条任务。
     *
     * 两种粒度，因为「停止」在两种状态下含义不同：
     *   · 还在排队（queued）→ 直接 canceled，它一步都没跑过，没有中间态要收尾；
     *   · 正在跑（running）→ 只能置 cancel_requested。模型请求已经发出去了，
     *     从外部硬取消不可能，硬杀 worker 还会留下 running 僵尸、要靠心跳超时回收。
     *     置标记后由 worker 在**阶段边界**读一次，自己退出。
     *
     * @return string queued→canceled / running→requested / 其它→unchanged
     */
    public function cancel(int $jobId): string
    {
        $stmt = $this->pdo->prepare(
            'UPDATE hq_job
                SET status = \'canceled\', locked_by = NULL, locked_at = NULL,
                    last_error = \'已由运营停止（排队中）\', updated_at = NOW()
              WHERE id = :id AND status = \'queued\''
        );
        $stmt->execute([':id' => $jobId]);
        if ($stmt->rowCount() > 0) {
            return 'canceled';
        }

        $stmt = $this->pdo->prepare(
            'UPDATE hq_job
                SET cancel_requested = 1, updated_at = NOW()
              WHERE id = :id AND status = \'running\' AND cancel_requested = 0'
        );
        $stmt->execute([':id' => $jobId]);

        return $stmt->rowCount() > 0 ? 'requested' : 'unchanged';
    }

    /** 把队列里所有排队中的任务一次性停掉（全局急停用）。 */
    public function cancelAllQueued(string $type = 'analysis.run'): int
    {
        $stmt = $this->pdo->prepare(
            'UPDATE hq_job
                SET status = \'canceled\', locked_by = NULL, locked_at = NULL,
                    last_error = \'已由运营停止（全局）\', updated_at = NOW()
              WHERE type = :type AND status = \'queued\''
        );
        $stmt->execute([':type' => $type]);

        return $stmt->rowCount();
    }

    /**
     * worker 在阶段边界问一句「我该停吗」。
     *
     * 每次都是一个单行主键查询，一个阶段才一次，可以忽略不计；
     * 换成「worker 启动时读一次」就变成了「暂停对已经在跑的任务无效」。
     */
    public function isCancelRequested(int $jobId): bool
    {
        $stmt = $this->pdo->prepare('SELECT cancel_requested FROM hq_job WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $jobId]);

        return (int) $stmt->fetchColumn() === 1;
    }

    /** 任务被主动停止后落账：不重试、不算失败。 */
    public function markCanceled(int $jobId, string $reason = '已按要求停止'): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE hq_job
                SET status = \'canceled\', locked_by = NULL, locked_at = NULL,
                    last_error = :err, updated_at = NOW()
              WHERE id = :id'
        );
        $stmt->execute([':err' => mb_substr($reason, 0, 500), ':id' => $jobId]);
    }

    /** 失败任务重新入队：重置尝试次数，立刻可跑。 */
    public function retry(int $jobId): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE hq_job
                SET status = \'queued\', attempts = 0, locked_by = NULL, locked_at = NULL,
                    cancel_requested = 0, available_at = NOW(), last_error = NULL, updated_at = NOW()
              WHERE id = :id AND status IN (\'failed\', \'canceled\')'
        );
        $stmt->execute([':id' => $jobId]);

        return $stmt->rowCount() > 0;
    }

    /**
     * 一批作品当前的队列状态（调度台列表用）。
     *
     * 一份作品可能有多条历史任务，这里只取「最该被看到的那一条」：
     * 运行中 > 排队中 > 失败 > 其它。
     *
     * @param list<int> $workIds
     * @return array<int, array{status:string, job_id:int}> work_id => 队列状态
     */
    public function statusByWork(array $workIds): array
    {
        $workIds = array_values(array_unique(array_filter(array_map('intval', $workIds), static fn (int $i): bool => $i > 0)));
        if ($workIds === []) {
            return [];
        }

        $out = [];
        foreach (array_chunk($workIds, 500) as $batch) {
            $in   = implode(',', array_fill(0, count($batch), '?'));
            $stmt = $this->pdo->prepare(
                "SELECT work_id, id, status FROM hq_job
                  WHERE type = 'analysis.run' AND work_id IN ({$in})
                  ORDER BY FIELD(status, 'running','queued','failed','canceled','succeeded'), id DESC"
            );
            $stmt->execute($batch);

            foreach ($stmt->fetchAll() as $row) {
                $workId = (int) $row['work_id'];
                if (!isset($out[$workId])) {
                    $out[$workId] = ['status' => (string) $row['status'], 'job_id' => (int) $row['id']];
                }
            }
        }

        return $out;
    }

    /** @param array<string,mixed>|null $context */
    public function log(int $jobId, string $level, string $message, ?array $context = null): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO hq_job_log (job_id, level, message, context_json, created_at)
             VALUES (:job, :level, :msg, :ctx, NOW())'
        );
        $stmt->execute([
            ':job'   => $jobId,
            ':level' => mb_substr($level, 0, 8),
            ':msg'   => mb_substr($message, 0, 500),
            ':ctx'   => $context === null ? null : json_encode($context, JSON_UNESCAPED_UNICODE),
        ]);
    }

    /** @return list<array<string,mixed>> */
    public function logs(int $jobId): array
    {
        $stmt = $this->pdo->prepare('SELECT level, message, context_json, created_at FROM hq_job_log WHERE job_id = :job ORDER BY id');
        $stmt->execute([':job' => $jobId]);

        return $stmt->fetchAll();
    }
}
