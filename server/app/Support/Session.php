<?php

declare(strict_types=1);

namespace App\Support;

/**
 * 会话封装：PHP 原生 session 存数据，hq_session 表存索引（见 SessionRepository）。
 *
 * 为什么两套都要：原生 session 只解决「浏览器带着 sid 回来时数据在哪」，
 * 解决不了「这个人在几台设备上登录着、要不要现在踢掉」。后者需要可查询的索引表。
 */
final class Session
{
    private bool $started = false;

    public function __construct(private readonly Config $config)
    {
    }

    public function start(): void
    {
        if ($this->started || PHP_SAPI === 'cli') {
            $this->started = true;
            return;
        }

        $lifetime = max(300, $this->config->int('session.lifetime', 1800));

        session_name($this->config->string('session.name', 'hq_admin'));
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'domain'   => '',
            'secure'   => $this->config->bool('session.secure', false),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        ini_set('session.gc_maxlifetime', (string) $lifetime);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');

        session_start();
        $this->started = true;

        // 空闲超时：超过 lifetime 没动作就作废（服务端判定，不依赖 cookie 过期）
        $now  = time();
        $last = (int) ($_SESSION['_seen_at'] ?? 0);
        if ($last > 0 && $now - $last > $lifetime) {
            $this->destroy();
            session_start();
            $_SESSION['_expired'] = true;
        }
        $_SESSION['_seen_at'] = $now;
    }

    public function id(): string
    {
        return session_id();
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public function forget(string $key): void
    {
        unset($_SESSION[$key]);
    }

    public function regenerate(): void
    {
        if (!$this->started) {
            $this->start();
        }
        session_regenerate_id(true);
    }

    public function destroy(): void
    {
        if (PHP_SAPI === 'cli') {
            return;
        }
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires'  => time() - 42000,
                'path'     => $params['path'],
                'domain'   => $params['domain'],
                'secure'   => (bool) $params['secure'],
                'httponly' => (bool) $params['httponly'],
                'samesite' => $params['samesite'] ?: 'Lax',
            ]);
        }
        session_destroy();
        $this->started = false;
    }

    /** CSRF：每会话一个令牌，表单 hidden 携带，服务端常量时间比较。 */
    public function csrfToken(): string
    {
        $token = $this->get('_csrf');
        if (!is_string($token) || strlen($token) < 32) {
            $token = bin2hex(random_bytes(32));
            $this->set('_csrf', $token);
        }
        return $token;
    }

    public function checkCsrf(?string $candidate): bool
    {
        $token = $this->get('_csrf');
        if (!is_string($token) || $candidate === null || $candidate === '') {
            return false;
        }
        return hash_equals($token, $candidate);
    }

    /**
     * 一次性提示消息。用「先取出后写入」的读删语义，避免读多遍。
     *
     * @return list<array{level: string, text: string}>
     */
    public function pullFlashes(): array
    {
        $flashes = $this->get('_flash', []);
        $this->forget('_flash');
        return is_array($flashes) ? $flashes : [];
    }

    public function flash(string $level, string $text): void
    {
        $flashes   = $this->get('_flash', []);
        $flashes   = is_array($flashes) ? $flashes : [];
        $flashes[] = ['level' => $level, 'text' => $text];
        $this->set('_flash', $flashes);
    }
}
