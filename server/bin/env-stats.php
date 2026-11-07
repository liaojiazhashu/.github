<?php

declare(strict_types=1);

/**
 * 环境自检用的队列快照。
 *
 * 只输出 ASCII，避免 PowerShell 按 ANSI 解码 UTF-8 时出现乱码。
 * 用法：php -c php.ini bin/env-stats.php
 */

require dirname(__DIR__) . '/config/bootstrap.php';

use App\Support\Config;
use App\Support\Db;

try {
    $config = new Config(require dirname(__DIR__) . '/config/settings.php');
    $pdo    = Db::connect($config);

    $job = $pdo->query(
        "SELECT
            COALESCE(SUM(status = 'queued'), 0)    AS q,
            COALESCE(SUM(status = 'running'), 0)   AS r,
            COALESCE(SUM(status = 'succeeded'), 0) AS s,
            COALESCE(SUM(status = 'failed'), 0)    AS f
           FROM hq_job WHERE type = 'analysis.run'"
    )->fetch(PDO::FETCH_ASSOC);

    $work = $pdo->query(
        "SELECT
            COALESCE(SUM(status = 'published'), 0) AS p,
            COALESCE(SUM(status = 'analyzed'), 0)  AS a,
            COALESCE(SUM(status = 'imported'), 0)  AS i
           FROM hq_work"
    )->fetch(PDO::FETCH_ASSOC);

    printf(
        "queue: %d queued / %d running / %d done / %d failed\n",
        (int) $job['q'],
        (int) $job['r'],
        (int) $job['s'],
        (int) $job['f']
    );
    printf(
        "works: %d published / %d analyzed / %d pending\n",
        (int) $work['p'],
        (int) $work['a'],
        (int) $work['i']
    );
} catch (Throwable $e) {
    // 数据库未就绪是正常情况（开机初期），交给外层判读
    fwrite(STDOUT, "unavailable\n");
    exit(3);
}
