<?php

declare(strict_types=1);

namespace App\Http\Controller\Admin;

use App\Application\Analysis\ProgressSnapshot;
use App\Support\Session;
use App\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * 拆解实况：一个自己会动的页面。
 *
 * 与「拆解调度」的分工：
 *   · 调度台是**控制面** —— 开始、暂停、重试，动作都在那里，看一次就够了；
 *   · 实况是**仪表盘** —— 只读，但必须一直在动。批跑一跑十几个小时，运营不会为了
 *     看「还剩多少、还要多久、还要多少钱」去按 F5 按一下午。
 *
 * 实现上刻意不用 websocket / SSE：PHP 内置服务器是单进程的，长连接会把它整条占住，
 * 后台别的页面就转不动了。5 秒一次的短轮询对这点数据量（7 条聚合查询）没有压力，
 * 也天然能在断线后自愈。
 *
 * 页面首屏由 Twig 直出（JS 没跑起来也看得见数），之后交给 progress.js 增量更新。
 */
final class ProgressController extends AdminController
{
    public function __construct(
        View $view,
        Session $session,
        private readonly ProgressSnapshot $snapshot,
    ) {
        parent::__construct($view, $session);
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $data = $this->snapshot->build();

        return $this->render($response, 'admin/progress/index', [
            'nav'      => 'progress',
            'user'     => (array) $request->getAttribute('user', []),
            'snapshot' => $data,
            // 轮询间隔放在模板里注入，改节奏不用碰 JS
            'pollMs'   => 5000,
        ]);
    }

    /**
     * 轮询端点。返回结构与首屏完全一致 —— 前端拿到什么就能直接覆盖什么。
     */
    public function data(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $json = json_encode(
            $this->snapshot->build(),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR
        );

        $response->getBody()->write($json === false ? '{}' : $json);

        // 后台整体已是 no-store，这里再显式写一次：这个端点的全部意义就是「不许缓存」
        return $response
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withHeader('Cache-Control', 'no-store, no-cache, must-revalidate');
    }
}
