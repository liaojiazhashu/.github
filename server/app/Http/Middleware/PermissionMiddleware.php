<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Application\Auth\Permission;
use App\Infrastructure\Pdo\AuditLogger;
use App\Support\Config;
use App\Support\Session;
use App\View\View;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;
use Slim\Routing\RouteContext;

/**
 * 权限校验中间件（M4「权限矩阵」的执行点）。
 *
 * 三条规矩：
 *   1. **默认拒绝**：路由名不在 config/permissions.php 里就 403。
 *      新增动作忘了配权限必须当场发现，而不是悄悄开放给所有登录用户。
 *   2. **只信服务端的权限列表**：权限来自 hq_role_permission（AuthMiddleware 每请求查库），
 *      不读 cookie、不读 session 里缓存的副本 —— 改权限当场生效，不用等用户重新登录。
 *   3. **拒绝要留痕**：写 hq_audit_log。越权尝试是最该被事后追问的那类事件。
 */
final class PermissionMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly Config $config,
        private readonly View $view,
        private readonly Session $session,
        private readonly AuditLogger $audit,
        private readonly ResponseFactoryInterface $responseFactory,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $user  = (array) $request->getAttribute('user', []);
        $map   = (array) $this->config->get('permissions', []);
        $route = RouteContext::fromRequest($request)->getRoute();
        $name  = $route?->getName();

        if ($name === null || $name === '') {
            return $this->deny($request, $user, '未命名路由', null);
        }

        if (!array_key_exists($name, $map)) {
            return $this->deny($request, $user, '路由未登记权限：' . $name, null);
        }

        $required = $map[$name];
        if ($required === null) {
            return $handler->handle($request);
        }

        $perms = (array) ($user['permissions'] ?? []);
        if (Permission::can($perms, (string) $required)) {
            return $handler->handle($request);
        }

        return $this->deny($request, $user, '缺少权限：' . (string) $required, (string) $required);
    }

    /**
     * @param array<string, mixed> $user
     */
    private function deny(ServerRequestInterface $request, array $user, string $message, ?string $permission): ResponseInterface
    {
        try {
            $this->audit->log(
                isset($user['id']) ? (int) $user['id'] : null,
                'auth.denied',
                'route',
                $request->getMethod() . ' ' . $request->getUri()->getPath(),
                null,
                ['reason' => $message, 'permission' => $permission]
            );
        } catch (RuntimeException) {
            // 审计写失败不能把 403 变成 500：拒绝本身是安全行为，先拒绝，再谈记录
        }

        return $this->view->render(
            $this->responseFactory->createResponse(403),
            'admin/errors/403',
            [
                'nav'     => '',
                'user'    => $user,
                'flashes' => $this->session->pullFlashes(),
                'csrf'    => $this->session->csrfToken(),
                'reason'  => $message,
                'need'    => $permission === null ? '' : ($this->labelOf($permission)),
            ]
        );
    }

    private function labelOf(string $permission): string
    {
        return Permission::LABELS[$permission] ?? $permission;
    }
}
