<?php

declare(strict_types=1);

/**
 * 启动引导：自动加载 → 环境变量 → 时区 → 目录 → 错误策略。
 * 这个文件必须保持「无副作用地可被 CLI 与 FPM 同时引用」。
 */

use Dotenv\Dotenv;

$root = dirname(__DIR__);

require $root . '/vendor/autoload.php';

// ---- 环境变量：只写 $_ENV / $_SERVER，不 putenv（php.ini 已禁 putenv） ----
if (is_file($root . '/.env')) {
    Dotenv::createImmutable($root)->safeLoad();
}

// ---- 时区 ----
$timezone = $_ENV['APP_TIMEZONE'] ?? 'Asia/Shanghai';
if (!is_string($timezone) || !in_array($timezone, timezone_identifiers_list(), true)) {
    $timezone = 'Asia/Shanghai';
}
date_default_timezone_set($timezone);

// ---- 目录：缺了就建，权限 0750（storage 不在 Web 根，且不允许 world 读） ----
foreach (['storage/uploads', 'storage/logs', 'storage/cache', 'storage/artifacts'] as $dir) {
    $path = $root . '/' . $dir;
    if (!is_dir($path)) {
        @mkdir($path, 0750, true);
    }
}

// ---- 错误策略：错误一律转异常，异常一律进 error.log ----
error_reporting(E_ALL);
$debug = filter_var($_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOL);
ini_set('display_errors', $debug ? '1' : '0');
ini_set('log_errors', '1');

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if ((error_reporting() & $severity) === 0) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

return $root;
