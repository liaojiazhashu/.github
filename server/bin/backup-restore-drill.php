<?php

declare(strict_types=1);

/**
 * 恢复演练（附录 E-65）：把一份备份真的恢复到一个独立的库，然后逐表比对。
 *
 * 「有备份」和「备份能恢复」是两件事，而第二件只能在演练里被证明。
 * 没演练过的备份，本质上是一堆看起来很安心的字节。
 *
 * 三条边界：
 *   · 演练写到 **hqlib_drill**，与生产库完全隔离；应用账号对它没有任何权限；
 *   · 演练用独立账号（DRILL_DB_*），因为这个账号需要 DDL，而应用账号不该有；
 *   · 比对的是「内容」不是「字节」：每张表算 行数 + 各行 md5 的异或，
 *     恢复后行序变了也能比（附录 E-65 要的是数据完整，不是文件相同）。
 *
 * 用法：
 *   php -c php.ini bin/backup-restore-drill.php                 # 拿最新一份
 *   php -c php.ini bin/backup-restore-drill.php --file=...
 *   php -c php.ini bin/backup-restore-drill.php --keep        # 演练完保留演练库数据（默认保留）
 *
 * 退出码：0 完全一致；1 有差异或恢复失败。
 */

$root   = require dirname(__DIR__) . '/config/bootstrap.php';
$config = new App\Support\Config(require dirname(__DIR__) . '/config/settings.php');
$crypto = new App\Support\Crypto($config);

$options = [];
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--')) {
        [$k, $v] = array_pad(explode('=', substr($arg, 2), 2), 2, '');
        $options[$k] = $v;
    }
}

$dir  = $config->string('backup.dir', $root . '/storage/backups');
$file = $options['file'] ?? '';

if ($file === '') {
    $files = glob($dir . '/*.dump.enc') ?: [];
    rsort($files);
    $file = $files[0] ?? '';
}

if ($file === '' || !is_file($file)) {
    fwrite(STDERR, "找不到备份文件：{$file}\n");
    exit(1);
}

$drill = (array) $config->get('backup.drill', []);
$dsn   = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $drill['host'], $drill['port'], $drill['name']);

try {
    $pdo = new PDO($dsn, (string) $drill['user'], (string) $drill['pass'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    fwrite(STDERR, "连不上演练库 {$dsn}：" . $e->getMessage() . "\n");
    fwrite(STDERR, "（需要先建库并授权：见 README §4.1）\n");
    exit(1);
}

$started = microtime(true);

// ---- 1) 读头部 ----
$fh = fopen($file, 'rb');
$headerLine = (string) fgets($fh);
$header = json_decode($headerLine, true);
if (!is_array($header) || ($header['type'] ?? '') !== 'hqdump') {
    fwrite(STDERR, "备份文件头部无法解析（期望 type=hqdump）\n");
    exit(1);
}

fwrite(STDOUT, sprintf("演练：%s（%d 块，明文 %.1f KB）\n", basename($file), (int) $header['chunks'], ((int) $header['bytes']) / 1024));

// ---- 2) 解密 + 解压到临时 JSONL ----
$tmp = (string) tempnam(sys_get_temp_dir(), 'hqlib-restore-');
$out = fopen($tmp, 'wb');
$chunks = 0;

while (($line = fgets($fh)) !== false) {
    $rec = json_decode($line, true);
    if (!is_array($rec) || ($rec['type'] ?? '') !== 'chunk') {
        continue;
    }
    $packed = $crypto->open((string) $rec['data']);
    // gzdeflate 产出的是裸 deflate（RFC 1951），必须用 gzinflate；
    // 用 gzuncompress（RFC 1950，带 zlib 头）会 data error —— 这里踩过一次。
    $plain = gzinflate($packed);
    if ($plain === false) {
        fwrite(STDERR, "第 {$rec['i']} 块解压失败\n");
        exit(1);
    }
    fwrite($out, $plain);
    $chunks++;
}
fclose($out);
fclose($fh);

if ($chunks !== (int) $header['chunks']) {
    fwrite(STDERR, sprintf("分块数不符：头部 %d，实际 %d（文件可能被截断）\n", (int) $header['chunks'], $chunks));
    exit(1);
}

// 元信息在负载末尾（它跟数据一起加密），先扫一遍拿到表清单
$meta = null;
$scan = fopen($tmp, 'rb');
while (($line = fgets($scan)) !== false) {
    if (str_contains($line, '"type":"meta"')) {
        $meta = json_decode($line, true);
    }
}
fclose($scan);

if (!is_array($meta) || !isset($meta['tables'])) {
    fwrite(STDERR, "备份里找不到元信息（meta 行缺失）\n");
    exit(1);
}

fwrite(STDOUT, sprintf(
    "  备份时间 %s / 表 %d 张 / 行 %d\n",
    (string) $meta['created_at'],
    count($meta['tables']),
    (int) $meta['rows']
));

// ---- 3) 清空演练库 ----
$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
$existing = $pdo->query(
    "SELECT table_name FROM information_schema.tables
      WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'"
)->fetchAll(PDO::FETCH_COLUMN);
foreach ($existing as $table) {
    $pdo->exec('DROP TABLE `' . $table . '`');
}

// ---- 4) 重建 + 灌数据 ----
$fh = fopen($tmp, 'rb');
$createStatements = 0;
$inserted = 0;
$currentTable = '';
$insertStmt = null;
$columns = [];

$decode = static function (mixed $v): mixed {
    if (is_array($v) && array_key_exists('b64', $v)) {
        return base64_decode((string) $v['b64'], true);
    }

    return $v;
};

// 事务只能包住 DML：MySQL 的 DDL 会隐式提交，把 CREATE TABLE 放进事务里
// 下一次 commit 就会报「There is no active transaction」。所以按表开事务。
$inTx = false;

while (($line = fgets($fh)) !== false) {
    $rec = json_decode($line, true);
    if (!is_array($rec)) {
        continue;
    }

    if ($rec['type'] === 'table') {
        if ($inTx) {
            $pdo->commit();
            $inTx = false;
        }
        $pdo->exec((string) $rec['create']);
        $createStatements++;
        $currentTable = (string) $rec['name'];
        $insertStmt = null;
        $columns = [];
        $pdo->beginTransaction();
        $inTx = true;
        continue;
    }

    if ($rec['type'] !== 'row') {
        continue;
    }

    $row = (array) $rec['v'];
    if ($insertStmt === null) {
        $columns = array_keys($row);
        $quoted  = array_map(static fn (string $c): string => '`' . $c . '`', $columns);
        $marks   = implode(', ', array_fill(0, count($columns), '?'));
        $insertStmt = $pdo->prepare(
            'INSERT INTO `' . $rec['t'] . '` (' . implode(', ', $quoted) . ') VALUES (' . $marks . ')'
        );
    }

    $values = [];
    foreach ($columns as $col) {
        $values[] = $decode($row[$col] ?? null);
    }
    $insertStmt->execute($values);
    $inserted++;
}

if ($inTx) {
    $pdo->commit();
}
fclose($fh);
@unlink($tmp);

// ---- 5) 逐表比对 ----
// 与 bin/backup.php 完全相同的归一化：否则二进制列（contact_cipher / totp_secret_cipher）
// 在备份里是 base64 标记、在恢复库里是原始字节，校验和必然不等 —— 那是假阳性
$encode = static function (mixed $v): mixed {
    if ($v === null) {
        return null;
    }
    if (is_string($v) && preg_match('//u', $v) !== 1) {
        return ['b64' => base64_encode($v)];
    }

    return is_string($v) ? $v : (string) $v;
};

$live = [];
foreach ($pdo->query(
    "SELECT table_name FROM information_schema.tables
      WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'"
)->fetchAll(PDO::FETCH_COLUMN) as $table) {
    $stmt = $pdo->query('SELECT * FROM `' . $table . '`');
    $rows = 0;
    $xor  = '';
    while (($row = $stmt->fetch()) !== false) {
        $clean = [];
        foreach ($row as $col => $value) {
            $clean[$col] = $encode($value);
        }
        $h = hash('md5', (string) json_encode(['type' => 'row', 't' => $table, 'v' => $clean], JSON_UNESCAPED_UNICODE), true);
        $xor = $rows === 0 ? $h : $xor ^ $h;
        $rows++;
    }
    $stmt->closeCursor();
    $live[$table] = ['rows' => $rows, 'xor' => bin2hex($xor)];
}

$diffs = [];
foreach ($meta['tables'] as $expect) {
    $name = (string) $expect['name'];
    if (!isset($live[$name])) {
        $diffs[] = $name . '：恢复后不存在';
        continue;
    }
    if ((int) $expect['rows'] !== $live[$name]['rows']) {
        $diffs[] = sprintf('%s：行数 %d → %d', $name, (int) $expect['rows'], $live[$name]['rows']);
        continue;
    }
    if ((string) $expect['xor'] !== $live[$name]['xor']) {
        $diffs[] = $name . '：行数一致但内容校验和不一致';
    }
}

$extra = array_diff(array_keys($live), array_column($meta['tables'], 'name'));
foreach ($extra as $name) {
    $diffs[] = $name . '：备份里没有这张表';
}

$elapsed = microtime(true) - $started;

// 把结论落成一份报告：附录 E-65 的判定不该靠人回忆「上次演练过了」
$reportDir = $root . '/storage/reports';
if (!is_dir($reportDir)) {
    @mkdir($reportDir, 0750, true);
}
$report = [
    'drilled_at'  => date('c'),
    'file'        => basename($file),
    'tables'      => $createStatements,
    'rows'        => $inserted,
    'seconds'     => round($elapsed, 2),
    'consistent'  => $diffs === [],
    'diffs'       => $diffs,
];
file_put_contents($reportDir . '/restore-drill-latest.json', json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
file_put_contents($reportDir . '/restore-drill-' . date('Ymd-His') . '.json', json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");

if ($diffs === []) {
    fwrite(STDOUT, sprintf(
        "\n恢复结论：**一致**\n  建表 %d 张 / 写入 %d 行 / 校验 %d 张表的 行数+内容异或 / 耗时 %.1fs\n",
        $createStatements,
        $inserted,
        count($meta['tables']),
        $elapsed
    ));
    exit(0);
}

fwrite(STDOUT, sprintf("\n恢复结论：**不一致**（%d 处）\n", count($diffs)));
foreach (array_slice($diffs, 0, 15) as $d) {
    fwrite(STDOUT, '  · ' . $d . "\n");
}
exit(1);
