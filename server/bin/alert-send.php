<?php

declare(strict_types=1);

/**
 * 命令行发一条告警。给 shell 脚本用 —— deploy/deploy-zha.sh 在连续拉取失败时调它。
 *
 * 为什么不直接在 shell 里 curl webhook：那样 webhook 地址会出现在脚本里、出现在
 * ps 的命令行里、出现在日志里。Alert 读的是 server/.env，shell 不需要知道地址。
 * 同时这也是唯一一处脱敏（Alert::scrub）能生效的路径。
 *
 * 用法：
 *   php bin/alert-send.php --level=error --title="部署链连续失败 3 次"
 *   php bin/alert-send.php --level=error --title="..." --context-json='{"k":"v"}'
 *
 * 退出码：
 *   0 —— 对方 2xx 收下了
 *   1 —— 发出去了但没被收下（通道配了但对方挂了）
 *   2 —— 通道没配（ALERT_WEBHOOK 为空或 ALERT_ENABLED=false），根本没发
 *
 * 2 单独分出来，是因为它和 1 是两件完全不同的事：1 是「对方病了」，2 是
 * 「我们自己没配」。混成一个码，下次看见非零退出码的人还要去翻 alert.log
 * 才知道是哪一种。调用方（deploy 脚本）把退出码原样写进日志。
 *
 * 这个脚本只做一件事，不做重试、不做兜底：告警是旁路，它自己挂了不能反过来
 * 拖住部署。Alert 内部已经保证「无论成败都落一行 alert.log」。
 */

$root = require dirname(__DIR__) . '/config/bootstrap.php';

use App\Support\Alert;

$options = [];
foreach (array_slice($argv, 1) as $arg) {
    if (!str_starts_with($arg, '--')) {
        continue;
    }
    [$k, $v] = array_pad(explode('=', substr($arg, 2), 2), 2, '');
    $options[$k] = $v;
}

$level = $options['level'] ?? 'error';
$title = trim($options['title'] ?? '');

if ($title === '') {
    fwrite(STDERR, "缺少 --title\n");
    exit(2);
}

$context = [];
if (($options['context-json'] ?? '') !== '') {
    $decoded = json_decode($options['context-json'], true);
    if (!is_array($decoded)) {
        fwrite(STDERR, "--context-json 不是合法 JSON，已忽略\n");
    } else {
        $context = $decoded;
    }
}

// 通道没配时 Alert::send() 也返回 false，与「发出去了但对方 500」不可区分。
// 所以先自己读一次配置，把这两种情况分开 —— 这是本脚本存在的第二个理由。
$config  = new App\Support\Config(require $root . '/config/settings.php');
$webhook = $config->string('alert.webhook', '');
$enabled = $config->bool('alert.enabled', false);

if ($webhook === '' || !$enabled) {
    $why = $webhook === '' ? 'ALERT_WEBHOOK 为空' : 'ALERT_ENABLED=false';
    fwrite(STDERR, "[alert-send] 通道未配置（{$why}），本条只落在 storage/logs/alert.log\n");
    Alert::send($level, $title, $context);
    exit(2);
}

exit(Alert::send($level, $title, $context) ? 0 : 1);
