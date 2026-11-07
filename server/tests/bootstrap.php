<?php

declare(strict_types=1);

/**
 * 测试引导：只加载自动加载器，不装配 Web 容器、不改全局错误处理器。
 * （生产 bootstrap 会把 warning 转异常，那会让 PHPUnit 自己的断言噪音变成失败。）
 */

require dirname(__DIR__) . '/vendor/autoload.php';

date_default_timezone_set('Asia/Shanghai');
