<?php

declare(strict_types=1);

namespace App\Infrastructure\Ai;

use App\Support\Config;

/**
 * DeepSeek 官方接口的最小封装（OpenAI 兼容）。
 *
 * 这里只做**一次**调用，不重试——重试涉及退避、max_tokens 抬升、降 effort 等策略，
 * 属于调度层的事（Pipeline / worker）。客户端保持「一次调用一个结果或一个异常」，
 * 才能在测试里用假客户端替身。
 *
 * 出站安全（§11.8 SSRF / §11.13）：
 *   · 解析后的 host 必须逐字命中白名单；
 *   · 禁止跟随重定向（否则 302 就能把请求带去任意地址）；
 *   · 显式置空 CURLOPT_PROXY，防止环境变量里的代理劫持出站流量；
 *   · SSL 校验强制开启。
 */
final class DeepSeekClient implements AiClientInterface
{
    public function __construct(
        private readonly Config $config,
        private readonly CostModel $costModel,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->config->string('ai.api_key') !== '';
    }

    public function assertAvailable(): void
    {
        if (!$this->config->bool('ai.enabled')) {
            throw AiException::disabled();
        }
        if (!$this->isConfigured()) {
            throw AiException::notConfigured();
        }
    }

    /**
     * 列模型（GET /models）。自检脚本用它确认「账号看得见哪些模型」，
     * 免得代码里写了一个账号根本没开通的模型名。
     *
     * @return list<array{id:string, context:int, max_output:int}>
     */
    public function models(): array
    {
        $this->assertAvailable();

        $base = rtrim($this->config->string('ai.base_url'), '/');
        $url  = $base . '/models';
        $parts = parse_url($url);
        if (strtolower((string) ($parts['host'] ?? '')) !== '' &&
            !in_array(strtolower((string) $parts['host']), $this->config->list('ai.host_allow'), true)) {
            throw new AiException('出站 host 不在白名单', 'AI_HOST_NOT_ALLOWED');
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROXY          => '',
            CURLOPT_NOPROXY        => '*',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CONNECTTIMEOUT => $this->config->int('ai.connect_timeout', 15),
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $this->config->string('ai.api_key')],
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code !== 200 || !is_string($body)) {
            throw new AiException('拉取模型列表失败（HTTP ' . $code . '）', 'AI_HTTP_' . $code, $code >= 500);
        }

        $decoded = json_decode($body, true);
        $out     = [];
        foreach ((array) ($decoded['data'] ?? []) as $row) {
            $out[] = [
                'id'         => (string) ($row['id'] ?? ''),
                'context'    => (int) ($row['context_length'] ?? $row['context_window'] ?? 0),
                'max_output' => (int) ($row['max_output_length'] ?? $row['max_tokens'] ?? 0),
            ];
        }

        return $out;
    }

    /**
     * @param list<array{role:string, content:string}> $messages
     * @param array{model?:string, temperature?:float, max_tokens?:int, thinking?:bool,
     *              effort?:?string, json?:bool, user_id?:?string} $options
     */
    public function chat(array $messages, array $options = []): AiResponse
    {
        $this->assertAvailable();

        $model = (string) ($options['model'] ?? $this->config->string('ai.model.default', 'deepseek-flash'));

        $payload = [
            'model'       => $model,
            'messages'    => $messages,
            'temperature' => (float) ($options['temperature'] ?? $this->config->get('ai.temperature', 0.2)),
            'max_tokens'  => (int) ($options['max_tokens'] ?? $this->config->int('ai.max_output', 32000)),
            'stream'      => false,
        ];

        if (($options['json'] ?? true) === true) {
            // DeepSeek 的 JSON Output：prompt 里必须出现 "json" 字样并给示例，否则会返回 400
            $payload['response_format'] = ['type' => 'json_object'];
        }

        // 思考开关：`disabled` 时 reasoning_tokens 归零；`disabled + effort` 会丢类别，故互斥
        $thinking = (bool) ($options['thinking'] ?? false);
        $payload['thinking'] = ['type' => $thinking ? 'enabled' : 'disabled'];
        if ($thinking && ($options['effort'] ?? null) !== null && $options['effort'] !== '') {
            $payload['effort'] = (string) $options['effort'];
        }

        if (($options['user_id'] ?? null) !== null && $options['user_id'] !== '') {
            // 只允许 [a-zA-Z0-9\-_] 且 ≤ 512，用于缓存隔离与调度隔离
            $payload['user_id'] = (string) preg_replace('/[^a-zA-Z0-9\-_]/', '-', (string) $options['user_id']);
        }

        $body = (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $url  = $this->endpoint();

        $startedAt = microtime(true);
        [$httpCode, $rawBody, $curlErrNo, $curlError, $respHeaders] = $this->send($url, $body);
        $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);
        $requestSha = hash('sha256', $body);

        if ($curlErrNo !== 0) {
            throw new AiException(
                sprintf('网络错误（%d）：%s', $curlErrNo, $curlError),
                'AI_NETWORK',
                true
            );
        }

        if ($httpCode !== 200) {
            throw $this->httpError($httpCode, $rawBody, $respHeaders);
        }

        $decoded = json_decode($rawBody, true);
        if (!is_array($decoded)) {
            throw new AiException('响应不是合法 JSON', 'AI_BAD_RESPONSE', true, $httpCode);
        }

        $message  = (array) ($decoded['choices'][0]['message'] ?? []);
        $usage    = (array) ($decoded['usage'] ?? []);
        $details  = (array) ($usage['completion_tokens_details'] ?? []);

        $promptTokens     = (int) ($usage['prompt_tokens'] ?? 0);
        $completionTokens = (int) ($usage['completion_tokens'] ?? 0);
        $reasoningTokens  = (int) ($details['reasoning_tokens'] ?? ($usage['reasoning_tokens'] ?? 0));

        // 命中缓存的部分：官方两种写法都存在过，按优先级取
        $cachedTokens = (int) ($usage['prompt_cache_hit_tokens'] ?? 0);
        if ($cachedTokens === 0) {
            $cachedTokens = (int) ($usage['prompt_tokens_details']['cached_tokens'] ?? 0);
        }

        return new AiResponse(
            content:          (string) ($message['content'] ?? ''),
            reasoning:        (string) ($message['reasoning_content'] ?? ''),
            finishReason:     (string) ($decoded['choices'][0]['finish_reason'] ?? ''),
            model:            (string) ($decoded['model'] ?? $model),
            requestId:        (string) ($decoded['id'] ?? ''),
            promptTokens:     $promptTokens,
            completionTokens: $completionTokens,
            cachedTokens:     $cachedTokens,
            reasoningTokens:  $reasoningTokens,
            latencyMs:        $latencyMs,
            httpCode:         $httpCode,
            requestSha:       $requestSha,
            costMicro:        $this->costModel->costMicro($model, $promptTokens, $cachedTokens, $completionTokens),
        );
    }

    private function endpoint(): string
    {
        $base = rtrim($this->config->string('ai.base_url', 'https://api.deepseek.com'), '/');
        $url  = $base . '/chat/completions';

        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host   = strtolower((string) ($parts['host'] ?? ''));

        if ($scheme !== 'https') {
            throw new AiException('出站地址必须是 https', 'AI_BAD_ENDPOINT');
        }
        if (!in_array($host, $this->config->list('ai.host_allow'), true)) {
            throw new AiException('出站 host 不在白名单：' . $host, 'AI_HOST_NOT_ALLOWED');
        }

        return $url;
    }

    /**
     * @return array{0:int, 1:string, 2:int, 3:string, 4:array<string,string>}
     */
    private function send(string $url, string $body): array
    {
        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: Bearer ' . $this->config->string('ai.api_key'),
        ];

        $respHeaders = [];
        $ch = curl_init();
        $options = [
            CURLOPT_URL            => $url,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => false,
            CURLOPT_FOLLOWLOCATION => false,          // 防 302 跳出白名单
            CURLOPT_PROXY          => '',             // 显式置空，防代理环境变量劫持
            CURLOPT_NOPROXY        => '*',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CONNECTTIMEOUT => $this->config->int('ai.connect_timeout', 15),
            CURLOPT_TIMEOUT        => $this->config->int('ai.timeout', 300),
            CURLOPT_USERAGENT      => 'huangqi-library/1.0',
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$respHeaders): int {
                $pos = strpos($line, ':');
                if ($pos !== false) {
                    $respHeaders[strtolower(trim(substr($line, 0, $pos)))] = trim(substr($line, $pos + 1));
                }
                return strlen($line);
            },
        ];

        $cafile = $this->config->string('ai.cafile');
        if ($cafile !== '' && is_file($cafile)) {
            $options[CURLOPT_CAINFO] = $cafile;
        }

        curl_setopt_array($ch, $options);

        $rawBody  = curl_exec($ch);
        $curlErrNo = curl_errno($ch);
        $curlError = curl_error($ch);
        $httpCode  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [$httpCode, is_string($rawBody) ? $rawBody : '', $curlErrNo, $curlError, $respHeaders];
    }

    /** @param array<string,string> $headers */
    private function httpError(int $httpCode, string $rawBody, array $headers): AiException
    {
        $detail = '';
        $code   = 'AI_HTTP_' . $httpCode;

        $decoded = json_decode($rawBody, true);
        if (is_array($decoded)) {
            $detail = (string) ($decoded['error']['message'] ?? $decoded['message'] ?? '');
            $apiCode = (string) ($decoded['error']['code'] ?? $decoded['error']['type'] ?? '');
            if ($apiCode !== '') {
                $code = 'AI_' . strtoupper($apiCode);
            }
        }
        if ($detail === '') {
            $detail = mb_substr($rawBody, 0, 200);
        }

        $retryAfter = (int) ($headers['retry-after'] ?? 0);

        return match (true) {
            $httpCode === 429 => new AiException('并发或频率超限（429）：' . $detail, 'AI_RATE_LIMIT', true, 429, $retryAfter),
            $httpCode >= 500  => new AiException('服务端错误（' . $httpCode . '）：' . $detail, 'AI_SERVER', true, $httpCode),
            $httpCode === 401, $httpCode === 403 => new AiException('凭据无效（' . $httpCode . '）：' . $detail, 'AI_AUTH', false, $httpCode),
            $httpCode === 402  => new AiException('账户余额不足（402）：' . $detail, 'AI_NO_BALANCE', false, 402),
            default            => new AiException('请求被拒绝（' . $httpCode . '）：' . $detail, $code, false, $httpCode),
        };
    }
}
