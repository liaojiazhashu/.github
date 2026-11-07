<?php

declare(strict_types=1);

/**
 * 迁移执行器。
 *
 * 在此之前，迁移是靠人把 .sql 贴进客户端跑的。问题是：
 *   · 跑没跑过、跑到哪一条，只能靠记忆；
 *   · 1300 篇批跑期间要动表，忘记某一条的后果是「字段不存在」这种 500；
 *   · 同一个文件被跑两遍，ALTER 直接报错中断，人在慌乱中很可能手工补一半。
 *
 * 所以补上这个脚本。三条约定：
 *   1. **只用文件名排序**（001_、002_…），不解析版本号；
 *   2. **checksum 记录**：已执行文件被改动过会报警 —— 已上线的迁移不该再改，
 *      要改就新加一条；
 *   3. `*_rollback.sql` 永不自动执行，只由人显式点名。
 *
 * 用法：
 *   php -c php.ini bin/migrate.php                     # 列出待执行（预览）
 *   php -c php.ini bin/migrate.php --apply             # 执行待执行的
 *   php -c php.ini bin/migrate.php --baseline          # 首次接入：把现存迁移记为已应用，不执行
 *   php -c php.ini bin/migrate.php --status            # 已应用 / 未应用的清单
 *
 * 首次接手一个手工建好的库，先跑 --baseline，再跑 --apply。
 */

use App\Support\Config;
use App\Support\Db;

require dirname(__DIR__) . '/config/bootstrap.php';

$options = [];
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--')) {
        [$k, $v] = array_pad(explode('=', substr($arg, 2), 2), 2, '1');
        $options[$k] = $v;
    }
}

$config = new Config(require dirname(__DIR__) . '/config/settings.php');

/**
 * 迁移用**管理连接**，不用应用连接。
 *
 * `hqlib_app` 只有 DML 权限，ALTER 会被 MySQL 拒绝（1142）——这不是配置疏漏，
 * 是刻意划的边界：应用进程被攻破时，攻击者改不了表结构，也就无法用改表的方式
 * 绕过应用层的约束。所以迁移必须另找一条能 DDL 的连接。
 *
 * 凭据来源**按优先级三级**，都不落进版本库：
 *   1. `--user=` / `--password=`（当前进程参数，适合一次性运维）；
 *   2. 环境变量 `MIGRATE_DB_USER` / `MIGRATE_DB_PASS`（适合批处理脚本）；
 *   3. 项目内 `storage/mysql-admin.cnf`（已 gitignore，适合常驻本机）。
 *
 * 之所以不直接读 `D:\webserver\mysql56\client-root.cnf`：php.ini 的 open_basedir
 * 把可访问路径钉死在项目目录内（这是对的，不该为了省事放开），项目外的文件读不到。
 */
$parseCnf = static function (string $path): array {
    $out = [];
    $section = '';
    foreach (preg_split('/\r\n|\r|\n/', (string) @file_get_contents($path)) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (str_starts_with($line, '[')) {
            $section = trim($line, "[] \t");
            continue;
        }
        if ($section !== 'client' || !str_contains($line, '=')) {
            continue;
        }
        [$k, $v] = explode('=', $line, 2);
        $out[trim($k)] = trim($v);
    }

    return $out;
};

/** @var array{user:string, pass:string, host:string, port:int}|null */
$credential = null;

// 1) 命令行参数
if (isset($options['user'], $options['password'])) {
    $credential = [
        'user' => (string) $options['user'],
        'pass' => (string) $options['password'],
        'host' => (string) ($options['host'] ?? $config->string('db.host', '127.0.0.1')),
        'port' => (int) ($options['port'] ?? $config->int('db.port', 3306)),
    ];
}

// 2) 环境变量
if ($credential === null) {
    $envUser = getenv('MIGRATE_DB_USER');
    $envPass = getenv('MIGRATE_DB_PASS');
    if (is_string($envUser) && $envUser !== '' && is_string($envPass)) {
        $credential = [
            'user' => $envUser,
            'pass' => $envPass,
            'host' => (string) (getenv('MIGRATE_DB_HOST') ?: $config->string('db.host', '127.0.0.1')),
            'port' => (int) (getenv('MIGRATE_DB_PORT') ?: $config->int('db.port', 3306)),
        ];
    }
}

// 3) 项目内的 cnf
if ($credential === null) {
    $cnfPath = (string) ($options['cnf'] ?? dirname(__DIR__) . '/storage/mysql-admin.cnf');
    if (is_file($cnfPath)) {
        $cnf = $parseCnf($cnfPath);
        if (isset($cnf['user'], $cnf['password'])) {
            $credential = [
                'user' => $cnf['user'],
                'pass' => $cnf['password'],
                'host' => (string) ($cnf['host'] ?? $config->string('db.host', '127.0.0.1')),
                'port' => (int) ($cnf['port'] ?? $config->int('db.port', 3306)),
            ];
        }
    }
}

if ($credential === null) {
    fwrite(STDERR, "找不到可用的管理连接（应用账号没有 ALTER 权限，这是有意的权限边界）。\n");
    fwrite(STDERR, "任选一种方式提供 DDL 凭据：\n");
    fwrite(STDERR, "  · php -c php.ini bin/migrate.php --apply --user=root --password=...\n");
    fwrite(STDERR, "  · \$env:MIGRATE_DB_USER='root'; \$env:MIGRATE_DB_PASS='...'; php -c php.ini bin/migrate.php --apply\n");
    fwrite(STDERR, "  · 在 storage/mysql-admin.cnf 写入含 [client] user/password 的配置（已 gitignore）\n");
    exit(2);
}

// 直接构造 PDO 而不复用 Db::connect：Db 是单例，把它占成管理连接后，
// 后续任何需要应用连接的代码都会悄悄用上管理权限 —— 那正是这条边界要防的事。
$admin = new PDO(
    sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        $credential['host'],
        $credential['port'],
        $config->string('db.name', 'hqlib')
    ),
    $credential['user'],
    $credential['pass'],
    [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]
);
$admin->exec('SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci');

$pdo = $admin;

$dir = dirname(__DIR__) . '/database/migrations';

$files = [];
foreach (glob($dir . '/*.sql') ?: [] as $path) {
    $name = basename($path);
    if (str_contains($name, '_rollback')) {
        continue;   // 回滚脚本永不自动跑
    }
    $files[$name] = $path;
}
ksort($files);

/** @var array<string, string> 文件名 → checksum */
$applied = [];
foreach ($pdo->query('SELECT filename, checksum FROM hq_migration')->fetchAll() as $row) {
    $applied[(string) $row['filename']] = (string) $row['checksum'];
}

$pending = [];
$changed = [];
foreach ($files as $name => $path) {
    $checksum = hash_file('sha256', $path) ?: '';
    if (!array_key_exists($name, $applied)) {
        $pending[$name] = $path;
    } elseif ($applied[$name] !== $checksum) {
        $changed[] = $name;
    }
}

if (isset($options['status'])) {
    foreach ($files as $name => $path) {
        printf("%-32s %s\n", $name, array_key_exists($name, $applied) ? '已应用' : '待执行');
    }
    exit(0);
}

if ($changed !== []) {
    fwrite(STDERR, "⚠️  以下迁移在应用后被改动过，checksum 不匹配：\n");
    foreach ($changed as $name) {
        fwrite(STDERR, "    {$name}\n");
    }
    fwrite(STDERR, "已上线的迁移不应再改内容，要修正就新增一条迁移。\n");
}

if (isset($options['baseline'])) {
    // 首次接入：库里已经有表（手工建过），那就把现存迁移标记为已应用，一条都不执行。
    //
    // ⚠️ 必须用 --through 划清界限。不带界限时会把**还没跑过的新迁移**也标成已应用，
    //    结果是「迁移记录说跑过了，字段其实不存在」——这次踩过一次，所以留这个参数。
    $through = (string) ($options['through'] ?? '');
    $n = 0;
    $stmt = $pdo->prepare(
        'INSERT IGNORE INTO hq_migration (filename, checksum, applied_at, duration_ms) VALUES (:f, :c, NOW(), 0)'
    );
    foreach ($files as $name => $path) {
        if (array_key_exists($name, $applied)) {
            continue;
        }
        if ($through !== '' && strcmp($name, $through) > 0) {
            continue;
        }
        $stmt->execute([':f' => $name, ':c' => hash_file('sha256', $path) ?: '']);
        printf("baseline  %s\n", $name);
        $n++;
    }
    fwrite(STDOUT, sprintf("已把 %d 条现存迁移标记为已应用（未执行任何 SQL）。\n", $n));
    if ($through === '') {
        fwrite(STDOUT, "注意：未指定 --through，若其中含有尚未执行的新迁移，请手动删除其 hq_migration 记录后重跑 --apply。\n");
    }
    exit(0);
}

if ($pending === []) {
    fwrite(STDOUT, "没有待执行的迁移。\n");
    exit(0);
}

if (!isset($options['apply'])) {
    fwrite(STDOUT, "待执行：\n");
    foreach ($pending as $name => $path) {
        printf("    %s\n", $name);
    }
    fwrite(STDOUT, "加 --apply 执行。\n");
    exit(0);
}

/**
 * 按分号切分 SQL。
 *
 * 只处理本项目会写的语句：不含存储过程、不含 DELIMITER。
 * 注释先整行剥掉 —— 注释里出现分号会把语句切碎。
 *
 * ⚠️ 切行必须用 `/\r\n|\r|\n/`，**不能用 `\R`**：PCRE 在非 UTF-8 模式下把字节 0x85
 *    也当作换行（NEL），而 UTF-8 汉字里随处可见 0x85（"关" = E5 85 B3）。
 *    实测后果是「开关」被劈成两半，注释剥离失效，整段中文注释被当成 SQL 发出去。
 */
$split = static function (string $sql): array {
    $lines = preg_split('/\r\n|\r|\n/', $sql) ?: [];
    $kept  = [];
    foreach ($lines as $line) {
        $trimmed = ltrim($line);
        if ($trimmed === '' || str_starts_with($trimmed, '--') || str_starts_with($trimmed, '#')) {
            continue;
        }
        $kept[] = $line;
    }

    $out = [];
    foreach (explode(';', implode("\n", $kept)) as $statement) {
        $statement = trim($statement);
        if ($statement !== '') {
            $out[] = $statement;
        }
    }

    return $out;
};

$insert = $pdo->prepare(
    'INSERT INTO hq_migration (filename, checksum, applied_at, duration_ms) VALUES (:f, :c, NOW(), :ms)'
);

$failed = 0;
foreach ($pending as $name => $path) {
    $started = microtime(true);
    $sql     = (string) file_get_contents($path);
    $parts   = $split($sql);

    fwrite(STDOUT, sprintf("执行 %s（%d 条语句）…\n", $name, count($parts)));

    try {
        foreach ($parts as $i => $statement) {
            try {
                $pdo->exec($statement);
            } catch (Throwable $e) {
                $head = mb_substr(preg_replace('/\s+/', ' ', $statement) ?? '', 0, 120);
                fwrite(STDERR, sprintf("  ✗ 第 %d 条失败：%s\n    语句：%s…\n", $i + 1, $e->getMessage(), $head));
                throw $e;
            }
        }

        $insert->execute([
            ':f'  => $name,
            ':c'  => hash_file('sha256', $path) ?: '',
            ':ms' => (int) round((microtime(true) - $started) * 1000),
        ]);
        fwrite(STDOUT, sprintf("  ✓ 完成（%d ms）\n", (int) round((microtime(true) - $started) * 1000)));
    } catch (Throwable) {
        // 中途失败就停：后面的迁移多半依赖这一条的结果。
        // MySQL 的 DDL 隐式提交，已执行的语句收不回来 —— 所以这一步只保证「不再往前推进」，
        // 收尾要靠人按 *_rollback.sql 手工处理。
        $failed++;
        fwrite(STDERR, "已中止。修好这一条后用 --status 确认，再重跑 --apply。\n");
        break;
    }
}

exit($failed > 0 ? 1 : 0);
