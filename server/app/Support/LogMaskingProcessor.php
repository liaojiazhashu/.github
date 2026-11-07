<?php

declare(strict_types=1);

namespace App\Support;

use Monolog\LogRecord;

/**
 * 日志脱敏（附录 E-54）。
 *
 * 光靠「约定不写敏感字段」不够 —— 一次性调错参数、把整个请求体丢进 context，
 * 就会把手机号、邮箱、API Key 写进日志文件，而日志文件活 30 天。
 *
 * 所以这里做的是**最后一道**：消息正文与 context 一起过一遍正则，
 * 认得的敏感形状一律替换成掩码。它不认识的东西（比如自造格式的 ID）仍然可能漏，
 * 所以真正的第一道防线是「不往日志里放」，这一层只是兜底。
 */
final class LogMaskingProcessor
{
    /** context 里出现这些键名，值直接变 ***，不看内容 */
    private const SENSITIVE_KEYS = [
        'password', 'passwd', 'pwd', 'token', 'csrf', 'secret', 'api_key', 'apikey',
        'authorization', 'cookie', 'kek', 'contact', 'phone', 'email', 'totp',
    ];

    public function __invoke(LogRecord $record): LogRecord
    {
        $message = self::mask($record->message);
        $context = self::maskArray($record->context);
        $extra   = self::maskArray($record->extra);

        return $record->with(message: $message, context: $context, extra: $extra);
    }

    public static function mask(string $text): string
    {
        // 1) API Key：sk-xxxx
        $text = (string) preg_replace('/sk-[A-Za-z0-9_\-]{8,}/', 'sk-***', $text);

        // 2) 邮箱：保留首字母与域名，中间打码
        $text = (string) preg_replace_callback(
            '/([A-Za-z0-9._%+\-])[A-Za-z0-9._%+\-]*(@[A-Za-z0-9.\-]+\.[A-Za-z]{2,})/',
            static fn (array $m): string => $m[1] . '***' . $m[2],
            $text
        );

        // 3) 中国大陆手机号：前 3 后 4，中间打码（11 位，且不能是长数字的一部分）
        $text = (string) preg_replace('/(?<!\d)(1[3-9]\d)\d{4}(\d{4})(?!\d)/', '$1****$2', $text);

        // 4) 常见凭据键值对：password=xxx&token=yyy 与 JSON 里的 "password":"xxx"
        //    注意不把 authorization 放进来：它的值里可能有空格（Bearer xxx），
        //    交给下一条整行规则处理才不会只吃掉「Bearer」把 token 留在原地
        $text = (string) preg_replace(
            '/(password|passwd|pwd|token|secret|apikey|api_key)(["\']?\s*[:=]\s*["\']?)([^&\s,"\'}]{1,128})/i',
            '$1$2***',
            $text
        );

        // 5) Authorization 头：整行余下部分全部抹掉（Bearer <token> 中间有空格，
        //    只抹到第一个空格会把 token 留在后面）
        $text = (string) preg_replace('/(authorization\s*:\s*)[^\r\n]+/i', '$1***', $text);

        // 6) 单独出现的 Bearer 头（没有 Authorization 前缀时）
        $text = (string) preg_replace('/Bearer\s+[A-Za-z0-9._\-]+/i', 'Bearer ***', $text);

        return $text;
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private static function maskArray(array $data): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            $keyLower = strtolower((string) $key);

            $hit = false;
            foreach (self::SENSITIVE_KEYS as $needle) {
                if (str_contains($keyLower, $needle)) {
                    $hit = true;
                    break;
                }
            }

            if ($hit && $value !== null && $value !== '') {
                $out[$key] = '***';
                continue;
            }

            if (is_string($value)) {
                $out[$key] = self::mask($value);
                continue;
            }
            if (is_array($value)) {
                $out[$key] = self::maskArray($value);
                continue;
            }

            $out[$key] = $value;
        }

        return $out;
    }
}
