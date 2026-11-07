<?php

declare(strict_types=1);

namespace App\Support;

use Throwable;

/**
 * 告警通道：把「出事了」推到人能看见的地方。
 *
 * 三条设计原则，都是为了它**在出事那天真的有用**：
 *
 *  1. **绝不抛异常**。告警是旁路：网断了、webhook 挂了、对方 500 —— 业务该继续跑。
 *     一个能把业务搞挂的告警系统，比没有告警更糟。
 *  2. **无论如何都留痕**。每次调用都在 storage/logs/alert.log 落一行（含「未配置」
 *     和「发送失败」）。否则告警通道自己坏了，没人知道 —— 这是告警系统最常见的
 *     死法：看起来配了，其实半年没发出去过。
 *  3. **有冷却期**。同一个故障在 30 分钟内只发一次。健康检查每分钟一次，
 *     不去重的话一次宕机能刷出上千条，人会开始忽略它。
 *
 * 通道只认配置里的地址（ALERT_WEBHOOK），不接受任何用户输入 —— 这一点与 E-49
 * 是同一件事：出站地址必须是运维决定的，不能是请求带进来的。
 */
final class Alert
{
    /** 运行期覆盖（演练与测试用）。非 null 时优先于配置。 */
    private static ?string $override = null;

    private static ?Config $config = null;

    public static function overrideWebhook(?string $url): void
    {
        self::$override = $url;
    }

    public static function forgetConfig(): void
    {
        self::$config = null;
        self::$override = null;
    }

    /**
     * 注入配置。只给单元测试和演练用：正常路径下 Alert 自己读 config/settings.php，
     * 测试则塞一份指向临时目录的配置 —— 否则测试会往真实的 storage/logs/alert.log 里写。
     */
    public static function useConfig(?Config $config): void
    {
        self::$config = $config;
    }

    /**
     * 发一条告警。
     *
     * @param  string               $level   info / warning / error / critical
     * @param  array<string,mixed>  $context 结构化上下文（不要放口令、token、正文）
     * @return bool                 是否真的被对方 2xx 收下
     */
    public static function send(string $level, string $title, array $context = []): bool
    {
        try {
            $config   = self::config();
            $webhook  = self::$override ?? $config->string('alert.webhook', '');
            $enabled  = self::$override !== null || $config->bool('alert.enabled', false);
            $timeout  = max(1, (int) $config->get('alert.timeout', 3));
            $cooldown = max(0, (int) $config->get('alert.cooldown', 1800));

            $payload = [
                'app'     => $config->string('app.name', 'hqlib'),
                'env'     => $config->string('app.env', 'production'),
                'level'   => $level,
                'title'   => $title,
                'context' => self::scrub($context),
                'time'    => date('c'),
                'host'    => gethostname() ?: '',
            ];

            if ($webhook === '') {
                self::trace('skipped', $level, $title, '未配置 ALERT_WEBHOOK');
                return false;
            }
            if (!$enabled) {
                self::trace('skipped', $level, $title, 'ALERT_ENABLED=false');
                return false;
            }
            if ($cooldown > 0 && !self::passCooldown($level . '|' . $title, $cooldown)) {
                self::trace('suppressed', $level, $title, '冷却期内（' . $cooldown . 's）');
                return false;
            }

            [$ok, $detail] = self::post($webhook, $payload, $timeout);
            self::trace($ok ? 'sent' : 'failed', $level, $title, $detail);

            return $ok;
        } catch (Throwable $e) {
            // 连自己的 trace 都写不进去也不能抛：调用方可能正处在故障处理路径上
            if (getenv('HQ_ALERT_DEBUG') === '1') {
                fwrite(STDERR, '[alert] ' . $e::class . '：' . $e->getMessage() . "\n");
            }
            @error_log('[alert] ' . $title . ' → ' . $e->getMessage());

            return false;
        }
    }

    /** @return array{0:bool,1:string} */
    private static function post(string $url, array $payload, int $timeout): array
    {
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($body === false) {
            return [false, 'payload 无法序列化'];
        }

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $body,
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_CONNECTTIMEOUT => $timeout,
                CURLOPT_FOLLOWLOCATION => false,   // 不跟随跳转：出站地址只认配置的那一个
            ]);
            $resp = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $err  = curl_error($ch);
            curl_close($ch);

            if ($resp === false) {
                return [false, 'curl 失败：' . $err];
            }

            return [$code >= 200 && $code < 300, 'HTTP ' . $code];
        }

        $ctx = stream_context_create(['http' => [
            'method'        => 'POST',
            'header'        => "Content-Type: application/json\r\n",
            'content'       => $body,
            'timeout'       => $timeout,
            'ignore_errors' => true,
            'follow_location' => 0,
        ]]);
        $resp = @file_get_contents($url, false, $ctx);
        if ($resp === false) {
            return [false, 'stream 请求失败'];
        }
        $code = 0;
        foreach ($http_response_header ?? [] as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
                $code = (int) $m[1];
            }
        }

        return [$code >= 200 && $code < 300, 'HTTP ' . $code];
    }

    /**
     * 冷却期闸门：同一个 key 在窗口内只放行一次。
     *
     * 用文件而不是数据库：告警可能正因为数据库挂了而发，那时候写库必然失败 ——
     * 「数据库不可用」这条告警永远发不出去，是最讽刺的失败模式。
     */
    private static function passCooldown(string $key, int $seconds): bool
    {
        $file = self::config()->string('storage.cache', dirname(__DIR__, 2) . '/storage/cache')
            . '/alert-cooldown.json';

        $handle = @fopen($file, 'c+');
        if ($handle === false) {
            return true;   // 拿不到闸门就放行：宁可多响一次，不要漏
        }

        try {
            flock($handle, LOCK_EX);
            $raw  = stream_get_contents($handle) ?: '';
            $data = json_decode($raw, true);
            $data = is_array($data) ? $data : [];

            $now  = time();
            $last = (int) ($data[$key] ?? 0);
            // 顺手清理：只留最近 200 条，避免这个文件无限长
            $data = array_slice(array_filter($data, static fn ($t): bool => $now - (int) $t < 86400), -200, null, true);

            if ($now - $last < $seconds) {
                return false;
            }

            $data[$key] = $now;
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, (string) json_encode($data));

            return true;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /** 每次调用都落一行，无论发没发出去 */
    private static function trace(string $result, string $level, string $title, string $detail): void
    {
        $dir = self::config()->string('storage.logs', dirname(__DIR__, 2) . '/storage/logs');
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        @file_put_contents(
            $dir . '/alert.log',
            sprintf("%s\t%s\t%s\t%s\t%s\n", date('c'), $result, $level, $title, $detail),
            FILE_APPEND | LOCK_EX
        );
    }

    /**
     * 告警会离开这台机器（推到邮件 / 群机器人 / 值班手机），所以 context 在出门前
     * 必须再脱一次敏。日志那层有 LogMaskingProcessor，但那条路只写本地文件 ——
     * 一个把 API Key 推到群里的告警系统，等于自己制造泄漏。
     *
     * @param  array<mixed> $context
     * @return array<mixed>
     */
    private static function scrub(array $context): array
    {
        $walk = static function (mixed $value) use (&$walk): mixed {
            if (is_array($value)) {
                return array_map($walk, $value);
            }
            if (!is_string($value)) {
                return $value;
            }

            $rules = [
                '/\bsk-[A-Za-z0-9_\-]{8,}/'                              => 'sk-***',
                '/\bBearer\s+[A-Za-z0-9._\-]{8,}/i'                      => 'Bearer ***',
                '/("?(?:password|passwd|token|secret|api_?key|authorization)"?\s*[:=]\s*)"[^"]*"/i' => '$1"***"',
                '/\b1[3-9]\d{9}\b/'                                      => '1**\*\*\*\*\*\*\*',
                '/\b([A-Za-z0-9._%+\-])[A-Za-z0-9._%+\-]*(@[A-Za-z0-9.\-]+\.[A-Za-z]{2,})/'      => '$1***$2',
            ];

            return (string) preg_replace(array_keys($rules), array_values($rules), $value);
        };

        return (array) $walk($context);
    }

    private static function config(): Config
    {
        if (self::$config === null) {
            self::$config = new Config(require dirname(__DIR__, 2) . '/config/settings.php');
        }

        return self::$config;
    }
}
