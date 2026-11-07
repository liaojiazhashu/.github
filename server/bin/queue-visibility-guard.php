<?php

declare(strict_types=1);

/**
 * 队列可见性护栏：把「僵尸作者」的待拆任务改成「拆完不公开」。
 *
 * 为什么需要这一步：
 *   一键拆解/批量入队时，任务载荷里带的是 `auto_publish: true`，worker 跑完就一律上架。
 *   但馆藏口径是「活跃作者的作品公开、僵尸作者的作品只在后台保留」。运营在后台
 *   勾「自动上架」时并不知道某个作者已经在这次批量里被判成僵尸了，于是队列里
 *   会留下一批「跑完就会误公开」的任务。
 *
 *   这个命令在开跑前把它们改成 `auto_publish: false` + `keep_hidden: true`，
 *   让 worker 走「置为不公开」那条分支（VisibilityService::hide，连带失效缓存）。
 *
 * 判定口径与 agent-analyze.php / 一键拆解完全一致：
 *   active / comment_only 算活跃（有投稿或有评稿）；activity-override 覆盖机器判断；
 *   快照里查不到的作者按不活跃处理 —— 宁可不公开，也不要误公开。
 *
 * 用法：
 *   php -c php.ini bin/queue-visibility-guard.php            # 预览，只报会改多少
 *   php -c php.ini bin/queue-visibility-guard.php --apply    # 真改
 */

require dirname(__DIR__) . '/config/bootstrap.php';

use App\Support\Config;

$container = require dirname(__DIR__) . '/config/container.php';
set_time_limit(0);

$apply = in_array('--apply', array_slice($argv, 1), true);

/** @var Config $config */
$config = $container->get(Config::class);
/** @var PDO $pdo */
$pdo = $container->get(PDO::class);

$root = $config->string('app.root');

// ---- 1. 作者活跃度：机器快照 + 人工覆盖 ----
$snapshotPath = $root . '/storage/cache/source/activity.json';
$raw          = is_file($snapshotPath) ? json_decode((string) file_get_contents($snapshotPath), true) : [];
if (!is_array($raw) || ($raw['authors'] ?? []) === []) {
    fwrite(STDERR, "活跃度快照不存在或为空：{$snapshotPath}\n先跑 bin/console.php source:activity 重建快照。\n");
    exit(2);
}

$states    = [];
$nameByUid = [];
foreach ($raw['authors'] as $row) {
    if (!is_array($row)) {
        continue;
    }
    $states[(string) ($row['name'] ?? '')] = (string) ($row['state'] ?? '');
    if (isset($row['uid'])) {
        $nameByUid[(int) $row['uid']] = (string) ($row['name'] ?? '');
    }
}

$ovPath = $root . '/storage/cache/source/activity-override.json';
if (is_file($ovPath)) {
    $ov = json_decode((string) file_get_contents($ovPath), true);
    foreach ($ov['authors'] ?? [] as $uid => $state) {
        $n = $nameByUid[(int) $uid] ?? '';
        if ($n !== '') {
            $states[$n] = (string) $state;
        }
    }
}

$isActive = static fn (string $name): bool
    => in_array($states[$name] ?? 'unknown', ['active', 'comment_only'], true);

// ---- 2. 扫待跑任务 ----
$rows = $pdo->query(
    "SELECT j.id, j.payload_json, w.id AS work_id, COALESCE(au.name, '') AS author
       FROM hq_job j
       JOIN hq_work w ON w.id = j.work_id
       LEFT JOIN hq_author au ON au.id = w.author_id
      WHERE j.type = 'analysis.run' AND j.status = 'queued'
      ORDER BY j.id"
)->fetchAll(PDO::FETCH_ASSOC);

$flip     = [];
$byAuthor = [];
$already  = 0;
foreach ($rows as $row) {
    $payload = json_decode((string) $row['payload_json'], true);
    if (!is_array($payload)) {
        continue;
    }
    if (!empty($payload['keep_hidden'])) {
        $already++;
        continue;
    }
    $author = (string) $row['author'];
    if ($isActive($author)) {
        continue;
    }
    $flip[]                     = $row;
    $label                      = $author === '' ? '（无作者）' : $author;
    $byAuthor[$label]           = ($byAuthor[$label] ?? 0) + 1;
}

arsort($byAuthor);

fwrite(STDOUT, sprintf(
    "待跑任务 %d 条；其中已是「不公开」的 %d 条；需要改的 %d 条，涉及 %d 位作者。\n\n",
    count($rows),
    $already,
    count($flip),
    count($byAuthor)
));

foreach ($byAuthor as $name => $n) {
    fwrite(STDOUT, sprintf("  %-24s %3d 篇  （机器判：%s）\n", $name, $n, $states[$name] ?? '不在快照'));
}

if ($flip === []) {
    fwrite(STDOUT, "\n队列已经符合口径，不需要改。\n");
    exit(0);
}

if (!$apply) {
    fwrite(STDOUT, "\n（预览）加 --apply 执行。\n");
    exit(0);
}

// ---- 3. 改载荷：auto_publish=false + keep_hidden=true ----
$update = $pdo->prepare('UPDATE hq_job SET payload_json = :p, updated_at = NOW() WHERE id = :id AND status = \'queued\'');
$pdo->beginTransaction();
try {
    foreach ($flip as $row) {
        $payload                  = json_decode((string) $row['payload_json'], true);
        $payload['auto_publish']  = false;
        $payload['keep_hidden']   = true;
        $update->execute([
            ':p'  => json_encode($payload, JSON_UNESCAPED_UNICODE),
            ':id' => (int) $row['id'],
        ]);
    }
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, '改载荷失败：' . $e->getMessage() . "\n");
    exit(1);
}

fwrite(STDOUT, sprintf("\n已改 %d 条任务为「拆完不公开」。\n", count($flip)));
