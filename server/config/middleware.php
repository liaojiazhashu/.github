<?php

declare(strict_types=1);

use App\Http\Middleware\CsrfMiddleware;
use App\Http\Middleware\SecurityHeadersMiddleware;
use App\Http\Middleware\SessionMiddleware;
use App\Http\Middleware\WriteRateLimitMiddleware;
use App\Http\Support\ErrorPage;
use App\Http\Support\LibraryErrorPage;
use App\Support\Config;
use App\Support\RequestContext;
use App\Support\Session;
use Monolog\Logger;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;
use Slim\Exception\HttpMethodNotAllowedException;
use Slim\Exception\HttpNotFoundException;
use Slim\Psr7\Factory\ResponseFactory;

/**
 * 中间件的装配顺序（由内到外，Slim 的 add() 是 LIFO）：
 *   路由 → BodyParsing → 写入限流 → CSRF → 会话 → 安全头 → 错误处理
 *
 * 「先路由还是先限流」是个真实取舍：先路由就能按路由名计数，
 * 但未匹配的请求就绕过限流。本系统把限流放在路由外层，宁可多计数，也不留刷接口的空档。
 */
return static function (App $app, \Psr\Container\ContainerInterface $container): void {
    $config = $container->get(Config::class);
    $logger = $container->get(Logger::class);

    $app->addRoutingMiddleware();
    $app->addBodyParsingMiddleware();

    // 这一行会在装配期构造中间件，所以它的构造函数**不许碰数据库**。
    // 限流器改成了请求期才取（取不到就放行），见 WriteRateLimitMiddleware 的类注释：
    // 2026-11-03 之前它是构造注入 RateLimiter，导致数据库一挂全站在装配期就崩，
    // 连 /healthz 都只剩一个 0 字节的 500。
    $app->add($container->get(WriteRateLimitMiddleware::class));
    $app->add($container->get(CsrfMiddleware::class));

    $app->add(new SessionMiddleware(
        $container->get(Session::class),
        $container->get(RequestContext::class)
    ));

    $app->add($container->get(SecurityHeadersMiddleware::class));

    $errorMiddleware = $app->addErrorMiddleware(
        $config->bool('app.debug', false),
        true,
        $config->bool('app.debug', false)
    );

    $errorMiddleware->setDefaultErrorHandler(
        function (
            ServerRequestInterface $request,
            Throwable $exception,
            bool $displayErrorDetails
        ) use ($config, $logger, $container): \Psr\Http\Message\ResponseInterface {
            $response = (new ResponseFactory())->createResponse();
            $path     = $request->getUri()->getPath();
            $isPublic = str_starts_with($path, '/library');

            if ($exception instanceof HttpNotFoundException) {
                return $isPublic
                    ? LibraryErrorPage::render($container, $response, 404, '没有这一页', '地址可能有误，或这条记录已被移除。')
                    : ErrorPage::render($response, 404, '页面不存在', '地址可能有误，或这条记录已被移除。');
            }
            if ($exception instanceof HttpMethodNotAllowedException) {
                return $isPublic
                    ? LibraryErrorPage::render($container, $response, 405, '没有这一页', '这个地址不支持这种请求方式。')
                    : ErrorPage::render($response, 405, '请求方式不被允许', '请从页面上的表单发起操作。');
            }

            $logger->error('unhandled', [
                'path'  => $path,
                'type'  => $exception::class,
                'msg'   => $exception->getMessage(),
                'file'  => $exception->getFile() . ':' . $exception->getLine(),
                'trace' => $displayErrorDetails ? $exception->getTraceAsString() : null,
            ]);

            if (str_starts_with($path, '/healthz')) {
                $response->getBody()->write((string) json_encode(
                    ['status' => 'error', 'message' => 'internal error'],
                    JSON_UNESCAPED_UNICODE
                ));

                return $response->withStatus(500)->withHeader('Content-Type', 'application/json; charset=utf-8');
            }

            $message = $displayErrorDetails
                ? $exception::class . '：' . $exception->getMessage()
                : '服务器处理这个请求时出错了。已记录到日志，请稍后重试或联系管理员。';

            // 500 不进 LibraryErrorPage：那个渲染器要查馆藏统计才能出页面，
            // 而走到 500 时数据库本身很可能就是挂着的那一个 —— 再查一次只是
            // 异常套异常（见 LibraryErrorPage 类注释里那句「500 不走这里」）。
            // 但**出口**要按前台/后台给对：读者拿到「返回后台」只会撞到登录页，
            // 与 404 那条路径修过的是同一个毛病。这里只换链接，不多访问一次数据库。
            return $isPublic
                ? ErrorPage::render($response, 500, '出了点问题', $message, '/library/works', '回到馆藏')
                : ErrorPage::render($response, 500, '出了点问题', $message);
        }
    );
};
