<?php

declare(strict_types=1);

namespace App\Http\Controller\Admin;

use App\Application\Auth\AuthService;
use App\Support\Session;
use App\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * 登录 / 登出。
 *
 * 表单回显只回显用户名，**绝不回显密码**；错误信息统一为「用户名或密码不正确」，
 * 具体原因只进审计与日志。
 */
final class AuthController extends AdminController
{
    public function __construct(
        View $view,
        Session $session,
        private readonly AuthService $auth,
    ) {
        parent::__construct($view, $session);
    }

    public function showLogin(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if (is_int($this->session->get('uid'))) {
            return $this->redirect($response, '/admin', 302);
        }

        return $this->render($response, 'admin/login', [
            'expired'  => $request->getQueryParams()['expired'] ?? null,
            'username' => '',
            'error'    => null,
        ]);
    }

    public function login(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body     = (array) ($request->getParsedBody() ?? []);
        $username = is_string($body['username'] ?? null) ? $body['username'] : '';
        $password = is_string($body['password'] ?? null) ? $body['password'] : '';
        $totp     = is_string($body['totp'] ?? null) ? $body['totp'] : null;

        $result = $this->auth->attempt($username, $password, $totp);

        if ($result['ok']) {
            // 这里曾经 flash 一条「已登录」。它占掉每页首屏 40px 的通栏，
            // 说的是人刚做完的事 —— 零信息量，还正好压住「今天要处理什么」。
            // 登录后落到概览，那儿的第一行本来就会回答状态，不必再说一遍。

            return $this->redirect($response, '/admin');
        }

        return $this->render($response, 'admin/login', [
            'expired'  => null,
            'username' => $username,
            'error'    => $result['message'],
        ], 422);
    }

    public function logout(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->auth->logout();

        return $this->redirect($response, '/admin/login');
    }
}
