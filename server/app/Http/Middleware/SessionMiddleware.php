<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\RequestContext;
use App\Support\Session;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * 启动会话 + 填充请求上下文（IP / UA / trace_id）。
 *
 * 注意：PHP 内置服务器的 REMOTE_ADDR 恒为 127.0.0.1，反向代理场景下也不能直接信
 * X-Forwarded-For（可伪造）。本系统只取 REMOTE_ADDR，部署在 Nginx 后面时由 Nginx
 * 负责设置 REMOTE_ADDR（见附录 B.4），应用层不再二次解析。
 */
final class SessionMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly Session $session,
        private readonly RequestContext $context,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $this->context->setClient(
            (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
            $request->getHeaderLine('User-Agent')
        );

        // 公开馆藏不启会话（§9：只读、无登录、无 Cookie）。
        // 启了也不报错，但响应会带上 Set-Cookie，公共缓存就不能再存它 ——
        // 一个用不上的 Cookie，代价是整页缓存失效。
        if (!$this->isPublicRead($request)) {
            $this->session->start();
        }

        return $handler->handle($request);
    }

    private function isPublicRead(ServerRequestInterface $request): bool
    {
        if (strtoupper($request->getMethod()) !== 'GET') {
            return false;
        }

        $path = $request->getUri()->getPath();

        // 库根只是一个 302，健康检查只回 JSON —— 两者都不渲染页面、不读会话，
        // 跟着启会话只会白写 session 文件并下发 cookie（探活是每分钟都在打的）。
        return $path === '/'
            || $path === '/healthz'
            || $path === '/sitemap.xml'
            || str_starts_with($path, '/library');
    }
}
