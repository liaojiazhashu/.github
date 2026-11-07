<?php

declare(strict_types=1);

use Slim\Factory\AppFactory;

/**
 * 唯一入口。
 *
 * 三条约束：
 *   1. 这个文件只做装配，不写业务；
 *   2. public/ 是唯一 Web 根，其它目录（app / config / database / storage / vendor）都不对外；
 *   3. 内置服务器（php -S）下把静态文件还给内置服务器，其余全部进 Slim。
 */

// PHP 内置服务器：存在的静态文件直接由它返回（生产由 Nginx 的 try_files 承担同样职责）
if (PHP_SAPI === 'cli-server') {
    $uri = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
    if ($uri !== '/' && is_file(__DIR__ . $uri)) {
        return false;
    }
}

/** @var \Psr\Container\ContainerInterface $container */
$container = require dirname(__DIR__) . '/config/container.php';

$root = dirname(__DIR__);

$app = AppFactory::createFromContainer($container);

(require $root . '/config/middleware.php')($app, $container);
(require $root . '/config/routes.php')($app);

$app->run();
