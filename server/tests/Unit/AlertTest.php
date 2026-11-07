<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Alert;
use App\Support\Config;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Alert 的四条底线，每条都是一个具体的失败模式：
 *
 *   · 没配通道时不能假装发出去了（否则上线后以为有告警，实际没人收到）；
 *   · 通道不可达时不能抛异常（告警是旁路，不能把业务一起拖走）；
 *   · 冷却期内不能重复发（一次宕机刷上千条，人会开始忽略它）；
 *   · 出站前必须脱敏（告警会离开这台机器）。
 *
 * 全部指向不可达地址或临时目录，不触网、不写真实日志。
 */
final class AlertTest extends TestCase
{
    private string $tmp = '';

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/hq-alert-test-' . bin2hex(random_bytes(4));
        mkdir($this->tmp, 0777, true);

        Alert::useConfig($this->config('http://127.0.0.1:1/alert', true));
        Alert::overrideWebhook(null);
    }

    protected function tearDown(): void
    {
        Alert::forgetConfig();
        foreach (glob($this->tmp . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->tmp);
    }

    private function config(string $webhook, bool $enabled, int $cooldown = 1800): Config
    {
        return new Config([
            'app'     => ['name' => 'hq-test', 'env' => 'test', 'root' => $this->tmp],
            'alert'   => ['enabled' => $enabled, 'webhook' => $webhook, 'timeout' => 1, 'cooldown' => $cooldown],
            'storage' => ['logs' => $this->tmp, 'cache' => $this->tmp, 'artifacts' => $this->tmp],
            'log'     => ['dir' => $this->tmp],
        ]);
    }

    private function trace(): string
    {
        $file = $this->tmp . '/alert.log';

        return is_file($file) ? (string) file_get_contents($file) : '';
    }

    public function testSkipsWhenWebhookMissingButStillTraces(): void
    {
        Alert::useConfig($this->config('', true));

        $this->assertFalse(Alert::send('error', '未配置通道'), '没配 webhook 时必须返回 false');
        $this->assertStringContainsString("skipped\terror\t未配置通道", $this->trace(), '跳过也要留痕');
    }

    public function testSkipsWhenDisabled(): void
    {
        Alert::useConfig($this->config('http://127.0.0.1:1/alert', false));

        $this->assertFalse(Alert::send('error', '通道关闭'));
        $this->assertStringContainsString('ALERT_ENABLED=false', $this->trace());
    }

    public function testDoesNotThrowWhenUnreachable(): void
    {
        // 127.0.0.1:1 必然连接被拒；这一步验证的是「不抛异常」而不是「发失败」
        $result = Alert::send('critical', '对方不可达');

        $this->assertFalse($result);
        $this->assertStringContainsString("failed\tcritical\t对方不可达", $this->trace());
    }

    public function testCooldownSuppressesSecondSend(): void
    {
        Alert::send('error', '同标题');
        $afterFirst = $this->trace();

        $second = Alert::send('error', '同标题');

        $this->assertFalse($second, '冷却期内第二次必须不发');
        $this->assertStringContainsString('suppressed', $this->trace());
        $this->assertGreaterThan(strlen($afterFirst), strlen($this->trace()), '第二次只多一行 suppressed');
    }

    public function testCooldownDoesNotBlockDifferentTitle(): void
    {
        Alert::send('error', '标题 A');
        Alert::send('error', '标题 B');

        // 两条都应该走到发送（都失败），说明冷却按标题隔离而不是全局
        $log = $this->trace();
        $this->assertStringContainsString("failed\terror\t标题 A", $log);
        $this->assertStringContainsString("failed\terror\t标题 B", $log);
    }

    public function testScrubsSecretsInContext(): void
    {
        $scrub = new ReflectionMethod(Alert::class, 'scrub');
        $scrub->setAccessible(true);

        $out = $scrub->invoke(null, [
            'key'   => 'sk-drill0123456789abcdef',
            'phone' => '13800001111',
            'email' => 'reader@example.com',
            'auth'  => 'Bearer eyJhbGciOiJIUzI1NiJ9.payload',
            'raw'   => 'password = "hunter2hunter2"',
            'safe'  => '这条应该原样保留',
            'nested'=> ['deep' => 'sk-nested0123456789'],
        ]);

        $this->assertSame('sk-***', $out['key']);
        $this->assertStringNotContainsString('13800001111', $out['phone']);
        $this->assertStringNotContainsString('reader@', $out['email']);
        $this->assertStringNotContainsString('eyJhbGci', $out['auth']);
        $this->assertStringNotContainsString('hunter2hunter2', $out['raw']);
        $this->assertSame('这条应该原样保留', $out['safe'], '无关内容不能被误伤');
        $this->assertSame('sk-***', $out['nested']['deep'], '嵌套数组也要走到');
    }

    public function testScrubKeepsUrlsAndIds(): void
    {
        $scrub = new ReflectionMethod(Alert::class, 'scrub');
        $scrub->setAccessible(true);

        $out = $scrub->invoke(null, [
            'job_id' => 42,
            'url'    => 'http://127.0.0.1:8090/library',
            'ulid'   => '01JABCD2EFGH3JKLMN4PQRSTVW',
        ]);

        $this->assertSame(42, $out['job_id'], '整数不能被转成字符串');
        $this->assertSame('http://127.0.0.1:8090/library', $out['url'], 'URL 不能被当成手机号切碎');
        $this->assertSame('01JABCD2EFGH3JKLMN4PQRSTVW', $out['ulid']);
    }
}
