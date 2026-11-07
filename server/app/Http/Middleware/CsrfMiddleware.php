<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Support\ErrorPage;
use App\Support\Config;
use App\Support\Session;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * CSRF 防护：Token + Origin + Sec-Fetch-Site 三重。
 *
 * 为什么三个都要：
 *   · Token 挡「别的站点的表单直接 POST 过来」；
 *   · Origin 挡「Token 被 XSS 读到后跨站重放」（同源策略下攻击者读不到，但要防漏）；
 *   · Sec-Fetch-Site 是现代浏览器的兜底信号，缺了不拦（老浏览器），有了必须对。
 */
final class CsrfMiddleware implements MiddlewareInterface
{
    private const UNSAFE = ['POST', 'PUT', 'PATCH', 'DELETE'];

    public function __construct(
        private readonly Session $session,
        private readonly Config $config,
        private readonly ResponseFactoryInterface $responseFactory,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!in_array(strtoupper($request->getMethod()), self::UNSAFE, true)) {
            return $handler->handle($request);
        }

        $origin = $request->getHeaderLine('Origin');
        if ($origin !== '' && !$this->sameOrigin($origin)) {
            return ErrorPage::render(
                $this->responseFactory->createResponse(),
                403,
                '请求来源不合法',
                '跨站提交已被拒绝。请从后台页面重新操作。'
            );
        }

        $fetchSite = $request->getHeaderLine('Sec-Fetch-Site');
        if ($fetchSite !== '' && !in_array($fetchSite, ['same-origin', 'none'], true)) {
            return ErrorPage::render(
                $this->responseFactory->createResponse(),
                403,
                '请求来源不合法',
                '浏览器标记该请求来自其他站点，已拒绝。'
            );
        }

        $body  = $request->getParsedBody();
        $token = is_array($body) ? ($body['_token'] ?? null) : null;
        if (!is_string($token) || $token === '') {
            $token = $request->getHeaderLine('X-CSRF-Token');
        }

        if (!$this->session->checkCsrf($token)) {
            return ErrorPage::render(
                $this->responseFactory->createResponse(),
                419,
                '页面已过期',
                '安全令牌校验未通过。可能是页面停留过久或开了多个标签页，返回上一页刷新后重试。'
            );
        }

        return $handler->handle($request);
    }

    private function sameOrigin(string $origin): bool
    {
        $expected = parse_url($this->config->string('app.url'));
        $actual   = parse_url($origin);

        if ($expected === false || $actual === false) {
            return false;
        }

        $expectedHost = strtolower((string) ($expected['host'] ?? ''));
        $actualHost   = strtolower((string) ($actual['host'] ?? ''));

        $expectedPort = $expected['port'] ?? ($this->isHttps($expected['scheme'] ?? '') ? 443 : 80);
        $actualPort   = $actual['port'] ?? ($this->isHttps($actual['scheme'] ?? '') ? 443 : 80);

        return $expectedHost !== ''
            && $expectedHost === $actualHost
            && (int) $expectedPort === (int) $actualPort
            && strtolower((string) ($expected['scheme'] ?? '')) === strtolower((string) ($actual['scheme'] ?? ''));
    }

    private function isHttps(string $scheme): bool
    {
        return strtolower($scheme) === 'https';
    }
}
