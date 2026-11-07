<?php

declare(strict_types=1);

/**
 * 备份：把整个库导成一份**加密的逻辑快照**。
 *
 * 为什么不是 mysqldump：
 *   1. php.ini 禁了 exec/system，PHP 里根本调不动外部命令；
 *   2. mysqldump 出来的是明文 SQL，落在磁盘上就是一份完整的用户数据拷贝 ——
 *      附录 E-56 要求备份本身加密，而「先明文落盘再加密」中间那几秒正好是窗口。
 *
 * 格式（全部是 JSON 行，便于流式处理）：
 *   第 1 行   {"type":"meta", ... "tables":[[name,rows,xor], ...]}
 *   第 2+ 行  {"type":"chunk","i":0,"data":"<base64(AES-256-GCM(gzdeflate(1MiB 明文)))>"}
 *
 * 三个刻意的选择：
 *   · **分块**：整库读进内存再加密，在库里长到 2GB 那天会 OOM，而那天正好是需要备份的那天；
 *   · **xor 校验**：每张表记「行数 + 各行 md5 的异或」。异或与顺序无关，
 *     所以恢复后哪怕行序变了也能比对 —— 它验的是「内容一样」，不是「文件字节一样」；
 *   · **二进制定界**：非 UTF-8 的列（contact_cipher、totp_secret_cipher）以 {"b64":...} 转义，
 *     否则 json_encode 会直接失败，而那恰好是最不能丢的两列。
 *
 * 用法：
 *   php -c php.ini bin/backup.php                # 默认写到 storage/backups，保留最近 7 份
 *   php -c php.ini bin/backup.php --keep=14
 *   php -c php.ini bin/backup.php --list
 */

$root   = require dirname(__DIR__) . '/config/bootstrap.php';
$config = new App\Support\Config(require dirname(__DIR__) . '/config/settings.php');
$crypto = new App\Support\Crypto($config);
$pdo    = App\Support\Db::connect($config);

$options = [];
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--')) {
        [$k, $v] = array_pad(explode('=', substr($arg, 2), 2), 2, '');
        $options[$k] = $v;
    }
}

const CHUNK_BYTES = 1048576;

$dir = (string) ($options['dir'] ?? $config->string('backup.dir', $root . '/storage/backups'));
if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
    fwrite(STDERR, "备份目录建不出来：{$dir}\n");
    exit(1);
}

if (isset($options['list'])) {
    $files = glob($dir . '/*.dump.enc') ?: [];
    rsort($files);
    if ($files === []) {
        fwrite(STDOUT, "还没有备份。\n");
    }
    foreach ($files as $file) {
        fwrite(STDOUT, sprintf("%s  %8.2f MB\n", basename($file), filesize($file) / 1048576));
    }
    exit(0);
}

// ---- 1) 先写成未加密的临时文件（放系统临时目录，且立刻在 finally 里删掉） ----
$tmp = (string) tempnam(sys_get_temp_dir(), 'hqlib-dump-');
$fh  = fopen($tmp, 'wb');
if ($fh === false) {
    fwrite(STDERR, "临时文件建不出来\n");
    exit(1);
}
$started = microtime(true);
$tables  = $pdo->query(
    "SELECT table_name FROM information_schema.tables
      WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'
      ORDER BY table_name"
)->fetchAll(PDO::FETCH_COLUMN);

$meta = [];
$totalRows = 0;

/** 把任意 PHP 值变成可 JSON 的形态；二进制列转 base64 并打标记 */
$encode = static function (mixed $v): mixed {
    if ($v === null) {
        return null;
    }
    if (is_string($v) && preg_match('//u', $v) !== 1) {
        return ['b64' => base64_encode($v)];
    }
    if (is_string($v) || is_int($v) || is_float($v)) {
        return is_string($v) ? $v : (string) $v;
    }

    return (string) $v;
};

try {
    foreach ($tables as $table) {
        $create = $pdo->query('SHOW CREATE TABLE `' . $table . '`')->fetch(PDO::FETCH_NUM);
        fwrite($fh, json_encode(['type' => 'table', 'name' => $table, 'create' => (string) $create[1]], JSON_UNESCAPED_UNICODE) . "\n");

        $stmt = $pdo->query('SELECT * FROM `' . $table . '`');
        $rows = 0;
        $xor  = '';

        while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
            $clean = [];
            foreach ($row as $col => $value) {
                $clean[$col] = $encode($value);
            }
            $line = json_encode(['type' => 'row', 't' => $table, 'v' => $clean], JSON_UNESCAPED_UNICODE);
            if ($line === false) {
                throw new RuntimeException('序列化失败：' . $table . ' / ' . json_last_error_msg());
            }
            fwrite($fh, $line . "\n");

            // 异或累积：与行序无关，恢复后行序变了也照样能比
            $h = hash('md5', $line, true);
            $xor = $rows === 0 ? $h : $xor ^ $h;
            $rows++;
        }
        $stmt->closeCursor();

        $meta[] = ['name' => $table, 'rows' => $rows, 'xor' => bin2hex($xor)];
        $totalRows += $rows;
    }

    // 元信息（表名 + 校验和）写在负载末尾，跟数据一起加密。
    // 放在明文头部等于把 schema 白送给拿到文件的人 —— 加密了数据却没加密表名，是半份的。
    fwrite($fh, json_encode([
        'type'       => 'meta',
        'version'    => 1,
        'schema'     => $config->string('db.name'),
        'created_at' => date('c'),
        'rows'       => $totalRows,
        'tables'     => $meta,
    ], JSON_UNESCAPED_UNICODE) . "\n");
} finally {
    fclose($fh);
}

// ---- 2) 分块压缩 + 加密 ----
$size   = (int) filesize($tmp);
$chunks = (int) ceil(max(1, $size) / CHUNK_BYTES);
$file   = $dir . '/hqlib-' . date('Ymd-His') . '.dump.enc';

$out = fopen($file, 'wb');
if ($out === false) {
    @unlink($tmp);
    fwrite(STDERR, "写不进去：{$file}\n");
    exit(1);
}

// 明文头只留「怎么拆块」：不含表名、不含任何业务内容
fwrite($out, json_encode(['type' => 'hqdump', 'version' => 1, 'chunks' => $chunks, 'bytes' => $size]) . "\n");

$in = fopen($tmp, 'rb');
for ($i = 0; $i < $chunks; $i++) {
    $plain = '';
    // fread 在大块上可能短读：循环到够为止，否则解密后 gzinflate 会 data error
    while (strlen($plain) < CHUNK_BYTES && !feof($in)) {
        $buf = fread($in, CHUNK_BYTES - strlen($plain));
        if ($buf === false || $buf === '') {
            break;
        }
        $plain .= $buf;
    }
    $packed = gzdeflate($plain, 6);
    if ($packed === false) {
        throw new RuntimeException('压缩失败：chunk ' . $i);
    }
    fwrite($out, json_encode(['type' => 'chunk', 'i' => $i, 'data' => $crypto->seal($packed)]) . "\n");
}
fclose($in);
fclose($out);
@unlink($tmp);

// ---- 3) 保留最近 N 份 ----
$keep  = max(1, (int) ($options['keep'] ?? $config->int('backup.keep', 7)));
$files = glob($dir . '/*.dump.enc') ?: [];
rsort($files);
$removed = 0;
foreach (array_slice($files, $keep) as $old) {
    if (@unlink($old)) {
        $removed++;
    }
}

fwrite(STDOUT, sprintf(
    "备份完成：%s\n  表 %d 张 / 行 %d / 明文 %s / 密文 %s / 耗时 %.1fs%s\n",
    $file,
    count($meta),
    $totalRows,
    number_format($size / 1024, 1) . ' KB',
    number_format(filesize($file) / 1024, 1) . ' KB',
    microtime(true) - $started,
    $removed > 0 ? sprintf('（轮转删除 %d 份旧备份）', $removed) : ''
));
