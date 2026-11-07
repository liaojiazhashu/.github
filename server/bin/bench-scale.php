<?php

declare(strict_types=1);

/**
 * 1300 篇规模演练（只在内存里成立，跑完无条件回滚）。
 *
 * 为什么要这个脚本：「确保能承载接下来连续 1300 篇拆解」这件事，不能用「按理论算，
 * 加了索引就没问题」来交差 —— 索引有没有被用上、二级查询会不会退化成全表扫，
 * 只有把真实数据量放进去跑一遍才知道。
 *
 * 做法：在一个事务里把作品补到 1300 篇、拆解补到 1300 条、关键词关联补到 12800 行、
 * 词条补到 368 个，然后逐条量关键查询的耗时与执行计划，最后 **rollback**。
 * 生产数据一行都不留；auto_increment 会往前走一点，那是唯一痕迹。
 *
 * ⚠️ 已知局限：事务内插入不会刷新 InnoDB 的统计信息，所以**优化器的选择不可信** ——
 * 它仍按演练前的行数估成本。因此脚本最后加了一段「强制走 / 强制不走」的对照实验：
 * 判断一个索引有没有价值，只看那两个数差多少，不看 EXPLAIN 里 key 是不是空的。
 *
 * 用法：php -c php.ini bin/bench-scale.php [--reps=15]
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Infrastructure\Pdo\LibraryRepository;
use App\Infrastructure\Pdo\TermRepository;
use App\Infrastructure\Pdo\WorkRepository;

$reps = 15;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--reps=')) {
        $reps = max(3, (int) substr($arg, 7));
    }
}

$env = parse_ini_file(dirname(__DIR__) . '/.env', false, INI_SCANNER_RAW);

/**
 * 只做一件事：把最近一次 prepare 的 SQL 记下来，好在压测后对它跑 EXPLAIN。
 * 耗时只能说明「现在多快」，EXPLAIN 才能说明「加了数据之后会不会突然变慢」。
 */
final class RecordingPdo extends PDO
{
    /** @var list<string> */
    public array $sqls = [];

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->sqls[] = $query;

        return parent::prepare($query, $options);
    }

    public function reset(): void
    {
        $this->sqls = [];
    }
}

$pdo = new RecordingPdo(
    sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $env['DB_HOST'], $env['DB_PORT'], $env['DB_NAME']),
    $env['DB_USER'],
    $env['DB_PASS']
);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

const TARGET_WORKS   = 1300;
const TARGET_TERMS   = 368;
const TERMS_PER_WORK = 10;

$t0 = hrtime(true);

$pdo->beginTransaction();

try {
    // ---------------------------------------------------------------- 造数
    $issueId  = (int) $pdo->query('SELECT id FROM hq_issue ORDER BY id LIMIT 1')->fetchColumn();
    $authorId = (int) $pdo->query('SELECT id FROM hq_author ORDER BY id LIMIT 1')->fetchColumn();
    $genreId  = (int) $pdo->query('SELECT id FROM hq_genre ORDER BY id LIMIT 1')->fetchColumn();

    // 词条补到 368 个：1300 篇按每篇 10 个词，单篇相关词条的共现查询才有真实基数
    $have = (int) $pdo->query('SELECT COUNT(*) FROM hq_index_term')->fetchColumn();
    if ($have < TARGET_TERMS) {
        $cats = ['setting', 'world', 'image'];
        $rows = [];
        $params = [];
        for ($i = $have; $i < TARGET_TERMS; $i++) {
            $rows[] = '(?, ?, ?, 1, \'active\', \'visible\', NOW(), NOW())';
            $params[] = '演练词条' . $i;
            $params[] = $cats[$i % 3];
            $params[] = '规模演练用词条，跑完即删。';
        }
        $pdo->prepare(
            'INSERT INTO hq_index_term (canonical, category, definition, is_canonical, status, visibility, created_at, updated_at)
             VALUES ' . implode(',', $rows)
        )->execute($params);
    }
    $termIds = array_map('intval', $pdo->query(
        'SELECT id FROM hq_index_term WHERE status = \'active\' AND visibility = \'visible\' ORDER BY id'
    )->fetchAll(PDO::FETCH_COLUMN));

    $existing = (int) $pdo->query('SELECT COUNT(*) FROM hq_work')->fetchColumn();
    $todo     = max(0, TARGET_WORKS - $existing);
    fwrite(STDOUT, sprintf("造数：作品 %d → %d（+%d），词条 %d 个\n", $existing, TARGET_WORKS, $todo, count($termIds)));

    $insertWork = $pdo->prepare(
        'INSERT INTO hq_work (code, issue_id, author_id, genre_id, title, word_count, status, visibility,
                              source, published_at, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, \'published\', \'visible\', \'import\', ?, NOW(), NOW())'
    );
    $insertAnalysis = $pdo->prepare(
        'INSERT INTO hq_analysis (code, work_id, work_version_id, status, visibility, model, prompt_version,
                                  input_hash, is_current, published_at, created_at, updated_at)
         VALUES (?, ?, 0, \'published\', \'visible\', \'deepseek-flash\', \'v1\', ?, 1, NOW(), NOW(), NOW())'
    );
    $insertCard = $pdo->prepare(
        'INSERT INTO hq_index_card (analysis_id, one_liner, created_at, updated_at) VALUES (?, ?, NOW(), NOW())'
    );
    $insertTerm = $pdo->prepare(
        'INSERT INTO hq_work_term (work_id, term_id, role, weight, analysis_id, created_at) VALUES (?, ?, ?, ?, ?, NOW())'
    );
    $linkCurrent = $pdo->prepare('UPDATE hq_work SET current_analysis_id = ? WHERE id = ?');

    $termCount = count($termIds);
    for ($n = 0; $n < $todo; $n++) {
        $seq  = $existing + $n + 1;
        $code = 'BNCH' . str_pad((string) $seq, 22, '0', STR_PAD_LEFT);

        $insertWork->execute([
            $code,
            $issueId,
            $authorId,
            $genreId,
            '演练作品 ' . $seq,
            1800 + ($seq % 4200),
            date('Y-m-d H:i:s', time() - $seq * 7200),
        ]);
        $workId = (int) $pdo->lastInsertId();

        $insertAnalysis->execute([str_pad((string) $seq, 26, 'B', STR_PAD_LEFT), $workId, hash('sha256', $code)]);
        $analysisId = (int) $pdo->lastInsertId();

        $insertCard->execute([$analysisId, '演练用一句话索引，第 ' . $seq . ' 篇。']);
        $linkCurrent->execute([$analysisId, $workId]);

        for ($k = 0; $k < TERMS_PER_WORK; $k++) {
            $tid = $termIds[($seq * TERMS_PER_WORK + $k) % $termCount];
            $insertTerm->execute([$workId, $tid, $k < 2 ? 'main' : 'sub', 90 - $k * 6, $analysisId]);
        }

        if ($n % 200 === 199) {
            fwrite(STDOUT, sprintf("  … 已造 %d 篇\n", $n + 1));
        }
    }

    $works = (int) $pdo->query('SELECT COUNT(*) FROM hq_work')->fetchColumn();
    $links = (int) $pdo->query('SELECT COUNT(*) FROM hq_work_term')->fetchColumn();
    fwrite(STDOUT, sprintf("造数完成：作品 %d 篇 / 词条 %d 个 / 关联 %d 行，用时 %.1fs\n\n", $works, count($termIds), $links, (hrtime(true) - $t0) / 1e9));

    // ---------------------------------------------------------------- 量测
    $lib  = new LibraryRepository($pdo);
    $wrk  = new WorkRepository($pdo);
    $term = new TermRepository($pdo);

    $sampleTerm = 0;
    foreach ($pdo->query('SELECT term_id FROM hq_work_term GROUP BY term_id ORDER BY COUNT(*) DESC LIMIT 1') as $r) {
        $sampleTerm = (int) $r['term_id'];
    }
    $sampleWork = 0;
    foreach ($pdo->query('SELECT id FROM hq_work WHERE status = \'published\' ORDER BY id DESC LIMIT 1') as $r) {
        $sampleWork = (int) $r['id'];
    }

    $cases = [
        '前台·作品列表 48/页'      => fn () => $lib->listWorks([], 48),
        '前台·列表计数'            => fn () => $lib->countWorks([]),
        '前台·馆藏统计'            => fn () => $lib->stats(),
        '前台·词条列表 48/页'      => fn () => $lib->termIndex('setting', '', 48, 0),
        '前台·词条总数'            => fn () => $lib->countTerms('setting'),
        '前台·筛选下拉选项'        => fn () => $lib->filterOptions(),
        '前台·词条页（引用最多）'  => fn () => $lib->worksOfTerm($sampleTerm, 60),
        '前台·词条共现推荐'        => fn () => $lib->cooccurringTerms($sampleTerm, 12),
        '前台·同一作品的其它词'    => fn () => $lib->sameTermWorks($sampleWork, 0, 3),
        '前台·按词检索（命中多）'  => fn () => $lib->matchKeywords(['演练词条'], 200),
        '前台·模糊检索'            => fn () => $lib->searchLike('演练作品', 40),
        '前台·期次列表'            => fn () => $lib->publishedIssues(),
        '前台·sitemap 全量'        => fn () => $lib->sitemapEntries(),
        '后台·作品列表 50/页'      => fn () => $wrk->paginate([], 1, 50),
        '后台·作品统计'            => fn () => $wrk->adminStats(),
        '后台·按筛选取全部 id'     => fn () => $wrk->idsByFilter([], 2000),
        '后台·词条列表 50/页'      => fn () => $term->paginate([], 1, 50),
        '后台·词条统计'            => fn () => $term->adminStats(),
        '后台·词条引用作品'        => fn () => $term->worksOfTermForAdmin($sampleTerm, 50),
    ];

    printf("%-26s %9s %9s %9s\n", '查询', '首次ms', '中位ms', '最慢ms');
    printf("%s\n", str_repeat('-', 58));

    $worst = 0.0;
    $bad   = [];
    foreach ($cases as $label => $fn) {
        $times = [];
        $first = null;
        for ($i = 0; $i < $reps; $i++) {
            $s = hrtime(true);
            $fn();
            $ms = (hrtime(true) - $s) / 1e6;
            if ($i === 0) {
                $first = $ms;
            }
            $times[] = $ms;
        }
        sort($times);
        $median = $times[intdiv(count($times), 2)];
        $max    = $times[count($times) - 1];
        $worst  = max($worst, $median);

        printf("%-26s %9.2f %9.2f %9.2f\n", $label, $first, $median, $max);
        if ($median > 200) {
            $bad[] = sprintf('%s 中位 %.0fms', $label, $median);
        }
    }

    printf("\n最慢查询中位 %.1fms；超过 200ms 的：%s\n", $worst, $bad === [] ? '无' : implode('、', $bad));

    // ---------------------------------------------------------- 执行计划
    // 耗时说明「现在多快」，EXPLAIN 说明「再长下去会不会突然变慢」。
    // 只挑最重的几条看，其余靠中位数兜底。
    fwrite(STDOUT, "\n执行计划（看有没有 type=ALL 的全表扫 / filesort）\n");
    printf("%-22s %-14s %-9s %-26s %8s  %s\n", '查询', '表', 'type', 'key', 'rows', 'Extra');
    printf("%s\n", str_repeat('-', 104));

    $plans = [
        '前台·作品列表 48/页'   => [fn () => $lib->listWorks([], 48), []],
        '前台·词条列表 48/页'   => [fn () => $lib->termIndex('setting', '', 48, 0), [':category' => 'setting', ':kw' => '%%']],
        '前台·词条共现推荐'     => [fn () => $lib->cooccurringTerms($sampleTerm, 12), [':tid' => $sampleTerm]],
        '前台·馆藏统计'         => [fn () => $lib->stats(), []],
        '前台·sitemap 全量'     => [fn () => $lib->sitemapEntries(), []],
        '后台·作品列表 50/页'   => [fn () => $wrk->paginate([], 1, 50), []],
    ];

    $alerts = [];
    foreach ($plans as $label => [$fn, $binds]) {
        $pdo->reset();
        $fn();
        foreach ($pdo->sqls as $sql) {
            if (!str_starts_with(ltrim($sql), 'SELECT')) {
                continue;
            }
            // 命名占位符按传入值替换；`?` 是 attachMainTerms 的 IN 列表，统一换成 1 ——
            // EXPLAIN 只关心走不走索引、扫多少行，不关心具体值。
            $sql = preg_replace_callback(
                '/:\w+/',
                static fn (array $m): string => (string) ($binds[substr($m[0], 1)] ?? '1'),
                $sql
            );
            $sql = str_replace('?', '1', $sql);

            foreach ($pdo->query('EXPLAIN ' . $sql) as $row) {
                $flags = [];
                if ((string) $row['type'] === 'ALL') {
                    $flags[] = '全表扫';
                }
                if (str_contains((string) $row['Extra'], 'filesort')) {
                    $flags[] = 'filesort';
                }
                if (str_contains((string) $row['Extra'], 'temporary')) {
                    $flags[] = '临时表';
                }
                printf(
                    "%-22s %-14s %-9s %-26s %8s  %s%s\n",
                    $label,
                    (string) $row['table'],
                    (string) $row['type'],
                    (string) ($row['key'] ?? '-'),
                    (string) $row['rows'],
                    (string) $row['Extra'],
                    $flags === [] ? '' : '  << ' . implode('/', $flags)
                );
                if ($flags !== []) {
                    $alerts[] = $label . ' → ' . $row['table'] . '：' . implode('/', $flags);
                }
            }
        }
    }

    fwrite(STDOUT, $alerts === []
        ? "\n没有全表扫、没有 filesort。\n"
        : "\n需要注意：\n  · " . implode("\n  · ", $alerts) . "\n");

    // ------------------------------------------------ 索引到底有没有用（对照实验）
    // 「EXPLAIN 里 key 有值」不等于「这个索引有用」。唯一可信的判据是：
    // 强制走它 / 强制不走它，耗时差多少。差不出量级就说明它是装饰品，应该删掉 ——
    // 留着只会让每次写入多维护一棵 B 树。
    fwrite(STDOUT, "\n索引对照实验（同一份数据、同一条 SQL，只换索引策略）\n");
    $pdo->reset();
    $lib->listWorks([], 48);
    $listSql = '';
    foreach ($pdo->sqls as $candidate) {
        if (str_contains($candidate, 'FROM hq_work w') && str_contains($candidate, 'ORDER BY')) {
            $listSql = $candidate;
            break;
        }
    }

    if ($listSql === '') {
        fwrite(STDOUT, "  取不到列表 SQL，跳过。\n");
    } else {
        $variants = [
            '优化器自选'                    => $listSql,
            '强制 idx_feed_visible'         => str_replace('FROM hq_work w', 'FROM hq_work w FORCE INDEX (idx_feed_visible)', $listSql),
            '强制忽略 idx_feed_visible'     => str_replace('FROM hq_work w', 'FROM hq_work w IGNORE INDEX (idx_feed_visible)', $listSql),
        ];
        printf("%-30s %10s %10s\n", '策略', '中位ms', '相对');
        printf("%s\n", str_repeat('-', 54));
        $base = null;
        foreach ($variants as $name => $sql) {
            $times = [];
            for ($i = 0; $i < $reps; $i++) {
                $s = hrtime(true);
                $pdo->query($sql)->fetchAll();
                $times[] = (hrtime(true) - $s) / 1e6;
            }
            sort($times);
            $med = $times[intdiv(count($times), 2)];
            $base ??= $med;
            printf("%-30s %10.2f %9.0f%%\n", $name, $med, $med / max(0.01, $base) * 100);
        }
    }

    fwrite(STDOUT, sprintf("\n总耗时 %.1fs。回滚中……\n", (hrtime(true) - $t0) / 1e9));
} finally {
    // 无条件回滚：无论上面哪一步抛异常，生产库都回到原样。
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
}

$after = (int) $pdo->query('SELECT COUNT(*) FROM hq_work')->fetchColumn();
$afterTerms = (int) $pdo->query('SELECT COUNT(*) FROM hq_index_term')->fetchColumn();
fwrite(STDOUT, sprintf("回滚完成：作品 %d 篇 / 词条 %d 个（应为演练前的原始数量）\n", $after, $afterTerms));
