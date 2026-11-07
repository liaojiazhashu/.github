<?php

declare(strict_types=1);

/**
 * 词条百科自检：以「库 + 文件基线」的合并视图，检查覆盖与内容形态。
 *
 * 检查三件事（口径都是**可索引词条**，见下）：
 *   1. 缺百科 —— 库里和文件里都没有这一段，详情页会少「起源与发展」「世界科幻中的它」；
 *   2. history 字数越界 —— 写作建议是 80–200 字，越界列出来但不拦保存；
 *   3. classics 不是 3 部 —— 「世界科幻中的它」设计上就三格，多一部少一部都要人看一眼。
 *
 * 可索引口径（与公开词库一致）：
 *   status='active' 且 在用作品数 ≥1 且 (is_canonical=1 或 在用作品数 ≥2)
 * 「在用作品」照抄 LibraryRepository::LIVE_WORK：作品已发布、未删除、可见，
 * 且当前拆解已发布、可见。**不要在这里另写一套** —— 两套口径的差值会变成
 * 「自检说缺 X 条、运营在词库里找不到这 X 条」这种谁也解释不清的账单。
 *
 * 用法（必须带 -c php.ini，全局 ini 会连错库）：
 *   php -c php.ini bin/check-encyclopedia.php
 *   php -c php.ini bin/check-encyclopedia.php --apply   # 只回填 history 为空的行，不覆盖已有内容
 */

require dirname(__DIR__) . '/config/bootstrap.php';

use App\Application\Analysis\TermEncyclopedia;
use App\Infrastructure\Pdo\TermEncyclopediaRepository;
use App\Support\Config;
use App\Support\Db;

/** 与 LibraryRepository::LIVE_WORK 等价（那里是 private const，无法直接引用） */
const LIVE_WORK = "(w.status = 'published'
        AND w.deleted_at IS NULL
        AND w.visibility = 'visible'
        AND w.current_analysis_id IS NOT NULL
        AND EXISTS (
            SELECT 1 FROM hq_analysis al
             WHERE al.id = w.current_analysis_id
               AND al.status = 'published'
               AND al.visibility = 'visible'))";

const MIN_HISTORY = 80;
const MAX_HISTORY = 200;
const CLASSICS_SLOTS = 3;

$apply = in_array('--apply', $argv, true);

$file = __DIR__ . '/../resources/taxonomy/term_encyclopedia.php';
if (!is_file($file)) {
    fwrite(STDERR, "!! 找不到百科文件 {$file}\n");
    exit(1);
}
$fileMap = require $file;
if (!is_array($fileMap)) {
    fwrite(STDERR, "!! 百科文件不是数组\n");
    exit(1);
}

try {
    $pdo = Db::connect(new Config(require dirname(__DIR__) . '/config/settings.php'));
} catch (Throwable $e) {
    fwrite(STDERR, '!! 连不上库：' . $e->getMessage() . "\n");
    exit(2);
}

$repo = new TermEncyclopediaRepository($pdo);
$enc  = new TermEncyclopedia($repo);

// 可索引词条 + 在用作品数。MySQL 5.6 开着 ONLY_FULL_GROUP_BY，HAVING 不能引用
// SELECT 别名（1463），所以先算成一列，再套一层子查询过滤。
$sql = 'SELECT x.id, x.canonical, x.is_canonical, x.refs
          FROM (
                SELECT t.id, t.canonical, t.is_canonical,
                       (SELECT COUNT(DISTINCT wt.work_id)
                          FROM hq_work_term wt
                          JOIN hq_work w ON w.id = wt.work_id AND ' . LIVE_WORK . '
                         WHERE wt.term_id = t.id) AS refs
                  FROM hq_index_term t
                 WHERE t.status = \'active\'
               ) x
         WHERE x.refs >= 1 AND (x.is_canonical = 1 OR x.refs >= 2)
         ORDER BY x.id';

$indexable = $pdo->query($sql)->fetchAll();

$missing  = [];
$shortHis = [];
$longHis  = [];
$classics = [];
$issues   = 0;

foreach ($indexable as $row) {
    $termId    = (int) $row['id'];
    $canonical = (string) $row['canonical'];

    // 合并视图：先库后文件，与详情页读的是同一个方法，不会出现「自检说有、页面没有」
    $entry = $enc->find($termId, $canonical);

    if ($entry === null) {
        $missing[] = sprintf('%s（id=%d，在用 %d 篇）', $canonical, $termId, (int) $row['refs']);
        $issues++;
        continue;
    }

    $len = mb_strlen($entry['history'], 'UTF-8');
    if ($len < MIN_HISTORY) {
        $shortHis[] = sprintf('%s（id=%d，%d 字）', $canonical, $termId, $len);
        $issues++;
    } elseif ($len > MAX_HISTORY) {
        $longHis[] = sprintf('%s（id=%d，%d 字）', $canonical, $termId, $len);
        $issues++;
    }

    $n = count($entry['classics']);
    if ($n !== CLASSICS_SLOTS) {
        $classics[] = sprintf('%s（id=%d，%d 部）', $canonical, $termId, $n);
        $issues++;
    }
}

fwrite(STDOUT, sprintf(
    "合并视图：可索引词条 %d 个 · 文件基线 %d 条 · 缺百科 %d 个\n",
    count($indexable),
    count($fileMap),
    count($missing)
));

foreach ($missing as $line) {
    fwrite(STDOUT, "  !! 缺百科：{$line}\n");
}

fwrite(STDOUT, sprintf(
    "history 越界：少于 %d 字 %d 个 · 多于 %d 字 %d 个\n",
    MIN_HISTORY,
    count($shortHis),
    MAX_HISTORY,
    count($longHis)
));
foreach (array_merge($shortHis, $longHis) as $line) {
    fwrite(STDOUT, "  !! 字数：{$line}\n");
}

fwrite(STDOUT, sprintf("classics 不是 %d 部：%d 个\n", CLASSICS_SLOTS, count($classics)));
foreach ($classics as $line) {
    fwrite(STDOUT, "  !! 经典：{$line}\n");
}

// ── 回填 ──────────────────────────────────────────────────────────────────
// 只补 history 为空的行，且只从文件基线补。后台改过的内容一律不碰 ——
// 这个命令的定位是「把漏掉的基线补上」，不是「用文件覆盖库」。
$filled = 0;
if ($apply) {
    foreach ($indexable as $row) {
        $termId    = (int) $row['id'];
        $canonical = (string) $row['canonical'];
        $dbRow     = $repo->findByTermId($termId);

        if ($dbRow !== null && $dbRow['history'] !== '') {
            continue;   // 已有内容，跳过
        }

        $baseline = $enc->find(0, $canonical);   // 只查文件：termId=0 关掉库查询
        if ($baseline === null || $baseline['history'] === '') {
            continue;   // 文件里也没有，无从补起
        }

        // 库里已有 classics 就保留，别用文件的覆盖 —— 只补 history
        $keep = $dbRow['classics'] ?? [];
        $repo->upsert($termId, $baseline['history'], $keep !== [] ? $keep : $baseline['classics'], null);
        $filled++;
    }
    fwrite(STDOUT, sprintf("已回填 history %d 条（仅空值，未覆盖已有内容）\n", $filled));
}

fwrite(STDOUT, sprintf(
    "结论：%d 个问题%s\n",
    $issues,
    $issues === 0 ? '，全部合格' : '（缺百科属内容缺口，不影响老数据：读取侧会回落文件基线）'
));

exit($issues === 0 ? 0 : 1);
