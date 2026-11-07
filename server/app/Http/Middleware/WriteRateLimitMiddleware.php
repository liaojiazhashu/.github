<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Config;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

/**
 * 写入型请求的数量闸门（IP 维度）。
 *
 * 与登录限流分开：登录用「次数 / 分钟」防撞库，这里用「写入 / 分钟」防误操作与脚本刷接口。
 * 计数落在 hq_rate_limit，重启不丢，多进程共享。
 *
 * **为什么限流器是懒取的而不是构造注入**（2026-11-03 修）：
 * RateLimiter 要 PDO，而 `PDO::class => Db::connect(...)` 是解析即连接。
 * 把它写进构造签名，就等于「应用启动的第一件事是连数据库」—— 数据库一挂，
 * 全站在装配期就崩，连 GET /healthz 也活不了，响应体还是空的（已实测复现）。
 * 改成请求期再取，GET 与公开页根本不碰它。
 *
 * **取不到时放行（fail-open），不是拒绝**：
 * 限流是防滥用，不是权限。数据库故障时把所有后台写入统统拒掉，等于把
 * 「数据库不可用」升级成「谁都别想操作」，比被刷更糟。
 * 注意别把它「修」成 fail-closed —— 那会把这一次事故原样重演一遍。
 */
final class WriteRateLimitMiddleware implements MiddlewareInterface
{
    private const UNSAFE = ['POST', 'PUT', 'PATCH', 'DELETE'];

    public function __construct(
        // 只要一个容器引用：真正的 RateLimiter 等到确实要计数时才取
        private readonly ContainerInterface $container,
        private readonly Config $config,
        private readonly ResponseFactoryInterface $responseFactory,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!in_array(strtoupper($request->getMethod()), self::UNSAFE, true)) {
            return $handler->handle($request);
        }

        $path = $request->getUri()->getPath();
        if (!str_starts_with($path, '/admin')) {
            return $handler->handle($request);
        }

        // 走到这里才真的需要计数。取不到就放行，理由见类注释。
        try {
            $limiter = $this->container->get(\App\Infrastructure\Pdo\RateLimiter::class);
        } catch (Throwable $e) {
            error_log('[ratelimit] 限流器不可用，本次写入放行：' . $e->getMessage());

            return $handler->handle($request);
        }

        if (!$limiter instanceof \App\Infrastructure\Pdo\RateLimiter) {
            return $handler->handle($request);
        }

        $bucket = 'admin_write:ip:' . ($_SERVER['REMOTE_ADDR'] ?? '');
        [$limit, $window] = (array) $this->config->get('security.rate_limit.admin_write', [120, 60]);

        if ($limiter->tooMany($bucket, (int) $limit, (int) $window)) {
            $response = $this->responseFactory->createResponse(429)
                ->withHeader('Retry-After', (string) $limiter->retryAfter($bucket))
                ->withHeader('Content-Type', 'text/plain; charset=utf-8');
            $response->getBody()->write('操作过于频繁，请稍后再试');

            return $response;
        }

        return $handler->handle($request);
    }
}
