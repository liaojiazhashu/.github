<?php

declare(strict_types=1);

/**
 * 告警通道演练（附录 E-67）：证明「出事时那条消息真的能送到人手边」。
 *
 * 为什么不能只测「Alert::send() 返回 true」：那可能是 curl 骗了你。只有把请求真的
 * 落到另一个进程写的服务器上，再从服务器那一侧读回来，才算证明了通道可用。
 * 接收端是 bin/alert-sink.php（`php -S 127.0.0.1:18099 bin/alert-sink.php`）。
 *
 * 为什么不自己起接收端：项目禁了 proc_open（E-50），这条禁令本身是对的 ——
 * 应用代码不该有起子进程的能力。演练多敲一条命令，换掉一个永久豁免口子，划算。
 *
 * 四条断言，缺一条都不算通过：
 *   1. 收到请求，且 level / title / context 正确；
 *   2. **脱敏生效**：context 里的假 API Key、手机号、邮箱必须已经是掩码 ——
 *      告警会离开这台机器，把 Key 推到群里等于自己制造泄漏；
 *   3. 冷却期生效：同一个标题连发两次，第二次不发、接收端也没有新增一条。
 *      健康检查每分钟一次，不去重的话一次宕机能刷出上千条，人就会开始忽略它；
 *   4. 对方不可达时不抛异常。告警是旁路：通道挂了，业务照跑。
 *
 * 用法：
 *   :: 终端 A
 *   D:\webserver\php\php.exe -S 127.0.0.1:18099 bin\alert-sink.php
 *   :: 终端 B
 *   D:\webserver\php\php.exe bin\alert-drill.php --sink=http://127.0.0.1:18099/alert
 *
 * 退出码：0 全部通过；1 有断言失败；2 接收端不可达（忘了起 sink）。
 */

$root = require dirname(__DIR__) . '/config/bootstrap.php';

use App\Support\Alert;

$options = [];
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--')) {
        [$k, $v] = array_pad(explode('=', substr($arg, 2), 2), 2, '');
        $options[$k] = $v;
    }
}

$sinkUrl = $options['sink'] ?? 'http://127.0.0.1:18099/alert';
$sinkLog = getenv('HQ_ALERT_SINK_LOG') ?: ($root . '/storage/reports/alert-sink.log');

// 接收端在不在？不在就直接说清楚，别让人对着「send() 返回 false」猜。
$probeHost = (string) parse_url($sinkUrl, PHP_URL_HOST);
$probePort = (int) (parse_url($sinkUrl, PHP_URL_PORT) ?: 80);
$probe     = @fsockopen($probeHost, $probePort, $errno, $errstr, 1.0);
if ($probe === false) {
    fwrite(STDERR, "[!] 接收端不可达 {$sinkUrl}\n");
    fwrite(STDERR, "    先另开一个终端跑：\n");
    fwrite(STDERR, "    D:\\webserver\\php\\php.exe -S {$probeHost}:{$probePort} bin\\alert-sink.php\n");
    exit(2);
}
fclose($probe);

@unlink($sinkLog);

$steps = [];
$fail  = 0;

$step = static function (string $name, bool $ok, string $evidence) use (&$steps, &$fail): void {
    if (!$ok) {
        $fail++;
    }
    $steps[] = ['name' => $name, 'ok' => $ok, 'evidence' => $evidence];
    printf("%s %s\n    %s\n", $ok ? '[OK  ]' : '[FAIL]', $name, $evidence);
};

$lines = static function () use ($sinkLog): int {
    return is_file($sinkLog) ? substr_count((string) file_get_contents($sinkLog), "\n") : 0;
};

$started = microtime(true);

// 标题带上本次时间戳：否则上一次演练留下的冷却记录会把这一次直接吞掉
// （第一次踩到的坑：send() 返回 false，而真正原因是 30 分钟内发过同标题）。
$title     = 'drill: 通道自检 ' . date('His');
$failTitle = 'drill: 宕机路径 ' . date('His');

// 只读本次新增的那段 alert.log：老记录里也有 sent，用整份文件做断言会假通过
$traceFile = $root . '/storage/logs/alert.log';
$traceFrom = is_file($traceFile) ? (int) filesize($traceFile) : 0;

Alert::forgetConfig();
Alert::overrideWebhook($sinkUrl);

// ---- 1) 真的发出去，且对方收到了 -------------------------------------------
$secret = 'sk-drill0123456789abcdef';
$sent   = Alert::send('error', $title, [
    'work'    => 'drill',
    'api_key' => $secret,
    'phone'   => '13800001111',
    'email'   => 'reader@example.com',
    'note'    => '这条是演练，不是真事故',
]);

$received = is_file($sinkLog) ? trim((string) file_get_contents($sinkLog)) : '';
$payload  = $received === '' ? null : json_decode($received, true);

$step(
    '请求抵达接收端且被 2xx 收下',
    $sent === true && is_array($payload),
    $sent === true
        ? 'send() = true，接收端收到 ' . strlen($received) . ' 字节'
        : 'send() 返回 false（对方没收到或非 2xx）'
);

// ---- 2) 报文结构 -----------------------------------------------------------
$shapeOk = is_array($payload)
    && ($payload['level'] ?? '') === 'error'
    && ($payload['title'] ?? '') === $title
    && isset($payload['context']['note'])
    && ($payload['app'] ?? '') !== '';

$step(
    '报文结构正确',
    $shapeOk,
    is_array($payload)
        ? 'level/title/context/app 齐全；app=' . ($payload['app'] ?? '?') . '，env=' . ($payload['env'] ?? '?')
        : '报文不是 JSON'
);

// ---- 3) 脱敏 --------------------------------------------------------------
$leak = [];
foreach ([$secret, '13800001111', 'reader@example.com'] as $needle) {
    if (str_contains($received, $needle)) {
        $leak[] = $needle;
    }
}

$step(
    '敏感字段出门前已脱敏',
    $leak === [],
    $leak === [] ? 'Key / 手机号 / 邮箱 在报文中均已掩码' : '仍可见明文：' . implode('、', $leak)
);

// ---- 4) 冷却期 ------------------------------------------------------------
$before = $lines();
$sent2  = Alert::send('error', $title, ['work' => 'drill']);
$after  = $lines();

$step(
    '冷却期内不重复发送',
    $sent2 === false && $after === $before,
    '第二次 send() = ' . var_export($sent2, true) . '，接收端条数 ' . $before . ' → ' . $after
);

// ---- 5) 通道挂了不影响业务 ------------------------------------------------
Alert::overrideWebhook('http://127.0.0.1:1/alert');   // 必然连不上
$throwable = null;
$sent3     = null;
try {
    $sent3 = Alert::send('critical', $failTitle, ['work' => 'drill']);
} catch (Throwable $e) {
    $throwable = $e;
}

$step(
    '对方不可达时不抛异常（旁路原则）',
    $throwable === null && $sent3 === false,
    $throwable === null
        ? 'send() 返回 false 而不是抛异常'
        : '抛了 ' . $throwable::class . '：' . $throwable->getMessage()
);

// ---- 6) 无论成败都留痕 ----------------------------------------------------
$newTrace = '';
if (is_file($traceFile)) {
    $fh = fopen($traceFile, 'rb');
    if ($fh !== false) {
        fseek($fh, $traceFrom);
        $newTrace = (string) stream_get_contents($fh);
        fclose($fh);
    }
}
$hasSent   = str_contains($newTrace, "sent\terror\t" . $title);
$hasFailed = str_contains($newTrace, "failed\tcritical\t" . $failTitle);

$step(
    'alert.log 有本次的 sent / failed 记录',
    $hasSent && $hasFailed,
    'sent=' . var_export($hasSent, true) . '，failed=' . var_export($hasFailed, true)
    . '（只看本次新增的 ' . strlen($newTrace) . ' 字节）'
);

$seconds = round(microtime(true) - $started, 2);

$report = [
    'drilled_at' => date('c'),
    'sink'       => $sinkUrl,
    'seconds'    => $seconds,
    'ok'         => $fail === 0,
    'steps'      => $steps,
];

$jsonPath = $options['json'] ?? ($root . '/storage/reports/alert-drill-latest.json');
if (!is_dir(dirname($jsonPath))) {
    @mkdir(dirname($jsonPath), 0750, true);
}
file_put_contents($jsonPath, json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");

fwrite(STDOUT, sprintf(
    "\n%s：%d 步，失败 %d 步，用时 %.2fs\n报告：%s\n",
    $fail === 0 ? '告警演练通过' : '告警演练失败',
    count($steps),
    $fail,
    $seconds,
    $jsonPath
));

exit($fail === 0 ? 0 : 1);
