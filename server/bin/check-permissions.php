<?php

declare(strict_types=1);

/**
 * 权限矩阵自检：把「代码里的契约」「数据库里的现状」「路由表的覆盖」三方对齐。
 *
 * 为什么需要一个独立脚本而不是只靠单测：
 *   · 单测跑的是纯逻辑（Permission::MATRIX 对不对），不碰数据库；
 *   · 数据库可以被人在 Navicat 里改一行，改了不会报错，但权限就变了 ——
 *     这类漂移只能靠"部署后跑一下就报"来兜；
 *   · 路由忘配权限是**最容易犯的错**：新加一个 POST 动作，忘了在 permissions.php 登记，
 *     PermissionMiddleware 会 403（默认拒绝，不会漏），但没人会知道"这个动作谁都点不了"。
 *
 * 三个检查：
 *   1. 契约 vs 数据库：谁少了谁多了，逐条列出来；
 *   2. 路由 vs 权限表：有没有路由没登记（会让所有人都 403）、有没有登记了却没有对应路由（死配置）；
 *   3. 权限码 vs 数据库 hq_permission：Permission::ALL 与表里的行是否一一对应。
 *
 * 第 4 项是**提示级**（warn，不影响退出码）：权限码 vs 路由消费 ——
 * Permission::ALL 里的码有没有被任何一条路由引用。查出来的形态是「声明了但没人用」，
 * 典型如 system.user / system.setting：契约里有、admin 有、界面上没有对应功能。
 *
 * 这一项的定位必须说清，别把它当成比它更大的东西：
 *   · 它是**语法层的集合比对**（权限码集合 ∩ 路由表引用的权限码集合），不涉及数据、不连库、不看业务状态；
 *   · 它能回答「有没有任何执行点引用这个码」，回答不了「引用得对不对」——
 *     路由挂错码、第二层校验漏写、数据层该过滤而没过滤，它一个都查不出来（见上面那条清单）；
 *   · 也不代表「没被引用就是错」：权限码先于功能落地是正常的开发顺序。
 *     它只保证这件事不会悄无声息 —— 这条提示摆在那，就不该有人再说「我们已经交付了用户管理」。
 *
 * **本脚本只校验「映射的权限码存在」，不校验语义**。有一类路由一条路径承载两种动作，
 * 权限表里只能写一个码，第二层校验写在业务里头 —— 目前只有一处：
 *   · POST /admin/works/bulk（admin.works.bulk → analysis.run）
 *     同时承载 hide / restore / hide-deck / restore-deck，这四个动作在
 *     WorkController::bulk 入口处二次校验 visibility.toggle，不通过返回 403。
 * 这种地方改权限表时脚本不会报警，只能靠读控制器 —— 所以这里留一条清单。
 *
 * 退出码（部署脚本请判 != 0，别只判 == 1）：
 *   · 0 —— 三方一致；
 *   · 1 —— 查出不一致（真的有问题）；
 *   · 2 —— **没查完**：连不上数据库，第 1、2 项跳过，路由与权限码那两项的结论仍然有效。
 *     这一档单独分开，是因为「数据库还没起来」和「权限配错了」是两件完全不同的事，
 *     混成一个码会让排障的人往错的方向走。
 */

use App\Application\Auth\Permission;
use App\Support\Config;
use Slim\Factory\AppFactory;

$container = require dirname(__DIR__) . '/config/container.php';

/** @var Config $config */
$config = $container->get(Config::class);

// 数据库要连，但**连不上不能让整个自检失去意义**。
// 第 3、4 项（路由 vs 权限表、权限码有没有人消费）是纯静态比对，
// 恰恰是「新机器还没配库」「数据库正在故障」这两种最需要跑自检的时候能给出的结论。
// 所以这里把 PDO 降级成可选：连不上就跳过第 1、2 项，其余照跑，最后以退出码 2 收尾
// （2 = 没验证完，别当成通过；部署脚本判 != 0 一样会拦）。
$pdo     = null;
$dbError = null;

try {
    $pdo = $container->get(PDO::class);
} catch (Throwable $e) {
    $dbError = $e->getMessage();
}

$errors = [];
$warns  = [];

// ---- 1. 数据库矩阵 vs 契约 -------------------------------------------------
if ($pdo !== null) {
    $rows = $pdo->query(
        'SELECT r.code AS role, p.code AS perm
           FROM hq_role r
           LEFT JOIN hq_role_permission rp ON rp.role_id = r.id
           LEFT JOIN hq_permission p ON p.id = rp.permission_id'
    )->fetchAll();

    $dbMatrix = [];
    foreach ($rows as $row) {
        $role = (string) $row['role'];
        $dbMatrix[$role] ??= [];
        if ($row['perm'] !== null) {
            $dbMatrix[$role][] = (string) $row['perm'];
        }
    }
    foreach (array_keys($dbMatrix) as $role) {
        $dbMatrix[$role] = array_values(array_unique($dbMatrix[$role]));
    }

    foreach (Permission::diff($dbMatrix) as $issue) {
        $errors[] = '矩阵不一致：' . $issue;
    }
}

// ---- 2. 权限码 vs hq_permission -------------------------------------------
if ($pdo !== null) {
    $dbPerms = array_map(
        static fn (array $r): string => (string) $r['code'],
        $pdo->query('SELECT code FROM hq_permission ORDER BY id')->fetchAll()
    );

    foreach (array_diff(Permission::ALL, $dbPerms) as $missing) {
        $errors[] = 'hq_permission 缺少权限码：' . $missing;
    }
    foreach (array_diff($dbPerms, Permission::ALL) as $extra) {
        $warns[] = 'hq_permission 多出契约未定义的权限码：' . $extra;
    }
}

// ---- 3. 路由 vs 权限表 -----------------------------------------------------
$app = AppFactory::createFromContainer($container);
(require dirname(__DIR__) . '/config/routes.php')($app);

$declared = [];
foreach ($app->getRouteCollector()->getRoutes() as $route) {
    $name = $route->getName();
    if ($name === null) {
        $errors[] = sprintf('路由没有命名（权限表按名字查）：%s %s', implode('|', $route->getMethods()), $route->getPattern());
        continue;
    }
    $declared[$name] = $route->getPattern();
}

/** @var array<string, ?string> $map */
$map = (array) $config->get('permissions', []);

foreach ($declared as $name => $pattern) {
    if (!array_key_exists($name, $map)) {
        $errors[] = sprintf('路由未登记权限（会被 403 全拦）：%s → %s', $name, $pattern);
    }
}
foreach (array_keys($map) as $name) {
    if (!array_key_exists($name, $declared)) {
        $warns[] = sprintf('权限表里的 %s 没有任何路由使用（死配置）', $name);
    }
}

// 登记了名字却引用了不存在的权限码
foreach ($map as $name => $perm) {
    if ($perm !== null && !in_array($perm, Permission::ALL, true)) {
        $errors[] = sprintf('权限表把 %s 指向了不存在的权限码：%s', $name, (string) $perm);
    }
}

// ---- 4. 权限码 vs 路由消费（提示级） ---------------------------------------
// 反方向的一问：不是「路由有没有登记权限」，而是「权限码有没有任何路由在用它」。
// 只做集合比对，不连库、不看业务状态 —— 见文件头对这项定位的说明。
// 之所以只提示不报错：权限码先于功能落地是正常的，只有「谎称已交付」才是问题。
$consumed = [];
foreach ($map as $perm) {
    if ($perm !== null) {
        $consumed[(string) $perm] = true;
    }
}

foreach (Permission::ALL as $code) {
    if (isset($consumed[$code])) {
        continue;
    }

    // 顺手把「本来有权用它的角色」列出来，读的人才知道这个空档有多大
    $roles = array_keys(array_filter(
        Permission::MATRIX,
        static fn (array $perms): bool => in_array($code, $perms, true)
    ));

    $warns[] = sprintf(
        '权限码 %s（%s）没有任何路由消费：契约里有、%s 拥有它，但界面上没有对应功能',
        $code,
        Permission::LABELS[$code] ?? '',
        $roles === [] ? '没有任何角色' : implode('/', $roles)
    );
}

// ---- 输出 -----------------------------------------------------------------
// 终端里中文占两列，str_pad 按字节/字符都排不齐，这里按显示宽度补空格
$displayWidth = static function (string $text): int {
    $width = 0;
    foreach (preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $char) {
        $width += strlen($char) > 1 ? 2 : 1;
    }

    return $width;
};
$pad = static fn (string $text, int $width): string => $text . str_repeat(' ', max(0, $width - $displayWidth($text)));

fwrite(STDOUT, "权限矩阵（● 有 / · 无）：\n\n");
fwrite(STDOUT, $pad('权限码', 24) . ' | ' . $pad('说明', 22) . ' | ');
foreach (array_keys(Permission::MATRIX) as $role) {
    fwrite(STDOUT, $pad((string) $role, 10));
}
fwrite(STDOUT, "\n" . str_repeat('-', 24 + 3 + 22 + 3 + 10 * count(Permission::MATRIX)) . "\n");

foreach (Permission::ALL as $code) {
    fwrite(STDOUT, $pad($code, 24) . ' | ' . $pad(Permission::LABELS[$code] ?? '', 22) . ' | ');
    foreach (array_keys(Permission::MATRIX) as $role) {
        $has = in_array($code, Permission::MATRIX[$role], true);
        fwrite(STDOUT, $pad($has ? '●' : '·', 10));
    }
    fwrite(STDOUT, "\n");
}

fwrite(STDOUT, sprintf("\n路由登记 %d 条，权限码 %d 个，角色 %d 个\n", count($declared), count(Permission::ALL), count(Permission::MATRIX)));

if ($dbError !== null) {
    fwrite(STDERR, "\n未能核验数据库侧（第 1、2 项）；上面的路由与权限码结论仍然有效。\n");
    fwrite(STDERR, '  原因：' . $dbError . "\n");
}

if ($warns !== []) {
    fwrite(STDOUT, "\n提示：\n");
    foreach ($warns as $warn) {
        fwrite(STDOUT, '  · ' . $warn . "\n");
    }
}

if ($errors !== []) {
    fwrite(STDERR, "\n错误：\n");
    foreach ($errors as $error) {
        fwrite(STDERR, '  × ' . $error . "\n");
    }
    fwrite(STDERR, sprintf("\n共 %d 项错误。\n", count($errors)));
    exit(1);
}

// 退出码 2 单独一档：不是「查出问题」，是「没查完」。
// 混进 1 会让部署脚本把「数据库还没起来」报成「权限配错了」。
if ($dbError !== null) {
    fwrite(STDERR, "\n静态部分一致；数据库部分未核验，退出码 2。\n");
    exit(2);
}

fwrite(STDOUT, "\n三方一致：代码契约 = 数据库 = 路由表。\n");
exit(0);
