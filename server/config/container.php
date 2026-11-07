<?php

declare(strict_types=1);

use DI\ContainerBuilder;
use Psr\Container\ContainerInterface;

/**
 * 容器装配。Web 入口与 CLI（worker / analyze）共用同一份定义。
 *
 * 抽出来的原因：以前 Web 与 CLI 各自装配，两边的绑定很容易走偏（比如 Web 里换了实现，
 * CLI 里还是旧的），而这类偏差只会在线上跑出「命令行能跑、网页报错」时才暴露。
 */

require_once __DIR__ . '/bootstrap.php';

$builder = new ContainerBuilder();
$builder->addDefinitions(__DIR__ . '/dependencies.php');

return $builder->build();
