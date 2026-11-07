<?php

declare(strict_types=1);

/**
 * 命令行批量导入（目录 → 作品库）。
 *
 * 与后台 /admin/works/import 走的是**同一个用例** WorkImportService::importBatch，
 * 差别只在文件从哪来：Web 从 $_FILES 拿 UploadedFile，这里从目录扫路径。
 * 之所以要有 CLI：回归测试需要一次灌进十几篇真实作品，用浏览器点两百次不现实。
 *
 * 用法：
 *   php -c php.ini bin/import.php --dir="storage/import-stage/某批" --author=某作者 --issue=25
 *   php -c php.ini bin/import.php --dir=... --author=... --dry          # 只看会导入哪些，不落库
 *   php -c php.ini bin/import.php --dir=... --author=... --force-dup    # 重复内容也再导一份
 *
 * 作者笔名：文件名里带「（作者：XXX）」时优先用文件名里的，否则用 --author。
 *
 * 注意 `open_basedir`：本项目的 php.ini 把可达范围限死在 server/ 目录内，
 * 所以 `--dir` 只能指向项目里的路径。外部语料先拷进 storage/import-stage/ 再导入
 * （不要为了图省事去放宽 open_basedir）。
 */

use App\Application\Work\WorkImportService;

$container = require dirname(__DIR__) . '/config/container.php';

$options = [];
foreach (array_slice($argv, 1) as $arg) {
    if (!str_starts_with($arg, '--')) {
        continue;
    }
    [$k, $v] = array_pad(explode('=', substr($arg, 2), 2), 2, '1');
    $options[$k] = $v;
}

$dir = (string) ($options['dir'] ?? '');
if ($dir === '' || !is_dir($dir)) {
    fwrite(STDERR, "用法：php -c php.ini bin/import.php --dir=<目录> --author=<笔名> [--issue=25] [--dry] [--no-recursive] [--force-dup]\n");
    if ($dir !== '') {
        fwrite(STDERR, "目录不可达：{$dir}\n");
        fwrite(STDERR, "若它在项目之外，多半是被 php.ini 的 open_basedir 挡住了 —— 先拷进 storage/import-stage/ 再导。\n");
    }
    exit(1);
}

$defaultAuthor = trim((string) ($options['author'] ?? ''));
$issueNo       = max(1, min(999, (int) ($options['issue'] ?? 25)));
$genreCode     = isset($options['genre']) && $options['genre'] !== '1' ? (string) $options['genre'] : null;
$tags          = isset($options['tags']) && $options['tags'] !== '1' ? (string) $options['tags'] : '';
$dry           = isset($options['dry']);
$recursive     = !isset($options['no-recursive']);
$forceDup      = isset($options['force-dup']);
$actorId       = (int) ($options['actor'] ?? 1);

/** @var WorkImportService $importer */
$importer = $container->get(WorkImportService::class);
/** @var PDO $pdo */
$pdo = $container->get(PDO::class);

// ---- 1. 扫目录 ----------------------------------------------------------
$allowed = ['txt', 'md', 'markdown', 'docx'];
$paths   = [];

$walk = static function (string $base) use (&$walk, $allowed, $recursive): array {
    $out = [];
    $it  = is_dir($base) ? scandir($base) : false;
    if ($it === false) {
        return $out;
    }
    foreach ($it as $name) {
        if ($name === '.' || $name === '..') {
            continue;
        }
        $full = $base . DIRECTORY_SEPARATOR . $name;
        if (is_dir($full)) {
            if ($recursive) {
                $out = array_merge($out, $walk($full));
            }
            continue;
        }
        // 「~$」是 Office 打开文档时留下的锁文件，不是作品
        if (str_starts_with($name, '~$')) {
            continue;
        }
        $ext = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));
        if (in_array($ext, $allowed, true)) {
            $out[] = $full;
        }
    }

    return $out;
};

$paths = $walk(rtrim($dir, '/\\'));
sort($paths);

if ($paths === []) {
    fwrite(STDOUT, "目录下没有可导入的文件（只认 " . implode(' / ', WorkImportService::ALLOWED_EXT) . "）。\n");
    exit(0);
}

// ---- 2. 逐个准备：解出作者、算 sha 判重、拷到临时文件 --------------------
$shaStmt = $pdo->prepare('SELECT work_id FROM hq_work_file WHERE sha256 = :sha LIMIT 1');

$prepared = [];
$skipped  = [];
$temps    = [];

foreach ($paths as $path) {
    $name = basename($path);
    $ext  = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));

    // 预演与实跑同一套判据。这些检查在 WorkImportService 里各有一份，
    // 但那是「实跑时抛异常」；预演阶段放过它们，--dry 就会报出一批注定失败的文件。
    // 判据本身只在 service 里定义一次（ALLOWED_EXT / MAX_BYTES），这里引用不复写。
    if (!in_array($ext, WorkImportService::ALLOWED_EXT, true)) {
        $skipped[] = [$name, '扩展名不支持（只接受 ' . implode(' / ', WorkImportService::ALLOWED_EXT) . '）'];
        continue;
    }

    $bytes = (int) @filesize($path);
    if ($bytes <= 0) {
        $skipped[] = [$name, '空文件（0 字节），正文必然为空'];
        continue;
    }
    if ($bytes > WorkImportService::MAX_BYTES) {
        $skipped[] = [$name, sprintf(
            '文件 %.1f MiB，超过 %.0f MiB 上限',
            $bytes / 1048576,
            WorkImportService::MAX_BYTES / 1048576
        )];
        continue;
    }

    $sha  = hash_file('sha256', $path);
    if ($sha === false) {
        $skipped[] = [$name, '文件不可读'];
        continue;
    }

    $shaStmt->execute([':sha' => $sha]);
    $dupWorkId = $shaStmt->fetchColumn();

    if ($dupWorkId !== false && !$forceDup) {
        $skipped[] = [$name, '内容重复，已属于作品 #' . (int) $dupWorkId];
        continue;
    }

    // 作者：文件名「（作者：XXX）」优先。必须有 /u —— 否则 [^）)] 是按字节否定的，
    // 全角括号的三个字节里只要不含那三个字节就"匹配成功"，结果永远解不出作者
    $author = $defaultAuthor;
    if (preg_match('/[（(]\s*作者\s*[:：]\s*([^）)]+)[）)]/u', $name, $m) === 1) {
        $author = trim($m[1]);
    }
    if ($author === '') {
        $skipped[] = [$name, '缺少作者笔名（文件名无「（作者：XXX）」，也没给 --author）'];
        continue;
    }

    // 拷到临时目录：UploadStore 对非上传文件用 copy，但走一遍临时文件
    // 可以让 CLI 与 Web 的入参形状完全一致，也避免误动用户的原文件
    $tmp = tempnam(sys_get_temp_dir(), 'hqimp');
    if ($tmp === false || !@copy($path, $tmp)) {
        $skipped[] = [$name, '临时文件创建失败'];
        continue;
    }
    $temps[] = $tmp;

    // 标题直接取文件名，所以把「（作者：XXX）」这段剪掉，只在作者字段里保留
    $cleanName = trim((string) preg_replace('/[（(]\s*作者\s*[:：]\s*[^）)]+[）)]/u', '', $name));

    $prepared[] = [
        'tmp_path'   => $tmp,
        'orig_name'  => $cleanName === '' ? $name : $cleanName,
        'ext'        => $ext,
        'mime'       => 'application/octet-stream',
        'size_bytes' => $bytes,
        '_author'    => $author,
        '_path'      => $path,
    ];
}

if ($prepared === []) {
    fwrite(STDOUT, "没有可导入的文件。\n");
    foreach ($skipped as [$n, $why]) {
        fwrite(STDOUT, sprintf("  跳过 %-40s %s\n", $n, $why));
    }
    exit(0);
}

// ---- 3. dry-run：只报告 -------------------------------------------------
if ($dry) {
    fwrite(STDOUT, sprintf("将导入 %d 篇（期次 %d，dry-run 不落库）：\n", count($prepared), $issueNo));
    foreach ($prepared as $p) {
        fwrite(STDOUT, sprintf("  %-44s 作者：%s\n", $p['orig_name'], $p['_author']));
    }
    foreach ($skipped as [$n, $why]) {
        fwrite(STDOUT, sprintf("  跳过 %-40s %s\n", $n, $why));
    }
    foreach ($temps as $t) {
        @unlink($t);
    }
    exit(0);
}

// importBatch 只接受统一的作者，所以按作者分组各走一批
$groups = [];
foreach ($prepared as $p) {
    $groups[$p['_author']][] = $p;
}

$okTotal     = 0;
$failedTotal = 0;
$rows        = [];

try {
    foreach ($groups as $author => $files) {
        fwrite(STDOUT, sprintf("\n导入 %d 篇，作者：%s\n", count($files), $author));
        $result = $importer->importBatch($files, [
            'author'     => (string) $author,
            'issue_no'   => $issueNo,
            'genre_code' => $genreCode,
            'tags'       => $tags,
        ], $actorId);

        $okTotal     += (int) $result['ok'];
        $failedTotal += (int) $result['failed'];

        foreach ($result['results'] as $row) {
            $rows[] = $row;
            if ($row['ok']) {
                fwrite(STDOUT, sprintf("  ok   %-44s %s\n", $row['name'], $row['code']));
                foreach ($row['warnings'] ?? [] as $w) {
                    fwrite(STDOUT, '       ! ' . $w . "\n");
                }
            } else {
                fwrite(STDOUT, sprintf("  fail %-44s %s\n", $row['name'], $row['error']));
            }
        }
    }
} finally {
    foreach ($temps as $t) {
        @unlink($t);
    }
}

foreach ($skipped as [$n, $why]) {
    fwrite(STDOUT, sprintf("跳过 %-44s %s\n", $n, $why));
}

fwrite(STDOUT, sprintf("\n合计：成功 %d 篇，失败 %d 篇，跳过 %d 篇\n", $okTotal, $failedTotal, count($skipped)));

exit($failedTotal > 0 ? 1 : 0);
