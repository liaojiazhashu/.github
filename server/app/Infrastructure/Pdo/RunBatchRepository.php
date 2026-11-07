<?php

declare(strict_types=1);

namespace App\Infrastructure\Pdo;

use PDO;

/**
 * 一键拆解批次（hq_run_batch）。
 *
 * 为什么需要一个「批次」而不是直接看队列：
 *   · 队列里的任务是**逐篇**的，运营问的却是「我点的这一批」——同一篇作品可能先后
 *     属于不同批次（跑歪了重跑），只看 work 分不清是哪一次；
 *   · 范围必须留快照。三个月后回看「那批 320 篇是怎么选的」，光有任务行答不出来；
 *   · 批次是「一键」这个动作的收据：谁点的、计划多少、其中多少要先去官网抓正文。
 *
 * 批次**不保存进度计数**。进度是 hq_job 的聚合结果，存一份计数就要在每次状态变化时
 * 跟着维护，漏一次就永久对不上；聚合查询有 idx_batch_status，几千条也是毫秒级。
 */
final class RunBatchRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @param array<string,mixed> $scope
     * @param array<string,mixed> $options
     */
    public function create(
        string $code,
        array $scope,
        array $options,
        int $planned,
        int $ingest,
        ?int $actorId,
        ?string $note = null,
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO hq_run_batch (code, scope_json, options_json, planned, ingest, status, created_by, note, created_at, updated_at)
             VALUES (:code, :scope, :options, :planned, :ingest, \'running\', :actor, :note, NOW(), NOW())'
        );
        $stmt->execute([
            ':code'    => $code,
            ':scope'   => json_encode($scope, JSON_UNESCAPED_UNICODE),
            ':options' => json_encode($options, JSON_UNESCAPED_UNICODE),
            ':planned' => max(0, $planned),
            ':ingest'  => max(0, $ingest),
            ':actor'   => $actorId !== null && $actorId > 0 ? $actorId : null,
            ':note'    => $note === null ? null : mb_substr($note, 0, 200),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /** @return array<string,mixed>|null */
    public function findByCode(string $code): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM hq_run_batch WHERE code = :code LIMIT 1');
        $stmt->execute([':code' => $code]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    /**
     * 最近几批，带实时进度。
     *
     * 进度分两类：**抓取**（还没入馆的那部分）与**拆解**。它们不是同一件事——
     * 抓取失败的作品根本没进馆，不会出现在拆解计数里，把两者混成一个百分比
     * 会让「320/1177」看起来像跑了一半，实际可能一篇都还没开始拆。
     *
     * @return list<array<string,mixed>>
     */
    public function recent(int $limit = 8): array
    {
        $limit = max(1, min(50, $limit));
        $rows  = $this->pdo->query(
            'SELECT * FROM hq_run_batch ORDER BY id DESC LIMIT ' . $limit
        )->fetchAll();

        if ($rows === []) {
            return [];
        }

        $ids = array_map(static fn (array $r): int => (int) $r['id'], $rows);
        $stats = $this->countsFor($ids);

        $rows = array_map(static function (array $row) use ($stats): array {
            $id = (int) $row['id'];
            $row['ingest_stats'] = $stats['source.ingest'][$id] ?? [];
            $row['run_stats']    = $stats['analysis.run'][$id] ?? [];
            $row['scope']        = json_decode((string) $row['scope_json'], true) ?: [];
            $row['options']      = json_decode((string) $row['options_json'], true) ?: [];
            $row['done']         = (($row['ingest_stats']['queued'] ?? 0) + ($row['ingest_stats']['running'] ?? 0)
                                  + ($row['run_stats']['queued'] ?? 0) + ($row['run_stats']['running'] ?? 0)) === 0;

            return $row;
        }, $rows);

        // 跑完的批次顺手收尾。批次的 status 只有三态（running / done / canceled），
        // 而「跑完」是聚合出来的事实 —— 放在这里写回，是为了让「这一批还开着吗」
        // 在数据库里也答得出来，而不是只有打开页面才知道。
        $finished = array_values(array_map(
            static fn (array $r): int => (int) $r['id'],
            array_filter($rows, static fn (array $r): bool => $r['done'] && (string) $r['status'] === 'running')
        ));
        if ($finished !== []) {
            $in = implode(',', array_fill(0, count($finished), '?'));
            $this->pdo->prepare("UPDATE hq_run_batch SET status = 'done', updated_at = NOW() WHERE id IN ({$in})")
                ->execute($finished);
        }

        return $rows;
    }

    /**
     * @param list<int> $batchIds
     * @return array<string, array<int, array{queued:int,running:int,succeeded:int,failed:int,canceled:int}>>
     */
    private function countsFor(array $batchIds): array
    {
        if ($batchIds === []) {
            return [];
        }

        $in   = implode(',', array_fill(0, count($batchIds), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT batch_id, type, status, COUNT(*) AS n
               FROM hq_job
              WHERE batch_id IN ({$in})
              GROUP BY batch_id, type, status"
        );
        $stmt->execute($batchIds);

        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[(string) $row['type']][(int) $row['batch_id']][(string) $row['status']] = (int) $row['n'];
        }

        return $out;
    }

    /**
     * 取消这一批里还排着队的任务。
     *
     * 只动 queued，不动 running：一条已经发给模型的请求收不回来，
     * 硬置 canceled 只会让那一篇的产物半途而废。正在跑的走「请求停止」那条路。
     */
    public function cancelQueued(int $batchId): int
    {
        $stmt = $this->pdo->prepare(
            "UPDATE hq_job
                SET status = 'canceled', updated_at = NOW(), last_error = '批次取消'
              WHERE batch_id = :id AND status = 'queued'"
        );
        $stmt->execute([':id' => $batchId]);

        $this->pdo->prepare(
            'UPDATE hq_run_batch SET status = \'canceled\', updated_at = NOW() WHERE id = :id AND status = \'running\''
        )->execute([':id' => $batchId]);

        return $stmt->rowCount();
    }

    /**
     * 批次里已经拆完、但还没上架的作品（给「一键上架」用）。
     *
     * 不能拿 `w.current_analysis_id` 找：那正是「上架之后才有」的东西。
     * 拆完待上架的作品 status=analyzed 而 current_analysis_id 仍为空（首次上架），
     * 或者还指着上一版（重跑之后）—— 两种情况都要取**最近一次成功的分析**。
     */
    public function pendingPublish(int $batchId, int $limit = 500): array
    {
        $limit = max(1, min(1000, $limit));
        $stmt  = $this->pdo->prepare(
            "SELECT w.id AS work_id, w.code AS work_code, w.title,
                    a.id AS analysis_id
               FROM hq_job j
               JOIN hq_work w ON w.id = j.work_id
                AND w.deleted_at IS NULL
                AND w.status = 'analyzed'
               JOIN hq_analysis a ON a.id = (
                        SELECT MAX(a2.id) FROM hq_analysis a2
                         WHERE a2.work_id = w.id AND a2.status = 'succeeded'
                    )
              WHERE j.batch_id = :id AND j.type = 'analysis.run'
              GROUP BY w.id, w.code, w.title, a.id
              ORDER BY w.id
              LIMIT " . $limit
        );
        $stmt->execute([':id' => $batchId]);

        return $stmt->fetchAll();
    }
}
