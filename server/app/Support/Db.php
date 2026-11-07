<?php

declare(strict_types=1);

namespace App\Support;

use PDO;
use PDOException;
use RuntimeException;

/**
 * PDO 工厂。
 *
 * 三条硬约束（规划文档 §11.3）：
 *   1. ATTR_EMULATE_PREPARES = false —— 真预处理，杜绝拼接注入；
 *   2. ERRMODE_EXCEPTION —— 错误必须抛，不准静默；
 *   3. 连接后显式设置 sql_mode 与字符集 —— 不让「本机宽松、生产严格」造成行为差异。
 */
final class Db
{
    /** 与 001_init.sql 头部一致；5.6 与 8.x 都能接受这串值 */
    private const SQL_MODE = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,'
        . 'NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

    private static ?PDO $instance = null;

    public static function connect(Config $config): PDO
    {
        if (self::$instance instanceof PDO) {
            return self::$instance;
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $config->string('db.host'),
            $config->int('db.port'),
            $config->string('db.name'),
            $config->string('db.charset', 'utf8mb4')
        );

        try {
            $pdo = new PDO($dsn, $config->string('db.user'), $config->string('db.pass'), [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_STRINGIFY_FETCHES  => false,
                PDO::ATTR_TIMEOUT            => 5,
            ]);
        } catch (PDOException $e) {
            // 绝不把 DSN / 用户名带进异常消息，避免错误页泄露拓扑
            throw new RuntimeException('数据库连接失败', 0, $e);
        }

        $pdo->exec("SET SESSION sql_mode = '" . self::SQL_MODE . "'");
        $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo->exec("SET SESSION time_zone = '+08:00'");

        self::$instance = $pdo;

        return $pdo;
    }

    public static function reset(): void
    {
        self::$instance = null;
    }
}
