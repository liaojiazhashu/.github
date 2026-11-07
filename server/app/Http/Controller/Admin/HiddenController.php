<?php

declare(strict_types=1);

namespace App\Http\Controller\Admin;

use App\Application\Library\VisibilityService;
use App\Infrastructure\Pdo\AuditLogger;
use App\Infrastructure\Pdo\TermRepository;
use App\Infrastructure\Pdo\WorkRepository;
use App\Support\Session;
use App\View\View;
use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * 隐藏归档：所有「已经藏起来的东西」的集中入口。
 *
 * 为什么需要单独一页：隐藏是「暂时不让看」，不是「处理完了」。藏起来的东西
 * 恰恰是最容易忘的东西 —— 1300 篇跑上一个月，中途藏过哪些、为什么藏、该不该
 * 放回去，如果只留在列表的筛选条件里，就没人再想得起来。
 *
 * 这一页按三类分开列：作品 / 拆解结果 / 词条。每一条都带隐藏时刻与理由，
 * 并且可以就地恢复。恢复是单向的、立即生效的、带审计的。
 *
 * 刻意不做「批量恢复全部」：如果 300 条隐藏里有 20 条是该继续藏着的，
 * 一键全放回去就是把那 20 条也放了出去。必须一条条看。
 */
final class HiddenController extends AdminController
{
    public function __construct(
        View $view,
        Session $session,
        private readonly WorkRepository $works,
        private readonly TermRepository $terms,
        private readonly VisibilityService $visibility,
        private readonly AuditLogger $audit,
        private readonly PDO $pdo,
    ) {
        parent::__construct($view, $session);
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->render($response, 'admin/hidden/index', [
            'nav'    => 'hidden',
            'user'   => (array) $request->getAttribute('user', []),
            'works'  => $this->hiddenWorks(),
            'held'   => $this->hiddenAnalyses(),
            'terms'  => $this->hiddenTerms(),
        ]);
    }

    /** 恢复一条（作品 / 拆解 / 词条）。 */
    public function restore(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = (array) $request->getAttribute('user', []);
        $body = (array) ($request->getParsedBody() ?? []);

        $type = (string) ($args['type'] ?? '');
        $id   = (int) ($args['id'] ?? 0);

        $map = [
            'work'     => VisibilityService::WORK,
            'analysis' => VisibilityService::ANALYSIS,
            'term'     => VisibilityService::TERM,
        ];

        if (!isset($map[$type]) || $id <= 0) {
            $this->session->flash('warn', '参数不对，什么都没做。');

            return $this->redirect($response, '/admin/hidden');
        }

        $result = $this->visibility->restore($map[$type], [$id], ((int) ($user['id'] ?? 0)) ?: null);

        $this->session->flash(
            $result['changed'] > 0 ? 'ok' : 'warn',
            $result['changed'] > 0
                ? sprintf('已恢复，%d 个页面缓存已失效。', $result['pages'])
                : '这条本来就不是隐藏状态。'
        );

        return $this->redirect($response, '/admin/hidden');
    }

    /** @return list<array<string,mixed>> */
    private function hiddenWorks(): array
    {
        return $this->pdo->query(
            "SELECT w.id, w.code, w.title, w.hidden_at, w.hidden_reason, w.status,
                    i.label AS issue_label, a.name AS author_name
               FROM hq_work w
               LEFT JOIN hq_issue i ON i.id = w.issue_id
               LEFT JOIN hq_author a ON a.id = w.author_id
              WHERE w.visibility = 'hidden' AND w.deleted_at IS NULL
              ORDER BY w.hidden_at DESC, w.id DESC
              LIMIT 300"
        )->fetchAll();
    }

    /** @return list<array<string,mixed>> */
    private function hiddenAnalyses(): array
    {
        return $this->pdo->query(
            "SELECT a.id, a.code, a.hidden_at, a.hidden_reason, a.status,
                    w.code AS work_code, w.title
               FROM hq_analysis a
               JOIN hq_work w ON w.id = a.work_id AND w.deleted_at IS NULL
              WHERE a.visibility = 'hidden'
              ORDER BY a.hidden_at DESC, a.id DESC
              LIMIT 300"
        )->fetchAll();
    }

    /** @return list<array<string,mixed>> */
    private function hiddenTerms(): array
    {
        return $this->pdo->query(
            "SELECT t.id, t.canonical, t.category, t.hidden_at, t.hidden_reason,
                    (SELECT COUNT(*) FROM hq_work_term wt WHERE wt.term_id = t.id) AS refs_all
               FROM hq_index_term t
              WHERE t.visibility = 'hidden'
              ORDER BY t.hidden_at DESC, t.id DESC
              LIMIT 300"
        )->fetchAll();
    }
}
