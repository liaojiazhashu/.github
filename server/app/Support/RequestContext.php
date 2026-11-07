<?php

declare(strict_types=1);

namespace App\Support;

/**
 * 请求级上下文：客户端 IP / UA 只在内存里出现，落库一律用 HMAC 摘要。
 * trace_id 串起「一次请求 → 日志 → 审计 → AI 调用」三段记录。
 */
final class RequestContext
{
    private string $ip = '';

    private string $ua = '';

    private readonly string $traceId;

    public function __construct(private readonly Crypto $crypto)
    {
        $this->traceId = bin2hex(random_bytes(8));
    }

    public function setClient(string $ip, string $userAgent): void
    {
        $this->ip = $ip;
        $this->ua = mb_substr($userAgent, 0, 255);
    }

    public function ip(): string
    {
        return $this->ip;
    }

    public function userAgent(): string
    {
        return $this->ua;
    }

    public function traceId(): string
    {
        return $this->traceId;
    }

    public function ipHash(): string
    {
        return $this->ip === '' ? '' : $this->crypto->digest('ip:' . $this->ip);
    }

    public function uaHash(): string
    {
        return $this->ua === '' ? '' : $this->crypto->digest('ua:' . $this->ua);
    }
}
