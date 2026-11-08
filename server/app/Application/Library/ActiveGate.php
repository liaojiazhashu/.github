<?php

declare(strict_types=1);

namespace App\Application\Library;

use App\Application\Source\AuthorActivity;
use App\Application\Source\SourceCatalog;
use App\Support\Config;
use InvalidArgumentException;
use PDO;
use RuntimeException;

/**
 * 公开区闸门：决定哪一篇拆解可以对外展示。
 *
 * 三条规矩，全部满足才公开 —— 等价于「可见 = 非匿名 ∧ 源站还打得开 ∧ 作者还活跃」：
 *
 *   1. **匿名作品一律不公开**（作品级，无条件）；
 *   2. **源站已经不公开或已删除的作品，其拆解不公开**（作品级，看目录快照）；
 *   3. **作者长期没投稿也没评稿的，其拆解不公开**（作者级，按时间窗判）。
 *
 * 为什么把三条塞进一个类、而不是三个命令各管一段：它们写的是同一个字段
 * （`hq_analysis.visibility`）。三个命令各自隐藏又各自恢复，会出现「A 刚收、
 * B 立刻放回」的拉锯，而且每一边都认为自己做对了 —— 这种错不会报错，
 * 只会让公开区的内容随执行顺序抖动。
 *
 * 三条合成一个判定之后，恢复也只有一个出口：**三条同时满足**才放回。
 * 于是「源站删了文章但作者很活跃」不会被放出来，「源站恢复了公开但作者停笔了」
 * 也不会被放出来。两个条件互为与关系，谁也不覆盖谁。
 *
 * ## 为什么作用在 hq_analysis 上，而不是 hq_work
 *
 * 公开区的内容就是拆解本身。`LibraryRepository::LIVE_WORK` 要求当前拆解的
 * `visibility = 'visible'`，所以隐藏拆解等于作品从公开区整体消失（列表、期次、
 * 首页、sitemap、详情页一起），效果与「隐藏作品」一致，但后台语义不同：
 * 原文还在、作品行没被碰。这样人工「隐藏作品」的操作不会与闸门互相覆盖 ——
 * 闸门只管拆解的那一层，人管作品的那一层，各说各话。
 *
 * 反过来，如果直接改 hq_work.visibility，闸门的恢复动作就会把人工下线过的
 * 作品重新放出来。这是本类存在的第一个理由。
 *
 * ## 匿名为什么必须单独一条规则，且排在活跃度之前
 *
 * 匿名是**作品级**的事实，不是作者级的：同一个人这一期显示「匿名用户」、
 * 往期是真名。若只按作者活跃度判，这位作者只要近期投过稿，他那篇匿名作品
 * 就会跟着放出来 —— 而站方把它匿掉，本来就是为了不让读者对上号。
 *
 * 所以判定顺序里匿名排在作者归属之前：不看 uid、不看活跃度，命中就收起来。
 * 但收的**只有这一篇** —— 同一作者的实名作品照常按活跃度判，两者互不牵连。
 *
 * 未判定的五种情况（缺 cid、cid 不在目录、缺 uid、快照里没这人、`ok=false`）
 * 对匿名不适用：匿名只依赖 cid 与目录里的名字，这两样齐了就能判。
 *
 * ## 源站状态为什么也排在活跃度之前
 *
 * 同理：一篇被作者改成「未公开」或被站方删掉的文章，它的拆解没有任何理由
 * 继续公开 —— 无论作者本人多活跃。这条只依赖目录快照里那四个字段，
 * 与 uid、快照、接口全部无关，所以同样排在作者归属之前。
 *
 * 反过来，**源站恢复了公开，也不代表就能放回来**：还得作者仍然活跃。
 * 这就是把三条合成一个判定的价值 —— 两个条件天然是与关系，不需要谁去
 * 协调谁先跑。
 *
 * ## 为什么恢复只认自己的理由前缀
 *
 * `hidden_reason` 以 `active-gate:` 开头 = 这条隐藏是本闸门写的。恢复时只碰
 * 这一类，人工在前台点「隐藏」（理由可能是空的、也可能写了别的话）永远不会被
 * 自动放出来。审计里能分清是谁藏的，业务表里也能。
 *
 * 三条规矩共用同一个前缀、只在后面加 `anon-` / `closed-` 区分，是为了让
 * 「整体撤销」不必让人记得哪条属于哪一类 —— 判断口径整个错了的时候，
 * 要的是一条命令全撤。
 *
 * ## 为什么未知一律不隐藏
 *
 * 判不了的情况有五种：作品没有 source_cid、cid 不在目录里、目录里没有作者 uid、
 * 快照里没有这位作者、作者 `ok = false`（接口三次重试全失败）。
 * 这五种都归「未判定」，**保持现状** —— 不隐藏、也不恢复。
 *
 * 取舍的理由不对称：误隐藏是读者立刻看不到一篇正常作品，且要等下一轮才能修；
 * 漏隐藏只是这篇继续公开着，下一轮再收。所以宁可漏，不可误伤。
 * `ok = false` 尤其危险 —— 它看起来像「没有近期活动」，实际是「没查到」，
 * 拿它当不活跃，一次接口抖动就能清掉一批活跃作者的拆解。
 *
 * ## 为什么单独一个类、且试算与落库分开
 *
 * 这类批量操作最怕「先跑一遍看看」把东西改了。plan() 是纯只读的：不写表、
 * 不写文件、不碰 VisibilityService。命令默认只调 plan()，加 --apply 才调 apply()。
 */
final class ActiveGate
{
    /** 闸门写进 hidden_reason 的前缀。恢复时靠它区分「我藏的」与「人藏的」 */
    public const REASON_PREFIX = 'active-gate:';

    /**
     * 匿名规则的完整前缀。
     *
     * 仍以 `active-gate:` 开头，所以 gatedIdsByRule() 与 classify() 的
     * 「是不是本闸门藏的」判断照样认它，整体撤销也一并覆盖。
     * `-` 而不是 `:` 收尾，是为了让 `LIKE 'active-gate:%'` 一次就能捞全三类。
     */
    public const ANON_REASON = self::REASON_PREFIX . 'anon-';

    /** 源站已不公开 / 已删除的完整前缀。理由同上，挂在同一个大前缀下 */
    public const CLOSED_REASON = self::REASON_PREFIX . 'closed-';

    /** 单批 id 数量。500 与 VisibilityService 内部一致：语句短、锁范围小 */
    public const BATCH = 500;

    /**
     * 默认时间窗。取自 AuthorActivity，与后台分类页、月采集同一个数字 ——
     * 这里若另写一个 2，三处就会在某人改了一处之后悄悄分叉。
     */
    public const DEFAULT_MONTHS = AuthorActivity::WINDOW_MONTHS;

    public function __construct(
        private readonly PDO $pdo,
        private readonly AuthorActivity $activity,
        private readonly VisibilityService $visibility,
        private readonly Config $config,
    ) {
    }

    /**
     * 一次落库要写的三条理由。纯函数，单测只测这一段。
     *
     * 不变式有三条，缺一条就会在别处塌：`restoreAll()` 靠 `LIKE 'active-gate:%'`
     * 一次捞全，靠 `groupByRule()` 分出三类。
     *
     *   1. 三条都以 REASON_PREFIX 开头 —— 否则整体回滚捞不到它，只能上后台一条条点；
     *   2. 两条带分类的必须能被 groupByRule() 认回原来的桶 —— 否则回滚页的分项数字
     *      会把它算成「作者不活跃」，而它其实是匿名或撤稿收的；
     *   3. 分类后缀用 `-` 收尾而不是 `:`，`active-gate:` 后面直接跟月份的老数据
     *      （2026-11-08 之前写的）仍然只匹配前缀，落进 activity 兜底，不会报错。
     *
     * @return array{hide:string, anon:string, closed:string}
     */
    public static function reasonsFor(?string $month = null): array
    {
        $month ??= date('Y-m');

        return [
            'hide'   => self::REASON_PREFIX . $month,
            'anon'   => self::ANON_REASON . $month,
            'closed' => self::CLOSED_REASON . $month,
        ];
    }

    /**
     * 本闸门收起过的全部拆解，按规矩分组。
     *
     * 分组只为打印（让运维看清「多少条是匿名收的、多少条是源站撤稿收的、
     * 多少条是作者不活跃收的」）。回滚不看分组：判断口径整个错了的时候，
     * 要的是「一条命令全撤」，不是让人逐条回忆归属。
     *
     * @return array{anon:list<int>, closed:list<int>, activity:list<int>}
     */
    public function gatedIdsByRule(): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT id, hidden_reason FROM hq_analysis
              WHERE visibility = 'hidden' AND hidden_reason LIKE :prefix
              ORDER BY id"
        );
        $stmt->execute([':prefix' => self::REASON_PREFIX . '%']);

        return self::groupByRule($stmt->fetchAll());
    }

    /**
     * 按理由前缀把「本闸门藏的」分成三类。纯函数，单测只测这一段。
     *
     * 认不出来的一律归 `activity`：那是三条里唯一会在条件变化后被放回的一类，
     * 归错它的代价最小。历史上 `active-gate:YYYY-MM`（没写分类后缀）也确实属于
     * 这一类，所以这个兜底同时兼容旧数据。
     *
     * @param list<array<string,mixed>> $rows 至少含 id 与 hidden_reason
     * @return array{anon:list<int>, closed:list<int>, activity:list<int>}
     */
    public static function groupByRule(array $rows): array
    {
        $out = ['anon' => [], 'closed' => [], 'activity' => []];
        foreach ($rows as $r) {
            if (!is_array($r)) {
                continue;
            }
            $reason = (string) ($r['hidden_reason'] ?? '');
            $kind   = str_starts_with($reason, self::ANON_REASON) ? 'anon'
                : (str_starts_with($reason, self::CLOSED_REASON) ? 'closed' : 'activity');
            $out[$kind][] = (int) ($r['id'] ?? 0);
        }

        return $out;
    }

    /**
     * 本闸门收起过的全部拆解 id。
     *
     * 给整体回滚用。口径判断错了的时候，运维得有一条「全部撤销」的路 ——
     * 否则只能到后台隐藏归档页一条条点，267 条点完人也废了。
     * 只认自己的理由前缀，人工隐藏的一条都不会被带出来。
     *
     * @return list<int>
     */
    public function gatedAnalysisIds(): array
    {
        $byRule = $this->gatedIdsByRule();
        $ids    = array_merge($byRule['anon'], $byRule['closed'], $byRule['activity']);
        sort($ids);

        return $ids;
    }

    /**
     * 整体放回。与 apply() 的区别是它不看活跃度，直接把闸门收起的全部恢复。
     *
     * @param list<int> $ids
     * @return array{requested:int, changed:int, pages:int}
     */
    public function restoreAll(array $ids, ?int $actorId = null): array
    {
        $changed = 0;
        $pages   = 0;

        foreach (array_chunk($ids, self::BATCH) as $chunk) {
            $r = $this->visibility->restore(VisibilityService::ANALYSIS, $chunk, $actorId);
            $changed += (int) ($r['changed'] ?? 0);
            $pages   += (int) ($r['pages'] ?? 0);
        }

        return ['requested' => count($ids), 'changed' => $changed, 'pages' => $pages];
    }

    /**
     * 试算：只读。不改表、不写文件、不碰缓存。
     *
     * 快照或目录缺失都**抛异常**，不静默返回空结果 —— 空结果在这条链路上
     * 等于「所有作者都不活跃」，会把全站拆解一起收起来。
     *
     * @return array{
     *   months:int,
     *   since:int,
     *   since_label:string,
     *   snapshot_at:int,
     *   snapshot_label:string,
     *   snapshot_authors:int,
     *   active_authors:int,
     *   anon_in_catalog:int,
     *   closed_in_catalog:int,
     *   catalog_total:int,
     *   catalog_at:int,
     *   buckets:array<string,array{works:int, ids:list<int>, authors:array<int,array{name:string,works:int}>}>,
     *   unknown_reasons:array<string,int>,
     *   toHide:list<int>,
     *   toHideAnon:list<int>,
     *   toHideClosed:list<int>,
     *   toRestore:list<int>
     * }
     */
    public function plan(int $months = self::DEFAULT_MONTHS): array
    {
        if ($months < 1) {
            throw new InvalidArgumentException('时间窗至少要一个月');
        }

        // 自然月回推，不做整点对齐：对齐到月初会让「今天」执行的那次实际上
        // 少看几天 —— 与 ActiveCollect::refreshActivity 同一口径。
        // 默认值取 AuthorActivity::WINDOW_MONTHS，所以后台分类页与这里算的是同一天。
        $since = strtotime('-' . $months . ' months');
        if ($since === false) {
            throw new RuntimeException('时间窗算不出来：-' . $months . ' months');
        }

        $snapshot = $this->activity->load();
        if ($snapshot === null) {
            throw new RuntimeException('活跃度快照不存在，请先跑 source:activity');
        }

        // 快照按 uid 建索引。这里保留整条记录而不只是 ok：名字要拿去打印，
        // ok 要拿来做「未判定」判定，两者都来自同一份事实。
        $authors = [];
        foreach (($snapshot['authors'] ?? []) as $a) {
            if (is_array($a) && (int) ($a['uid'] ?? 0) > 0) {
                $authors[(int) $a['uid']] = $a;
            }
        }

        $active  = $this->activity->activeUids($since);
        $index   = $this->catalogIndex();
        $rows    = $this->rows();
        $classes = self::classify(
            $rows,
            $index['uid'],
            $authors,
            $active,
            $index['anon'],
            $index['available']
        );

        $builtAt = (int) ($snapshot['meta']['built_at'] ?? 0);

        return [
            'months'            => $months,
            'since'             => $since,
            'since_label'       => date('Y-m-d H:i', $since),
            'snapshot_at'       => $builtAt,
            'snapshot_label'    => $builtAt > 0 ? date('Y-m-d H:i', $builtAt) : '未知',
            'snapshot_authors'  => count($authors),
            'active_authors'    => count($active),
            'anon_in_catalog'   => count($index['anon']),
            'closed_in_catalog' => count($index['uid']) - count($index['available']),
            'catalog_total'     => count($index['uid']),
            'catalog_at'        => (int) ($index['fetched_at'] ?? 0),
            'buckets'           => $classes['buckets'],
            'unknown_reasons'   => $classes['unknown_reasons'],
            'toHide'            => $classes['toHide'],
            'toHideAnon'        => $classes['toHideAnon'],
            'toHideClosed'      => $classes['toHideClosed'],
            'toRestore'        => $classes['toRestore'],
        ];
    }

    /**
     * 落库。只做四件事：收匿名、收源站已撤稿的、收不活跃作者的、放回该放的。
     *
     * 分批不只是为了 SQL 长度 —— 每批走一次 VisibilityService，就各有一条审计，
     * 一次操作动到上千条时，审计里按批留痕比塞一条巨大的汇总更好定位。
     *
     * 三种隐藏分开写理由（`active-gate:anon-` / `active-gate:closed-` /
     * `active-gate:`），这样审计与隐藏归档页能看出「这条是为什么收的」。
     * 三者前缀相同，整体回滚不分彼此。
     *
     * 注意返回的 `pages` 是各批失效页面数之和，不同批可能命中同一个标签，
     * 所以它只是「体量参考」，不是去重后的页面数。
     *
     * @param array{toHide:list<int>, toHideAnon?:list<int>, toHideClosed?:list<int>, toRestore:list<int>} $plan
     * @return array{
     *   anon:array{requested:int, changed:int, pages:int},
     *   closed:array{requested:int, changed:int, pages:int},
     *   hide:array{requested:int, changed:int, pages:int},
     *   restore:array{requested:int, changed:int, pages:int},
     *   changed:int,
     *   pages:int,
     *   reason:string,
     *   anon_reason:string,
     *   closed_reason:string
     * }
     */
    public function apply(array $plan, ?int $actorId = null): array
    {
        $reasons      = self::reasonsFor();
        $reason       = $reasons['hide'];
        $anonReason   = $reasons['anon'];
        $closedReason = $reasons['closed'];

        $blank   = ['requested' => 0, 'changed' => 0, 'pages' => 0];
        $anon    = $blank;
        $closed  = $blank;
        $hide    = $blank;
        $restore = $blank;

        // 作品级的两条排在前面：它们不依赖活跃度，优先级更高，日志里也更好读
        foreach (array_chunk($this->ids($plan['toHideAnon'] ?? []), self::BATCH) as $batch) {
            $r = $this->visibility->hide(VisibilityService::ANALYSIS, $batch, $actorId, $anonReason);
            $anon['requested'] += $r['requested'];
            $anon['changed']   += $r['changed'];
            $anon['pages']     += $r['pages'];
        }

        foreach (array_chunk($this->ids($plan['toHideClosed'] ?? []), self::BATCH) as $batch) {
            $r = $this->visibility->hide(VisibilityService::ANALYSIS, $batch, $actorId, $closedReason);
            $closed['requested'] += $r['requested'];
            $closed['changed']   += $r['changed'];
            $closed['pages']     += $r['pages'];
        }

        foreach (array_chunk($this->ids($plan['toHide'] ?? []), self::BATCH) as $batch) {
            $r = $this->visibility->hide(VisibilityService::ANALYSIS, $batch, $actorId, $reason);
            $hide['requested'] += $r['requested'];
            $hide['changed']   += $r['changed'];
            $hide['pages']     += $r['pages'];
        }

        foreach (array_chunk($this->ids($plan['toRestore'] ?? []), self::BATCH) as $batch) {
            $r = $this->visibility->restore(VisibilityService::ANALYSIS, $batch, $actorId);
            $restore['requested'] += $r['requested'];
            $restore['changed']   += $r['changed'];
            $restore['pages']     += $r['pages'];
        }

        return [
            'anon'          => $anon,
            'closed'        => $closed,
            'hide'          => $hide,
            'restore'       => $restore,
            'changed'       => $anon['changed'] + $closed['changed'] + $hide['changed'] + $restore['changed'],
            'pages'         => $anon['pages'] + $closed['pages'] + $hide['pages'] + $restore['pages'],
            'reason'        => $reason,
            'anon_reason'   => $anonReason,
            'closed_reason' => $closedReason,
        ];
    }

    /**
     * 一条 SQL 取全量，不按作者 N+1。
     *
     * 口径与 `LibraryRepository::PUBLISHED_JOIN` 对齐（已发布、未删、作品可见、
     * 当前拆解已发布），但**不筛拆解可见性** —— 被闸门藏过的行必须查得出来，
     * 否则恢复永远没有对象。
     *
     * 标题与作品状态不取：闸门只改拆解可见性，不需要正文信息。
     *
     * 按 work.id 排序是为了让「同一个拆解被两个作品行引用」时结果可复现 ——
     * `hq_work.current_analysis_id` 没有唯一约束，不加排序时先见到哪一行由
     * 执行计划决定，同一份数据两次跑可能给出不同结论。
     *
     * @return list<array<string,mixed>>
     */
    private function rows(): array
    {
        $sql = "SELECT w.id AS work_id, w.current_analysis_id AS analysis_id, w.source_cid,
                       a.visibility AS analysis_visibility, a.hidden_reason, a.status AS analysis_status
                  FROM hq_work w
                  JOIN hq_analysis a ON a.id = w.current_analysis_id
                 WHERE w.status = 'published' AND w.deleted_at IS NULL AND w.visibility = 'visible'
                   AND a.status = 'published'
                 ORDER BY w.id";

        $stmt = $this->pdo->query($sql);
        $rows = $stmt === false ? [] : $stmt->fetchAll();

        return is_array($rows) ? $rows : [];
    }

    /**
     * 目录快照里的三张表：cid → authorUid、哪些 cid 是匿名、哪些 cid 还可用。
     *
     * 一次读盘出三张表。拆成几个方法会让 987 KB 的 JSON 被反复解析，
     * 而这三张表本来就是同一次遍历的产物。
     *
     * 直接读 catalog.json 而不是装配 SourceCatalog：那个类要一个 SourceApi，
     * 而这里只需要映射关系，凭空造一个 API 对象是多出来的依赖面。但「可用」
     * 的四个条件仍然调 `SourceCatalog::available()` —— 那是采集与闸门共用的
     * 唯一判据，抄一份过来就等着两边分叉。
     *
     * 匿名按**作品**判：目录里这一篇的 `authorName` 是站方给的占位名。
     * 不用 `authorUid` 判 —— 同一个人这一期匿名、往期实名，按 uid 判会把
     * 他的实名作品一起误收。
     *
     * 缺失/损坏一律抛异常：把它当成空表会让每一篇都归到「未判定」，
     * 结果是整轮什么都不做，看起来却像「跑过了、没人需要隐藏」。
     *
     * @return array{uid:array<int,int>, anon:array<int,true>, available:array<int,true>, fetched_at:int}
     */
    private function catalogIndex(): array
    {
        $dir = $this->config->string('source.cache_dir');
        if ($dir === '') {
            throw new RuntimeException('没有配置 source.cache_dir，读不到目录快照');
        }

        $path = rtrim($dir, '/\\') . '/catalog.json';
        if (!is_file($path)) {
            throw new RuntimeException('目录快照不存在，请先跑 source:sync');
        }

        $raw = @file_get_contents($path);
        if (!is_string($raw) || $raw === '') {
            throw new RuntimeException('目录快照读不出来：' . $path);
        }

        $data = json_decode($raw, true);
        if (!is_array($data) || !is_array($data['items'] ?? null)) {
            throw new RuntimeException('目录快照格式不对：' . $path);
        }

        return self::buildCatalogIndex($data);
    }

    /**
     * 把目录快照的原始结构折成三张表。纯函数，不碰文件系统 —— 单测只测这一段。
     *
     * 缺失/损坏一律由调用方抛异常，这里只处理「结构已经合法」的输入。
     *
     * @param array<string,mixed> $data catalog.json 解码后的数组
     * @return array{uid:array<int,int>, anon:array<int,true>, available:array<int,true>, fetched_at:int}
     */
    public static function buildCatalogIndex(array $data): array
    {
        $uid       = [];
        $anon      = [];
        $available = [];
        foreach (($data['items'] ?? []) as $i) {
            if (!is_array($i)) {
                continue;
            }
            $cid = (int) ($i['cid'] ?? 0);
            if ($cid <= 0) {
                continue;
            }

            $uid[$cid] = (int) ($i['authorUid'] ?? 0);

            if (self::isAnonymousItem($i)) {
                $anon[$cid] = true;
            }

            // 只记「还开着」的。cid 在 uid 表里但不在这一张里 = 源站已撤稿，
            // 这个差集就是「要收起来」的判据，和采集方向用的是同一份事实。
            if (SourceCatalog::available($i)) {
                $available[$cid] = true;
            }
        }

        return [
            'uid'        => $uid,
            'anon'       => $anon,
            'available'  => $available,
            'fetched_at' => (int) ($data['meta']['fetched_at'] ?? $data['meta']['built_at'] ?? 0),
        ];
    }

    /**
     * 目录里这一篇算不算匿名。
     *
     * **只认站方显式打的占位名，不认空名字。** 空名字意味着「目录里没有这个
     * 字段」，来源是接口某次少给了 `authorInfo.name`，或者同步被截断。那种情况
     * 下我们并不知道这篇是不是匿名 —— 而匿名规则是无条件隐藏、绕开后面五种
     * 「未判定」保护，一旦把空名字当匿名，一次字段抖动就能把整片公开区收掉，
     * 而且不报错。
     *
     * `AuthorActivity::ANON_NAMES` 里含空串，那是给展示用的（后台分类页、CSV）：
     * 那里认错只是标签难看。这里要的是「确认匿名」，所以用更严的 ANON_PLACEHOLDER。
     *
     * 单独提出来是为了能单测 —— 这是「数据坏了被当成确认匿名」的唯一入口。
     *
     * @param array<string,mixed> $item 目录快照里的一条
     */
    public static function isAnonymousItem(array $item): bool
    {
        return trim((string) ($item['authorName'] ?? '')) === AuthorActivity::ANON_PLACEHOLDER;
    }

    /**
     * 六分类 + 与现状求差。
     *
     * 六类互斥，判定顺序就是优先级：
     *   1. 人工隐藏 —— 已经被人藏了、且理由不是本闸门写的，一律不碰；
     *   2. 匿名     —— 目录里这一篇的名字是「匿名用户」，无条件收起来；
     *   3. 源站撤稿 —— 目录里这一篇已不可用（未公开 / 已删除 / 退回草稿），收起；
     *   4. 未判定   —— 缺 cid / cid 不在目录 / 缺 uid / 快照里没有 / ok=false；
     *   5. 保留     —— 三条都过关，uid 在活跃集合里；
     *   6. 应当隐藏 —— 其余（快照里 ok=true，就是确实没动静）。
     *
     * 前四类的顺序不能换：
     *   · 人工隐藏排最前 —— 这类行无论如何都不该被动，算进「未判定」会让那个
     *     数字虚高，掩盖真正的数据缺口；
     *   · 匿名与源站撤稿排在未判定之前 —— 这两条只取决于 cid 与目录快照，
     *     不经过 uid 与作者快照，所以它们不可能落进那五种「未判定」。放到后面
     *     反而会让「作者 uid 是临时的」「作者快照里没这人」的撤稿作品被当成
     *     未判定，永远收不起来 —— 那正是最该收的一类。
     *
     * 做成**静态纯函数**：这是唯一会「算错也不报错」的地方，单测要能直接喂数据，
     * 不必为了一个分类去装配 PDO、目录快照与可见性服务。
     *
     * 六个参数全部必填、没有默认值，是刻意的：`$available` 若给默认空数组，
     * 就等于「目录里没有一篇还开着」，会把全站拆解一次性收光 —— 这种错不该
     * 靠人记得传参来避免，而该在调用时就报错。
     *
     * @param list<array<string,mixed>> $rows
     * @param array<int,int>            $cidToUid
     * @param array<int,array<string,mixed>> $authors
     * @param array<int,array{name:string,last:int}> $active
     * @param array<int,true>           $anonCids 目录里标成匿名的 cid 集合
     * @param array<int,true>           $available 目录里仍然打得开的 cid 集合
     * @return array{
     *   buckets:array<string,array{works:int, ids:list<int>, authors:array<int,array{name:string,works:int}>}>,
     *   unknown_reasons:array<string,int>,
     *   toHide:list<int>,
     *   toHideAnon:list<int>,
     *   toHideClosed:list<int>,
     *   toRestore:list<int>
     * }
     */
    public static function classify(
        array $rows,
        array $cidToUid,
        array $authors,
        array $active,
        array $anonCids,
        array $available
    ): array {
        $buckets = [
            'keep'    => ['works' => 0, 'ids' => [], 'authors' => []],
            'hide'    => ['works' => 0, 'ids' => [], 'authors' => []],
            'anon'    => ['works' => 0, 'ids' => [], 'authors' => []],
            'closed'  => ['works' => 0, 'ids' => [], 'authors' => []],
            'unknown' => ['works' => 0, 'ids' => [], 'authors' => []],
            'manual'  => ['works' => 0, 'ids' => [], 'authors' => []],
        ];
        $reasons      = [];
        $toHide       = [];
        $toHideAnon   = [];
        $toHideClosed = [];
        $toRestore    = [];
        $seen         = [];

        foreach ($rows as $row) {
            $analysisId = (int) ($row['analysis_id'] ?? 0);
            if ($analysisId <= 0) {
                continue;
            }

            // 同一个拆解被两个作品行引用时只认第一行（rows() 已按 work.id 排序）。
            // 否则同一个 id 可能既进 toHideAnon 又进 toHide，那次操作会写两条审计、
            // 隐藏条数虚高一倍，而实际上只发生了一次状态变化。
            if (isset($seen[$analysisId])) {
                continue;
            }
            $seen[$analysisId] = true;

            $hidden = (string) ($row['analysis_visibility'] ?? '') === 'hidden';
            $reason = (string) ($row['hidden_reason'] ?? '');
            $byGate = str_starts_with($reason, self::REASON_PREFIX);

            // 作者归属先解出来：人工隐藏那一类也要按人计数，
            // 否则「12 篇 · 1 位作者」这种数字会让人误判影响面。
            $cid  = (int) ($row['source_cid'] ?? 0);
            $uid  = $cid > 0 ? (int) ($cidToUid[$cid] ?? 0) : 0;
            $name = $uid > 0 ? (string) ($authors[$uid]['name'] ?? '') : '';

            // 人工隐藏优先于一切判定：作者是否活跃都不影响「人藏的东西不能自动放出来」
            if ($hidden && !$byGate) {
                self::add($buckets['manual'], $analysisId, $uid, $name);
                continue;
            }

            // 匿名：作品级、无条件。只收这一篇，同一作者的实名作品走下面的活跃度判定。
            // 不进 authors 统计 —— 匿名作品本来就不该被归到任何一个 uid 名下，
            // 记一条假作者只会让「N 篇 · 1 位作者」这种输出凭空多一个人。
            if ($cid > 0 && isset($anonCids[$cid])) {
                ++$buckets['anon']['works'];
                $buckets['anon']['ids'][] = $analysisId;
                if (!$hidden) {
                    $toHideAnon[] = $analysisId;
                }
                continue;
            }

            // 源站已撤稿（作者改成未公开 / 站方删了 / 退回草稿）：拆解没有理由继续公开，
            // 无论作者本人多活跃。与匿名同理，只看目录快照，不经过 uid 与活跃度，
            // 所以必须排在下面那五种「未判定」之前 —— 否则一篇 uid 恰好缺失的撤稿作品
            // 会被判成未判定、继续公开着。
            //
            // 注意判据用 `isset($available)` 的**补集**，且要求 cid 在目录里：
            // cid 根本不在目录里属于「不知道」（可能同步不完整），走下面的未判定。
            if ($cid > 0 && array_key_exists($cid, $cidToUid) && !isset($available[$cid])) {
                ++$buckets['closed']['works'];
                $buckets['closed']['ids'][] = $analysisId;
                if (!$hidden) {
                    $toHideClosed[] = $analysisId;
                }
                continue;
            }

            if ($cid <= 0) {
                self::add($buckets['unknown'], $analysisId, 0, '');
                $reasons['作品没有 source_cid'] = ($reasons['作品没有 source_cid'] ?? 0) + 1;
                continue;
            }
            if (!array_key_exists($cid, $cidToUid)) {
                self::add($buckets['unknown'], $analysisId, 0, '');
                $reasons['cid 不在目录里'] = ($reasons['cid 不在目录里'] ?? 0) + 1;
                continue;
            }
            if ($uid <= 0) {
                self::add($buckets['unknown'], $analysisId, 0, '');
                $reasons['目录里没有作者 uid'] = ($reasons['目录里没有作者 uid'] ?? 0) + 1;
                continue;
            }

            $author = $authors[$uid] ?? null;
            if ($author === null) {
                self::add($buckets['unknown'], $analysisId, $uid, '');
                $reasons['快照里没有这位作者'] = ($reasons['快照里没有这位作者'] ?? 0) + 1;
                continue;
            }
            if (!(bool) ($author['ok'] ?? true)) {
                self::add($buckets['unknown'], $analysisId, $uid, (string) ($author['name'] ?? ''));
                $reasons['接口没查到（ok=false）'] = ($reasons['接口没查到（ok=false）'] ?? 0) + 1;
                continue;
            }

            if (isset($active[$uid])) {
                self::add($buckets['keep'], $analysisId, $uid, $name);
                // 只有本闸门藏过的才恢复；这里 $hidden 已隐含 $byGate（人工的上面就跳走了）。
                // 恢复不区分当初是匿名规则还是活跃度规则收的 —— 能走到这里说明这篇
                // 已经不是匿名了，理由字符串只是历史痕迹，不值得为它多写一条 UPDATE。
                if ($hidden && $byGate) {
                    $toRestore[] = $analysisId;
                }
                continue;
            }

            self::add($buckets['hide'], $analysisId, $uid, $name);
            if (!$hidden) {
                $toHide[] = $analysisId;
            }
        }

        foreach ($buckets as &$bucket) {
            uasort(
                $bucket['authors'],
                static fn (array $x, array $y): int => [$y['works'], $x['name']] <=> [$x['works'], $y['name']]
            );
        }
        unset($bucket);

        return [
            'buckets'         => $buckets,
            'unknown_reasons' => $reasons,
            'toHide'          => array_values($toHide),
            'toHideAnon'      => array_values($toHideAnon),
            'toHideClosed'    => array_values($toHideClosed),
            'toRestore'       => array_values($toRestore),
        ];
    }

    /**
     * @param array{works:int, ids:list<int>, authors:array<int,array{name:string,works:int}>} $bucket
     */
    private static function add(array &$bucket, int $analysisId, int $uid, string $name): void
    {
        ++$bucket['works'];
        $bucket['ids'][] = $analysisId;

        $bucket['authors'][$uid] ??= ['name' => $name, 'works' => 0];
        ++$bucket['authors'][$uid]['works'];
    }

    /**
     * @param list<int> $ids
     * @return list<int>
     */
    private function ids(array $ids): array
    {
        return array_values(array_unique(array_filter(
            array_map('intval', $ids),
            static fn (int $id): bool => $id > 0
        )));
    }
}
