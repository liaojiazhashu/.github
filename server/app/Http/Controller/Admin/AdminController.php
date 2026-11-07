<?php

declare(strict_types=1);

namespace App\Http\Controller\Admin;

use App\Support\Session;
use App\View\View;
use Psr\Http\Message\ResponseInterface;

/**
 * 后台控制器基类：统一注入模板公共数据（flash / csrf），避免每个动作各写一遍。
 */
abstract class AdminController
{
    public function __construct(
        protected readonly View $view,
        protected readonly Session $session,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    protected function render(ResponseInterface $response, string $template, array $data = [], int $status = 200): ResponseInterface
    {
        $payload = array_merge([
            'flashes'  => $this->session->pullFlashes(),
            'csrf'     => $this->session->csrfToken(),
            'nav'      => '',
        ], $data);

        return $this->view->render($response->withStatus($status), $template, $payload);
    }

    protected function redirect(ResponseInterface $response, string $location, int $status = 303): ResponseInterface
    {
        return $response->withStatus($status)->withHeader('Location', $location);
    }
}
