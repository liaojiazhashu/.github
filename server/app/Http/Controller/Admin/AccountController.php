<?php

declare(strict_types=1);

namespace App\Http\Controller\Admin;

use App\Application\Auth\AuthService;
use App\Infrastructure\Pdo\AuditLogger;
use App\Infrastructure\Pdo\UserRepository;
use App\Support\Session;
use App\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * 自己的账号：改口令。
 *
 * 只做「改自己的」，不做「管别人」—— 后者属于 system.user 权限，是另一件事，
 * 而且在一套只有几个运营账号的系统里，加人减人用命令行更可控（有记录、可复核）。
 *
 * 三条规矩：
 *   · 改口令必须验旧口令：否则借到一台没锁屏的机器就能把真正的主人锁在外面；
 *   · 新口令有长度下限（与 user:create 一致），并且不接受「与原口令相同」；
 *   · 改完写审计。口令本身绝不进审计，只记「改了」。
 */
final class AccountController extends AdminController
{
    private const MIN_LENGTH = 12;

    public function __construct(
        View $view,
        Session $session,
        private readonly AuthService $auth,
        private readonly UserRepository $users,
        private readonly AuditLogger $audit,
    ) {
        parent::__construct($view, $session);
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = (array) $request->getAttribute('user', []);

        return $this->render($response, 'admin/account/index', [
            'nav'     => 'account',
            'user'    => $user,
            'error'   => null,
            'minLen'  => self::MIN_LENGTH,
        ]);
    }

    public function password(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = (array) $request->getAttribute('user', []);
        $body = (array) ($request->getParsedBody() ?? []);

        $current = is_string($body['current'] ?? null) ? $body['current'] : '';
        $next    = is_string($body['next'] ?? null) ? $body['next'] : '';
        $repeat  = is_string($body['repeat'] ?? null) ? $body['repeat'] : '';

        $error = $this->validate($user, $current, $next, $repeat);

        if ($error !== null) {
            return $this->render($response, 'admin/account/index', [
                'nav'    => 'account',
                'user'   => $user,
                'error'  => $error,
                'minLen' => self::MIN_LENGTH,
            ], 422);
        }

        $this->users->updatePassword((int) $user['id'], $this->auth->hash($next));
        $this->audit->log((int) $user['id'], 'user.password_change', 'user', (string) $user['id']);

        $this->session->flash('ok', '口令已更新。下次登录请用新口令。');

        return $this->redirect($response, '/admin/account');
    }

    /** @param array<string,mixed> $user */
    private function validate(array $user, string $current, string $next, string $repeat): ?string
    {
        if ($current === '' || $next === '') {
            return '三项都要填。';
        }

        if (!password_verify($current, (string) ($user['password_hash'] ?? ''))) {
            return '当前口令不正确。';
        }

        if ($next !== $repeat) {
            return '两次输入的新口令不一致。';
        }

        if (mb_strlen($next) < self::MIN_LENGTH) {
            return sprintf('新口令至少 %d 位。', self::MIN_LENGTH);
        }

        if ($next === $current) {
            return '新口令不能和当前口令相同。';
        }

        return null;
    }
}
