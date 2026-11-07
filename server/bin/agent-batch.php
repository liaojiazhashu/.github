<?php

declare(strict_types=1);

/**
 * 代拆批次的编排：取一批待拆作品、批量导出任务包、批量校验入库。
 *
 * 与 agent-analyze.php 的分工：
 *   agent-analyze 管「一篇」的进退（导出 / 入库 / 公开裁定）；
 *   本命令管「一批」的收发（取哪些、导出成一组、一次入库一组）。
 *
 * 公开口径与一键拆解完全一致：活跃作者公开，僵尸作者只在后台保留。
 * 作者类型读 source:activity 的快照，人工结论（activity-override.json）优先。
 *
 * 用法：
 *   php -c php.ini bin/agent-batch.php pick --limit=18 --author=active [--export]
 *   php -c php.ini bin/agent-batch.php ingest --ids=8047,8080 [--dry]
 */

require dirname(__DIR__) . '/config/bootstrap.php';

use App\Application\Library\VisibilityService;
use App\Infrastructure\Pdo\AnalysisRepository;
use App\Infrastructure\Pdo\WorkRepository;
use App\Support\Config;
use App\Support\Db;

$root      = dirname(__DIR__);
$config    = new Config(require $root . '/config/settings.php');
$pdo       = Db::connect($config);
$works     = new WorkRepository($pdo);
$analyses  = new AnalysisRepository($pdo, $config->string('ai.artifact_dir', $root . '/storage/artifacts'));

$options = [];
$command = null;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--')) {
        [$k, $v] = array_pad(explode('=', substr($arg, 2), 2), 2, '1');
        $options[$k] = $v;
    } elseif ($command === null) {
        $command = $arg;
    }
}

/** 作者名 → 活跃状态（快照 + 人工结论） */
function authorStates(string $root): array
{
    $map  = [];
    $path = $root . '/storage/cache/source/activity.json';
    if (is_file($path)) {
        $raw = json_decode((string) file_get_contents($path), true);
        foreach ($raw['authors'] ?? [] as $row) {
            if (is_array($row) && isset($row['name'])) {
                $map[(string) $row['name']] = (string) ($row['state'] ?? 'unknown');
            }
        }
    }
    $ovPath = $root . '/storage/cache/source/activity-override.json';
    if (is_file($ovPath)) {
        $ov = json_decode((string) file_get_contents($ovPath), true);
        $nameByUid = [];
        $raw = is_file($path) ? json_decode((string) file_get_contents($path), true) : [];
        foreach ($raw['authors'] ?? [] as $row) {
            if (is_array($row) && isset($row['uid'])) {
                $nameByUid[(int) $row['uid']] = (string) ($row['name'] ?? '');
            }
        }
        foreach ($ov['authors'] ?? [] as $uid => $state) {
            $name = $nameByUid[(int) $uid] ?? '';
            if ($name !== '') {
                $map[$name] = (string) $state;
            }
        }
    }

    return $map;
}

$stateByName = authorStates($root);

if ($command === 'pick') {
    $limit  = max(1, (int) ($options['limit'] ?? 10));
    $want   = (string) ($options['author'] ?? '');
    $export = isset($options['export']);

    $rows = $pdo->query(
        "SELECT w.id, w.title, w.word_count, au.name AS author
           FROM hq_work w
           LEFT JOIN hq_author au ON au.id = w.author_id
          WHERE w.status = 'imported' AND w.deleted_at IS NULL
          ORDER BY w.word_count ASC, w.id ASC"
    )->fetchAll();

    $picked = [];
    foreach ($rows as $row) {
        $name  = (string) ($row['author'] ?? '');
        $state = $stateByName[$name] ?? 'unknown';
        $isActive = in_array($state, ['active', 'comment_only'], true);

        if ($want === 'active' && !$isActive) {
            continue;
        }
        if ($want === 'zombie' && $isActive) {
            continue;
        }

        $picked[] = $row;
        if (count($picked) >= $limit) {
            break;
        }
    }

    if ($picked === []) {
        fwrite(STDOUT, "PICKED=0\n");
        exit(0);
    }

    $ids = array_map(static fn (array $r): int => (int) $r['id'], $picked);
    fwrite(STDOUT, 'PICKED=' . count($ids) . "\n");
    fwrite(STDOUT, 'IDS=' . implode(',', $ids) . "\n");
    foreach ($picked as $row) {
        fwrite(STDOUT, sprintf("  %d\t%s\t%d字\t%s\n", $row['id'], $row['title'], $row['word_count'], $row['author']));
    }
    exit(0);
}

fwrite(STDERR, "用法：pick --limit=N --author=active|zombie\n");
exit(1);
