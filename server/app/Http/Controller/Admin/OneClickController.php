<?php

declare(strict_types=1);

namespace App\Http\Controller\Admin;

use App\Application\Analysis\PublishService;
use App\Application\Pipeline\OneClick;
use App\Application\Source\AuthorActivity;
use App\Application\Source\SourceCatalog;
use App\Infrastructure\Pdo\AuditLogger;
use App\Infrastructure\Pdo\RunBatchRepository;
use App\Infrastructure\Pdo\SettingRepository;
use App\Infrastructure\Pdo\WorkRepository;
use App\Support\Session;
use App\View\View;
use RuntimeException;
use Throwable;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * 一键拆解。
 *
 * 与「拆解调度台」的分工：
 *   · 调度台是仪表盘 —— 看进度、看失败、暂停、逐条重试；
 *   · 这一页是**动作** —— 选一段范围，按下去，剩下的事（抓正文、入馆、拆解、可选上架）自己跑完。
 *
 * 为什么单独一页而不是塞进调度台：调度台要回答的是「现在怎么样」，
 * 一键拆解要回答的是「接下来拆哪些」。两件事混在一屏，每次开始拆解都要在一堆
 * 仪表中间找表单，而 1300 篇的节奏是「选范围 → 按 → 隔一阵回来看」。
 *
 * 范围用 GET 表单提交，所以预览可以直接刷新、可以收藏成书签
 * （「第 1–17 期的僵尸作品」这种范围会被反复用）。
 */
final class OneClickController extends AdminController
{
    public function __construct(
        View $view,
        Session $session,
        private readonly OneClick $oneClick,
        private readonly RunBatchRepository $batches,
        private readonly WorkRepository $works,
        private readonly SettingRepository $settings,
        private readonly PublishService $publisher,
        private readonly AuditLogger $audit,
        private readonly SourceCatalog $catalog,
        private readonly AuthorActivity $activity,
    ) {
        parent::__construct($view, $session);
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $query = $request->getQueryParams();
        $scope = OneClick::normalizeScope($query);

        $error = '';
        $plan  = null;
        try {
            $plan = $this->oneClick->resolve($scope);
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }

        $meta = $this->catalog->load()['meta'] ?? [];

        return $this->render($response, 'admin/oneclick/index', [
            'nav'        => 'oneclick',
            'user'       => (array) $request->getAttribute('user', []),
            'scope'      => $scope,
            'plan'       => $plan,
            'error'      => $error,
            'issues'     => $this->works->issues(),
            'paused'     => $this->settings->bool('analysis.paused', false),
            'batches'    => $this->batches->recent(6),
            'catalog_at' => (int) ($meta['built_at'] ?? 0),
            'activity_at' => $this->activity->builtAt(),
            'just_launched' => (string) ($query['batch'] ?? ''),
        ]);
    }

    /** 「开始拆解」：建批次 → 派活（抓取 / 拆解），返回同一页看进度。 */
    public function launch(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user  = (array) $request->getAttribute('user', []);
        $body  = (array) ($request->getParsedBody() ?? []);
        $scope = OneClick::normalizeScope($body);

        try {
            $result = $this->oneClick->launch($scope, [
                'auto_publish' => !empty($body['auto_publish']),
                'keep_hidden'  => !empty($body['keep_hidden']),
                'note'         => (string) ($body['note'] ?? ''),
            ], (int) ($user['id'] ?? 0) ?: null);
        } catch (RuntimeException $e) {
            $this->session->flash('warn', $e->getMessage());

            return $this->redirect($response, '/admin/oneclick?' . http_build_query($this->queryOf($scope)));
        }

        $this->session->flash('ok', sprintf(
            '已派出 %d 篇：%d 篇直接拆解，%d 篇先去官网抓正文（抓到后自动接拆解）。',
            $result['queued'],
            $result['queued'] - $result['ingest'],
            $result['ingest']
        ));

        foreach ($result['reasons'] as $reason => $count) {
            $this->session->flash('warn', sprintf('跳过 %d 篇：%s', $count, $reason));
        }

        if (!empty($body['keep_hidden'])) {
            $this->session->flash('ok', '这批拆完会置为「不公开」：结果只在后台可见，前台任何页面都不显示。');
        }

        if ($result['queued'] > 0 && $this->settings->bool('analysis.paused', false)) {
            $this->session->flash('warn', '闸门当前是「已暂停」，任务会排着但不领。恢复在拆解调度台。');
        }

        return $this->redirect($response, '/admin/oneclick?batch=' . urlencode($result['batch_code']));
    }

    /**
     * 把这一批里「已拆完、还没上架」的作品一次上架。
     *
     * 上架与拆解分开按：拆解是花钱跑机器，上架是「这些结果可以见人了」的对外承诺。
     * 一键拆解默认停在「已拆未上架」，人看过再按这个键 —— 把两者合成一步，
     * 等于模型跑歪的产物不经人眼就出现在馆里。
     */
    public function publish(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user  = (array) $request->getAttribute('user', []);
        $body  = (array) ($request->getParsedBody() ?? []);
        $code  = (string) ($body['batch'] ?? '');
        $batch = $this->batches->findByCode($code);

        if ($batch === null) {
            $this->session->flash('err', '找不到这个批次。');

            return $this->redirect($response, '/admin/oneclick');
        }

        $actorId = (int) ($user['id'] ?? 0) ?: null;

        // 不公开批次不给整批上架的路：它存在的意义就是「结果只在后台」。
        // 真要公开某几篇，去作品页逐篇点，那边有明确的确认。
        $options = json_decode((string) ($batch['options_json'] ?? ''), true) ?: [];
        if (!empty($options['keep_hidden'])) {
            $this->session->flash('warn', '这一批是「拆完不公开」的批次，不能整批上架。要公开请到作品页逐篇处理。');

            return $this->redirect($response, '/admin/oneclick?batch=' . urlencode($code));
        }

        $rows = $this->batches->pendingPublish((int) $batch['id']);

        // 逐篇的状态变更 / 快照 / 审计 / 索引与缓存 / sitemap 全在 PublishService 里，
        // sitemap 由它在批末只写一次。本方法只保留一条**批次级**审计
        // （「谁一次上了多少篇」），所以逐篇审计关掉，否则同一件事会写两遍。
        //
        // mark_edited 显式 false：发布不是编辑。这条路径以前吃 snapshot() 的默认
        // true，于是「一键拆解 → 自动上架」的每一篇都被记成 source=mixed，
        // 后台据此显示「模型生成 + 人工调整」—— 而全程没有人动过一个字。
        // 来源标记一旦失真就没法反推「哪几篇真被人工改过」，这个信息比省一行参数值钱。
        $result = $this->publisher->publishMany(
            array_map(
                static fn (array $row): array => [
                    'analysis_id' => (int) $row['analysis_id'],
                    'work_id'     => (int) $row['work_id'],
                ],
                $rows
            ),
            $actorId,
            [
                'note'        => '一键拆解批次上架',
                'audit'       => false,
                'mark_edited' => false,
            ]
        );

        $ok     = $result['published'];
        $failed = $result['failed'];

        $this->audit->log($actorId, 'pipeline.publish_batch', 'run_batch', $code, null, [
            'published' => $ok, 'failed' => $failed,
        ]);

        $this->session->flash($failed === 0 ? 'ok' : 'warn', sprintf(
            '已上架 %d 篇%s。',
            $ok,
            $failed > 0 ? sprintf('，%d 篇失败（可在作品页逐篇处理）', $failed) : ''
        ));

        return $this->redirect($response, '/admin/oneclick?batch=' . urlencode($code));
    }

    /** 取消这一批里还排着队的任务（正在跑的不动，它们要跑完或走单条停止）。 */
    public function cancel(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user  = (array) $request->getAttribute('user', []);
        $body  = (array) ($request->getParsedBody() ?? []);
        $code  = (string) ($body['batch'] ?? '');
        $batch = $this->batches->findByCode($code);

        if ($batch === null) {
            $this->session->flash('err', '找不到这个批次。');

            return $this->redirect($response, '/admin/oneclick');
        }

        $count = $this->batches->cancelQueued((int) $batch['id']);

        $this->audit->log((int) ($user['id'] ?? 0) ?: null, 'pipeline.cancel_batch', 'run_batch', $code, null, [
            'canceled' => $count,
        ]);

        $this->session->flash('ok', sprintf('已取消 %d 条排队中的任务。正在跑的不受影响。', $count));

        return $this->redirect($response, '/admin/oneclick?batch=' . urlencode($code));
    }

    /** 把范围写回查询串：开始/失败之后回到同一屏，不用重新勾一遍。 */
    private function queryOf(array $scope): array
    {
        $q = ['stage' => $scope['stage'], 'limit' => $scope['limit']];
        if ($scope['issues'] !== []) {
            $q['issues'] = $scope['issues'];
        }
        if ($scope['author_state'] !== 'all') {
            $q['author_state'] = $scope['author_state'];
        }
        if ($scope['min_words'] > 0) {
            $q['min_words'] = $scope['min_words'];
        }
        if ($scope['max_words'] > 0) {
            $q['max_words'] = $scope['max_words'];
        }
        if ($scope['keyword'] !== '') {
            $q['keyword'] = $scope['keyword'];
        }
        if ($scope['force']) {
            $q['force'] = 1;
        }

        return $q;
    }
}
