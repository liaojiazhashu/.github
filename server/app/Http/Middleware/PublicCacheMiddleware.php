<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Application\Library\PageCache;
use App\Support\Config;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * 公开页面的整页缓存 + ETag/304。
 *
 * 顺序上的一个刻意选择：**缓存放在限流里面**（先查缓存，命中就走）。
 * 理由是被缓存的命中不产生任何后端成本，让它占限流额度没有意义；
 * 反过来，缓存未命中才计数，限流表里的数字就真的代表「打到我身上的请求」。
 *
 * 只缓存满足全部条件的响应：GET、200、`text/html`、没有 `Set-Cookie`。
 * 少一条都可能把「一次带状态的响应」当成公共页面发给下一个人。
 */
final class PublicCacheMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly Config $config,
        private readonly PageCache $cache,
        private readonly ResponseFactoryInterface $responseFactory,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (strtoupper($request->getMethod()) !== 'GET') {
            return $handler->handle($request);
        }

        $path = $request->getUri()->getPath();
        $ttl  = $this->ttlFor($path);
        if ($ttl <= 0) {
            // 不缓存也要表态：否则 SecurityHeaders 会兜一个 `public, max-age=60`，
            // 让中间层把「某个人的检索结果」当成可共享的页面缓存起来
            return $handler->handle($request)->withHeader('Cache-Control', 'no-store')->withHeader('X-Cache', 'BYPASS');
        }

        $key    = $this->keyOf($request);
        $cached = $this->cache->get($key, $ttl);

        if ($cached !== null) {
            if ($this->etagMatches($request, $cached['etag'])) {
                return $this->responseFactory->createResponse(304)
                    ->withHeader('ETag', $cached['etag'])
                    ->withHeader('Cache-Control', 'public, max-age=' . $ttl)
                    ->withHeader('X-Cache', 'HIT');
            }

            $response = $this->responseFactory->createResponse(200)
                ->withHeader('Content-Type', 'text/html; charset=utf-8')
                ->withHeader('ETag', $cached['etag'])
                ->withHeader('Cache-Control', 'public, max-age=' . max(0, $ttl - $cached['age']))
                ->withHeader('X-Cache', 'HIT');
            $response->getBody()->write($cached['html']);

            return $response;
        }

        $response = $handler->handle($request);

        if (!$this->isCacheable($response)) {
            return $response->withHeader('X-Cache', 'BYPASS');
        }

        $html = (string) $response->getBody();
        $etag = $this->cache->put($key, $html, $this->tagsFor($path));

        $response = $response
            ->withHeader('Cache-Control', 'public, max-age=' . $ttl)
            ->withHeader('X-Cache', $etag === '' ? 'BYPASS' : 'MISS');

        if ($etag !== '') {
            $response = $response->withHeader('ETag', $etag);
        }

        return $response;
    }

    /** 各页的 TTL（秒）。0 = 不缓存。 */
    private function ttlFor(string $path): int
    {
        $path = rtrim($path, '/');

        if ($path === '/library') {
            return $this->config->int('library.cache.home', 60);
        }
        if (str_starts_with($path, '/library/search')) {
            return 0;
        }
        if (preg_match('#^/library/works/[^/]+(/author)?$#', $path) === 1) {
            return $this->config->int('library.cache.work', 300);
        }
        // 作者页：一群人名下的作品会随发布/隐藏变化，标签落在默认的 works 上，
        // VisibilityService 每次都会同时失效 works 与 terms，所以这里敢给 300 秒
        //
        // 作者索引与它同档：索引里每一条都是「篇数 + 字数」，发布一篇就会变。
        // 精确到路径的判定必须写在 `authors/[^/]+` 之前 —— 那个正则要求斜杠后
        // 有内容，索引本来也不会被它吃掉；写在前是为了让「索引走哪条规则」一眼可见。
        if ($path === '/library/authors') {
            return $this->config->int('library.cache.author', 300);
        }
        if (preg_match('#^/library/authors/[^/]+$#', $path) === 1) {
            return $this->config->int('library.cache.author', 300);
        }
        if (str_starts_with($path, '/library/works')) {
            return $this->config->int('library.cache.works', 60);
        }
        /* 方法说明是纯文案页：只有改代码才会变，给一小时 */
        if ($path === '/library/method') {
            return 3600;
        }
        /* 练笔统计每月 16 日 18:00 重算一次：一天之内不会变，给一小时。
           给更久也行，但一小时能在「手动补算完」之后尽快让人看到新数。 */
        if ($path === '/library/practice') {
            return 3600;
        }
        if (preg_match('#^/library/terms/[0-9]+$#', $path) === 1) {
            return $this->config->int('library.cache.term', 300);
        }
        if (str_starts_with($path, '/library/terms')) {
            return $this->config->int('library.cache.terms', 300);
        }
        if (str_starts_with($path, '/library/issues')) {
            return $this->config->int('library.cache.issue', 300);
        }

        return 0;
    }

    /**
     * 失效标签。判定与 ttlFor 同一套前缀规则，两处必须同步改 ——
     * 否则会出现「页面写着 5 分钟缓存，但发布时没被清掉」这种最难查的一类问题。
     *
     * @return list<string>
     */
    private function tagsFor(string $path): array
    {
        $path = rtrim($path, '/');

        if ($path === '/library') {
            return ['works', 'terms'];
        }
        if (preg_match('#^/library/works/([^/]+)$#', $path, $m) === 1) {
            return ['work:' . $m[1], 'works'];
        }
        if (preg_match('#^/library/terms/([0-9]+)$#', $path, $m) === 1) {
            return ['terms', 'term:' . $m[1]];
        }
        if (str_starts_with($path, '/library/terms')) {
            return ['terms'];
        }
        if (preg_match('#^/library/issues/([0-9]+)$#', $path, $m) === 1) {
            return ['works', 'issue:' . $m[1]];
        }
        /* 作者页同时依赖作品与词条：篇数、卡片来自作品，底部「反复写到的」来自词条。
           所以挂两个标签 —— VisibilityService 每次都同时失效它们，任何一个被藏都能清掉。
           刻意不发 `author:CODE`：没有任何路径会用它失效，加上去只会让人误以为
           作者页能被精确清理。 */
        if (preg_match('#^/library/authors/[^/]+$#', $path) === 1) {
            return ['works', 'terms'];
        }

        return ['works'];
    }

    /** 键包含完整查询串：同一路径不同筛选是不同的页面 */
    private function keyOf(ServerRequestInterface $request): string
    {
        $query = $request->getQueryParams();
        ksort($query);

        return $request->getUri()->getPath() . '?' . http_build_query($query);
    }

    private function etagMatches(ServerRequestInterface $request, string $etag): bool
    {
        $header = trim($request->getHeaderLine('If-None-Match'));

        return $header !== '' && ($header === $etag || $header === '*');
    }

    private function isCacheable(ResponseInterface $response): bool
    {
        if ($response->getStatusCode() !== 200) {
            return false;
        }
        if ($response->hasHeader('Set-Cookie')) {
            return false;
        }

        return str_contains(strtolower($response->getHeaderLine('Content-Type')), 'text/html');
    }
}
