<?php

declare(strict_types=1);

namespace App\Infrastructure\Ai;

use RuntimeException;

/**
 * AI 调用异常。
 *
 * 关键设计：`retryable` 决定调度层是「退避重试」还是「直接失败并告警」——
 * 429/5xx/超时是可重试的，400/401/402 是参数或凭据问题，重试一万次也不会变好（§7.3）。
 */
final class AiException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode,
        public readonly bool $retryable = false,
        public readonly ?int $httpCode = null,
        public readonly int $retryAfter = 0,
    ) {
        parent::__construct($message);
    }

    public static function notConfigured(): self
    {
        return new self('未配置 DeepSeek API Key（DEEPSEEK_API_KEY）', 'AI_NOT_CONFIGURED');
    }

    public static function disabled(): self
    {
        return new self('AI 总开关已关闭（AI_ENABLED=false）', 'AI_DISABLED');
    }

    public static function emptyContent(string $finishReason, int $completionTokens, int $reasoningTokens): self
    {
        return new self(
            sprintf(
                '模型返回空 content（finish_reason=%s，输出 %d tokens 其中思考 %d）',
                $finishReason,
                $completionTokens,
                $reasoningTokens
            ),
            'AI_EMPTY_CONTENT',
            true
        );
    }

    public static function budget(string $message): self
    {
        return new self($message, 'AI_BUDGET_EXCEEDED');
    }
}
