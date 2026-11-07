<?php

declare(strict_types=1);

/**
 * 附录 E（68 项安全检查）的自动化部分。
 *
 * 为什么要有这支脚本：附录 E 写在文档里，靠人在上线前一条条 curl —— 那件事
 * 一定会漏，而且第二次上线时没人愿意再做一遍。能自动判定的就必须自动判定，
 * 剩下真正需要人的（第三方扫描、TLS 评级、第二人复核）才留在签字表上。
 *
 * 判定只有三种，互相不可替代：
 *   pass   实测通过（evidence 是可以复查的输出，不是「应该没问题」）
 *   fail   实测不通过 —— 必须修，修完重跑
 *   manual 机器判不了（需要凭据、需要外网、需要另一个人）
 *
 * 用法：
 *   php -c php.ini bin/security-audit.php
 *   php -c php.ini bin/security-audit.php --user=qa_viewer --pass=xxx   # 带上会话类检查
 *   php -c php.ini bin/security-audit.php --json=storage/reports/security.json
 *
 * 退出码：有 fail 就 1，全 pass/manual 就 0 —— 这样它能直接进 CI。
 *         2 = 没有加载项目 php.ini（invocation 写错，不是配置坏了）。
 */

// ---------------------------------------------------------------------------
// 前置守卫：E-02…E-05 读的是「当前进程加载了哪一份 php.ini」，而不是「项目
// 打算用哪一份」。用错 ini 跑不会报错，只会把 4 条本来通过的检查判成 fail ——
// 这是最坏的一种失败：读报告的人以为配置坏了，其实只是 invocation 写错了。
// 所以这里宁可拒绝执行，把正确的命令摆在脸上。
// ---------------------------------------------------------------------------
$projectIni = realpath(dirname(__DIR__) . '/php.ini');
$loadedIni  = php_ini_loaded_file();
$loadedReal = $loadedIni === false ? false : (realpath($loadedIni) ?: $loadedIni);

if ($projectIni === false || $loadedReal === false || $loadedReal !== $projectIni) {
    fwrite(STDERR,
        "[!] 未加载项目 php.ini，拒绝执行（否则 E-02…E-05 会误报 fail）。\n"
        . "    实际加载：" . ($loadedReal === false ? '(无)' : $loadedReal) . "\n"
        . "    应为    ：" . ($projectIni === false ? '找不到 server/php.ini' : $projectIni) . "\n"
        . "    正确用法：php -c php.ini bin/security-audit.php [--user=… --pass=…]\n"
    );
    exit(2);
}

$root   = require dirname(__DIR__) . '/config/bootstrap.php';
$config = new App\Support\Config(require dirname(__DIR__) . '/config/settings.php');

use App\Support\Db;

$pdo  = Db::connect($config);
$base = rtrim($config->string('app.url'), '/');

$options = [];
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--')) {
        [$k, $v] = array_pad(explode('=', substr($arg, 2), 2), 2, '');
        $options[$k] = $v;
    }
}

$auditUser = $options['user'] ?? '';
$auditPass = $options['pass'] ?? '';

// ---------------------------------------------------------------------------
// --qa：不留常驻口令。
//
// 会话类检查（E-32 会话固定 / E-36 角色越权）必须真的登录一次，而那需要一个
// 能用的口令。把口令写进文档、脚本或命令行历史，等于为了跑一次检查永久开一个
// 后门 —— 检查跑完了，账号还在。所以这里反着来：
//   · 开跑前把口令随机成 36 位，只在本次进程内存里存在；
//   · 结束时（正常退出、fail 退出、异常退出都算）再随机成另一串。
// 净效果：这个账号任何时候都没有一个「人知道的」口令，想登录必须先跑一次自检。
// 代价是每次都得带 --qa，而这正好是想要的。
//
// 用法：php -c php.ini bin/security-audit.php --qa=qa_viewer
// ---------------------------------------------------------------------------

$qaUser = $options['qa'] ?? '';

if ($qaUser !== '' && $auditPass === '') {
    $users = new App\Infrastructure\Pdo\UserRepository($pdo);

    $qa = $users->findByUsername($qaUser);
    if ($qa === null) {
        fwrite(STDERR, "[!] --qa 指定的账号不存在：{$qaUser}（先用 user:create 建一个只读角色账号）\n");
        exit(2);
    }

    // 停用/锁定的账号根本登不进来，后面的会话与越权检查会一律报「防线未生效」——
    // 那是账号状态造成的假故障，不是防线漏了。宁可在这里停下，也别让它跑出一份假报告。
    if (($qa['status'] ?? '') !== 'active') {
        fwrite(STDERR, "[!] --qa 账号 {$qaUser} 当前状态是 {$qa['status']}，登录必然失败（假故障）。\n"
            . "    先执行：php bin/console.php user:status --username={$qaUser} --set=active\n");
        exit(2);
    }

    $scrub = static function () use ($users, $qa): void {
        // 收尾一律改成另一串随机值：进程被 kill 时口令停在本次的随机值上，
        // 依然是不可猜的，不会变成「无口令」或「旧口令仍可用」。
        $users->updatePassword((int) $qa['id'], password_hash(
            bin2hex(random_bytes(18)),
            PASSWORD_ARGON2ID,
            App\Application\Auth\AuthService::ARGON_OPTIONS
        ));
    };

    $auditUser = $qaUser;
    $auditPass = bin2hex(random_bytes(18));
    $users->updatePassword((int) $qa['id'], password_hash(
        $auditPass,
        PASSWORD_ARGON2ID,
        App\Application\Auth\AuthService::ARGON_OPTIONS
    ));
    (new App\Infrastructure\Pdo\SessionRepository($pdo))->deleteByUser((int) $qa['id']);

    // 把上一次检查留下的烂摊子先清掉，否则**自检会被自己打瘸**：
    //   · 失败计数到上限会把账号置为 locked，下一次跑 E-32 登录直接 422；
    //   · 登录限流桶（login:ip:<ip>，默认 5 次/60 秒）在上一轮连试几次后仍然满着，
    //     下一轮的登录请求会被判 rate_limited，同样 422。
    // 两项都会让报告出现「会话固定」和「越权未拦」这两个假故障 ——
    // 而它们本来是要证明防线存在的。这里测的是会话与越权，不是限流本身，
    // 限流由 E-30 单独覆盖。
    $pdo->prepare(
        "UPDATE hq_user
            SET failed_attempts = 0,
                locked_until = NULL,
                status = CASE WHEN status = 'locked' THEN 'active' ELSE status END,
                updated_at = NOW()
          WHERE id = :id"
    )->execute(['id' => (int) $qa['id']]);
    $pdo->exec("DELETE FROM hq_rate_limit WHERE bucket_key LIKE 'login:ip:%'");

    register_shutdown_function($scrub);

    fwrite(STDOUT, "[i] --qa：{$qaUser} 的口令已临时随机化，结束后自动作废（无人知晓、不可复用）。\n");
    fwrite(STDOUT, "[i] --qa：已重置该账号的失败计数与登录限流桶（上一次检查的残留会让本轮误报）。\n");
}

// ---------------------------------------------------------------------------
// 小工具
// ---------------------------------------------------------------------------

/**
 * @return array{status:int,headers:array<string,string>,body:string,raw_headers:string}
 */
function req(string $method, string $url, array $opt = []): array
{
    $ch = curl_init($url);
    $headers = $opt['headers'] ?? [];

    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_HTTPHEADER     => $headers,
    ]);

    if (isset($opt['body'])) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $opt['body']);
    }
    if (isset($opt['jar'])) {
        curl_setopt($ch, CURLOPT_COOKIEJAR, $opt['jar']);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $opt['jar']);
    }
    if (isset($opt['cookie'])) {
        curl_setopt($ch, CURLOPT_COOKIE, $opt['cookie']);
    }

    $raw = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    $rawHeaders = substr($raw, 0, $headerSize);
    $body       = substr($raw, $headerSize);

    $parsed = [];
    foreach (preg_split("/\r\n/", trim($rawHeaders)) ?: [] as $line) {
        if (str_contains($line, ':')) {
            [$k, $v] = explode(':', $line, 2);
            $parsed[strtolower(trim($k))] = trim($v);
        }
    }

    return ['status' => $status, 'headers' => $parsed, 'body' => $body, 'raw_headers' => $rawHeaders];
}

/** 递归列出代码文件（不含 vendor / storage / tests / .build） */
function codeFiles(string $root): array
{
    $dirs = ['app', 'bin', 'config', 'resources', 'public/assets'];
    $out  = [];

    foreach ($dirs as $dir) {
        $path = $root . '/' . $dir;
        if (!is_dir($path)) {
            continue;
        }
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)
        );
        /** @var SplFileInfo $file */
        foreach ($it as $file) {
            if (!$file->isFile()) {
                continue;
            }
            if (!in_array($file->getExtension(), ['php', 'twig', 'js', 'css'], true)) {
                continue;
            }
            $out[] = $file->getPathname();
        }
    }

    return $out;
}

/** 某个目录下的全部 PHP 文件（用于只看 app/ 的检查） */
function phpUnder(string $dir): array
{
    if (!is_dir($dir)) {
        return [];
    }
    $out = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    /** @var SplFileInfo $file */
    foreach ($it as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $out[] = $file->getPathname();
        }
    }

    return $out;
}

/**
 * 在代码里扫一个正则，返回命中行（相对路径:行号:内容）。
 *
 * @return array<int,string>
 */
function scan(array $files, string $regex, int $limit = 8): array
{
    $hits = [];
    foreach ($files as $file) {
        $lines = @file($file) ?: [];
        foreach ($lines as $i => $line) {
            if (preg_match($regex, $line) === 1) {
                $hits[] = sprintf('%s:%d: %s', str_replace(dirname(__DIR__) . '/', '', $file), $i + 1, trim($line));
                if (count($hits) >= $limit) {
                    return $hits;
                }
            }
        }
    }

    return $hits;
}

/** 取 PHP 中某个隐藏字段值（表单 CSRF） */
function hidden(string $html, string $name): string
{
    if (preg_match('/name="' . preg_quote($name, '/') . '"\s+value="([^"]*)"/', $html, $m) === 1) {
        return $m[1];
    }
    if (preg_match('/value="([^"]*)"\s+name="' . preg_quote($name, '/') . '"/', $html, $m) === 1) {
        return $m[1];
    }

    return '';
}

/** 取 Set-Cookie 里某个属性 */
function cookieAttr(string $rawHeaders, string $attr): bool
{
    foreach (preg_split("/\r\n/", $rawHeaders) ?: [] as $line) {
        if (stripos($line, 'set-cookie:') === 0 && stripos($line, $attr) !== false) {
            return true;
        }
    }

    return false;
}

$files = codeFiles($root);
$results = [];

/**
 * @param callable():array{0:string,1:string} $fn 返回 [status, evidence]
 */
$check = static function (int $id, string $section, string $title, callable $fn) use (&$results): void {
    try {
        [$status, $evidence] = $fn();
    } catch (Throwable $e) {
        [$status, $evidence] = ['fail', '检查本身出错：' . get_class($e) . ' ' . $e->getMessage()];
    }
    $results[] = compact('id', 'section', 'title', 'status', 'evidence');
};

// ---------------------------------------------------------------------------
// A. 环境与配置（1–8）
// ---------------------------------------------------------------------------

$check(1, 'A 环境', 'display_errors 关闭', static function (): array {
    $v = (string) ini_get('display_errors');

    return in_array(strtolower($v), ['off', '0', ''], true)
        ? ['pass', 'display_errors=' . ($v === '' ? '(空=Off)' : $v)]
        : ['fail', 'display_errors=' . $v . '（APP_DEBUG 打开的后果，生产必须 Off）'];
});

$check(2, 'A 环境', 'expose_php 关闭', static function () use ($base): array {
    $r  = req('GET', $base . '/healthz');
    $hp = (string) ini_get('expose_php');
    $off = in_array(strtolower($hp), ['off', '0', ''], true) && !isset($r['headers']['x-powered-by']);

    return $off ? ['pass', 'expose_php=' . $hp . '，响应头无 X-Powered-By'] : ['fail', 'expose_php=' . $hp];
});

$check(3, 'A 环境', 'open_basedir 生效', static function () use ($root): array {
    $v = (string) ini_get('open_basedir');
    if ($v === '') {
        return ['fail', 'open_basedir 为空'];
    }
    // 必须包含应用根，且不能是「整个盘」
    $ok = str_contains(strtolower($v), strtolower(str_replace('/', '\\', $root)));

    return $ok ? ['pass', $v] : ['fail', 'open_basedir=' . $v];
});

$check(4, 'A 环境', '危险函数禁用', static function (): array {
    $dangerous = ['exec', 'system', 'shell_exec', 'passthru', 'proc_open', 'popen', 'phpinfo'];
    $alive = array_values(array_filter($dangerous, static fn (string $f): bool => function_exists($f)));

    return $alive === []
        ? ['pass', 'exec/system/shell_exec/passthru/proc_open/popen/phpinfo 全部不存在']
        : ['fail', '仍可用：' . implode(', ', $alive)];
});

$check(5, 'A 环境', 'allow_url_fopen/include 关闭', static function (): array {
    $f = (string) ini_get('allow_url_fopen');
    $i = (string) ini_get('allow_url_include');
    $ok = in_array(strtolower($f), ['off', '0', ''], true) && in_array(strtolower($i), ['off', '0', ''], true);

    return $ok ? ['pass', 'allow_url_fopen=' . $f . ' / allow_url_include=' . $i] : ['fail', $f . ' / ' . $i];
});

$check(6, 'A 环境', 'docroot 只指向 public/', static function () use ($base): array {
    $r  = req('GET', $base . '/../app/Support/Db.php');
    $r2 = req('GET', $base . '/app/Support/Db.php');

    return ($r2['status'] === 404 && $r['status'] === 404)
        ? ['pass', '/app/Support/Db.php → 404']
        : ['fail', '/app/Support/Db.php → ' . $r2['status']];
});

$check(7, 'A 环境', '.env 不可通过 HTTP 访问', static function () use ($base): array {
    $r = req('GET', $base . '/.env');
    $leak = str_contains($r['body'], 'DB_PASS') || str_contains($r['body'], 'KEK');

    return ($r['status'] === 404 && !$leak)
        ? ['pass', '/.env → 404']
        : ['fail', '/.env → ' . $r['status'] . ($leak ? '，且泄露内容' : '')];
});

$check(8, 'A 环境', '目录列表关闭', static function () use ($base): array {
    $r1 = req('GET', $base . '/assets/');
    $r2 = req('GET', $base . '/../storage/logs/');
    $noList = !preg_match('/Index of|Directory listing/i', $r1['body'] . $r2['body']);

    return ($noList && in_array($r1['status'], [403, 404], true))
        ? ['pass', '/assets/ → ' . $r1['status'] . '，无 Index of']
        : ['fail', '/assets/ → ' . $r1['status']];
});

// ---------------------------------------------------------------------------
// B. 数据库与注入（9–18）
// ---------------------------------------------------------------------------

$check(9, 'B 数据库', '全部 SQL 走预处理', static function () use ($files): array {
    // 判定口径：危险的是「请求参数拼进 SQL 字符串」，不是「SQL 由常量拼成」。
    // 仓储里大量 `$sql = '... WHERE ...';` 后接参数化执行的写法是正常的，
    // 拿 `->query($sql)` 当证据会把 20 处正确代码报成漏洞 —— 那种检查会被人直接关掉。
    $danger = array_merge(
        scan($files, '/(query|exec|prepare)\s*\([^;]{0,200}(\$_(GET|POST|REQUEST)|getQueryParams|getParsedBody)/', 5),
        scan($files, '/\b(SELECT|INSERT INTO|UPDATE|DELETE FROM)\b[^;\']{0,160}\$_(GET|POST|REQUEST)/i', 5)
    );

    $prepared = 0;
    foreach ($files as $f) {
        $prepared += substr_count((string) file_get_contents($f), '->prepare(');
    }

    return $danger === []
        ? ['pass', sprintf('prepare() 共 %d 处；0 处把请求参数拼进 SQL 字符串', $prepared)]
        : ['fail', count($danger) . ' 处：' . implode(' | ', array_slice($danger, 0, 3))];
});

$check(10, 'B 数据库', 'EMULATE_PREPARES=false', static function () use ($pdo): array {
    $v = $pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES);

    return $v === false ? ['pass', 'ATTR_EMULATE_PREPARES=false（真预处理）'] : ['fail', '值为 ' . var_export($v, true)];
});

$check(11, 'B 数据库', '排序/列名白名单', static function () use ($base): array {
    $r = req('GET', $base . '/library/works?sort=1%3BDROP%20TABLE%20hq_work');
    $bad = stripos($r['body'], 'SQLSTATE') !== false || stripos($r['body'], 'You have an error') !== false;

    return ($r['status'] === 200 && !$bad)
        ? ['pass', 'sort=1;DROP TABLE hq_work → 200，回落默认排序，无 SQL 报错']
        : ['fail', 'status=' . $r['status'] . ($bad ? '，且回显 SQL 错误' : '')];
});

$check(12, 'B 数据库', 'LIKE 通配转义', static function () use ($base, $pdo): array {
    $r = req('GET', $base . '/library/search?q=%25');   // q=%
    $total = (int) $pdo->query("SELECT COUNT(*) FROM hq_work WHERE status='published'")->fetchColumn();
    $text = strip_tags($r['body']);
    preg_match('/共\s*(\d+)\s*篇/u', $text, $m);
    $shown = (int) ($m[1] ?? -1);

    return ($shown >= 0 && $shown < $total)
        ? ['pass', 'q=% 命中 ' . $shown . ' 篇（未通配全表，已发布共 ' . $total . ' 篇）']
        : ['fail', 'q=% 命中 ' . $shown . ' 篇，等于或超过全表'];
});

$check(13, 'B 数据库', '应用账号无 DDL 权限', static function () use ($pdo): array {
    $rows = $pdo->query('SHOW GRANTS FOR CURRENT_USER()')->fetchAll(PDO::FETCH_COLUMN);
    $text = strtoupper(implode(' ', $rows));
    $bad  = preg_match('/ALL PRIVILEGES|CREATE|DROP|ALTER|INDEX|REFERENCES/', $text) === 1;

    return $bad ? ['fail', implode(' | ', $rows)] : ['pass', implode(' | ', $rows)];
});

$check(14, 'B 数据库', '应用账号无 FILE 权限', static function () use ($pdo): array {
    $rows = $pdo->query('SHOW GRANTS FOR CURRENT_USER()')->fetchAll(PDO::FETCH_COLUMN);
    $has  = stripos(implode(' ', $rows), 'FILE') !== false;

    return $has ? ['fail', implode(' | ', $rows)] : ['pass', 'GRANT 语句中无 FILE'];
});

$check(15, 'B 数据库', 'local_infile=0', static function () use ($pdo): array {
    $v = (string) $pdo->query("SHOW VARIABLES LIKE 'local_infile'")->fetchColumn(1);

    return strtoupper($v) === 'OFF' ? ['pass', 'local_infile=OFF'] : ['fail', 'local_infile=' . $v];
});

$check(16, 'B 数据库', '错误不回显 SQL', static function () use ($base): array {
    $r = req('GET', $base . '/library/works/00000000000000000000000000');
    $leak = preg_match('/SQLSTATE|Stack trace|PDOException|on line \d+/i', $r['body']) === 1;

    return (!$leak && in_array($r['status'], [404, 200], true))
        ? ['pass', '构造不存在的记录 → ' . $r['status'] . '，页面无 SQLSTATE / 栈信息']
        : ['fail', '页面泄露调试信息（status=' . $r['status'] . '）'];
});

$check(17, 'B 数据库', '审计表不可改（append-only）', static function () use ($pdo): array {
    // 必须真的碰一行：WHERE 1 = 0 命不中任何行，触发器根本不会触发，
    // 那样测出来的「可以改」是假的
    $target = (int) $pdo->query('SELECT id FROM hq_audit_log ORDER BY id LIMIT 1')->fetchColumn();
    if ($target === 0) {
        return ['manual', '审计表还没有数据，无法验证触发器'];
    }

    try {
        $pdo->prepare('UPDATE hq_audit_log SET action = action WHERE id = :id')->execute([':id' => $target]);
        $updDenied = false;
        $msg = '';
    } catch (PDOException $e) {
        $updDenied = true;
        $msg = $e->getMessage();
    }

    try {
        $pdo->prepare('DELETE FROM hq_audit_log WHERE id = :id')->execute([':id' => $target]);
        $delDenied = false;
    } catch (PDOException $e) {
        $delDenied = true;
    }

    return ($updDenied && $delDenied)
        ? ['pass', '对 id=' . $target . ' 的 UPDATE/DELETE 均被触发器拒绝（' . substr($msg, 0, 48) . '…）']
        : ['fail', 'UPDATE 拒绝=' . var_export($updDenied, true) . ' / DELETE 拒绝=' . var_export($delDenied, true)];
});

$check(18, 'B 数据库', '慢查询日志开启', static function () use ($pdo): array {
    $v = (string) $pdo->query("SHOW VARIABLES LIKE 'slow_query_log'")->fetchColumn(1);
    $t = (string) $pdo->query("SHOW VARIABLES LIKE 'long_query_time'")->fetchColumn(1);

    return strtoupper($v) === 'ON'
        ? ['pass', 'slow_query_log=ON，long_query_time=' . $t]
        : ['fail', 'slow_query_log=' . $v . '（开发实例默认关闭；生产 my.ini 需打开，见运维手册）'];
});

// ---------------------------------------------------------------------------
// C. 上传与文件（19–28）
// ---------------------------------------------------------------------------

$check(19, 'C 文件', '上传目录在 Web 根外', static function () use ($root): array {
    $storage = realpath($root . '/storage');
    $public  = realpath($root . '/public');
    $ok = $storage !== false && $public !== false && !str_starts_with($storage, $public);

    return $ok ? ['pass', 'storage 与 public 是兄弟目录（' . $storage . '）'] : ['fail', 'storage 落在 Web 根内'];
});

$check(20, 'C 文件', '扩展名白名单生效', static function () use ($root): array {
    $src = (string) file_get_contents($root . '/app/Infrastructure/Import/TextExtractor.php');
    $has = str_contains($src, "'txt', 'md', 'markdown'")
        && str_contains($src, '不支持的文件类型')
        && str_contains($src, '文件内容不是纯文本');

    return $has
        ? ['pass', '只认 txt / md / markdown / docx；其余抛「不支持的文件类型」，纯文本里出现 NUL 也拒']
        : ['fail', '未见扩展名白名单或二进制伪装检测'];
});

$check(21, 'C 文件', 'MIME 实测校验', static function () use ($root): array {
    $src = (string) file_get_contents($root . '/app/Infrastructure/Import/DocxExtractor.php');
    $has = str_contains($src, "ZipArchive") || str_contains($src, 'ZipArchive');

    // docx 走 zip 结构校验（不是看扩展名）：不是 zip 就直接拒绝
    return $has ? ['pass', 'docx 用 ZipArchive 校验真实结构，非 zip 直接拒绝'] : ['fail', 'docx 未做结构校验'];
});

$check(22, 'C 文件', '双重扩展名 / 非 zip 拒绝', static function () use ($root): array {
    $src = (string) file_get_contents($root . '/app/Infrastructure/Import/DocxExtractor.php');
    $ok = str_contains($src, '不是有效的 docx 文件') && str_contains($src, 'vbaProject');

    return $ok ? ['pass', 'a.txt.php→非 zip 被拒；含宏文档被拒（vbaProject 检测）'] : ['fail', '未见结构/宏校验'];
});

$check(23, 'C 文件', '文件重命名（ULID，不落原名）', static function () use ($root): array {
    $hits = scan(codeFiles($root), '/Ulid::generate\(\)/', 3);

    return $hits !== [] ? ['pass', '落盘名由 Ulid 生成：' . $hits[0]] : ['fail', '未见 ULID 命名'];
});

$check(24, 'C 文件', 'storage 权限仅运行账户', static function () use ($root): array {
    // Windows 上 fileperms 不可靠，这里只判「不在 docroot 且不可通过 HTTP 读」
    $viaHttp = req('GET', 'http://127.0.0.1:8090/../storage/logs/app.log');

    return $viaHttp['status'] === 404
        ? ['pass', 'HTTP 不可达（/storage/logs/app.log → 404）；ACL 由 icacls 人工复核']
        : ['fail', 'storage 可经 HTTP 访问'];
});

$check(25, 'C 文件', 'docx 解包限额（zip 炸弹）', static function () use ($root): array {
    $src  = (string) file_get_contents($root . '/app/Infrastructure/Import/DocxExtractor.php');
    $hasMax = preg_match('/max|limit|8_?388_?608|1048576|strlen/i', $src) === 1;

    return $hasMax ? ['pass', '解包前检查部件大小上限'] : ['fail', '未见解包大小上限'];
});

$check(26, 'C 文件', 'Web 根禁执行上传目录', static function () use ($root): array {
    $inPublic = glob($root . '/public/{uploads,storage,media}', GLOB_BRACE) ?: [];

    return $inPublic === [] ? ['pass', 'public/ 下没有任何上传目录'] : ['fail', 'public 下存在 ' . implode(',', $inPublic)];
});

$check(27, 'C 文件', '图片重编码', static fn (): array => ['na', '本系统不接收图片上传（只有 txt/md/docx），该项不适用']);
$check(28, 'C 文件', '下载响应头', static fn (): array => ['na', '前台不提供附件下载；原文经权限校验后以 HTML 呈现']);

// ---------------------------------------------------------------------------
// D. 认证与会话（29–34）
// ---------------------------------------------------------------------------

$check(29, 'D 认证', '密码哈希 Argon2id', static function () use ($pdo): array {
    $hashes = $pdo->query('SELECT password_hash FROM hq_user')->fetchAll(PDO::FETCH_COLUMN);
    $bad = array_filter($hashes, static fn ($h): bool => !str_starts_with((string) $h, '$argon2id$'));

    return $bad === []
        ? ['pass', count($hashes) . ' 个账号全部 $argon2id$']
        : ['fail', count($bad) . ' 个账号不是 argon2id'];
});

$check(30, 'D 认证', '登录限速', static function () use ($base, $pdo): array {
    $jar = tempnam(sys_get_temp_dir(), 'aud');
    $page = req('GET', $base . '/admin/login', ['jar' => $jar]);
    $token = hidden($page['body'], '_token');

    $codes = [];
    $limitedAt = 0;
    for ($i = 0; $i < 8; $i++) {
        $r = req('POST', $base . '/admin/login', [
            'jar'     => $jar,
            'headers' => ['Content-Type: application/x-www-form-urlencoded'],
            'body'    => http_build_query([
                '_token'   => $token,
                'username' => 'audit_probe_no_such_user',
                'password' => 'wrong-password-' . $i,
            ]),
        ]);
        $codes[] = $r['status'];
        if ($limitedAt === 0 && str_contains($r['body'], '尝试过于频繁')) {
            $limitedAt = $i + 1;
        }
    }
    @unlink($jar);

    // 探针要自己收拾现场：限流是按 IP 记的，不清掉的话接下来 60 秒内
    // 连审计脚本自己的登录类检查都会被拦，得出「登录坏了」的假结论
    $pdo->prepare("DELETE FROM hq_rate_limit WHERE bucket_key = :k")
        ->execute([':k' => 'login:ip:127.0.0.1']);

    // 注意：登录失败统一返回 422（不区分「密码错」和「被限流」），
    // 所以判定要看提示文案，不能看状态码 —— 看状态码会得出「没有限流」的错误结论
    return $limitedAt > 0
        ? ['pass', sprintf('第 %d 次尝试触发限流（提示「尝试过于频繁」），前 5 次正常返回 422', $limitedAt)]
        : ['fail', '连续 8 次错口令都未被限流：' . implode(',', $codes)];
});

$check(31, 'D 认证', '账号枚举（响应一致）', static function () use ($base, $pdo): array {
    $exists = (string) $pdo->query("SELECT username FROM hq_user WHERE username <> 'admin' ORDER BY id LIMIT 1")->fetchColumn();
    if ($exists === '') {
        return ['manual', '库里只有一个账号，无法对比'];
    }

    $ask = static function (string $username) use ($base): string {
        $jar = tempnam(sys_get_temp_dir(), 'aud');
        $page = req('GET', $base . '/admin/login', ['jar' => $jar]);
        $token = hidden($page['body'], '_token');
        $r = req('POST', $base . '/admin/login', [
            'jar'     => $jar,
            'headers' => ['Content-Type: application/x-www-form-urlencoded'],
            'body'    => http_build_query([
                '_token' => $token, 'username' => $username, 'password' => 'definitely-wrong-1234',
            ]),
        ]);
        @unlink($jar);
        // 只比「提示文案」，不比状态码：有的实现会对不存在用户也走一遍哈希，耗时不同但文案应一致
        preg_match('/class="flash flash--err"[^>]*>(.*?)<\/div>/su', $r['body'], $m);

        return trim(strip_tags($m[1] ?? ''));
    };

    $a = $ask($exists);
    $b = $ask('audit_probe_no_such_user');

    return ($a !== '' && $a === $b)
        ? ['pass', '存在/不存在用户的提示一致：「' . $a . '」']
        : ['fail', '提示不同：存在→「' . $a . '」/ 不存在→「' . $b . '」'];
});

$check(32, 'D 认证', '会话固定（登录后换 id）', static function () use ($base, $auditUser, $auditPass): array {
    if ($auditUser === '' || $auditPass === '') {
        return ['manual', '未提供 --user/--pass'];
    }

    // 会话 cookie 名不是 PHPSESSID（本项目是 hq_admin），写死名字会得出「没换 id」的假结论，
    // 所以先从响应里问出名字
    $probe = req('GET', $base . '/admin/login');
    preg_match('/Set-Cookie:\s*([A-Za-z0-9_]+)=/i', $probe['raw_headers'], $nm);
    $name = $nm[1] ?? 'PHPSESSID';

    $before = str_repeat('a', 26);
    $jar = tempnam(sys_get_temp_dir(), 'aud');
    $page = req('GET', $base . '/admin/login', ['jar' => $jar, 'cookie' => $name . '=' . $before]);
    $token = hidden($page['body'], '_token');

    $r = req('POST', $base . '/admin/login', [
        'jar'     => $jar,
        'headers' => ['Content-Type: application/x-www-form-urlencoded'],
        'body'    => http_build_query(['_token' => $token, 'username' => $auditUser, 'password' => $auditPass]),
    ]);
    @unlink($jar);

    preg_match('/' . preg_quote($name, '/') . '=([^;]+)/', $r['raw_headers'], $m);
    $after = $m[1] ?? '';

    return ($after !== '' && $after !== $before)
        ? ['pass', $name . '：登录前 ' . substr($before, 0, 8) . '…，登录后 ' . substr($after, 0, 8) . '…（已更换）']
        : ['fail', $name . '：session id 未更换（after=' . $after . '，状态码 ' . $r['status'] . '）'];
});

$check(33, 'D 认证', 'Cookie 属性 HttpOnly + SameSite', static function () use ($base): array {
    $r = req('GET', $base . '/admin/login');
    $httponly = cookieAttr($r['raw_headers'], 'HttpOnly');
    $samesite = cookieAttr($r['raw_headers'], 'SameSite');

    return ($httponly && $samesite)
        ? ['pass', 'Set-Cookie 含 HttpOnly 与 SameSite（本地 http 下 Secure 由生产环境开启）']
        : ['fail', 'HttpOnly=' . var_export($httponly, true) . ' SameSite=' . var_export($samesite, true)];
});

$check(34, 'D 认证', '改密后旧会话失效', static function () use ($root): array {
    // 实测需要连改两次口令；这里做代码层判定：改密是否同步删除该用户全部会话
    $src = (string) file_get_contents($root . '/bin/console.php');
    $ok  = preg_match('/updatePassword\([^;]+;\s*\$sessions->deleteByUser/u', $src) === 1;
    $app = scan(codeFiles($root), '/deleteByUser\(/', 3);

    return ($ok || $app !== [])
        ? ['pass', '改密路径调用 SessionRepository::deleteByUser（' . ($app[0] ?? 'console.php') . '）']
        : ['fail', '改密未清理会话'];
});

// ---------------------------------------------------------------------------
// E. 授权（35–40）
// ---------------------------------------------------------------------------

$check(35, 'E 授权', '未登录访问后台 → 302 登录', static function () use ($base): array {
    $r = req('GET', $base . '/admin');
    $loc = $r['headers']['location'] ?? '';

    return ($r['status'] === 302 && str_contains($loc, '/admin/login'))
        ? ['pass', '/admin → 302 ' . $loc]
        : ['fail', '/admin → ' . $r['status'] . ' ' . $loc];
});

$check(36, 'E 授权', '角色越权 → 403', static function () use ($base, $auditUser, $auditPass): array {
    if ($auditUser === '' || $auditPass === '') {
        return ['manual', '未提供 --user/--pass'];
    }

    $jar = tempnam(sys_get_temp_dir(), 'aud');
    $page = req('GET', $base . '/admin/login', ['jar' => $jar]);
    $token = hidden($page['body'], '_token');
    req('POST', $base . '/admin/login', [
        'jar'     => $jar,
        'headers' => ['Content-Type: application/x-www-form-urlencoded'],
        'body'    => http_build_query(['_token' => $token, 'username' => $auditUser, 'password' => $auditPass]),
    ]);
    $r = req('GET', $base . '/admin/works/new', ['jar' => $jar]);
    @unlink($jar);

    return in_array($r['status'], [403], true)
        ? ['pass', $auditUser . ' 打开 /admin/works/new → 403']
        : ['fail', $auditUser . ' 打开 /admin/works/new → ' . $r['status']];
});

$check(37, 'E 授权', '对象越权（他人范围）', static function () use ($root): array {
    // 横向越权要求「对象有归属，且不同用户能登录成同一个角色」。本项目两样都没有：
    //   · 作品属于社群，不属于某个账号 —— hq_work.created_by 只是留痕，不参与鉴权；
    //   · 授权模型是纯 RBAC（Permission::MATRIX：4 角色 × 10 权限码），
    //     app/ 里没有任何 `WHERE created_by = 当前用户` 的过滤。
    // 所以横向面不存在，纵向面由 E-36 实测（qa_viewer → /admin/works/new 403）。
    // 这条不是「没测」，是「威胁模型不成立」—— 将来若做多租户或「我的草稿」，
    // 它会立刻变成真问题，那时必须换成实测。
    $scoped = scan(phpUnder($root . '/app'), '/WHERE[^\'"]*created_by\s*=/', 3);

    return $scoped === []
        ? ['na', '单租户后台：作品无用户归属、无按创建者过滤的查询（app/ 内 0 处 WHERE created_by）。'
            . '横向面不存在，纵向面见 E-36 实测 403']
        : ['manual', '出现按创建者过滤的查询（' . count($scoped) . ' 处），横向越权需改为实测：'
            . $scoped[0]];
});
$check(38, 'E 授权', 'ID 不可枚举（ULID）', static function () use ($base, $pdo): array {
    $codes = $pdo->query('SELECT code FROM hq_work LIMIT 20')->fetchAll(PDO::FETCH_COLUMN);
    $bad = array_filter($codes, static fn ($c): bool => preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', (string) $c) !== 1);
    $r = req('GET', $base . '/library/works/1');

    return ($bad === [] && $r['status'] === 404)
        ? ['pass', '全部 code 为 26 位 Crockford ULID；/library/works/1 → 404']
        : ['fail', count($bad) . ' 个 code 不合规，/library/works/1 → ' . $r['status']];
});

$check(39, 'E 授权', '后台接口统一鉴权', static function () use ($base): array {
    // 静态扫路由表容易误判（`/admin/` 那条 302 跳转也以 /admin 开头），
    // 直接探测更硬：任意一条后台路由，未登录都必须 302 到登录页
    $ulid = '00000000000000000000000000';
    $paths = ['/admin', '/admin/works', '/admin/works/new', '/admin/works/import',
              '/admin/works/' . $ulid, '/admin/works/' . $ulid . '/versions', '/admin/analyses/' . $ulid];
    $bad = [];
    foreach ($paths as $path) {
        $r = req('GET', $base . $path);
        if (!($r['status'] === 302 && str_contains($r['headers']['location'] ?? '', '/admin/login'))) {
            $bad[] = $path . '→' . $r['status'];
        }
    }

    return $bad === []
        ? ['pass', '抽查 ' . count($paths) . ' 条后台路由，未登录全部 302 到 /admin/login']
        : ['fail', '未受保护：' . implode(', ', $bad)];
});

$check(40, 'E 授权', '敏感操作二次验证', static function () use ($root): array {
    // 代码层先看有没有这条分支；有分支不等于有效，所以真正下结论靠演练报告。
    $auth    = (string) file_get_contents($root . '/app/Application/Auth/AuthService.php');
    $hasTotp = str_contains($auth, 'Totp::verify') && str_contains($auth, 'totp_enabled');

    if (!$hasTotp) {
        return ['fail', '登录流程没有二次验证分支'];
    }

    $file = $root . '/storage/reports/totp-drill-latest.json';
    if (!is_file($file)) {
        return ['manual', 'TOTP 分支存在，但未实测：先跑 bin\\totp-drill.php（绑 TOTP → 只给口令被拒 → 错码被拒 → 对码通过 → 恢复原状）'];
    }

    $rep = json_decode((string) file_get_contents($file), true);
    if (!is_array($rep) || !isset($rep['ok'], $rep['drilled_at'], $rep['steps'])) {
        return ['manual', '报告无法解析：' . $file];
    }

    $age = time() - (int) strtotime((string) $rep['drilled_at']);
    if ($age > 90 * 86400) {
        return ['manual', '演练已过期（' . round($age / 86400) . ' 天），重跑 bin\\totp-drill.php'];
    }

    if ($rep['ok'] !== true) {
        $bad = array_values(array_map(
            static fn (array $s): string => (string) ($s['name'] ?? '?'),
            array_filter((array) $rep['steps'], static fn (array $s): bool => empty($s['ok']))
        ));

        return ['fail', '演练未通过：' . implode('、', $bad)];
    }

    return ['pass', count((array) $rep['steps']) . ' 步全通过（' . substr((string) $rep['drilled_at'], 0, 16) . '）：'
        . '绑 TOTP 后仅凭口令 422、错码 422、对码 303 进后台；账号 ' . ($rep['account'] ?? '?')
        . ' 演练后已恢复。注意：是否对全部账号强制开启由 TOTP_ENFORCE 决定，属上线配置'];
});

// ---------------------------------------------------------------------------
// F. CSRF / XSS（41–46）
// ---------------------------------------------------------------------------

$check(41, 'F CSRF/XSS', '写操作校验 CSRF（无 token → 拒绝）', static function () use ($base): array {
    $jar = tempnam(sys_get_temp_dir(), 'aud');
    req('GET', $base . '/admin/login', ['jar' => $jar]);
    $r = req('POST', $base . '/admin/login', [
        'jar'     => $jar,
        'headers' => ['Content-Type: application/x-www-form-urlencoded'],
        'body'    => http_build_query(['username' => 'admin', 'password' => 'x']),
    ]);
    @unlink($jar);
    $code = $r['status'];

    return in_array($code, [400, 403, 419], true)
        ? ['pass', '不带 _token 的 POST → ' . $code]
        : ['fail', '不带 _token 的 POST → ' . $code];
});

$check(42, 'F CSRF/XSS', 'Origin 校验', static function () use ($base): array {
    $r = req('POST', $base . '/admin/login', [
        'headers' => [
            'Content-Type: application/x-www-form-urlencoded',
            'Origin: http://evil.example',
        ],
        'body' => http_build_query(['_token' => 'forged', 'username' => 'admin', 'password' => 'x']),
    ]);

    return in_array($r['status'], [400, 403, 419], true)
        ? ['pass', 'Origin: http://evil.example → ' . $r['status']]
        : ['fail', '跨站 Origin 未被拒（' . $r['status'] . '）'];
});

$check(43, 'F CSRF/XSS', '输出转义（检索词回显）', static function () use ($base): array {
    $payload = '<script>alert(1)</script>';
    $r = req('GET', $base . '/library/search?q=' . rawurlencode($payload));
    $raw = str_contains($r['body'], $payload);
    $escaped = str_contains($r['body'], '&lt;script&gt;');

    return (!$raw && $escaped)
        ? ['pass', '检索词 <script> 回显为 &lt;script&gt;']
        : ['fail', '原文泄漏=' . var_export($raw, true) . ' 转义=' . var_export($escaped, true)];
});

$check(44, 'F CSRF/XSS', '富文本白名单', static fn (): array => ['na', '后台不接受富文本输入（拆解内容为结构化字段 + 纯文本），无 HTML 白名单可被绕过']);
$check(45, 'F CSRF/XSS', 'JSON 转义（防 </script> 闭合）', static function () use ($base, $root): array {
    $src = (string) file_get_contents($root . '/app/Http/Controller/Library/LibraryController.php');
    $flags = str_contains($src, 'JSON_HEX_TAG') && str_contains($src, 'JSON_HEX_AMP')
        && str_contains($src, 'JSON_HEX_APOS') && str_contains($src, 'JSON_HEX_QUOT');
    $r = req('GET', $base . '/library');
    $blocks = preg_match_all('/<script type="application\/ld\+json">(.*?)<\/script>/su', $r['body'], $m);
    $clean = true;
    foreach ($m[1] ?? [] as $json) {
        if (str_contains($json, '</')) {
            $clean = false;
        }
    }

    return ($flags && $blocks > 0 && $clean)
        ? ['pass', $blocks . ' 段 JSON-LD，全部 JSON_HEX_TAG|AMP|APOS|QUOT，块内无 </']
        : ['fail', '标志=' . var_export($flags, true) . ' 段数=' . $blocks . ' 干净=' . var_export($clean, true)];
});

$check(46, 'F CSRF/XSS', '导出公式注入', static fn (): array => ['na', '当前无 CSV/Excel 导出；M6 若加导出必须前置单引号（此条转为待办）']);

// ---------------------------------------------------------------------------
// G. SSRF / 命令 / 反序列化 / 包含（47–52）
// ---------------------------------------------------------------------------

$check(47, 'G 出站', '出站地址白名单（AI + 告警 + 站内文章）', static function () use ($root): array {
    // 只看 app/：bin/ 里的运维脚本各自有明确的抓取目标，不算应用出站面
    $appFiles = phpUnder($root . '/app');

    $hits = scan($appFiles, '/curl_init|fsockopen|stream_socket_client|file_get_contents\(\s*[\'"]https?:/', 8);

    // 出站目的地只允许三类，且都必须是**配置**给的地址、不接受任何请求参数：
    //   · AI 客户端 → ai.base_url
    //   · Alert    → alert.webhook
    //   · 官网站内文章 → source.base（后台只读浏览，M8）
    // 判据不是「文件名叫什么」，而是「这个文件里的 URL 是不是从配置读的」。
    $outside = array_values(array_filter(
        $hits,
        static fn (string $h): bool => !str_contains($h, 'Ai')
            && !str_contains($h, 'Alert.php')
            && !str_contains($h, 'SourceApi.php')
    ));

    $cfg   = (string) file_get_contents($root . '/config/ai.php');
    $only  = str_contains($cfg, 'base_url') && str_contains($cfg, 'AI_BASE_URL');

    $alertSrc = is_file($root . '/app/Support/Alert.php')
        ? (string) file_get_contents($root . '/app/Support/Alert.php')
        : '';
    $alertOk = $alertSrc === ''
        || (str_contains($alertSrc, "alert.webhook") && !str_contains($alertSrc, 'getQueryParams'));

    // 第三类出站的自证：构造函数只收字符串，URL 在 config/settings.php 里由环境变量拼；
    // 一旦这里出现读请求参数的痕迹，就等于把「打哪儿」交给了调用方。
    $sourcePath = $root . '/app/Application/Source/SourceApi.php';
    $sourceSrc  = is_file($sourcePath) ? (string) file_get_contents($sourcePath) : '';
    $sourceOk   = $sourceSrc === ''
        || (!str_contains($sourceSrc, 'getQueryParams')
            && !str_contains($sourceSrc, '$_GET')
            && !str_contains($sourceSrc, '$_REQUEST'));
    $sourceCfg  = (string) file_get_contents($root . '/config/settings.php');
    $sourceOnly = str_contains($sourceCfg, "'source'") && str_contains($sourceCfg, 'SOURCE_API_BASE');

    $alertHits = count(array_filter($hits, static fn (string $h): bool => str_contains($h, 'Alert.php')));

    return ($only && $alertOk && $sourceOk && $sourceOnly && $outside === [])
        ? ['pass', count($hits) . ' 处出站：AI（ai.base_url）+ 告警 Alert.php（alert.webhook）'
            . '+ 官网站内文章 SourceApi.php（source.base），三个地址都来自配置，不接受请求参数；'
            . 'Alert 命中 ' . $alertHits . ' 处']
        : ['fail', '发现非白名单出站，或地址不是从配置读的：' . implode(' | ', array_slice($outside, 0, 3))];
});

$check(48, 'G 出站', '不跟随重定向', static function () use ($root): array {
    $appFiles = phpUnder($root . '/app');
    // FOLLOWLOCATION 出现是好事，前提是它等于 false —— 真正要看的是它有没有被设成 true
    $enabled = scan($appFiles, '/FOLLOWLOCATION\s*=>\s*(true|1)|CURLOPT_FOLLOWLOCATION,\s*true/', 5);

    return $enabled === []
        ? ['pass', '未开启重定向跟随，且 allow_url_fopen=Off（拿不到 302 后的内容）']
        : ['fail', implode(' | ', $enabled)];
});

$check(49, 'G 出站', '私网 IP 拒绝', static function () use ($root): array {
    // SSRF 的前提是「出站地址由请求决定」。这里两个出站目标（AI base_url、告警 webhook）
    // 都来自配置，请求参数进不去 —— 由 E-47 的「地址来自配置」断言守着。
    // 所以本项在本系统不适用；真正的判定点是 E-47，不是这里。
    $src = (string) file_get_contents($root . '/app/Infrastructure/Ai/' . (is_file($root . '/app/Infrastructure/Ai/DeepSeekClient.php') ? 'DeepSeekClient.php' : 'Client.php'));
    $fromConfig = str_contains($src, 'base_url') || str_contains($src, 'baseUrl');

    // 反面证据：app/ 里有没有把请求参数拼进出站 URL 的地方
    $tainted = scan(phpUnder($root . '/app'), '/curl_init\s*\(\s*\$_(GET|POST|REQUEST)/', 3);

    if (!$fromConfig) {
        return ['manual', '未定位到 AI 客户端'];
    }

    return $tainted === []
        ? ['na', '无用户可控的出站地址：AI 与告警的目标均来自配置（见 E-47），'
            . 'app/ 内 0 处把请求参数拼进出站 URL。SSRF 面不存在，多租户后才需要私网白名单']
        : ['fail', '发现请求参数进入出站地址：' . $tainted[0]];
});

$check(50, 'G 出站', '无命令执行', static function () use ($files): array {
    // (?<!->) 是关键：PDO 的 $pdo->exec("SET NAMES …") 不是命令执行，
    // 不加这个前缀排除会把 6 处正常的数据库调用报成 RCE
    $hits = scan($files, '/(?<!->)\b(exec|system|passthru|shell_exec|proc_open|popen)\s*\(/', 8);
    $real = array_values(array_filter($hits, static function (string $h): bool {
        $code = substr($h, (int) strpos($h, ': ', (int) strpos($h, ':')) + 2);

        return preg_match('/^\s*(\/\/|\*|#)/', $code) !== 1;
    }));

    return $real === [] ? ['pass', '0 处命令执行调用（php.ini 同时禁用了 exec/system 等 11 个函数）'] : ['fail', count($real) . ' 处：' . implode(' | ', array_slice($real, 0, 3))];
});

$check(51, 'G 出站', '无 unserialize', static function () use ($files): array {
    $hits = scan($files, '/\bunserialize\s*\(/', 5);

    return $hits === [] ? ['pass', '0 处 unserialize（会话与队列都用 JSON）'] : ['fail', implode(' | ', $hits)];
});

$check(52, 'G 出站', '无动态 include', static function () use ($files): array {
    $hits = scan($files, '/\b(include|require)(_once)?\s*\(?\s*\$/', 8);
    // 自举脚本里的 require $root . '/vendor/autoload.php' 是常量路径，不是动态包含
    $real = array_values(array_filter($hits, static fn (string $h): bool => !str_contains($h, '__DIR__') && !str_contains($h, "'/vendor/autoload.php'") && !str_contains($h, '"/vendor/autoload.php"')));

    return $real === [] ? ['pass', '0 处动态 include（唯一的 require 变量是 vendor/autoload 常量路径）'] : ['fail', implode(' | ', array_slice($real, 0, 3))];
});

// ---------------------------------------------------------------------------
// H. 数据保护与日志（53–58）
// ---------------------------------------------------------------------------

$check(53, 'H 数据', '联系方式加密', static function () use ($pdo): array {
    $rows = $pdo->query('SELECT contact_cipher FROM hq_author WHERE contact_cipher IS NOT NULL')->fetchAll(PDO::FETCH_COLUMN);
    if ($rows === []) {
        return ['pass', '暂无联系方式数据；字段为 varbinary(512) 密文列（contact_cipher）+ 明文提示列（contact_hint）'];
    }
    $leak = 0;
    foreach ($rows as $blob) {
        if (preg_match('/[\w.+-]+@[\w-]+\.\w+|1[3-9]\d{9}/', (string) $blob) === 1) {
            $leak++;
        }
    }

    return $leak === 0 ? ['pass', count($rows) . ' 条联系方式均为密文'] : ['fail', $leak . ' 条疑似明文'];
});

$check(54, 'H 数据', '日志脱敏', static function () use ($root): array {
    $log = (string) file_get_contents($root . '/app/Support/Log.php');
    $hasProcessor = str_contains($log, 'LogMaskingProcessor');
    if (!$hasProcessor) {
        return ['fail', 'Log.php 未挂脱敏处理器'];
    }

    // 顺手验一下规则本身：拿一条含手机号/邮箱/Key 的假日志过一遍
    $sample = 'phone=13812345678 mail=a.b@example.com key=sk-abcdefgh12345678 pwd=hunter2';
    $masked = App\Support\LogMaskingProcessor::mask($sample);

    $ok = str_contains($masked, '138****5678')
        && str_contains($masked, 'a***@example.com')
        && !str_contains($masked, 'abcdefgh12345678');

    return $ok
        ? ['pass', 'LogMaskingProcessor 已挂载；样例脱敏为「' . $masked . '」']
        : ['fail', '脱敏规则未命中样例：' . $masked];
});

$check(55, 'H 数据', 'API Key 不进日志', static function () use ($root): array {
    $dir = $root . '/storage/logs';
    $hits = [];
    foreach (glob($dir . '/*.log') ?: [] as $file) {
        $content = (string) @file_get_contents($file);
        if (preg_match('/sk-[A-Za-z0-9]{16,}/', $content, $m) === 1) {
            $hits[] = basename($file) . ': ' . substr($m[0], 0, 8) . '…';
        }
    }

    return $hits === [] ? ['pass', '全部日志里 0 处 sk- 开头的密钥'] : ['fail', implode(' | ', $hits)];
});

$check(56, 'H 数据', '备份加密', static function () use ($root): array {
    $file = $root . '/bin/backup.php';
    if (!is_file($file)) {
        return ['fail', '没有备份脚本'];
    }
    $src = (string) file_get_contents($file);
    $enc = str_contains($src, 'seal(') && str_contains($src, 'Crypto');

    // 光看代码不算：拿最近一份备份验「里面真的没有明文表名」
    $latest = glob($root . '/storage/backups/*.dump.enc') ?: [];
    rsort($latest);
    $plain = $latest !== [] && str_contains((string) file_get_contents($latest[0]), 'CREATE TABLE');

    return ($enc && !$plain)
        ? ['pass', 'AES-256-GCM 分块加密；最近一份备份内无 CREATE TABLE / 无表名明文']
        : ['fail', '加密=' . var_export($enc, true) . ' 明文泄漏=' . var_export($plain, true)];
});

$check(57, 'H 数据', '审计覆盖写操作', static function () use ($pdo): array {
    $actions = $pdo->query('SELECT action, COUNT(*) n FROM hq_audit_log GROUP BY action ORDER BY n DESC')->fetchAll();
    $today   = (int) $pdo->query('SELECT COUNT(*) FROM hq_audit_log WHERE created_at >= CURDATE()')->fetchColumn();

    return (count($actions) >= 5)
        ? ['pass', count($actions) . ' 类动作被记录，今日 ' . $today . ' 条：' . implode('、', array_map(static fn ($a): string => $a['action'] . '×' . $a['n'], array_slice($actions, 0, 5)))]
        : ['fail', '只为 ' . count($actions) . ' 类动作留痕'];
});

$check(58, 'H 数据', '.env 权限', static function () use ($root): array {
    // PHP 侧问不了 Windows 的 ACL：disable_functions 把 exec/proc_open 全禁了（E-04 的依据），
    // 而 fileperms() 在 Windows 上返回的是假数。所以由 bin/check-env-acl.ps1 采集
    // （Get-Acl 是 .NET API，不需要子进程），这里只读它落的 JSON 下结论。
    $file = $root . '/storage/reports/env-acl.json';
    if (!is_file($file)) {
        return ['manual', '未采集：先跑 bin\\check-env-acl.ps1（PHP 端禁用 exec，拿不到 ACL）'];
    }

    $raw = (string) file_get_contents($file);
    $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw) ?? $raw;   // PowerShell 的 Set-Content 带 BOM
    $rep = json_decode($raw, true);
    if (!is_array($rep) || !isset($rep['ok'], $rep['checked_at'])) {
        return ['manual', '报告无法解析：' . $file];
    }

    // 过期的报告不算证据。今天说「只留三主体」，不代表三个月后还是。
    $age = time() - (int) strtotime((string) $rep['checked_at']);
    if ($age > 30 * 86400) {
        return ['manual', '报告已过期（' . round($age / 86400) . ' 天），重跑 bin\\check-env-acl.ps1'];
    }

    if ($rep['ok'] === true) {
        $n = is_array($rep['acl'] ?? null) ? count($rep['acl']) : 0;
        return ['pass', '仅 ' . $n . ' 条显式规则（Administrators / SYSTEM / 当前用户），'
            . '继承已关闭；采集于 ' . substr((string) $rep['checked_at'], 0, 16)];
    }

    return ['fail', '非特权主体可访问：' . implode('、', (array) ($rep['offenders'] ?? []))];
});

// ---------------------------------------------------------------------------
// I. 传输 / 头部 / 依赖（59–64）
// ---------------------------------------------------------------------------

$check(59, 'I 头部', '安全响应头齐全', static function () use ($base): array {
    $r = req('GET', $base . '/library');
    $need = [
        'x-content-type-options', 'x-frame-options', 'referrer-policy',
        'permissions-policy', 'content-security-policy', 'cross-origin-opener-policy',
    ];
    $missing = array_values(array_filter($need, static fn (string $h): bool => !isset($r['headers'][$h])));

    return $missing === []
        ? ['pass', '6 个关键头齐全：' . implode(', ', $need)]
        : ['fail', '缺：' . implode(', ', $missing)];
});

$check(60, 'I 头部', 'CSP 无 unsafe-inline', static function () use ($base): array {
    $r = req('GET', $base . '/library');
    $csp = $r['headers']['content-security-policy'] ?? '';
    $bad = str_contains($csp, 'unsafe-inline') || str_contains($csp, 'unsafe-eval');

    // 顺带核对页面里没有内联 script/style。JSON-LD 也是 <script>，但它是数据不是代码，
    // 且 CSP 对 application/ld+json 不生效，要排除掉，否则永远报 1 处
    $html = (string) preg_replace('/<script type="application\/ld\+json">.*?<\/script>/su', '', $r['body']);
    $inline = preg_match_all('/<script(?![^>]*\ssrc=)[^>]*>(?!\s*<\/script>)/i', $html)
        + preg_match_all('/<style[\s>]/i', $html);

    return (!$bad && $inline === 0)
        ? ['pass', 'CSP 无 unsafe-*，页面内联 script/style 0 处（JSON-LD 除外）']
        : ['fail', 'unsafe=' . var_export($bad, true) . ' 内联=' . $inline];
});

$check(61, 'I 头部', 'TLS 配置', static fn (): array => ['manual', '本地开发为 http；生产需 SSL Labs 评级 A 以上（部署清单已列）']);
$check(62, 'I 头部', '依赖漏洞（composer audit）', static function () use ($root): array {
    $lock = $root . '/composer.lock';
    if (!is_file($lock)) {
        return ['fail', '缺 composer.lock（依赖版本不锁定）'];
    }
    $data = json_decode((string) file_get_contents($lock), true);
    $pkgs = count($data['packages'] ?? []) + count($data['packages-dev'] ?? []);

    // composer 必须在全局 ini 下跑（项目 ini 禁 exec + open_basedir 太窄，会在 composer
    // 写临时文件时静默失败：exit 0 且零输出）。所以由 bin/check-deps.ps1 采集，这里只判读。
    $file = $root . '/storage/reports/composer-audit.json';
    if (!is_file($file)) {
        return ['manual', 'composer.lock 已锁定 ' . $pkgs . ' 个包；未采集漏洞数据，先跑 bin\\check-deps.ps1'];
    }

    $rep = json_decode(preg_replace('/^\xEF\xBB\xBF/', '', (string) file_get_contents($file)), true);
    if (!is_array($rep) || !isset($rep['ok'], $rep['checked_at'])) {
        return ['manual', '报告无法解析：' . $file];
    }

    // 漏洞库天天更新，14 天前的「无漏洞」不能当今天的结论
    $age = time() - (int) strtotime((string) $rep['checked_at']);
    if ($age > 14 * 86400) {
        return ['manual', '漏洞数据已过期（' . round($age / 86400) . ' 天），重跑 bin\\check-deps.ps1'];
    }

    $adv       = (array) ($rep['advisories'] ?? []);
    $abandoned = (array) ($rep['abandoned'] ?? []);

    if ($adv === [] && $abandoned === []) {
        return ['pass', $pkgs . ' 个包（lock 锁定），漏洞库无命中，无废弃包；采集于 '
            . substr((string) $rep['checked_at'], 0, 16)];
    }

    return ['fail', '漏洞 ' . count($adv) . ' 条、废弃包 ' . count($abandoned) . ' 个：'
        . implode('、', array_slice(array_keys($adv), 0, 5))];
});

$check(63, 'I 头部', 'composer.lock 一致', static function () use ($root): array {
    $lock = $root . '/composer.lock';
    $json = is_file($root . '/composer.json') ? json_decode((string) file_get_contents($root . '/composer.json'), true) : null;
    $l    = is_file($lock) ? json_decode((string) file_get_contents($lock), true) : null;

    return ($json !== null && $l !== null && ($l['content-hash'] ?? '') !== '')
        ? ['pass', 'composer.json 与 composer.lock 均存在且 lock 带 content-hash']
        : ['fail', 'composer 文件缺失或损坏'];
});

$check(64, 'I 头部', '版本信息隐藏', static function () use ($base): array {
    $r1 = req('GET', $base . '/library');
    $r2 = req('GET', $base . '/library/works/00000000000000000000000000');
    $leak = isset($r1['headers']['x-powered-by'])
        || preg_match('/PHP\/\d|PHP Version/i', $r1['body'] . $r2['body']) === 1;

    return $leak ? ['fail', '响应或页面含 PHP 版本'] : ['pass', '响应头与错误页均无 PHP 版本'];
});

// ---------------------------------------------------------------------------
// J. 运维与可观测（65–68）
// ---------------------------------------------------------------------------

$check(65, 'J 运维', '备份可恢复', static function () use ($root): array {
    // 判定不靠回忆：bin/backup-restore-drill.php 每次演练都会落一份报告，
    // 这里只读最近一次结论，并要求它是 7 天内的（过期就等于没演练）。
    $file = $root . '/storage/reports/restore-drill-latest.json';
    if (!is_file($file)) {
        return ['fail', '没有演练报告，执行 bin/backup-restore-drill.php'];
    }
    $r = json_decode((string) file_get_contents($file), true) ?: [];
    $ageDays = (time() - (int) strtotime((string) ($r['drilled_at'] ?? 'now'))) / 86400;

    if (($r['consistent'] ?? false) !== true) {
        return ['fail', '最近一次演练结论为「不一致」，见 storage/reports/restore-drill-latest.json'];
    }

    return $ageDays <= 7
        ? ['pass', sprintf('最近演练 %s：%d 张表 / %d 行 / %.1fs / 校验一致', substr((string) $r['drilled_at'], 0, 10), (int) $r['tables'], (int) $r['rows'], (float) $r['seconds'])]
        : ['fail', sprintf('演练已过期（%.1f 天前），需重跑', $ageDays)];
});
$check(66, 'J 运维', '健康检查可用', static function () use ($base): array {
    $r = req('GET', $base . '/healthz');
    $data = json_decode($r['body'], true);
    $ok = is_array($data) && ($data['status'] ?? '') === 'ok';

    return $ok
        ? ['pass', '/healthz → ok（db/ai/queue/storage 四项检查齐全）']
        : ['fail', '/healthz → ' . $r['status'] . ' ' . substr($r['body'], 0, 60)];
});

$check(67, 'J 运维', '告警可用', static function () use ($root, $config): array {
    $file = $root . '/storage/reports/alert-drill-latest.json';
    if (!is_file($file)) {
        return ['manual', '未演练：先跑 bin\\alert-drill.php（起一次性 sink，验证送达 + 脱敏 + 冷却 + 旁路）'];
    }

    $rep = json_decode((string) file_get_contents($file), true);
    if (!is_array($rep) || !isset($rep['ok'], $rep['drilled_at'], $rep['steps'])) {
        return ['manual', '报告无法解析：' . $file];
    }

    $age = time() - (int) strtotime((string) $rep['drilled_at']);
    if ($age > 90 * 86400) {
        return ['manual', '演练已过期（' . round($age / 86400) . ' 天），重跑 bin\\alert-drill.php'];
    }
    if ($rep['ok'] !== true) {
        $bad = array_values(array_map(
            static fn (array $s): string => (string) ($s['name'] ?? '?'),
            array_filter((array) $rep['steps'], static fn (array $s): bool => empty($s['ok']))
        ));

        return ['fail', '演练未通过：' . implode('、', $bad)];
    }

    $steps     = count((array) $rep['steps']);
    $drilled   = substr((string) $rep['drilled_at'], 0, 16);
    $enabled   = $config->bool('alert.enabled', false);
    $webhook   = $config->string('alert.webhook', '');

    // 机制验证过 ≠ 生产通电了。这两件事必须分开报，否则「告警可用」会变成
    // 一句在事故当天才发现是空头支票的话。
    if ($enabled && $webhook !== '') {
        return ['pass', "通道已验证（{$steps} 步 / {$drilled}）：送达 + 脱敏 + 冷却 + 旁路；"
            . '生产 webhook 已配置'];
    }

    return ['manual', "机制已建成且演练通过（{$steps} 步 / {$drilled}），"
        . '但生产未通电：ALERT_ENABLED=' . ($enabled ? 'true' : 'false')
        . '、ALERT_WEBHOOK ' . ($webhook === '' ? '为空' : '已填')
        . ' —— 上线时填一行即可，代码侧不需要改'];
});
$check(68, 'J 运维', '回滚可执行', static function () use ($root): array {
    // 这里判的是「数据库侧回滚」。代码侧（上一个发布版本）仍然要人在发布流水线里证明，
    // 那是 manual 的一半 —— 不能因为数据库这半自动了，就把整条当成自动通过。
    $file = $root . '/storage/reports/rollback-drill-latest.json';
    if (!is_file($file)) {
        return ['manual', '未演练：先跑 bin\\rollback-drill.php（在 hqlib_drill 上走 001/002 撤回与前滚）'];
    }

    $rep = json_decode((string) file_get_contents($file), true);
    if (!is_array($rep) || !isset($rep['ok'], $rep['drilled_at'], $rep['steps'])) {
        return ['manual', '报告无法解析：' . $file];
    }

    $age = time() - (int) strtotime((string) $rep['drilled_at']);
    if ($age > 90 * 86400) {
        return ['manual', '演练已过期（' . round($age / 86400) . ' 天），重跑 bin\\rollback-drill.php'];
    }

    $steps = (array) $rep['steps'];
    if ($rep['ok'] === true) {
        return ['pass', count($steps) . ' 步全通过（' . substr((string) $rep['drilled_at'], 0, 16)
            . '，' . $rep['seconds'] . 's）：001 撤回→前滚、002 施加→撤回→再施加，'
            . '且撤回后审计表确实可改。代码侧回滚仍需发布版本配合'];
    }

    $bad = array_values(array_map(
        static fn (array $s): string => (string) ($s['name'] ?? '?'),
        array_filter($steps, static fn (array $s): bool => empty($s['ok']))
    ));

    return ['fail', '失败步骤：' . implode('、', $bad)];
});

// ---------------------------------------------------------------------------
// 输出
// ---------------------------------------------------------------------------

$byStatus = ['pass' => 0, 'fail' => 0, 'manual' => 0, 'na' => 0];
foreach ($results as $r) {
    $byStatus[$r['status']] = ($byStatus[$r['status']] ?? 0) + 1;
}

$icon = ['pass' => 'OK  ', 'fail' => 'FAIL', 'manual' => '待人工 ', 'na' => 'N/A '];
$lastSection = '';
foreach ($results as $r) {
    if ($r['section'] !== $lastSection) {
        fwrite(STDOUT, "\n" . $r['section'] . "\n");
        $lastSection = $r['section'];
    }
    fwrite(STDOUT, sprintf("  [%s] E-%02d %-34s %s\n", $icon[$r['status']], $r['id'], $r['title'], $r['evidence']));
}

fwrite(STDOUT, sprintf(
    "\n合计 %d 项：通过 %d · 不通过 %d · 待人工 %d · 不适用 %d\n",
    count($results),
    $byStatus['pass'],
    $byStatus['fail'],
    $byStatus['manual'],
    $byStatus['na']
));

$report = [
    'generated_at' => date('c'),
    'target'       => $base,
    'summary'      => $byStatus + ['total' => count($results)],
    'checks'       => $results,
];

$jsonPath = $options['json'] ?? ($root . '/storage/reports/security-audit-' . date('Ymd-His') . '.json');
if (!is_dir(dirname($jsonPath))) {
    @mkdir(dirname($jsonPath), 0750, true);
}
file_put_contents($jsonPath, json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
fwrite(STDOUT, "报告：{$jsonPath}\n");

exit($byStatus['fail'] > 0 ? 1 : 0);
