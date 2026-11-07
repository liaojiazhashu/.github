<?php

declare(strict_types=1);

/**
 * 隐藏词条的公开泄漏扫描器（只读，不改任何数据）。
 *
 * 为什么需要这个工具：可见性过滤做在 SQL 层，只能挡住「结构化引用」
 * （关联表、计数、列表、检索）。但拆解产物里存的是自由文本 ——
 * one_liner、theme_note、情节摘要、建议正文、读者视角 —— 这些字段是
 * 模型写出来的叙述，会自然地把词条名写进句子里。SQL 层过滤不到，
 * 页面照常把它们吐给读者。
 *
 * 本工具做两件事：
 *   1) 数据层：逐个字符串字段检查隐藏词条（含别名）是否出现在待渲染的文本里；
 *   2) 呈现层：把前台所有公开路由真抓一遍，在 HTML 里找残留。
 *
 * 用法：php -c php.ini bin/scan-term-leak.php [--base=http://127.0.0.1:8090] [--quiet]
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

$base = 'http://127.0.0.1:8090';
$quiet = false;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--base=')) {
        $base = substr($arg, 7);
    }
    if ($arg === '--quiet') {
        $quiet = true;
    }
}

$env = parse_ini_file($root . '/.env', false, INI_SCANNER_RAW);
$pdo = new PDO(
    'mysql:host=' . $env['DB_HOST'] . ';port=' . $env['DB_PORT'] . ';dbname=' . $env['DB_NAME'] . ';charset=utf8mb4',
    $env['DB_USER'],
    $env['DB_PASS'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

// 不 require config/container.php：那会被安全自检的 E-52「无动态 include」判为
// 可变路径引入（规则本身是对的 —— include 注入是最经典的一类漏洞，不该为一个
// 巡检脚本开例外）。这里只需要一个 AnalysisRepository，直接构造即可。
$analyses = new App\Infrastructure\Pdo\AnalysisRepository($pdo, $root . '/storage/artifacts');

/* ── 1. 取隐藏词条与全部词形 ─────────────────────────────────── */

/** @var array<string, array{id:int, canonical:string}> $needles 词形 => 词条 */
$needles = [];

// 「这个词在公开区还有没有出处」—— 与 PublicTextGuard::LIVE_TERM_SQL 同一口径。
// 巡检器刻意与守卫同口径，而不是自己发明一套：它要验证的是「守卫执行到位没有」，
// 判据本身若不一致，报出来的残留就无法判断是守卫漏了还是脚本算错了。
$liveTermSql = "EXISTS (
    SELECT 1 FROM hq_work_term wtl
      JOIN hq_work wl
        ON wl.id = wtl.work_id
       AND wl.status = 'published'
       AND wl.deleted_at IS NULL
       AND wl.visibility = 'visible'
       AND wl.current_analysis_id = wtl.analysis_id
      JOIN hq_analysis al
        ON al.id = wtl.analysis_id
       AND al.status = 'published'
       AND al.visibility = 'visible'
     WHERE wtl.term_id = t.id)";

// 要扫的词 = 显式隐藏的词条 ∪ 因唯一出处被隐藏而失去全部引用的词条。
// 后者正是「藏一篇拆解，它的关键词一起消失」这条需求的核心，漏掉它巡检会报
// 「没有隐藏词条」然后退出 —— 最危险的那种通过。
$hiddenTerms = $pdo->query(
    "SELECT t.id, t.canonical
       FROM hq_index_term t
      WHERE t.visibility = 'hidden'
         OR (EXISTS (SELECT 1 FROM hq_work_term wh
                       JOIN hq_analysis ah
                         ON ah.id = wh.analysis_id
                        AND ah.status = 'published'
                        AND ah.visibility = 'hidden'
                      WHERE wh.term_id = t.id)
             AND NOT $liveTermSql)
      ORDER BY t.id"
)->fetchAll(PDO::FETCH_ASSOC);

foreach ($hiddenTerms as $t) {
    $needles[(string) $t['canonical']] = ['id' => (int) $t['id'], 'canonical' => (string) $t['canonical']];
    $stmt = $pdo->prepare('SELECT alias FROM hq_index_term_alias WHERE term_id = :id');
    $stmt->execute([':id' => (int) $t['id']]);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $alias) {
        $alias = (string) $alias;
        // 单字词形不进扫描：会命中任何句子里偶然的同字，噪声大于信号
        if (mb_strlen($alias) < 2) {
            continue;
        }
        $needles[$alias] ??= ['id' => (int) $t['id'], 'canonical' => (string) $t['canonical']];
    }
}

/* ── 1b. 公开区「本应不存在」的作品 ───────────────────────────── */

// 与 LibraryRepository::LIVE_WORK 同一口径的**反面**：当前拆解不可见的作品，
// 在公开区里应当等于「从未被拆解过」—— 列表、期次、首页、sitemap、检索、
// 联想、以及任何页面的任何链接里，都不该再出现它。
//
// 为什么巡检器必须自己算这一份，而不能只看隐藏词条：如果一篇拆解的关键词
// 全部是共享词（比如「世界观」「飞船」这类通用词），它被隐藏后不会产生任何
// 一个死词条 —— 只查词条的话，脚本会打印「没有隐藏词条，无需扫描」然后
// 退出 0。那是最危险的一种通过：什么都没验，却报了一个绿。
$liveWorkSql = "(w.status = 'published'
    AND w.deleted_at IS NULL
    AND w.visibility = 'visible'
    AND w.current_analysis_id IS NOT NULL
    AND EXISTS (SELECT 1 FROM hq_analysis al
                 WHERE al.id = w.current_analysis_id
                   AND al.status = 'published'
                   AND al.visibility = 'visible'))";

/** @var array<string, string> code => 标题 */
$goneWorks = [];
foreach ($pdo->query(
    "SELECT w.code, w.title
       FROM hq_work w
      WHERE w.deleted_at IS NULL
        AND NOT $liveWorkSql
        AND EXISTS (SELECT 1 FROM hq_analysis ap
                     WHERE ap.work_id = w.id AND ap.status = 'published')
      ORDER BY w.id"
)->fetchAll(PDO::FETCH_ASSOC) as $row) {
    // 只收「曾经发布过、现在不该在」的作品。从没发布过的草稿本来就没进过公开区，
    // 把它当扫描目标只会让每个页面多跑几千次 substr_count，结果恒为 0。
    $goneWorks[(string) $row['code']] = (string) $row['title'];
}

foreach ($goneWorks as $code => $title) {
    printf("本应消失的作品：%s（%s）\n", $title, $code);
}

if ($needles === [] && $goneWorks === []) {
    echo "没有隐藏词条，也没有因隐藏而消失的作品，无需扫描。\n";
    exit(0);
}

// 可见对象的名字：与某个隐藏词形**完全相同**的不参与扫描。
// 词条叫「蒲公英」、作品也叫《蒲公英》是同名巧合，不是泄漏；因为隐藏一个词条
// 就把作品从列表里抓出来，反而是错的。守卫里也有这条边界，这里独立算一遍 ——
// 两边都用更宽的集合去查，才可能发现对方漏掉的东西。
//
// 「可见」必须按公开区的实际口径算：没有可见拆解的作品、没有出处的词条，
// 在馆里已经不存在，它们不配给谁豁免。否则一个刚被藏掉拆解的词条会用自己的
// 名字豁免自己，扫描一行残留都报不出来。
$exact = [];
foreach ($pdo->query(
    "SELECT t.canonical AS name
       FROM hq_index_term t
      WHERE t.visibility = 'visible' AND $liveTermSql
     UNION
     SELECT w.title AS name
       FROM hq_work w
      WHERE w.visibility = 'visible'
        AND w.deleted_at IS NULL
        AND w.status = 'published'
        AND EXISTS (SELECT 1 FROM hq_analysis al
                     WHERE al.id = w.current_analysis_id
                       AND al.status = 'published'
                       AND al.visibility = 'visible')"
)->fetchAll(PDO::FETCH_COLUMN) as $name) {
    $exact[(string) $name] = true;
}

$forms = array_values(array_filter(
    array_keys($needles),
    static fn (string $f): bool => !isset($exact[$f])
));
usort($forms, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

printf(
    "隐藏词条 %d 个，待查词形 %d 个：%s\n",
    count($hiddenTerms),
    count($forms),
    implode('、', array_slice($forms, 0, 8)) . (count($forms) > 8 ? ' …' : '')
);

if ($forms === []) {
    // 不退出：词形为空不等于没事可查。作品层（有作品消失）与消亡层照样要跑，
    // 早退会让「藏了一篇通用词多的拆解」这种情况整个漏检。
    echo "（没有需要检查的词形：隐藏词的名字与某个可见作品/词条同名，已按边界跳过）\n";
}

/* ── 2. 数据层：拆解产物里有没有这些词形 ─────────────────────── */

echo "【数据层】产物源文本里需要遮蔽的位置（这些不是泄漏，是守卫要处理的工作量）\n";

// 扫的是 AnalysisRepository::loadArtifacts() 的真实输出，不是 storage/artifacts 下的
// 落盘 JSON —— 那批 JSON 是分析过程的原始记录，读者看不到，拿它当口径会误报。
$dataHits = 0;

/** 递归把产物里的每个字符串连同路径吐出来 */
$walk = static function (mixed $node, string $path, callable $visit) use (&$walk): void {
    if (is_array($node)) {
        foreach ($node as $k => $v) {
            $walk($v, $path === '' ? (string) $k : $path . '.' . $k, $visit);
        }

        return;
    }
    if (is_string($node)) {
        $visit($node, $path);
    }
};

$analysisIds = $pdo->query(
    "SELECT a.id FROM hq_analysis a
       JOIN hq_work w ON w.id = a.work_id
      WHERE a.status = 'published' AND a.visibility = 'visible'
        AND w.visibility = 'visible' AND w.deleted_at IS NULL"
)->fetchAll(PDO::FETCH_COLUMN);

foreach ($analysisIds as $aid) {
    $raw = $analyses->loadArtifacts((int) $aid);
    $walk($raw, '', static function (string $text, string $path) use (
        &$dataHits, $needles, $forms, $aid, $quiet
    ): void {
        foreach ($forms as $form) {
            $pos = mb_strpos($text, $form);
            if ($pos === false) {
                continue;
            }
            ++$dataHits;
            if ($quiet) {
                return;
            }
            $ctx = mb_substr($text, max(0, $pos - 14), mb_strlen($form) + 28);
            printf(
                "  analysis#%-3s %-30s 「%s」→ %s\n",
                $aid,
                $path,
                $form,
                str_replace(["\n", "\r"], ' ', $ctx)
            );

            return;
        }
    });
}

if ($dataHits === 0) {
    echo "  （无）\n";
} else {
    printf("  —— 共 %d 处\n", $dataHits);
}

/* ── 3. 呈现层：真抓前台页面 ─────────────────────────────────── */

echo "\n【呈现层】前台页面中的残留（隐藏词形 + 已消失作品的链接，应恒为 0）\n";

// 用 curl 而不是 file_get_contents：本项目 allow_url_fopen 是关的（安全加固的
// 一部分，不该为了一个巡检脚本去动它），file_get_contents 拿不到 http:// 内容。
$ch = curl_init();
/** @return array{0:int, 1:string, 2:int} [状态码, 正文, Retry-After] */
$fetch = static function (string $url) use ($ch): array {
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTPHEADER => ['User-Agent: leak-scan'],
    ]);
    $body = curl_exec($ch);

    return [
        (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE),
        is_string($body) ? $body : '',
        (int) curl_getinfo($ch, CURLINFO_RETRY_AFTER),
    ];
};

// 公开区限额是 60 请求 / 60 秒（config/settings.php 的 library.rate_limit）。
// 全量扫有 40+ 个页面，一口气打完必然撞限额 —— 而 429 页面里没有词，会被当成
// 「这一页干净」，扫描结论就整个失真了。所以这里**主动节流**，并把 429 当成
// 「没测到」重试，绝不把限流页算成通过。
$delay = 1.15;
$retryLimit = 6;

$urls = ['/library', '/library/works', '/library/terms', '/library/method', '/sitemap.xml'];

foreach ($pdo->query(
    "SELECT w.code FROM hq_work w WHERE $liveWorkSql"
)->fetchAll(PDO::FETCH_COLUMN) as $code) {
    $urls[] = '/library/works/' . $code;
    $urls[] = '/library/works/' . $code . '/author';
}
// 只扫能打开的词条页：停用、已归并、失去全部可见引用的词条本来就 404，
// 扫它们只会污染「跳过」计数。它们该不该 404，由第 4 层独立断言。
foreach ($pdo->query(
    "SELECT t.id FROM hq_index_term t
      WHERE t.status = 'active' AND t.visibility = 'visible' AND $liveTermSql"
)->fetchAll(PDO::FETCH_COLUMN) as $id) {
    $urls[] = '/library/terms/' . $id;
}
// 期次路由吃的是「期号」而不是 issue_id，用库里真实存在的期号，别拿 1..40 硬试
foreach ($pdo->query('SELECT DISTINCT no FROM hq_issue WHERE no IS NOT NULL')->fetchAll(PDO::FETCH_COLUMN) as $no) {
    $urls[] = '/library/issues/' . $no;
}
$urls = array_values(array_unique($urls));

$pageHits = 0;
$workHits = 0;
$pages = 0;
/** @var array{429:int, 404:int, other:int} $skippedBy */
$skippedBy = ['429' => 0, '404' => 0, 'other' => 0];

foreach ($urls as $path) {
    $code = 0;
    $html = '';

    for ($attempt = 0; $attempt <= $retryLimit; ++$attempt) {
        [$code, $html, $retryAfter] = $fetch($base . $path);

        if ($code === 429) {
            $wait = $retryAfter > 0 ? min(60, $retryAfter) : 5;
            if (!$quiet) {
                printf("  · 触发限流，等待 %d 秒后重试（%s）\n", $wait, $path);
            }
            sleep($wait);

            continue;
        }

        break;
    }

    usleep((int) ($delay * 1_000_000));

    if ($code !== 200 || $html === '') {
        // 429 走到这里说明重试用尽 —— 必须单独计数。把「没测到」算成「没泄漏」
        // 是这个脚本最危险的失败方式。
        $skippedBy[$code === 429 ? '429' : ($code === 404 ? '404' : 'other')]++;
        if ($code === 429) {
            printf("  ! %s 仍未取到（限流），这一页没验证\n", $path);
        }

        continue;
    }
    ++$pages;

    // 检索页会把用户输入原样回显（标题、h1、搜索框 value）。那不是站内内容泄漏 ——
    // 是读者自己打的字。剥掉这几处再扫，剩下的命中才可能是结果区泄漏。
    if (str_starts_with($path, '/library/search')) {
        $html = (string) preg_replace(
            ['~<title>.*?</title>~s', '/检索「[^」]*」/u', '/value="[^"]*"/', '/content="[^"]*"/'],
            '',
            $html
        );
    }

    foreach ($forms as $form) {
        if (!str_contains($html, $form)) {
            continue;
        }
        ++$pageHits;
        if ($quiet) {
            continue;
        }

        // 区分「链接/标签里」与「叙述正文里」——前者说明结构化过滤漏了，
        // 后者说明是自由文本，两者的修法完全不同
        $linkHits = substr_count($html, '>' . $form . '</a>');
        preg_match_all('/[^<>]{0,20}' . preg_quote($form, '/') . '[^<>]{0,20}/u', $html, $m);
        $sample = $m[0][0] ?? '';
        printf(
            "  %-52s 「%s」×%-3d 链接内 %d 处  例：%s\n",
            $path,
            $form,
            substr_count($html, $form),
            $linkHits,
            str_replace(["\n", "\r"], ' ', trim(strip_tags($sample)))
        );
    }

    // 作品层：任何页面出现指向「本应消失」作品的链接，都说明某条导航路径
    // 还留着它 —— 列表、期次、词条的作品卡、同词作品、相关推荐，全在这一条上。
    // 只认 code 不认标题：《错位》这种标题本身就是常用词，按文本匹配必然误报。
    foreach ($goneWorks as $goneCode => $goneTitle) {
        if (!str_contains($html, '/library/works/' . $goneCode)) {
            continue;
        }
        ++$workHits;
        if ($quiet) {
            continue;
        }
        printf(
            "  %-52s 仍链接到已消失的作品《%s》（%d 处）\n",
            $path,
            $goneTitle,
            substr_count($html, '/library/works/' . $goneCode)
        );
    }
}

if ($pageHits === 0 && $workHits === 0) {
    echo "  （无残留）\n";
}

/* ── 3. 检索层：搜被隐藏的词，必须查不到 ─────────────────────── */

// 通用页面扫描做不了这条断言：检索页必然把用户输入回显出来（标题、h1、
// 「没有找到「××」」），回显不是泄漏，却会被当成泄漏。所以单独验一件事：
// **拿隐藏词去搜，结果必须是 0 篇**。搜得到，就等于告诉读者「这个词存在过」。
echo "\n【检索层】按隐藏词形检索的结果（应恒为 0 篇）\n";

$searchFail = 0;
foreach ($forms as $form) {
    $code = 0;
    $html = '';

    for ($attempt = 0; $attempt <= $retryLimit; ++$attempt) {
        [$code, $html, $retryAfter] = $fetch($base . '/library/search?q=' . rawurlencode($form));
        if ($code === 429) {
            sleep($retryAfter > 0 ? min(60, $retryAfter) : 5);

            continue;
        }

        break;
    }
    usleep((int) ($delay * 1_000_000));

    if ($code !== 200) {
        ++$searchFail;
        printf("  ! 「%s」检索页 HTTP %d，未验证\n", $form, $code);

        continue;
    }

    if (preg_match('/共\s*(\d+)\s*篇/u', $html, $m)) {
        $count = (int) $m[1];
    } else {
        $count = str_contains($html, '没有找到') ? 0 : -1;
    }

    if ($count === 0) {
        continue;
    }

    ++$searchFail;
    printf("  「%s」→ %s\n", $form, $count < 0 ? '无法判定' : $count . ' 篇');
}

if ($searchFail === 0) {
    echo "  （全部 0 篇）\n";
}

/* ── 4. 消亡层：该消失的地址必须真的 404 ─────────────────────── */

// 前三层查的是「有没有漏出来」，这一层查反面：「该关的门关没关」。
// 少了它，一个把过滤写反的实现（什么都 404）能让前三层全绿 —— 页面都不存在，
// 当然没有残留。反过来，一个「藏了但页面还开着」的实现也会在前三层暴露。
// 两面都验，才叫验过。
echo "\n【消亡层】应当 404 的地址（应恒为 404）\n";

// 状态探测要自己处理限流：429 当成「应当 404 却返回 429」会误报，
// 而误报比漏报更糟 —— 它让人开始不信任这个脚本。
$status = static function (string $url) use ($fetch, $retryLimit): int {
    for ($attempt = 0; $attempt <= $retryLimit; ++$attempt) {
        [$code, , $retryAfter] = $fetch($url);
        if ($code === 429) {
            sleep($retryAfter > 0 ? min(60, $retryAfter) : 5);

            continue;
        }

        return $code;
    }

    return 429;
};

$aliveFail = 0;

foreach ($hiddenTerms as $t) {
    $path = '/library/terms/' . (int) $t['id'];
    $code = $status($base . $path);
    usleep((int) ($delay * 1_000_000));
    if ($code !== 404) {
        ++$aliveFail;
        printf("  词条「%s」（#%d）返回 %d，应当 404\n", $t['canonical'], (int) $t['id'], $code);
    }
}

foreach ($goneWorks as $goneCode => $goneTitle) {
    $path = '/library/works/' . $goneCode;
    $code = $status($base . $path);
    usleep((int) ($delay * 1_000_000));
    if ($code !== 404) {
        ++$aliveFail;
        printf("  作品《%s》（%s）返回 %d，应当 404\n", $goneTitle, $goneCode, $code);
    }
}

if ($aliveFail === 0) {
    echo "  （全部 404）\n";
}

printf(
    "\n扫描 %d 个页面；跳过 %d 个（404 %d / 限流 %d / 其他 %d）。\n"
    . "数据层 %d 处待遮蔽、呈现层词形 %d 处残留、呈现层作品链接 %d 处残留、"
    . "检索层 %d 个失败、消亡层 %d 个该 404 却没 404。\n",
    $pages,
    $skippedBy['404'] + $skippedBy['429'] + $skippedBy['other'],
    $skippedBy['404'],
    $skippedBy['429'],
    $skippedBy['other'],
    $dataHits,
    $pageHits,
    $workHits,
    $searchFail,
    $aliveFail
);

// 有限流页 = 结论不完整，必须报错退出，不能让人以为「扫过了没问题」
if ($skippedBy['429'] > 0) {
    fwrite(STDERR, "警告：有 " . $skippedBy['429'] . " 个页面因限流未验证，本次结论不完整。\n");
    exit(2);
}

// 数据层命中是「守卫要处理的工作量」，不是失败 —— 呈现层残留、作品链接残留、
// 检索命中、该 404 却没 404，这四类才算。
exit(($pageHits + $workHits + $searchFail + $aliveFail) > 0 ? 1 : 0);
