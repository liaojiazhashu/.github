<?php

declare(strict_types=1);

namespace App\Http\Controller\Admin;

use App\Application\Analysis\BatchDispatcher;
use App\Infrastructure\Ai\CostModel;
use App\Infrastructure\Pdo\AiCallRepository;
use App\Infrastructure\Pdo\AuditLogger;
use App\Infrastructure\Pdo\JobRepository;
use App\Infrastructure\Pdo\SettingRepository;
use App\Infrastructure\Pdo\WorkRepository;
use App\Support\Config;
use App\Support\Session;
use App\View\View;
use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * 拆解调度台。
 *
 * 这个页面存在的唯一理由：1300 篇不是「点 1300 次按钮」能干完的活。
 * 运营在这里要回答四个问题，页面就按这四件事组织：
 *
 *   1. 现在到哪了？        —— 进度条 + 分状态计数
 *   2. 机器在动吗？        —— 队列计数 + 活跃 worker 心跳
 *   3. 要不要现在停？      —— 全局暂停（不改任何数据，只让 worker 不再领活）
 *   4. 下一步干什么？      —— 按筛选批量入队 / 重试失败 / 取消排队
 *
 * 关于「停」的两层：
 *   · 全局暂停 = 队列的闸门。恢复后排队中的任务照旧跑，一条不丢。这是发现提示词
 *     写错了、或者预算要超了的时候按的那个键 —— 它必须比杀进程更轻。
 *   · 取消单条 = 处理个别跑歪的。排队中的直接撤销；正在跑的只能置标记，
 *     由 worker 在阶段边界退出（模型请求发出去就收不回来了）。
 */
final class QueueController extends AdminController
{
    /** 一次最多入队多少篇。1300 篇按筛选分批推进，避免一次点错烧掉整月预算 */
    private const MAX_ENQUEUE = 500;

    public function __construct(
        View $view,
        Session $session,
        private readonly WorkRepository $works,
        private readonly JobRepository $jobs,
        private readonly BatchDispatcher $dispatcher,
        private readonly AiCallRepository $aiCalls,
        private readonly CostModel $cost,
        private readonly SettingRepository $settings,
        private readonly AuditLogger $audit,
        private readonly PDO $pdo,
        private readonly Config $config,
    ) {
        parent::__construct($view, $session);
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $stats = $this->works->adminStats();
        $today = $this->aiCalls->summary(date('Y-m-d 00:00:00'));

        return $this->render($response, 'admin/queue/index', [
            'nav'        => 'queue',
            'user'       => (array) $request->getAttribute('user', []),
            'stats'      => $stats,
            'queue'      => $this->jobs->counts(),
            'today'      => $today,
            'todayYuan'  => CostModel::toYuan((int) $today['cost_micro']),
            'budgetYuan' => (float) $this->config->get('ai.budget.daily_yuan', 100.0),
            'paused'     => $this->settings->bool('analysis.paused', false),
            'pauseNote'  => (string) $this->settings->get('analysis.paused_note', ''),
            'workers'    => $this->workers(),
            'recent'     => $this->recentJobs(),
            'failures'   => $this->failures(),
            'estimate'   => $this->estimate($stats),
            'maxEnqueue' => self::MAX_ENQUEUE,
            'issues'     => $this->works->issues(),
        ]);
    }

    /**
     * 批量入队（「开始拆解」）。
     *
     * 两种选法：
     *   · scope=filter —— 按当前筛选条件，服务端自己算出该动哪些（1300 篇的正路）
     *   · scope=ids    —— 列表里勾选的那几条
     * 无论哪种，最终都会再经过 BatchDispatcher 的四条跳过规则。
     */
    public function enqueue(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = (array) $request->getAttribute('user', []);
        $body = (array) ($request->getParsedBody() ?? []);

        $force = !empty($body['force']);
        $limit = max(1, min(self::MAX_ENQUEUE, (int) ($body['limit'] ?? 50)));

        $ids = $this->targetIds($body, $limit);

        if ($ids === []) {
            $this->session->flash('warn', '没有符合条件的作品，什么都没做。');

            return $this->redirect($response, '/admin/queue');
        }

        $result = $this->dispatcher->enqueue($ids, (int) ($user['id'] ?? 0) ?: null, $force);

        $this->audit->log(
            (int) ($user['id'] ?? 0) ?: null,
            'analysis.enqueue',
            'analysis.batch',
            null,
            null,
            [
                'requested' => count($ids),
                'queued'    => $result['queued'],
                'skipped'   => $result['skipped'],
                'force'     => $force,
                'reasons'   => $result['reasons'],
            ]
        );

        $this->session->flash('ok', sprintf('已入队 %d 篇。', $result['queued']));
        foreach ($result['reasons'] as $reason => $count) {
            $this->session->flash('warn', sprintf('跳过 %d 篇：%s', $count, $reason));
        }

        if ($result['queued'] > 0 && !$this->workers()) {
            $this->session->flash('warn', '当前没有正在运行的 worker，任务会一直排队。启动方式见 README「千篇批跑」。');
        }

        return $this->redirect($response, '/admin/queue');
    }

    /** 全局暂停 / 恢复。只改一个开关，不动任何任务与数据。 */
    public function pause(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = (array) $request->getAttribute('user', []);
        $body = (array) ($request->getParsedBody() ?? []);
        $on   = (string) ($body['state'] ?? 'pause') === 'pause';

        $this->settings->set('analysis.paused', $on ? '1' : '0');
        $this->settings->set('analysis.paused_note', $on ? mb_substr((string) ($body['note'] ?? ''), 0, 200) : '');

        $this->audit->log(
            (int) ($user['id'] ?? 0) ?: null,
            $on ? 'analysis.pause' : 'analysis.resume',
            'setting',
            'analysis.paused',
            ['paused' => !$on],
            ['paused' => $on, 'note' => (string) ($body['note'] ?? '')]
        );

        // 「暂停」不影响已经在跑的那一篇：它会跑完再停。这一点必须说清楚，
        // 否则运营会以为按下就立刻静止，然后误判还在烧钱。
        $this->session->flash(
            'ok',
            $on
                ? '已暂停领取。正在跑的那一篇会跑完；排队中的一条都不会丢。'
                : '已恢复，worker 会继续领取排队中的任务。'
        );

        return $this->redirect($response, '/admin/queue');
    }

    /** 取消全部排队中的任务（急停）。正在跑的不动 —— 它们需要走单条取消。 */
    public function cancelQueued(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user  = (array) $request->getAttribute('user', []);
        $count = $this->jobs->cancelAllQueued('analysis.run');

        $this->audit->log(
            (int) ($user['id'] ?? 0) ?: null,
            'analysis.cancel_all',
            'job',
            null,
            null,
            ['canceled' => $count]
        );

        $this->session->flash('ok', sprintf('已取消 %d 条排队中的任务。正在运行的不受影响。', $count));

        return $this->redirect($response, '/admin/queue');
    }

    /** 取消单条。排队中→立即撤销；运行中→置标记，阶段边界退出。 */
    public function cancelJob(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user  = (array) $request->getAttribute('user', []);
        $jobId = (int) ($args['id'] ?? 0);
        $state = $this->jobs->cancel($jobId);

        $this->audit->log(
            (int) ($user['id'] ?? 0) ?: null,
            'analysis.cancel',
            'job',
            (string) $jobId,
            null,
            ['result' => $state]
        );

        $this->session->flash('ok', match ($state) {
            'canceled'  => '已取消这条排队中的任务。',
            'requested' => '已请求停止。它会在当前阶段结束后退出，不会立刻中断。',
            default     => '这条任务已经结束了，无需操作。',
        });

        return $this->redirect($response, (string) ($request->getParsedBody()['back'] ?? '/admin/queue'));
    }

    /** 失败任务重新入队。 */
    public function retryJob(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user  = (array) $request->getAttribute('user', []);
        $jobId = (int) ($args['id'] ?? 0);
        $ok    = $this->jobs->retry($jobId);

        $this->audit->log(
            (int) ($user['id'] ?? 0) ?: null,
            'analysis.retry',
            'job',
            (string) $jobId,
            null,
            ['ok' => $ok]
        );

        $this->session->flash($ok ? 'ok' : 'warn', $ok ? '已重新入队。' : '这条任务不是失败状态，无需重试。');

        return $this->redirect($response, (string) ($request->getParsedBody()['back'] ?? '/admin/queue'));
    }

    /**
     * 忽略一条入馆失败。
     *
     * 这类失败的共同点是「再重试也一样」：源站正文根本拉不到，或字数低于拆解下限。
     * 它没有作品可点、没有正文可拆，留在失败列表里只会让红点永远消不掉。
     *
     * 置 canceled 而不是删掉：任务历史要留档；同时往 payload 写 dismissed，
     * 让一键拆解把这一篇排除掉 —— 否则下次跑一遍又失败一次，红点复发。
     */
    public function dismissJob(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user  = (array) $request->getAttribute('user', []);
        $jobId = (int) ($args['id'] ?? 0);
        $back  = (string) ($request->getParsedBody()['back'] ?? '/admin/queue#failures');

        $stmt = $this->pdo->prepare(
            "SELECT id, type, status, payload_json FROM hq_job WHERE id = :id"
        );
        $stmt->execute([':id' => $jobId]);
        $job = $stmt->fetch() ?: null;

        if ($job === null || (string) $job['type'] !== 'source.ingest' || (string) $job['status'] !== 'failed') {
            // 静默成功最糟：运营以为忽略掉了，红点却还在
            $this->session->flash('warn', '这条任务不是可忽略的入馆失败。');

            return $this->redirect($response, $back);
        }

        $payload = json_decode((string) ($job['payload_json'] ?? ''), true);
        $payload = is_array($payload) ? $payload : [];
        $payload['dismissed'] = true;
        $cid     = (int) ($payload['cid'] ?? 0);
        $issueNo = (int) ($payload['issue_no'] ?? 0);

        // MySQL 5.6 没有 JSON_SET，只能整串写回
        $stmt = $this->pdo->prepare(
            "UPDATE hq_job
                SET status = 'canceled', payload_json = :payload, locked_by = NULL, locked_at = NULL, updated_at = NOW()
              WHERE id = :id AND type = 'source.ingest' AND status = 'failed'"
        );
        $stmt->execute([
            ':payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':id'      => $jobId,
        ]);

        $this->audit->log(
            (int) ($user['id'] ?? 0) ?: null,
            'analysis.dismiss',
            'job',
            (string) $jobId,
            null,
            ['cid' => $cid, 'issue_no' => $issueNo]
        );

        $this->session->flash('ok', '已忽略：这条不再计入待处理，下次一键拆解也不会再派它。');

        return $this->redirect($response, $back);
    }

    /**
     * 解析「要动哪些作品」。
     *
     * @param array<string, mixed> $body
     * @return list<int>
     */
    private function targetIds(array $body, int $limit): array
    {
        if ((string) ($body['scope'] ?? 'filter') === 'ids') {
            $raw = $body['ids'] ?? [];
            if (!is_array($raw)) {
                return [];
            }

            return array_slice(array_values(array_unique(array_filter(
                array_map('intval', $raw),
                static fn (int $id): bool => $id > 0
            ))), 0, $limit);
        }

        $filter = [
            'keyword'    => (string) ($body['q'] ?? ''),
            'issue'      => (int) ($body['issue'] ?? 0),
            'stage'      => (string) ($body['stage'] ?? 'pending'),
            'visibility' => (string) ($body['visibility'] ?? ''),
        ];

        return $this->works->idsByFilter($filter, $limit);
    }

    /**
     * 活跃 worker：running 任务按 locked_by 归组。
     *
     * 没有 running 任务但队列里有 queued —— 说明 worker 没在跑，这是最常见的
     * 「点了开始但没动静」，所以要单独提示。
     *
     * @return list<array{name:string, jobs:int, last_at:string}>
     */
    private function workers(): array
    {
        $rows = $this->pdo->query(
            "SELECT locked_by AS name, COUNT(*) AS jobs, MAX(locked_at) AS last_at
               FROM hq_job
              WHERE status = 'running' AND locked_by IS NOT NULL
              GROUP BY locked_by
              ORDER BY last_at DESC"
        )->fetchAll();

        return array_map(static fn (array $r): array => [
            // locked_by 形如 "w1:ab12cd34"（worker 名 + 一次性令牌），展示时只保留名字
            'name'    => explode(':', (string) $r['name'])[0],
            'jobs'    => (int) $r['jobs'],
            'last_at' => (string) $r['last_at'],
        ], $rows);
    }

    /** @return list<array<string,mixed>> */
    private function recentJobs(): array
    {
        $rows = $this->pdo->query(
            "SELECT j.id, j.status, j.attempts, j.max_attempts, j.last_error, j.cancel_requested,
                    j.created_at, j.updated_at, w.title, w.code
               FROM hq_job j
               LEFT JOIN hq_work w ON w.id = j.work_id
              WHERE j.type = 'analysis.run'
              ORDER BY j.id DESC
              LIMIT 20"
        )->fetchAll();

        return $rows;
    }

    /**
     * 失败列表：拆解失败 + 入馆失败。
     *
     * 入馆失败没有 work_id（作品从未入库），所以「作品」列只能靠 payload 里的
     * cid / issue_no 说明「是哪一篇、在哪一期」。不把这类算进来，概览上的
     * 「入馆失败 N」点进来会是一片空白。
     *
     * @return list<array<string,mixed>>
     */
    private function failures(): array
    {
        $rows = $this->pdo->query(
            "SELECT j.id, j.type, j.payload_json, j.work_id, j.last_error, j.attempts, j.max_attempts, j.updated_at,
                    w.title, w.code
               FROM hq_job j
               LEFT JOIN hq_work w ON w.id = j.work_id
              WHERE j.status = 'failed' AND j.type IN ('analysis.run', 'source.ingest')
              ORDER BY j.id DESC
              LIMIT 30"
        )->fetchAll();

        return array_map(static function (array $row): array {
            $payload = json_decode((string) ($row['payload_json'] ?? ''), true);
            $cid     = is_array($payload) ? (int) ($payload['cid'] ?? 0) : 0;
            $issueNo = is_array($payload) ? (int) ($payload['issue_no'] ?? 0) : 0;

            // 拼不出准确出处就给空串，让模板决定是否显示占位 —— 用「—」冒充
            // 会让「不知道是哪篇」看起来像「已经定位到了」。
            $row['source_label'] = ($cid > 0 && $issueNo > 0)
                ? sprintf('第 %d 期 · cid %d', $issueNo, $cid)
                : '';

            unset($row['payload_json']);

            return $row;
        }, $rows);
    }

    /**
     * 按已完成分析的实测均价，估算「还剩多少篇、要多少钱、要多久」。
     *
     * 数字来自本库自己的历史，不用外部拍脑袋的系数 —— 换模型、换提示词之后
     * 它会自动跟着变，这比写死「1.4 分钟/篇」可靠。
     *
     * @param array<string,int> $stats
     * @return array{per_work_yuan:float, remaining:int, cost_yuan:float, hours:float, per_work_minutes:float}
     */
    private function estimate(array $stats): array
    {
        $row = $this->pdo->query(
            "SELECT COUNT(*) AS n, COALESCE(SUM(cost_micro), 0) AS cost,
                    COALESCE(AVG(TIMESTAMPDIFF(SECOND, started_at, finished_at)), 0) AS secs
               FROM hq_analysis
              WHERE status IN ('succeeded','published') AND started_at IS NOT NULL AND finished_at IS NOT NULL"
        )->fetch() ?: [];

        $n         = (int) ($row['n'] ?? 0);
        $perYuan   = $n > 0 ? ((int) $row['cost'] / 1e6) / $n : 0.0;
        $perMinute = $n > 0 ? ((float) $row['secs']) / 60 : 0.0;

        $remaining = max(0, (int) $stats['imported']);

        return [
            'per_work_yuan'    => round($perYuan, 3),
            'per_work_minutes' => round($perMinute, 2),
            'remaining'        => $remaining,
            'cost_yuan'        => round($perYuan * $remaining, 2),
            'hours'            => round($perMinute * $remaining / 60, 1),
        ];
    }
}
