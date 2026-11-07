<?php

declare(strict_types=1);

namespace App\Infrastructure\Ai;

/**
 * 一次模型调用的结果。所有字段都可直接落 hq_ai_call，不做二次加工。
 */
final class AiResponse
{
    public function __construct(
        public readonly string $content,
        public readonly string $reasoning,
        public readonly string $finishReason,
        public readonly string $model,
        public readonly string $requestId,
        public readonly int $promptTokens,
        public readonly int $completionTokens,
        public readonly int $cachedTokens,
        public readonly int $reasoningTokens,
        public readonly int $latencyMs,
        public readonly int $httpCode,
        public readonly string $requestSha,
        public readonly int $costMicro,
    ) {
    }

    /**
     * content 是否可用于后续处理。
     *
     * 空 content 有两种成因，处置完全不同（§7.2）：
     *   · finish_reason=length  → 思考 token 吃光额度，加 max_tokens 重试；
     *   · finish_reason=stop    → 服务端真空返回，降温度 + 追加「必须输出 JSON」重试。
     */
    public function isEmpty(): bool
    {
        return trim($this->content) === '';
    }

    public function exhaustedByLength(): bool
    {
        return $this->isEmpty() && $this->finishReason === 'length';
    }

    /**
     * 解析 content 为数组；失败抛不可重试的异常，由调用方决定是否走「修复提示」。
     *
     * @return array<string, mixed>
     */
    public function json(): array
    {
        $content = trim($this->content);

        // 模型偶尔会把 JSON 包进 ```json 代码块，虽然 prompt 里禁止了，但没必要为此失败
        if (str_starts_with($content, '```')) {
            $content = (string) preg_replace('/^```[a-zA-Z]*\s*|\s*```$/', '', $content);
            $content = trim($content);
        }

        $decoded = json_decode($content, true);
        if (!is_array($decoded)) {
            throw new AiException(
                '模型输出不是合法 JSON：' . json_last_error_msg(),
                'AI_INVALID_JSON',
                false,
                $this->httpCode
            );
        }

        return $decoded;
    }

    /** @return array<string, mixed> 供 hq_ai_call 入库 */
    public function toCallRow(string $stageCode, string $promptVersion): array
    {
        return [
            'stage_code'        => $stageCode,
            'model'             => $this->model,
            'prompt_version'    => $promptVersion,
            'request_sha'       => $this->requestSha,
            'request_id'        => $this->requestId !== '' ? $this->requestId : null,
            'http_code'         => $this->httpCode,
            'status'            => $this->isEmpty() ? 'error' : 'ok',
            'prompt_tokens'     => $this->promptTokens,
            'completion_tokens' => $this->completionTokens,
            'cached_tokens'     => $this->cachedTokens,
            'latency_ms'        => $this->latencyMs,
            'cost_micro'        => $this->costMicro,
        ];
    }
}
