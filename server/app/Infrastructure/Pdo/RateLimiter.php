<?php

declare(strict_types=1);

namespace App\Infrastructure\Pdo;

use PDO;

/**
 * 限流（hq_rate_limit）。固定窗口计数，bucket = 动作:维度:键。
 *
 * 为什么用一条 UPDATE 而不是「先查后写」：
 * 把「窗口是否过期」和「计数是否 +1」放进同一条语句，靠单行更新天然串行，避免并发下的 TOCTOU。
 * MySQL 单表 UPDATE 的赋值从左到右求值，所以 hits 的 IF 读到的是**未被改写**的 expires_at —— 这正是要的。
 */
final class RateLimiter
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function tooMany(string $bucket, int $limit, int $windowSeconds): bool
    {
        $key    = mb_substr($bucket, 0, 128);
        $window = max(1, $windowSeconds);
        $limit  = max(1, $limit);

        // 注意：真预处理（EMULATE_PREPARES=false）下同一命名参数**不能**在一句里出现多次，
        // 否则 PDO 抛 HY093。所以这里的时间列直接用 NOW()，不绑参数。
        $this->pdo->prepare(
            'INSERT INTO hq_rate_limit (bucket_key, hits, window_start, expires_at)
             VALUES (:k, 0, NOW(), NOW())
             ON DUPLICATE KEY UPDATE bucket_key = bucket_key'
        )->execute([':k' => $key]);

        // $window 已强制为 int，拼接无注入面（INTERVAL ? SECOND 在部分版本不接受占位符）
        $sql = "UPDATE hq_rate_limit
                   SET hits = IF(expires_at <= NOW(), 1, hits + 1),
                       window_start = IF(expires_at <= NOW(), NOW(), window_start),
                       expires_at = IF(expires_at <= NOW(), DATE_ADD(NOW(), INTERVAL {$window} SECOND), expires_at)
                 WHERE bucket_key = :k";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':k' => $key]);

        $stmt = $this->pdo->prepare('SELECT hits FROM hq_rate_limit WHERE bucket_key = :k');
        $stmt->execute([':k' => $key]);
        $hits = (int) ($stmt->fetchColumn() ?: 0);

        return $hits > $limit;
    }

    /** 距离窗口结束还有多少秒（用于 Retry-After 头）。 */
    public function retryAfter(string $bucket): int
    {
        $stmt = $this->pdo->prepare('SELECT GREATEST(0, TIMESTAMPDIFF(SECOND, NOW(), expires_at)) FROM hq_rate_limit WHERE bucket_key = :k');
        $stmt->execute([':k' => mb_substr($bucket, 0, 128)]);

        return max(1, (int) ($stmt->fetchColumn() ?: 1));
    }

    /** 登录成功后清掉该键的失败计数，避免「昨天失败 4 次」影响今天。 */
    public function clear(string $bucket): void
    {
        $this->pdo->prepare('DELETE FROM hq_rate_limit WHERE bucket_key = :k')
            ->execute([':k' => mb_substr($bucket, 0, 128)]);
    }

    /** 由 console 定时清理。 */
    public function purgeExpired(): int
    {
        $stmt = $this->pdo->prepare('DELETE FROM hq_rate_limit WHERE expires_at < DATE_SUB(NOW(), INTERVAL 1 DAY)');

        return $stmt->execute() ? $stmt->rowCount() : 0;
    }
}
