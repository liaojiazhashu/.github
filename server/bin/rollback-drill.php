<?php

declare(strict_types=1);

/**
 * 回滚演练（附录 E-68）：把「代码 + 数据库」的退路真的走一遍。
 *
 * 「写了回滚脚本」和「回滚脚本能跑」是两件事。只有第一种时，出事那天才会发现
 * 脚本里少了个 DROP INDEX、或者触发器根本没被删掉 —— 而那天没有时间修脚本。
 *
 * 演练在 **hqlib_drill** 上做（与生产隔离，独立账号，可随便砸），走一个完整闭环：
 *
 *   1. 001 撤回   → 39 张表必须一张不剩
 *   2. 001 前滚   → 39 张表必须全部回来（证明回滚不是单向破坏）
 *   3. 002 前滚   → 审计表两个触发器在位，UPDATE 会被 45000 拒
 *   4. 002 撤回   → 触发器消失，**UPDATE 必须成功**（这才是「撤回生效」的证据，
 *                    「没报错」不算）
 *   5. 002 再前滚 → 触发器回来，UPDATE 重新被拒（可重复施加）
 *
 * 第 4 步是这套演练的重点：验证一个「保护」被移除，必须看到它保护的东西真的能改，
 * 否则你只是验证了 SQL 没语法错误。
 *
 * 用法：
 *   php -c php.ini bin/rollback-drill.php
 *   php -c php.ini bin/rollback-drill.php --json=storage/reports/x.json
 *
 * 退出码：0 全步通过；1 有断言失败。
 */

$root   = require dirname(__DIR__) . '/config/bootstrap.php';
$config = new App\Support\Config(require dirname(__DIR__) . '/config/settings.php');

$options = [];
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--')) {
        [$k, $v] = array_pad(explode('=', substr($arg, 2), 2), 2, '');
        $options[$k] = $v;
    }
}

$drill = (array) $config->get('backup.drill', []);
if ($drill === []) {
    fwrite(STDERR, "缺 backup.drill 配置（DRILL_DB_*）。\n");
    exit(1);
}

$dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $drill['host'], $drill['port'], $drill['name']);

try {
    $pdo = new PDO($dsn, (string) $drill['user'], (string) $drill['pass'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    fwrite(STDERR, "连不上演练库 {$dsn}：" . $e->getMessage() . "\n");
    fwrite(STDERR, "（先跑一次 bin/backup-restore-drill.php 建库授权）\n");
    exit(1);
}

$migrations = $root . '/database/migrations';

// ---------------------------------------------------------------------------
// 执行一个 .sql 文件：剥注释 → 按分号切 → 逐条 exec
//
// 401 行以上的迁移脚本不能整段丢给 PDO::exec（多语句默认关闭，且 5.6 驱动不支持）。
// 这里切分是安全的：本项目的迁移里没有存储过程，唯一的 BEGIN…END 风味的
// CREATE TRIGGER 也是单语句体（SIGNAL 结尾就一句话），不存在嵌套分号。
// ---------------------------------------------------------------------------
$runSqlFile = static function (PDO $pdo, string $path): int {
    $sql = (string) file_get_contents($path);

    // 去掉 -- 行注释（保留字符串里的 -- 不重要：本项目迁移里没有这种字符串）
    $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;

    $count = 0;
    foreach (explode(';', $sql) as $stmt) {
        $stmt = trim($stmt);
        if ($stmt === '') {
            continue;
        }
        $pdo->exec($stmt);
        $count++;
    }

    return $count;
};

$tableCount = static function (PDO $pdo): int {
    $row = $pdo->query(
        "SELECT COUNT(*) FROM information_schema.tables
          WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'"
    )->fetchColumn();

    return (int) $row;
};

$triggerCount = static function (PDO $pdo, string $table): int {
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.triggers
          WHERE trigger_schema = DATABASE() AND event_object_table = :t'
    );
    $stmt->execute([':t' => $table]);

    return (int) $stmt->fetchColumn();
};

/** 尝试对审计表做一次 UPDATE；返回 [是否被拒, 细节] */
$tryUpdateAudit = static function (PDO $pdo): array {
    try {
        $pdo->exec("INSERT INTO hq_audit_log (action, target_type, created_at) VALUES ('drill', 'drill', NOW())");
        $id = (int) $pdo->lastInsertId();
        $pdo->exec("UPDATE hq_audit_log SET action = 'drill.tampered' WHERE id = " . $id);

        return [false, '未被拒'];
    } catch (PDOException $e) {
        $state = (string) ($e->errorInfo[0] ?? $e->getCode());

        return [true, 'SQLSTATE ' . $state];
    }
};

$steps = [];
$fail  = 0;

/**
 * 记一步：$expect 为 true 表示「期望这一步成立」，不成立就计一次失败。
 * 每一步都同时记录「期望」与「实测」，报告里能看出是断言错了还是执行错了。
 */
$step = static function (string $name, bool $expect, string $evidence) use (&$steps, &$fail): void {
    $ok = $expect;
    if (!$ok) {
        $fail++;
    }
    $steps[] = ['name' => $name, 'ok' => $ok, 'evidence' => $evidence];
    printf("%s %s\n    %s\n", $ok ? '[OK  ]' : '[FAIL]', $name, $evidence);
};

$started = microtime(true);

// ---- 1) 001 撤回：39 张表必须清空 ------------------------------------------
$n = $runSqlFile($pdo, $migrations . '/001_init_rollback.sql');
$left = $tableCount($pdo);
$step('001 撤回：表清空', $left === 0, "执行 {$n} 条语句，剩余表 {$left} 张");

// ---- 2) 001 前滚：结构必须完整回来 -----------------------------------------
$n = $runSqlFile($pdo, $migrations . '/001_init.sql');
$back = $tableCount($pdo);
$step('001 前滚：39 张表重建', $back === 39, "执行 {$n} 条语句，重建 {$back} 张表");

// ---- 3) 002 前滚：触发器在位、UPDATE 被拒 ----------------------------------
$runSqlFile($pdo, $migrations . '/002_audit_append_only.sql');
$trg = $triggerCount($pdo, 'hq_audit_log');
[$rejected, $msg] = $tryUpdateAudit($pdo);
$step(
    '002 前滚：append-only 生效',
    $trg === 2 && $rejected,
    "触发器 {$trg} 个；UPDATE " . ($rejected ? "被拒（{$msg}）" : $msg)
);

// ---- 4) 002 撤回：触发器消失，UPDATE 必须成功 ------------------------------
$runSqlFile($pdo, $migrations . '/002_audit_append_only_rollback.sql');
$trg = $triggerCount($pdo, 'hq_audit_log');
[$rejected, $msg] = $tryUpdateAudit($pdo);
$step(
    '002 撤回：保护确实被移除',
    $trg === 0 && !$rejected,
    "触发器 {$trg} 个；UPDATE " . ($rejected ? "仍被拒（{$msg}）—— 撤回没生效" : '成功（保护已移除）')
);

// ---- 5) 002 再前滚：可重复施加 --------------------------------------------
$runSqlFile($pdo, $migrations . '/002_audit_append_only.sql');
$trg = $triggerCount($pdo, 'hq_audit_log');
[$rejected, $msg] = $tryUpdateAudit($pdo);
$step(
    '002 再前滚：可重复施加',
    $trg === 2 && $rejected,
    "触发器 {$trg} 个；UPDATE " . ($rejected ? "被拒（{$msg}）" : $msg)
);

$seconds = round(microtime(true) - $started, 1);

// 演练库留在「001 + 002 完整前滚」的状态，方便下一次直接重跑
$report = [
    'drilled_at' => date('c'),
    'database'   => $drill['name'],
    'seconds'    => $seconds,
    'ok'         => $fail === 0,
    'steps'      => $steps,
];

$jsonPath = $options['json'] ?? ($root . '/storage/reports/rollback-drill-latest.json');
if (!is_dir(dirname($jsonPath))) {
    @mkdir(dirname($jsonPath), 0750, true);
}
file_put_contents(
    $jsonPath,
    json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n"
);

fwrite(STDOUT, sprintf(
    "\n%s：%d 步，失败 %d 步，用时 %.1fs\n报告：%s\n",
    $fail === 0 ? '回滚演练通过' : '回滚演练失败',
    count($steps),
    $fail,
    $seconds,
    $jsonPath
));

exit($fail === 0 ? 0 : 1);
