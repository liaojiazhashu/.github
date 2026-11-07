<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Application\Auth\Permission;
use App\Infrastructure\Pdo\SessionRepository;
use App\Infrastructure\Pdo\UserRepository;
use App\Support\Config;
use App\Support\Crypto;
use App\Support\Session;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * 认证中间件：把「谁在操作」解析成请求属性 user。
 *
 * 两个容易被忽略的点：
 *   1. **会话必须能在服务端被吊销**。只靠 PHP session 文件的话，「踢掉某台设备」做不到——
 *      所以每次请求都比对 hq_session 索引，行没了立即失效。
 *   2. **禁用账号要立刻断线**。用户被置为 disabled 后，下一次请求就得踢出去，不能等会话过期。
 */
final class AuthMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly Session $session,
        private readonly SessionRepository $sessions,
        private readonly UserRepository $users,
        private readonly Crypto $crypto,
        private readonly Config $config,
        private readonly ResponseFactoryInterface $responseFactory,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $uid = $this->session->get('uid');

        if (!is_int($uid) || $uid <= 0) {
            return $this->redirectToLogin();
        }

        $digest = $this->crypto->digest($this->session->id());

        if (!$this->sessions->isAlive($digest, $uid)) {
            $this->session->destroy();
            return $this->redirectToLogin('expired=1');
        }

        $user = $this->users->findById($uid);
        if ($user === null || ($user['status'] ?? '') !== 'active') {
            $this->session->destroy();
            $this->sessions->delete($digest);
            return $this->redirectToLogin('expired=1');
        }

        // 权限每请求查库，不缓存在会话里：改一行 hq_role_permission 就该当场生效，
        // 不能让「已经登录的人」拿着旧权限继续操作（M4 权限矩阵）。
        $user['permissions'] = $this->users->permissionsOf((int) ($user['role_id'] ?? 0));
        $user['role_label']  = Permission::ROLE_LABELS[(string) ($user['role_code'] ?? '')]
            ?? (string) ($user['role_code'] ?? '');

        $this->sessions->touch($digest, $this->config->int('session.lifetime', 1800));

        return $handler->handle($request->withAttribute('user', $user));
    }

    private function redirectToLogin(string $query = ''): ResponseInterface
    {
        $location = '/admin/login' . ($query === '' ? '' : '?' . $query);

        return $this->responseFactory->createResponse(302)->withHeader('Location', $location);
    }
}
