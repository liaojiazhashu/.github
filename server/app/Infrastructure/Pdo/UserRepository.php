<?php

declare(strict_types=1);

namespace App\Infrastructure\Pdo;

use PDO;

/**
 * 后台运营账号仓储（hq_user）。全部预处理语句，SQL 里不出现任何变量拼接。
 */
final class UserRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array<string, mixed>|null */
    public function findByUsername(string $username): ?array
    {
        $sql = 'SELECT u.*, r.code AS role_code
                  FROM hq_user u
                  JOIN hq_role r ON r.id = u.role_id
                 WHERE u.username = :username
                 LIMIT 1';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':username' => $username]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    /** @return array<string, mixed>|null */
    public function findById(int $id): ?array
    {
        $sql = 'SELECT u.*, r.code AS role_code
                  FROM hq_user u
                  JOIN hq_role r ON r.id = u.role_id
                 WHERE u.id = :id
                 LIMIT 1';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    /**
     * 连续失败 +1；达到阈值即落到 locked 并写 locked_until。
     *
     * ⚠️ MySQL 单表 UPDATE 的赋值**从左到右**求值：`failed_attempts` 先被 +1，
     * 后面 CASE 里读到的是**已加过的新值**，所以判断条件写 `failed_attempts >= :max`
     * 而不是 `failed_attempts + 1 >= :max` —— 后者会变成「第 4 次就锁」（实测踩过）。
     */
    public function recordFailure(int $userId, int $maxAttempts, int $lockMinutes): void
    {
        $sql = 'UPDATE hq_user
                   SET failed_attempts = failed_attempts + 1,
                       locked_until = CASE WHEN failed_attempts >= :max
                                           THEN DATE_ADD(NOW(), INTERVAL :mins MINUTE)
                                           ELSE locked_until END,
                       status = CASE WHEN failed_attempts >= :max2 AND status = \'active\'
                                     THEN \'locked\'
                                     ELSE status END,
                       updated_at = NOW()
                 WHERE id = :id';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':max'  => $maxAttempts,
            ':mins' => $lockMinutes,
            ':max2' => $maxAttempts,
            ':id'   => $userId,
        ]);
    }

    /** 登录成功：清失败计数、解锁定、记录时间与 IP 哈希。 */
    public function recordSuccess(int $userId, string $ipHash): void
    {
        $sql = 'UPDATE hq_user
                   SET failed_attempts = 0,
                       locked_until = NULL,
                       status = CASE WHEN status = \'locked\' THEN \'active\' ELSE status END,
                       last_login_at = NOW(),
                       last_login_ip_hash = :ip,
                       updated_at = NOW()
                 WHERE id = :id';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':ip' => $ipHash, ':id' => $userId]);
    }

    /**
     * 解除一次已经过期的锁定：失败计数归零、locked_until 清空、locked 还原成 active。
     *
     * 为什么需要单独一个方法：`recordSuccess()` 也做同样的事，但它只在**登录成功**时跑。
     * 锁窗过后还没登进来的账号（以及运维手工要解的账号）就卡在「计数 ≥ 阈值 + 标签 locked」
     * 的状态里，下一次错一个口令就立刻再锁 15 分钟。登录前置的惰性复位与
     * `console.php user:unlock` 都走这里，避免两处各写一份 SQL。
     *
     * 只改 locked，绝不动 disabled —— 禁用是运营决定，不该被解锁动作顺手放开。
     */
    public function clearLock(int $userId): void
    {
        $sql = 'UPDATE hq_user
                   SET failed_attempts = 0,
                       locked_until = NULL,
                       status = CASE WHEN status = \'locked\' THEN \'active\' ELSE status END,
                       updated_at = NOW()
                 WHERE id = :id';
        $this->pdo->prepare($sql)->execute([':id' => $userId]);
    }

    /** @return list<string> 权限码列表 */
    public function permissionsOf(int $roleId): array
    {
        $sql = 'SELECT p.code
                  FROM hq_role_permission rp
                  JOIN hq_permission p ON p.id = rp.permission_id
                 WHERE rp.role_id = :role';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':role' => $roleId]);

        return array_values(array_map(static fn ($r): string => (string) $r['code'], $stmt->fetchAll()));
    }

    public function create(string $username, string $passwordHash, int $roleId): int
    {
        $sql = 'INSERT INTO hq_user (username, password_hash, role_id, status, created_at, updated_at)
                VALUES (:username, :hash, :role, \'active\', NOW(), NOW())';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':username' => $username, ':hash' => $passwordHash, ':role' => $roleId]);

        return (int) $this->pdo->lastInsertId();
    }

    public function updatePassword(int $userId, string $passwordHash): void
    {
        $sql = 'UPDATE hq_user SET password_hash = :hash, updated_at = NOW() WHERE id = :id';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':hash' => $passwordHash, ':id' => $userId]);
    }
}
