<?php

declare(strict_types=1);

namespace App\Infrastructure\Pdo;

use App\Support\Text;
use PDO;

/**
 * 公开馆藏（前台只读）。与后台仓储的分界线：
 *
 *   · 后台读「全部作品」，前台只读「已发布的那一份」；
 *   · 后台按 id 找，前台按 code 找（对外暴露的是 code，不是自增 id）；
 *   · 前台任何查询都必须自带发布过滤，**不能靠调用方记得加**——
 *     这正是把前台查询单独放在一个类里的理由：漏一次就是泄一篇未发布的作品。
 *
 * 发布口径固定为三件事同时成立（见 AnalysisRepository::publish）：
 *   `w.status='published'` ∧ `w.current_analysis_id = a.id` ∧ `a.status='published'`。
 * 只查前两个会出现「作品写着已发布、分析已被替换」的中间态，所以三个都查。
 *
 * v2.5 起再加**可见性**两个条件（后台的「隐藏」）：
 *   `w.visibility='visible'` ∧ `a.visibility='visible'`。
 *
 * 为什么加在这里而不是各个调用点：隐藏的语义是「与之相关的一切显示都要跟着变」。
 * 计数、期次、词条关联、共现、检索、sitemap、页脚统计全部走这一段 JOIN，
 * 改一处就等于全站生效；分散加判断则一定会漏，而漏掉的那一处就是「藏了还能被搜到」。
 */
final class LibraryRepository
{
    /** 「这篇作品当前对外的那一份分析」——列表、词条、检索全部复用这一段，避免各处口径漂移 */
    private const PUBLISHED_JOIN = "
        JOIN hq_work w
          ON w.id = wt.work_id
         AND w.status = 'published'
         AND w.deleted_at IS NULL
         AND w.visibility = 'visible'
         AND w.current_analysis_id = wt.analysis_id
        JOIN hq_analysis a
          ON a.id = wt.analysis_id
         AND a.status = 'published'
         AND a.visibility = 'visible'
    ";

    /**
     * 「这个词现在索引得到作品吗」。
     *
     * 词库的用途是让人顺着一个词找到作品，所以点进去空空如也的词条不该出现在
     * 列表和分类计数里。v1 时代留下的孤儿词（重跑后没有任何已发布作品引用）
     * 就靠这个条件挡住；词条本身不删，归并历史和别名词都还在。
     *
     * 这里同时挡住**被隐藏的词条**：`t.visibility` 写在这段相关子查询里，
     * 于是凡是用到它的查询（总览、计数、分类、共现、同类、sitemap、首页统计）
     * 全部自动生效 —— 词条被隐藏后，它在任何地方都不再出现，包括作品页上的标签。
     */
    private const TERM_LIVE = "(EXISTS (
        SELECT 1 FROM hq_work_term wtv
          JOIN hq_work wv
            ON wv.id = wtv.work_id
           AND wv.status = 'published'
           AND wv.deleted_at IS NULL
           AND wv.visibility = 'visible'
           AND wv.current_analysis_id = wtv.analysis_id
          JOIN hq_analysis av
            ON av.id = wtv.analysis_id
           AND av.status = 'published'
           AND av.visibility = 'visible'
         WHERE wtv.term_id = t.id)
        AND t.visibility = 'visible')";

    /**
     * 「这个词值得出现在词库里吗」。
     *
     * 只被一篇作品引用的词，在词库里是死货：点进去只有那一篇，索引就退化成
     * 那篇作品的目录。所以公开词库（总览、分类计数、同类、共现、检索推荐、对照）
     * 只收两种：
     *   - 通行词表里的词（人工审过，本来就是通用概念，暂时只有一篇也该在）；
     *   - 表外新词，但已经有 ≥2 篇作品用到它 —— 用引用数投票证明它确实通用。
     *
     * **零引用也不收**：词表里有、但还没有一篇作品用到的说法，点开是一片空白。
     * 词表是给分析用的选词范围，不是公开目录 —— 目录里每一条都得有作品可看。
     *
     * 词条详情页仍走 TERM_LIVE（≥1 篇）：作品页上的关键词必须点得进，
     * 否则读者点一下就 404。
     */
    /**
     * 这个词在**已发布**作品里的引用数。
     *
     * JOIN 与 PUBLISHED_JOIN 等价，只是别名换了一套（wtc/wc/ac）。常量不能传参，
     * 而这段要嵌进 WHERE 子查询里，与 SELECT 里的 `wt` 不在同一作用域。
     */
    private const TERM_REFS = "(SELECT COUNT(*)
          FROM hq_work_term wtc
          JOIN hq_work wc
            ON wc.id = wtc.work_id
           AND wc.status = 'published'
           AND wc.deleted_at IS NULL
           AND wc.visibility = 'visible'
           AND wc.current_analysis_id = wtc.analysis_id
          JOIN hq_analysis ac
            ON ac.id = wtc.analysis_id
           AND ac.status = 'published'
           AND ac.visibility = 'visible'
         WHERE wtc.term_id = t.id)";

    private const TERM_INDEXABLE = "(" . self::TERM_LIVE
        . " AND " . self::TERM_REFS . " >= 1"
        . " AND (t.is_canonical = 1 OR " . self::TERM_REFS . " >= 2))";

    /**
     * 「这篇作品在公开区算不算一篇」。
     *
     * 公开区唯一的内容就是拆解本身。所以一篇作品的当前拆解被后台隐藏时，
     * 它在馆里等同于**从未被拆解过**：不进列表、不计期次、不上首页、
     * 不进 sitemap、详情页 404，它引用的关键词也不再计入任何数字。
     *
     * 这与「作品级隐藏」在公开区看起来完全一样，但后台语义不同：作品级隐藏
     * 是整篇下线；拆解级隐藏是原文仍在、只是这一次结论收存。这个区别只属于后台，
     * 公开区不该露出任何痕迹 —— 露出一句「拆解暂不公开」，就等于承认馆里
     * 有这篇作品的拆解，而它本该像不存在一样。
     *
     * 为什么写成一段常量、而不是要求每个调用方自己 JOIN hq_analysis：散落的
     * JOIN 一定会漏一处，而漏掉的那一处就是「藏了还看得见」。1300 篇的规模下，
     * 主键相关的 EXISTS 每行一次 eq_ref 查找，代价可以忽略。
     */
    private const LIVE_WORK = "(w.status = 'published'
        AND w.deleted_at IS NULL
        AND w.visibility = 'visible'
        AND w.current_analysis_id IS NOT NULL
        AND EXISTS (
            SELECT 1 FROM hq_analysis al
             WHERE al.id = w.current_analysis_id
               AND al.status = 'published'
               AND al.visibility = 'visible'))";

    /** 排序 → [排序表达式, 方向, 游标列] */    private const SORTS = [
        'newest' => ['w.id', 'DESC', 'id'],
        'words'  => ['w.word_count', 'DESC', 'word_count'],
        'title'  => ['w.title', 'ASC', 'title'],
    ];

    public function __construct(private readonly PDO $pdo)
    {
    }

    // ------------------------------------------------------------------ 列表

    /**
     * 已发布作品列表。游标分页（keyset），不用 OFFSET —— 深翻页在 5.6 上会退化成全扫。
     *
     * 千篇级馆藏后一页只有「下一页」是走不通的：读者从第 3 页想回第 2 页只能一路点回首页。
     * 这里加 $backward 模式——查「游标之前的一页」：排序整体取镜像，取 n+1 条再翻回来，
     * 下一页/上一页两条链都由真实查询担保存在，不会点进去一片空白。
     *
     * @param array{issue?:int, genre?:string, tag?:string, term?:int, year?:int,
     *              min_words?:int, max_words?:int, sort?:string} $filter
     * @param array{k:string|int, id:int}|null $cursor forward 模式=上一页最后一行的游标；
     *                                                 backward 模式=本页第一行的游标（查它前面的一页）
     * @return array{rows:list<array<string,mixed>>, next:array{k:string|int,id:int}|null,
     *               prev:array{k:string|int,id:int}|null, sort:string}
     *         next/prev 分别是「往后翻」「往前翻」要带的锚点；为 null 表示该方向没有页
     */
    public function listWorks(array $filter, int $limit, ?array $cursor = null, bool $backward = false): array
    {
        $limit  = max(1, min(48, $limit));
        $sort   = isset(self::SORTS[(string) ($filter['sort'] ?? '')]) ? (string) $filter['sort'] : 'newest';
        [$expr, $dir, $key] = self::SORTS[$sort];

        [$where, $params] = $this->workFilters($filter);

        if ($cursor !== null) {
            // keyset：先比排序键，键相同再比 id。三列一起才能保证「不重不漏」
            if ($backward) {
                // 镜像：前进序里的「前一页」= 游标之前、离游标最近的 n 行
                $cmp = $dir === 'DESC' ? '>' : '<';
                $where[] = sprintf(
                    '(%s %s :ck OR (%s = :ck2 AND w.id > :cid))',
                    $expr,
                    $cmp,
                    $expr
                );
            } else {
                $cmp = $dir === 'DESC' ? '<' : '>';
                $where[] = sprintf(
                    '(%s %s :ck OR (%s = :ck2 AND w.id < :cid))',
                    $expr,
                    $cmp,
                    $expr
                );
            }
            $params[':ck']  = $cursor['k'];
            $params[':ck2'] = $cursor['k'];
            $params[':cid'] = $cursor['id'];
        }

        // backward 模式按前进序的镜像排，离游标最近的排最前；多取的那 1 条是最远的，弹掉
        $orderDir  = $backward ? ($dir === 'DESC' ? 'ASC' : 'DESC') : $dir;
        $orderTie  = $backward ? 'ASC' : 'DESC';
        // 过滤条件（$where）里的 LIVE_WORK 已经要求当前拆解可见，所以列表里不会
        // 出现「作品在、拆解被收存」的行 —— 那种状态在公开区等于从未被拆解过。
        // 这里仍显式 JOIN 一次，是为了让投影字段（期次、作者、一句话索引）与过滤
        // 取自同一份分析：万一将来有人改过滤，投影也不会悄悄落到旧版本上。
        $sql = 'SELECT w.id, w.code, w.title, w.word_count, w.published_at,
                       w.issue_id, w.created_at,
                       i.no AS issue_no, i.label AS issue_label,
                       au.name AS author_name, g.name AS genre_name,
                       ic.one_liner
                  FROM hq_work w
                  JOIN hq_analysis a ON a.id = w.current_analysis_id
                                    AND a.status = \'published\'
                                    AND a.visibility = \'visible\'
                  LEFT JOIN hq_issue i ON i.id = w.issue_id
                  LEFT JOIN hq_author au ON au.id = w.author_id
                  LEFT JOIN hq_genre g ON g.id = w.genre_id
                  LEFT JOIN hq_index_card ic ON ic.analysis_id = a.id
                 WHERE ' . implode(' AND ', $where) . '
                 ORDER BY ' . $expr . ' ' . $orderDir . ', w.id ' . $orderTie . '
                 LIMIT ' . ($limit + 1);

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $hasMore = count($rows) > $limit;
        if ($hasMore) {
            array_pop($rows);
        }
        if ($backward) {
            $rows = array_reverse($rows);   // 镜像序翻回前进序展示
        }

        $next = null;
        $prev = null;
        if ($rows !== []) {
            $first = $rows[0];
            $last  = $rows[count($rows) - 1];
            if ($backward) {
                // 后一页必然存在（就是查询发起时所在的那页），锚在展示序最后一行
                $next = ['k' => $this->cursorKey($sort, $last), 'id' => (int) $last['id']];
                if ($hasMore) {
                    $prev = ['k' => $this->cursorKey($sort, $first), 'id' => (int) $first['id']];
                }
            } else {
                if ($hasMore) {
                    $next = ['k' => $this->cursorKey($sort, $last), 'id' => (int) $last['id']];
                }
                if ($cursor !== null) {
                    // 正常导航里 cursor 非空就意味着前面还有一页；锚在第一行
                    $prev = ['k' => $this->cursorKey($sort, $first), 'id' => (int) $first['id']];
                }
            }
        }

        // 首屏列表要显示「两个主词」，一条额外查询取全部，避免 N+1
        $rows = $this->attachMainTerms($rows);

        return ['rows' => $rows, 'next' => $next, 'prev' => $prev, 'sort' => $sort];
    }

    /**
     * @param array<string, mixed> $filter
     */
    public function countWorks(array $filter): int
    {
        [$where, $params] = $this->workFilters($filter);

        // 与 listWorks 同一口径（都走 LIVE_WORK）：列表显示几条，页头就写几篇。
        // 拆解被隐藏的作品不算 —— 它在公开区等于从未被拆解，不该被数进去。
        $sql = 'SELECT COUNT(*)
                  FROM hq_work w
                  JOIN hq_analysis a ON a.id = w.current_analysis_id
                                    AND a.status = \'published\'
                                    AND a.visibility = \'visible\'
                  LEFT JOIN hq_issue i ON i.id = w.issue_id
                  LEFT JOIN hq_author au ON au.id = w.author_id
                  LEFT JOIN hq_genre g ON g.id = w.genre_id
                 WHERE ' . implode(' AND ', $where);

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @param array<string, mixed> $filter
     * @return array{0:list<string>, 1:array<string,mixed>}
     */
    private function workFilters(array $filter): array
    {
        $where = [self::LIVE_WORK];
        $params = [];

        $issue = (int) ($filter['issue'] ?? 0);
        if ($issue > 0) {
            $where[]         = 'i.no = :issue_no';
            $params[':issue_no'] = $issue;
        }

        $genre = trim((string) ($filter['genre'] ?? ''));
        if ($genre !== '') {
            $where[]         = 'g.code = :genre_code';
            $params[':genre_code'] = $genre;
        }

        $tag = trim((string) ($filter['tag'] ?? ''));
        if ($tag !== '') {
            $where[] = 'EXISTS (SELECT 1 FROM hq_work_tag wtg JOIN hq_tag tg ON tg.id = wtg.tag_id
                                 WHERE wtg.work_id = w.id AND tg.name = :tag_name)';
            $params[':tag_name'] = $tag;
        }

        $term = (int) ($filter['term'] ?? 0);
        if ($term > 0) {
            // 按词条筛选：这个词必须出现在**当前这一份**拆解里。LIVE_WORK 已经
            // 保证 current_analysis_id 指向的拆解可见，所以这里不必再判可见性 ——
            // 这也是把口径收成一段常量的好处：少一处手写判断，就少一处漂移。
            $where[] = 'EXISTS (SELECT 1 FROM hq_work_term wtm
                                 WHERE wtm.work_id = w.id AND wtm.term_id = :term_id
                                   AND wtm.analysis_id = w.current_analysis_id)';
            $params[':term_id'] = $term;
        }

        $min = (int) ($filter['min_words'] ?? 0);
        if ($min > 0) {
            $where[]       = 'w.word_count >= :min_words';
            $params[':min_words'] = min(200000, $min);
        }

        $max = (int) ($filter['max_words'] ?? 0);
        if ($max > 0) {
            $where[]       = 'w.word_count <= :max_words';
            $params[':max_words'] = min(200000, $max);
        }

        // 作者按 code 筛，不按名字：笔名会改、会重名，code 是稳定的那个。
        // 条件写在 au 上，而 listWorks / countWorks 都 LEFT JOIN 了 hq_author，
        // 所以作者页与列表页用的是同一段过滤 —— 页头写几篇，网格就摆几篇。
        $author = trim((string) ($filter['author'] ?? ''));
        if ($author !== '') {
            $where[]            = 'au.code = :author_code';
            $params[':author_code'] = $author;
        }

        $year = (int) ($filter['year'] ?? 0);
        if ($year >= 2000 && $year <= 2100) {
            // 不用 YEAR(published_at)=?：对列做运算会让索引失效（§5.7 禁止事项）
            $where[]       = 'w.published_at >= :year_from AND w.published_at < :year_to';
            $params[':year_from'] = $year . '-01-01 00:00:00';
            $params[':year_to']   = ($year + 1) . '-01-01 00:00:00';
        }

        return [$where, $params];
    }

    /** @param array<string, mixed> $row */
    private function cursorKey(string $sort, array $row): string|int
    {
        return match ($sort) {
            'words' => (int) $row['word_count'],
            'title' => (string) $row['title'],
            default => (int) $row['id'],
        };
    }

    /**
     * 给列表行补上「主词」（最多两个）+ 词条 id，供卡片上直接点进词条页。
     *
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    public function attachMainTerms(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $codes = array_map(static fn (array $r): string => (string) $r['code'], $rows);
        $in    = implode(',', array_fill(0, count($codes), '?'));

        $sql = 'SELECT w.code, t.id, t.canonical, t.category, wt.role, wt.weight
                  FROM hq_work_term wt
                  JOIN hq_work w ON w.id = wt.work_id
                  JOIN hq_index_term t ON t.id = wt.term_id
                  JOIN hq_analysis a ON a.id = wt.analysis_id AND a.visibility = \'visible\'
                 WHERE w.code IN (' . $in . ')
                   AND wt.analysis_id = w.current_analysis_id
                   AND t.status = \'active\'
                   AND t.visibility = \'visible\'
                 ORDER BY w.code, wt.role = \'sub\', wt.weight DESC';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($codes);

        $byCode = [];
        foreach ($stmt->fetchAll() as $term) {
            $byCode[(string) $term['code']][] = [
                'id'        => (int) $term['id'],
                'canonical' => (string) $term['canonical'],
                'category'  => (string) $term['category'],
                'role'      => (string) $term['role'],
                'weight'    => (int) $term['weight'],
            ];
        }

        foreach ($rows as &$row) {
            $row['terms'] = array_slice($byCode[(string) $row['code']] ?? [], 0, 3);
        }

        return $rows;
    }

    // ------------------------------------------------------------------ 作者
    //
    // 作者页回答一个问题：**这个人还写过什么**。
    // 1300 篇入馆之后，读者看完一篇喜欢了，第一反应就是找同一个人的其它篇；
    // 卡片上的作者名如果不是链接，这条路就断了。
    //
    // 篇数与卡片的口径与作品页完全一致（LIVE_WORK），不会出现「页头写 9 篇、
    // 底下摆 8 张卡」这种差一。**但页面在不在，只看人有没有作品记录** ——
    // 作品被隐藏是作品的事，不该连坐到一个从没被授权隐藏的人。

    /**
     * 按 code 取一位作者，附带在馆内的作品数、字数与期次跨度。
     *
     * **存续条件是「馆内有没有这个人的作品记录」，不是「有没有可见作品」。**
     *
     * 这两件事必须分开算。作品的可见性只管作品：一篇被隐藏，它就不存在；
     * 但一个人不会因为作品被隐藏而**从来没写过**。用 INNER JOIN 卡可见性，
     * 结果就是「把一个人的作品全部隐藏」= 顺手把这个人也从公开区抹掉 ——
     * 那是授权范围之外的第二层隐藏（作者级），而用户要的始终只是拆解级。
     *
     * 所以这里是 LEFT JOIN：作品数、字数只数可见的那部分（可以是 0），
     * 而页面在不在，看 `work_records`（已发布、未删除的作品记录条数）。
     * 0 篇的作者页不泄任何作品信息，只承认「这个人在馆里有作品，但目前没有公开的」。
     *
     * @return array<string,mixed>|null
     */
    public function findAuthorByCode(string $code): ?array
    {
        $sql = 'SELECT au.id, au.code, au.name, au.bio,
                       COALESCE(v.works, 0) AS works,
                       COALESCE(v.words, 0) AS words,
                       v.first_issue, v.last_issue,
                       (SELECT COUNT(*) FROM hq_work w0
                         WHERE w0.author_id = au.id
                           AND w0.status = \'published\'
                           AND w0.deleted_at IS NULL) AS work_records
                  FROM hq_author au
                  LEFT JOIN (
                      SELECT w.author_id,
                             COUNT(*) AS works,
                             SUM(w.word_count) AS words,
                             MIN(i.no) AS first_issue,
                             MAX(i.no) AS last_issue
                        FROM hq_work w
                        JOIN hq_analysis a
                          ON a.id = w.current_analysis_id
                         AND a.status = \'published\'
                         AND a.visibility = \'visible\'
                        LEFT JOIN hq_issue i ON i.id = w.issue_id
                       WHERE w.status = \'published\'
                         AND w.deleted_at IS NULL
                         AND w.visibility = \'visible\'
                       GROUP BY w.author_id
                  ) v ON v.author_id = au.id
                 WHERE au.code = :code
                 LIMIT 1';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':code' => $code]);
        $row = $stmt->fetch();

        // 馆内没有这个人的任何作品记录（写错编号、或作品已被移出馆藏）→ 没有这一页
        if (!is_array($row) || (int) $row['work_records'] === 0) {
            return null;
        }

        unset($row['work_records']);
        $row['works'] = (int) $row['works'];
        $row['words'] = (int) $row['words'];

        return $row;
    }

    /**
     * 这位作者跨作品反复写到的词。
     *
     * 排序按「出现在几篇作品里」而不是权重之和：一篇里权重再高也只说明那一次写得多，
     * 跨篇复现才是这个作者真正在意的概念。只有一篇作品时所有词并列 1 篇，
     * 此时按权重兜底 —— 总能给出这一篇的主词。
     *
     * @return list<array<string,mixed>>
     */
    public function authorTerms(int $authorId, int $limit = 16): array
    {
        $limit = max(1, min(48, $limit));

        $sql = 'SELECT t.id, t.canonical, t.category,
                       COUNT(DISTINCT wt.work_id) AS works,
                       MAX(wt.weight) AS weight
                  FROM hq_work w
                  JOIN hq_analysis a
                    ON a.id = w.current_analysis_id
                   AND a.status = \'published\'
                   AND a.visibility = \'visible\'
                  JOIN hq_work_term wt
                    ON wt.work_id = w.id AND wt.analysis_id = w.current_analysis_id
                  JOIN hq_index_term t
                    ON t.id = wt.term_id AND t.status = \'active\' AND t.visibility = \'visible\'
                 WHERE w.author_id = :aid
                   AND w.status = \'published\'
                   AND w.deleted_at IS NULL
                   AND w.visibility = \'visible\'
                 GROUP BY t.id, t.canonical, t.category
                 ORDER BY works DESC, weight DESC, t.canonical
                 LIMIT ' . $limit;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':aid' => $authorId]);

        return $stmt->fetchAll();
    }

    /**
     * 作者索引：按「馆内可见篇数」排的人物清单。
     *
     * 它和 findAuthorByCode 必须用**同一套条件**（published ∧ visible ∧ 未删除 ∧
     * 当前分析已发布且可见）。两处各写一份，迟早出现「索引说 5 篇、点进去只有 3 篇」。
     *
     * 这里用 INNER JOIN，而不是作者页那个 LEFT JOIN：一篇可见作品都没有的人
     * 不进索引 —— 作者页照旧打得开（那是他自己的链接），但把「0 篇」的人
     * 排进一份「谁在写」的清单里，既没有信息量，也等于把隐藏过的作品数量泄出去。
     *
     * @return list<array<string,mixed>>
     */
    public function listAuthors(int $limit = 400): array
    {
        $limit = max(1, min(1000, $limit));

        $sql = 'SELECT au.id, au.code, au.name, au.bio,
                       COUNT(*) AS works,
                       SUM(w.word_count) AS words,
                       MIN(i.no) AS first_issue,
                       MAX(i.no) AS last_issue,
                       MAX(w.published_at) AS last_published_at
                  FROM hq_work w
                  JOIN hq_author au ON au.id = w.author_id
                  JOIN hq_analysis a
                    ON a.id = w.current_analysis_id
                   AND a.status = \'published\'
                   AND a.visibility = \'visible\'
                  LEFT JOIN hq_issue i ON i.id = w.issue_id
                 WHERE w.status = \'published\'
                   AND w.deleted_at IS NULL
                   AND w.visibility = \'visible\'
                 GROUP BY au.id, au.code, au.name, au.bio
                 ORDER BY works DESC, last_published_at DESC, au.name
                 LIMIT ' . $limit;
        $stmt = $this->pdo->query($sql);

        $rows = $stmt === false ? [] : $stmt->fetchAll();

        foreach ($rows as &$row) {
            $row['works'] = (int) $row['works'];
            $row['words'] = (int) $row['words'];
        }
        unset($row);

        return $rows;
    }

    /** 索引里的作者总数（与 listAuthors 同条件，用于「共 N 位」） */
    public function countAuthors(): int
    {
        $sql = 'SELECT COUNT(DISTINCT w.author_id)
                  FROM hq_work w
                  JOIN hq_analysis a
                    ON a.id = w.current_analysis_id
                   AND a.status = \'published\'
                   AND a.visibility = \'visible\'
                 WHERE w.status = \'published\'
                   AND w.deleted_at IS NULL
                   AND w.visibility = \'visible\'
                   AND w.author_id IS NOT NULL';
        $stmt = $this->pdo->query($sql);

        return $stmt === false ? 0 : (int) $stmt->fetchColumn();
    }

    // ------------------------------------------------------------------ 详情
    /**
     * 按 code 取一篇已发布作品（含当前分析）。
     *
     * 这里**挡 visibility，两种隐藏都返回 null**。
     *
     * 公开区唯一的内容就是拆解本身。作品被隐藏是整篇下线；拆解被隐藏，则这篇作品
     * 在馆里应当**从未被拆解过** —— 没有卡片、没有词条、没有数字，那就没有页面。
     * 留一个「作品在馆、拆解暂不公开」的壳，等于告诉读者馆里有这篇的结论，
     * 而它本该不存在。所以两种隐藏在前台收敛成同一个结果：404。
     *
     * 差别只留在后台：作品级隐藏是原文也不对外；拆解级隐藏是原文仍在、结论收存。
     *
     * @return array<string,mixed>|null
     */
    /**
     * 按编号取作品主键，**不看发布状态**。
     *
     * 与 findPublishedByCode 的差别只有一条：下线、隐藏之后也要能拿到 id。
     * 检索索引的重建与清除都得在「已经不是公开状态」的时候跑一次 —— 只查
     * 已发布的话，下线那一刻就找不到这行，索引留在表里，搜出来还是能点进去，
     * 点进去却是 404。
     */
    public function workIdByCode(string $code): int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM hq_work WHERE code = :code AND deleted_at IS NULL LIMIT 1');
        $stmt->execute([':code' => $code]);

        return (int) ($stmt->fetchColumn() ?: 0);
    }

    public function findPublishedByCode(string $code): ?array
    {
        $sql = 'SELECT w.id, w.code, w.title, w.subtitle, w.word_count, w.summary, w.published_at,
                       w.issue_id, w.current_analysis_id,
                       i.no AS issue_no, i.label AS issue_label,
                       au.name AS author_name, au.code AS author_code,
                       g.name AS genre_name, g.code AS genre_code,
                       a.code AS analysis_code, a.model, a.source AS analysis_source,
                       a.created_at AS analysis_created_at, a.updated_at AS analysis_updated_at,
                       a.published_at AS analysis_published_at, a.prompt_version
                  FROM hq_work w
                  JOIN hq_analysis a ON a.id = w.current_analysis_id
                  LEFT JOIN hq_issue i ON i.id = w.issue_id
                  LEFT JOIN hq_author au ON au.id = w.author_id
                  LEFT JOIN hq_genre g ON g.id = w.genre_id
                 WHERE w.code = :code
                   AND w.status = \'published\'
                   AND w.deleted_at IS NULL
                   AND w.visibility = \'visible\'
                   AND a.status = \'published\'
                   AND a.visibility = \'visible\'
                 LIMIT 1';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':code' => $code]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    /**
     * 某篇分析的索引词（带词条 id，前台要能点进词条页）。
     *
     * @return list<array<string,mixed>>
     */
    public function termsOfAnalysis(int $analysisId): array
    {
        $sql = 'SELECT wt.term_id AS id, wt.role, wt.weight, wt.evidence, t.canonical, t.category, t.definition
                  FROM hq_work_term wt
                  JOIN hq_index_term t ON t.id = wt.term_id
                 WHERE wt.analysis_id = :aid AND t.status = \'active\' AND t.visibility = \'visible\'
                 ORDER BY FIELD(t.category, \'setting\',\'world\',\'image\'),
                          wt.role = \'sub\', wt.weight DESC, t.canonical';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':aid' => $analysisId]);

        return $stmt->fetchAll();
    }

    // ------------------------------------------------------------------ 词条

    /**
     * 同一个关键词下的其它作品 —— 页尾推荐用。
     *
     * 按本篇的显著度排序（main 优先，其次 weight），第一个「能连到两篇以上
     * 作品」的词胜出。为什么不是无条件取第一名：一个词如果只有本篇用到，
     * 推荐位就是空的，读者点不到任何东西。所以找的是「最重要的、且真的
     * 索引得到别人」的那个词。
     *
     * @return array{term:string, term_id:int, works:list<array<string,mixed>>}
     */
    public function sameTermWorks(int $workId, int $analysisId, int $limit = 3): array
    {
        $sql = 'SELECT t.id AS term_id, t.canonical AS term, t.category,
                       w.code, w.title, w.word_count, au.name AS author_name
                  FROM hq_work_term wt
                  JOIN hq_index_term t
                    ON t.id = wt.term_id AND t.status = \'active\' AND t.visibility = \'visible\'
                  JOIN hq_work_term x
                    ON x.term_id = t.id AND x.work_id <> :wid
                  JOIN hq_work w
                    ON w.id = x.work_id
                   AND w.status = \'published\'
                   AND w.deleted_at IS NULL
                   AND w.visibility = \'visible\'
                   AND w.current_analysis_id = x.analysis_id
                  JOIN hq_analysis a
                    ON a.id = x.analysis_id
                   AND a.status = \'published\'
                   AND a.visibility = \'visible\'
                  LEFT JOIN hq_author au ON au.id = w.author_id
                 WHERE wt.analysis_id = :aid
                 ORDER BY (wt.role = \'main\') DESC, wt.weight DESC, x.weight DESC
                 LIMIT 40';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':wid' => $workId, ':aid' => $analysisId]);

        $groups = [];
        foreach ($stmt->fetchAll() as $row) {
            $groups[(string) $row['term']][] = $row;
        }

        if ($groups === []) {
            return ['term' => '', 'term_id' => 0, 'works' => []];
        }

        // 先找能连到 ≥2 篇的，实在没有就退而用第一个（至少不是空的）
        $picked = null;
        foreach ($groups as $rows) {
            if (count($rows) >= 2) {
                $picked = $rows;
                break;
            }
        }
        $picked ??= reset($groups);

        return [
            'term'    => (string) $picked[0]['term'],
            'term_id' => (int) $picked[0]['term_id'],
            'works'   => array_slice($picked, 0, max(1, min(6, $limit))),
        ];
    }
    /**
     * 词条总览。热度 = 关联多少篇已发布作品。
     *
     * @return list<array<string,mixed>>
     */
    public function termIndex(?string $category, string $keyword, int $limit, int $offset): array
    {
        $where  = ['t.status = \'active\'', self::TERM_INDEXABLE];
        $params = [];

        if ($category !== null && $category !== '') {
            $where[]           = 't.category = :cat';
            $params[':cat']    = $category;
        }
        if ($keyword !== '') {
            $where[] = '(t.canonical LIKE :kw OR EXISTS (
                            SELECT 1 FROM hq_index_term_alias al WHERE al.term_id = t.id AND al.alias LIKE :kw2))';
            $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $keyword) . '%';
            $params[':kw']  = $like;
            $params[':kw2'] = $like;
        }

        $sql = 'SELECT t.id, t.canonical, t.category,
                       (SELECT COUNT(*)
                          FROM hq_work_term wt ' . self::PUBLISHED_JOIN . '
                         WHERE wt.term_id = t.id) AS works,
                       (SELECT COUNT(*) FROM hq_index_term_alias al WHERE al.term_id = t.id) AS aliases
                  FROM hq_index_term t
                 WHERE ' . implode(' AND ', $where) . '
                 ORDER BY works DESC, t.weight_base DESC, t.canonical ASC
                 LIMIT ' . max(1, min(120, $limit)) . ' OFFSET ' . max(0, $offset);

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    public function countTerms(?string $category, string $keyword = ''): int
    {
        $where  = ['t.status = \'active\'', self::TERM_INDEXABLE];
        $params = [];

        if ($category !== null && $category !== '') {
            $where[]        = 't.category = :cat';
            $params[':cat'] = $category;
        }
        if ($keyword !== '') {
            $where[] = '(t.canonical LIKE :kw OR EXISTS (
                            SELECT 1 FROM hq_index_term_alias al WHERE al.term_id = t.id AND al.alias LIKE :kw2))';
            $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $keyword) . '%';
            $params[':kw']  = $like;
            $params[':kw2'] = $like;
        }

        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM hq_index_term t WHERE ' . implode(' AND ', $where)
        );
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    /**
     * 三类各有多少词条（分类导航用）。只数索引得到作品的词，口径同 termIndex。
     *
     * @return array<string,int>
     */
    public function termCategoryCounts(): array
    {
        $rows = $this->pdo->query(
            'SELECT t.category, COUNT(*) AS n
               FROM hq_index_term t
              WHERE t.status = \'active\' AND ' . self::TERM_INDEXABLE . '
              GROUP BY t.category'
        )->fetchAll();

        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row['category']] = (int) $row['n'];
        }

        return $out;
    }

    /**
     * 单个词条。索引不到作品的词条按「没有这个词」处理 —— 前台不提供空货架。
     *
     * @return array<string,mixed>|null
     */
    public function findTerm(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT t.id, t.canonical, t.category, t.definition, t.status, t.merged_into, t.created_at
               FROM hq_index_term t WHERE t.id = :id AND ' . self::TERM_LIVE . ' LIMIT 1'
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();

        if (!is_array($row)) {
            return null;
        }

        $row['aliases'] = $this->aliasRows($id);
        $row['works']   = $this->countWorksOfTerm($id);

        return $row;
    }

    /** @return list<string> */
    private function aliasRows(int $termId): array
    {
        $stmt = $this->pdo->prepare('SELECT alias FROM hq_index_term_alias WHERE term_id = :id ORDER BY id');
        $stmt->execute([':id' => $termId]);

        return array_values(array_map(static fn (array $r): string => (string) $r['alias'], $stmt->fetchAll()));
    }

    public function countWorksOfTerm(int $termId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM hq_work_term wt ' . self::PUBLISHED_JOIN . ' WHERE wt.term_id = :tid'
        );
        $stmt->execute([':tid' => $termId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * 关联该词条的已发布作品，按权重降序。
     *
     * @return list<array<string,mixed>>
     */
    public function worksOfTerm(int $termId, int $limit = 60): array
    {
        $sql = 'SELECT w.code, w.title, w.word_count, w.published_at, w.summary,
                       wt.role, wt.weight, wt.evidence,
                       i.no AS issue_no, i.label AS issue_label,
                       au.name AS author_name, au.code AS author_code
                  FROM hq_work_term wt ' . self::PUBLISHED_JOIN . '
                  LEFT JOIN hq_issue i ON i.id = w.issue_id
                  LEFT JOIN hq_author au ON au.id = w.author_id
                 WHERE wt.term_id = :tid
                 ORDER BY wt.weight DESC, w.id DESC
                 LIMIT ' . max(1, min(200, $limit));
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':tid' => $termId]);

        return $stmt->fetchAll();
    }

    /**
     * 共现词：在同样的作品里一起出现的其它词条。这是「按词漫游」的入口。
     *
     * @return list<array<string,mixed>>
     */
    public function cooccurringTerms(int $termId, int $limit = 12): array
    {
        $sql = 'SELECT t.id, t.canonical, t.category, COUNT(*) AS n
                  FROM hq_work_term x
                  JOIN hq_work_term y ON y.work_id = x.work_id AND y.analysis_id = x.analysis_id
                                     AND y.term_id <> x.term_id
                  JOIN hq_index_term t ON t.id = y.term_id AND t.status = \'active\'
                  JOIN hq_work w ON w.id = x.work_id AND w.status = \'published\' AND w.deleted_at IS NULL
                                AND w.visibility = \'visible\'
                                AND w.current_analysis_id = x.analysis_id
                  JOIN hq_analysis a ON a.id = x.analysis_id
                                    AND a.status = \'published\' AND a.visibility = \'visible\'
                 WHERE x.term_id = :tid AND ' . self::TERM_INDEXABLE . '
                 GROUP BY t.id, t.canonical, t.category
                 ORDER BY n DESC, t.canonical ASC
                 LIMIT ' . max(1, min(40, $limit));
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':tid' => $termId]);

        return $stmt->fetchAll();
    }

    /**
     * 同分类里的其它词条（按关联作品数排序）。
     *
     * 词条详情页用它在主栏底部补一段导航。没有它的时候，一个只关联一两篇作品的词
     * 会让左栏早早结束、右栏还长着半屏 —— 读者看到的是空白，而不是「这一页讲完了」。
     *
     * @return list<array<string,mixed>>
     */
    public function categoryTerms(int $excludeId, string $category, int $limit = 10): array
    {
        $sql = 'SELECT t.id, t.canonical,
                       (SELECT COUNT(*) FROM hq_work_term wt ' . self::PUBLISHED_JOIN . '
                         WHERE wt.term_id = t.id) AS works
                  FROM hq_index_term t
                 WHERE t.status = \'active\' AND t.category = :cat AND t.id <> :id
                   AND ' . self::TERM_INDEXABLE . '
                 ORDER BY works DESC, t.weight_base DESC, t.canonical ASC
                 LIMIT ' . max(1, min(40, $limit));
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':cat' => $category, ':id' => $excludeId]);

        return $stmt->fetchAll();
    }

    /**
     * 方法页要举一个「主词 vs 长变体」的真实例子，说明「只关联一篇的词是目录，不是索引」。
     *
     * 为什么从库里取而不是写死：写死的时候举了「世代飞船」，后来这个词被后台隐藏了，
     * 方法页就变成在给读者点名一个不该出现的词 —— 而这一页恰恰是全站唯一解释
     * 「词怎么收」的地方，它自己先破了规矩。示例跟着词表走，就再也不会撞。
     *
     * @return array{canonical:string, alias:string}|null
     */
    public function mergeExample(): ?array
    {
        $sql = 'SELECT t.canonical, a.alias
                  FROM hq_index_term_alias a
                  JOIN hq_index_term t ON t.id = a.term_id
                 WHERE t.status = \'active\'
                   AND ' . self::TERM_INDEXABLE . '
                   AND a.alias LIKE CONCAT(\'%\', t.canonical, \'%\')
                   AND CHAR_LENGTH(a.alias) > CHAR_LENGTH(t.canonical)
                 ORDER BY t.id
                 LIMIT 1';
        $row = $this->pdo->query($sql)->fetch();

        if (!is_array($row)) {
            return null;
        }

        return ['canonical' => (string) $row['canonical'], 'alias' => (string) $row['alias']];
    }

    /**
     * 权重分布（每 10 分一档）。词条详情页已不渲染此数据（前台只列不注），
     * 保留作分析口径 —— 后台或回归要看「这个词在各篇里的分量分布」时用。
     *
     * @return array<int,int> 档位 => 篇数
     */
    public function weightSpread(int $termId): array
    {
        $sql = 'SELECT FLOOR(wt.weight / 10) * 10 AS bucket, COUNT(*) AS n
                  FROM hq_work_term wt ' . self::PUBLISHED_JOIN . '
                 WHERE wt.term_id = :tid
                 GROUP BY bucket
                 ORDER BY bucket';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':tid' => $termId]);

        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[(int) $row['bucket']] = (int) $row['n'];
        }

        return $out;
    }

    /**
     * 全部启用词条（只取 id + 规范名），供「相近词建议」做编辑距离。
     *
     * @return list<array{id:int, canonical:string}>
     */
    public function allActiveTerms(): array
    {
        $rows = $this->pdo->query(
            'SELECT t.id, t.canonical FROM hq_index_term t
              WHERE t.status = \'active\' AND ' . self::TERM_INDEXABLE . '
              ORDER BY t.id'
        )->fetchAll();

        return array_map(
            static fn (array $r): array => ['id' => (int) $r['id'], 'canonical' => (string) $r['canonical']],
            $rows
        );
    }

    // ------------------------------------------------------------------ 对照

    /**
     * 有已发布作品的期次。
     *
     * @return list<array<string,mixed>>
     */
    public function publishedIssues(): array
    {
        // 期次计数同 LIVE_WORK 口径：拆解被隐藏的作品不计入任何一期。
        // 否则会出现「第 7 期写着 3 篇、点进去只有 2 篇」—— 而这 1 篇在馆里
        // 本该像从未拆解过一样不存在。
        $sql = 'SELECT i.no, i.label, COUNT(w.id) AS n
                  FROM hq_issue i
                  JOIN hq_work w ON w.issue_id = i.id AND w.status = \'published\' AND w.deleted_at IS NULL
                                AND w.visibility = \'visible\'
                  JOIN hq_analysis a ON a.id = w.current_analysis_id AND a.status = \'published\'
                                    AND a.visibility = \'visible\'
                 GROUP BY i.no, i.label
                 ORDER BY i.no DESC';

        return $this->pdo->query($sql)->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public function findIssueByNo(int $no): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, no, label, starts_at, ends_at, status FROM hq_issue WHERE no = :no LIMIT 1');
        $stmt->execute([':no' => $no]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    // ------------------------------------------------------------- 筛选与统计

    /**
     * 列表页的筛选项（只列有作品的那些，空选项没有意义）。
     *
     * @return array{issues:list<array<string,mixed>>, genres:list<array<string,mixed>>, tags:list<array<string,mixed>>, years:list<int>}
     */
    public function filterOptions(): array
    {
        $issues = $this->publishedIssues();

        // 三个下拉都按 LIVE_WORK 口径：只在「有可见拆解」的作品里统计。
        // 否则会出现一个选项，选了之后一条也筛不出来 —— 那比没有这个选项更糟。
        $genres = $this->pdo->query(
            'SELECT g.code, g.name, COUNT(w.id) AS n
               FROM hq_genre g
               JOIN hq_work w ON w.genre_id = g.id AND w.status = \'published\' AND w.deleted_at IS NULL
                             AND w.visibility = \'visible\'
               JOIN hq_analysis a ON a.id = w.current_analysis_id AND a.status = \'published\'
                                 AND a.visibility = \'visible\'
               GROUP BY g.code, g.name, g.sort
               ORDER BY g.sort'
        )->fetchAll();

        $tags = $this->pdo->query(
            'SELECT t.name, COUNT(wt.work_id) AS n
               FROM hq_tag t
               JOIN hq_work_tag wt ON wt.tag_id = t.id
               JOIN hq_work w ON w.id = wt.work_id AND w.status = \'published\' AND w.deleted_at IS NULL
                             AND w.visibility = \'visible\'
               JOIN hq_analysis a ON a.id = w.current_analysis_id AND a.status = \'published\'
                                 AND a.visibility = \'visible\'
               GROUP BY t.name
               ORDER BY n DESC, t.name
               LIMIT 16'
        )->fetchAll();

        $years = array_map(
            static fn (array $r): int => (int) $r['y'],
            $this->pdo->query(
                'SELECT DISTINCT YEAR(w.published_at) AS y FROM hq_work w
                   JOIN hq_analysis a ON a.id = w.current_analysis_id AND a.status = \'published\'
                                     AND a.visibility = \'visible\'
                  WHERE w.status = \'published\' AND w.deleted_at IS NULL AND w.published_at IS NOT NULL
                    AND w.visibility = \'visible\'
                  ORDER BY y DESC'
            )->fetchAll()
        );

        return ['issues' => $issues, 'genres' => $genres, 'tags' => $tags, 'years' => $years];
    }

    /**
     * 馆藏规模（首页与页脚用）。
     *
     * @return array{works:int, words:int, terms:int, issues:int, updated_at:?string}
     */
    public function stats(): array
    {
        // 首页「馆藏 N 篇」与列表同口径（LIVE_WORK）：拆解被隐藏的作品不算。
        // 数字和列表必须出自同一句判断，否则「首页写 14、列表只有 13」这种矛盾
        // 会在隐藏任何一篇时立刻出现。
        $works = $this->pdo->query(
            'SELECT COUNT(*) AS n, COALESCE(SUM(w.word_count), 0) AS words, MAX(w.published_at) AS last_at
               FROM hq_work w
               JOIN hq_analysis a ON a.id = w.current_analysis_id AND a.status = \'published\'
                                 AND a.visibility = \'visible\'
              WHERE w.status = \'published\' AND w.deleted_at IS NULL
                AND w.visibility = \'visible\''
        )->fetch() ?: [];

        // 与 termCategoryCounts 同一个口径：首页三张分类卡的数字加起来要等于页脚那个总数，
        // 差一条都会被人一眼看出来（v1 遗留的孤儿词正是靠这个口径挡在门外）
        $terms = (int) $this->pdo->query(
            'SELECT COUNT(*) FROM hq_index_term t
              WHERE t.status = \'active\' AND ' . self::TERM_INDEXABLE
        )->fetchColumn();

        return [
            'works'      => (int) ($works['n'] ?? 0),
            'words'      => (int) ($works['words'] ?? 0),
            'terms'      => $terms,
            'issues'     => count($this->publishedIssues()),
            'updated_at' => isset($works['last_at']) ? (string) $works['last_at'] : null,
        ];
    }

    // ------------------------------------------------------------------ 检索

    /**
     * L3：关键词表精确匹配（对上即得候选集）。
     *
     * @param list<string> $keywords
     * @return list<array{work_id:int, score:int}>
     */
    public function matchKeywords(array $keywords, int $limit = 200): array
    {
        $keywords = array_values(array_unique(array_filter($keywords, static fn (string $k): bool => $k !== '')));
        if ($keywords === []) {
            return [];
        }

        $keywords = array_slice($keywords, 0, 40);
        $in       = implode(',', array_fill(0, count($keywords), '?'));
        // 第三道可见性：关键词所属的词条。前两道只挡作品与拆解，挡不住「词被隐藏了
        // 但关键词行还躺在物化表里」—— 那会导致搜这个词照旧能搜到，而页面上一个
        // 入口都没有。term_id 为 NULL 的是标题二元组、作者名这类，本来就不属于任何词条。
        $sql      = 'SELECT k.work_id, SUM(k.weight) AS score
                       FROM hq_search_keyword k
                       JOIN hq_work w ON w.id = k.work_id
                                     AND w.status = \'published\'
                                     AND w.deleted_at IS NULL
                                     AND w.visibility = \'visible\'
                       JOIN hq_analysis a ON a.id = w.current_analysis_id
                                         AND a.status = \'published\'
                                         AND a.visibility = \'visible\'
                      WHERE k.keyword IN (' . $in . ')
                        AND (k.term_id IS NULL OR EXISTS (
                                SELECT 1 FROM hq_index_term t
                                 WHERE t.id = k.term_id AND t.visibility = \'visible\'))
                      GROUP BY k.work_id
                      ORDER BY score DESC
                      LIMIT ' . max(1, min(500, $limit));
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($keywords);

        return array_map(
            static fn (array $r): array => ['work_id' => (int) $r['work_id'], 'score' => (int) $r['score']],
            $stmt->fetchAll()
        );
    }

    /**
     * 按 id 批量取列表卡片需要的字段（保持 matchKeywords 的顺序）。
     *
     * @param list<int> $workIds
     * @return list<array<string,mixed>>
     */
    public function listByWorkIds(array $workIds): array
    {
        $workIds = array_values(array_unique(array_map('intval', $workIds)));
        if ($workIds === []) {
            return [];
        }

        $in  = implode(',', array_fill(0, count($workIds), '?'));
        $sql = 'SELECT w.id, w.code, w.title, w.word_count, w.summary, w.published_at,
                       i.no AS issue_no, i.label AS issue_label,
                       au.name AS author_name, g.name AS genre_name, ic.one_liner
                  FROM hq_work w
                  JOIN hq_analysis a ON a.id = w.current_analysis_id
                                    AND a.status = \'published\'
                                    AND a.visibility = \'visible\'
                  LEFT JOIN hq_issue i ON i.id = w.issue_id
                  LEFT JOIN hq_author au ON au.id = w.author_id
                  LEFT JOIN hq_genre g ON g.id = w.genre_id
                  LEFT JOIN hq_index_card ic ON ic.analysis_id = a.id
                 WHERE w.id IN (' . $in . ')
                   AND w.status = \'published\' AND w.deleted_at IS NULL
                   AND w.visibility = \'visible\'';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($workIds);

        return $stmt->fetchAll();
    }

    /**
     * 这批作品里，到底是哪些词条对上了检索串（用来在结果页说明「为什么搜到它」）。
     *
     * 用 LIKE 而不是 IN：读者搜「克制」时对上的词条叫「克制的悲伤」，
     * IN 只认整串相等，会把最该解释的那一条漏掉。
     *
     * @param list<int>    $workIds
     * @param list<string> $keywords
     * @return array<int, list<string>> work_id => 词条规范名
     */
    public function matchedTerms(array $workIds, array $keywords, int $perWork = 3): array
    {
        $workIds  = array_values(array_unique(array_filter(array_map('intval', $workIds), static fn (int $i): bool => $i > 0)));
        $keywords = array_values(array_unique(array_filter(
            array_map(static fn (string $k): string => trim($k), $keywords),
            static fn (string $k): bool => mb_strlen($k, 'UTF-8') >= 2
        )));

        if ($workIds === [] || $keywords === []) {
            return [];
        }

        $keywords = array_slice($keywords, 0, 8);

        $params = [];
        $like   = [];
        foreach ($keywords as $i => $keyword) {
            $like[]           = 't.canonical LIKE :k' . $i;
            $params[':k' . $i] = '%' . str_replace(['%', '_'], ['\%', '\_'], $keyword) . '%';
        }

        $marks = [];
        foreach ($workIds as $i => $workId) {
            $marks[]               = ':w' . $i;
            $params[':w' . $i]     = $workId;
        }

        // 命名占位符与位置占位符**不能混用**（PDO 直接抛 HY093），所以两边都用命名
        $sql = 'SELECT wt.work_id, t.canonical
                  FROM hq_work_term wt
                  JOIN hq_index_term t ON t.id = wt.term_id AND t.status = \'active\' AND t.visibility = \'visible\'
                  JOIN hq_work w ON w.id = wt.work_id AND w.current_analysis_id = wt.analysis_id
                                AND w.visibility = \'visible\'
                 WHERE wt.work_id IN (' . implode(',', $marks) . ')
                   AND (' . implode(' OR ', $like) . ')
                 ORDER BY wt.work_id, wt.weight DESC';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $workId = (int) $row['work_id'];
            if (count($out[$workId] ?? []) >= $perWork) {
                continue;
            }
            $out[$workId][] = (string) $row['canonical'];
        }

        return $out;
    }

    /**
     * LIKE 兜底：只在关键词表对不上时用，且必须带上限（禁止全表裸 LIKE 无界）。
     *
     * @return list<array<string,mixed>>
     */
    /**
     * LIKE 兜底检索。
     *
     * $includeSummary 为 false 时只匹配标题与作者名。用途：查询词本身是一个
     * 已被隐藏的词条时，不拿它去翻作品简介 —— 简介是作者原文，不该因为后台
     * 隐藏了某个词就被改写，但也不能变成那个词的检索入口。
     */
    public function searchLike(string $keyword, int $limit = 40, bool $includeSummary = true): array
    {
        $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $keyword) . '%';

        $match = 'w.title LIKE :kw OR au.name LIKE :kw2';
        if ($includeSummary) {
            $match .= ' OR w.summary LIKE :kw3';
        }

        $sql  = 'SELECT w.id, w.code, w.title, w.word_count, w.summary, w.published_at,
                        i.no AS issue_no, i.label AS issue_label,
                        au.name AS author_name, g.name AS genre_name, ic.one_liner
                   FROM hq_work w
                   JOIN hq_analysis a ON a.id = w.current_analysis_id
                                     AND a.status = \'published\'
                                     AND a.visibility = \'visible\'
                   LEFT JOIN hq_issue i ON i.id = w.issue_id
                   LEFT JOIN hq_author au ON au.id = w.author_id
                   LEFT JOIN hq_genre g ON g.id = w.genre_id
                   LEFT JOIN hq_index_card ic ON ic.analysis_id = a.id
                  WHERE w.status = \'published\' AND w.deleted_at IS NULL
                    AND w.visibility = \'visible\'
                    AND (' . $match . ')
                  ORDER BY w.published_at DESC, w.id DESC
                  LIMIT ' . max(1, min(200, $limit));
        $stmt = $this->pdo->prepare($sql);
        $params = [':kw' => $like, ':kw2' => $like];
        if ($includeSummary) {
            $params[':kw3'] = $like;
        }
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    // ------------------------------------------------------- 检索关键词维护

    /**
     * 重建一篇作品的检索关键词（发布、重跑、编辑后调用）。
     *
     * 关键词来源：标题（2-gram + 整词）、作者名、索引词规范名与别名、建议标题。
     * 每篇最多 400 条 —— 上限是为了让表体积可预期，而不是因为存不下。
     *
     * @param list<string> $terms        规范名
     * @param list<string> $aliases      别名
     * @param list<string> $suggestions  建议标题
     */
    /**
     * 重建一篇作品的关键词索引。
     *
     * @param list<array{id:int, canonical:string}> $terms
     * @param list<array{term_id:int, alias:string}> $aliases
     * @param list<string> $suggestions
     */
    public function replaceKeywords(int $workId, string $title, string $author, array $terms, array $aliases, array $suggestions): int
    {
        /** @var array<string, array{weight:int, term_id:int|null}> $rows */
        $rows = [];

        $add = static function (string $keyword, int $weight, ?int $termId = null) use (&$rows): void {
            $keyword = trim($keyword);
            if ($keyword === '' || mb_strlen($keyword) > 64) {
                return;
            }
            $key = mb_strtolower($keyword);

            if (!isset($rows[$key])) {
                $rows[$key] = ['weight' => $weight, 'term_id' => $termId];

                return;
            }

            // 权重取最高（同一句里出现多次只算一次）；term_id **一旦有就留住** ——
            // 标题二元组权重 100 会盖过词条的 55，若顺势把 term_id 抹成 NULL，
            // 这个词就失去了「属于哪个词条」的归属，隐藏之后还能被搜到。
            $rows[$key]['weight'] = max($rows[$key]['weight'], $weight);
            if ($rows[$key]['term_id'] === null) {
                $rows[$key]['term_id'] = $termId;
            }
        };

        foreach (Text::grams($title) as $gram) {
            $add($gram, 100);
        }
        $add($title, 100);
        $add($author, 80);

        foreach ($terms as $term) {
            $add((string) $term['canonical'], 70, (int) $term['id']);
            // 词条的 2-gram 也要进表：读者搜「克制」时要能找到叫「克制的悲伤」那个词条。
            // 只存整词的话，短于词条的查询永远搜不到，检索会显得很笨。
            // gram 同样挂 term_id —— 否则隐藏词条之后，搜它的片段仍然能搜到。
            foreach (Text::grams((string) $term['canonical']) as $gram) {
                $add($gram, 55, (int) $term['id']);
            }
        }
        foreach ($aliases as $alias) {
            $add((string) $alias['alias'], 40, (int) $alias['term_id']);
        }
        foreach ($suggestions as $suggestion) {
            $add(mb_substr($suggestion, 0, 64), 30);
        }

        // 权重高的先留，超上限就截断（保留最有用的一批）
        uasort($rows, static fn (array $a, array $b): int => $b['weight'] <=> $a['weight']);
        $rows = array_slice($rows, 0, 400, true);

        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('DELETE FROM hq_search_keyword WHERE work_id = :w')->execute([':w' => $workId]);
            $insert = $this->pdo->prepare(
                'INSERT IGNORE INTO hq_search_keyword (work_id, keyword, term_id, weight, created_at)
                 VALUES (:w, :k, :term, :weight, NOW())'
            );
            foreach ($rows as $keyword => $row) {
                $insert->execute([
                    ':w' => $workId,
                    ':k' => $keyword,
                    ':term' => $row['term_id'],
                    ':weight' => $row['weight'],
                ]);
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return count($rows);
    }

    public function deleteKeywords(int $workId): void
    {
        $this->pdo->prepare('DELETE FROM hq_search_keyword WHERE work_id = :w')->execute([':w' => $workId]);
    }

    /**
     * 重建关键词的原料：标题、作者、索引词（规范名 + 别名）、建议标题。
     *
     * 取的是这篇作品**当前对外的那一份分析**；若还没发布（比如刚导入就想建索引），
     * 退到它最近一次成功的分析 —— 索引先建好，发布时就不必再全量扫一遍。
     *
     * @return array{title:string, author:string, terms:list<string>, aliases:list<string>, suggestions:list<string>}|null
     */
    public function keywordSource(int $workId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT w.title, w.current_analysis_id, au.name AS author_name
               FROM hq_work w LEFT JOIN hq_author au ON au.id = w.author_id
              WHERE w.id = :id AND w.deleted_at IS NULL LIMIT 1'
        );
        $stmt->execute([':id' => $workId]);
        $work = $stmt->fetch();
        if (!is_array($work)) {
            return null;
        }

        $analysisId = (int) ($work['current_analysis_id'] ?? 0);
        if ($analysisId === 0) {
            $latest = $this->pdo->prepare(
                'SELECT id FROM hq_analysis WHERE work_id = :w AND status IN (\'succeeded\',\'published\')
                  ORDER BY id DESC LIMIT 1'
            );
            $latest->execute([':w' => $workId]);
            $analysisId = (int) ($latest->fetchColumn() ?: 0);
        }

        $terms     = [];
        $aliases   = [];
        $suggested = [];

        if ($analysisId > 0) {
            // 这里**故意不过滤 visibility**，只过滤 status。
            //
            // 关键词表是「这个词在这篇里出现过」的事实记录，不是可见性判断。
            // 可见性在 matchKeywords 查询时按 term_id 判断（与 PUBLISHED_JOIN 同一口径）。
            //
            // 反过来做（隐藏就不写入）会造成一个很隐蔽的坑：半夜定时跑了
            // `library:reindex`，正好某词处于隐藏状态，它的关键词就永久缺失 ——
            // 之后恢复可见，搜这个词仍然搜不到，而页面一切正常，没人知道。
            $rows = $this->pdo->prepare(
                'SELECT t.id, t.canonical
                   FROM hq_work_term wt
                   JOIN hq_index_term t ON t.id = wt.term_id AND t.status = \'active\'
                  WHERE wt.analysis_id = :aid'
            );
            $rows->execute([':aid' => $analysisId]);
            $termRows = $rows->fetchAll();

            $aliasStmt = $this->pdo->prepare(
                'SELECT alias FROM hq_index_term_alias WHERE term_id = :t'
            );
            foreach ($termRows as $row) {
                $terms[] = ['id' => (int) $row['id'], 'canonical' => (string) $row['canonical']];
                $aliasStmt->execute([':t' => (int) $row['id']]);
                foreach ($aliasStmt->fetchAll() as $alias) {
                    $aliases[] = ['term_id' => (int) $row['id'], 'alias' => (string) $alias['alias']];
                }
            }

            $sug = $this->pdo->prepare('SELECT title FROM hq_suggestion WHERE analysis_id = :aid');
            $sug->execute([':aid' => $analysisId]);
            foreach ($sug->fetchAll() as $row) {
                $suggested[] = (string) $row['title'];
            }
        }

        return [
            'title'       => (string) $work['title'],
            'author'      => (string) ($work['author_name'] ?? ''),
            'terms'       => $terms,
            'aliases'     => $aliases,
            'suggestions' => $suggested,
        ];
    }

    /**
     * @return list<int>
     */
    public function publishedWorkIds(): array
    {
        $rows = $this->pdo->query(
            'SELECT w.id FROM hq_work w
               JOIN hq_analysis a ON a.id = w.current_analysis_id AND a.status = \'published\'
                                 AND a.visibility = \'visible\'
              WHERE w.status = \'published\' AND w.deleted_at IS NULL
                AND w.visibility = \'visible\'
              ORDER BY w.id'
        )->fetchAll();

        return array_map(static fn (array $r): int => (int) $r['id'], $rows);
    }

    /**
     * sitemap 用：已发布作品的 code 与最后修改时间、有作品的词条 id、有作品的期次号。
     *
     * @return array{works:list<array{code:string, lastmod:?string}>, terms:list<int>, issues:list<int>}
     */
    public function sitemapEntries(): array
    {
        $works = $this->pdo->query(
            'SELECT w.code, COALESCE(a.published_at, w.published_at) AS lastmod
               FROM hq_work w
               JOIN hq_analysis a ON a.id = w.current_analysis_id AND a.status = \'published\'
                                 AND a.visibility = \'visible\'
              WHERE w.status = \'published\' AND w.deleted_at IS NULL
                AND w.visibility = \'visible\'
              ORDER BY w.id DESC'
        )->fetchAll();

        // 只收「词库里真的列得出来」的词条。用 PUBLISHED_JOIN 会把 57 个全收进来，
        // 其中 5 个是表外单篇 —— 它们在 /library/terms 里根本不出现，
        // 收录进 sitemap 等于让搜索引擎去索引一个列表里找不到的页面。
        $terms = $this->pdo->query(
            'SELECT DISTINCT t.id AS term_id
               FROM hq_index_term t
               JOIN hq_work_term wt ON wt.term_id = t.id ' . self::PUBLISHED_JOIN . '
              WHERE t.status = \'active\' AND ' . self::TERM_INDEXABLE . '
              ORDER BY t.id'
        )->fetchAll();

        $issueNos = array_map(
            static fn (array $r): int => (int) $r['no'],
            $this->publishedIssues()
        );

        // 作者页收「馆内有作品记录」的人 —— 与 findAuthorByCode 的存续条件同一把尺：
        // 页面能打开的，sitemap 里就该有；sitemap 里有的，就一定打得开。
        //
        // 一篇可见作品都没有的人也收：页面是 200（0 篇），不是死链。把他们从 sitemap
        // 里摘掉等于对搜索引擎说「这个人不存在」，那是第二层隐藏。lastmod 只取可见作品的
        // 发布时间 —— 0 篇就是空，不拿隐藏作品的日期凑数。
        $authors = $this->pdo->query(
            'SELECT au.code,
                    MAX(CASE WHEN w.visibility = \'visible\' AND a.id IS NOT NULL
                             THEN COALESCE(a.published_at, w.published_at, w.created_at) END) AS lastmod
               FROM hq_author au
               JOIN hq_work w
                 ON w.author_id = au.id
                AND w.status = \'published\'
                AND w.deleted_at IS NULL
               LEFT JOIN hq_analysis a
                 ON a.id = w.current_analysis_id
                AND a.status = \'published\'
                AND a.visibility = \'visible\'
              GROUP BY au.id, au.code
              ORDER BY au.id'
        )->fetchAll();

        return [
            'works' => array_map(
                static fn (array $r): array => [
                    'code'    => (string) $r['code'],
                    'lastmod' => $r['lastmod'] === null ? null : (string) $r['lastmod'],
                ],
                $works
            ),
            'terms'  => array_map(static fn (array $r): int => (int) $r['term_id'], $terms),
            'issues' => $issueNos,
            'authors' => array_map(
                static fn (array $r): array => [
                    'code'    => (string) $r['code'],
                    'lastmod' => $r['lastmod'] === null ? null : (string) $r['lastmod'],
                ],
                $authors
            ),
        ];
    }
}
