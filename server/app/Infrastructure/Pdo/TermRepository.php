<?php

declare(strict_types=1);

namespace App\Infrastructure\Pdo;

use PDO;
use RuntimeException;

/**
 * 索引词：全局词条（hq_index_term）与作品关联（hq_work_term）。
 *
 * 两张表的分工不是冗余：
 *   · `hq_index_term` 是**全站词表**，一个概念只该有一条（canonical 唯一）；
 *   · `hq_work_term` 是**某篇分析用到哪些词**，带主/辅、权重与引文。
 * 所以「归并」改的是词表（把 A 并进 B，A 从此只是一个别名），
 * 「调权/主辅/删词」改的是关联（只影响这一篇）。
 * 把两者混为一谈就会写出「改一篇作品的词，全站词表跟着变」这种事故。
 */
final class TermRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * 某篇分析的索引词（分析页渲染用）。
     *
     * @return list<array<string, mixed>>
     */
    public function forAnalysis(int $analysisId): array
    {
        $sql = 'SELECT wt.work_id, wt.term_id, wt.role, wt.weight, wt.evidence, wt.created_at,
                       t.canonical, t.category, t.status, t.merged_into,
                       m.canonical AS merged_into_canonical,
                       (SELECT COUNT(*) FROM hq_index_term_alias al WHERE al.term_id = t.id) AS alias_count
                  FROM hq_work_term wt
                  JOIN hq_index_term t ON t.id = wt.term_id
                  LEFT JOIN hq_index_term m ON m.id = t.merged_into
                 WHERE wt.analysis_id = :aid
                 ORDER BY FIELD(t.category, \'setting\',\'world\',\'image\'),
                          wt.role = \'sub\', wt.weight DESC, t.canonical';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':aid' => $analysisId]);

        return $stmt->fetchAll();
    }

    /** @return list<string> */
    public function aliasesOf(int $termId): array
    {
        $stmt = $this->pdo->prepare('SELECT alias FROM hq_index_term_alias WHERE term_id = :t ORDER BY id');
        $stmt->execute([':t' => $termId]);

        return array_values(array_map(static fn (array $r): string => (string) $r['alias'], $stmt->fetchAll()));
    }

    // ------------------------------------------------------------ 词条治理（后台）

    /**
     * 「这个词在前台关联几篇作品」。
     *
     * 与 LibraryRepository::PUBLISHED_JOIN 是同一套口径（已发布 ∧ 当前分析 ∧ 都未隐藏）。
     * 后台必须用这个数字，不能用「关联了几篇」——运营要判断的是读者会看到什么，
     * 而不是数据库里躺着多少行。两个数字都返回，差值就是「隐藏吃掉的」。
     */
    private const REFS_VISIBLE = "(SELECT COUNT(*)
          FROM hq_work_term wtv
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
         WHERE wtv.term_id = t.id)";

    /**
     * 后台词条列表。
     *
     * 1300 篇跑完之后词条会到两三千条，所以这里必须「能筛、能排、能分页」：
     *   · 筛：分类 / 可见性 / 关键词（含别名）
     *   · 排：引用数降序（运营最关心「哪些词只有一篇」）、最近更新、字典序
     *   · 分页：每页 50
     *
     * @param array{keyword?:string, category?:string, visibility?:string, status?:string, sort?:string} $filter
     * @return array{rows: list<array<string,mixed>>, total: int}
     */
    public function paginate(array $filter, int $page, int $perPage): array
    {
        [$whereSql, $params] = $this->buildWhere($filter);

        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM hq_index_term t WHERE ' . $whereSql
        );
        $stmt->execute($params);
        $total = (int) $stmt->fetchColumn();

        $page    = max(1, $page);
        $perPage = min(100, max(1, $perPage));
        $offset  = ($page - 1) * $perPage;

        // 排序表达式只从白名单里取，绝不拼外部输入
        $orderBy = match ((string) ($filter['sort'] ?? 'refs')) {
            'name'    => 't.canonical ASC',
            'newest'  => 't.id DESC',
            'hidden'  => "t.visibility = 'visible', t.canonical ASC",
            default   => 'refs DESC, t.weight_base DESC, t.canonical ASC',
        };

        $sql = 'SELECT t.id, t.canonical, t.category, t.status, t.visibility,
                       t.hidden_at, t.hidden_reason, t.is_canonical, t.definition, t.updated_at,
                       ' . self::REFS_VISIBLE . ' AS refs,
                       (SELECT COUNT(*) FROM hq_work_term wtr WHERE wtr.term_id = t.id) AS refs_all,
                       (SELECT COUNT(*) FROM hq_index_term_alias al WHERE al.term_id = t.id) AS aliases
                  FROM hq_index_term t
                 WHERE ' . $whereSql . '
                 ORDER BY ' . $orderBy . '
                 LIMIT ' . $perPage . ' OFFSET ' . $offset;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return ['rows' => $stmt->fetchAll(), 'total' => $total];
    }

    /**
     * 「按当前筛选全选」的 id 清单。理由同 WorkRepository::idsByFilter —— 只信服务端的条件。
     *
     * @param array<string, mixed> $filter
     * @return list<int>
     */
    public function idsByFilter(array $filter, int $limit = 2000): array
    {
        [$whereSql, $params] = $this->buildWhere($filter);
        $limit = max(1, min(2000, $limit));

        $stmt = $this->pdo->prepare(
            'SELECT t.id FROM hq_index_term t WHERE ' . $whereSql . ' ORDER BY t.id LIMIT ' . $limit
        );
        $stmt->execute($params);

        return array_map(static fn (array $r): int => (int) $r['id'], $stmt->fetchAll());
    }

    /**
     * @param array<string, mixed> $filter
     * @return array{0:string, 1:array<string,mixed>}
     */
    private function buildWhere(array $filter): array
    {
        // 状态先定：显式指定就按指定，否则默认只看 active。
        // （早先写成「先加 active、后面再 array_shift 掉」，可读性差且容易误删别的条件）
        $where  = [];
        $params = [];

        $status = (string) ($filter['status'] ?? '');
        if (in_array($status, ['active', 'merged', 'deprecated'], true)) {
            $where[]         = 't.status = :status';
            $params[':status'] = $status;
        } else {
            $where[] = "t.status = 'active'";
        }

        $category = (string) ($filter['category'] ?? '');
        if (in_array($category, ['setting', 'world', 'image'], true)) {
            $where[]          = 't.category = :cat';
            $params[':cat']   = $category;
        }

        $visibility = (string) ($filter['visibility'] ?? '');
        if ($visibility === 'hidden') {
            $where[] = "t.visibility = 'hidden'";
        } elseif ($visibility === 'visible') {
            $where[] = "t.visibility = 'visible'";
        }

        $keyword = trim((string) ($filter['keyword'] ?? ''));
        if ($keyword !== '') {
            $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $keyword) . '%';
            $where[] = '(t.canonical LIKE :kw OR EXISTS (
                            SELECT 1 FROM hq_index_term_alias al2 WHERE al2.term_id = t.id AND al2.alias LIKE :kw2))';
            $params[':kw']  = $like;
            $params[':kw2'] = $like;
        }

        return [implode(' AND ', $where), $params];
    }

    /**
     * 词条治理的计数（概览与词库页头用）。
     *
     * @return array<string,int>
     */
    public function adminStats(): array
    {
        $row = $this->pdo->query(
            "SELECT COUNT(*) AS total,
                    COALESCE(SUM(visibility = 'hidden'), 0) AS hidden,
                    COALESCE(SUM(is_canonical = 1), 0) AS canonical,
                    COALESCE(SUM(status = 'merged'), 0) AS merged
               FROM hq_index_term WHERE status = 'active'"
        )->fetch() ?: [];

        // 「孤儿词」：一条作品都不引用。它们在词库里不显示，但会一直占着选词范围，
        // 运营需要知道有多少 —— 1300 篇跑完，这个数字会明显上升。
        $orphans = (int) $this->pdo->query(
            'SELECT COUNT(*) FROM hq_index_term t
              WHERE t.status = \'active\'
                AND NOT EXISTS (SELECT 1 FROM hq_work_term wt WHERE wt.term_id = t.id)'
        )->fetchColumn();

        return [
            'total'     => (int) ($row['total'] ?? 0),
            'hidden'    => (int) ($row['hidden'] ?? 0),
            'canonical' => (int) ($row['canonical'] ?? 0),
            'merged'    => (int) ($row['merged'] ?? 0),
            'orphans'   => $orphans,
        ];
    }

    /**
     * 后台词条详情：不受可见性限制地取出这个词，连同它关联的作品。
     *
     * @return array<string,mixed>|null
     */
    public function findForAdmin(int $termId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT t.*, ' . self::REFS_VISIBLE . ' AS refs,
                    (SELECT COUNT(*) FROM hq_work_term wtr WHERE wtr.term_id = t.id) AS refs_all
               FROM hq_index_term t WHERE t.id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $termId]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    /**
     * 引用了某个词的已发布作品（后台详情页用，含可见性标注）。
     *
     * @return list<array<string,mixed>>
     */
    public function worksOfTermForAdmin(int $termId, int $limit = 50): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT w.id, w.code, w.title, w.visibility AS work_visibility,
                    a.visibility AS analysis_visibility, a.status AS analysis_status,
                    wt.role, wt.weight, i.no AS issue_no, au.name AS author_name
               FROM hq_work_term wt
               JOIN hq_work w ON w.id = wt.work_id AND w.deleted_at IS NULL
               JOIN hq_analysis a ON a.id = wt.analysis_id
               LEFT JOIN hq_issue i ON i.id = w.issue_id
               LEFT JOIN hq_author au ON au.id = w.author_id
              WHERE wt.term_id = :tid
              ORDER BY wt.weight DESC, w.id DESC
              LIMIT ' . max(1, min(200, $limit))
        );
        $stmt->execute([':tid' => $termId]);

        return $stmt->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public function findById(int $termId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM hq_index_term WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $termId]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    /** @return array<string, mixed>|null */
    public function findByCanonical(string $canonical): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM hq_index_term WHERE canonical = :c LIMIT 1');
        $stmt->execute([':c' => $canonical]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    /**
     * 归并目标搜索：优先前缀匹配，其次包含匹配；已归并的词条也列出来（附并入目标），
     * 因为「A 已经并进 B 了，再想把 C 并进 A」应当提示改选 B。
     *
     * @return list<array<string, mixed>>
     */
    public function search(string $keyword, int $limit = 20): array
    {
        $keyword = trim($keyword);
        $limit   = max(1, min(50, $limit));

        if ($keyword === '') {
            $stmt = $this->pdo->prepare(
                'SELECT t.*, m.canonical AS merged_into_canonical
                   FROM hq_index_term t LEFT JOIN hq_index_term m ON m.id = t.merged_into
                  ORDER BY (SELECT COUNT(*) FROM hq_work_term wt WHERE wt.term_id = t.id) DESC, t.id DESC
                  LIMIT ' . $limit
            );
            $stmt->execute();

            return $stmt->fetchAll();
        }

        $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $keyword) . '%';
        $sql  = 'SELECT t.*, m.canonical AS merged_into_canonical,
                        (SELECT COUNT(*) FROM hq_work_term wt WHERE wt.term_id = t.id) AS work_count
                   FROM hq_index_term t
                   LEFT JOIN hq_index_term m ON m.id = t.merged_into
                  WHERE t.canonical LIKE :kw
                     OR EXISTS (SELECT 1 FROM hq_index_term_alias a WHERE a.term_id = t.id AND a.alias LIKE :kw2)
                  ORDER BY t.canonical = :exact DESC, t.canonical LIKE :prefix DESC, work_count DESC
                  LIMIT ' . $limit;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':kw'     => $like,
            ':kw2'    => $like,
            ':exact'  => $keyword,
            ':prefix' => $keyword . '%',
        ]);

        return $stmt->fetchAll();
    }

    /** 改作品关联的主/辅与权重（只影响这一篇，不动全局词表） */
    public function updateWorkTerm(int $analysisId, int $termId, string $role, int $weight): void
    {
        $role = in_array($role, ['main', 'sub'], true) ? $role : 'sub';
        $weight = max(1, min(100, $weight));

        $stmt = $this->pdo->prepare(
            'UPDATE hq_work_term SET role = :role, weight = :weight
              WHERE analysis_id = :aid AND term_id = :tid'
        );
        $stmt->execute([':role' => $role, ':weight' => $weight, ':aid' => $analysisId, ':tid' => $termId]);

        if ($stmt->rowCount() === 0) {
            throw new RuntimeException('这条索引词不属于该分析，可能已被重跑覆盖');
        }
    }

    /** 从这一篇里移除某个词（不动全局词表：别的作品可能还在用） */
    public function deleteWorkTerm(int $analysisId, int $termId): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM hq_work_term WHERE analysis_id = :aid AND term_id = :tid');
        $stmt->execute([':aid' => $analysisId, ':tid' => $termId]);
    }

    /**
     * 给这篇作品补一个索引词。
     *
     * 词条必须已经在全局词表里 —— 这里不造词。造词会让词库长出一堆只出现过一次的
     * 私有说法，之后就没人敢删了；要加新词，走「词条治理 → 新增」，那是另一个入口。
     *
     * 主键是 (work_id, term_id)，同一个词重复点「加入」不该报错，所以用
     * ON DUPLICATE KEY UPDATE 覆盖，语义等于「确保这篇挂上这个词」。
     */
    public function addWorkTerm(int $analysisId, int $termId, string $role, int $weight, string $evidence = ''): void
    {
        $role   = in_array($role, ['main', 'sub'], true) ? $role : 'sub';
        $weight = max(1, min(100, $weight));

        $owner = $this->pdo->prepare('SELECT work_id FROM hq_analysis WHERE id = :aid LIMIT 1');
        $owner->execute([':aid' => $analysisId]);
        $workId = (int) $owner->fetchColumn();

        if ($workId <= 0) {
            throw new RuntimeException('找不到这次分析对应的作品');
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO hq_work_term (work_id, term_id, role, weight, evidence, analysis_id, created_at)
             VALUES (:work, :tid, :role, :weight, :ev, :aid, NOW())
             ON DUPLICATE KEY UPDATE role = VALUES(role), weight = VALUES(weight),
                                     evidence = VALUES(evidence), analysis_id = VALUES(analysis_id)'
        );
        $stmt->execute([
            ':work'   => $workId,
            ':tid'    => $termId,
            ':role'   => $role,
            ':weight' => $weight,
            ':ev'     => mb_substr(trim($evidence), 0, 200),
            ':aid'    => $analysisId,
        ]);
    }

    /**
     * 把 from 归并进 to。整个过程一个事务，任一步失败整批回滚。
     *
     * 三步，顺序不能反：
     *   1. 作品关联搬迁 —— 先处理"这篇同时用了两个词"的重复（PK 是 work_id+term_id，直接改会撞键）；
     *   2. 别名搬迁 —— 别名全局唯一，`UPDATE IGNORE` 后再删残留，抢不到的唯一性就留给原词条做记录；
     *   3. 标记 from 为 merged 并写入 merged_into。
     *
     * @return array{works_moved:int, works_deduped:int, aliases_moved:int}
     */
    public function merge(int $fromId, int $toId, ?int $actorId = null): array
    {
        if ($fromId === $toId) {
            throw new RuntimeException('不能把词条并入它自己');
        }

        $from = $this->findById($fromId);
        $to   = $this->findById($toId);

        if ($from === null || $to === null) {
            throw new RuntimeException('词条不存在');
        }
        if ($from['status'] === 'merged') {
            throw new RuntimeException(sprintf(
                '「%s」已经并入「%s」，请直接归并到最终词条',
                (string) $from['canonical'],
                (string) ($from['merged_into'] === null ? '其他词条' : '该词条')
            ));
        }
        if ($to['status'] === 'merged') {
            throw new RuntimeException(sprintf('「%s」本身已被归并，不能作为归并目标', (string) $to['canonical']));
        }
        if ($from['category'] !== $to['category']) {
            throw new RuntimeException(sprintf(
                '不能跨类别归并：「%s」属于%s，「%s」属于%s',
                (string) $from['canonical'],
                (string) $from['category'],
                (string) $to['canonical'],
                (string) $to['category']
            ));
        }

        $moved   = 0;
        $dedup   = 0;
        $aliases = 0;

        $this->pdo->beginTransaction();
        try {
            // ---- 1. 作品关联 ----
            $rows = $this->pdo->prepare('SELECT work_id, role, weight, evidence FROM hq_work_term WHERE term_id = :t');
            $rows->execute([':t' => $fromId]);
            $fromRows = $rows->fetchAll();

            $hasTo = $this->pdo->prepare('SELECT role, weight, evidence FROM hq_work_term WHERE work_id = :w AND term_id = :t');
            $bump  = $this->pdo->prepare(
                'UPDATE hq_work_term SET role = :role, weight = :weight, evidence = :evidence
                  WHERE work_id = :w AND term_id = :t'
            );
            $move  = $this->pdo->prepare('UPDATE hq_work_term SET term_id = :to WHERE work_id = :w AND term_id = :from');
            $drop  = $this->pdo->prepare('DELETE FROM hq_work_term WHERE work_id = :w AND term_id = :from');

            foreach ($fromRows as $row) {
                $workId = (int) $row['work_id'];
                $hasTo->execute([':w' => $workId, ':t' => $toId]);
                $existing = $hasTo->fetch();

                if (is_array($existing)) {
                    // 同一篇作品两个词都用了 → 合并成一条：主词优先、权重取大、引文取非空
                    $role = ($row['role'] === 'main' || $existing['role'] === 'main') ? 'main' : 'sub';
                    $bump->execute([
                        ':role'     => $role,
                        ':weight'   => max((int) $existing['weight'], (int) $row['weight']),
                        ':evidence' => (string) ($existing['evidence'] ?? '') !== '' ? $existing['evidence'] : $row['evidence'],
                        ':w'        => $workId,
                        ':t'        => $toId,
                    ]);
                    $drop->execute([':w' => $workId, ':from' => $fromId]);
                    $dedup++;
                } else {
                    $move->execute([':to' => $toId, ':w' => $workId, ':from' => $fromId]);
                    $moved++;
                }
            }

            // ---- 2. 别名 ----
            // 抢不到唯一性的那些留着也没意义（别名表对 alias 建的是全局唯一），删残留即可
            $aliasMove = $this->pdo->prepare('UPDATE IGNORE hq_index_term_alias SET term_id = :to WHERE term_id = :from');
            $aliasMove->execute([':to' => $toId, ':from' => $fromId]);
            $aliases = $aliasMove->rowCount();
            $this->pdo->prepare('DELETE FROM hq_index_term_alias WHERE term_id = :from')->execute([':from' => $fromId]);

            // 原词条本身也是一条"叫法"，登记为目标词条的别名，之后搜旧名还能搜到
            $this->pdo->prepare(
                'INSERT IGNORE INTO hq_index_term_alias (term_id, alias, source, created_at)
                 VALUES (:to, :alias, \'human\', NOW())'
            )->execute([':to' => $toId, ':alias' => (string) $from['canonical']]);

            // ---- 3. 标记 ----
            $this->pdo->prepare(
                'UPDATE hq_index_term SET status = \'merged\', merged_into = :to, updated_at = NOW() WHERE id = :from'
            )->execute([':to' => $toId, ':from' => $fromId]);

            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return ['works_moved' => $moved, 'works_deduped' => $dedup, 'aliases_moved' => $aliases];
    }

    /**
     * 撤销归并（只能恢复词条本身）。
     *
     * 说清楚做不到什么：已经搬到目标词条的作品关联**不会**自动搬回来 ——
     * 归并的语义是"这两个说法指同一个概念"，作品挂在哪个 id 上对读者没有区别；
     * 硬要回滚就得给每次归并留一份明细快照，那是另一套机制（hq_analysis_revision 那类）。
     * 与其做一个"看起来能撤销"的半成品，不如把边界写在界面上。
     */
    public function unmerge(int $termId): void
    {
        $term = $this->findById($termId);
        if ($term === null) {
            throw new RuntimeException('词条不存在');
        }
        if ($term['status'] !== 'merged') {
            throw new RuntimeException('该词条没有被归并');
        }

        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare(
                'DELETE FROM hq_index_term_alias WHERE term_id = :to AND alias = :alias AND source = \'human\''
            )->execute([':to' => (int) $term['merged_into'], ':alias' => (string) $term['canonical']]);

            $this->pdo->prepare(
                'UPDATE hq_index_term SET status = \'active\', merged_into = NULL, updated_at = NOW() WHERE id = :id'
            )->execute([':id' => $termId]);

            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }
}
