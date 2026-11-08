<?php

declare(strict_types=1);

namespace App\Application\Pipeline;

use App\Application\Analysis\BatchDispatcher;
use App\Application\Analysis\PaceEstimate;
use App\Application\Source\AuthorActivity;
use App\Application\Source\SourceCatalog;
use App\Infrastructure\Pdo\AuditLogger;
use App\Infrastructure\Pdo\JobRepository;
use App\Infrastructure\Pdo\RunBatchRepository;
use App\Infrastructure\Pdo\WorkRepository;
use App\Support\Ulid;
use PDO;
use RuntimeException;

/**
 * 一键拆解：选一段范围，按一次；剩下的（抓正文 → 入馆 → 拆解 → 可选上架）由队列跑完。
 *
 * 这个类只做两件事——**算清楚会动哪些篇**，以及**把活派下去**。真正干活的是 worker：
 * 拆解是分钟级、要调模型、会失败重试的长任务，塞进 HTTP 请求里必然超时。
 *
 * 范围的单位是「官网的练笔作品」，不是「馆内已有的作品」。原因很实际：
 * 1177 篇里绝大多数还没进馆，如果只认馆内作品，一键拆解第一次点下去就只会动 12 篇，
 * 剩下 1165 篇还得有人手工导 —— 那不是一键。
 *
 * 两条派发路径，按「这篇在不在馆里」分：
 *   · 不在馆 → source.ingest：worker 去官网抓正文、清洗、走导入服务入馆，入完自动接拆解；
 *   · 在馆   → analysis.run：直接排拆解（四条跳过规则复用 BatchDispatcher，不重写一遍）。
 *
 * 匿名作品**不进范围**，也不计进「还剩多少」：揭晓前官网连作者名都是「匿名用户」，
 * 导进来是一篇没有归属的作品，等揭晓后还得删掉重来。
 */
final class OneClick
{
    /** 一次最多派多少篇。1300 篇分几批推进，避免一次点错烧掉整月预算 */
    public const MAX_TARGETS = 500;

    public function __construct(
        private readonly PDO $pdo,
        private readonly SourceCatalog $catalog,
        private readonly AuthorActivity $activity,
        private readonly WorkRepository $works,
        private readonly RunBatchRepository $batches,
        private readonly BatchDispatcher $dispatcher,
        private readonly JobRepository $jobs,
        private readonly PaceEstimate $pace,
        private readonly AuditLogger $audit,
    ) {
    }

    // ------------------------------------------------------------------ 范围

    /**
     * 把界面上的表单（字符串、空值、多选）收敛成一份稳定的范围条件。
     *
     * 所有入口（页面、CLI、将来的自动化）都走这里，避免「页面勾了三期、
     * 命令行只认第一期」这种静默偏差。
     *
     * @param array<string,mixed> $raw
     * @return array{issues:list<int>, author_state:string, min_words:int, max_words:int,
     *               keyword:string, stage:string, limit:int, force:bool}
     */
    public static function normalizeScope(array $raw): array
    {
        $issues = [];
        $rawIssues = $raw['issues'] ?? [];
        foreach (is_array($rawIssues) ? $rawIssues : [$rawIssues] as $no) {
            $no = (int) $no;
            if ($no > 0 && !in_array($no, $issues, true)) {
                $issues[] = $no;
            }
        }
        sort($issues);

        $state = (string) ($raw['author_state'] ?? 'all');
        if (!in_array($state, ['all', AuthorActivity::ACTIVE, AuthorActivity::ZOMBIE], true)) {
            $state = 'all';
        }

        $stage = (string) ($raw['stage'] ?? 'todo');
        if (!in_array($stage, ['todo', 'failed', 'pending_publish', 'all'], true)) {
            $stage = 'todo';
        }

        $min = max(0, (int) ($raw['min_words'] ?? 0));
        $max = max(0, (int) ($raw['max_words'] ?? 0));
        if ($max > 0 && $min > $max) {
            [$min, $max] = [$max, $min];
        }

        return [
            'issues'       => $issues,
            'author_state' => $state,
            'min_words'    => $min,
            'max_words'    => $max,
            'keyword'      => mb_substr(trim((string) ($raw['keyword'] ?? '')), 0, 60),
            'stage'        => $stage,
            'limit'        => max(1, min(self::MAX_TARGETS, (int) ($raw['limit'] ?? self::MAX_TARGETS))),
            'force'        => !empty($raw['force']),
        ];
    }

    /**
     * 算出「这一范围里有哪些篇、各自什么状态」。
     *
     * @param array<string,mixed> $scope 已 normalize 的范围
     * @return array{
     *   targets:list<array{cid:int, work_id:int, issue_no:int, title:string, words:int, state:string}>,
     *   counts:array<string,int>,
     *   scope_total:int,
     *   excluded:array<string,int>,
     *   pace:array<string,mixed>,
     *   has_catalog:bool,
     *   has_activity:bool,
     *   truncated:bool
     * }
     */
    public function resolve(array $scope): array
    {
        $catalog = $this->catalog->load();
        $states  = $this->activity->states();
        $workMap = $this->works->sourceCidMap();
        $failed  = $this->failedWorkIds();
        $queued  = $this->queuedCids();
        $running = $this->queuedWorkIds();

        $excluded = ['anonymous' => 0, 'out_of_scope' => 0, 'published' => 0, 'in_queue' => 0, 'no_text' => 0, 'ignored' => 0];
        $universe = [];

        foreach (($catalog['items'] ?? []) as $item) {
            if (!$this->catalog->isPractice($item) || !SourceCatalog::available($item)) {
                continue;
            }

            $name = (string) ($item['authorName'] ?? '');
            if (in_array($name, AuthorActivity::ANON_NAMES, true) || (int) ($item['authorUid'] ?? 0) <= 0) {
                ++$excluded['anonymous'];
                continue;
            }

            if (!$this->inScope($item, $scope, $states)) {
                ++$excluded['out_of_scope'];
                continue;
            }

            $cid = (int) $item['cid'];

            // 已经在抓取队列里的不再算一遍。少了这一步，页面会把「刚派出去的 500 篇」
            // 继续显示成「待入馆 500 篇」，再点一次就重复排 500 条抓取 ——
            // 抓取本身是幂等的（入过馆的直接跳过），但队列会被灌成两倍长，
            // 进度看着像卡住，而且两次的批次对不上。
            if (isset($queued[$cid])) {
                ++$excluded['in_queue'];
                continue;
            }

            $work  = $workMap[$cid] ?? null;

            // 已经排进拆解队列的也跳过：它们入馆了、正在等 worker，
            // 再派一次只会让「本次会派出」这个数字虚高，而实际一条都不会新增。
            if ($work !== null && isset($running[(int) $work['id']])) {
                ++$excluded['in_queue'];
                continue;
            }

            $state = $work === null ? 'missing' : self::stateOf($work['status']);

            $universe[] = [
                'cid'      => $cid,
                'work_id'  => $work['id'] ?? 0,
                'issue_no' => (int) ($item['issueNo'] ?? 0),
                'title'    => (string) ($item['title'] ?? ''),
                'words'    => (int) ($item['wordCount'] ?? 0),
                'state'    => $state,
                'failed'   => $work !== null && isset($failed[$work['id']]),
            ];
        }

        // 手工导入的作品（没有官网 cid）：只在「不限来源」时参与，且不参与作者类型筛选
        // —— 它们是上传的文件，对不上官网 uid，硬套活跃度只会筛出一批随机结果。
        foreach ($this->libraryOnlyWorks($scope) as $row) {
            if (isset($running[(int) $row['id']])) {
                ++$excluded['in_queue'];
                continue;
            }
            $universe[] = [
                'cid'      => 0,
                'work_id'  => (int) $row['id'],
                'issue_no' => (int) $row['no'],
                'title'    => (string) $row['title'],
                'words'    => (int) $row['word_count'],
                'state'    => self::stateOf((string) $row['status']),
                'failed'   => isset($failed[(int) $row['id']]),
            ];
        }

        // 运营确认「拉不到正文」并忽略过的条目不再派。
        // 少了这一步，下次一键拆解会把它重新排进去、再失败一次，红点复发 ——
        // 忽略动作就等于白做了。
        $ignored = $this->dismissedCids();
        if ($ignored !== []) {
            $universe = array_values(array_filter(
                $universe,
                static function (array $row) use ($ignored, &$excluded): bool {
                    if ($row['cid'] > 0 && isset($ignored[$row['cid']])) {
                        ++$excluded['ignored'];

                        return false;
                    }

                    return true;
                }
            ));
        }

        // 期次早的排前面：先把老作品拆完，读者在「按期次浏览」里才不会觉得馆里缺了一块
        usort($universe, static fn (array $a, array $b): int => [$a['issue_no'], $a['cid'], $a['work_id']] <=> [$b['issue_no'], $b['cid'], $b['work_id']]);

        $scopeTotal = count($universe);
        $counts     = ['missing' => 0, 'imported' => 0, 'analyzing' => 0, 'analyzed' => 0, 'published' => 0, 'archived' => 0, 'failed' => 0];
        foreach ($universe as $row) {
            ++$counts[$row['state']];
            if ($row['failed']) {
                ++$counts['failed'];
            }
        }

        $selected = [];
        foreach ($universe as $row) {
            $state = $row['state'];
            if ($state === 'published') {
                ++$excluded['published'];
                if (!$scope['force']) {
                    continue;
                }
            }
            if ($state === 'analyzing') {
                ++$excluded['in_queue'];
                continue;
            }
            if ($state === 'archived') {
                continue;
            }

            $keep = match ($scope['stage']) {
                'todo'            => in_array($state, ['missing', 'imported'], true),
                'failed'          => $row['failed'],
                'pending_publish' => $state === 'analyzed',
                'all'             => true,
                default           => false,
            };
            if (!$keep) {
                continue;
            }

            $selected[] = $row;
        }

        $truncated = count($selected) > $scope['limit'];
        if ($truncated) {
            $selected = array_slice($selected, 0, $scope['limit']);
        }

        $ingest  = 0;
        $analyze = 0;
        $publish = 0;
        foreach ($selected as $row) {
            if ($row['state'] === 'missing') {
                ++$ingest;
            } elseif ($row['state'] === 'analyzed') {
                ++$publish;
            } else {
                ++$analyze;
            }
        }

        return [
            'targets'      => $selected,
            'counts'       => $counts,
            'scope_total'  => $scopeTotal,
            'excluded'     => $excluded,
            'pace'         => $this->pace->forWorks($ingest + $analyze),
            'has_catalog'  => $catalog !== null,
            'has_activity' => $states !== [],
            'truncated'    => $truncated,
            'plan'         => ['ingest' => $ingest, 'analyze' => $analyze, 'publish' => $publish],
        ];
    }

    // ------------------------------------------------------------------ 启动

    /**
     * 建批次并派活。
     *
     * @param array<string,mixed> $scope   已 normalize
     * @param array<string,mixed> $options auto_publish / note
     * @return array{batch_code:string, planned:int, ingest:int, queued:int, skipped:int, reasons:array<string,int>}
     */
    public function launch(array $scope, array $options, ?int $actorId): array
    {
        $plan    = $this->resolve($scope);
        $targets = $plan['targets'];

        if ($targets === []) {
            throw new RuntimeException('这个范围里没有可拆解的作品。换个期次或状态试试。');
        }

        $autoPublish = !empty($options['auto_publish']);
        $keepHidden  = !empty($options['keep_hidden']);
        $code        = Ulid::generate();
        $batchId     = $this->batches->create(
            $code,
            $scope,
            ['auto_publish' => $autoPublish, 'keep_hidden' => $keepHidden, 'force' => $scope['force']],
            count($targets),
            $plan['plan']['ingest'],
            $actorId,
            isset($options['note']) ? (string) $options['note'] : null
        );

        $queued = 0;
        $runIds = [];

        foreach ($targets as $row) {
            if ($row['cid'] <= 0) {
                // 手工导入的作品没有 cid，只能走拆解
                $runIds[] = $row['work_id'];
                continue;
            }

            if ($row['state'] !== 'missing') {
                $runIds[] = $row['work_id'];
                continue;
            }

            $this->jobs->enqueue('source.ingest', [
                'cid'          => $row['cid'],
                'issue_no'     => $row['issue_no'],
                'auto_publish' => $autoPublish,
                'keep_hidden'  => $keepHidden,
                'created_by'   => $actorId,
                'batch_code'   => $code,
            ], 8, 0, 3, $batchId);
            ++$queued;
        }

        $result = ['queued' => 0, 'skipped' => 0, 'reasons' => []];
        if ($runIds !== []) {
            // 复用 BatchDispatcher：它在队列里、正在分析、已发布、没有原文版本这四种情况下
            // 会跳过并给出理由。这里再写一遍判断，两边迟早对「已发布要不要重跑」给出不同答案。
            $result = $this->dispatcher->enqueue($runIds, $actorId, $scope['force'], 8, $batchId, $autoPublish, $keepHidden);
            $queued += $result['queued'];
        }

        $this->audit->log($actorId, 'pipeline.oneclick', 'run_batch', $code, null, [
            'scope'   => $scope,
            'options' => ['auto_publish' => $autoPublish, 'keep_hidden' => $keepHidden],
            'planned' => count($targets),
            'queued'  => $queued,
            'skipped' => $result['skipped'],
        ]);

        return [
            'batch_code' => $code,
            'planned'    => count($targets),
            'ingest'     => $plan['plan']['ingest'],
            'queued'     => $queued,
            'skipped'    => $result['skipped'],
            'reasons'    => $result['reasons'],
        ];
    }

    // ------------------------------------------------------------------ 内部

    /** @param array<string,mixed> $item */
    private function inScope(array $item, array $scope, array $states): bool
    {
        if ($scope['issues'] !== [] && !in_array((int) ($item['issueNo'] ?? 0), $scope['issues'], true)) {
            return false;
        }

        $words = (int) ($item['wordCount'] ?? 0);
        if ($scope['min_words'] > 0 && $words < $scope['min_words']) {
            return false;
        }
        if ($scope['max_words'] > 0 && $words > $scope['max_words']) {
            return false;
        }

        if ($scope['keyword'] !== '' && mb_stripos((string) ($item['title'] ?? ''), $scope['keyword']) === false) {
            return false;
        }

        if ($scope['author_state'] !== 'all') {
            $state = $states[(int) ($item['authorUid'] ?? 0)] ?? '';
            if ($state !== $scope['author_state']) {
                return false;
            }
        }

        return true;
    }

    private static function stateOf(string $status): string
    {
        return match ($status) {
            'draft', 'imported' => 'imported',
            'analyzing'         => 'analyzing',
            'analyzed'          => 'analyzed',
            'published'         => 'published',
            'archived'          => 'archived',
            default             => 'imported',
        };
    }

    /**
     * 已经在抓取队列里的 cid。
     *
     * MySQL 5.6 没有 JSON 函数，只能把 payload 取回来在 PHP 里解。条数是几千级，
     * 一次全取没问题；分页反而会让「队列中的那篇」在页与页之间漏掉。
     *
     * @return array<int,true>
     */
    private function queuedCids(): array
    {
        $rows = $this->pdo->query(
            "SELECT payload_json FROM hq_job
              WHERE type = 'source.ingest' AND status IN ('queued', 'running')"
        )->fetchAll();

        $map = [];
        foreach ($rows as $row) {
            $payload = json_decode((string) $row['payload_json'], true);
            $cid     = is_array($payload) ? (int) ($payload['cid'] ?? 0) : 0;
            if ($cid > 0) {
                $map[$cid] = true;
            }
        }

        return $map;
    }

    /**
     * 被忽略过的入馆失败 cid（status=canceled 且 payload 里 dismissed=true）。
     *
     * 与 queuedCids 一样，MySQL 5.6 没有 JSON 函数，取回 payload 在 PHP 里解。
     *
     * @return array<int,true>
     */
    private function dismissedCids(): array
    {
        $rows = $this->pdo->query(
            "SELECT payload_json FROM hq_job
              WHERE type = 'source.ingest' AND status = 'canceled'"
        )->fetchAll();

        $map = [];
        foreach ($rows as $row) {
            $payload = json_decode((string) $row['payload_json'], true);
            if (!is_array($payload) || empty($payload['dismissed'])) {
                continue;
            }
            $cid = (int) ($payload['cid'] ?? 0);
            if ($cid > 0) {
                $map[$cid] = true;
            }
        }

        return $map;
    }

    /**
     * 已经排进拆解队列的 work id（含正在跑的）。
     *
     * @return array<int,true>
     */
    private function queuedWorkIds(): array
    {
        $rows = $this->pdo->query(
            "SELECT DISTINCT work_id FROM hq_job
              WHERE type = 'analysis.run' AND status IN ('queued', 'running') AND work_id IS NOT NULL"
        )->fetchAll();

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['work_id']] = true;
        }

        return $map;
    }

    /** @return array<int,true> */
    private function failedWorkIds(): array
    {
        $rows = $this->pdo->query(
            "SELECT DISTINCT work_id FROM hq_job
              WHERE type = 'analysis.run' AND status = 'failed' AND work_id IS NOT NULL"
        )->fetchAll();

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['work_id']] = true;
        }

        return $map;
    }

    /**
     * 馆内手工导入的作品（没有官网 cid）。
     *
     * 按作者类型筛选时**一律不进范围**：这些作品没有官网 uid，判不出作者是
     * 活跃还是僵尸。留着它们不是「不参与筛选」，而是「筛选对它们失效」——
     * 勾了「只拆活跃作者」，结果库里几张上传的稿子也被拆了，这个筛选项就不可信了。
     *
     * @param array<string,mixed> $scope
     * @return list<array<string,mixed>>
     */
    private function libraryOnlyWorks(array $scope): array
    {
        if ($scope['author_state'] !== 'all') {
            return [];
        }

        $where  = ["w.deleted_at IS NULL", 'w.source_cid IS NULL'];
        $params = [];

        if ($scope['issues'] !== []) {
            $in = implode(',', array_fill(0, count($scope['issues']), '?'));
            $where[]      = 'i.no IN (' . $in . ')';
            $params       = array_merge($params, $scope['issues']);
        }
        if ($scope['min_words'] > 0) {
            $where[]  = 'w.word_count >= ?';
            $params[] = $scope['min_words'];
        }
        if ($scope['max_words'] > 0) {
            $where[]  = 'w.word_count <= ?';
            $params[] = $scope['max_words'];
        }
        if ($scope['keyword'] !== '') {
            $where[]  = 'w.title LIKE ?';
            $params[] = '%' . str_replace(['%', '_'], ['\%', '\_'], $scope['keyword']) . '%';
        }

        $stmt = $this->pdo->prepare(
            'SELECT w.id, w.title, w.word_count, w.status, i.no
               FROM hq_work w
               LEFT JOIN hq_issue i ON i.id = w.issue_id
              WHERE ' . implode(' AND ', $where) . '
              ORDER BY w.id'
        );
        $stmt->execute($params);

        return $stmt->fetchAll();
    }
}
