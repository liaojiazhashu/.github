<?php

declare(strict_types=1);

namespace App\Application\Library;

use App\Application\Source\AuthorActivity;
use App\Support\Config;
use InvalidArgumentException;
use PDO;
use RuntimeException;

/**
 * 作者活跃度闸门：把长期没投稿也没评稿的作者，其**当前拆解**从公开区收起来。
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
 * ## 为什么恢复只认自己的理由前缀
 *
 * `hidden_reason` 以 `active-gate:` 开头 = 这条隐藏是本闸门写的。恢复时只碰
 * 这一类，人工在前台点「隐藏」（理由可能是空的、也可能写了别的话）永远不会被
 * 自动放出来。审计里能分清是谁藏的，业务表里也能。
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

    /** 单批 id 数量。500 与 VisibilityService 内部一致：语句短、锁范围小 */
    public const BATCH = 500;

    /** 默认时间窗。两个月：够盖住一期练笔的完整周期，又不至于把停笔一季的人算成活跃 */
    public const DEFAULT_MONTHS = 2;

    public function __construct(
        private readonly PDO $pdo,
        private readonly AuthorActivity $activity,
        private readonly VisibilityService $visibility,
        private readonly Config $config,
    ) {
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
        $stmt = $this->pdo->prepare(
            "SELECT id FROM hq_analysis
              WHERE visibility = 'hidden' AND hidden_reason LIKE :prefix
              ORDER BY id"
        );
        $stmt->execute([':prefix' => self::REASON_PREFIX . '%']);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
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
     *   buckets:array<string,array{works:int, ids:list<int>, authors:array<int,array{name:string,works:int}>}>,
     *   unknown_reasons:array<string,int>,
     *   toHide:list<int>,
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

        $active   = $this->activity->activeUids($since);
        $cidToUid = $this->catalogAuthors();
        $rows     = $this->rows();
        $classes  = self::classify($rows, $cidToUid, $authors, $active);

        $builtAt = (int) ($snapshot['meta']['built_at'] ?? 0);

        return [
            'months'           => $months,
            'since'            => $since,
            'since_label'      => date('Y-m-d H:i', $since),
            'snapshot_at'      => $builtAt,
            'snapshot_label'   => $builtAt > 0 ? date('Y-m-d H:i', $builtAt) : '未知',
            'snapshot_authors' => count($authors),
            'active_authors'   => count($active),
            'buckets'          => $classes['buckets'],
            'unknown_reasons'  => $classes['unknown_reasons'],
            'toHide'           => $classes['toHide'],
            'toRestore'        => $classes['toRestore'],
        ];
    }

    /**
     * 落库。只做两件事：隐藏该藏的、恢复该恢复的。
     *
     * 分批不只是为了 SQL 长度 —— 每批走一次 VisibilityService，就各有一条审计，
     * 一次操作动到上千条时，审计里按批留痕比塞一条巨大的汇总更好定位。
     *
     * 注意返回的 `pages` 是各批失效页面数之和，不同批可能命中同一个标签，
     * 所以它只是「体量参考」，不是去重后的页面数。
     *
     * @param array{toHide:list<int>, toRestore:list<int>} $plan
     * @return array{
     *   hide:array{requested:int, changed:int, pages:int},
     *   restore:array{requested:int, changed:int, pages:int},
     *   changed:int,
     *   pages:int,
     *   reason:string
     * }
     */
    public function apply(array $plan, ?int $actorId = null): array
    {
        $reason  = self::REASON_PREFIX . date('Y-m');
        $hide    = ['requested' => 0, 'changed' => 0, 'pages' => 0];
        $restore = ['requested' => 0, 'changed' => 0, 'pages' => 0];

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
            'hide'    => $hide,
            'restore' => $restore,
            'changed' => $hide['changed'] + $restore['changed'],
            'pages'   => $hide['pages'] + $restore['pages'],
            'reason'  => $reason,
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
     * @return list<array<string,mixed>>
     */
    private function rows(): array
    {
        $sql = "SELECT w.id AS work_id, w.current_analysis_id AS analysis_id, w.source_cid,
                       a.visibility AS analysis_visibility, a.hidden_reason, a.status AS analysis_status
                  FROM hq_work w
                  JOIN hq_analysis a ON a.id = w.current_analysis_id
                 WHERE w.status = 'published' AND w.deleted_at IS NULL AND w.visibility = 'visible'
                   AND a.status = 'published'";

        $stmt = $this->pdo->query($sql);
        $rows = $stmt === false ? [] : $stmt->fetchAll();

        return is_array($rows) ? $rows : [];
    }

    /**
     * cid → authorUid。
     *
     * 直接读 catalog.json 而不是装配 SourceCatalog：那个类要一个 SourceApi，
     * 而这里只需要一张映射表，凭空造一个 API 对象是多出来的依赖面。
     *
     * 缺失/损坏一律抛异常：把它当成空表会让每一篇都归到「未判定」，
     * 结果是整轮什么都不做，看起来却像「跑过了、没人需要隐藏」。
     *
     * @return array<int,int>
     */
    private function catalogAuthors(): array
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

        $map = [];
        foreach ($data['items'] as $i) {
            if (!is_array($i)) {
                continue;
            }
            $cid = (int) ($i['cid'] ?? 0);
            if ($cid > 0) {
                $map[$cid] = (int) ($i['authorUid'] ?? 0);
            }
        }

        return $map;
    }

    /**
     * 四分类 + 与现状求差。
     *
     * 四类互斥，判定顺序就是优先级：
     *   1. 人工隐藏 —— 已经被人藏了、且理由不是本闸门写的，一律不碰；
     *   2. 未判定   —— 缺 cid / cid 不在目录 / 缺 uid / 快照里没有 / ok=false；
     *   3. 保留     —— uid 在短窗活跃集合里；
     *   4. 应当隐藏 —— 其余（快照里 ok=true，就是确实没动静）。
     *
     * 人工隐藏排在未判定之前：这类行无论如何都不会被动，把它算进「未判定」
     * 会让「未判定」这个数字虚高，掩盖真正的数据缺口。
     *
     * 做成**静态纯函数**：这是唯一会「算错也不报错」的地方，单测要能直接喂数据，
     * 不必为了一个分类去装配 PDO、目录快照与可见性服务。
     *
     * @param list<array<string,mixed>> $rows
     * @param array<int,int>            $cidToUid
     * @param array<int,array<string,mixed>> $authors
     * @param array<int,array{name:string,last:int}> $active
     * @return array{
     *   buckets:array<string,array{works:int, ids:list<int>, authors:array<int,array{name:string,works:int}>}>,
     *   unknown_reasons:array<string,int>,
     *   toHide:list<int>,
     *   toRestore:list<int>
     * }
     */
    public static function classify(array $rows, array $cidToUid, array $authors, array $active): array
    {
        $buckets = [
            'keep'    => ['works' => 0, 'ids' => [], 'authors' => []],
            'hide'    => ['works' => 0, 'ids' => [], 'authors' => []],
            'unknown' => ['works' => 0, 'ids' => [], 'authors' => []],
            'manual'  => ['works' => 0, 'ids' => [], 'authors' => []],
        ];
        $reasons   = [];
        $toHide    = [];
        $toRestore = [];

        foreach ($rows as $row) {
            $analysisId = (int) ($row['analysis_id'] ?? 0);
            if ($analysisId <= 0) {
                continue;
            }

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
                // 只有本闸门藏过的才恢复；这里 $hidden 已隐含 $byGate（人工的上面就跳走了）
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
