<?php

declare(strict_types=1);

namespace App\Http\Support;

use App\Infrastructure\Pdo\LibraryRepository;
use App\Support\Config;
use App\View\View;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * 前台（/library/*）的 404 / 405 页面。
 *
 * 为什么需要它：Slim 在**路由匹配失败**时就抛 HttpNotFoundException，控制器根本没被调用，
 * 于是请求落到顶层错误处理器，渲染出后台那张「返回后台」的卡片 —— 读者点「返回后台」只会
 * 撞到登录页。路由约束（作品 code 是 26 位 ULID）会拦下大量手抄错的地址，所以这条路径
 * 并不罕见，必须给前台自己的出口。
 *
 * 500 不走这里：那时数据库很可能已经不可用，再查一次馆藏统计只会把异常套异常。
 */
final class LibraryErrorPage
{
    public static function render(
        ContainerInterface $container,
        ResponseInterface $response,
        int $status,
        string $title,
        string $message,
    ): ResponseInterface {
        try {
            $library = $container->get(LibraryRepository::class);
            $config  = $container->get(Config::class);
            $view    = $container->get(View::class);

            $data = array_merge(PublicPageData::build($library, $config), [
                'page_title' => $title . ' · ' . $config->string('app.name', '银河图书馆'),
                'page_desc'  => $message,
                'noindex'    => true,
                'code'       => (string) $status,
                'message'    => $message,
                'heading'    => $title,
                // 错误页也给几条出口：按词找往往比「去看馆藏」更快
                'hot_terms'  => $library->termIndex(null, '', 8, 0),
            ]);

            return $view->render($response->withStatus($status), 'library/errors/404', $data)
                ->withHeader('X-Robots-Tag', 'noindex, nofollow');
        } catch (Throwable) {
            // 连错误页都渲染不出来：退回不依赖数据库的纯 HTML，不让用户看到白屏
            return ErrorPage::render($response, $status, $title, $message, '/library/works', '回到馆藏');
        }
    }
}
