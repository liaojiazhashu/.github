<?php

declare(strict_types=1);

namespace App\Http\Controller\Admin;

use App\Infrastructure\Ai\CostModel;
use App\Infrastructure\Pdo\AiCallRepository;
use App\Infrastructure\Pdo\JobRepository;
use App\Infrastructure\Pdo\WorkRepository;
use App\Support\Config;
use App\Support\Session;
use App\View\View;
use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

/**
 * 概览页：只回答「现在什么状态」，不堆营销话术。
 */
final class DashboardController extends AdminController
{
    public function __construct(
        View $view,
        Session $session,
        private readonly WorkRepository $works,
        private readonly JobRepository $jobs,
        private readonly AiCallRepository $aiCalls,
        private readonly CostModel $cost,
        private readonly PDO $pdo,
        private readonly Config $config,
    ) {
        parent::__construct($view, $session);
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = (array) $request->getAttribute('user', []);
        $stats  = $this->works->stats();
        // 「拆解失败」只认 analysis.run；入馆失败单独一项，否则点进队列页会找不到条目
        $queue        = $this->jobs->counts('analysis.run');
        $ingestFailed = (int) ($this->jobs->counts('source.ingest')['failed'] ?? 0);
        $admin = $this->works->adminStats();

        $today   = $this->aiCalls->summary(date('Y-m-d 00:00:00'));
        $budget  = (float) $this->config->get('ai.budget.daily_yuan', 100.0);

        return $this->render($response, 'admin/dashboard', [
            'nav'    => 'dashboard',
            'user'   => $user,
            'stats'  => $stats,
            'recent' => $this->works->recent(8),
            'health' => $this->health(),
            'queue'  => $queue,
            'today'  => $today,
            'todo'   => $this->todo($queue, $admin, $ingestFailed),
            'todayYuan' => CostModel::toYuan((int) $today['cost_micro']),
            'budgetYuan' => $budget,
            'peakLabel' => $this->cost->peakLabel(),
        ]);
    }

    /**
     * 「今天有没有事要做」——概览页存在的第一个理由。
     *
     * 进入 1300 篇之后，运营每天早上只想知道三件事：有没有跑坏、有没有拆完没发、
     * worker 还活着没有。这三样以前散在队列卡的一行灰色小字和另一个页面里，
     * 而要发现它们，人得先自己想到去翻。这里把它们提到第一屏，按后果排先后。
     *
     * 返回空数组 = 真的没事。模板那时只渲染一行「队列在跑，没有需要处理的事」，
     * 不占一行高度去写「暂无」——「暂无」和「没加载出来」在视觉上分不开。
     *
     * @param array<string,int|string> $queue
     * @param array<string,int>        $admin
     * @param int                      $ingestFailed 入馆失败条数（source.ingest）
     * @return list<array{label:string,n:int,href:string,tone:string}>
     */
    private function todo(array $queue, array $admin, int $ingestFailed = 0): array
    {
        $failed  = (int) ($queue['failed'] ?? 0);
        $queued  = (int) ($queue['queued'] ?? 0);
        $running = (int) ($queue['running'] ?? 0);
        $ready   = (int) ($admin['analyzed'] ?? 0);

        $todo = [];

        if ($failed > 0) {
            $todo[] = [
                'label' => '拆解失败',
                'title' => '有任务失败了：点进去看失败原因，或整批重试',
                'n'     => $failed,
                // 锚到失败区块：落到页面顶部的话，人还得自己往下翻找那几条
                'href'  => '/admin/queue#failures',
                'tone'  => 'danger',
            ];
        }

        if ($ingestFailed > 0) {
            $todo[] = [
                'label' => '入馆失败',
                'title' => '源站正文没拉到或字数不足：重试前先看原因；确认拉不到可以忽略，忽略后不再计入待处理',
                'n'     => $ingestFailed,
                'href'  => '/admin/queue#ingest-failed',
                'tone'  => 'danger',
            ];
        }

        // 有排队却没人在跑：这是最安静的一种故障 —— 页面一切正常，
        // 只是从某一刻起不再前进。放在失败之后、待发布之前。
        if ($queued > 0 && $running === 0) {
            $todo[] = [
                'label' => '排队却没人跑',
                'title' => '队列里有任务，但没有 worker 在运行：去调度页看闸门与 worker 状态',
                'n'     => $queued,
                'href'  => '/admin/queue',
                'tone'  => 'danger',
            ];
        }

        if ($ready > 0) {
            $todo[] = [
                'label' => '拆完待上架',
                'title' => '拆解已完成但还没公开：点进去筛出这批，抽查后批量上架',
                'n'     => $ready,
                'href'  => '/admin/works?stage=ready',
                'tone'  => 'warn',
            ];
        }

        return $todo;
    }

    /** @return array{db: string, version: string, tables: int, ai: string, env: string} */
    private function health(): array
    {
        $out = ['db' => 'error', 'version' => '-', 'tables' => 0, 'ai' => 'off', 'env' => $this->config->string('app.env', 'production')];

        try {
            $out['version'] = (string) $this->pdo->query('SELECT VERSION()')->fetchColumn();
            $out['tables']  = (int) $this->pdo->query(
                "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()"
            )->fetchColumn();
            $out['db'] = 'ok';
        } catch (Throwable) {
            // 保持 error，页面照常渲染
        }

        $out['ai'] = $this->config->bool('ai.enabled', false) ? 'on' : 'off';

        return $out;
    }
}
