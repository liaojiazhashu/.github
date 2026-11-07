<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Support\Alert;
use App\Support\Config;
use App\Support\Crypto;
use App\Support\Db;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

/**
 * 探活。回答「这套东西现在能不能用」，不含任何业务数据。
 *
 * 输出只有两层：`status`（ok / degraded）与 `checks.<部件>.state`。
 * 任一部件失败即 degraded，HTTP 仍 200 —— 让监控能读到细分状态，
 * 而不是只看到一个 500。
 *
 * **这里刻意不给明细**：数据库版本、表数量、今日调用次数、今日成本、PHP 版本
 * 这些曾经都在这儿，而 `/healthz` 是**匿名可访问**的 —— 等于把拓扑与用量
 * 开给任何扫到它的人。探活只需要回答「好的还是坏的」，要数字去 `/admin`
 * 那几个页面，那里有登录与权限码。
 *
 * 唯一保留的细节是**坏了的时候说哪个目录坏了**：排障时「storage 错」这四个字
 * 没法行动，说出 `artifacts` 才够。
 */
final class HealthController
{
    public function __construct(
        private readonly Config $config,
        private readonly Crypto $crypto,
        // 刻意在容器这一层拿依赖，而不是把 JobRepository 写进构造签名。
        // 理由：仓储要 PDO，而 `PDO::class => Db::connect(...)` 是**解析即连接**。
        // 写进构造签名的话，数据库一挂，控制器在「被 new 出来」这一步就抛，
        // 根本进不到 index() 的 try/catch —— /healthz 于是只能回一句
        // {"status":"error"}，恰恰说不清「是数据库挂了」这件事。
        // 这是全项目唯一一处 service locator，它的职责本来就是去探那些不可信的依赖。
        private readonly ContainerInterface $container,
        private readonly ResponseFactoryInterface $responseFactory,
    ) {
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $checks = [];
        $status = 'ok';

        // ---- 数据库：只问「连得上吗」----------------------------------------
        // 不查 VERSION() 也不数表：那两条只为展示，故障时反而多两次往返。
        try {
            Db::connect($this->config)->query('SELECT 1');
            $checks['db'] = ['state' => 'ok'];
        } catch (Throwable) {
            $status = 'degraded';
            $checks['db'] = ['state' => 'error'];
        }

        // ---- 存储：四个目录可写吗 -------------------------------------------
        $broken  = [];
        $storage = [];
        foreach (['uploads', 'logs', 'cache', 'artifacts'] as $dir) {
            $path = $this->config->string('app.root') . '/storage/' . $dir;
            if (!is_dir($path)) {
                @mkdir($path, 0750, true);
            }
            $ok            = is_dir($path) && is_writable($path);
            $storage[$dir] = $ok ? 'ok' : 'error';
            if (!$ok) {
                $broken[] = $dir;
            }
        }
        // state 这一层不能省：下面的告警循环按 $check['state'] 判断，
        // 早先 storage 只有目录映射、没有 state，于是「目录不可写」会把
        // status 拉成 degraded，但告警内容里永远不出现它。
        $checks['storage'] = ['state' => $broken === [] ? 'ok' : 'error'];
        if ($broken !== []) {
            $status                     = 'degraded';
            $checks['storage']['broken'] = $broken;
        }

        // ---- AI：只报「配没配」 ---------------------------------------------
        $checks['ai'] = ['state' => $this->config->bool('ai.enabled', false) ? 'ok' : 'off'];

        // ---- 队列：只报「队列表读得到吗」 -----------------------------------
        // 具体积压多少在 /admin/queue，那里要登录。这里再查一次 AiCallRepository
        // 取今日成本是没有必要的第二次连接。
        try {
            $this->container->get(\App\Infrastructure\Pdo\JobRepository::class)->counts();
            $checks['queue'] = ['state' => 'ok'];
        } catch (Throwable) {
            $status          = 'degraded';
            $checks['queue'] = ['state' => 'error'];
        }

        // ---- 运行时：KEK 还能加解密吗 ---------------------------------------
        // 长度不对时所有加密功能都会静默失效，必须提前暴露。
        $kekOk = $this->keyOk();
        if (!$kekOk) {
            $status = 'degraded';
        }
        $checks['runtime'] = ['state' => $kekOk ? 'ok' : 'error'];

        // 只有 degraded 才告警，且靠 Alert 的冷却期去重：健康检查每分钟一次，
        // 不去重的话一次宕机能刷出上千条，人就会开始忽略它。
        if ($status !== 'ok') {
            $down = [];
            foreach ($checks as $name => $check) {
                if (($check['state'] ?? 'ok') !== 'ok') {
                    $down[$name] = $check['state'] ?? 'error';
                }
            }
            Alert::send('error', 'healthz degraded', ['broken' => $down]);
        }

        $payload = json_encode(
            ['status' => $status, 'checks' => $checks],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        $response->getBody()->write($payload === false ? '{}' : $payload);

        return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    /** APP_KEK 能否用来加解密。 */
    private function keyOk(): bool
    {
        try {
            return $this->crypto->open($this->crypto->seal('probe')) === 'probe';
        } catch (Throwable) {
            return false;
        }
    }
}
