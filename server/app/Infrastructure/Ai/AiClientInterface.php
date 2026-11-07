<?php

declare(strict_types=1);

namespace App\Infrastructure\Ai;

/**
 * 模型客户端接口。
 *
 * 抽这一层不是为了将来换厂商（那属于过度设计），而是为了让**流水线能在没有网络、
 * 不产生费用**的情况下被测试——闸门、重试、幂等这些逻辑才是最容易写错的地方，
 * 它们不该依赖真实 API。
 */
interface AiClientInterface
{
    public function isConfigured(): bool;

    /**
     * @param list<array{role:string, content:string}> $messages
     * @param array<string,mixed>                      $options
     */
    public function chat(array $messages, array $options = []): AiResponse;
}
