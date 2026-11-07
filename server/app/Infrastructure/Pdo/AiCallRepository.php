<?php

declare(strict_types=1);

namespace App\Infrastructure\Pdo;

use PDO;

/**
 * 模型调用计量（hq_ai_call）。每次调用一行，含 token、缓存命中、耗时、成本。
 *
 * 这张表是对账的唯一依据：左侧是 DeepSeek 账单，右侧是这张表的 cost_micro 汇总，
 * 两者对不上就说明单价表过期了（或者有人绕过客户端直接调了接口）。
 */
final class AiCallRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @param array<string,mixed> $row */
    public function record(?int $analysisId, array $row): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO hq_ai_call
                (analysis_id, stage_code, model, prompt_version, request_sha, request_id, http_code,
                 status, prompt_tokens, completion_tokens, cached_tokens, latency_ms, cost_micro, error_code, created_at)
             VALUES
                (:analysis, :stage, :model, :pver, :sha, :rid, :http,
                 :status, :pin, :pout, :pcached, :latency, :cost, :err, NOW())'
        );

        $stmt->execute([
            ':analysis' => $analysisId,
            ':stage'    => $row['stage_code'],
            ':model'    => $row['model'],
            ':pver'     => $row['prompt_version'],
            ':sha'      => $row['request_sha'],
            ':rid'      => $row['request_id'] ?? null,
            ':http'     => $row['http_code'] ?? null,
            ':status'   => $row['status'] ?? 'ok',
            ':pin'      => (int) ($row['prompt_tokens'] ?? 0),
            ':pout'     => (int) ($row['completion_tokens'] ?? 0),
            ':pcached'  => (int) ($row['cached_tokens'] ?? 0),
            ':latency'  => (int) ($row['latency_ms'] ?? 0),
            ':cost'     => (int) ($row['cost_micro'] ?? 0),
            ':err'      => $row['error_code'] ?? null,
        ]);
    }

    /** 失败也要记：Request 发出去了就产生了费用，不记账会让预算熔断失效 */
    public function recordFailure(?int $analysisId, string $stageCode, string $model, string $promptVersion, string $errorCode, int $latencyMs, ?int $httpCode = null): void
    {
        $this->record($analysisId, [
            'stage_code'    => $stageCode,
            'model'         => $model,
            'prompt_version' => $promptVersion,
            'request_sha'   => str_repeat('0', 64),
            'request_id'    => null,
            'http_code'     => $httpCode,
            'status'        => 'error',
            'latency_ms'    => $latencyMs,
            'error_code'    => mb_substr($errorCode, 0, 48),
        ]);
    }

    /** 今日（自然日）累计成本，微元 */
    public function costTodayMicro(): int
    {
        $stmt = $this->pdo->query(
            "SELECT COALESCE(SUM(cost_micro), 0) FROM hq_ai_call WHERE created_at >= CURDATE()"
        );

        return (int) $stmt->fetchColumn();
    }

    public function costOfAnalysis(int $analysisId): int
    {
        $stmt = $this->pdo->prepare('SELECT COALESCE(SUM(cost_micro), 0) FROM hq_ai_call WHERE analysis_id = :id');
        $stmt->execute([':id' => $analysisId]);

        return (int) $stmt->fetchColumn();
    }

    /** @return array{calls:int, tokens_in:int, tokens_out:int, cached:int, cost_micro:int} */
    public function summary(?string $since = null): array
    {
        $sql = 'SELECT COUNT(*) AS calls,
                       COALESCE(SUM(prompt_tokens), 0) AS tokens_in,
                       COALESCE(SUM(completion_tokens), 0) AS tokens_out,
                       COALESCE(SUM(cached_tokens), 0) AS cached,
                       COALESCE(SUM(cost_micro), 0) AS cost_micro
                  FROM hq_ai_call';
        $params = [];
        if ($since !== null) {
            $sql .= ' WHERE created_at >= :since';
            $params[':since'] = $since;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch() ?: [];

        return [
            'calls'      => (int) ($row['calls'] ?? 0),
            'tokens_in'  => (int) ($row['tokens_in'] ?? 0),
            'tokens_out' => (int) ($row['tokens_out'] ?? 0),
            'cached'     => (int) ($row['cached'] ?? 0),
            'cost_micro' => (int) ($row['cost_micro'] ?? 0),
        ];
    }

    /** @return list<array<string,mixed>> 某次分析的全部调用，后台分析详情页逐条列出 */
    public function forAnalysis(int $analysisId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT stage_code, model, status, http_code, prompt_tokens, completion_tokens, cached_tokens,
                    latency_ms, cost_micro, error_code, created_at
               FROM hq_ai_call WHERE analysis_id = :aid ORDER BY id'
        );
        $stmt->execute([':aid' => $analysisId]);

        return $stmt->fetchAll();
    }

    /** @return list<array<string,mixed>> 最近调用，后台排查用 */
    public function recent(int $limit = 20): array
    {
        $limit = min(100, max(1, $limit));

        return $this->pdo->query(
            'SELECT id, analysis_id, stage_code, model, status, http_code,
                    prompt_tokens, completion_tokens, cached_tokens, latency_ms, cost_micro, error_code, created_at
               FROM hq_ai_call ORDER BY id DESC LIMIT ' . $limit
        )->fetchAll();
    }
}
