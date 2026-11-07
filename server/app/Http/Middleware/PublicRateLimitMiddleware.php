<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Support\ErrorPage;
use App\Infrastructure\Pdo\RateLimiter;
use App\Support\Config;
use App\Support\RequestContext;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * 公开读限流（60 次/分/IP，§10.1）。
 *
 * 放在缓存中间件**里面**执行：被缓存命中的请求根本走不到这里。
 * 这不是偷懒，而是口径问题 —— 限流要保护的是后端，不是「访问网页的次数」；
 * 走缓存的请求只读了一个磁盘文件，把它算进额度会让正常读者莫名其妙被 429。
 *
 * 只统计 GET（公开区没有写接口）。
 */
final class PublicRateLimitMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly Config $config,
        private readonly RateLimiter $limiter,
        private readonly RequestContext $context,
        private readonly ResponseFactoryInterface $responseFactory,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (strtoupper($request->getMethod()) !== 'GET') {
            return $handler->handle($request);
        }

        [$limit, $window] = $this->config->get('library.rate_limit', [60, 60]);
        $limit  = max(1, (int) $limit);
        $window = max(1, (int) $window);

        $bucket = 'library:' . $this->context->ip();

        if ($this->limiter->tooMany($bucket, $limit, $window)) {
            $retryAfter = $this->limiter->retryAfter($bucket);

            return ErrorPage::render(
                $this->responseFactory->createResponse(429),
                429,
                '请求有点快',
                sprintf('每分钟最多 %d 次访问，请在 %d 秒后重试。', $limit, $retryAfter),
                '/library/works',
                '回到馆藏'
            )->withHeader('Retry-After', (string) $retryAfter);
        }

        return $handler->handle($request);
    }
}
