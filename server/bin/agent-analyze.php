<?php

declare(strict_types=1);

/**
 * 外部代拆：把一篇作品的拆解任务导出成「任务包」，再把模型产出的六份 JSON 校验入库。
 *
 * 为什么要有这条路：
 *   Pipeline 是「调 DeepSeek API」的流水线，按 token 计费。当运营方不希望继续消耗
 *   那个 key 时，拆解本身并没有消失 —— 消失的只是「谁来生成这六段 JSON」。
 *   本命令把「生成」这一步交给外部模型，把「校验 + 落库」留在系统内，于是：
 *     - 产物仍然是同一套 StageGate 闸门把关，不因为换了生成者就放宽；
 *     - 落库路径与 Pipeline 完全一致（saveRaw / stageFinish / saveArtifacts），
 *       后台看到的数据、前台渲染的结果没有任何区别；
 *     - `model` 记录为 wb-v4.1-flash，账单里能一眼分出这批是谁拆的、花了 0 元。
 *
 * 用法：
 *   php -c php.ini bin/agent-analyze.php export --work=7719
 *   php -c php.ini bin/agent-analyze.php ingest --work=7719 --dir=storage/agent-tasks/7719 [--publish] [--hidden] [--dry]
 */

require dirname(__DIR__) . '/config/bootstrap.php';

use App\Application\Analysis\GateResult;
use App\Application\Analysis\PromptLibrary;
use App\Application\Analysis\PublishService;
use App\Application\Analysis\StageGate;
use App\Application\Analysis\StageSpec;
use App\Application\Library\VisibilityService;
use App\Infrastructure\Import\TextExtractor;
use App\Infrastructure\Pdo\AnalysisRepository;
use App\Infrastructure\Pdo\WorkRepository;
use App\Infrastructure\Storage\UploadStore;
use App\Support\Config;

$container = require dirname(__DIR__) . '/config/container.php';
set_time_limit(0);

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

/** @var Config $config */
$config = $container->get(Config::class);
/** @var WorkRepository $works */
$works = $container->get(WorkRepository::class);
/** @var AnalysisRepository $analyses */
$analyses = $container->get(AnalysisRepository::class);
/** @var PromptLibrary $prompts */
$prompts = $container->get(PromptLibrary::class);
/** @var UploadStore $store */
$store = $container->get(UploadStore::class);
/** @var TextExtractor $extractor */
$extractor = $container->get(TextExtractor::class);
/** @var PDO $pdo */
$pdo = $container->get(PDO::class);

/**
 * 这篇的作者算不算活跃。
 *
 * 口径与一键拆解一致：active / comment_only 都算活跃（有投稿或有评稿），
 * 人工结论（activity-override）覆盖机器判断。查不到就按不活跃处理 ——
 * 宁可不公开，也不要把一个判不清的作者的作品直接推到前台。
 */
function authorIsActive(PDO $pdo, int $workId, string $root): bool
{
    $stmt = $pdo->prepare('SELECT au.name FROM hq_work w LEFT JOIN hq_author au ON au.id = w.author_id WHERE w.id = :id');
    $stmt->execute([':id' => $workId]);
    $name = (string) $stmt->fetchColumn();
    if ($name === '') {
        return false;
    }

    $path   = $root . '/storage/cache/source/activity.json';
    $raw    = is_file($path) ? json_decode((string) file_get_contents($path), true) : [];
    $states = [];
    $nameByUid = [];
    foreach ($raw['authors'] ?? [] as $row) {
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

    return in_array($states[$name] ?? 'unknown', ['active', 'comment_only'], true);
}

if (!in_array($command, ['export', 'ingest', 'hide', 'publish'], true)) {
    fwrite(STDERR, "用法：\n  php -c php.ini bin/agent-analyze.php export --work=ID\n  php -c php.ini bin/agent-analyze.php ingest --work=ID [--dir=DIR] [--publish] [--hidden] [--dry]\n  php -c php.ini bin/agent-analyze.php hide --work=ID\n  php -c php.ini bin/agent-analyze.php publish --work=ID\n");
    exit(1);
}

$workId = (int) ($options['work'] ?? 0);
if ($workId <= 0) {
    fwrite(STDERR, "缺少 --work=ID\n");
    exit(1);
}

$work = $works->findById($workId);
if ($work === null) {
    fwrite(STDERR, "找不到作品 #{$workId}\n");
    exit(1);
}

$version = $works->latestVersion($workId);
if ($version === null) {
    fwrite(STDERR, "作品 #{$workId} 没有原文版本\n");
    exit(1);
}

// 与 Pipeline::loadText 同一套取文方式：换一条路取文，字数口径就会和闸门对不上
$file = $works->findFile((int) $version['file_id']);
if ($file === null) {
    fwrite(STDERR, "找不到原文文件记录\n");
    exit(1);
}
$path = $store->absolutePath((string) $file['storage_key']);
$ext  = strtolower((string) pathinfo((string) $file['orig_name'], PATHINFO_EXTENSION));
$text = trim($extractor->extract($path, $ext)['text']);
$chars = mb_strlen($text, 'UTF-8');

$taskDir = (string) ($options['dir'] ?? $config->string('app.root') . '/storage/agent-tasks/' . $workId);

// 已入库之后再决定公开与否：代拆是批量的，而「这篇该不该公开」由批次规则决定，
// 不该逼着每次 ingest 都先想清楚。两个独立动作，出错也不会互相污染。
if ($command === 'hide' || $command === 'publish') {
    /** @var PDO $pdo */
    $pdo  = $container->get(PDO::class);
    $stmt = $pdo->prepare('SELECT id FROM hq_analysis WHERE work_id = :w AND status IN (\'succeeded\', \'published\') ORDER BY id DESC LIMIT 1');
    $stmt->execute([':w' => $workId]);
    $aid = (int) $stmt->fetchColumn();

    if ($aid <= 0) {
        fwrite(STDERR, "作品 #{$workId} 没有可用的分析结果\n");
        exit(1);
    }

    $actorId = (int) ($options['actor'] ?? 1);

    if ($command === 'hide') {
        /** @var VisibilityService $visibility */
        $visibility = $container->get(VisibilityService::class);
        $visibility->hide(VisibilityService::WORK, [$workId], null, '代拆：本批不对外公开');
        fwrite(STDOUT, sprintf("《%s》#%d 已置为不公开\n", (string) $work['title'], $workId));
    } else {
        // 顺序不能反：先恢复可见，再上架。
        // 反过来会得到「status=published 但 visibility=hidden」这种既不显示、
        // 也不报错的中间态 —— 前台只看到 404，后台看状态又是已发布。
        /** @var VisibilityService $visibility */
        $visibility = $container->get(VisibilityService::class);
        $visibility->restore(VisibilityService::WORK, [$workId], null);

        // 上架只有一条路：状态 / 快照 / 审计 / 索引与缓存 / sitemap 全在 PublishService。
        // allow_status 覆盖到 partially_failed：原代码**没有状态门槛**，什么状态都往
        // publish 里送；这里收窄到「产物已落库」的三态，是补一道护栏而不是改流程 ——
        // 代拆跑出 partially_failed 后人工判过再上架，是既有用法。
        // mark_edited 显式 false：发布不是编辑（此处原先吃 snapshot 的默认 true）。
        $container->get(PublishService::class)->publish($aid, $workId, $actorId, [
            'note'         => '代拆提交并上架',
            'mark_edited'  => false,
            'allow_status' => PublishService::PUBLISHABLE_WITH_PARTIAL,
        ]);

        fwrite(STDOUT, sprintf("《%s》#%d 已上架（analysis #%d）\n", (string) $work['title'], $workId, $aid));
    }
    exit(0);
}

if ($command === 'export') {
    if (!is_dir($taskDir) && !@mkdir($taskDir, 0750, true) && !is_dir($taskDir)) {
        fwrite(STDERR, "无法创建目录：{$taskDir}\n");
        exit(1);
    }

    file_put_contents($taskDir . '/text.txt', $text);

    $stages = [];
    foreach (StageSpec::order() as $code) {
        $spec = StageSpec::fromConfig($config, $code);
        $stages[] = [
            'code'        => $code,
            'label'       => $spec->label,
            'max_tokens'  => $spec->maxTokens,
            'temperature' => $spec->temperature,
            // 已渲染好的阶段指令（含 {{TAXONOMY}} / {{RETIRED}} 的替换结果），
            // 外部模型拿到的就是 Pipeline 里那份，不是另抄一份
            'instruction' => $prompts->stage($code),
        ];
    }

    $task = [
        'work_id'        => $workId,
        'work_code'      => (string) $work['code'],
        'title'          => (string) $work['title'],
        'author'         => (string) ($work['author_name'] ?? ''),
        'issue_no'       => (int) ($work['issue_no'] ?? 0),
        'chars'          => $chars,
        'prompt_version' => $prompts->version(),
        'base'           => $prompts->base(),
        'stages'         => $stages,
    ];

    file_put_contents(
        $taskDir . '/task.json',
        json_encode($task, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
    );

    fwrite(STDOUT, sprintf(
        "已导出《%s》#%d（%d 字）→ %s\n  text.txt / task.json（6 个阶段指令已渲染）\n",
        (string) $work['title'],
        $workId,
        $chars,
        $taskDir
    ));
    exit(0);
}

// ------------------------------------------------------------------ ingest

$dry = isset($options['dry']);

/** @var array<string, array<string,mixed>> $raw 各阶段原始 JSON */
$raw = [];
$missing = [];
foreach (StageSpec::order() as $code) {
    $p = $taskDir . '/' . $code . '.json';
    if (!is_file($p)) {
        $missing[] = $code;
        continue;
    }
    $decoded = json_decode((string) file_get_contents($p), true);
    if (!is_array($decoded)) {
        fwrite(STDERR, "{$code}.json 不是合法 JSON（" . json_last_error_msg() . "）\n");
        exit(1);
    }
    // 允许两种写法：直接是 data，或包一层 {"stage":..,"data":..}
    if (isset($decoded['data']) && is_array($decoded['data']) && !isset($decoded['terms']) && !isset($decoded['beats'])) {
        $decoded = $decoded['data'];
    }
    $raw[$code] = $decoded;
}

if ($missing !== []) {
    fwrite(STDERR, sprintf("缺少阶段文件：%s（目录 %s）\n", implode('、', $missing), $taskDir));
    exit(1);
}

// 闸门按 Pipeline 的顺序逐个过，前面的结果要喂给后面的
$clean = [];
$softAll = [];
$failed = [];

$g1 = StageGate::g1($raw['S1_INDEX'], $text, false, false);
if (!$g1->passed) { $failed['S1_INDEX'] = $g1; } else { $clean['S1_INDEX'] = $g1->data; $softAll = array_merge($softAll, $g1->soft); }

$g2 = StageGate::g2($raw['S2_PLOT'], $text);
if (!$g2->passed) { $failed['S2_PLOT'] = $g2; } else { $clean['S2_PLOT'] = $g2->data; $softAll = array_merge($softAll, $g2->soft); }

$beatSeqs = array_map(
    static fn (array $b): int => (int) $b['seq'],
    $clean['S2_PLOT']['beats'] ?? ($raw['S2_PLOT']['beats'] ?? [])
);
$g3 = StageGate::g3($raw['S3_FORESHADOW'], $beatSeqs);
if (!$g3->passed) { $failed['S3_FORESHADOW'] = $g3; } else { $clean['S3_FORESHADOW'] = $g3->data; $softAll = array_merge($softAll, $g3->soft); }

$g4 = StageGate::g4($raw['S4_ADVICE'], false);
if (!$g4->passed) { $failed['S4_ADVICE'] = $g4; } else { $clean['S4_ADVICE'] = $g4->data; $softAll = array_merge($softAll, $g4->soft); }

$g5 = StageGate::g5($raw['S5_REVISION'], $clean['S4_ADVICE']['suggestions'] ?? [], false);
if (!$g5->passed) { $failed['S5_REVISION'] = $g5; } else { $clean['S5_REVISION'] = $g5->data; $softAll = array_merge($softAll, $g5->soft); }

$g7 = StageGate::g7($raw['S7_READER'], false);
if (!$g7->passed) { $failed['S7_READER'] = $g7; } else { $clean['S7_READER'] = $g7->data; $softAll = array_merge($softAll, $g7->soft); }

if ($failed !== []) {
    fwrite(STDERR, sprintf("《%s》#%d 未通过闸门，未入库：\n", (string) $work['title'], $workId));
    /** @var GateResult $gate */
    foreach ($failed as $code => $gate) {
        fwrite(STDERR, "  【{$code}】\n");
        foreach ($gate->hard as $line) {
            fwrite(STDERR, '    - ' . $line . "\n");
        }
    }
    exit(2);
}

if ($softAll !== []) {
    fwrite(STDOUT, sprintf("软告警 %d 条（不影响入库）：\n", count($softAll)));
    foreach (array_slice($softAll, 0, 20) as $line) {
        fwrite(STDOUT, '  · ' . $line . "\n");
    }
}

if ($dry) {
    fwrite(STDOUT, sprintf("《%s》#%d 六阶段全部通过闸门（--dry，未入库）\n", (string) $work['title'], $workId));
    exit(0);
}

// --------------------------------------------------------------- 落库

$model         = 'wb-v4.1-flash';
$promptVersion = $prompts->version();

$signatures = [];
foreach (StageSpec::order() as $code) {
    $signatures[] = StageSpec::fromConfig($config, $code)->signature();
}
// 与 Pipeline::inputHash 同构，但 model 不同 → hash 天然不同，
// 不会因为「同一篇正文」被 findReusable 误当成 API 拆过的那一份
$inputHash = hash('sha256', implode('|', [
    (string) $version['sha256'],
    $promptVersion,
    $model,
    $prompts->fingerprint(),
    implode(',', $signatures),
]));

$actorId = (int) ($options['actor'] ?? 1);

$analysis   = $analyses->create($workId, (int) $version['id'], $model, $promptVersion, $inputHash, $actorId);
$analysisId = (int) $analysis['id'];

$works->setStatus($workId, 'analyzing');
$analyses->setStatus($analysisId, 'running');

foreach (StageSpec::order() as $code) {
    $analyses->stageStart($analysisId, $code, $model, $promptVersion);
    $rawPath = $analyses->saveRaw($analysisId, $code, [
        'stage'  => $code,
        'model'  => $model,
        'prompt' => $promptVersion,
        'data'   => $clean[$code],
        'soft'   => $softAll,
    ]);
    // 外部代拆不产生 token 与费用，usage 记空：账单里这一篇就是 0 元，
    // 而不是「记了个假数再乘出个假成本」
    $analyses->stageFinish($analysisId, $code, 'succeeded', [], null, null, $rawPath);
}

$analyses->saveArtifacts(
    $analysisId,
    $workId,
    $clean['S1_INDEX'],
    $clean['S2_PLOT'],
    $clean['S3_FORESHADOW'],
    $clean['S4_ADVICE'],
    $clean['S5_REVISION'],
    $clean['S7_READER']
);

$analyses->setStatus($analysisId, 'succeeded');
$works->markAnalyzed($workId);

fwrite(STDOUT, sprintf("《%s》#%d 已入库（analysis #%d，0 元）\n", (string) $work['title'], $workId, $analysisId));

// 公开裁定：--auto 按作者活跃度自动决定，避免批量时每篇都要人工指定
$mode = '';
if (isset($options['hidden'])) {
    $mode = 'hidden';
} elseif (isset($options['publish'])) {
    $mode = 'publish';
} elseif (isset($options['auto'])) {
    $mode = authorIsActive($pdo, $workId, dirname(__DIR__)) ? 'publish' : 'hidden';
}

if ($mode === 'hidden') {
    /** @var VisibilityService $visibility */
    $visibility = $container->get(VisibilityService::class);
    $visibility->hide(VisibilityService::WORK, [$workId], null, '代拆：本批不对外公开');
    fwrite(STDOUT, "已置为不公开（只在后台保留拆解结果）\n");
} elseif ($mode === 'publish') {
    /** @var VisibilityService $visibility */
    $visibility = $container->get(VisibilityService::class);
    $visibility->restore(VisibilityService::WORK, [$workId], null);

    // 同上：五步都交给 PublishService，不要再在这里拼两行 publish + snapshot。
    // allow_status 的理由见上一处（原代码没有状态门槛）；mark_edited 也统一为 false。
    $container->get(PublishService::class)->publish($analysisId, $workId, $actorId, [
        'note'         => '代拆提交并上架',
        'mark_edited'  => false,
        'allow_status' => PublishService::PUBLISHABLE_WITH_PARTIAL,
    ]);

    fwrite(STDOUT, "已上架并建立快照\n");
}
