<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * 安全响应头。全部在中间件里统一发，避免「某个控制器忘了加」。
 *
 * CSP 刻意收紧到 'self'：后台没有 CDN、没有 inline script、没有外链字体。
 * 一旦有人图省事往模板里塞 <script>…</script>，浏览器会直接拦下并报错，这是想要的摩擦。
 */
final class SecurityHeadersMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        $isAdmin = str_starts_with($request->getUri()->getPath(), '/admin');

        $response = $response
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('X-Frame-Options', 'DENY')
            ->withHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->withHeader('Permissions-Policy', 'geolocation=(), microphone=(), camera=()')
            ->withHeader('Cross-Origin-Opener-Policy', 'same-origin')
            ->withHeader('Cross-Origin-Resource-Policy', 'same-origin')
            ->withHeader(
                'Content-Security-Policy',
                "default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; "
                . "font-src 'self'; connect-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'"
            );

        if ($isAdmin) {
            // 后台不进搜索引擎（§9.4）。meta 标签能删，响应头不能，两层都要有
            return $response
                ->withHeader('X-Robots-Tag', 'noindex, nofollow')
                ->withHeader('Cache-Control', 'no-store, no-cache, must-revalidate');
        }

        // 公开页的 Cache-Control 由 PublicCacheMiddleware 按页设（60s / 300s / 不缓存）。
        // 这里只在「里面没人设过」时兜一个默认值 —— 否则发布时就白设了，一切回落成 60s。
        return $response->hasHeader('Cache-Control')
            ? $response
            : $response->withHeader('Cache-Control', 'public, max-age=60');
    }
}
