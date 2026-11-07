<?php

declare(strict_types=1);

namespace App\Infrastructure\Pdo;

use PDO;

/**
 * 会话索引（hq_session）。主键是 PHP session id 的 HMAC，不是原文。
 */
final class SessionRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function create(string $id, int $userId, ?string $ipHash, ?string $uaHash, int $lifetime): void
    {
        $sql = 'INSERT INTO hq_session (id, user_id, token_hash, ip_hash, ua_hash, expires_at, last_seen_at, created_at)
                VALUES (:id, :user, NULL, :ip, :ua, DATE_ADD(NOW(), INTERVAL :life SECOND), NOW(), NOW())
                ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), ip_hash = VALUES(ip_hash),
                                        ua_hash = VALUES(ua_hash),
                                        expires_at = DATE_ADD(NOW(), INTERVAL :life2 SECOND),
                                        last_seen_at = NOW()';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':id'   => $id,
            ':user' => $userId,
            ':ip'   => $ipHash,
            ':ua'   => $uaHash,
            ':life' => max(300, $lifetime),
            ':life2' => max(300, $lifetime),
        ]);
    }

    /** 会话是否仍然有效（未过期且未被踢下线）。 */
    public function isAlive(string $id, int $userId): bool
    {
        $sql = 'SELECT 1 FROM hq_session
                 WHERE id = :id AND user_id = :user AND expires_at > NOW()
                 LIMIT 1';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':id' => $id, ':user' => $userId]);

        return $stmt->fetchColumn() !== false;
    }

    public function touch(string $id, int $lifetime): void
    {
        $sql = 'UPDATE hq_session
                   SET last_seen_at = NOW(),
                       expires_at = DATE_ADD(NOW(), INTERVAL :life SECOND)
                 WHERE id = :id';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':life' => max(300, $lifetime), ':id' => $id]);
    }

    public function delete(string $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM hq_session WHERE id = :id');
        $stmt->execute([':id' => $id]);
    }

    public function deleteByUser(int $userId): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM hq_session WHERE user_id = :user');
        $stmt->execute([':user' => $userId]);
    }

    /** @return list<array<string, mixed>> */
    public function listByUser(int $userId): array
    {
        $sql = 'SELECT id, ip_hash, ua_hash, expires_at, last_seen_at, created_at
                  FROM hq_session
                 WHERE user_id = :user AND expires_at > NOW()
                 ORDER BY last_seen_at DESC';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':user' => $userId]);

        return $stmt->fetchAll();
    }

    /** 清理过期会话（由 console 定时调用，不放在请求路径里）。 */
    public function purgeExpired(): int
    {
        $stmt = $this->pdo->prepare('DELETE FROM hq_session WHERE expires_at <= NOW()');

        return $stmt->execute() ? $stmt->rowCount() : 0;
    }
}
