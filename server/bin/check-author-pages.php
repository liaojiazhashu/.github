<?php

declare(strict_types=1);

/**
 * 作者页自检：确认「一个人名下的全部作品」真的都在一个页面里。
 *
 * 为什么需要：
 *   · 作者页的篇数来自一条聚合 SQL，卡片来自另一条列表 SQL。两条 SQL 各写一遍
 *     条件，早晚会漂 —— 而漂移的样子极其隐蔽：页头写「9 篇」，底下摆 8 张卡，
 *     读者不会察觉少了一篇，作者本人会。
 *   · 作者页是唯一按人聚合的入口。它要是 404，读者从作品页点过去就是死链；
 *     它要是在某人所有作品都隐藏之后跟着 404，那就是**把人也一起藏了** ——
 *     作品级隐藏不该连坐到作者，那是没被授权过的第二层隐藏。
 *
 * 检查五件事：
 *   1. 每个「馆内有作品记录」的作者，作者页 200，页头篇数与库里一致（可以是 0）；
 *   2. 首屏卡片数 == min(篇数, 每页数)，既不少也不能多（多就是漏了过滤条件）；
 *   3. 卡片指向的作品详情页确实可访问；
 *   4. 0 篇的作者页要有空态，且不能摆卡片；
 *   5. 反向：馆内没有任何作品记录的作者，作者页必须 404；
 *   6. sitemap 里的作者条目数 == 能打开的作者页数（页面在，sitemap 里就该在）。
 *
 * 用法：
 *   php -c php.ini bin/check-author-pages.php [--base=http://127.0.0.1:8090]
 *
 * 退出码：有任何一条不通过就返回 1（适合放进部署脚本）。
 */

require dirname(__DIR__) . '/config/bootstrap.php';

use App\Support\Config;

// 写成 dirname(__DIR__) 而不是先赋给变量再拼接：安全自检 E-52 把
// 「路径来自变量的 include」一律当作动态包含 —— 规则是对的，不为巡检脚本开例外。
$container = require dirname(__DIR__) . '/config/container.php';

/** @var Config $config */
$config = $container->get(Config::class);

$base = $config->string('app.url', 'http://127.0.0.1:8090');
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--base=')) {
        $base = substr($arg, 7);
    }
}
$base = rtrim($base, '/');

$pdo     = $container->get(PDO::class);
$perPage = max(1, $config->int('library.per_page', 12));

$fail = 0;
$ok   = 0;

/**
 * GET 一个地址（不跟随重定向：作者页就不该跳）。
 *
 * @return array{status:int, body:string}
 */
$get = static function (string $url): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_USERAGENT      => 'HuangqiLibraryCheck/1.0',
    ]);
    $body   = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    return ['status' => $status, 'body' => $body];
};

$report = static function (bool $pass, string $label, string $detail = '') use (&$fail, &$ok): void {
    if ($pass) {
        ++$ok;
        fwrite(STDOUT, "  [OK]   {$label}\n");
    } else {
        ++$fail;
        fwrite(STDOUT, "  [FAIL] {$label}" . ($detail === '' ? '' : "  —— {$detail}") . "\n");
    }
};

// 与 LibraryRepository::LIVE_WORK 同一口径。这里重写一遍是有意的：
// 巡检脚本若直接调仓储，就等于用被检查的尺子量自己，条件漏了也一起漏。
$visibleSql = 'w2.status = \'published\' AND w2.deleted_at IS NULL AND w2.visibility = \'visible\'
               AND EXISTS (SELECT 1 FROM hq_analysis a2 WHERE a2.id = w2.current_analysis_id
                              AND a2.status = \'published\' AND a2.visibility = \'visible\')';

// 页面存在与否看 w（有作品记录就算），篇数看 v（只看可见）。两个 COUNT(DISTINCT)
// 是为了抵消 JOIN 出来的笛卡尔积 —— 直接 COUNT 会把 2 篇数成 4 篇。
$rows = $pdo->query(
    'SELECT au.code, au.name,
            COUNT(DISTINCT w.id) AS records,
            COUNT(DISTINCT v.id) AS works
       FROM hq_author au
       JOIN hq_work w
         ON w.author_id = au.id
        AND w.status = \'published\'
        AND w.deleted_at IS NULL
       LEFT JOIN (
            SELECT w2.id, w2.author_id
              FROM hq_work w2
             WHERE ' . $visibleSql . '
       ) v ON v.author_id = au.id
      GROUP BY au.id, au.code, au.name
      ORDER BY works DESC, au.id'
)->fetchAll();

fwrite(STDOUT, sprintf("作者页自检 · %s（每页 %d 篇）\n", $base, $perPage));

foreach ($rows as $row) {
    $code   = (string) $row['code'];
    $name   = (string) $row['name'];
    $works  = (int) $row['works'];
    $label  = sprintf('%s（%d 篇）', $name, $works);
    $url    = $base . '/library/authors/' . $code;

    $res = $get($url);
    if ($res['status'] === 429) {
        // 公开区限流 60/60s。撞上了不算通过也不算失败，如实说一声就够
        fwrite(STDOUT, "  [SKIP] {$label}  —— 触发公开区限流，稍后重跑\n");
        break;
    }

    $report($res['status'] === 200, $label . ' 可访问', 'HTTP ' . $res['status']);
    if ($res['status'] !== 200) {
        continue;
    }

    $html = $res['body'];

    // 页头「馆内作品 N 篇」必须与库里一致
    $headWorks = null;
    if (preg_match('#馆内作品\s*<b[^>]*>\s*([0-9,]+)\s*</b>\s*篇#u', $html, $m) === 1) {
        $headWorks = (int) str_replace(',', '', $m[1]);
    }
    $report($headWorks === $works, $label . ' 页头篇数', '页面写 ' . var_export($headWorks, true) . '，库里 ' . $works);

    // 首屏卡片数：满页时就是每页数，不满时就是总篇数
    $cards = preg_match_all('#<article class="wcard">#', $html);
    $want  = min($works, $perPage);
    $report($cards === $want, $label . ' 首屏卡片', '页面 ' . $cards . ' 张，应为 ' . $want . ' 张');

    // 0 篇：必须有空态，且不能顺手把「本来有几篇被藏了」写出来
    if ($works === 0) {
        $hasEmpty = mb_strpos($html, '这位作者名下暂时没有公开展示的作品') !== false;
        $report($hasEmpty, $label . ' 空态', '没有找到空态文案');
    }

    // 卡片上的链接必须真的能打开（只抽查第一张，避免把自检跑成压测）
    if (preg_match('#href="(/library/works/[0-9A-HJKMNP-TV-Z]{26})"#', $html, $m) === 1) {
        $work = $get($base . $m[1]);
        $report($work['status'] === 200, $label . ' 首篇详情', 'HTTP ' . $work['status'] . ' ' . $m[1]);
    }
}

// ---- 反向：馆内没有任何作品记录的人，作者页必须 404 ----
$ghosts = $pdo->query(
    'SELECT au.code, au.name
       FROM hq_author au
      WHERE NOT EXISTS (
            SELECT 1 FROM hq_work w
             WHERE w.author_id = au.id
               AND w.status = \'published\'
               AND w.deleted_at IS NULL)
      ORDER BY au.id
      LIMIT 3'
)->fetchAll();

if ($ghosts === []) {
    fwrite(STDOUT, "  [SKIP] 反向检查 —— 没有「有档案但馆内无作品记录」的作者\n");
} else {
    foreach ($ghosts as $ghost) {
        $res = $get($base . '/library/authors/' . $ghost['code']);
        $report(
            $res['status'] === 404,
            sprintf('馆内无作品记录的 %s 应当没有页面', (string) $ghost['name']),
            'HTTP ' . $res['status']
        );
    }
}

// ---- sitemap：作者条目数必须等于能打开的作者页数 ----
// 页面在、sitemap 里没有 = 对搜索引擎说这个人不存在，是另一种形式的隐藏；
// sitemap 里有、页面 404 = 死链。两边必须同一批人。
$sitemap = $get($base . '/sitemap.xml');
if ($sitemap['status'] !== 200) {
    $report(false, 'sitemap 可访问', 'HTTP ' . $sitemap['status']);
} else {
    $inSitemap = preg_match_all('#<loc>[^<]*/library/authors/[0-9A-HJKMNP-TV-Z]{26}</loc>#', $sitemap['body']);
    $report(
        $inSitemap === count($rows),
        'sitemap 作者条目',
        sprintf('sitemap 里 %d 条，可访问作者页 %d 个', $inSitemap, count($rows))
    );
}

fwrite(STDOUT, sprintf("\n%d 通过 / %d 不通过\n", $ok, $fail));
exit($fail === 0 ? 0 : 1);
