<?php

declare(strict_types=1);

/**
 * 装配与降级的黑盒回归：数据库挂掉时，应用还站得住吗？
 *
 * 守的是一次真实故障（2026-11-03 实测）：把 MySQL 停掉，`/`、`/library`、
 * `/admin/login`、`/healthz` 全部返回 500 且**响应体 0 字节**、**没有任何安全头**。
 * 浏览器一片空白，运维看不到一句错话，只能靠猜。
 *
 * 根因不在任何一行业务代码，在装配期：`WriteRateLimitMiddleware` 与
 * `HealthController` 的构造签名里直接写了需要连库的依赖（RateLimiter /
 * JobRepository / AiCallRepository）。Slim 在 `add()` 那一刻就把中间件 new 出来，
 * PHP-DI 顺着构造签名把仓储连根拔起 —— 数据库一挂，整个 `$app` 还没开始处理
 * 请求就崩了，连「降级」这条代码路径本身都执行不到。所以那天的 `/healthz`
 * 永远走不到自己的 try/catch，返回的是空白 500。
 *
 * 怎么查：把 DB_HOST / DB_PORT 指到一个必然被拒的地址与端口，按
 * public/index.php 的**同一条链**装配（container → middleware → routes），
 * 再逐个 dispatch。不起子进程 —— php.ini 的 disable_functions 里有
 * `proc_open` / `exec` / `popen`，起不来；也不开端口，避免端口占用与时序等待。
 *
 * 三组断言：
 *   A. **装配期不连库**。三条装配语句不抛，且 `Db::$instance` 仍是 null。
 *      这一条是根因本身：只要装配期碰了库，后面 B 组必然一起垮。
 *   B. `/healthz` 降级可用。200 + JSON + `status=degraded` + `checks.db.state=error`，
 *      且**全套安全头在场** —— 它走的是正常流程，安全头没有任何理由缺席。
 *   C. 页面级降级。`/` 是 302 跳转到 /library（不渲染，断言别抛 + 安全头在场）；
 *      `/library` **200（缓存命中）与 500（真错误页）都算过**，只有白屏判红；
 *      `/library/works/<不存在的合法 code>` 与 `/admin/login` 必须 500、
 *      响应体非空、是 HTML，不泄露 SQLSTATE / DSN / 服务器绝对路径 / 行号，
 *      且各自的出口对得上所在的那一端（前台「回到馆藏」、后台「返回后台」）。
 *
 * 已知缺口（末尾提示，不判红）：**错误响应不带安全头**。错误中间件是最外层
 * （config/middleware.php 里最后 add），异常响应由它直接产出，内层的
 * SecurityHeadersMiddleware 没有机会补头。要修得先给 ErrorPage 外链样式 ——
 * 它现在是内联 `<style>`，一加 CSP 就会把自己的样式屏蔽掉（记忆里的
 * 「CSP 静默丢弃内联样式」）。等那件事落地，把判红条件打开即可。
 *
 * 用法：php bin/check-boot.php
 * 退出码：0 全通过 / 1 有不符合项
 */

// ── 0. 把数据库指到必然被拒的地方 ────────────────────────────────────────────
//
// 端口 1 上不会有 MySQL：connect() 立刻 ECONNREFUSED，不会等满 Db 里那 5 秒超时。
// 为什么写 $_ENV 而不是 putenv：bootstrap 用 Dotenv::createImmutable，
// 已存在的 $_ENV 不会被 .env 覆盖；而 putenv 在服务器 php.ini 的
// disable_functions 里，写了就是 Call to undefined function。
// 刻意不动 DB_USER / DB_PASS：万一有人把端口改回 3306 再跑，
// 脚本也不会拿错账号去连生产库。
$_ENV['DB_HOST']   = $_SERVER['DB_HOST']   = '127.0.0.1';
$_ENV['DB_PORT']   = $_SERVER['DB_PORT']   = '1';
$_ENV['APP_DEBUG'] = $_SERVER['APP_DEBUG'] = '0';

$root = dirname(__DIR__);

require $root . '/vendor/autoload.php';

use App\Support\Db;
use Slim\App;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

/** 安全头：SecurityHeadersMiddleware 无条件发的那七个 */
const REQUIRED_HEADERS = [
    'X-Content-Type-Options',
    'X-Frame-Options',
    'Referrer-Policy',
    'Permissions-Policy',
    'Cross-Origin-Opener-Policy',
    'Cross-Origin-Resource-Policy',
    'Content-Security-Policy',
];

$errors = [];
$warns  = [];
$rows   = [];

// ── 1. 装配（与 public/index.php 完全同一条链） ──────────────────────────────
try {
    /** @var \Psr\Container\ContainerInterface $container */
    $container = require $root . '/config/container.php';
    $app       = AppFactory::createFromContainer($container);
    (require $root . '/config/middleware.php')($app, $container);
    (require $root . '/config/routes.php')($app);
} catch (Throwable $e) {
    fwrite(STDERR, '装配失败：' . $e::class . '：' . $e->getMessage() . PHP_EOL);
    fwrite(STDERR, '位置：' . $e->getFile() . ':' . $e->getLine() . PHP_EOL);
    fwrite(STDERR, PHP_EOL . '这就是缺陷本身：构造签名里有连库依赖，装配期被迫解析。' . PHP_EOL);
    exit(1);
}

// ── 2. A 组：装配期不许碰库 ─────────────────────────────────────────────────
$connected = null;
try {
    $prop      = new ReflectionProperty(Db::class, 'instance');
    $connected = $prop->getValue() !== null;
} catch (Throwable $e) {
    $warns[] = '读不到 Db::$instance，装配期是否连库未能核验：' . $e->getMessage();
}

if ($connected === true) {
    $errors[] = '装配期就建了数据库连接：中间件或控制器在构造签名里要了仓储，'
        . '数据库一挂整站起不来（本脚本守的就是这个）';
}

$rows[] = ['A', '装配期不连库', $connected === null ? '未核验' : ($connected ? '连了' : '未连'), $connected !== true];

// ── 3. 请求辅助 ─────────────────────────────────────────────────────────────
$factory = new ServerRequestFactory();

/**
 * 发一个请求，返回 [状态码:int, 头数组, 响应体:string]。
 *
 * 会话要显式关掉：SessionMiddleware 每个请求都 session_start()，从第二个请求
 * 起就是「session already active」的 warning，而 bootstrap 的错误处理器把
 * warning 一律转成 ErrorException —— 不关掉的话后几个断言会全部假失败。
 *
 * @return array{0:int, 1:array<string, list<string>>, 2:string}
 */
$send = static function (App $app, string $path) use ($factory): array {
    if (session_status() === PHP_SESSION_ACTIVE) {
        @session_write_close();
    }

    $response = $app->handle(
        $factory->createServerRequest('GET', $path)
            ->withHeader('Accept', 'text/html,application/json;q=0.9')
    );

    return [$response->getStatusCode(), $response->getHeaders(), (string) $response->getBody()];
};

/**
 * @param array<string, list<string>> $headers
 *
 * @return list<string> 缺席的安全头
 */
$missingHeaders = static function (array $headers): array {
    $have   = array_map('strtolower', array_keys($headers));
    $absent = [];
    foreach (REQUIRED_HEADERS as $name) {
        if (!in_array(strtolower($name), $have, true)) {
            $absent[] = $name;
        }
    }

    return $absent;
};

/**
 * 错误页里不该出现的东西：数据库拓扑、服务器路径、源码行号。
 *
 * @return list<string> 命中的泄露项
 */
$leaks = static function (string $body): array {
    $hit    = [];
    $needles = [
        'SQLSTATE'      => '数据库错误码（暴露出错环节）',
        'mysql:'        => 'DSN 片段',
        'dbname='       => 'DSN 片段',
        '127.0.0.1:1'   => '数据库连接地址',
        'hq_analysis'   => '内部表名',
        ' on line '     => '源码行号',
    ];
    foreach ($needles as $needle => $why) {
        if (str_contains($body, $needle)) {
            $hit[] = $why . '「' . $needle . '」';
        }
    }

    // 绝对路径：Linux 与 Windows 两种形态
    if (preg_match('#(/www/wwwroot/|[A-Za-z]:\\\\?[A-Za-z]+\\\\?(?:AITEST|webserver))#', $body) === 1) {
        $hit[] = '服务器绝对路径';
    }

    return $hit;
};

// ── 4. B 组：/healthz 必须降级可用 ─────────────────────────────────────────
try {
    [$status, $headers, $body] = $send($app, '/healthz');

    $json = json_decode($body, true);
    $ok   = $status === 200
        && is_array($json)
        && ($json['status'] ?? null) === 'degraded'
        && ($json['checks']['db']['state'] ?? null) === 'error';

    if ($status !== 200) {
        $errors[] = sprintf('/healthz 在数据库不可用时返回 %d，期望 200（降级而非宕机）', $status);
    } elseif (!is_array($json)) {
        $errors[] = '/healthz 返回的不是 JSON：' . mb_substr($body, 0, 80);
    } elseif (($json['status'] ?? null) !== 'degraded') {
        $errors[] = sprintf('/healthz 的 status 是 %s，期望 degraded', var_export($json['status'] ?? null, true));
    } elseif (($json['checks']['db']['state'] ?? null) !== 'error') {
        $errors[] = sprintf(
            '/healthz 的 checks.db.state 是 %s，期望 error',
            var_export($json['checks']['db']['state'] ?? null, true)
        );
    }

    // 安全头：/healthz 走正常流程，不能少
    $absent = $missingHeaders($headers);
    if ($absent !== []) {
        $errors[] = '/healthz 缺安全头：' . implode('、', $absent);
    }

    $rows[] = ['B', '/healthz 降级', sprintf('%d · %s', $status, (string) ($json['status'] ?? '非 JSON')), $ok && $absent === []];
} catch (Throwable $e) {
    $errors[] = '/healthz 处理阶段直接抛异常（降级路径没走到）：' . $e::class . '：' . $e->getMessage();
    $rows[]   = ['B', '/healthz 降级', '抛异常', false];
}

// ── 5. C 组：页面级降级 ─────────────────────────────────────────────────────
//
// `/` 在路由表里本来就是个 302 跳转到 /library（config/routes.php:43），
// 它不渲染任何东西，所以「数据库挂了」对它没有影响 —— 断言的是**别抛、别白屏**，
// 并且它走正常流程，安全头必须在。
try {
    [$status, $headers, $body] = $send($app, '/');

    $location = $headers['Location'][0] ?? '';
    $absent   = $missingHeaders($headers);
    $ok       = $status === 302 && $location === '/library' && $absent === [];

    if ($status !== 302) {
        $errors[] = sprintf('/ 返回 %d，期望 302（路由表里它就是跳转到 /library）', $status);
    } elseif ($location !== '/library') {
        $errors[] = sprintf('/ 的 Location 是「%s」，期望 /library', $location);
    }
    if ($absent !== []) {
        $errors[] = '/ 缺安全头：' . implode('、', $absent);
    }

    $rows[] = ['C', '/', sprintf('%d → %s', $status, $location === '' ? '（无）' : $location), $ok];
} catch (Throwable $e) {
    $errors[] = '/ 处理阶段直接抛异常：' . $e::class . '：' . $e->getMessage();
    $rows[]   = ['C', '/', '抛异常', false];
}

// 列表页：**两种结果都算过**，只有白屏不行。
//
// 页面缓存在 storage/cache/pages 里，命中的话数据库挂着也能正常出页面 ——
// 那是更好的降级结果，不该判红。线上实测就是这样：清过缓存后先访问过一次
// /library，之后再跑本脚本，它 200 返回了 37676 字节的完整页面。
// 没命中缓存才回落到 500 + 错误页，那时才检查错误页本身。
try {
    [$status, $headers, $body] = $send($app, '/library');

    $len = strlen($body);
    $ok  = true;

    if ($status === 200) {
        // 缓存命中：至少要像一张真页面
        if ($len < 500 || !str_contains(strtolower(implode(' ', $headers['Content-Type'] ?? [])), 'text/html')) {
            $errors[] = sprintf('/library 返回 200 但只有 %d 字节，不像一页缓存下来的完整页面', $len);
            $ok = false;
        }
        $actual = sprintf('200 · %d 字节（缓存命中）', $len);
    } elseif ($status === 500) {
        if ($len < 200) {
            $errors[] = sprintf('/library 的响应体只有 %d 字节，不是可读的错误页（白屏）', $len);
            $ok = false;
        }
        $hit = $leaks($body);
        if ($hit !== []) {
            $errors[] = '/library 的错误页泄露了内部信息：' . implode('、', $hit);
            $ok = false;
        }
        $actual = sprintf('500 · %d 字节（未命中缓存）', $len);
    } else {
        $errors[] = sprintf('/library 返回 %d，期望 200（缓存命中）或 500（真错误页）', $status);
        $ok = false;
        $actual = (string) $status;
    }

    $rows[] = ['C', '/library', $actual, $ok];
} catch (Throwable $e) {
    $errors[] = '/library 处理阶段直接抛异常：' . $e::class . '：' . $e->getMessage();
    $rows[]   = ['C', '/library', '抛异常', false];
}

// 错误页：必须是**真的页面**，且出口对得上自己所在的那一端。
// 「/library 给后台出口」是真实存在过的毛病 —— 读者点「返回后台」撞到登录页，
// 与 LibraryErrorPage 当初为 404 修掉的是同一类问题。
//
// 三条路径各测一件事，别合并：
//   · 搜索页 —— TTL 0、永不进缓存、必然走库，是唯一能稳定命中 500 分支的前台入口，
//     也就是 config/middleware.php 里新加的那个 $isPublic 分支；
//   · 详情页 —— code 合法但不存在，走 LibraryErrorPage 的 404 → 查库失败 → 回落，
//     测的是回落之后出口还在不在（这条与库挂不挂无关，是稳定不变量）；
//   · 登录页 —— 后台侧，出口必须是「返回后台」。
$pages = [
    '/library/search?q=bootcheck'               => [500, '回到馆藏', 'search 500'],
    '/library/works/TESTBOOTCHECK0000000000000' => [404, '回到馆藏', 'work 404'],
    '/admin/login'                              => [500, '返回后台', 'admin/login'],
];

foreach ($pages as $path => [$expectStatus, $expectLink, $label]) {
    try {
        [$status, $headers, $body] = $send($app, $path);
    } catch (Throwable $e) {
        $errors[] = $path . ' 处理阶段直接抛异常（连错误页都没有）：' . $e::class . '：' . $e->getMessage();
        $rows[]   = ['C', $label, '抛异常', false];
        continue;
    }

    $len = strlen($body);
    $ok  = true;

    if ($status !== $expectStatus) {
        $errors[] = sprintf('%s 返回 %d，期望 %d', $path, $status, $expectStatus);
        $ok = false;
    }

    // 这一条是那次故障最刺眼的地方：500 但 0 字节，浏览器白屏
    if ($len < 200) {
        $errors[] = sprintf('%s 的响应体只有 %d 字节，不是可读的错误页（白屏）', $path, $len);
        $ok = false;
    }

    $type = implode(' ', $headers['Content-Type'] ?? []);
    if (!str_contains(strtolower($type), 'text/html')) {
        $errors[] = sprintf('%s 的错误页 Content-Type 是「%s」，期望 text/html', $path, $type === '' ? '（无）' : $type);
        $ok = false;
    }

    $hit = $leaks($body);
    if ($hit !== []) {
        $errors[] = $path . ' 的错误页泄露了内部信息：' . implode('、', $hit);
        $ok = false;
    }

    // 出口：前台回馆藏、后台回后台
    if (!str_contains($body, $expectLink)) {
        $errors[] = sprintf('%s 的错误页没有「%s」出口（前台拿到后台链接会撞登录页）', $path, $expectLink);
        $ok = false;
    }

    $rows[] = ['C', $label, sprintf('%d · %d 字节', $status, $len), $ok];
}

// ── 6. 汇总 ────────────────────────────────────────────────────────────────
fwrite(STDOUT, '装配与降级回归（数据库指向 127.0.0.1:1，必然连不上）：' . PHP_EOL . PHP_EOL);
fwrite(STDOUT, sprintf("%-3s | %-16s | %-22s | %s\n", '组', '项目', '实测', '结论'));
fwrite(STDOUT, str_repeat('-', 68) . PHP_EOL);
foreach ($rows as [$group, $name, $actual, $ok]) {
    fwrite(STDOUT, sprintf("%-3s | %-16s | %-22s | %s\n", $group, $name, $actual, $ok ? '通过' : '不符'));
}
fwrite(STDOUT, PHP_EOL);

if ($warns !== []) {
    fwrite(STDOUT, '提示：' . PHP_EOL);
    foreach ($warns as $w) {
        fwrite(STDOUT, '  · ' . $w . PHP_EOL);
    }
    fwrite(STDOUT, PHP_EOL);
}

if ($errors !== []) {
    fwrite(STDERR, '不符合项：' . PHP_EOL);
    foreach ($errors as $i => $e) {
        fwrite(STDERR, sprintf('  %d) %s%s', $i + 1, $e, PHP_EOL));
    }
    fwrite(STDERR, PHP_EOL . '装配或降级行为不符合预期，退出码 1。' . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, '已知缺口（不判红）：错误响应不带安全头。错误中间件在最外层，'
    . '异常响应由它直接产出，内层 SecurityHeadersMiddleware 补不上；'
    . '要修得先给 ErrorPage 外链样式（现在内联 style 一加 CSP 就被屏蔽）。' . PHP_EOL);
fwrite(STDOUT, PHP_EOL . '装配与降级行为符合预期，退出码 0。' . PHP_EOL);
exit(0);
