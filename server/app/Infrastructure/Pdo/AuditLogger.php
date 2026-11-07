<?php

declare(strict_types=1);

namespace App\Infrastructure\Pdo;

use App\Support\RequestContext;
use PDO;
use Throwable;

/**
 * 审计日志（hq_audit_log）。只 INSERT，绝不 UPDATE / DELETE —— 数据库账号层面也这么授权。
 *
 * 审计写失败不能影响主流程：异常被吞并转进 error 日志（否则一次磁盘满会让业务全挂），
 * 但error 日志里会留下痕迹，运维据此补齐。
 */
final class AuditLogger
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly RequestContext $context,
    ) {
    }

    /**
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     */
    public function log(
        ?int $actorId,
        string $action,
        string $targetType,
        ?string $targetId = null,
        ?array $before = null,
        ?array $after = null
    ): void {
        $sql = 'INSERT INTO hq_audit_log
                    (actor_id, action, target_type, target_id, before_json, after_json, ip_hash, ua_hash, trace_id, created_at)
                VALUES (:actor, :action, :ttype, :tid, :before, :after, :ip, :ua, :trace, NOW())';

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                ':actor'  => $actorId,
                ':action' => $action,
                ':ttype'  => $targetType,
                ':tid'    => $targetId,
                ':before' => $before === null ? null : $this->encode($before),
                ':after'  => $after === null ? null : $this->encode($after),
                ':ip'     => $this->context->ipHash() ?: null,
                ':ua'     => $this->context->uaHash() ?: null,
                ':trace'  => $this->context->traceId(),
            ]);
        } catch (Throwable $e) {
            error_log('audit_write_failed: ' . $e->getMessage());
        }
    }

    /** @param array<string, mixed> $data */
    private function encode(array $data): string
    {
        // 审计里不放原文，只放字段名与变化后的标量值
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $json === false ? '{}' : mb_substr($json, 0, 60000);
    }
}
