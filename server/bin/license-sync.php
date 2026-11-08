<?php

declare(strict_types=1);

/**
 * 授权条款同步与校验。
 *
 * 条款正文的唯一真相源是 resources/legal/*.md —— 不在库里改，不在模板里改，
 * 也不在迁移文件里写死。这个脚本只做两件事：把文件导进 hq_license_text，或者
 * 核对库里的正文与文件是否仍然一致。
 *
 * 为什么执着于摘要一致性：
 *   授权记录里存的是 `text_version`，作者点"同意"时看到的是那一版的正文。如果
 *   事后有人为了改个错别字直接 UPDATE 库里的 body，版本号不变、正文变了，那么
 *   "作者同意过 v1.0"这句话就变成了对另一份文本的同意 —— 需要举证时无法自证，
 *   而这恰恰是授权链里最不该出问题的一环。所以本脚本发现摘要不符时**报错退出**，
 *   改条款的正确做法是新增一个版本文件，不是改老文件。
 *
 * 用法：
 *   服务器（Linux，用系统 php-cli.ini）：
 *     php bin/license-sync.php --check
 *     php bin/license-sync.php --apply
 *   本机开发（Windows，用项目自带的 php.ini）：
 *     php.exe -c php.ini bin/license-sync.php --check
 *
 *   注意：**服务器上不要带 `-c php.ini`**。server/php.ini 是 Windows 专用配置，
 *   里面的 open_basedir 与 extension_dir 都是 D:\ 路径，在 Linux 上会让 require
 *   直接失败（open_basedir restriction，退出码 255、没有任何输出）。这个坑已经踩过一次。
 */

require dirname(__DIR__) . '/config/bootstrap.php';

use App\Support\Config;
use App\Support\Db;

$dir   = dirname(__DIR__) . '/resources/legal';
$apply = in_array('--apply', $argv, true);
$check = in_array('--check', $argv, true);

if (!$apply && !$check) {
    fwrite(STDOUT, "用法：\n  php -c php.ini bin/license-sync.php --check\n  php -c php.ini bin/license-sync.php --apply\n");
    exit(0);
}

try {
    $pdo = Db::connect(new Config(require dirname(__DIR__) . '/config/settings.php'));
} catch (Throwable $e) {
    fwrite(STDERR, '!! 连不上库：' . $e->getMessage() . "\n");
    exit(2);
}

// 表还没建时给出明确指引，而不是让 PDO 抛一张 42S02 的错误码出来。
try {
    $pdo->query('SELECT 1 FROM hq_license_text LIMIT 1');
} catch (Throwable $e) {
    fwrite(STDERR, "!! 表 hq_license_text 不存在。先执行迁移：\n");
    fwrite(STDERR, "   php -c php.ini bin/migrate.php --apply --user=root\n");
    exit(2);
}

/**
 * 规范化正文：统一换行、去掉首尾空白。
 *
 * 只做这两件事。折叠空格、去空行之类的"清洗"会让摘要变得不可预测 ——
 * 换个编辑器保存一次就变一次摘要，校验就失去了意义。
 */
function normalize(string $raw): string
{
    return trim(str_replace(["\r\n", "\r"], "\n", $raw));
}

/** 从条款文件里取出「版本 / 标题 / 生效日期」三个元信息，取不到就报错。 */
function parseMeta(string $body, string $file): array
{
    $meta = [];

    // 中文正则必须带 /u，否则 UTF-8 多字节会被按字节匹配，标点一多就匹配失败。
    if (preg_match('/^>\s*版本：\s*(v[0-9.]+)\s*$/mu', $body, $m)) {
        $meta['version'] = $m[1];
    }
    if (preg_match('/^#\s+(.+?)\s*$/mu', $body, $m)) {
        $meta['title'] = trim($m[1]);
    }
    if (preg_match('/^>\s*生效日期：\s*(\d{4})\s*年\s*(\d{1,2})\s*月\s*(\d{1,2})\s*日/mu', $body, $m)) {
        $meta['effective_at'] = sprintf('%04d-%02d-%02d 00:00:00', (int) $m[1], (int) $m[2], (int) $m[3]);
    }

    foreach (['version', 'title', 'effective_at'] as $k) {
        if (empty($meta[$k])) {
            fwrite(STDERR, "!! {$file} 缺少元信息「{$k}」，无法入库。\n");
            exit(1);
        }
    }

    return $meta;
}

$files = glob($dir . '/*.md') ?: [];
if ($files === []) {
    fwrite(STDERR, "!! {$dir} 下没有条款文件。\n");
    exit(1);
}

$bad    = 0;
$added  = 0;
$same   = 0;

foreach ($files as $file) {
    $raw  = (string) file_get_contents($file);
    $body = normalize($raw);
    $meta = parseMeta($body, basename($file));
    $hash = hash('sha256', $body);
    $name = basename($file);

    $stmt = $pdo->prepare('SELECT id, body, body_sha256, effective_at FROM hq_license_text WHERE version = ?');
    $stmt->execute([$meta['version']]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row === false) {
        if ($apply) {
            $ins = $pdo->prepare(
                'INSERT INTO hq_license_text (version, title, body, body_sha256, effective_at, created_at)
                 VALUES (?, ?, ?, ?, ?, NOW())'
            );
            $ins->execute([$meta['version'], $meta['title'], $body, $hash, $meta['effective_at']]);
            fwrite(STDOUT, "++ 已导入 {$meta['version']}（{$name}，{$hash}）\n");
            $added++;
        } else {
            fwrite(STDOUT, "?? 库中缺少 {$meta['version']}（{$name}），执行 --apply 可导入\n");
            $bad++;
        }
        continue;
    }

    // 摘要不一致：库里正文被改过，或者文件被改过。两种都算问题，都不能静默覆盖。
    if (!hash_equals((string) $row['body_sha256'], $hash)) {
        fwrite(STDERR, "!! {$meta['version']} 摘要不一致（{$name}）\n");
        fwrite(STDERR, "   库中：{$row['body_sha256']}\n");
        fwrite(STDERR, "   文件：{$hash}\n");
        fwrite(STDERR, "   改条款请新增版本文件，不要改已生效的版本。\n");
        $bad++;
        continue;
    }

    // 摘要对得上，但正文可能因编码/换行差异在字符串层面不同，一并核一遍。
    if (normalize((string) $row['body']) !== $body) {
        fwrite(STDERR, "!! {$meta['version']} 正文与文件不一致（摘要相同但内容不同，属异常）\n");
        $bad++;
        continue;
    }

    fwrite(STDOUT, "ok {$meta['version']}（{$name}）\n");
    $same++;
}

fwrite(STDOUT, sprintf("\n条款文件 %d 个：一致 %d，导入 %d，待处理/异常 %d\n", count($files), $same, $added, $bad));
exit($bad > 0 ? 1 : 0);
