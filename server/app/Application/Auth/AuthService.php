<?php

declare(strict_types=1);

namespace App\Application\Auth;

use App\Infrastructure\Pdo\AuditLogger;
use App\Infrastructure\Pdo\RateLimiter;
use App\Infrastructure\Pdo\SessionRepository;
use App\Infrastructure\Pdo\UserRepository;
use App\Support\Config;
use App\Support\Crypto;
use App\Support\RequestContext;
use App\Support\Session;
use App\Support\Totp;

/**
 * 后台登录。四个必须做到的点（规划文档 §11.5）：
 *   1. 不区分「用户不存在」与「密码错误」，响应文案与耗时都一致（防枚举 / 防时序）；
 *   2. 失败计数落库，达阈值锁定并指数退避（15m → 1h → 24h 的起点是 15m）；
 *   3. 登录成功必须换会话 ID（防会话固定），并在 hq_session 里留索引；
 *   4. 每次成功与失败都写审计，actor 为空表示未认证。
 */
final class AuthService
{
    /**
     * p（threads）只能是 1：线上 PHP 8.4.27 的 libargon2 编译时没开线程支持，
     * 传 threads > 1 会直接抛 ValueError（"A thread value other than 1 is not
     * supported by this implementation"），于是 password_hash() 全线失败。
     *
     * 后果不是「哈希慢一点」而是三处一起坏：
     *   · 未知用户名那条路要算一次等价哈希（dummyHash），抛错 → 登录页 500，
     *     而存在的用户回的是正常提示 —— 500 与提示就是一张账号枚举表；
     *   · user:create / user:reset-password 直接建不出账号、改不了口令；
     *   · 任何触发 rehash 的登录 500。
     *
     * 库里的老哈希是 p=2 时代生成的，换成 p=1 后 password_needs_rehash() 会判 true，
     * 各账号在下次登录成功时自动重算一次，不需要批量重置口令。
     * memory_cost 64MB / time_cost 4 / p=1 已高于 OWASP 建议值，降 p 不等于降安全。
     */
    public const ARGON_OPTIONS = ['memory_cost' => 65536, 'time_cost' => 4, 'threads' => 1];

    private const MIN_RESPONSE_MS = 250;

    public function __construct(
        private readonly UserRepository $users,
        private readonly SessionRepository $sessions,
        private readonly RateLimiter $limiter,
        private readonly AuditLogger $audit,
        private readonly Session $session,
        private readonly RequestContext $context,
        private readonly Crypto $crypto,
        private readonly Config $config,
    ) {
    }

    /**
     * @return array{ok: bool, message: string, user: array<string, mixed>|null}
     */
    public function attempt(string $username, string $password, ?string $totpCode): array
    {
        $started = microtime(true);
        $username = trim($username);

        $ip      = $this->context->ip();
        $bucket  = 'login:ip:' . $ip;
        $maxTry  = $this->config->int('security.login_max_attempts', 5);

        try {
            if ($username === '' || $password === '' || mb_strlen($username) > 64) {
                return $this->fail($started, null, '用户名或密码不正确');
            }

            if ($this->limiter->tooMany($bucket, $maxTry, 60)) {
                return $this->fail($started, null, '尝试过于频繁，请稍后再试', 'auth.rate_limited');
            }

            $user = $this->users->findByUsername($username);

            if ($user === null) {
                // 走一次等价开销的哈希校验，抹平「用户不存在」的耗时差
                password_verify($password, $this->dummyHash());
                return $this->fail($started, null, '用户名或密码不正确');
            }

            $userId = (int) $user['id'];

            if (($user['status'] ?? '') === 'disabled') {
                return $this->fail($started, $userId, '用户名或密码不正确', 'auth.disabled');
            }

            // 锁窗已经过去、但 failed_attempts / status 还留在表上的账号，在这里就地复位。
            //
            // 必须放在「计一次失败」之前：`recordFailure()` 把计数累加到阈值就置 locked +
            // locked_until，而锁定是有时效的、计数是永久的。锁窗一过计数仍 ≥ 阈值，于是
            // 用户下一次就算只错一次也会立刻再锁 15 分钟 —— 永远拿不回正常的 5 次容错。
            // 线上 admin=7、qa_viewer=9 正是这么攒上去的。复位只动 locked→active，
            // 不碰 disabled。
            if ($this->lockWindowPassed($user, $maxTry)) {
                $this->users->clearLock($userId);
                $user['status']          = 'active';
                $user['failed_attempts'] = 0;
                $user['locked_until']    = null;
            }

            if (!password_verify($password, (string) $user['password_hash'])) {
                $this->users->recordFailure($userId, $maxTry, $this->config->int('security.login_lock_minutes', 15));
                return $this->fail($started, $userId, '用户名或密码不正确', 'auth.login_failed');
            }

            // 锁定提示故意放在口令校验**之后**：
            // 口令对了才配知道「这个账号存在、而且被锁了」；口令错了与不存在的用户
            // 回的是同一句「用户名或密码不正确」。反过来写，锁定提示本身就是一张
            // 在线枚举表 —— 拿一串用户名各试几次，会回「已锁定」的那个就是真人。
            if (!empty($user['locked_until']) && strtotime((string) $user['locked_until']) > time()) {
                $minutes = max(1, (int) ceil((strtotime((string) $user['locked_until']) - time()) / 60));
                return $this->fail($started, $userId, sprintf('账号已临时锁定，请 %d 分钟后再试', $minutes), 'auth.locked');
            }

            if ((int) $user['totp_enabled'] === 1) {
                $secret = $user['totp_secret_cipher'] === null
                    ? ''
                    : $this->crypto->open((string) $user['totp_secret_cipher']);

                if ($secret === '' || !Totp::verify($secret, (string) $totpCode)) {
                    $this->users->recordFailure($userId, $maxTry, $this->config->int('security.login_lock_minutes', 15));
                    return $this->fail($started, $userId, '动态验证码不正确', 'auth.totp_failed');
                }
            } elseif ($this->config->bool('security.totp_enforce', false)) {
                return $this->fail($started, $userId, '该账号尚未绑定动态验证码，请联系管理员', 'auth.totp_missing');
            }

            if (password_needs_rehash((string) $user['password_hash'], PASSWORD_ARGON2ID, self::ARGON_OPTIONS)) {
                $this->users->updatePassword($userId, $this->hash($password));
            }

            $this->session->regenerate();
            $this->session->set('uid', $userId);
            $this->session->set('role', (string) $user['role_code']);
            $this->session->set('login_at', time());

            $this->sessions->create(
                $this->crypto->digest($this->session->id()),
                $userId,
                $this->context->ipHash() ?: null,
                $this->context->uaHash() ?: null,
                $this->config->int('session.lifetime', 1800)
            );

            $this->users->recordSuccess($userId, $this->context->ipHash());
            $this->limiter->clear($bucket);

            $this->audit->log($userId, 'auth.login', 'user', (string) $userId, null, ['username' => $username]);

            return ['ok' => true, 'message' => '', 'user' => $user];
        } finally {
            $this->pad($started);
        }
    }

    public function logout(): void
    {
        $uid = $this->session->get('uid');
        if (is_int($uid)) {
            $this->sessions->delete($this->crypto->digest($this->session->id()));
            $this->audit->log($uid, 'auth.logout', 'user', (string) $uid);
        }
        $this->session->destroy();
    }

    public function hash(string $password): string
    {
        return password_hash($password, PASSWORD_ARGON2ID, self::ARGON_OPTIONS);
    }

    /**
     * @return array{ok: bool, message: string, user: null}
     */
    private function fail(float $started, ?int $actorId, string $message, string $action = 'auth.login_failed'): array
    {
        $this->audit->log($actorId, $action, 'user', $actorId === null ? null : (string) $actorId);

        return ['ok' => false, 'message' => $message, 'user' => null];
    }

    private function pad(float $started): void
    {
        $elapsedMs = (microtime(true) - $started) * 1000;
        if ($elapsedMs < self::MIN_RESPONSE_MS) {
            usleep((int) ((self::MIN_RESPONSE_MS - $elapsedMs) * 1000));
        }
    }

    private function dummyHash(): string
    {
        static $hash = null;
        $hash ??= password_hash('hqlib-timing-equalizer', PASSWORD_ARGON2ID, self::ARGON_OPTIONS);

        return $hash;
    }

    /**
     * 「锁已经过期，但表里的痕迹还在」——需要复位。
     *
     * 仍在锁窗内的返回 false，交给上层那句「请 N 分钟后再试」继续拦。
     *
     * @param array<string, mixed> $user
     */
    private function lockWindowPassed(array $user, int $maxAttempts): bool
    {
        $locked = ($user['status'] ?? '') === 'locked'
            || (int) ($user['failed_attempts'] ?? 0) >= $maxAttempts;

        if (!$locked) {
            return false;
        }

        $until = $user['locked_until'] ?? null;

        return empty($until) || strtotime((string) $until) <= time();
    }
}
