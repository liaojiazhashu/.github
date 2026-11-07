<?php

declare(strict_types=1);

namespace App\Infrastructure\Pdo;

use App\Support\Text;
use App\Support\Ulid;
use PDO;

/**
 * 作品聚合仓储。所有 SQL 预处理，列名与排序字段全部来自白名单。
 */
final class WorkRepository
{
    /** 允许排序的列（外部参数只做映射，绝不拼进 SQL） */
    private const SORTABLE = [
        'created' => 'w.id',
        'title'   => 'w.title',
        'words'   => 'w.word_count',
        'issue'   => 'w.issue_id',
    ];

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return list<array<string, mixed>> */
    public function issues(): array
    {
        return $this->pdo->query('SELECT id, no, label, status FROM hq_issue ORDER BY no DESC')->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public function findIssueByNo(int $no): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, no, label FROM hq_issue WHERE no = :no LIMIT 1');
        $stmt->execute([':no' => $no]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    public function ensureIssue(int $no): int
    {
        $found = $this->findIssueByNo($no);
        if ($found !== null) {
            return (int) $found['id'];
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO hq_issue (no, label, starts_at, ends_at, status, created_at, updated_at)
             VALUES (:no, :label, NOW(), NOW(), \'planned\', NOW(), NOW())'
        );
        $stmt->execute([':no' => $no, ':label' => '第 ' . $no . ' 期']);

        return (int) $this->pdo->lastInsertId();
    }

    /** @return list<array<string, mixed>> */
    public function genres(): array
    {
        return $this->pdo->query('SELECT id, code, name FROM hq_genre ORDER BY sort, id')->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public function findGenreByCode(string $code): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, code, name FROM hq_genre WHERE code = :code LIMIT 1');
        $stmt->execute([':code' => $code]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    /**
     * 按笔名归一值查作者，无则新建。
     *
     * 注：hq_author.name_norm 只有普通索引，并发下理论上可能插出两条同名作者；
     * 后台单人操作的实际并发为 0，且后续有「作者合并」工具（§5.7 巡检）兜底。
     */
    public function findOrCreateAuthor(string $name): int
    {
        $norm = Text::normalizeName($name);

        $stmt = $this->pdo->prepare('SELECT id FROM hq_author WHERE name_norm = :norm ORDER BY id LIMIT 1');
        $stmt->execute([':norm' => $norm]);
        $id = $stmt->fetchColumn();
        if ($id !== false) {
            return (int) $id;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO hq_author (code, name, name_norm, created_at, updated_at)
             VALUES (:code, :name, :norm, NOW(), NOW())'
        );
        $stmt->execute([':code' => Ulid::generate(), ':name' => $name, ':norm' => $norm]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @param array{issue_id:int, author_id:?int, genre_id:?int, title:string, subtitle:?string,
     *              word_count:int, summary:?string, status:string, source:string, created_by:int} $data
     * @return array{id:int, code:string}
     */
    public function createWork(array $data): array
    {
        $code = Ulid::generate();
        $stmt = $this->pdo->prepare(
            'INSERT INTO hq_work
                (code, issue_id, author_id, genre_id, title, subtitle, word_count, summary,
                 status, current_analysis_id, source, published_at, deleted_at, created_by, created_at, updated_at)
             VALUES
                (:code, :issue, :author, :genre, :title, :subtitle, :words, :summary,
                 :status, NULL, :source, NULL, NULL, :creator, NOW(), NOW())'
        );
        $stmt->execute([
            ':code'     => $code,
            ':issue'    => $data['issue_id'],
            ':author'   => $data['author_id'],
            ':genre'    => $data['genre_id'],
            ':title'    => $data['title'],
            ':subtitle' => $data['subtitle'],
            ':words'    => $data['word_count'],
            ':summary'  => $data['summary'],
            ':status'   => $data['status'],
            ':source'   => $data['source'],
            ':creator'  => $data['created_by'],
        ]);

        return ['id' => (int) $this->pdo->lastInsertId(), 'code' => $code];
    }

    /**
     * @param array{storage_key:string, orig_name:string, mime:string, size_bytes:int,
     *              sha256:string, encoding:string, char_count:int} $file
     */
    public function addFile(int $workId, array $file): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO hq_work_file
                (work_id, storage_key, orig_name, mime, size_bytes, sha256, encoding, char_count, created_at)
             VALUES (:work, :key, :name, :mime, :size, :sha, :enc, :chars, NOW())'
        );
        $stmt->execute([
            ':work'  => $workId,
            ':key'   => $file['storage_key'],
            ':name'  => $file['orig_name'],
            ':mime'  => $file['mime'],
            ':size'  => $file['size_bytes'],
            ':sha'   => $file['sha256'],
            ':enc'   => $file['encoding'],
            ':chars' => $file['char_count'],
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function addVersion(int $workId, int $fileId, int $versionNo, string $sha, int $charCount, ?string $note): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO hq_work_version (work_id, file_id, version_no, sha256, char_count, note, created_at)
             VALUES (:work, :file, :ver, :sha, :chars, :note, NOW())'
        );
        $stmt->execute([
            ':work'  => $workId,
            ':file'  => $fileId,
            ':ver'   => $versionNo,
            ':sha'   => $sha,
            ':chars' => $charCount,
            ':note'  => $note,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * 原文版本（hq_work_version）。一次导入 = 版本 1；作者改稿后重新上传 = 版本 2、3…
     *
     * @return list<array<string, mixed>>
     */
    public function versionsOf(int $workId): array
    {
        $sql = 'SELECT v.id, v.version_no, v.sha256, v.char_count, v.note, v.created_at,
                       f.orig_name, f.encoding, f.size_bytes
                  FROM hq_work_version v
                  JOIN hq_work_file f ON f.id = v.file_id
                 WHERE v.work_id = :work
                 ORDER BY v.version_no DESC';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':work' => $workId]);

        return $stmt->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public function findVersion(int $versionId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM hq_work_version WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $versionId]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    /** @return array<string, mixed>|null 同一作品的某个版本号（diff 页用） */
    public function findVersionByNo(int $workId, int $versionNo): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM hq_work_version WHERE work_id = :work AND version_no = :no LIMIT 1'
        );
        $stmt->execute([':work' => $workId, ':no' => $versionNo]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    public function nextVersionNo(int $workId): int
    {
        $stmt = $this->pdo->prepare('SELECT COALESCE(MAX(version_no), 0) + 1 FROM hq_work_version WHERE work_id = :work');
        $stmt->execute([':work' => $workId]);

        return (int) $stmt->fetchColumn();
    }

    /** 正文字数跟着最新版本走（列表页与短文本拦截都读这个字段） */
    public function updateWordCount(int $workId, int $chars): void
    {
        $this->pdo->prepare('UPDATE hq_work SET word_count = :c, updated_at = NOW() WHERE id = :id')
            ->execute([':c' => max(0, $chars), ':id' => $workId]);
    }

    /**
     * 软删作品。
     *
     * 只写 deleted_at + 状态，不删任何行：删掉的应该是"这一篇不再对外"，
     * 而不是把分析、审计、调用记录一起抹了 —— 那些记录的价值恰恰在事后追溯。
     * 所有查询都带 `deleted_at IS NULL`，所以软删之后它就查不到了。
     */
    public function softDelete(int $workId): void
    {
        $this->pdo->prepare(
            'UPDATE hq_work SET deleted_at = NOW(), status = \'archived\', current_analysis_id = NULL, updated_at = NOW()
              WHERE id = :id AND deleted_at IS NULL'
        )->execute([':id' => $workId]);
    }

    /** @param list<string> $names */
    public function attachTags(int $workId, array $names): void
    {
        foreach ($names as $raw) {
            $name = trim($raw);
            if ($name === '' || mb_strlen($name) > 32) {
                continue;
            }

            $stmt = $this->pdo->prepare('SELECT id FROM hq_tag WHERE name = :name LIMIT 1');
            $stmt->execute([':name' => $name]);
            $tagId = $stmt->fetchColumn();

            if ($tagId === false) {
                $stmt = $this->pdo->prepare(
                    'INSERT INTO hq_tag (name, slug, kind, created_at) VALUES (:name, :slug, \'custom\', NOW())'
                );
                $stmt->execute([':name' => $name, ':slug' => $this->slugOf($name)]);
                $tagId = $this->pdo->lastInsertId();
            }

            $this->pdo->prepare(
                'INSERT IGNORE INTO hq_work_tag (work_id, tag_id, created_at) VALUES (:work, :tag, NOW())'
            )->execute([':work' => $workId, ':tag' => $tagId]);
        }
    }

    /** @return array<string, mixed>|null */
    public function findById(int $id): ?array
    {
        $sql = 'SELECT w.*, i.label AS issue_label, a.name AS author_name, g.name AS genre_name
                  FROM hq_work w
                  LEFT JOIN hq_issue i ON i.id = w.issue_id
                  LEFT JOIN hq_author a ON a.id = w.author_id
                  LEFT JOIN hq_genre g ON g.id = w.genre_id
                 WHERE w.id = :id AND w.deleted_at IS NULL
                 LIMIT 1';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    /** @return array<string, mixed>|null 最新原文版本 */
    public function latestVersion(int $workId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM hq_work_version WHERE work_id = :work ORDER BY version_no DESC LIMIT 1'
        );
        $stmt->execute([':work' => $workId]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    /** @return array<string, mixed>|null */
    public function findFile(int $fileId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, work_id, storage_key, orig_name, mime, size_bytes, sha256, encoding, char_count
               FROM hq_work_file WHERE id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $fileId]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    /** 分析开始/结束时推进作品状态；不碰 current_analysis_id（那是发布才做的事） */
    public function setStatus(int $workId, string $status): void
    {
        $stmt = $this->pdo->prepare('UPDATE hq_work SET status = :status, updated_at = NOW() WHERE id = :id');
        $stmt->execute([':status' => $status, ':id' => $workId]);
    }

    /**
     * 「这次分析跑完了」时该把作品置成什么状态。
     *
     * 已发布的作品重跑一次分析，它对外的那一份（current_analysis_id）并没有变，
     * 所以状态必须是 published，不能回落成 analyzed —— 回落的后果是前台列表里少一篇，
     * 而作品详情页直接 404，看起来像「发布丢了」。这条不变式以前散在 Pipeline 的三处，
     * 现在收在这里：状态由指针决定，不由「跑没跑完」决定。
     */
    public function markAnalyzed(int $workId): void
    {
        $this->pdo->prepare(
            'UPDATE hq_work
                SET status = CASE WHEN current_analysis_id IS NULL THEN \'analyzed\' ELSE \'published\' END,
                    updated_at = NOW()
              WHERE id = :id'
        )->execute([':id' => $workId]);
    }

    /**
     * 一次分析**中止**（失败 / 运营停止 / 进程中断）之后，作品该回到哪一格。
     *
     * 与 markAnalyzed 同一条不变式：状态由指针与既有产物决定，不由「这次跑没跑完」决定。
     * 三条判断，顺序不能换：
     *   1. 作品当前指向的是**另一条**已发布的分析 → 这是一次重跑被中止，
     *      作品仍然在线，退回 imported 会让它从馆藏里凭空消失；
     *   2. 被中止的这条已经有阶段跑成功 → 产物还能看，留在 analyzed；
     *   3. 什么都没留下 → imported，可以重新发起。
     *
     * 以前停止分支压根没走这一步，作品会永久停在 analyzing：后台列表一直显示
     * 「正在分析」，而 BatchDispatcher 的「正在分析」跳过规则又会让它再次入队时被静默跳过 ——
     * 一篇作品就这样从运营视野里消失，且没有任何一处报错。
     */
    public function statusAfterAbort(int $workId, int $analysisId): string
    {
        $stmt = $this->pdo->prepare(
            'SELECT w.current_analysis_id,
                    a.status AS current_status,
                    (SELECT COUNT(*) FROM hq_analysis_stage s
                      WHERE s.analysis_id = :aid AND s.status = \'succeeded\') AS done_stages
               FROM hq_work w
               LEFT JOIN hq_analysis a ON a.id = w.current_analysis_id
              WHERE w.id = :id'
        );
        $stmt->execute([':aid' => $analysisId, ':id' => $workId]);
        $row = $stmt->fetch();

        if (!is_array($row)) {
            return 'imported';
        }

        return self::decideStatusAfterAbort(
            $row['current_analysis_id'] === null ? null : (int) $row['current_analysis_id'],
            $analysisId,
            $row['current_status'] === null ? null : (string) $row['current_status'],
            (int) $row['done_stages']
        );
    }

    /**
     * statusAfterAbort 的判定本身，与数据库无关，单独抽出来是为了能直接单测。
     *
     * @param int|null    $pointer       hq_work.current_analysis_id
     * @param int         $analysisId    被中止的那条分析
     * @param string|null $pointerStatus 指针所指那条分析的状态
     * @param int         $doneStages    被中止那条已跑成功的阶段数
     */
    public static function decideStatusAfterAbort(
        ?int $pointer,
        int $analysisId,
        ?string $pointerStatus,
        int $doneStages
    ): string {
        // 1. 指针指向另一条已发布的 → 重跑被中止，作品仍在线。这一条必须排在最前，
        //    否则一篇已上线的作品会因为一次失败的重跑从馆藏里消失。
        if ($pointer !== null && $pointer > 0 && $pointer !== $analysisId && $pointerStatus === 'published') {
            return 'published';
        }

        // 2. 有跑成功的阶段 → 产物还能看，留在 analyzed（与 analysis:reap 同口径）。
        if ($doneStages > 0) {
            return 'analyzed';
        }

        // 3. 什么都没留下 → imported，可以重新发起。
        return 'imported';
    }

    /** @return array<string, mixed>|null */
    public function findByCode(string $code): ?array
    {
        $sql = 'SELECT w.*, i.label AS issue_label, a.name AS author_name, g.name AS genre_name
                  FROM hq_work w
                  LEFT JOIN hq_issue i ON i.id = w.issue_id
                  LEFT JOIN hq_author a ON a.id = w.author_id
                  LEFT JOIN hq_genre g ON g.id = w.genre_id
                 WHERE w.code = :code AND w.deleted_at IS NULL
                 LIMIT 1';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':code' => $code]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    /**
     * 后台作品列表。
     *
     * 与前台列表的根本差别：这里要看得见**没发布的、被隐藏的、跑失败的**，
     * 因为 1300 篇的运营中，绝大多数时间面对的正是这些。
     *
     * stage 是把「文件状态 + 队列状态」合成的一个运营视角，比裸 status 有用：
     *   · pending  还没拆
     *   · running  正在拆（含排队）
     *   · ready    拆完了，还没发布
     *   · published 已发布
     *   · failed   拆解失败，等人处理
     *   · hidden   被隐藏（作品级或拆解级）
     * 裸 status 回答不了「这篇是不是卡在队列里」，而那恰恰是最需要一眼看见的事。
     *
     * @param array{keyword?:string, issue?:int, status?:string, stage?:string, visibility?:string, sort?:string} $filter
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function paginate(array $filter, int $page, int $perPage): array
    {
        [$whereSql, $params] = $this->buildWhere($filter);

        $orderBy = self::SORTABLE[(string) ($filter['sort'] ?? 'created')] ?? 'w.id';

        $countSql = 'SELECT COUNT(*)
                       FROM hq_work w
                       LEFT JOIN hq_author a ON a.id = w.author_id
                       LEFT JOIN hq_analysis ca ON ca.id = w.current_analysis_id
                      WHERE ' . $whereSql;
        $stmt = $this->pdo->prepare($countSql);
        $stmt->execute($params);
        $total = (int) $stmt->fetchColumn();

        $page    = max(1, $page);
        $perPage = min(100, max(1, $perPage));
        $offset  = ($page - 1) * $perPage;

        $sql = 'SELECT w.id, w.code, w.title, w.word_count, w.status, w.source, w.created_at,
                       w.visibility, w.hidden_at, w.hidden_reason,
                       w.current_analysis_id,
                       ca.visibility AS analysis_visibility,
                       ca.status AS analysis_status,
                       i.label AS issue_label, a.name AS author_name, g.name AS genre_name
                  FROM hq_work w
                  LEFT JOIN hq_analysis ca ON ca.id = w.current_analysis_id
                  LEFT JOIN hq_issue i ON i.id = w.issue_id
                  LEFT JOIN hq_author a ON a.id = w.author_id
                  LEFT JOIN hq_genre g ON g.id = w.genre_id
                 WHERE ' . $whereSql . '
                 ORDER BY ' . $orderBy . ' DESC
                 LIMIT ' . $perPage . ' OFFSET ' . $offset;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return ['rows' => $stmt->fetchAll(), 'total' => $total];
    }

    /**
     * 「按当前筛选全选」时用的 id 清单。
     *
     * 批量操作绝不接受前端传来的 1300 个 id —— 那既不可信（改一个数字就动了别的作品），
     * 也会撞上 max_input_vars。做法是：把同一套筛选条件重新在这里跑一遍，
     * 服务端自己算出「该动哪些」。这也是「筛出第 3 期失败的 47 篇，一次全部重跑」能成立的前提。
     *
     * @param array<string, mixed> $filter
     * @return list<int>
     */
    public function idsByFilter(array $filter, int $limit = 2000): array
    {
        [$whereSql, $params] = $this->buildWhere($filter);
        $limit = max(1, min(2000, $limit));

        // 只取 id，不取整行：2000 条 id 是几十 KB，2000 行完整记录是几 MB。
        // 不做 LIMIT/OFFSET 分页，因为这里的结果本来就要一次性拿全。
        $stmt = $this->pdo->prepare(
            'SELECT w.id
               FROM hq_work w
               LEFT JOIN hq_author a ON a.id = w.author_id
               LEFT JOIN hq_analysis ca ON ca.id = w.current_analysis_id
              WHERE ' . $whereSql . '
              ORDER BY w.id
              LIMIT ' . $limit
        );
        $stmt->execute($params);

        return array_map(static fn (array $r): int => (int) $r['id'], $stmt->fetchAll());
    }

    /**
     * 筛选条件 → WHERE 子句。列表与「全选」共用这一份，避免两处口径漂移。
     *
     * @param array<string, mixed> $filter
     * @return array{0:string, 1:array<string,mixed>}
     */
    private function buildWhere(array $filter): array
    {
        $where  = ['w.deleted_at IS NULL'];
        $params = [];

        $keyword = trim((string) ($filter['keyword'] ?? ''));
        if ($keyword !== '') {
            $where[] = '(w.title LIKE :kw OR a.name LIKE :kw2)';
            $like    = '%' . str_replace(['%', '_'], ['\%', '\_'], $keyword) . '%';
            $params[':kw']  = $like;
            $params[':kw2'] = $like;
        }

        $issue = (int) ($filter['issue'] ?? 0);
        if ($issue > 0) {
            $where[]        = 'w.issue_id = :issue';
            $params[':issue'] = $issue;
        }

        $status = (string) ($filter['status'] ?? '');
        if (in_array($status, ['draft', 'imported', 'analyzing', 'analyzed', 'published', 'archived'], true)) {
            $where[]         = 'w.status = :status';
            $params[':status'] = $status;
        }

        $visibility = (string) ($filter['visibility'] ?? '');
        if ($visibility === 'hidden') {
            $where[] = "(w.visibility = 'hidden' OR ca.visibility = 'hidden')";
        } elseif ($visibility === 'visible') {
            $where[] = "w.visibility = 'visible' AND (ca.visibility IS NULL OR ca.visibility = 'visible')";
        }

        $stage = (string) ($filter['stage'] ?? '');
        switch ($stage) {
            case 'pending':
                $where[] = "w.status = 'imported'";
                break;
            case 'running':
                $where[] = "(w.status = 'analyzing' OR EXISTS (
                                SELECT 1 FROM hq_job j
                                 WHERE j.type = 'analysis.run' AND j.work_id = w.id
                                   AND j.status IN ('queued','running')))";
                break;
            case 'ready':
                $where[] = "w.status = 'analyzed'";
                break;
            case 'published':
                $where[] = "w.status = 'published'";
                break;
            case 'failed':
                $where[] = "w.status <> 'published' AND EXISTS (
                                SELECT 1 FROM hq_job j
                                 WHERE j.type = 'analysis.run' AND j.work_id = w.id
                                   AND j.status = 'failed')";
                break;
            case 'hidden':
                $where[] = "(w.visibility = 'hidden' OR ca.visibility = 'hidden')";
                break;
        }

        return [implode(' AND ', $where), $params];
    }

    /** @return list<array<string, mixed>> */
    public function filesOf(int $workId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, storage_key, orig_name, mime, size_bytes, sha256, encoding, char_count, created_at
               FROM hq_work_file WHERE work_id = :work ORDER BY id DESC'
        );
        $stmt->execute([':work' => $workId]);

        return $stmt->fetchAll();
    }

    /** @return list<string> */
    public function tagsOf(int $workId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT t.name FROM hq_work_tag wt JOIN hq_tag t ON t.id = wt.tag_id WHERE wt.work_id = :work ORDER BY t.name'
        );
        $stmt->execute([':work' => $workId]);

        return array_values(array_map(static fn ($r): string => (string) $r['name'], $stmt->fetchAll()));
    }

    public function createBatch(string $code, string $sourceType, ?string $filename, int $total, int $userId): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO hq_import_batch (code, source_type, filename, total, ok, failed, status, created_by, created_at)
             VALUES (:code, :type, :file, :total, 0, 0, \'running\', :user, NOW())'
        );
        $stmt->execute([
            ':code'  => $code,
            ':type'  => $sourceType,
            ':file'  => $filename,
            ':total' => $total,
            ':user'  => $userId,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function addBatchRow(int $batchId, int $rowNo, ?array $raw, string $status, ?int $workId, ?string $error): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO hq_import_row (batch_id, row_no, raw_json, status, work_id, error, created_at)
             VALUES (:batch, :row, :raw, :status, :work, :error, NOW())'
        );
        $stmt->execute([
            ':batch'  => $batchId,
            ':row'    => $rowNo,
            ':raw'    => $raw === null ? null : json_encode($raw, JSON_UNESCAPED_UNICODE),
            ':status' => $status,
            ':work'   => $workId,
            ':error'  => $error === null ? null : mb_substr($error, 0, 500),
        ]);
    }

    public function finishBatch(int $batchId, int $ok, int $failed): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE hq_import_batch
                SET ok = :ok, failed = :failed,
                    status = CASE WHEN :failed2 > 0 AND :ok2 = 0 THEN \'failed\' ELSE \'done\' END,
                    finished_at = NOW()
              WHERE id = :id'
        );
        $stmt->execute([
            ':ok' => $ok, ':failed' => $failed,
            ':ok2' => $ok, ':failed2' => $failed,
            ':id' => $batchId,
        ]);
    }

    /**
     * 概览数字（首页四张卡）。
     *
     * @return array{total:int, pending:int, done:int, this_month:int, words:int}
     */
    public function stats(): array
    {
        $sql = "SELECT COUNT(*) AS total,
                       COALESCE(SUM(CASE WHEN status IN ('imported','analyzing') THEN 1 ELSE 0 END), 0) AS pending,
                       COALESCE(SUM(CASE WHEN status IN ('analyzed','published') THEN 1 ELSE 0 END), 0) AS done,
                       COALESCE(SUM(CASE WHEN created_at >= DATE_FORMAT(NOW(), '%Y-%m-01') THEN 1 ELSE 0 END), 0) AS this_month,
                       COALESCE(SUM(word_count), 0) AS words
                  FROM hq_work
                 WHERE deleted_at IS NULL";
        $row = $this->pdo->query($sql)->fetch() ?: [];

        return [
            'total'      => (int) ($row['total'] ?? 0),
            'pending'    => (int) ($row['pending'] ?? 0),
            'done'       => (int) ($row['done'] ?? 0),
            'this_month' => (int) ($row['this_month'] ?? 0),
            'words'      => (int) ($row['words'] ?? 0),
        ];
    }

    /**
     * 后台概览与调度台的数字。
     *
     * 和前台 stats() 的区别是「不只看能给人看的」。1300 篇的运营最常问的是：
     * 还有多少没拆、多少拆了没发、多少人被藏了、词条藏了几个。
     *
     * @return array<string,int>
     */
    public function adminStats(): array
    {
        $row = $this->pdo->query(
            "SELECT COUNT(*) AS total,
                    COALESCE(SUM(status = 'imported'), 0)  AS imported,
                    COALESCE(SUM(status = 'analyzing'), 0) AS analyzing,
                    COALESCE(SUM(status = 'analyzed'), 0)  AS analyzed,
                    COALESCE(SUM(status = 'published'), 0) AS published,
                    COALESCE(SUM(status = 'archived'), 0)  AS archived,
                    COALESCE(SUM(visibility = 'hidden'), 0) AS work_hidden,
                    COALESCE(SUM(word_count), 0) AS words
               FROM hq_work
              WHERE deleted_at IS NULL"
        )->fetch() ?: [];

        $analysisHidden = (int) $this->pdo->query(
            "SELECT COUNT(*) FROM hq_analysis a
               JOIN hq_work w ON w.id = a.work_id AND w.deleted_at IS NULL
              WHERE a.visibility = 'hidden'"
        )->fetchColumn();

        $terms = $this->pdo->query(
            "SELECT COUNT(*) AS total,
                    COALESCE(SUM(visibility = 'hidden'), 0) AS hidden
               FROM hq_index_term
              WHERE status = 'active'"
        )->fetch() ?: [];

        return [
            'total'           => (int) ($row['total'] ?? 0),
            'imported'        => (int) ($row['imported'] ?? 0),
            'analyzing'       => (int) ($row['analyzing'] ?? 0),
            'analyzed'        => (int) ($row['analyzed'] ?? 0),
            'published'       => (int) ($row['published'] ?? 0),
            'archived'        => (int) ($row['archived'] ?? 0),
            'work_hidden'     => (int) ($row['work_hidden'] ?? 0),
            'analysis_hidden' => $analysisHidden,
            'words'           => (int) ($row['words'] ?? 0),
            'terms_total'     => (int) ($terms['total'] ?? 0),
            'terms_hidden'    => (int) ($terms['hidden'] ?? 0),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function recent(int $limit = 8): array
    {
        $limit = min(50, max(1, $limit));
        $sql = 'SELECT w.code, w.title, w.word_count, w.status, w.created_at,
                       i.label AS issue_label, a.name AS author_name
                  FROM hq_work w
                  LEFT JOIN hq_issue i ON i.id = w.issue_id
                  LEFT JOIN hq_author a ON a.id = w.author_id
                 WHERE w.deleted_at IS NULL
                 ORDER BY w.id DESC
                 LIMIT ' . $limit;

        return $this->pdo->query($sql)->fetchAll();
    }

    // ------------------------------------------------- 官网来源（cid → 作品）

    /**
     * 官网 cid 已在馆内是哪一篇。
     *
     * 「一键拆解」按下之前要靠它判断这篇是「已入馆、直接排拆解」还是「还没入馆、先抓正文」。
     * 判据必须是数据库里的唯一键，不是标题比对 —— 同一期里标题撞车不罕见，
     * 而重导一次的代价是多一份一模一样的正文、多一次拆解费用。
     *
     * @return array{id:int, code:string, status:string}|null
     */
    public function findBySourceCid(int $cid): ?array
    {
        if ($cid <= 0) {
            return null;
        }

        $stmt = $this->pdo->prepare(
            'SELECT id, code, status FROM hq_work
              WHERE source_cid = :cid AND deleted_at IS NULL
              LIMIT 1'
        );
        $stmt->execute([':cid' => $cid]);
        $row = $stmt->fetch();

        return is_array($row) ? ['id' => (int) $row['id'], 'code' => (string) $row['code'], 'status' => (string) $row['status']] : null;
    }

    /**
     * 回填来源 cid。抓取入馆发生在 importFile 之后（导入服务不认识官网），
     * 所以是两步：先按普通作品导入，再补上来源。补不上就抛 —— 缺 cid 的作品
     * 下一轮会被当成「还没入馆」再抓一遍。
     */
    public function setSourceCid(int $workId, int $cid): void
    {
        $stmt = $this->pdo->prepare('UPDATE hq_work SET source_cid = :cid, updated_at = NOW() WHERE id = :id');
        $stmt->execute([':cid' => $cid, ':id' => $workId]);
    }

    /**
     * 全部「有官网来源」的作品：cid → {work_id, status}。
     *
     * 一次取全表而不是逐篇查：一键拆解的范围预览要在这张图上做集合运算，
     * 1177 篇逐条查就是 1177 次往返，页面会卡到没法用。
     *
     * @return array<int, array{id:int, status:string, code:string}>
     */
    public function sourceCidMap(): array
    {
        $rows = $this->pdo->query(
            'SELECT id, code, status, source_cid FROM hq_work
              WHERE source_cid IS NOT NULL AND deleted_at IS NULL'
        )->fetchAll();

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['source_cid']] = [
                'id'     => (int) $row['id'],
                'code'   => (string) $row['code'],
                'status' => (string) $row['status'],
            ];
        }

        return $map;
    }

    private function slugOf(string $name): string
    {
        $ascii = strtolower((string) preg_replace('/[^a-zA-Z0-9]+/', '-', $name));
        $ascii = trim($ascii, '-');

        return $ascii !== '' ? mb_substr($ascii, 0, 40) : 't-' . substr(sha1($name), 0, 10);
    }
}
