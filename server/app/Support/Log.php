<?php

declare(strict_types=1);

namespace App\Support;

use Monolog\Handler\RotatingFileHandler;
use Monolog\Level;
use Monolog\Logger;
use Monolog\Processor\PsrLogMessageProcessor;
use Monolog\Processor\UidProcessor;

/**
 * 日志：按日切分，info 与 error 分流两个文件。
 * 只写相对路径与业务标识，绝不写口令、token、APP_KEK、API Key。
 */
final class Log
{
    public static function create(Config $config): Logger
    {
        $dir = $config->string('log.dir');
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }

        $level = Level::fromName(ucfirst(strtolower($config->string('log.level', 'info'))));

        $logger = new Logger('hqlib');
        $logger->pushProcessor(new UidProcessor(12));
        // 脱敏必须排在 PsrLogMessageProcessor 之后：先把占位符换成真值，再掩码
        $logger->pushProcessor(new PsrLogMessageProcessor(null, true));
        $logger->pushProcessor(new LogMaskingProcessor());
        $logger->pushHandler(new RotatingFileHandler($dir . '/app.log', 30, $level));
        $logger->pushHandler(new RotatingFileHandler($dir . '/error.log', 30, Level::Error));

        return $logger;
    }
}
