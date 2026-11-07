<?php

declare(strict_types=1);

namespace App\Http\Controller\Admin;

use App\Support\Session;
use App\View\View;
use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * 审计日志（只读）。
 *
 * hq_audit_log 是 append-only 的（002 迁移用触发器挡住了 UPDATE / DELETE），
 * 所以这一页只提供「看」和「筛」，不提供任何修改入口 —— 那正是它可信的原因。
 *
 * 1300 篇的批跑会产生大量条目，所以默认按时间倒序 + 分页 60 条，
 * 并且把「批量操作」压缩成**一条**汇总记录（见 VisibilityService / QueueController），
 * 而不是每篇一条 —— 否则一次隐藏 300 篇就是 300 行，人根本读不过来。
 */
final class AuditController extends AdminController
{
    private const PER_PAGE = 60;

    public function __construct(
        View $view,
        Session $session,
        private readonly PDO $pdo,
    ) {
        parent::__construct($view, $session);
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $query  = $request->getQueryParams();
        $page   = max(1, (int) ($query['page'] ?? 1));
        $action = trim((string) ($query['action'] ?? ''));
        $actor  = (int) ($query['actor'] ?? 0);

        $where  = ['1 = 1'];
        $params = [];

        if ($action !== '') {
            $where[]          = 'l.action LIKE :action';
            $params[':action'] = str_replace(['%', '_'], ['\%', '\_'], $action) . '%';
        }
        if ($actor > 0) {
            $where[]         = 'l.actor_id = :actor';
            $params[':actor'] = $actor;
        }

        $whereSql = implode(' AND ', $where);

        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM hq_audit_log l WHERE ' . $whereSql);
        $stmt->execute($params);
        $total = (int) $stmt->fetchColumn();

        $offset = ($page - 1) * self::PER_PAGE;

        $stmt = $this->pdo->prepare(
            'SELECT l.id, l.actor_id, l.action, l.target_type, l.target_id,
                    l.before_json, l.after_json, l.trace_id, l.created_at,
                    u.username
               FROM hq_audit_log l
               LEFT JOIN hq_user u ON u.id = l.actor_id
              WHERE ' . $whereSql . '
              ORDER BY l.id DESC
              LIMIT ' . self::PER_PAGE . ' OFFSET ' . $offset
        );
        $stmt->execute($params);

        return $this->render($response, 'admin/audit/index', [
            'nav'    => 'audit',
            'user'   => (array) $request->getAttribute('user', []),
            'rows'   => $stmt->fetchAll(),
            'total'  => $total,
            'page'   => $page,
            'pages'  => max(1, (int) ceil($total / self::PER_PAGE)),
            'filter' => ['action' => $action, 'actor' => $actor],
            'actors' => $this->actors(),
            'actions' => $this->actions(),
        ]);
    }

    /** @return list<array<string,mixed>> */
    private function actors(): array
    {
        return $this->pdo->query(
            'SELECT u.id, u.username, COUNT(l.id) AS n
               FROM hq_audit_log l
               JOIN hq_user u ON u.id = l.actor_id
              GROUP BY u.id, u.username
              ORDER BY n DESC
              LIMIT 20'
        )->fetchAll();
    }

    /** @return list<array<string,mixed>> */
    private function actions(): array
    {
        return $this->pdo->query(
            'SELECT action, COUNT(*) AS n
               FROM hq_audit_log
              GROUP BY action
              ORDER BY n DESC
              LIMIT 30'
        )->fetchAll();
    }
}
