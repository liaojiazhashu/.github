<?php

declare(strict_types=1);

namespace App\Application\Analysis;

use App\Infrastructure\Pdo\SettingRepository;
use App\Support\Config;
use PDO;

/**
 * 拆解实况快照：一次采集，给「实况」页的 HTML 首屏和 JSON 轮询共用。
 *
 * 为什么要单独一个类，而不是写在控制器里：
 *   页面首屏与 5 秒一次的轮询必须给出**同构**的数字。两处各写一套 SQL 的下场是
 *   「刷新前显示 871、刷新后跳成 874」这种自相矛盾的画面，而这种矛盾在长时间批跑里
 *   会让人怀疑是不是有任务丢了。所以只有一个来源。
 *
 * 三条口径，跟库里其它地方保持一致，别在这里另立一套：
 *   1. **进度**只数 hq_work：已拆解 = published + analyzed（analyzed 即「拆完但按
 *      作者活跃度只留后台」），待拆 = imported，处理中 = analyzing。
 *   2. **单价**只取「当前模型」最近 30 篇的实测。整个 hq_analysis 的历史里混着
 *      零成本的代拆批次（model=wb-v4.1-flash），把它们算进均价会让「还剩 800 篇要
 *      多少钱」直接掉到实际值的十分之一 —— 那种估算比不给更危险。
 *   3. **速率**用最近 15 分钟真实完成篇数除时间窗口，而不是「并发数 ÷ 单篇耗时」
 *      推导。推导出来的是理论值，实测的是当下值，停机、限流、死锁都能从实测值里看出来。
 */
final class ProgressSnapshot
{
    /** 速率窗口：分钟。太短会被单篇的抖动放大，太长则对停机不敏感 */
    private const RATE_WINDOW_MINUTES = 15;

    /** 单价样本数：取当前模型最近 N 篇。30 篇足以抹平长短篇差异 */
    private const SAMPLE_SIZE = 30;

    /** 吞吐曲线：回看分钟数与分桶宽度（60 / 5 = 12 根柱子） */
    private const SERIES_MINUTES = 60;

    private const SERIES_BUCKET_MINUTES = 5;

    public function __construct(
        private readonly PDO $pdo,
        private readonly Config $config,
        private readonly SettingRepository $settings,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(): array
    {
        $model  = $this->config->string('ai.model.default', 'deepseek-flash');
        $budget = (float) $this->config->get('ai.budget.daily_yuan', 100.0);

        $progress = $this->progress();
        $queue    = $this->queueCounts();
        $pace     = $this->pace($model);

        $rate   = $this->rate($pace, (int) $queue['running']);
        $eta    = $this->eta($progress['remaining'], $rate);
        $today  = $this->todaySpend();
        $avgYuan = $pace['yuan'];

        $projected       = round($avgYuan * $progress['remaining'], 2);
        $projectedTotal  = round($today['yuan'] + $projected, 2);
        $budgetLeft      = round($budget - $today['yuan'], 2);

        $paused = $this->settings->bool('analysis.paused', false);

        // 柱状图没有纵轴刻度，光看高低只能说「有快有慢」，说不出「一格是多少篇」。
        // 把峰值和合计一并给出去，标题行就能写死参照系。
        $series      = $this->series();
        $seriesDone  = array_column($series, 'done');

        $snapshot = [
            'at'       => date('Y-m-d H:i:s'),
            'at_hms'   => date('H:i:s'),
            'model'    => $model,
            'paused'   => $paused,
            'progress' => $progress + [
                'percent' => $progress['total'] > 0
                    ? round($progress['done'] / $progress['total'] * 100, 1)
                    : 0.0,
                // 分段条用的三个百分比，加起来正好 100（除不尽时由「待拆」吃掉误差）
                'published_pct' => $progress['total'] > 0 ? $progress['published'] / $progress['total'] * 100 : 0.0,
                'analyzed_pct'  => $progress['total'] > 0 ? $progress['analyzed'] / $progress['total'] * 100 : 0.0,
                'analyzing_pct' => $progress['total'] > 0 ? $progress['analyzing'] / $progress['total'] * 100 : 0.0,
            ],
            'pace'  => $pace + [
                'rate_per_minute' => $rate['per_minute'],
                'rate_source'     => $rate['source'],
                'window'          => $rate['window'],
                'measured'        => $rate['measured'],
            ],
            'queue' => $queue,
            'eta'   => $eta,
            'spend' => [
                'today_yuan'          => $today['yuan'],
                'today_calls'         => $today['calls'],
                'budget_yuan'         => $budget,
                'budget_left_yuan'    => $budgetLeft,
                'budget_used_pct'     => $budget > 0 ? round($today['yuan'] / $budget * 100, 1) : 0.0,
                'avg_yuan'            => $avgYuan,
                'projected_yuan'      => $projected,
                'projected_total_yuan' => $projectedTotal,
                'over_budget'         => $projectedTotal > $budget,
            ],
            'workers'   => $this->workers(),
            'series'    => $series,
            'series_peak'  => $seriesDone === [] ? 0 : (int) max($seriesDone),
            'series_total' => (int) array_sum($seriesDone),
            'recent'    => $this->recent(),
            'last_call_ago' => $this->lastCallAgo(),
        ];

        $snapshot['warnings'] = $this->warnings($snapshot);

        return $snapshot;
    }

    /**
     * 作品进度。只用 hq_work 一张表 —— 分析的可见性不影响「这篇拆没拆过」。
     *
     * @return array{total:int, published:int, analyzed:int, analyzing:int, imported:int, archived:int, done:int, remaining:int}
     */
    private function progress(): array
    {
        $row = $this->pdo->query(
            "SELECT COUNT(*) AS total,
                    COALESCE(SUM(status = 'published'), 0) AS published,
                    COALESCE(SUM(status = 'analyzed'), 0)  AS analyzed,
                    COALESCE(SUM(status = 'analyzing'), 0) AS analyzing,
                    COALESCE(SUM(status = 'imported'), 0)  AS imported,
                    COALESCE(SUM(status = 'archived'), 0)  AS archived
               FROM hq_work
              WHERE deleted_at IS NULL"
        )->fetch() ?: [];

        $published = (int) ($row['published'] ?? 0);
        $analyzed  = (int) ($row['analyzed'] ?? 0);
        $analyzing = (int) ($row['analyzing'] ?? 0);
        $imported  = (int) ($row['imported'] ?? 0);
        $archived  = (int) ($row['archived'] ?? 0);
        $total     = (int) ($row['total'] ?? 0);

        return [
            'total'     => $total,
            'published' => $published,
            'analyzed'  => $analyzed,
            'analyzing' => $analyzing,
            'imported'  => $imported,
            'archived'  => $archived,
            'done'      => $published + $analyzed,
            'remaining' => $imported + $analyzing,
        ];
    }

    /**
     * @return array{queued:int, running:int, failed:int, succeeded:int, canceled:int}
     */
    private function queueCounts(): array
    {
        $out = ['queued' => 0, 'running' => 0, 'failed' => 0, 'succeeded' => 0, 'canceled' => 0];

        $rows = $this->pdo->query(
            "SELECT status, COUNT(*) AS n FROM hq_job WHERE type = 'analysis.run' GROUP BY status"
        )->fetchAll();

        foreach ($rows as $r) {
            $key = (string) $r['status'];
            if (isset($out[$key])) {
                $out[$key] = (int) $r['n'];
            }
        }

        return $out;
    }

    /**
     * 当前模型的实测单价与单篇耗时。样本不足时退回全库历史，并标记 estimated。
     *
     * @return array{yuan:float, secs:int, samples:int, estimated:bool}
     */
    private function pace(string $model): array
    {
        $sql = "SELECT COUNT(*) AS n,
                       COALESCE(SUM(cost_micro), 0) AS cost,
                       COALESCE(AVG(TIMESTAMPDIFF(SECOND, started_at, finished_at)), 0) AS secs
                  FROM (SELECT cost_micro, started_at, finished_at
                          FROM hq_analysis
                         WHERE status IN ('succeeded','published')
                           AND started_at IS NOT NULL AND finished_at IS NOT NULL
                           %s
                         ORDER BY finished_at DESC
                         LIMIT " . self::SAMPLE_SIZE . ") t";

        $stmt = $this->pdo->prepare(sprintf($sql, 'AND model = ?'));
        $stmt->execute([$model]);
        $row = $stmt->fetch() ?: [];

        $n = (int) ($row['n'] ?? 0);

        if ($n === 0) {
            // 换模型后的头几篇：用全库历史兜底，但要标出来是估的
            $row = $this->pdo->query(sprintf($sql, ''))->fetch() ?: [];
            $n   = (int) ($row['n'] ?? 0);
        }

        if ($n === 0) {
            return ['yuan' => 0.0, 'secs' => 0, 'samples' => 0, 'estimated' => true];
        }

        return [
            'yuan'      => round(((int) $row['cost'] / 1e6) / $n, 3),
            'secs'      => (int) round((float) $row['secs']),
            'samples'   => $n,
            'estimated' => $n < self::SAMPLE_SIZE,
        ];
    }

    /**
     * 速率：最近 15 分钟真实跑完几篇。
     *
     * 一条没跑完不代表停机 —— 可能只是第一篇正跑到一半，所以这时退回
     * 「并发数 ÷ 单篇耗时」，但把 source 标成 derived，界面上要能看出区别。
     *
     * @param array{yuan:float, secs:int, samples:int, estimated:bool} $pace
     * @return array{per_minute:float, source:string, measured:int, window:int}
     */
    private function rate(array $pace, int $running): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM hq_analysis
              WHERE status IN ('succeeded','published')
                AND finished_at >= NOW() - INTERVAL " . self::RATE_WINDOW_MINUTES . " MINUTE"
        );
        $stmt->execute();
        $measured = (int) $stmt->fetchColumn();

        if ($measured > 0) {
            return [
                'per_minute' => round($measured / self::RATE_WINDOW_MINUTES, 3),
                'source'     => 'measured',
                'measured'   => $measured,
                'window'     => self::RATE_WINDOW_MINUTES,
            ];
        }

        if ($running > 0 && $pace['secs'] > 0) {
            return [
                'per_minute' => round($running * 60 / $pace['secs'], 3),
                'source'     => 'derived',
                'measured'   => 0,
                'window'     => self::RATE_WINDOW_MINUTES,
            ];
        }

        return ['per_minute' => 0.0, 'source' => 'idle', 'measured' => 0, 'window' => self::RATE_WINDOW_MINUTES];
    }

    /**
     * @param array{per_minute:float, source:string, measured:int, window:int} $rate
     * @return array{seconds:?int, label:string, finish_at:?string, finish_day:?string, stalled:bool}
     */
    private function eta(int $remaining, array $rate): array
    {
        if ($remaining <= 0) {
            return ['seconds' => 0, 'label' => '已完成', 'finish_at' => null, 'finish_day' => null, 'stalled' => false];
        }

        if ($rate['per_minute'] <= 0) {
            return ['seconds' => null, 'label' => '停滞', 'finish_at' => null, 'finish_day' => null, 'stalled' => true];
        }

        $seconds = (int) round($remaining / $rate['per_minute'] * 60);
        $finish  = time() + $seconds;

        $day = '今天';
        if (date('Y-m-d', $finish) !== date('Y-m-d')) {
            $day = date('Y-m-d', $finish) === date('Y-m-d', strtotime('+1 day')) ? '次日' : date('m-d', $finish);
        }

        return [
            'seconds'   => $seconds,
            'label'     => $this->durationLabel($seconds),
            'finish_at' => date('H:i', $finish),
            'finish_day' => $day,
            'stalled'   => false,
        ];
    }

    /**
     * @return array{yuan:float, calls:int}
     */
    private function todaySpend(): array
    {
        $row = $this->pdo->query(
            "SELECT COUNT(*) AS calls, COALESCE(SUM(cost_micro), 0) AS cost
               FROM hq_ai_call
              WHERE created_at >= CURDATE()"
        )->fetch() ?: [];

        return [
            'yuan'  => round((int) ($row['cost'] ?? 0) / 1e6, 2),
            'calls' => (int) ($row['calls'] ?? 0),
        ];
    }

    /**
     * 活跃 worker。locked_at 是领任务那一刻，不是心跳 —— 单篇要跑一两分钟，
     * 所以这里用「距上次活动多少秒」表达，超过 5 分钟就当作没有心跳。
     *
     * @return list<array{name:string, jobs:int, idle_seconds:?int, alive:bool}>
     */
    private function workers(): array
    {
        $rows = $this->pdo->query(
            "SELECT locked_by AS name, COUNT(*) AS jobs,
                    TIMESTAMPDIFF(SECOND, MAX(updated_at), NOW()) AS idle
               FROM hq_job
              WHERE status = 'running' AND locked_by IS NOT NULL
              GROUP BY locked_by
              ORDER BY idle ASC"
        )->fetchAll();

        return array_map(static function (array $r): array {
            $idle = (int) ($r['idle'] ?? 0);

            return [
                // locked_by 形如 "w1:ab12cd34"（worker 名 + 一次性令牌），只留名字
                'name'         => explode(':', (string) $r['name'])[0],
                'jobs'         => (int) $r['jobs'],
                'idle_seconds' => $idle,
                'alive'        => $idle < 300,
            ];
        }, $rows);
    }

    /**
     * 近 60 分钟吞吐，5 分钟一桶。空桶也要留着，否则曲线会把「停机」画成「一路平」
     *
     * @return list<array{label:string, done:int, yuan:float}>
     */
    private function series(): array
    {
        $rows = $this->pdo->query(
            "SELECT UNIX_TIMESTAMP(finished_at) AS ts, cost_micro
               FROM hq_analysis
              WHERE status IN ('succeeded','published')
                AND finished_at >= NOW() - INTERVAL " . self::SERIES_MINUTES . " MINUTE"
        )->fetchAll();

        $buckets = [];
        $now     = time();
        $step    = self::SERIES_BUCKET_MINUTES * 60;

        // 桶的右边界对齐到「当前时刻」，向左铺满 12 格
        for ($i = self::SERIES_MINUTES / self::SERIES_BUCKET_MINUTES - 1; $i >= 0; $i--) {
            $end   = $now - $i * $step;
            $start = $end - $step;
            $buckets[] = [
                'label' => date('H:i', $start),
                'done'  => 0,
                'yuan'  => 0.0,
            ];
        }

        $count = count($buckets);

        foreach ($rows as $r) {
            $ts     = (int) $r['ts'];
            $offset = (int) floor(($now - $ts) / $step);

            if ($offset < 0 || $offset >= $count) {
                continue;
            }

            $idx = $count - 1 - $offset;
            $buckets[$idx]['done']++;
            $buckets[$idx]['yuan'] = round($buckets[$idx]['yuan'] + (int) $r['cost_micro'] / 1e6, 2);
        }

        return $buckets;
    }

    /**
     * 最近完成的一批。
     *
     * @return list<array{code:string, title:string, yuan:float, secs:int, visibility:string, finished_at:string, model:string}>
     */
    private function recent(): array
    {
        $rows = $this->pdo->query(
            "SELECT w.code, w.title, a.cost_micro, a.visibility, a.finished_at, a.model,
                    TIMESTAMPDIFF(SECOND, a.started_at, a.finished_at) AS secs
               FROM hq_analysis a
               JOIN hq_work w ON w.id = a.work_id AND w.deleted_at IS NULL
              WHERE a.status IN ('succeeded','published')
                AND a.finished_at IS NOT NULL
              ORDER BY a.finished_at DESC
              LIMIT 8"
        )->fetchAll();

        return array_map(static fn (array $r): array => [
            'code'        => (string) $r['code'],
            'title'       => (string) $r['title'],
            'yuan'        => round((int) $r['cost_micro'] / 1e6, 3),
            'secs'        => max(0, (int) $r['secs']),
            'visibility'  => (string) $r['visibility'],
            'finished_at' => (string) $r['finished_at'],
            'model'       => (string) $r['model'],
        ], $rows);
    }

    private function lastCallAgo(): ?int
    {
        $last = $this->pdo->query("SELECT MAX(created_at) FROM hq_ai_call")->fetchColumn();

        if (!is_string($last) || $last === '') {
            return null;
        }

        return max(0, time() - (int) strtotime($last));
    }

    /**
     * 页面上要主动说出来的异常。空数组表示一切正常。
     *
     * @param array<string, mixed> $s
     * @return list<string>
     */
    private function warnings(array $s): array
    {
        $out = [];

        /** @var array<string,int> $q */
        $q = $s['queue'];

        if (!empty($s['paused'])) {
            $out[] = '拆解闸门已暂停：worker 不再领取新任务，正在跑的那一篇会跑完。';
        }

        if (!empty($s['eta']['stalled']) && (int) $s['progress']['remaining'] > 0) {
            $out[] = '队列里还有 ' . number_format((int) $s['progress']['remaining'])
                . ' 篇，但最近 ' . self::RATE_WINDOW_MINUTES . ' 分钟没有任何一篇跑完，也没有 worker 在跑 —— 任务会一直排着。';
        }

        if ($q['failed'] > 0) {
            $out[] = '有 ' . number_format($q['failed']) . ' 条任务失败，需要在调度台重试或查看报错。';
        }

        if ($q['queued'] > 0 && $q['running'] === 0 && empty($s['paused'])) {
            $out[] = '有 ' . number_format($q['queued']) . ' 条任务在排队，但没有 worker 正在执行。';
        }

        if (!empty($s['spend']['over_budget'])) {
            $out[] = '按当前速率，今日累计将达 ¥' . number_format((float) $s['spend']['projected_total_yuan'], 2)
                . '，超过日预算 ¥' . number_format((float) $s['spend']['budget_yuan'], 0) . '。';
        }

        return $out;
    }

    /** 秒 → 「9 小时 35 分」。不用「h/m」是因为运营看的是中文界面 */
    private function durationLabel(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds . ' 秒';
        }

        $minutes = (int) round($seconds / 60);

        if ($minutes < 60) {
            return $minutes . ' 分钟';
        }

        $hours = intdiv($minutes, 60);
        $rest  = $minutes % 60;

        return $rest > 0 ? $hours . ' 小时 ' . $rest . ' 分' : $hours . ' 小时';
    }
}
