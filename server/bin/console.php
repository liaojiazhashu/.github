<?php

declare(strict_types=1);

/**
 * 控制台：建账号、跑迁移、清理过期数据。
 *
 * 用法：
 *   php -c php.ini bin/console.php user:create --username=admin
 *   php -c php.ini bin/console.php user:list
 *   php -c php.ini bin/console.php user:reset-password --username=admin
 *   php -c php.ini bin/console.php user:unlock --username=admin
 *   php -c php.ini bin/console.php user:status --username=admin --set=disabled
 *   php -c php.ini bin/console.php user:enable-totp --username=admin
 *   php -c php.ini bin/console.php session:purge
 *   php -c php.ini bin/console.php seed:rbac
 */

require dirname(__DIR__) . '/config/bootstrap.php';

use App\Application\Analysis\BatchDispatcher;
use App\Application\Analysis\PaceEstimate;
use App\Application\Analysis\PublishService;
use App\Application\Auth\AuthService;
use App\Application\Library\ActiveGate;
use App\Application\Library\IssueSchedule;
use App\Application\Library\PageCache;
use App\Application\Library\PublicSurface;
use App\Application\Library\PublicTextGuard;
use App\Application\Library\SearchService;
use App\Application\Library\SitemapBuilder;
use App\Application\Library\VisibilityService;
use App\Application\Pipeline\OneClick;
use App\Application\Source\ActiveCollect;
use App\Application\Source\AuthorActivity;
use App\Application\Source\IssueStats;
use App\Application\Source\SourceApi;
use App\Application\Source\SourceCatalog;
use App\Infrastructure\Pdo\AnalysisRepository;
use App\Infrastructure\Pdo\AuditLogger;
use App\Infrastructure\Pdo\JobRepository;
use App\Infrastructure\Pdo\LibraryRepository;
use App\Infrastructure\Pdo\PracticeStatRepository;
use App\Infrastructure\Pdo\RateLimiter;
use App\Infrastructure\Pdo\RunBatchRepository;
use App\Infrastructure\Pdo\SessionRepository;
use App\Infrastructure\Pdo\UserRepository;
use App\Infrastructure\Pdo\WorkRepository;
use App\Support\Config;
use App\Support\Crypto;
use App\Support\Db;
use App\Support\RequestContext;
use App\Support\Totp;

/** @var array<string, string> $options */
$options = [];
$command = null;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--')) {
        [$k, $v] = array_pad(explode('=', substr($arg, 2), 2), 2, '');
        $options[$k] = $v;
    } elseif ($command === null) {
        $command = $arg;
    }
}

$config    = new Config(require dirname(__DIR__) . '/config/settings.php');
$pdo       = Db::connect($config);
$crypto    = new Crypto($config);
$users     = new UserRepository($pdo);
$sessions  = new SessionRepository($pdo);
$limiter   = new RateLimiter($pdo);

$hashPassword = static fn (string $password): string => password_hash(
    $password,
    PASSWORD_ARGON2ID,
    // 与 AuthService::ARGON_OPTIONS 共用一份：分开写正是 p=2 那个坑的来源
    // （线上 libargon2 不支持多线程，password_hash 直接抛 ValueError）。
    AuthService::ARGON_OPTIONS
);

$roleIdOf = static function (string $code) use ($pdo): int {
    $stmt = $pdo->prepare('SELECT id FROM hq_role WHERE code = :code');
    $stmt->execute([':code' => $code]);
    $id = $stmt->fetchColumn();
    if ($id === false) {
        fwrite(STDERR, "角色 {$code} 不存在，请先执行 seed:rbac\n");
        exit(1);
    }

    return (int) $id;
};

/** 从终端读口令，不回显。 */
$prompt = static function (string $label): string {
    fwrite(STDOUT, $label);

    return trim((string) fgets(STDIN));
};

$requireOption = static function (string $key) use ($options): string {
    $value = $options[$key] ?? '';
    if ($value === '') {
        fwrite(STDERR, "缺少参数 --{$key}\n");
        exit(1);
    }

    return $value;
};

switch ($command) {
    case 'seed:rbac':
        $roles = [
            'admin'    => '管理员',
            'editor'   => '编辑',
            'reviewer' => '审校',
            'viewer'   => '只读',
        ];
        foreach ($roles as $code => $name) {
            $pdo->prepare('INSERT IGNORE INTO hq_role (code, name, created_at) VALUES (:c, :n, NOW())')
                ->execute([':c' => $code, ':n' => $name]);
        }

        $permissions = [
            ['work.read', '查看作品与原文'],
            ['work.write', '新建/编辑/导入作品'],
            ['work.delete', '删除作品（软删）'],
            ['analysis.run', '触发分析/重分析'],
            ['analysis.edit', '编辑四件产出'],
            ['analysis.publish', '发布/下线'],
            ['term.merge', '索引词归并'],
            ['system.user', '用户与角色'],
            ['system.setting', '系统设置'],
            ['audit.read', '审计日志'],
            ['visibility.toggle', '隐藏/恢复内容'],
        ];
        foreach ($permissions as [$code, $name]) {
            $pdo->prepare('INSERT IGNORE INTO hq_permission (code, name, created_at) VALUES (:c, :n, NOW())')
                ->execute([':c' => $code, ':n' => $name]);
        }

        // 与 App\Application\Auth\Permission::MATRIX 必须一致，bin/check-permissions.php 会比对
        $matrix = [
            'admin'    => array_column($permissions, 0),
            'editor'   => ['work.read', 'work.write', 'analysis.run', 'analysis.edit', 'term.merge', 'visibility.toggle'],
            'reviewer' => ['work.read', 'analysis.edit', 'analysis.publish', 'audit.read', 'visibility.toggle'],
            'viewer'   => ['work.read'],
        ];
        foreach ($matrix as $roleCode => $codes) {
            $roleId = $roleIdOf($roleCode);
            foreach ($codes as $code) {
                $pdo->prepare(
                    'INSERT IGNORE INTO hq_role_permission (role_id, permission_id)
                     SELECT :role, id FROM hq_permission WHERE code = :code'
                )->execute([':role' => $roleId, ':code' => $code]);
            }
        }

        $genres = [
            ['sf', '科幻', 10],
            ['science', '科学', 20],
            ['fantasy', '奇幻', 30],
            ['essay', '随笔', 40],
        ];
        foreach ($genres as [$code, $name, $sort]) {
            $pdo->prepare('INSERT IGNORE INTO hq_genre (code, name, sort, created_at) VALUES (:c, :n, :s, NOW())')
                ->execute([':c' => $code, ':n' => $name, ':s' => $sort]);
        }

        fwrite(STDOUT, "角色 / 权限 / 体裁已就绪。\n");
        break;

    case 'user:create':
        $username = $requireOption('username');
        $password = $options['password'] ?? $prompt('设置口令（≥12 位）：');
        $force    = isset($options['force']);

        // 默认 12 位；--force 允许按运营方指定的口令创建（比如交接时指定的那一串），
        // 但仍然拒绝 8 位以下 —— 那不是「按要求」，那是没有口令。
        $min = $force ? 8 : 12;
        if (mb_strlen($password) < $min) {
            fwrite(STDERR, "口令至少 {$min} 位。\n");
            exit(1);
        }
        if ($force && mb_strlen($password) < 12) {
            fwrite(STDOUT, "注意：强制创建，口令长度低于默认下限 12 位。建议首次登录后在「我的账号」里改掉。\n");
        }
        if ($users->findByUsername($username) !== null) {
            fwrite(STDERR, "用户名已存在。\n");
            exit(1);
        }

        $id = $users->create($username, $hashPassword($password), $roleIdOf($options['role'] ?? 'admin'));
        fwrite(STDOUT, "已创建用户 #{$id} {$username}（角色 " . ($options['role'] ?? 'admin') . "）\n");
        break;

    case 'user:list':
        $rows = $pdo->query(
            'SELECT u.id, u.username, r.code AS role, u.status, u.totp_enabled, u.last_login_at
               FROM hq_user u JOIN hq_role r ON r.id = u.role_id ORDER BY u.id'
        )->fetchAll();
        foreach ($rows as $row) {
            printf(
                "#%d\t%s\t%s\t%s\ttotp=%s\tlast=%s\n",
                $row['id'],
                $row['username'],
                $row['role'],
                $row['status'],
                $row['totp_enabled'] ? 'on' : 'off',
                $row['last_login_at'] ?? '-'
            );
        }
        break;

    case 'user:reset-password':
        $username = $requireOption('username');
        $user     = $users->findByUsername($username);
        if ($user === null) {
            fwrite(STDERR, "用户不存在。\n");
            exit(1);
        }
        $password = $options['password'] ?? $prompt('新口令（≥12 位）：');
        if (mb_strlen($password) < 12) {
            fwrite(STDERR, "口令至少 12 位。\n");
            exit(1);
        }
        $users->updatePassword((int) $user['id'], $hashPassword($password));
        $sessions->deleteByUser((int) $user['id']);
        fwrite(STDOUT, "已重置口令，该账号所有会话已失效。\n");
        break;

    /**
     * 解一次「已经过期、却还挂在表上」的锁定。
     *
     * 场景：连续输错口令把账号锁了 15 分钟，之后人走了、锁窗过了没人再登 ——
     * 账号就长期停在 status=locked + failed_attempts≥阈值。这时候哪怕只错一个口令，
     * 也会立刻再锁 15 分钟（计数从没归零）。这个命令把计数、locked_until、status 一起复位。
     * 登录链路现在也会在锁窗过后自动复位，此命令是给「不想等、也不能改 SQL」的运维准备的。
     */
    case 'user:unlock':
        $username = $requireOption('username');
        $user     = $users->findByUsername($username);
        if ($user === null) {
            fwrite(STDERR, "用户不存在。\n");
            exit(1);
        }
        $users->clearLock((int) $user['id']);
        fwrite(STDOUT, "已解除 {$username} 的锁定：失败计数归零、locked_until 清空。\n");
        if (($user['status'] ?? '') === 'disabled') {
            fwrite(STDOUT, "注意：该账号仍是 disabled，要登录得先 user:status --set=active。\n");
        }
        break;

    /**
     * 启用 / 停用账号。此前建得出账号却停不掉，只能改 SQL —— 这正是测试账号
     * 一直留在生产库里的原因。停用会连带踢掉该账号的全部会话，否则已经登录的
     * 浏览器还能继续用到会话过期为止。
     */
    case 'user:status':
        $username = $requireOption('username');
        $set      = $requireOption('set');
        if (!in_array($set, ['active', 'disabled'], true)) {
            fwrite(STDERR, "--set 只接受 active 或 disabled。\n");
            exit(1);
        }
        $user = $users->findByUsername($username);
        if ($user === null) {
            fwrite(STDERR, "用户不存在。\n");
            exit(1);
        }
        $pdo->prepare('UPDATE hq_user SET status = :s, updated_at = NOW() WHERE id = :id')
            ->execute([':s' => $set, ':id' => (int) $user['id']]);
        if ($set === 'disabled') {
            $sessions->deleteByUser((int) $user['id']);
        }
        fwrite(STDOUT, "{$username} 已置为 {$set}" . ($set === 'disabled' ? "，其会话已全部失效。\n" : "。\n"));
        break;

    case 'user:enable-totp':
        $username = $requireOption('username');
        $user     = $users->findByUsername($username);
        if ($user === null) {
            fwrite(STDERR, "用户不存在。\n");
            exit(1);
        }
        $secret = Totp::generateSecret();
        $pdo->prepare('UPDATE hq_user SET totp_secret_cipher = :s, totp_enabled = 1, updated_at = NOW() WHERE id = :id')
            ->execute([':s' => $crypto->seal($secret), ':id' => (int) $user['id']]);

        fwrite(STDOUT, "TOTP 已绑定。密钥（仅显示一次）：{$secret}\n");
        fwrite(STDOUT, "在验证器里选择「手动输入」，算法 TOTP / 6 位 / 30 秒。\n");
        break;

    case 'user:disable-totp':
        $username = $requireOption('username');
        $user     = $users->findByUsername($username);
        if ($user === null) {
            fwrite(STDERR, "用户不存在。\n");
            exit(1);
        }
        $pdo->prepare('UPDATE hq_user SET totp_secret_cipher = NULL, totp_enabled = 0, updated_at = NOW() WHERE id = :id')
            ->execute([':id' => (int) $user['id']]);
        fwrite(STDOUT, "TOTP 已解除。\n");
        break;

    case 'session:purge':
        $n = $sessions->purgeExpired();
        $m = $limiter->purgeExpired();
        fwrite(STDOUT, "清理会话 {$n} 条，限流记录 {$m} 条。\n");
        break;

    /**
     * 回收卡在 running 的分析。
     *
     * 进程被 kill（Ctrl+C、终端关掉、机器重启、部署时重启 php）时，分析记录会永远停在
     * running、作品永远停在 analyzing —— 而作品一旦是 analyzing，后台就**不让再发起分析**。
     * 也就是说一次中断会让这篇作品"卡死"。这个命令就是解卡用的：
     * 默认只报告，加 --apply 才改。判据是 started_at 超过 --minutes（默认 60）。
     */
    case 'analysis:reap':
        $minutes = max(1, (int) ($options['minutes'] ?? 60));
        $apply   = isset($options['apply']);

        $stmt = $pdo->prepare(
            'SELECT a.id, a.code, a.work_id, a.started_at, w.title
               FROM hq_analysis a JOIN hq_work w ON w.id = a.work_id
              WHERE a.status = \'running\'
                AND a.started_at IS NOT NULL
                AND a.started_at < DATE_SUB(NOW(), INTERVAL :mins MINUTE)
              ORDER BY a.id'
        );
        $stmt->execute([':mins' => $minutes]);
        $rows = $stmt->fetchAll();

        if ($rows === []) {
            fwrite(STDOUT, "没有超过 {$minutes} 分钟仍在 running 的分析。\n");
            break;
        }

        foreach ($rows as $row) {
            fwrite(STDOUT, sprintf("#%d %s 《%s》 始于 %s\n", $row['id'], $row['code'], $row['title'], $row['started_at']));
        }

        if (!$apply) {
            fwrite(STDOUT, sprintf("\n共 %d 条（未改动）。确认后加 --apply 才会标失败并解卡。\n", count($rows)));
            break;
        }

        $markAnalysis = $pdo->prepare(
            'UPDATE hq_analysis
                SET status = \'failed\', error_code = \'ANALYSIS_INTERRUPTED\',
                    error_msg = \'进程中断，未跑完（由 analysis:reap 判定）\', finished_at = NOW(), updated_at = NOW()
              WHERE id = :id AND status = \'running\''
        );
        // 有产物就留在 analyzed（产物还能看），没有就退回 imported（可重新发起）
        $backToAnalyzed = $pdo->prepare(
            'UPDATE hq_work SET status = \'analyzed\', updated_at = NOW() WHERE id = :id'
        );
        $backToImported = $pdo->prepare(
            'UPDATE hq_work SET status = \'imported\', updated_at = NOW() WHERE id = :id'
        );
        $hasStage = $pdo->prepare(
            'SELECT COUNT(*) FROM hq_analysis_stage WHERE analysis_id = :aid AND status = \'succeeded\''
        );

        foreach ($rows as $row) {
            $markAnalysis->execute([':id' => $row['id']]);
            $hasStage->execute([':aid' => $row['id']]);
            $done = (int) $hasStage->fetchColumn();

            if ($done > 0) {
                $backToAnalyzed->execute([':id' => $row['work_id']]);
            } else {
                $backToImported->execute([':id' => $row['work_id']]);
            }

            fwrite(STDOUT, sprintf("  已处理 #%d（成功阶段 %d 个 → 作品退回 %s）\n", $row['id'], $done, $done > 0 ? 'analyzed' : 'imported'));
        }
        fwrite(STDOUT, sprintf("共处理 %d 条。\n", count($rows)));
        break;

    /**
     * 批量拆解调度：大规模（几百上千篇）连续拆解的三个动作都在这里。
     *
     * 后台按钮一次只入队一篇，1300 篇点不动；daily 预算到顶时任务会被
     * failHard（重试无意义），第二天也不会自己活过来 —— 这两个缺口由本命令补上。
     *
     * 用法：
     *   php -c php.ini bin/console.php analysis:batch --stats            # 进度与成本总览
     *   php -c php.ini bin/console.php analysis:batch --enqueue [--limit=N] [--apply]
     *                                                                    # 把「已导入」的作品批量入队（默认预览）
     *   php -c php.ini bin/console.php analysis:batch --requeue-failed [--apply]
     *                                                                    # 失败任务（预算停机、闸门耗尽）重新入队
     *
     * 入队排除规则：已有 queued/running 的 analysis.run 任务的作品不再重复入队
     * （MySQL 5.6 没有 JSON_EXTRACT，用 payload 的固定键序做 LIKE 匹配，
     * work_id 后面恒跟逗号 —— 载荷由本系统自己写，格式是自知的）。
     */
    case 'analysis:batch':
        $jobs = new JobRepository($pdo);
        $apply = isset($options['apply']);

        // ── 进度总览：--stats（也是不带参数时的默认行为） ──
        if (isset($options['stats']) || (!isset($options['enqueue']) && !isset($options['requeue-failed']))) {
            foreach ($pdo->query('SELECT status, COUNT(*) n FROM hq_work GROUP BY status ORDER BY status')->fetchAll(PDO::FETCH_NUM) as [$s, $n]) {
                printf("作品   %-14s %d\n", $s, $n);
            }
            foreach ($pdo->query("SELECT status, COUNT(*) n FROM hq_job WHERE type = 'analysis.run' GROUP BY status ORDER BY status")->fetchAll(PDO::FETCH_NUM) as [$s, $n]) {
                printf("任务   %-14s %d\n", $s, $n);
            }
            $spent = (int) $pdo->query('SELECT COALESCE(SUM(cost_micro),0) FROM hq_ai_call WHERE created_at >= CURDATE()')->fetchColumn();
            $daily = (float) $config->get('ai.budget.daily_yuan', 100.0);
            printf("今日   %.2f / %.2f 元\n", $spent / 1e6, $daily);

            $agg = $pdo->query(
                "SELECT COUNT(*) n, COALESCE(SUM(cost_micro),0) c
                   FROM hq_analysis WHERE status IN ('succeeded','published')"
            )->fetch(PDO::FETCH_ASSOC);
            if ((int) $agg['n'] > 0) {
                $perWork = (float) $agg['c'] / (int) $agg['n'] / 1e6;
                printf("单篇   均价 %.3f 元 × %d 篇已完成\n", $perWork, (int) $agg['n']);
                $remaining = (int) $pdo->query("SELECT COUNT(*) FROM hq_work WHERE status = 'imported'")->fetchColumn();
                if ($remaining > 0) {
                    printf("预估   剩 %d 篇 ≈ %.2f 元 ≈ %.1f 小时（单 worker 串行，按 1.4 分钟/篇）\n", $remaining, $perWork * $remaining, $remaining * 1.4 / 60);
                }
            }
            break;
        }

        // ── 失败重入队：--requeue-failed ──
        if (isset($options['requeue-failed'])) {
            $failed = $pdo->query(
                "SELECT id, payload_json, last_error FROM hq_job
                  WHERE type = 'analysis.run' AND status = 'failed' ORDER BY id"
            )->fetchAll(PDO::FETCH_ASSOC);
            if ($failed === []) {
                fwrite(STDOUT, "没有失败的分析任务。\n");
                break;
            }
            foreach ($failed as $row) {
                $payload = json_decode((string) $row['payload_json'], true);
                $workId  = (int) ($payload['work_id'] ?? 0);
                $workCode = (string) ($payload['work_code'] ?? '?');
                printf("#%d 作品 %s（%s）：%s\n", $row['id'], $workCode, $workId > 0 ? "id={$workId}" : 'id?', mb_substr((string) $row['last_error'], 0, 60));
                if (!$apply) {
                    continue;
                }
                $newId = $jobs->enqueue('analysis.run', $payload, 8);
                fwrite(STDOUT, "  → 已重新入队为新任务 #{$newId}\n");
            }
            fwrite(STDOUT, $apply ? "完成。\n" : sprintf("共 %d 条（预览）。加 --apply 执行。\n", count($failed)));
            break;
        }

        // ── 批量入队：--enqueue ──
        $limit = max(1, (int) ($options['limit'] ?? 50));
        $rows = $pdo->query(
            "SELECT w.id, w.code, w.title
               FROM hq_work w
              WHERE w.status = 'imported' AND w.deleted_at IS NULL
                AND NOT EXISTS (
                    SELECT 1 FROM hq_job j
                     WHERE j.type = 'analysis.run'
                       AND j.status IN ('queued', 'running')
                       AND j.payload_json LIKE CONCAT('%\"work_id\":', w.id, ',%')
                )
              ORDER BY w.id
              LIMIT {$limit}"
        )->fetchAll(PDO::FETCH_ASSOC);

        if ($rows === []) {
            fwrite(STDOUT, "没有待入队的作品（都已入队或不在「已导入」状态）。\n");
            break;
        }

        foreach ($rows as $row) {
            printf("待入队 #%d %s 《%s》\n", $row['id'], $row['code'], $row['title']);
            if (!$apply) {
                continue;
            }
            $jobs->enqueue('analysis.run', [
                'work_id'    => (int) $row['id'],
                'work_code'  => (string) $row['code'],
                'force'      => false,
                'created_by' => null,
            ], 8);
        }
        fwrite(STDOUT, $apply
            ? sprintf("已入队 %d 篇。启动 worker：php -c php.ini bin\\worker.php\n", count($rows))
            : sprintf("共 %d 篇（预览）。加 --apply 执行。\n", count($rows)));
        break;

    /**
     * 发布 / 下线（M5：把已完成的拆解挂到公开馆藏上）。
     *
     * 走的是与后台按钮完全相同的入口（PublishService）：状态变更 / 快照 / 审计 /
     * 检索索引与页面缓存 / sitemap 五步一步不少。命令行的意义只在「批量」：
     * 回归跑完 20 篇、要一次性上架时，点 20 次按钮没有意义。
     *
     * 这里**手工装配**——本命令不走容器（见文件顶部那段 new 装配），
     * 所以 PublishService 与它依赖的 PublicSurface 也要按同样的方式手工构造。
     *
     * 用法：
     *   php -c php.ini bin/console.php library:publish --work=01M61...
     *   php -c php.ini bin/console.php library:publish --all [--apply]
     *   php -c php.ini bin/console.php library:unpublish --work=01M61...
     */
    case 'library:publish':
    case 'library:unpublish':
        $works     = new WorkRepository($pdo);
        $analyses  = new AnalysisRepository($pdo, $config->string('ai.artifact_dir', $config->string('app.root') . '/storage/artifacts'));
        $library   = new LibraryRepository($pdo);
        $search    = new SearchService($library, PublicTextGuard::fromDatabase($pdo));
        $cache     = new PageCache($config->string('library.cache_dir'));
        $audit     = new AuditLogger($pdo, new RequestContext($crypto));
        $surface   = new PublicSurface($cache, $library, $config, $search);
        // 发布 / 下线的唯一入口。命令行没有登录态，操作者由 --actor 指定（默认 1 = 管理员）
        $publisher  = new PublishService($analyses, $audit, $surface);
        $actorId    = (int) ($options['actor'] ?? 1);
        $apply      = isset($options['apply']);
        $publishing = $command === 'library:publish';

        $targets = [];

        $code = $options['work'] ?? '';
        if ($code !== '') {
            $work = $works->findByCode($code);
            if ($work === null) {
                fwrite(STDERR, "没有这篇作品：{$code}\n");
                exit(1);
            }
            $targets[] = $work;
        } elseif (isset($options['all'])) {
            // 发布：只挑「已分析但还没发布」的；下线：只挑「已发布」的
            $sql = $publishing
                ? 'SELECT w.* FROM hq_work w
                    WHERE w.deleted_at IS NULL AND w.status = \'analyzed\'
                    ORDER BY w.id'
                : 'SELECT w.* FROM hq_work w
                    WHERE w.deleted_at IS NULL AND w.status = \'published\'
                    ORDER BY w.id';
            $targets = $pdo->query($sql)->fetchAll();
        } else {
            fwrite(STDERR, "需要 --work=CODE 或 --all\n");
            exit(1);
        }

        if ($targets === []) {
            fwrite(STDOUT, "没有需要处理的作品。\n");
            break;
        }

        // 先只做「选谁、跳谁」的判断，把真正要动的收进队列：
        // 一批动完再统一重写 sitemap（publishMany 内部就是这么做的），
        // 逐条重写等于把一份几百个 URL 的 XML 写几百遍。
        /** @var list<array{analysis_id:int, work_id:int, title:string}> $queue */
        $queue = [];

        foreach ($targets as $work) {
            $workId = (int) $work['id'];
            $latest = $analyses->latestForWork($workId);

            if ($latest === null) {
                fwrite(STDOUT, sprintf("· 《%s》 没有分析记录，跳过\n", $work['title']));
                continue;
            }

            if ($publishing) {
                $status = (string) $latest['status'];

                // 已发布但作品状态没跟上（历史上 runWork 会把 published 回落成 analyzed）：
                // 这种情况要修，不能跳过 —— 否则前台列表里永远少这一篇
                $drifted = $status === 'published' && (string) $work['status'] !== 'published';

                if ($status === 'published' && !$drifted) {
                    fwrite(STDOUT, sprintf("· 《%s》 已是发布状态，跳过\n", $work['title']));
                    continue;
                }
                if (!$drifted && !in_array($status, ['succeeded', 'partially_failed'], true)) {
                    fwrite(STDOUT, sprintf("· 《%s》 最近一次分析是 %s，跳过\n", $work['title'], $status));
                    continue;
                }
                if (!$apply) {
                    fwrite(STDOUT, sprintf(
                        "· 《%s》 将%s分析 #%d（加 --apply 才执行）\n",
                        $work['title'],
                        $drifted ? '修复发布状态：' : '发布',
                        $latest['id']
                    ));
                    continue;
                }
            } else {
                if ((string) $latest['status'] !== 'published') {
                    fwrite(STDOUT, sprintf("· 《%s》 当前没有已发布的分析，跳过\n", $work['title']));
                    continue;
                }
                if (!$apply) {
                    fwrite(STDOUT, sprintf("· 《%s》 将下线分析 #%d（加 --apply 才执行）\n", $work['title'], $latest['id']));
                    continue;
                }
            }

            $queue[] = [
                'analysis_id' => (int) $latest['id'],
                'work_id'     => $workId,
                'title'       => (string) $work['title'],
            ];
        }

        if ($queue === []) {
            fwrite(STDOUT, "没有需要执行的作品。\n");
            break;
        }

        // partially_failed 也放行：产物不全但已落库，历史上一直允许上线。
        //
        // mark_edited **必须是 false**：这条路径以前根本不拍快照，也就从没执行过
        // `UPDATE hq_analysis SET source='mixed'`。现在补上快照后若沿用 true，会把
        // `--all` 跑过的每一篇（最多 1111 篇）的来源永久标成 mixed，而
        // analysis.twig 把 mixed 渲染成「模型生成 + 人工调整」—— 等于凭空给全库
        // 记上一笔没发生过的编辑。发布不是编辑，这里传 false。
        $result = $publishing
            ? $publisher->publishMany($queue, $actorId, [
                'allow_status' => PublishService::PUBLISHABLE_WITH_PARTIAL,
                'note'         => '控制台批量上架',
                'mark_edited'  => false,
            ])
            : $publisher->unpublishMany($queue, $actorId);

        /** @var array<int, string> $errors 分析 id → 失败原因 */
        $errors = [];
        foreach ($result['failures'] as $failure) {
            $errors[(int) $failure['analysis_id']] = (string) $failure['error'];
        }

        foreach ($queue as $item) {
            $analysisId = (int) $item['analysis_id'];

            if (isset($errors[$analysisId])) {
                fwrite(STDERR, sprintf(
                    "· 《%s》 %s失败：%s\n",
                    $item['title'],
                    $publishing ? '发布' : '下线',
                    $errors[$analysisId]
                ));
                continue;
            }

            fwrite(STDOUT, sprintf("· 《%s》 %s\n", $item['title'], $publishing ? '已发布' : '已下线'));
        }

        $ok      = $publishing ? (int) $result['published'] : (int) $result['unpublished'];
        $failed  = (int) $result['failed'];

        fwrite(STDOUT, sprintf(
            "共处理 %d 篇%s。sitemap 已按最终状态重写一次。\n",
            $ok,
            $failed > 0 ? sprintf('，%d 篇失败（见上方逐条）', $failed) : ''
        ));
        break;

    /**
     * 同步官网站内文章目录（后台「站内文章」的数据来源）。
     *
     * 全站 1800 多篇、每页上限 50 条，要翻 37 页、二十秒左右，所以不在
     * 页面请求里跑，做成命令让运维自己排计划任务。后台也有一个手动同步按钮，
     * 那是给人临时用的，不是常态。
     *
     * 中途失败**不落盘**：半份目录比没有目录更危险 —— 页面会把「练笔 800 篇」
     * 当真，而实际 1375 篇。
     */
    case 'source:sync':
        $catalog = new SourceCatalog(
            new SourceApi(
                $config->string('source.base'),
                $config->string('source.key'),
                $config->string('source.ca', '')
            ),
            $config->string('source.cache_dir')
        );

        $result = $catalog->sync(static function (int $page, int $n): void {
            fwrite(STDOUT, sprintf("  第 %d 页，累计 %d 篇\n", $page, $n));
        });

        fwrite(STDOUT, sprintf(
            "已同步 %d 篇（API 报告 %d 篇，翻了 %d 页）。\n",
            $result['fetched'],
            $result['total'],
            $result['pages']
        ));
        break;

    /**
     * 导出练笔清单（CSV）。
     *
     *   php -c php.ini bin/console.php source:export
     *   php -c php.ini bin/console.php source:export --scope=practice --issue=22
     *   php -c php.ini bin/console.php source:export --out=D:/清单.csv
     *
     * 默认落 storage/artifacts/，文件名带日期 —— 拆解排期要的是一份能拿去用的文件，
     * 不是屏幕上的表格。`--out=-` 直接写 STDOUT，方便管道给别的工具。
     */
    case 'source:export':
        $catalog = new SourceCatalog(
            new SourceApi(
                $config->string('source.base'),
                $config->string('source.key'),
                $config->string('source.ca', '')
            ),
            $config->string('source.cache_dir')
        );

        $cache = $catalog->load();
        if ($cache === null) {
            fwrite(STDERR, "还没有目录缓存。先跑 source:sync。\n");
            exit(1);
        }

        $scope = (string) ($options['scope'] ?? 'practice_ready');
        if (!in_array($scope, ['practice_ready', 'practice', 'all'], true)) {
            fwrite(STDERR, "scope 只能是 practice_ready / practice / all。\n");
            exit(1);
        }
        $issue = (int) ($options['issue'] ?? 0);
        $q     = (string) ($options['q'] ?? '');

        $items = $catalog->filter($cache['items'], $scope);
        $items = $catalog->filterIssue($items, $issue);
        $items = $catalog->search($items, $q);

        $csv = $catalog->toCsv($items);

        $out = (string) ($options['out'] ?? '');
        if ($out === '-') {
            fwrite(STDOUT, $csv);
            break;
        }

        if ($out === '') {
            $dir = $config->string('library.artifact_dir', dirname(__DIR__) . '/storage/artifacts');
            if (!is_dir($dir) && !@mkdir($dir, 0o750, true) && !is_dir($dir)) {
                fwrite(STDERR, "导出目录不可写：$dir\n");
                exit(1);
            }
            // 文件名用中文口径，不用 practice_ready 这种内部代号 ——
            // 这份文件是要发给别人排期用的，文件名得先能看懂。
            $scopeLabel = ['practice_ready' => '练笔可用', 'practice' => '练笔全部', 'all' => '全站'][$scope];
            $out = $dir . '/荒启科幻练笔-' . ($issue > 0 ? '第' . $issue . '期' : $scopeLabel) . '-' . date('Ymd-Hi') . '.csv';
        }

        if (@file_put_contents($out, $csv) === false) {
            fwrite(STDERR, "写入失败：$out\n");
            exit(1);
        }

        fwrite(STDOUT, sprintf(
            "已导出 %d 篇（%s%s）→ %s\n",
            count($items),
            $scope,
            $issue > 0 ? '，第 ' . $issue . ' 期' : '',
            $out
        ));
        break;

    /**
     * 用官网的 26 个练笔活动校正期次表。
     *
     *   php -c php.ini bin/console.php source:issue-sync           # 预览
     *   php -c php.ini bin/console.php source:issue-sync --apply   # 落库
     *
     * 与 `library:issue-dates` 的分工：
     *   · issue-dates 按 IssueSchedule 的日历规则**推算**，没有官网数据时用它；
     *   · 本命令拿官网活动里作者填的 deadline，是**实然**，一旦有就以它为准。
     *
     * 两者会打架，而且第一次跑就能看出问题：官网第 13 期 2025-06-01 截稿，
     * 日历推算给的是 2025-07-01 —— 26 期里有 13 期差一个月。
     * 所以 `library:issue-dates` 现在会跳过「已对准官网」的期次（source_ends_at 非空），
     * 否则运维跑一次日历命令，就把刚同步好的真日期又推回错的。
     */
    case 'source:issue-sync':
        $apply = isset($options['apply']);

        $catalog = new SourceCatalog(
            new SourceApi(
                $config->string('source.base'),
                $config->string('source.key'),
                $config->string('source.ca', '')
            ),
            $config->string('source.cache_dir')
        );

        $cache = $catalog->load();
        if ($cache === null) {
            fwrite(STDERR, "还没有目录缓存。先跑 source:sync。\n");
            exit(1);
        }

        $issues = $catalog->issueStats($catalog->filter($cache['items'], 'practice'));
        if ($issues === []) {
            fwrite(STDERR, "目录里没有解析出任何练笔期次。\n");
            exit(1);
        }

        $cacheStore = new PageCache($config->string('library.cache_dir'));
        $now        = time();
        $prevEnd    = null;
        $inserted   = 0;
        $updated    = 0;
        $changed    = 0;

        $find = $pdo->prepare('SELECT id, starts_at, ends_at, source_ends_at FROM hq_issue WHERE no = :no');
        $insert = $pdo->prepare(
            'INSERT INTO hq_issue (no, label, starts_at, ends_at, status, source_mid, source_ends_at, source_reveal_at, created_at, updated_at)
             VALUES (:no, :label, :s, :e, :status, :mid, :se, :sr, NOW(), NOW())'
        );
        $update = $pdo->prepare(
            'UPDATE hq_issue SET label = :label, starts_at = :s, ends_at = :e, status = :status,
                    source_mid = :mid, source_ends_at = :se, source_reveal_at = :sr, updated_at = NOW()
             WHERE id = :id'
        );

        foreach ($issues as $row) {
            $no = (int) $row['no'];

            // 起点：第 1 期固定从 FIRST_YEAR 年 1 月 1 日 07:00 开始，其余接上一期截止
            $startTs = $prevEnd ?? (new DateTimeImmutable(sprintf('%d-01-01 %02d:00:00', IssueSchedule::FIRST_YEAR, IssueSchedule::CUTOFF_HOUR)))->getTimestamp();
            $endTs   = (int) $row['endsAt'];

            if ($endTs <= 0) {
                fwrite(STDOUT, sprintf("· 第 %d 期 没有官网截稿时间，跳过\n", $no));
                continue;
            }

            $prevEnd = $endTs;

            $starts = date('Y-m-d H:i:s', $startTs);
            $ends   = date('Y-m-d H:i:s', $endTs);
            $reveal = (int) $row['revealAt'] > 0 ? date('Y-m-d H:i:s', (int) $row['revealAt']) : null;

            $status = match (true) {
                $startTs > $now => 'planned',
                $endTs <= $now  => 'closed',
                default         => 'active',
            };

            $have = $find->execute(['no' => $no]) ? $find->fetch() : false;

            // 与日历推算的差异：只提示，不改日历规则（规则是业务定的，这里有实然数据）
            $sched = IssueSchedule::period($no);
            $drift = ($endTs - $sched['end']->getTimestamp()) / 86400;
            $warn  = abs($drift) >= 1 ? sprintf('  ⚠ 日历推算 %s，差 %+.0f 天', $sched['end']->format('Y-m-d H:i'), $drift) : '';

            if ($have === false) {
                ++$inserted;
                fwrite(STDOUT, sprintf(
                    '+ 第 %2d 期  %s → %s  mid=%d  %s%s%s',
                    $no, $starts, $ends, (int) $row['mid'], $status, $warn,
                    $apply ? "　已插入\n" : "　（加 --apply 才执行）\n"
                ));
                if ($apply) {
                    $insert->execute([
                        'no' => $no, 'label' => '第 ' . $no . ' 期', 's' => $starts, 'e' => $ends,
                        'status' => $status, 'mid' => (int) $row['mid'], 'se' => $ends, 'sr' => $reveal,
                    ]);
                }
                continue;
            }

            $same = ((string) $have['starts_at'] === $starts)
                && ((string) $have['ends_at'] === $ends)
                && ((int) $have['source_ends_at'] === $endTs);
            if ($same) {
                fwrite(STDOUT, sprintf("· 第 %2d 期  已是 %s → %s，跳过%s\n", $no, $starts, $ends, $warn));
                continue;
            }

            ++$updated;
            ++$changed;
            fwrite(STDOUT, sprintf(
                '~ 第 %2d 期  %s → %s　（原 %s → %s）mid=%d%s%s',
                $no, $starts, $ends, $have['starts_at'], $have['ends_at'], (int) $row['mid'], $warn,
                $apply ? "　已更新\n" : "　（加 --apply 才执行）\n"
            ));

            if ($apply) {
                $update->execute([
                    'label' => '第 ' . $no . ' 期', 's' => $starts, 'e' => $ends, 'status' => $status,
                    'mid' => (int) $row['mid'], 'se' => $ends, 'sr' => $reveal, 'id' => (int) $have['id'],
                ]);
                $cacheStore->invalidateTags(['issue:' . $no]);
            }
        }

        fwrite(STDOUT, sprintf(
            "\n%d 期：新增 %d，更新 %d（实际变动 %d）。%s\n",
            count($issues),
            $inserted,
            $updated,
            $changed,
            $apply ? '已写库。' : '这是预览，加 --apply 才落库。'
        ));
        break;

    /**
     * 按「时间窗内站内有没有产出」给作者和作品分类。
     *
     *   php -c php.ini bin/console.php source:activity            # 默认两个月
     *   php -c php.ini bin/console.php source:activity --refresh  # 忽略缓存重查
     *   php -c php.ini bin/console.php source:activity --since=2025-11-01
     *   php -c php.ini bin/console.php source:activity --limit=5  # 只查前 5 人（冒烟）
     *
     * 判据：时间窗内**投过稿**（站内任意栏目，不限于练笔）或**评过稿**→ 活跃；
     * 两样都没有 → 僵尸。评论单独记一份，只用来识别「只评论不评稿」的人，
     * 不参与主分类（口径见 AuthorActivity 类注释）。
     *
     * 窗口长度取 AuthorActivity::WINDOW_MONTHS，与后台分类页、公开区闸门同一个
     * 数字 —— 这里的结论会直接决定公开区保留哪些作品，两边不能各算各的。
     *
     * 一个人一次请求，两百多人要跑几分钟，所以做成命令；中途失败记 unknown
     * 而不是当成「没评过稿」—— 那会把活跃作者静默地判成僵尸。
     */
    case 'source:activity':
        $catalog = new SourceCatalog(
            new SourceApi(
                $config->string('source.base'),
                $config->string('source.key'),
                $config->string('source.ca', '')
            ),
            $config->string('source.cache_dir')
        );

        $cache = $catalog->load();
        if ($cache === null) {
            fwrite(STDERR, "还没有目录缓存。先跑 source:sync。\n");
            exit(1);
        }

        $since = (string) ($options['since'] ?? '');
        $sinceTs = $since !== ''
            ? (strtotime($since . ' 00:00:00') ?: 0)
            : AuthorActivity::since();
        if ($sinceTs <= 0) {
            fwrite(STDERR, "--since 看不懂：$since（要 YYYY-MM-DD）\n");
            exit(1);
        }

        $activity = new AuthorActivity(
            new SourceApi(
                $config->string('source.base'),
                $config->string('source.key'),
                $config->string('source.ca', '')
            ),
            $config->string('source.cache_dir')
        );

        // 分类对象：练笔且可用（公开可读）。匿名在下面单列，仍参与统计
        $works = $catalog->filter($cache['items'], 'practice_ready');

        $limit = (int) ($options['limit'] ?? 0);
        $probeComments = !isset($options['no-comments']);

        fwrite(STDOUT, sprintf(
            "数据截至 %s · 全站 %d 篇 · 练笔可用 %d 篇\n判定阈值：%s 之后（%s）\n\n",
            date('Y-m-d H:i', (int) ($cache['meta']['fetched_at'] ?? 0)),
            count($cache['items']),
            count($works),
            date('Y-m-d H:i', $sinceTs),
            $since !== '' ? '手工指定' : '今天往前' . AuthorActivity::WINDOW_LABEL
        ));

        // 冒烟：只查前 N 人，避免第一次就把五分钟花在一个可能的 bug 上。
        // 必须跳过匿名与已注销 —— 他们不问接口，抽到就白占名额。
        if ($limit > 0) {
            $keep = [];
            foreach ($works as $w) {
                $name = (string) ($w['authorName'] ?? '');
                if (in_array($name, AuthorActivity::ANON_NAMES, true)
                    || in_array($name, AuthorActivity::GONE_NAMES, true)) {
                    continue;
                }
                $keep[(int) $w['authorUid']] = true;
                if (count($keep) >= $limit) {
                    break;
                }
            }

            $works = array_values(array_filter(
                $works,
                static fn (array $w): bool => isset($keep[(int) $w['authorUid']])
            ));
            $probeItems = array_values(array_filter(
                $cache['items'],
                static fn (array $i): bool => isset($keep[(int) $i['authorUid']])
            ));

            fwrite(STDOUT, sprintf("冒烟模式：只查 %d 位作者、%d 篇作品。\n\n", count($keep), count($works)));
        } else {
            $probeItems = $cache['items'];
        }

        $t0 = microtime(true);
        $payload = $activity->build(
            $probeItems,
            $sinceTs,
            $probeComments,
            isset($options['refresh']),
            static function (int $done, int $total, string $name): void {
                // 每 10 人一行：252 人全打会刷屏，一行不打又像卡住
                if ($done % 10 === 0 || $done === $total) {
                    fwrite(STDOUT, sprintf("  %3d/%d  %s\n", $done, $total, $name));
                }
            }
        );

        $authors = $payload['authors'];
        $tagged  = $activity->tagItems($works, $authors);
        $summary = $activity->summarize($tagged);

        fwrite(STDOUT, sprintf("\n作者 %d 位，用时 %.0f 秒。\n\n", count($authors), microtime(true) - $t0));

        fwrite(STDOUT, sprintf("%-6s %-6s %-8s %s\n", '作者', '篇数', '字数', '分类'));
        fwrite(STDOUT, str_repeat('-', 46) . "\n");
        foreach ($summary as $state => $s) {
            if ($s['works'] === 0 && $state !== AuthorActivity::ACTIVE && $state !== AuthorActivity::ZOMBIE) {
                continue;
            }
            fwrite(STDOUT, sprintf(
                "%-6d %-6d %-8s %s\n",
                $s['authors'],
                $s['works'],
                number_format($s['words']),
                AuthorActivity::LABELS[$state] ?? $state
            ));
        }

        $bad = $summary[AuthorActivity::UNKNOWN] ?? ['authors' => 0];
        if ($bad['authors'] > 0) {
            fwrite(STDOUT, sprintf(
                "\n⚠ %d 位作者这次没查到（接口失败），已留成「未查到」而不是判成僵尸。\n"
                . "  再跑一次 source:activity 即可补上（缓存里已成功的不会重查）。\n",
                $bad['authors']
            ));
        }

        $zombie = array_values(array_filter($authors, static fn (array $a): bool => $a['state'] === AuthorActivity::ZOMBIE));
        if ($zombie !== []) {
            fwrite(STDOUT, sprintf("\n僵尸作者 %d 位（按站内投稿数排序，前 15）：\n", count($zombie)));
            usort($zombie, static fn (array $a, array $b): int => $b['posts'] <=> $a['posts']);
            foreach (array_slice($zombie, 0, 15) as $z) {
                fwrite(STDOUT, sprintf(
                    "  %-14s uid=%-6d 投稿 %2d 篇  最近 %s  评稿 %d 条\n",
                    $z['name'],
                    $z['uid'],
                    $z['posts'],
                    $z['latest_post_at'] > 0 ? date('Y-m-d', (int) $z['latest_post_at']) : '—',
                    $z['review_total']
                ));
            }
        }

        $dir = $config->string('library.artifact_dir', dirname(__DIR__) . '/storage/artifacts');
        if (!is_dir($dir) && !@mkdir($dir, 0o750, true) && !is_dir($dir)) {
            fwrite(STDERR, "导出目录不可写：$dir\n");
            exit(1);
        }

        $stamp   = date('Ymd-Hi');
        $worksFile   = $dir . '/活跃与僵尸-作品清单-' . $stamp . '.csv';
        $authorsFile = $dir . '/活跃与僵尸-作者清单-' . $stamp . '.csv';

        if (@file_put_contents($worksFile, $activity->worksCsv($tagged)) === false
            || @file_put_contents($authorsFile, $activity->authorsCsv($tagged)) === false) {
            fwrite(STDERR, "写入失败：$dir\n");
            exit(1);
        }

        fwrite(STDOUT, "\n→ $worksFile\n→ $authorsFile\n");

        if (($summary[AuthorActivity::UNKNOWN]['authors'] ?? 0) > 0) {
            exit(1);
        }
        break;

    /**
     * 人工指定某位作者的分类（不改机器判定，只在结果上盖一层）。
     *
     *   php -c php.ini bin/console.php source:activity-override --list
     *   php -c php.ini bin/console.php source:activity-override --set=鸑鷟=active
     *   php -c php.ini bin/console.php source:activity-override --set=1946=zombie
     *   php -c php.ini bin/console.php source:activity-override --remove=鸑鷟
     *
     * 为什么要单独一条命令：`source:activity` 每次整份重写快照，手改 activity.json
     * 会在下一次重查时无声消失。人工结论存 activity-override.json，重查后照样生效，
     * 并在快照里留 state_auto 记下机器原本判的是什么。
     *
     * 只认 active / zombie。改完立刻对 `pipeline:oneclick --author=…` 生效，不用重跑分类。
     */
    case 'source:activity-override':
        $activity = new AuthorActivity(
            new SourceApi(
                $config->string('source.base'),
                $config->string('source.key'),
                $config->string('source.ca', '')
            ),
            $config->string('source.cache_dir')
        );

        $snapshot = $activity->load();
        $authors  = $snapshot['authors'] ?? [];
        $byName   = [];
        $byUid    = [];
        foreach ($authors as $row) {
            $byName[trim((string) ($row['name'] ?? ''))] = $row;
            $byUid[(int) ($row['uid'] ?? 0)]             = $row;
        }

        // 名字或 uid 都能定位：命令行里打中文名最直观，脚本里用 uid 最稳
        $resolve = static function (string $key) use ($byName, $byUid): ?array {
            $key = trim($key);
            if ($key === '') {
                return null;
            }
            if (ctype_digit($key)) {
                return $byUid[(int) $key] ?? null;
            }

            return $byName[$key] ?? null;
        };

        if (isset($options['set'])) {
            [$who, $state] = array_pad(explode('=', (string) $options['set'], 2), 2, '');
            $state = trim($state);
            if (!in_array($state, [AuthorActivity::ACTIVE, AuthorActivity::ZOMBIE], true)) {
                fwrite(STDERR, "--set 的写法是「作者=active」或「作者=zombie」，只认这两个值。\n");
                exit(1);
            }

            $row = $resolve($who);
            if ($row === null) {
                fwrite(STDERR, "找不到作者：$who（先跑 source:activity 建快照，名字要一字不差）\n");
                exit(1);
            }

            $activity->setOverride((int) $row['uid'], $state, 'command line');
            fwrite(STDOUT, sprintf(
                "已指定：%s（uid=%d）→ %s。机器原本判的是 %s。\n",
                (string) $row['name'],
                (int) $row['uid'],
                AuthorActivity::LABELS[$state] ?? $state,
                AuthorActivity::LABELS[(string) ($row['state'] ?? '')] ?? (string) ($row['state'] ?? '')
            ));
            break;
        }

        if (isset($options['remove'])) {
            $row = $resolve((string) $options['remove']);
            if ($row === null) {
                fwrite(STDERR, "找不到作者：{$options['remove']}\n");
                exit(1);
            }
            $ok = $activity->clearOverride((int) $row['uid']);
            fwrite(STDOUT, $ok
                ? sprintf("已取消人工指定：%s（uid=%d），回到机器判定。\n", (string) $row['name'], (int) $row['uid'])
                : sprintf("%s（uid=%d）本来就没有人工指定。\n", (string) $row['name'], (int) $row['uid']));
            break;
        }

        // 默认（含 --list）：把人工指定摊开给人看
        $overrides = $activity->overrides();
        if ($overrides === []) {
            fwrite(STDOUT, "还没有人工指定。全部按机器判定。\n");
            break;
        }

        fwrite(STDOUT, sprintf("人工指定 %d 位（重跑 source:activity 不会冲掉）：\n\n", count($overrides)));
        foreach ($overrides as $uid => $state) {
            $row = $byUid[$uid] ?? null;
            fwrite(STDOUT, sprintf(
                "  %-14s uid=%-6d → %s（机器判：%s）\n",
                (string) ($row['name'] ?? '（快照里没有这个人）'),
                $uid,
                AuthorActivity::LABELS[$state] ?? $state,
                AuthorActivity::LABELS[(string) ($row['state'] ?? 'unknown')] ?? '—'
            ));
        }
        break;

    /**
     * 练笔统计：按「每一期」与「总计」两个维度重算一次。
     *
     *   php -c php.ini bin/console.php source:stats                      # 只打印，不落库
     *   php -c php.ini bin/console.php source:stats --apply              # 落库（官网页面读它）
     *   php -c php.ini bin/console.php source:stats --no-reviews         # 不数评稿，快跑一次
     *   php -c php.ini bin/console.php source:stats --apply --json=/path/to/stats.json --csv=/path/to/stats.csv
     *
     * 计划任务：每月 16 日 18:00（见 /etc/cron.d/hqlib-practice）。
     *
     * 为什么默认不落库：这个命令会被人在排查时反复跑。每一次都写一轮快照，
     * 「上一轮什么时候算的」就失去意义了 —— 那本该是「每月 16 日 18:00」这件事的证据。
     * 所以落库要显式加 `--apply`，定时任务里才写。
     *
     * `--no-reviews` 存在的理由：评稿要翻一百五十页、两分钟。只想看作品数时
     * 没必要等；而「不数评稿」这件事必须写在输出里，不能让人拿一份评稿全是 0
     * 的报表去汇报。
     */
    case 'source:stats':
        $sourceApi = new SourceApi(
            $config->string('source.base'),
            $config->string('source.key'),
            $config->string('source.ca', '')
        );
        $catalog = new SourceCatalog($sourceApi, $config->string('source.cache_dir'));
        $stats   = new IssueStats(
            $sourceApi,
            $catalog,
            new PracticeStatRepository($pdo)
        );

        // 记在拉目录之前：这一轮「花了多久」要含同步，否则定时任务从 18:00
        // 跑到 18:04 你只会看到 0 秒，以为它秒完就退出了
        $startedAt = time();

        // 统计前**必须**刷新目录：拿一份三天前的目录算出来的数，看起来完全正常，
        // 只是少了这几天新投的稿 —— 而这一页每月才更新一次，错了要等一个月才有人发现。
        // 想跳过就显式写 --no-sync（调试时用），并且输出里会说清用的是哪份快照。
        if (!isset($options['no-sync'])) {
            fwrite(STDOUT, "同步官网目录…\n");
            $sync = $catalog->sync(static function (int $page, int $n): void {
                if ($page % 10 === 0) {
                    fwrite(STDOUT, sprintf("  第 %d 页，累计 %d 篇\n", $page, $n));
                }
            });
            fwrite(STDOUT, sprintf("  目录 %d 篇（API 报 %d 篇，%d 页）\n", $sync['fetched'], $sync['total'], $sync['pages']));
        }

        $withReviews = !isset($options['no-reviews']);

        fwrite(STDOUT, $withReviews
            ? "开始重算（含评稿，约两分钟）…\n"
            : "开始重算（不数评稿，评稿相关列记 0）…\n");

        $built = $stats->build($withReviews, static function (int $no, string $line): void {
            fwrite(STDOUT, sprintf("  第 %-3d 期：%s\n", $no, $line));
        });

        $fmt = static function (array $r): string {
            return sprintf(
                "  %-8s 公开 %5d · 未公开 %4d · 作者 %4d · 评稿 %6d 条 / %4d 人 · 总字数 %9s",
                (string) $r['label'],
                (int) $r['works_public'],
                (int) $r['works_hidden'],
                (int) $r['authors'],
                (int) $r['review_count'],
                (int) $r['review_people'],
                number_format((int) $r['words_total'])
            );
        };

        fwrite(STDOUT, "\n" . str_repeat('─', 72) . "\n");
        fwrite(STDOUT, $fmt($built['total']) . "\n");
        fwrite(STDOUT, sprintf(
            "  分母口径：%d 篇（公开 %d + 未公开 %d + 已公开未发布 %d），已排除草稿箱与回收站\n",
            (int) $built['total']['works_total'],
            (int) $built['total']['works_public'],
            (int) $built['total']['works_hidden'],
            (int) $built['total']['works_rejected']
        ));
        fwrite(STDOUT, sprintf(
            "  目录快照：%s（%d 篇）\n",
            (int) $built['catalog_at'] > 0 ? date('Y-m-d H:i', (int) $built['catalog_at']) : '未知',
            (int) $built['catalog_n']
        ));

        if (isset($options['json']) && $options['json'] !== '') {
            $path = (string) $options['json'];
            $json = json_encode(
                [
                    'generated_at' => $built['fetched_at'],
                    'catalog_at'   => (int) $built['catalog_at'],
                    'catalog_n'    => (int) $built['catalog_n'],
                    'rows'         => $built['rows'],
                    'total'        => $built['total'],
                ],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
            );
            if ($json === false || @file_put_contents($path, $json) === false) {
                throw new RuntimeException('JSON 写入失败：' . $path);
            }
            fwrite(STDOUT, "  JSON → {$path}\n");
        }

        if (isset($options['csv']) && $options['csv'] !== '') {
            $path = (string) $options['csv'];
            if (@file_put_contents($path, $stats->toCsv($built)) === false) {
                throw new RuntimeException('CSV 写入失败：' . $path);
            }
            fwrite(STDOUT, "  CSV  → {$path}\n");
        }

        if (isset($options['apply'])) {
            $run = $stats->persist($built, 'cron', (string) ($options['note'] ?? ''), $startedAt);
            fwrite(STDOUT, sprintf(
                "\n已落库：批次 %s（run_id=%d，%d 期 + 总计 1 行）\n",
                $run['code'],
                $run['run_id'],
                count($built['rows'])
            ));
        } else {
            fwrite(STDOUT, "\n（预览。加 --apply 才落库。）\n");
        }
        break;

    /**
     * 每月 2 日 18:00 的采集：第二十五期起、非匿名公开发表、作者近期活跃的作品
     * → 采进银河图书馆 → 送进拆解流水线。
     *
     * 「近期」＝ AuthorActivity::WINDOW_MONTHS（当前两个月），与后台分类页、
     * 公开区闸门同一个数字。收紧窗口意味着这条线采得更少 —— 但采进来的每一篇
     * 都是公开区会保留的，不会出现「刚拆完就被闸门收起来」的白花钱。
     *
     *   php -c php.ini bin/console.php source:active-collect                 # 只看会派什么
     *   php -c php.ini bin/console.php source:active-collect --apply         # 真派（默认上架）
     *   php -c php.ini bin/console.php source:active-collect --apply --dry-run --limit=5
     *   php -c php.ini bin/console.php source:active-collect --apply --no-publish
     *
     * `--no-sync` 跳过目录同步（目录刚同步过时用）；`--refresh-activity` 强制重建
     * 活跃度快照（默认超过一天才重建）。
     *
     * 这条线**不会**去碰第一到二十四期：那几期早在馆里了，范围写宽只会把
     * 已经上架的作品重新排进队列，白跑一遍。
     */
    case 'source:active-collect':
        $sourceApi = new SourceApi(
            $config->string('source.base'),
            $config->string('source.key'),
            $config->string('source.ca', '')
        );
        $catalog  = new SourceCatalog($sourceApi, $config->string('source.cache_dir'));
        $activity = new AuthorActivity($sourceApi, $config->string('source.cache_dir'));

        $jobs     = new JobRepository($pdo);
        $oneClick = new OneClick(
            $pdo,
            $catalog,
            $activity,
            new WorkRepository($pdo),
            new RunBatchRepository($pdo),
            new BatchDispatcher($pdo, $jobs),
            $jobs,
            new PaceEstimate($pdo),
            new AuditLogger($pdo, new RequestContext($crypto))
        );

        $fromIssue = (int) ($options['from'] ?? ActiveCollect::FROM_ISSUE);
        if ($fromIssue <= 0) {
            $fromIssue = ActiveCollect::FROM_ISSUE;
        }

        if (!isset($options['no-sync'])) {
            fwrite(STDOUT, "同步官网目录…\n");
            $sync = $catalog->sync(static function (int $page, int $n): void {
                if ($page % 10 === 0) {
                    fwrite(STDOUT, sprintf("  第 %d 页，累计 %d 篇\n", $page, $n));
                }
            });
            fwrite(STDOUT, sprintf("  目录 %d 篇（API 报 %d 篇，%d 页）\n", $sync['fetched'], $sync['total'], $sync['pages']));
        }

        $collect = new ActiveCollect($catalog, $activity, $oneClick);
        $limit   = (int) ($options['limit'] ?? OneClick::MAX_TARGETS);

        fwrite(STDOUT, sprintf("刷新活跃度快照（近%s）…\n", AuthorActivity::WINDOW_LABEL));
        $plan = $collect->plan($fromIssue, $limit, isset($options['refresh-activity']));

        fwrite(STDOUT, sprintf(
            "范围：第 %d 期起（共 %d 期）· 仅活跃作者 · 非匿名 · 已公开发表\n",
            $fromIssue,
            count($plan['issues'])
        ));
        if (($plan['activity']['rebuilt'] ?? false) === true) {
            fwrite(STDOUT, sprintf(
                "  活跃度已重建：%d 人（其中活跃 %d 人）\n",
                (int) ($plan['activity']['authors'] ?? 0),
                (int) ($plan['activity']['active'] ?? 0)
            ));
        } else {
            fwrite(STDOUT, sprintf(
                "  活跃度沿用快照：%s\n",
                date('Y-m-d H:i', (int) ($plan['activity']['built_at'] ?? 0))
            ));
        }

        $p = $plan['plan'];
        fwrite(STDOUT, sprintf(
            "范围内 %d 篇；本次派 %d 篇（待入馆 %d、待拆解 %d、待上架 %d）\n",
            (int) $p['scope_total'],
            count($p['targets']),
            (int) $p['plan']['ingest'],
            (int) $p['plan']['analyze'],
            (int) $p['plan']['publish']
        ));
        foreach (($p['excluded'] ?? []) as $reason => $n) {
            if ($n > 0) {
                fwrite(STDOUT, sprintf("  排除 %d 篇：%s\n", $n, $reason));
            }
        }
        if ($p['pace']['samples'] > 0) {
            fwrite(STDOUT, sprintf(
                "按实测均价估算：约 %.2f 元 / %.1f 小时\n",
                $p['pace']['cost_yuan'],
                $p['pace']['hours']
            ));
        }

        if (!isset($options['apply'])) {
            fwrite(STDOUT, "\n（预览。加 --apply 才真正派活。）\n");
            break;
        }

        if (count($p['targets']) === 0) {
            fwrite(STDOUT, "\n没有待处理的作品，本次不建批次。\n");
            break;
        }

        $result = $collect->launch(
            $plan['scope'],
            !isset($options['no-publish']),
            '每月 2 日自动采集（第 ' . $fromIssue . ' 期起 · 仅活跃作者）',
            (int) ($options['actor'] ?? 1)
        );

        fwrite(STDOUT, sprintf(
            "\n已派活：批次 %s，计划 %d 篇，入队 %d 篇，跳过 %d 篇。\n",
            $result['batch_code'],
            $result['planned'],
            $result['queued'],
            $result['skipped']
        ));
        break;

    /**
     * 清空全部拆解结果与词条（换模型重跑、正式生成前重置）。
     *
     *   php -c php.ini bin/console.php library:purge-analysis         # 只报会删多少
     *   php -c php.ini bin/console.php library:purge-analysis --apply # 真删
     *
     * 删的是「从正文算出来的东西」：分析、阶段、修订、剧情、伏笔、索引卡、读者简报、
     * 修改建议、索引词与词条关联、以及导入/拆解留下的任务与批次。
     *
     * **不删**：作品本体、原文文件、作者、期次、审计日志、AI 调用账单、访客搜索词。
     * 这几样要么是原始素材，要么是账 —— 重跑一遍不会让它们变得不准。
     *
     * 作品退回「已入馆待拆解」：status=imported、current_analysis_id=NULL、
     * summary 与 published_at 清空、隐藏状态复位。这样它们仍在库里，
     * 下次一键拆解按 `--stage=todo` 就能重新拆，不必再抓一遍正文。
     */
    case 'library:purge-analysis':
        $tables = [
            'hq_suggestion_path', 'hq_suggestion',
            'hq_revision_item', 'hq_revision',
            'hq_reader_brief', 'hq_foreshadow',
            'hq_plot_beat', 'hq_plot_summary',
            'hq_index_card', 'hq_work_term',
            'hq_index_term_alias', 'hq_index_term',
            'hq_analysis_stage', 'hq_analysis_revision', 'hq_analysis',
            'hq_job_log', 'hq_job', 'hq_run_batch',
        ];

        $counts = [];
        $total  = 0;
        foreach ($tables as $t) {
            $n = (int) $pdo->query("SELECT COUNT(*) FROM {$t}")->fetchColumn();
            $counts[$t] = $n;
            $total += $n;
        }

        $works = (int) $pdo->query('SELECT COUNT(*) FROM hq_work WHERE deleted_at IS NULL')->fetchColumn();
        $live  = (int) $pdo->query("SELECT COUNT(*) FROM hq_work WHERE status = 'published' AND deleted_at IS NULL")->fetchColumn();

        fwrite(STDOUT, sprintf(
            "将删除 %d 行（%d 张表）；%d 篇作品退回「待拆解」，其中 %d 篇已上架会随之下线。\n\n",
            $total,
            count($tables),
            $works,
            $live
        ));
        foreach ($counts as $t => $n) {
            if ($n > 0) {
                fwrite(STDOUT, sprintf("  %-26s %6d\n", $t, $n));
            }
        }
        fwrite(STDOUT, "\n保留：作品本体、原文文件、作者、期次、审计日志、AI 调用账单、访客搜索词。\n");

        if (!isset($options['apply'])) {
            fwrite(STDOUT, "\n这是预览。加 --apply 才真删（删之前先 mysqldump 备份）。\n");
            break;
        }

        $pdo->beginTransaction();
        try {
            foreach ($tables as $t) {
                $pdo->exec("DELETE FROM {$t}");
            }

            $reset = $pdo->exec(
                "UPDATE hq_work
                    SET current_analysis_id = NULL,
                        summary = NULL,
                        status = CASE WHEN status IN ('published', 'analyzed', 'analyzing') THEN 'imported' ELSE status END,
                        published_at = NULL,
                        visibility = 'visible',
                        hidden_at = NULL,
                        hidden_by = NULL,
                        hidden_reason = NULL,
                        updated_at = NOW()
                  WHERE deleted_at IS NULL"
            );

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            fwrite(STDERR, '清空失败，已回滚：' . $e->getMessage() . "\n");
            exit(1);
        }

        // 页面缓存必须清：里面存的是「上架那一刻的 HTML」，作品退回待拆解后
        // 继续返回旧页面，读者还能看到已经不存在的作品。
        $cleared = new PageCache($config->string('library.cache_dir'))->clear();

        try {
            $urls = SitemapBuilder::write(
                new LibraryRepository($pdo),
                $config->string('app.url'),
                dirname(__DIR__) . '/public/sitemap.xml'
            );
            $sitemap = "sitemap 已重写（{$urls} 条 URL）";
        } catch (\Throwable $e) {
            $sitemap = 'sitemap 没写成：' . $e->getMessage();
        }

        (new AuditLogger($pdo))->log(null, 'library.purge_analysis', 'library', null, null, [
            'rows'  => $counts,
            'works' => $works,
        ]);

        fwrite(STDOUT, sprintf(
            "\n已清空：%d 行；%d 篇作品退回待拆解；清掉 %d 个缓存页；%s。\n",
            $total,
            (int) $reset,
            $cleared,
            $sitemap
        ));
        break;

    /**
     * 公开区闸门：按两条规则把不该公开的拆解收起来。
     *
     *   1. 匿名作品 —— 一律不公开（作品级，不看作者活跃度）；
     *   2. 长期没投稿也没评稿的作者 —— 其拆解不公开（作者级，按时间窗判）。
     *
     *   php -c php.ini bin/console.php library:active-gate                  # 只试算
     *   php -c php.ini bin/console.php library:active-gate --months=3
     *   php -c php.ini bin/console.php library:active-gate --apply
     *   php -c php.ini bin/console.php library:active-gate --refresh-activity
     *   php -c php.ini bin/console.php library:active-gate --restore-all --apply   # 整体撤销
     *
     * 只动 hq_analysis.visibility：隐藏后作品在公开区整体消失（LIVE_WORK 要求
     * 当前拆解可见），但作品行、检索索引、人工「隐藏作品」都不受影响。
     * 恢复只认本闸门写下的 `active-gate:` 理由，人工藏起来的永不自动放出。
     *
     * 默认时间窗取 AuthorActivity::WINDOW_MONTHS，与后台分类页同一个数字；
     * `--months` 只用于临时试算别的口径，别拿它长期跑。
     *
     * 不带 --apply 时一个字节都不写 —— 包括不碰页面缓存与 sitemap。
     */
    case 'library:active-gate':
        $sourceApi = new SourceApi(
            $config->string('source.base'),
            $config->string('source.key'),
            $config->string('source.ca', '')
        );
        $catalog  = new SourceCatalog($sourceApi, $config->string('source.cache_dir'));
        $activity = new AuthorActivity($sourceApi, $config->string('source.cache_dir'));

        $months = (int) ($options['months'] ?? ActiveGate::DEFAULT_MONTHS);
        if ($months < 1) {
            fwrite(STDERR, "months 必须大于 0。\n");
            exit(1);
        }

        // 快照超过一天才重建。里面存的是**原始时间戳**而不是结论，结论在读取时
        // 现算，所以后台（两个月）与本命令 `--months` 试算别的口径可以共用同一份
        // 快照，各算各的。重建时统一按 AuthorActivity::since() 建，保持标签同源。
        if (isset($options['refresh-activity']) && $activity->builtAt() < time() - 86_400) {
            $cache = $catalog->load();
            if ($cache === null) {
                fwrite(STDERR, "还没有目录缓存。先跑 source:sync。\n");
                exit(1);
            }

            fwrite(STDOUT, sprintf("活跃度快照过期，重建中（%d 篇，一人一次请求）…\n", count($cache['items'])));
            $payload = $activity->build(
                $cache['items'],
                AuthorActivity::since(),
                true,
                true,
                static function (int $done, int $total, string $name): void {
                    if ($done % 10 === 0 || $done === $total) {
                        fwrite(STDOUT, sprintf("  %3d/%d  %s\n", $done, $total, $name));
                    }
                }
            );
            fwrite(STDOUT, sprintf("  已重建：%d 位作者\n\n", count($payload['authors'])));
        }

        $gate = new ActiveGate(
            $pdo,
            $activity,
            new VisibilityService(
                $pdo,
                new AuditLogger($pdo, new RequestContext($crypto)),
                new PageCache($config->string('library.cache_dir')),
                new LibraryRepository($pdo),
                $config
            ),
            $config
        );

        // 整体回滚：口径判断错了，或先撤下来再讨论时用。
        // 与其他命令一致，不带 --apply 只数不写。
        if (isset($options['restore-all'])) {
            $byRule = $gate->gatedIdsByRule();
            $ids    = $gate->gatedAnalysisIds();

            if ($ids === []) {
                fwrite(STDOUT, "没有由本闸门隐藏的拆解，无需回滚。\n");
                break;
            }

            // 两类一起撤：判断口径整个错了的时候，要的是「一条命令全撤」，
            // 不该逼人先分清哪条是匿名收的、哪条是活跃度收的。
            fwrite(STDOUT, sprintf(
                "待回滚 %d 条：匿名规则 %d 条、不活跃 %d 条。\n",
                count($ids),
                count($byRule['anon']),
                count($byRule['activity'])
            ));

            if (!isset($options['apply'])) {
                fwrite(STDOUT, "预览，加 --apply 才生效。\n");
                break;
            }

            $done = $gate->restoreAll($ids, (int) ($options['actor'] ?? 1));
            fwrite(STDOUT, sprintf(
                "已回滚：%d 条恢复可见，实际改动 %d 条，失效页面 %d 个。\n",
                $done['requested'],
                $done['changed'],
                $done['pages']
            ));
            break;
        }

        try {
            $plan = $gate->plan($months);
        } catch (RuntimeException $e) {
            // 快照/目录缺失是正常的前置条件问题，给人一句能照着做的提示，不甩堆栈
            fwrite(STDERR, $e->getMessage() . "\n");
            exit(1);
        }

        $b = $plan['buckets'];
        fwrite(STDOUT, "公开区闸门（只改拆解可见性，不动作品与检索）\n");
        fwrite(STDOUT, sprintf(
            "  时间窗：近 %d 个月，%s 之后投过稿或评过稿算活跃\n",
            $plan['months'],
            $plan['since_label']
        ));
        fwrite(STDOUT, sprintf(
            "  快照：%s · %d 位作者，其中活跃 %d 位\n",
            $plan['snapshot_label'],
            $plan['snapshot_authors'],
            $plan['active_authors']
        ));
        fwrite(STDOUT, sprintf(
            "  目录：%d 条作品标为匿名（一律不公开）\n\n",
            $plan['anon_in_catalog']
        ));

        // 五个标签都是四字，等宽下自然对齐，不必按字节补空格。
        // 匿名那一桶不打印作者数：匿名作品本来就不该被归到任何一个 uid 名下，
        // 打一个「1 位作者」出来，等于用命令行的输出把匿名揭开了。
        foreach ([
            'keep'    => '保留可见',
            'anon'    => '匿名隐藏',
            'hide'    => '应当隐藏',
            'unknown' => '无法判定',
            'manual'  => '人工隐藏',
        ] as $key => $label) {
            if ($key === 'anon') {
                fwrite(STDOUT, sprintf("  %s  %5d 篇\n", $label, $b[$key]['works']));
                continue;
            }
            fwrite(STDOUT, sprintf(
                "  %s  %5d 篇 · %4d 位作者\n",
                $label,
                $b[$key]['works'],
                count($b[$key]['authors'])
            ));
        }

        foreach ($plan['unknown_reasons'] as $why => $count) {
            fwrite(STDOUT, sprintf("      · %s：%d 篇\n", $why, $count));
        }

        if ($b['hide']['works'] > 0) {
            fwrite(STDOUT, "\n  将隐藏的作者（按篇数，前 10 位）：\n");
            $shown = 0;
            foreach ($b['hide']['authors'] as $uid => $a) {
                if ($shown++ >= 10) {
                    break;
                }
                fwrite(STDOUT, sprintf(
                    "    %4d 篇  %s（uid %d）\n",
                    $a['works'],
                    $a['name'] !== '' ? $a['name'] : '无名',
                    $uid
                ));
            }
            if (count($b['hide']['authors']) > 10) {
                fwrite(STDOUT, sprintf("    …… 另有 %d 位\n", count($b['hide']['authors']) - 10));
            }
        }

        fwrite(STDOUT, sprintf(
            "\n将隐藏拆解 %d 篇（匿名 %d 篇 + 不活跃 %d 篇）；将恢复可见 %d 篇（此前被本闸门隐藏）。\n",
            count($plan['toHideAnon']) + count($plan['toHide']),
            count($plan['toHideAnon']),
            count($plan['toHide']),
            count($plan['toRestore'])
        ));

        if (!isset($options['apply'])) {
            fwrite(STDOUT, "预览。加 --apply 才生效。\n");
            break;
        }

        if ($plan['toHideAnon'] === [] && $plan['toHide'] === [] && $plan['toRestore'] === []) {
            fwrite(STDOUT, "没有需要改动的拆解。\n");
            break;
        }

        $result = $gate->apply($plan, (int) ($options['actor'] ?? 1));
        fwrite(STDOUT, sprintf(
            "已执行：匿名隐藏 %d 篇（改动 %d）；不活跃隐藏 %d 篇（改动 %d）；"
            . "恢复 %d 篇（改动 %d）；失效页面 %d 个。\n",
            $result['anon']['requested'],
            $result['anon']['changed'],
            $result['hide']['requested'],
            $result['hide']['changed'],
            $result['restore']['requested'],
            $result['restore']['changed'],
            $result['pages']
        ));
        fwrite(STDOUT, sprintf("  理由：%s / %s\n", $result['anon_reason'], $result['reason']));
        break;

    /** 重建检索关键词表（词表归并、批量发布之后跑一次） */
    case 'library:reindex':
        $library = new LibraryRepository($pdo);
        $search  = new SearchService($library, PublicTextGuard::fromDatabase($pdo));
        $result  = $search->reindexAll();

        fwrite(STDOUT, sprintf("已重建 %d 篇的关键词，共 %d 条。\n", $result['works'], $result['keywords']));
        new PageCache($config->string('library.cache_dir'))->invalidateTags(['works', 'terms']);
        break;

    /**
     * 回收过期页面缓存文件。
     *
     * 为什么需要：PageCache::get() 过期**故意不删**（省一次 IO，下次 put 会覆盖），
     * put() 只覆盖同一个 key。于是「被访问过一次、此后再没人要」的页面永远留着 ——
     * 键里带完整查询串（筛选、翻页游标、词条），取值空间没有上限。
     * 代价落在 invalidateTags() 上：它每次发布都要 glob 整个目录、逐个读 JSON 解析，
     * 陈旧文件越多，每次发布越慢。这个命令定期把死文件清掉。
     *
     * 门槛默认 86400 秒（24 小时），远大于最长 TTL（method 页 3600 秒），
     * 所以删掉的一定是「无论哪一页都不可能再命中」的文件，不存在误删。
     *
     * 用法：
     *   php -c php.ini bin/console.php library:cache-prune
     *   php -c php.ini bin/console.php library:cache-prune --max-age=604800 --apply
     */
    case 'library:cache-prune':
        $apply  = isset($options['apply']);
        $maxAge = (int) ($options['max-age'] ?? 86400);

        // 最长 TTL 是 method 页的 3600 秒。门槛比它小就可能删掉还能命中的文件，
        // 而这种错不会报错，只会让某些页莫名其妙变慢 —— 直接从入口挡掉。
        if ($maxAge < 7200) {
            fwrite(STDERR, "max-age 不能小于 7200 秒（最长页面 TTL 是 3600 秒）。\n");
            exit(1);
        }

        $cache = new PageCache($config->string('library.cache_dir'));
        $human = static fn (int $bytes): string => $bytes >= 1048576
            ? sprintf('%.1f MB', $bytes / 1048576)
            : sprintf('%.1f KB', $bytes / 1024);

        if (!$apply) {
            $stats = $cache->stats();
            fwrite(STDOUT, sprintf(
                "当前 %d 个页面、共 %s。预演结束，加 --apply 清理超过 %d 小时的文件。\n",
                $stats['pages'],
                $human($stats['bytes']),
                intdiv($maxAge, 3600)
            ));
            break;
        }

        $pruned = $cache->prune($maxAge);
        fwrite(STDOUT, sprintf("已清理 %d 个页面，释放 %s。\n", $pruned['removed'], $human($pruned['bytes'])));
        break;

    /**
     * 把词条上的 is_canonical 与 resources/taxonomy/sf_terms.php 对齐。
     *
     * 词表是人工审过的通行词清单，词库页给它们免检展示；表外新词要挣到
     * 第二篇作品的引用才露面。所以**每次改完词表都要跑一遍**，否则新加的
     * 词会被当成表外词。
     *
     * 用法：
     *   php -c php.ini bin/console.php term:sync-taxonomy
     *   php -c php.ini bin/console.php term:sync-taxonomy --apply
     */
    case 'term:sync-taxonomy':
        $apply = isset($options['apply']);

        if (!$apply) {
            $n = \App\Application\Analysis\Taxonomy::size();
            fwrite(STDOUT, sprintf("词表 %d 条。预演结束，加 --apply 写库。\n", $n));
            break;
        }

        $r = \App\Application\Analysis\Taxonomy::syncFlags($pdo);
        fwrite(STDOUT, sprintf(
            "词表 %d 条 · 新标记 %d 条 · 撤销标记 %d 条\n",
            $r['total'],
            $r['marked'],
            $r['cleared']
        ));
        new PageCache($config->string('library.cache_dir'))->invalidateTags(['terms']);
        break;

    /**
     * 把词条百科文件基线灌进 hq_term_encyclopedia。
     *
     * 文件（resources/taxonomy/term_encyclopedia.php）是 68 条人工基线。搬迁到库里
     * 之后它仍保留：读取侧查不到库才回落文件，文件既是兜底也是一次性导入源。
     *
     * 匹配按 hq_index_term.canonical（去空白、转小写后比对）。预览与落库共用同一段
     * 比对逻辑，所以「预览说覆盖 3 条」就是真会覆盖 3 条 —— 回灌基线前先看预览，
     * 别把后台刚改过的内容冲掉。
     *
     * 文件里有、库里没有的键会列出来：那是死键（改名/归并后没跟上），
     * 应该改文件，而不是在库里造一条空词条。
     *
     * 用法：
     *   php -c php.ini bin/console.php term:encyclopedia-import
     *   php -c php.ini bin/console.php term:encyclopedia-import --apply
     */
    case 'term:encyclopedia-import':
        $apply = isset($options['apply']);
        $file  = dirname(__DIR__) . '/resources/taxonomy/term_encyclopedia.php';

        if (!is_file($file)) {
            fwrite(STDERR, "找不到百科文件：{$file}\n");
            exit(1);
        }

        /** @var array<string, array{history: string, classics: list<array{title: string, author: string, year: int}>}> $map */
        $map = require $file;
        if (!is_array($map) || $map === []) {
            fwrite(STDERR, "百科文件为空或不是数组。\n");
            exit(1);
        }

        $termIdByName = [];
        foreach ($pdo->query('SELECT id, canonical FROM hq_index_term') as $row) {
            $termIdByName[(string) $row['canonical']] = (int) $row['id'];
        }

        $known = [];
        foreach (array_keys($termIdByName) as $name) {
            $known[\App\Infrastructure\Pdo\TermEncyclopediaRepository::normalize((string) $name)] = true;
        }

        $dead = [];
        $matched = 0;
        foreach (array_keys($map) as $canonical) {
            if (isset($known[\App\Infrastructure\Pdo\TermEncyclopediaRepository::normalize((string) $canonical)])) {
                $matched++;
            } else {
                $dead[] = (string) $canonical;
            }
        }

        $repo   = new \App\Infrastructure\Pdo\TermEncyclopediaRepository($pdo);
        $result = $repo->seedFromFile($map, $termIdByName, null, !$apply);

        // skipped 里混了两种：死的键 + 已有且内容相同。分开报，否则「跳过 68 条」
        // 会被读成「全都死键」或「全都重复」，两种结论都错。
        $unchanged = max(0, $result['skipped'] - count($dead));

        fwrite(STDOUT, sprintf(
            "文件 %d 条 · 可匹配 %d 条 · 死键 %d 条\n",
            count($map),
            $matched,
            count($dead)
        ));
        foreach ($dead as $word) {
            fwrite(STDOUT, "  !! 死键：{$word}\n");
        }
        fwrite(STDOUT, sprintf(
            "%s %d 条 · 覆盖 %d 条 · 无变化 %d 条%s\n",
            $apply ? '已新增' : '将新增',
            $result['inserted'],
            $result['updated'],
            $unchanged,
            $apply ? '' : '　（预览，加 --apply 落库）'
        ));

        if ($apply) {
            new PageCache($config->string('library.cache_dir'))->invalidateTags(['terms']);
            fwrite(STDOUT, "已失效词条页缓存（terms）。\n");
        }
        break;

    /**
     * 清理词库：删掉退役词与不可索引的孤儿词。
     *
     * 两类要删的东西：
     *   1. retired_terms.php 里列出的词 —— 形状合格但**与科幻没有强关联**，
     *      留在库里会让词库看起来像一本通用意象词典；
     *   2. 表外、且引用不足 2 篇的词 —— 它们本来就不会出现在公开词库里
     *      （合成口径 TERM_INDEXABLE 已排除），删掉只是让后台不再堆着四百条死记录。
     *
     * 先删关联、再删别名、最后删词条 —— hq_work_term 有外键，反过来会失败。
     *
     * 用法：
     *   php -c php.ini bin/console.php term:prune
     *   php -c php.ini bin/console.php term:prune --apply
     */
    case 'term:prune':
        $apply = isset($options['apply']);

        $rows = $pdo->query(
            'SELECT t.id, t.canonical, t.is_canonical,
                    (SELECT COUNT(DISTINCT w.work_id) FROM hq_work_term w WHERE w.term_id = t.id) AS refs
               FROM hq_index_term t'
        )->fetchAll();

        $retiredIds = [];
        $orphanIds  = [];
        $links      = 0;

        foreach ($rows as $row) {
            $id   = (int) $row['id'];
            $word = (string) $row['canonical'];
            $refs = (int) $row['refs'];

            if (\App\Application\Analysis\Taxonomy::isRetired($word)) {
                $retiredIds[] = $id;
                $links += $refs;
                fwrite(STDOUT, sprintf("· 退役　%-14s 关联 %d 篇\n", $word, $refs));
                continue;
            }

            if ((int) $row['is_canonical'] === 0 && $refs < 2) {
                $orphanIds[] = $id;
            }
        }

        $total = count($retiredIds) + count($orphanIds);
        fwrite(STDOUT, sprintf(
            "\n退役词 %d 条（关联 %d 条）· 孤儿词 %d 条 · 合计 %d 条%s\n",
            count($retiredIds),
            $links,
            count($orphanIds),
            $total,
            $apply ? '' : '　（加 --apply 才执行）'
        ));

        if ($total === 0 || !$apply) {
            break;
        }

        $ids = implode(',', array_map('intval', array_merge($retiredIds, $orphanIds)));
        $pdo->beginTransaction();
        try {
            $pdo->exec("DELETE FROM hq_work_term WHERE term_id IN ($ids)");
            $pdo->exec("DELETE FROM hq_index_term_alias WHERE term_id IN ($ids)");
            $pdo->exec("DELETE FROM hq_index_term WHERE id IN ($ids)");
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            fwrite(STDERR, '清理失败，已回滚：' . $e->getMessage() . "\n");
            exit(1);
        }

        new PageCache($config->string('library.cache_dir'))->invalidateTags(['terms', 'works']);

        // 词条页少了一批，sitemap 必须跟着重写，否则搜索引擎会去抓一堆 404
        $n = SitemapBuilder::write(
            new LibraryRepository($pdo),
            $config->string('app.url'),
            dirname(__DIR__) . '/public/sitemap.xml'
        );
        fwrite(STDOUT, sprintf("已重写 sitemap.xml（%d 条 URL）\n", $n));
        break;

    /**
     * 按期次日历校正 hq_issue 的起止时间。
     *
     * 导入时留下的 starts_at/ends_at 是「插入那一秒」的时间戳，不是真实收稿期；
     * 期次页因此一直不敢显示区间（两条时间戳同秒，看起来像坏了）。
     * 这里用 IssueSchedule（规则 4.1 的 9 期不等长日历）算出应有值，
     * 默认只预演，加 --apply 才写库。
     *
     * 用法：
     *   php -c php.ini bin/console.php library:issue-dates
     *   php -c php.ini bin/console.php library:issue-dates --apply
     */
    case 'library:issue-dates':
        $apply = isset($options['apply']);
        $rows  = $pdo->query('SELECT id, no, label, starts_at, ends_at, source_ends_at FROM hq_issue ORDER BY no')->fetchAll();

        if ($rows === []) {
            fwrite(STDOUT, "库里没有期次记录。\n");
            break;
        }

        $cache   = new PageCache($config->string('library.cache_dir'));
        $changed = 0;
        $skipped = 0;

        foreach ($rows as $row) {
            $no     = (int) $row['no'];

            // 已对准官网的期次不碰。官网 deadline 是主办方自己填的，是实然；
            // 这里给的是按「9 期/年」推出来的应然。两者冲突时以官网为准，
            // 否则跑一次本命令就把 source:issue-sync 刚写好的真日期推回错的。
            if ($row['source_ends_at'] !== null && $row['source_ends_at'] !== '') {
                ++$skipped;
                fwrite(STDOUT, sprintf(
                    "· %s 已对准官网（截稿 %s），跳过\n",
                    $row['label'],
                    (string) $row['source_ends_at']
                ));
                continue;
            }

            $period = IssueSchedule::period($no);
            $want   = [$period['start']->format('Y-m-d H:i:s'), $period['end']->format('Y-m-d H:i:s')];
            $have   = [(string) $row['starts_at'], (string) $row['ends_at']];

            if ($have === $want) {
                fwrite(STDOUT, sprintf("· %s 已是 %s → %s，跳过\n", $row['label'], $want[0], $want[1]));
                continue;
            }

            $changed++;
            fwrite(STDOUT, sprintf(
                "· %s %s → %s　应为　%s → %s（%s）%s\n",
                $row['label'],
                $have[0],
                $have[1],
                $want[0],
                $want[1],
                $period['months'],
                $apply ? '　已写入' : '　（加 --apply 才执行）'
            ));

            if (!$apply) {
                continue;
            }

            $stmt = $pdo->prepare('UPDATE hq_issue SET starts_at = :s, ends_at = :e, updated_at = NOW() WHERE id = :id');
            $stmt->execute(['s' => $want[0], 'e' => $want[1], 'id' => (int) $row['id']]);
            $cache->invalidateTags(['issue:' . $no]);
        }

        fwrite(STDOUT, sprintf(
            "\n共 %d 期，需要校正 %d 期，已对准官网跳过 %d 期。\n",
            count($rows),
            $changed,
            $skipped
        ));
        break;

    /**
     * 一键拆解（命令行版）。
     *
     * 与后台 /admin/oneclick 走的是同一个 OneClick 用例，差别只在输入从哪来：
     * 后台是表单，这里是参数。命令行版的用处是「排计划任务」和一个更容易被脚本/助手
     * 调用的入口 —— 但**不带 --apply 只预览**，避免一条手滑的命令烧掉整月预算。
     *
     * 用法：
     *   php -c php.ini bin/console.php pipeline:oneclick --issues=1,2,3 --stage=todo
     *   php -c php.ini bin/console.php pipeline:oneclick --issues=1 --limit=50 --apply
     *   php -c php.ini bin/console.php pipeline:oneclick --author=zombie --auto-publish --apply
     */
    case 'pipeline:oneclick':
        $sourceApi = new SourceApi(
            $config->string('source.base'),
            $config->string('source.key'),
            $config->string('source.ca', '')
        );
        $jobs     = new JobRepository($pdo);
        $oneClick = new OneClick(
            $pdo,
            new SourceCatalog($sourceApi, $config->string('source.cache_dir')),
            new AuthorActivity($sourceApi, $config->string('source.cache_dir')),
            new WorkRepository($pdo),
            new RunBatchRepository($pdo),
            new BatchDispatcher($pdo, $jobs),
            $jobs,
            new PaceEstimate($pdo),
            new AuditLogger($pdo, new RequestContext($crypto))
        );

        $issueArg = trim((string) ($options['issues'] ?? ''));
        $issues   = $issueArg === ''
            ? []
            : array_values(array_filter(array_map('intval', explode(',', $issueArg)), static fn (int $n): bool => $n > 0));

        $scope = OneClick::normalizeScope([
            'issues'       => $issues,
            'author_state' => (string) ($options['author'] ?? 'all'),
            'min_words'    => (int) ($options['min'] ?? 0),
            'max_words'    => (int) ($options['max'] ?? 0),
            'keyword'      => (string) ($options['q'] ?? ''),
            'stage'        => (string) ($options['stage'] ?? 'todo'),
            'limit'        => (int) ($options['limit'] ?? 500),
            'force'        => isset($options['force']),
        ]);

        $plan = $oneClick->resolve($scope);

        fwrite(STDOUT, sprintf(
            "范围：%s · %s · %s\n",
            $scope['issues'] === [] ? '全部期次' : '第 ' . implode('、', $scope['issues']) . ' 期',
            $scope['author_state'] === 'all' ? '不限作者' : ($scope['author_state'] === 'active' ? '仅活跃作者' : '仅僵尸作者'),
            ['todo' => '还没拆的', 'failed' => '失败重试', 'pending_publish' => '待上架', 'all' => '全部'][$scope['stage']]
        ));
        fwrite(STDOUT, sprintf(
            "范围内 %d 篇；本次派 %d 篇（其中待入馆 %d、待拆解 %d、待上架 %d）\n",
            $plan['scope_total'],
            count($plan['targets']),
            $plan['plan']['ingest'],
            $plan['plan']['analyze'],
            $plan['plan']['publish']
        ));
        $pace = $plan['pace'];
        fwrite(STDOUT, $pace['samples'] > 0
            ? sprintf(
                "按实测均价估算：约 %.2f 元 / %.1f 小时（样本 %d 篇）\n",
                $pace['cost_yuan'],
                $pace['hours'],
                $pace['samples']
            )
            : sprintf(
                "按经验值估算：约 %.2f 元 / %.1f 小时（本库还没有实测样本，这是 %.2f 元/篇的估数）\n",
                $pace['cost_yuan'],
                $pace['hours'],
                $pace['per_work_yuan']
            ));

        if (!isset($options['apply'])) {
            fwrite(STDOUT, "\n（预览。加 --apply 才真正派活。）\n");
            break;
        }

        $result = $oneClick->launch($scope, [
            'auto_publish' => isset($options['auto-publish']),
            'keep_hidden'  => isset($options['keep-hidden']),
            'note'         => (string) ($options['note'] ?? '命令行一键拆解'),
        ], (int) ($options['actor'] ?? 1));

        fwrite(STDOUT, sprintf(
            "\n已派活：批次 %s，计划 %d 篇，实际入队 %d 篇，跳过 %d 篇。\n",
            $result['batch_code'],
            $result['planned'],
            $result['queued'],
            $result['skipped']
        ));
        foreach ($result['reasons'] as $reason => $count) {
            fwrite(STDOUT, sprintf("  · 跳过 %d 篇：%s\n", $count, $reason));
        }
        if ($result['queued'] > 0) {
            fwrite(STDOUT, "worker 启动方式：php -c php.ini bin/worker.php --name=w1\n");
        }
        break;

    /** 生成静态 sitemap.xml（生产推荐：让 Nginx 直接命中文件，PHP 不参与） */
    case 'sitemap':
        $path = dirname(__DIR__) . '/public/sitemap.xml';

        try {
            $n = SitemapBuilder::write(new LibraryRepository($pdo), $config->string('app.url'), $path);
        } catch (\Throwable $e) {
            fwrite(STDERR, '写不进去：' . $e->getMessage() . "\n");
            exit(1);
        }

        fwrite(STDOUT, sprintf("已生成 %s（%d 条 URL）\n", $path, $n));
        break;

    case 'migrate':
        $file = $options['file'] ?? dirname(__DIR__) . '/database/migrations/001_init.sql';
        if (!is_file($file)) {
            fwrite(STDERR, "找不到 SQL 文件：{$file}\n");
            exit(1);
        }
        $sql = (string) file_get_contents($file);
        $pdo->exec($sql);
        fwrite(STDOUT, "已执行 {$file}\n");
        break;

    case 'check:db':
        $version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
        $tables  = (int) $pdo->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()')->fetchColumn();
        fwrite(STDOUT, "MySQL {$version} / 表 {$tables} 张\n");
        break;

    default:
        // 帮助里必须列全，否则运维会以为某个命令不存在。
        // 时间窗的数字与说法从这里取，避免改了 WINDOW_MONTHS 之后帮助还写着旧值。
        $wm = AuthorActivity::WINDOW_MONTHS;
        $wl = AuthorActivity::WINDOW_LABEL;
        fwrite(STDOUT, <<<TXT
银河图书馆控制台

账户与权限
  seed:rbac                                 写入角色、权限、体裁种子
  user:create --username=NAME [--password=PWD] [--role=admin]
  user:list
  user:reset-password --username=NAME [--password=PWD]
  user:enable-totp --username=NAME
  user:disable-totp --username=NAME
  session:purge

数据
  migrate [--file=PATH]                     跑迁移，默认 001_init.sql
  check:db                                  打印 MySQL 版本与表数

分析
  analysis:reap [--minutes=60] [--apply]    把卡在 running 的分析判失败；不带 --apply 只预览

一键拆解
  pipeline:oneclick [--issues=1,2,3] [--author=active|zombie] [--min=N] [--max=N]
                    [--q=关键词] [--stage=todo|failed|pending_publish|all]
                    [--limit=500] [--auto-publish] [--keep-hidden] [--force] [--apply]
                                            按范围抓正文 → 入馆 → 拆解（可选拆完自动上架）；
                                            --keep-hidden 拆完置为不公开，只在后台保留结果；
                                            不带 --apply 只预览会派多少篇、约多少钱

发布
  library:publish --work=CODE | --all [--apply] [--actor=ID]
  library:unpublish --work=CODE | --all [--apply] [--actor=ID]

可见性
  library:active-gate [--months={$wm}] [--refresh-activity] [--apply] [--actor=ID]
                                            收起不该公开的拆解：匿名作品一律收，
                                            作者不活跃的也收（默认近{$wl}）；
                                            --restore-all 整体撤销本闸门收过的；
                                            只改拆解可见性，人工隐藏的不会被恢复；
                                            不带 --apply 只试算，一个字节都不写

清空重来
  library:purge-analysis [--apply]          删掉全部拆解结果与词条，作品退回「待拆解」
                                            （保留作品、原文、作者、审计与账单）

索引词
  term:sync-taxonomy [--apply]              词表 is_canonical 对齐；改完词表必须跑
  term:prune [--apply]                      删退役词与引用不足的孤儿词，顺带重写 sitemap
  term:encyclopedia-import [--apply]        把 term_encyclopedia.php 基线灌进库；不带 --apply 只比对
  library:reindex                           重建关键词索引并失效页面缓存
  library:cache-prune [--max-age=SEC] [--apply]
                                            清理陈旧页面缓存文件（默认门槛 24 小时）

站点
  library:issue-dates [--apply]             按 IssueSchedule 回写期次起止
  sitemap                                   生成 public/sitemap.xml

内容源（官网站内文章）
  source:sync                               拉取全站目录落盘（37 页，约一分钟）
  source:export [--scope=…] [--issue=N] [--q=…] [--out=PATH]
                                            导出练笔清单 CSV；--out=- 写标准输出，
                                            不带 --out 则落 storage/artifacts/
  source:issue-sync [--apply]               用官网 26 个活动校正期次表
  source:activity [--since=DATE] [--refresh] [--limit=N] [--no-comments]
                                            按近{$wl}有无投稿/评稿分「活跃」「僵尸」，
                                            一人一次请求（约三分钟）
  source:activity-override [--list] | --set=作者=active|zombie | --remove=作者
                                            人工指定作者分类；重跑分类不会被冲掉

除 analysis:reap、term:prune、library:issue-dates 外，
publish / unpublish / sync-taxonomy / active-gate 同样默认只预览，加 --apply 才落库。

TXT);
        break;
}
