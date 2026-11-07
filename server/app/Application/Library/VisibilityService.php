<?php

declare(strict_types=1);

namespace App\Application\Library;

use App\Infrastructure\Pdo\AuditLogger;
use App\Infrastructure\Pdo\LibraryRepository;
use App\Support\Config;
use PDO;
use Throwable;

/**
 * 可见性：把内容藏起来 / 放回去。
 *
 * 这个类是「隐藏」这件事的**唯一入口**。后台的按钮、命令行、将来的定时任务都走它，
 * 因为它承担了四件必须一起发生的事，漏一件就会出现「藏了但还看得见」：
 *
 *   1. 改 hq_work / hq_analysis / hq_index_term 的 visibility；
 *   2. 写一条审计（谁、什么时候、为什么、涉及多少条）；
 *   3. 让**所有受影响的页面缓存**失效；
 *   4. 重写**静态 sitemap.xml**（若部署用的是静态文件）。
 *
 * 第 3、4 条是最容易漏的。前台有 60–300 秒的整页缓存，只改数据库的话，
 * 已缓存的页面还会继续把隐藏内容吐给读者；sitemap 更彻底 —— 它是构建时的快照，
 * 不改就永远留在那里，而搜索引擎会一直来抓一个 404。所以这里不按「改了哪张表」
 * 失效，而按「哪些页面会变」失效 —— 隐藏一篇作品，它的作品页、期次页、列表页、
 * 首页、以及它所有词条的详情页都变了。
 *
 * 关于批量：1300 篇的规模下，一次操作可能涉及上千条。处理方式是
 *   · UPDATE 按 500 一批分开跑（一条 IN 带 5000 个 id 会撞 max_allowed_packet，
 *     也会长时间持锁）；
 *   · 审计只写**一条**汇总，不写 1300 条 —— 审计表是拿来回答「谁动的、动了多少」，
 *     不是流水账；行级细节在业务表自己的 hidden_* 字段里；
 *   · 缓存标签一次性收集完再统一失效（invalidateTags 本来就只扫一遍目录）。
 */
final class VisibilityService
{
    public const WORK     = 'work';
    public const ANALYSIS = 'analysis';
    public const TERM     = 'term';

    /** @var array<string,string> 类型 → 表名（只用于拼 SQL，键来自本类常量，不接受外部输入） */
    private const TABLES = [
        self::WORK     => 'hq_work',
        self::ANALYSIS => 'hq_analysis',
        self::TERM     => 'hq_index_term',
    ];

    /** @var array<string,string> 类型 → 中文说法（审计与提示用） */
    public const LABELS = [
        self::WORK     => '作品',
        self::ANALYSIS => '拆解结果',
        self::TERM     => '词条',
    ];

    /** 单批 id 数量。500 是经验值：语句短、锁范围小、失败重试代价低 */
    private const BATCH = 500;

    public function __construct(
        private readonly PDO $pdo,
        private readonly AuditLogger $audit,
        private readonly PageCache $cache,
        private readonly LibraryRepository $library,
        private readonly Config $config,
    ) {
    }

    /**
     * 隐藏。
     *
     * @param list<int> $ids
     * @return array{requested:int, changed:int, pages:int, sitemap:bool, reason:string}
     */
    public function hide(string $type, array $ids, ?int $actorId, string $reason = ''): array
    {
        return $this->apply($type, $ids, 'hidden', $actorId, $reason);
    }

    /**
     * 恢复可见。
     *
     * @param list<int> $ids
     * @return array{requested:int, changed:int, pages:int, sitemap:bool, reason:string}
     */
    public function restore(string $type, array $ids, ?int $actorId): array
    {
        return $this->apply($type, $ids, 'visible', $actorId, '');
    }

    /**
     * @param list<int> $ids
     * @return array{requested:int, changed:int, pages:int, sitemap:bool, reason:string}
     */
    private function apply(string $type, array $ids, string $visibility, ?int $actorId, string $reason): array
    {
        $table = self::TABLES[$type] ?? null;
        if ($table === null) {
            throw new \InvalidArgumentException('未知的可见性目标类型：' . $type);
        }

        $ids = array_values(array_unique(array_filter(
            array_map('intval', $ids),
            static fn (int $id): bool => $id > 0
        )));

        if ($ids === []) {
            return ['requested' => 0, 'changed' => 0, 'pages' => 0, 'sitemap' => false, 'reason' => ''];
        }

        // 受影响范围必须在**改之前**取：改完之后再查「哪些词条属于这篇作品」结果一样，
        // 但「哪些页面现在是旧的」这件事，只有在动之前才说得清 —— 而且万一半途失败，
        // 缓存已经按最坏情况失效过了，不会留下脏页。
        $tags = $this->affectedTags($type, $ids);

        $changed = 0;
        $this->pdo->beginTransaction();
        try {
            foreach (array_chunk($ids, self::BATCH) as $batch) {
                $in     = implode(',', array_fill(0, count($batch), '?'));
                $params = $batch;

                if ($visibility === 'hidden') {
                    // 已经是 hidden 的不再写一次时间与理由：重复点「隐藏」不该覆盖第一次的理由，
                    // 那是复核时最该看到的信息
                    $sql = "UPDATE {$table}
                               SET visibility = 'hidden',
                                   hidden_at = COALESCE(hidden_at, NOW()),
                                   hidden_by = COALESCE(hidden_by, ?),
                                   hidden_reason = COALESCE(hidden_reason, ?),
                                   updated_at = NOW()
                             WHERE id IN ({$in}) AND visibility <> 'hidden'";
                    array_unshift($params, $actorId, $this->clip($reason));
                } else {
                    // 恢复时清掉 hidden_*：状态字段是唯一真源，留下的旧理由会让下一次隐藏
                    // 看起来像「早就藏过」。历史在审计里。
                    $sql = "UPDATE {$table}
                               SET visibility = 'visible',
                                   hidden_at = NULL,
                                   hidden_by = NULL,
                                   hidden_reason = NULL,
                                   updated_at = NOW()
                             WHERE id IN ({$in}) AND visibility <> 'visible'";
                }

                $stmt = $this->pdo->prepare($sql);
                $stmt->execute($params);
                $changed += $stmt->rowCount();
            }

            $this->audit->log(
                $actorId,
                $visibility === 'hidden' ? 'visibility.hide' : 'visibility.restore',
                'visibility.' . $type,
                count($ids) === 1 ? (string) $ids[0] : null,
                ['type' => $type],
                [
                    'type'      => $type,
                    'requested' => count($ids),
                    'changed'   => $changed,
                    // 审计里不塞 1300 个 id，塞前 20 个够定位是哪一批
                    'ids'       => array_slice($ids, 0, 20),
                    'reason'    => $reason,
                ]
            );

            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        // 失效放在事务之后：事务回滚时缓存不该被清（虽然清了也只是变慢），
        // 但更重要的是别在持锁期间做文件 IO
        $pages = $this->cache->invalidateTags($tags);

        // sitemap 也在这里，理由同上：它是文件 IO，而且失败不该回滚已经改好的数据
        $sitemap = $this->rewriteSitemap();

        return [
            'requested' => count($ids),
            'changed'   => $changed,
            'pages'     => $pages,
            'sitemap'   => $sitemap,
            'reason'    => $reason,
        ];
    }

    /**
     * 重写静态 sitemap.xml（文件不存在就跳过）。
     *
     * 只重写不新建：用动态 `/sitemap.xml` 路由的部署本来就没有这个文件，
     * 凭空生成一个只会让两份真相并存。
     *
     * 失败**不抛**：数据已经提交，隐藏事实上生效了，不该因为一个 SEO 文件
     * 把整次操作判成失败、让人重复点一遍。但必须留痕 —— 这是「藏了还看得见」
     * 的一种，只是发生在搜索引擎那边。
     */
    private function rewriteSitemap(): bool
    {
        $path = dirname(__DIR__, 3) . '/public/sitemap.xml';

        if (!is_file($path)) {
            return false;
        }

        try {
            SitemapBuilder::write($this->library, $this->config->string('app.url'), $path);

            return true;
        } catch (Throwable $e) {
            error_log('[visibility] sitemap 重写失败：' . $e->getMessage());

            return false;
        }
    }

    /**
     * 这批对象变化后，哪些页面会变。
     *
     * 注意「一级」与「二级」影响：
     *   · 隐藏作品 → 作品页变了（一级），它引用的每个词条的关联篇数也变了（二级），
     *     它所在期次的计数也变了（二级）。只失效一级就会留下一堆写着旧数字的页面。
     *
     * @param list<int> $ids
     * @return list<string>
     */
    private function affectedTags(string $type, array $ids): array
    {
        $tags = ['works', 'terms'];

        // 一批一批查，避免 IN 过长
        foreach (array_chunk($ids, self::BATCH) as $batch) {
            $in = implode(',', array_fill(0, count($batch), '?'));

            if ($type === self::WORK) {
                $stmt = $this->pdo->prepare(
                    "SELECT w.code, i.no AS issue_no
                       FROM hq_work w
                       LEFT JOIN hq_issue i ON i.id = w.issue_id
                      WHERE w.id IN ({$in})"
                );
                $stmt->execute($batch);
                foreach ($stmt->fetchAll() as $row) {
                    $tags[] = 'work:' . (string) $row['code'];
                    if ($row['issue_no'] !== null) {
                        $tags[] = 'issue:' . (int) $row['issue_no'];
                    }
                }

                $stmt = $this->pdo->prepare(
                    "SELECT DISTINCT term_id FROM hq_work_term WHERE work_id IN ({$in})"
                );
                $stmt->execute($batch);
                foreach ($stmt->fetchAll() as $row) {
                    $tags[] = 'term:' . (int) $row['term_id'];
                }

                continue;
            }

            if ($type === self::ANALYSIS) {
                $stmt = $this->pdo->prepare(
                    "SELECT w.code, i.no AS issue_no
                       FROM hq_analysis a
                       JOIN hq_work w ON w.id = a.work_id
                       LEFT JOIN hq_issue i ON i.id = w.issue_id
                      WHERE a.id IN ({$in})"
                );
                $stmt->execute($batch);
                foreach ($stmt->fetchAll() as $row) {
                    $tags[] = 'work:' . (string) $row['code'];
                    if ($row['issue_no'] !== null) {
                        $tags[] = 'issue:' . (int) $row['issue_no'];
                    }
                }

                $stmt = $this->pdo->prepare(
                    "SELECT DISTINCT term_id FROM hq_work_term WHERE analysis_id IN ({$in})"
                );
                $stmt->execute($batch);
                foreach ($stmt->fetchAll() as $row) {
                    $tags[] = 'term:' . (int) $row['term_id'];
                }

                continue;
            }

            // 词条：自身详情页变了，引用它的作品页上那个标签也消失了
            foreach ($batch as $termId) {
                $tags[] = 'term:' . $termId;
            }

            $stmt = $this->pdo->prepare(
                "SELECT DISTINCT w.code
                   FROM hq_work_term wt
                   JOIN hq_work w ON w.id = wt.work_id
                  WHERE wt.term_id IN ({$in})"
            );
            $stmt->execute($batch);
            foreach ($stmt->fetchAll() as $row) {
                $tags[] = 'work:' . (string) $row['code'];
            }
        }

        return array_values(array_unique($tags));
    }

    private function clip(string $reason): string
    {
        return mb_substr(trim($reason), 0, 200);
    }
}
