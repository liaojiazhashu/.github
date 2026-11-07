<?php

declare(strict_types=1);

/**
 * 二次验证演练（附录 E-40）：证明「光有口令进不来」是事实，不是配置里的一句注释。
 *
 * 只验证「代码里调用了 Totp::verify」没有意义 —— 那只证明写了几行代码。
 * 要证明的是四件事，按顺序串成一个闭环：
 *
 *   1. 账号绑了 TOTP 之后，只给用户名 + 口令 → 登录被拒（422，提示验证码问题）；
 *   2. 补上正确的 6 位码 → 登录成功（303 跳后台）；
 *   3. 给错误的码 → 仍然被拒（否则「校验」只是摆设）；
 *   4. 收尾必须把账号恢复原状（TOTP 关掉、口令改成不可知的随机值）。
 *
 * 第 4 条和检查本身一样重要：一次演练不该在系统里留下一个绑着 TOTP、口令却写在
 * 报告里的账号 —— 那就成了为了验证安全而制造的漏洞。
 *
 * 用哪个账号：默认 qa_reviewer（**不要用 qa_viewer**，security-audit 的 --qa 模式
 * 依赖它只用口令就能登进来跑会话类检查）。
 *
 * 用法：
 *   php -c php.ini bin/totp-drill.php
 *   php -c php.ini bin/totp-drill.php --user=qa_reviewer
 *
 * 退出码：0 全部通过；1 有断言失败；2 环境不满足（账号不存在 / 服务没起）。
 */

$root = require dirname(__DIR__) . '/config/bootstrap.php';

use App\Infrastructure\Pdo\UserRepository;
use App\Support\Config;
use App\Support\Crypto;
use App\Support\Db;
use App\Support\Totp;

$config = new Config(require dirname(__DIR__) . '/config/settings.php');
$pdo    = Db::connect($config);
$crypto = new Crypto($config);
$users  = new UserRepository($pdo);
$base   = rtrim($config->string('app.url'), '/');

$options = [];
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--')) {
        [$k, $v] = array_pad(explode('=', substr($arg, 2), 2), 2, '');
        $options[$k] = $v;
    }
}

$username = $options['user'] ?? 'qa_reviewer';

$user = $users->findByUsername($username);
if ($user === null) {
    fwrite(STDERR, "[!] 账号不存在：{$username}\n");
    exit(2);
}
if ($username === 'qa_viewer') {
    fwrite(STDERR, "[!] 不能用 qa_viewer：security-audit --qa 依赖它只用口令登录\n");
    exit(2);
}

// 服务在不在
$probe = @fsockopen((string) parse_url($base, PHP_URL_HOST), (int) (parse_url($base, PHP_URL_PORT) ?: 80), $errno, $errstr, 1.0);
if ($probe === false) {
    fwrite(STDERR, "[!] 服务未启动：{$base}\n");
    exit(2);
}
fclose($probe);

$userId = (int) $user['id'];

// 记录原始状态，收尾要还原
$origin = [
    'totp_enabled' => (int) $user['totp_enabled'],
    'totp_cipher'  => $user['totp_secret_cipher'],
];

$hash = static fn (string $pw): string => password_hash($pw, PASSWORD_ARGON2ID, App\Application\Auth\AuthService::ARGON_OPTIONS);

$password = bin2hex(random_bytes(18));
$secret   = Totp::generateSecret();

/** 收尾：无论成败都把账号恢复成「TOTP 原样、口令不可知」 */
$restore = static function () use ($pdo, $users, $userId, $origin, $hash): void {
    $pdo->prepare('UPDATE hq_user SET totp_enabled = :e, totp_secret_cipher = :c, updated_at = NOW() WHERE id = :id')
        ->execute([':e' => $origin['totp_enabled'], ':c' => $origin['totp_cipher'], ':id' => $userId]);
    $users->updatePassword($userId, $hash(bin2hex(random_bytes(18))));
    (new App\Infrastructure\Pdo\SessionRepository($pdo))->deleteByUser($userId);
};
register_shutdown_function($restore);

// 埋好：口令 + TOTP 都已知，接下来全走真实 HTTP
$users->updatePassword($userId, $hash($password));
$pdo->prepare('UPDATE hq_user SET totp_enabled = 1, totp_secret_cipher = :s, updated_at = NOW() WHERE id = :id')
    ->execute([':s' => $crypto->seal($secret), ':id' => $userId]);

// ---------------------------------------------------------------------------
// 最小 HTTP 客户端（带 cookie jar；只为了这次演练，不引第三方）
// ---------------------------------------------------------------------------
$jar = tempnam(sys_get_temp_dir(), 'totp');

/** @return array{status:int,body:string,headers:string,location:string} */
$http = static function (string $method, string $url, array $form = []) use ($jar): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_COOKIEJAR      => $jar,
        CURLOPT_COOKIEFILE     => $jar,
        CURLOPT_TIMEOUT        => 10,
    ]);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($form));
    }
    $raw  = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $hdr  = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    $headers  = substr($raw, 0, $hdr);
    $body     = substr($raw, $hdr);
    $location = '';
    if (preg_match('/^Location:\s*(.+)$/mi', $headers, $m)) {
        $location = trim($m[1]);
    }

    return ['status' => $code, 'body' => $body, 'headers' => $headers, 'location' => $location];
};

$csrf = static function (string $html): string {
    return preg_match('/name="_token"\s+value="([^"]+)"/', $html, $m) === 1 ? $m[1] : '';
};

$steps = [];
$fail  = 0;

$step = static function (string $name, bool $ok, string $evidence) use (&$steps, &$fail): void {
    if (!$ok) {
        $fail++;
    }
    $steps[] = ['name' => $name, 'ok' => $ok, 'evidence' => $evidence];
    printf("%s %s\n    %s\n", $ok ? '[OK  ]' : '[FAIL]', $name, $evidence);
};

$started = microtime(true);

// ---- 1) 只有口令，没有验证码 ----------------------------------------------
$page  = $http('GET', $base . '/admin/login');
$token = $csrf($page['body']);
$step('登录页可取到 CSRF token', $token !== '', $token === '' ? '页面里找不到 _token' : 'token ' . substr($token, 0, 10) . '…');

$noCode = $http('POST', $base . '/admin/login', [
    '_token'   => $token,
    'username' => $username,
    'password' => $password,
]);

$msg = '';
if (preg_match('/flash--err[^>]*>([^<]+)/', $noCode['body'], $m) === 1) {
    $msg = trim($m[1]);
}

$step(
    '仅凭口令登录被拒',
    $noCode['status'] === 422 && $noCode['location'] === '',
    'HTTP ' . $noCode['status'] . ($msg !== '' ? '，提示「' . $msg . '」（未跳转）' : '')
);

// ---- 2) 错误的验证码 ------------------------------------------------------
$page  = $http('GET', $base . '/admin/login');
$token = $csrf($page['body']);
$wrong = $http('POST', $base . '/admin/login', [
    '_token'   => $token,
    'username' => $username,
    'password' => $password,
    'totp'     => '000000',
]);

// 000000 极小概率正好等于当前码；真撞上了这一步会误报，重跑一次即可
$step(
    '错误验证码被拒',
    $wrong['status'] === 422 && $wrong['location'] === '',
    'HTTP ' . $wrong['status'] . ($wrong['status'] === 422 ? '（被拒）' : '，Location: ' . $wrong['location'] . '（意外通过）')
);

// ---- 3) 正确的验证码 ------------------------------------------------------
$code = Totp::code(Totp::base32Decode($secret), (int) floor(time() / 30));
$page = $http('GET', $base . '/admin/login');
$token = $csrf($page['body']);
$ok = $http('POST', $base . '/admin/login', [
    '_token'   => $token,
    'username' => $username,
    'password' => $password,
    'totp'     => $code,
]);

$step(
    '口令 + 正确验证码 → 登录成功',
    $ok['status'] === 303 && str_contains($ok['location'], '/admin'),
    'HTTP ' . $ok['status'] . '，Location: ' . ($ok['location'] === '' ? '(无)' : $ok['location'])
);

// ---- 4) 登录后 TOTP 生效，会话可用 ----------------------------------------
$dash = $http('GET', $base . '/admin');
$step(
    '登录后的会话可用（/admin 200）',
    $dash['status'] === 200,
    'HTTP ' . $dash['status']
);

$seconds = round(microtime(true) - $started, 2);

@unlink($jar);

$report = [
    'drilled_at' => date('c'),
    'account'    => $username,
    'seconds'    => $seconds,
    'ok'         => $fail === 0,
    'restored'   => true,
    'steps'      => $steps,
];

$jsonPath = $options['json'] ?? ($root . '/storage/reports/totp-drill-latest.json');
if (!is_dir(dirname($jsonPath))) {
    @mkdir(dirname($jsonPath), 0750, true);
}
file_put_contents($jsonPath, json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");

fwrite(STDOUT, sprintf(
    "\n%s：%d 步，失败 %d 步，用时 %.2fs（账号 %s 已恢复原状）\n报告：%s\n",
    $fail === 0 ? 'TOTP 演练通过' : 'TOTP 演练失败',
    count($steps),
    $fail,
    $seconds,
    $username,
    $jsonPath
));

exit($fail === 0 ? 0 : 1);
