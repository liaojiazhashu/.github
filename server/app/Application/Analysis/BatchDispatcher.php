<?php

declare(strict_types=1);

namespace App\Application\Analysis;

use App\Infrastructure\Pdo\JobRepository;
use PDO;

/**
 * 批量派发：把一批作品放进分析队列。
 *
 * 后台的「开始拆解」按钮与命令行的 `analysis:batch --enqueue` 都走这里。
 * 之前这套判断只写在 console 里，后台按钮如果要复用就得抄一遍 —— 而抄一遍的下场是
 * 两边对「已发布的作品要不要重跑」给出不同答案，这种事在 1300 篇的批跑里会很难收拾。
 *
 * 四条跳过规则（都是为了不花冤枉钱）：
 *   1. 已经在队列里（queued / running）—— 重复入队会跑两遍、付两份钱；
 *   2. 正在分析中（status=analyzing）—— 队列里那条正在动它；
 *   3. 已经发布（published）—— 除非显式 force，否则不动已上架的成果；
 *   4. 没有原文版本 —— 跑不了，入队只会在 worker 里失败一次再退出来。
 *
 * 返回里带着「为什么跳过」，因为运营在点完按钮之后最想知道的正是这个。
 */
final class BatchDispatcher
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly JobRepository $jobs,
    ) {
    }

    /**
     * @param list<int> $workIds
     * @return array{queued:int, skipped:int, reasons:array<string,int>}
     */
    public function enqueue(
        array $workIds,
        ?int $actorId,
        bool $force = false,
        int $priority = 8,
        ?int $batchId = null,
        bool $autoPublish = false,
        bool $keepHidden = false,
    ): array
    {
        $workIds = array_values(array_unique(array_filter(
            array_map('intval', $workIds),
            static fn (int $id): bool => $id > 0
        )));

        $result = ['queued' => 0, 'skipped' => 0, 'reasons' => []];

        if ($workIds === []) {
            return $result;
        }

        $works = $this->loadWorks($workIds);
        $busy  = $this->busyWorkIds($workIds);

        foreach ($works as $work) {
            $id = (int) $work['id'];

            if (isset($busy[$id])) {
                $result['skipped']++;
                $result['reasons']['已在队列中'] = ($result['reasons']['已在队列中'] ?? 0) + 1;
                continue;
            }

            if ((string) $work['status'] === 'analyzing') {
                $result['skipped']++;
                $result['reasons']['正在分析'] = ($result['reasons']['正在分析'] ?? 0) + 1;
                continue;
            }

            if ((string) $work['status'] === 'published' && !$force) {
                $result['skipped']++;
                $result['reasons']['已发布（需勾选重跑）'] = ($result['reasons']['已发布（需勾选重跑）'] ?? 0) + 1;
                continue;
            }

            if ((int) $work['version_count'] === 0) {
                $result['skipped']++;
                $result['reasons']['没有原文版本'] = ($result['reasons']['没有原文版本'] ?? 0) + 1;
                continue;
            }

            $this->jobs->enqueue('analysis.run', [
                'work_id'      => $id,
                'work_code'    => (string) $work['code'],
                'force'        => $force,
                'created_by'   => $actorId,
                // 一键拆解的「拆完自动上架」要一路带到 worker，
                // 在派发这一层丢掉它，页面上勾了也不会生效——而且不会报错。
                'auto_publish' => $autoPublish,
                // 「拆完不公开」同理：拆完把作品置为 hidden，只在后台留结果。
                // 少了这一行的后果更隐蔽——作品停在 analyzed 时前台确实看不见，
                // 但只要以后有人点一次上架，它就公开了，没有任何地方记得「这批本来不该公开」。
                'keep_hidden' => $keepHidden,
            ], $priority, 0, 3, $batchId);

            $result['queued']++;
        }

        // 传进来的 id 里有一部分已经不存在（被删或删错）—— 也要如实计入，不能静默丢弃
        $missing = count($workIds) - count($works);
        if ($missing > 0) {
            $result['skipped'] += $missing;
            $result['reasons']['作品不存在'] = $missing;
        }

        return $result;
    }

    /**
     * @param list<int> $workIds
     * @return list<array{id:int|string, code:string, status:string, version_count:int|string}>
     */
    private function loadWorks(array $workIds): array
    {
        $rows = [];
        foreach (array_chunk($workIds, 500) as $batch) {
            $in   = implode(',', array_fill(0, count($batch), '?'));
            $stmt = $this->pdo->prepare(
                "SELECT w.id, w.code, w.status,
                        (SELECT COUNT(*) FROM hq_work_version v WHERE v.work_id = w.id) AS version_count
                   FROM hq_work w
                  WHERE w.id IN ({$in}) AND w.deleted_at IS NULL"
            );
            $stmt->execute($batch);
            foreach ($stmt->fetchAll() as $row) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * 这批作品里，哪些已经在队列里。
     *
     * @param list<int> $workIds
     * @return array<int,true>
     */
    private function busyWorkIds(array $workIds): array
    {
        $busy = [];
        foreach (array_chunk($workIds, 500) as $batch) {
            $in   = implode(',', array_fill(0, count($batch), '?'));
            $stmt = $this->pdo->prepare(
                "SELECT DISTINCT work_id FROM hq_job
                  WHERE type = 'analysis.run' AND status IN ('queued','running')
                    AND work_id IN ({$in})"
            );
            $stmt->execute($batch);
            foreach ($stmt->fetchAll() as $row) {
                $busy[(int) $row['work_id']] = true;
            }
        }

        return $busy;
    }
}
