<?php

declare(strict_types=1);

/**
 * 重算字数（运维工具）。
 *
 * 什么时候用：字数口径调整过（例如把 \p{Han} 换成码位区段判定）、或怀疑某篇字数不对。
 * 它按当前代码里的 Text::countChars 重新解析原文并回写三张表：
 *   hq_work.word_count / hq_work_file.char_count / hq_work_version.char_count
 *
 * 用法：
 *   php -c php.ini bin/recount-words.php            # 只报告差异
 *   php -c php.ini bin/recount-words.php --apply    # 实际写回
 */

require dirname(__DIR__) . '/config/bootstrap.php';

use App\Infrastructure\Import\TextExtractor;
use App\Infrastructure\Import\DocxExtractor;
use App\Infrastructure\Storage\UploadStore;
use App\Support\Config;
use App\Support\Db;
use App\Support\Text;

$apply = in_array('--apply', $argv, true);

$config    = new Config(require dirname(__DIR__) . '/config/settings.php');
$pdo       = Db::connect($config);
$store     = new UploadStore($config->string('upload.dir'));
$extractor = new TextExtractor(new DocxExtractor());

$rows = $pdo->query(
    'SELECT f.id AS file_id, f.work_id, f.storage_key, f.orig_name, f.char_count, w.title, w.word_count
       FROM hq_work_file f JOIN hq_work w ON w.id = f.work_id
      ORDER BY f.work_id, f.id'
)->fetchAll();

if ($rows === []) {
    fwrite(STDOUT, "没有可重算的作品。\n");
    exit(0);
}

$changed = 0;
foreach ($rows as $row) {
    $ext = strtolower((string) pathinfo((string) $row['orig_name'], PATHINFO_EXTENSION));
    $key = (string) $row['storage_key'];

    try {
        $path   = $store->absolutePath($key);
        $parsed = $extractor->extract($path, $ext);
        $chars  = Text::countChars($parsed['text']);
    } catch (Throwable $e) {
        printf("#%-4d %-24s 跳过：%s\n", (int) $row['work_id'], $row['title'], $e->getMessage());
        continue;
    }

    $old = (int) $row['char_count'];
    if ($old === $chars) {
        printf("#%-4d %-24s %d 字（不变）\n", (int) $row['work_id'], $row['title'], $chars);
        continue;
    }

    $changed++;
    printf("#%-4d %-24s %d → %d 字\n", (int) $row['work_id'], $row['title'], $old, $chars);

    if (!$apply) {
        continue;
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE hq_work_file SET char_count = :c WHERE id = :id')
            ->execute([':c' => $chars, ':id' => (int) $row['file_id']]);
        $pdo->prepare('UPDATE hq_work_version SET char_count = :c WHERE file_id = :id')
            ->execute([':c' => $chars, ':id' => (int) $row['file_id']]);
        $pdo->prepare('UPDATE hq_work SET word_count = :c, updated_at = NOW() WHERE id = :id')
            ->execute([':c' => $chars, ':id' => (int) $row['work_id']]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        fwrite(STDERR, "写回失败：{$e->getMessage()}\n");
    }
}

printf("\n共 %d 篇，%d 篇字数有变化%s\n", count($rows), $changed, $apply ? '，已写回' : '，未写回（加 --apply 生效）');
