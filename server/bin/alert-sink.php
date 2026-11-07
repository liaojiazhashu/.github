<?php

declare(strict_types=1);

/**
 * 一次性告警 sink：给 bin/alert-drill.php 当接收端，验证「消息真的出得去」。
 *
 * 为什么单独一个文件而不是让 drill 自己 fork 一个：项目禁用 proc_open（E-50），
 * 而这条禁令是对的 —— 应用代码里不该有起子进程的能力。宁可让演练多一步手工命令，
 * 也不为了省这一步去开一个豁免口子。豁免口子开一次，第二次就没人记得为什么开。
 *
 * 用法（两个终端，或后台起第一个）：
 *   php -S 127.0.0.1:18099 bin/alert-sink.php
 *   php bin/alert-drill.php --sink=http://127.0.0.1:18099/alert
 *
 * 收到的请求体会追加到环境变量 HQ_ALERT_SINK_LOG 指定的文件，
 * 默认 storage/reports/alert-sink.log。这个文件里**应当**只有掩码后的内容 ——
 * 如果能看到明文 Key，说明 Alert 的脱敏坏了。
 */

$log = getenv('HQ_ALERT_SINK_LOG');
if ($log === false || $log === '') {
    $log = dirname(__DIR__) . '/storage/reports/alert-sink.log';
}

$body = (string) file_get_contents('php://input');

if ($body !== '') {
    @file_put_contents($log, $body . "\n", FILE_APPEND | LOCK_EX);
}

header('Content-Type: application/json; charset=utf-8');
http_response_code(200);
echo '{"ok":true}';

return true;
