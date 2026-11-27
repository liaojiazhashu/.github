<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Application\Library\SitemapBuilder;
use PHPUnit\Framework\TestCase;

final class SitemapBuilderTest extends TestCase
{
    public function testEmptyListIsStillValidXml(): void
    {
        $xml = SitemapBuilder::build([]);

        self::assertStringContainsString('<urlset', $xml);
        self::assertStringNotContainsString('<url>', $xml);
        self::assertNotFalse(simplexml_load_string($xml));
    }

    public function testWritesAllOptionalFields(): void
    {
        $xml = SitemapBuilder::build([[
            'loc'        => 'https://example.test/library/works/ABC',
            'lastmod'    => '2026-10-28',
            'changefreq' => 'weekly',
            'priority'   => '0.8',
        ]]);

        self::assertStringContainsString('<loc>https://example.test/library/works/ABC</loc>', $xml);
        self::assertStringContainsString('<lastmod>2026-10-28</lastmod>', $xml);
        self::assertStringContainsString('<changefreq>weekly</changefreq>', $xml);
        self::assertStringContainsString('<priority>0.8</priority>', $xml);
        self::assertNotFalse(simplexml_load_string($xml));
    }

    public function testOmitsMissingOptionalFields(): void
    {
        $xml = SitemapBuilder::build([['loc' => 'https://example.test/library']]);

        self::assertStringNotContainsString('<lastmod>', $xml);
        self::assertStringNotContainsString('<changefreq>', $xml);
        self::assertStringNotContainsString('<priority>', $xml);
    }

    public function testSkipsEntriesWithoutLoc(): void
    {
        $xml = SitemapBuilder::build([
            ['loc' => ''],
            ['loc' => '   '],
            ['changefreq' => 'daily'],
            ['loc' => 'https://example.test/ok'],
        ]);

        self::assertSame(1, substr_count($xml, '<url>'));
    }

    public function testEscapesAmpersandAndAngleBrackets(): void
    {
        // 回归：作品标题里的 & 曾经让整份 XML 解析失败
        $xml = SitemapBuilder::build([['loc' => 'https://example.test/?a=1&b=<2>']]);

        self::assertStringContainsString('&amp;', $xml);
        self::assertStringContainsString('&lt;2&gt;', $xml);
        self::assertNotFalse(simplexml_load_string($xml));
    }

    public function testKeepsUtf8LocReadable(): void
    {
        $xml = SitemapBuilder::build([['loc' => 'https://example.test/library/terms/恒星观测']]);
        $parsed = simplexml_load_string($xml);

        self::assertNotFalse($parsed);
        self::assertSame('https://example.test/library/terms/恒星观测', (string) $parsed->url->loc);
    }

    public function testEndsWithSingleNewline(): void
    {
        $xml = SitemapBuilder::build([['loc' => 'https://example.test/']]);

        self::assertStringEndsWith("</urlset>\n", $xml);
    }

    /**
     * 生产环境配成回环地址时必须炸，不能静默产出对外无效的 sitemap。
     *
     * 这是 2026-11-27 之前真实发生过的形态：APP_URL 漏配，整份 sitemap 的
     * 每一个 <loc> 都是 http://127.0.0.1:8090，HTTP 200、条数正确、自检全过，
     * 只有搜索引擎一条都收不进去，谁也不会发现。
     */
    public function testRejectsLoopbackBaseInProduction(): void
    {
        // 不能写成 static 闭包：闭包里要用 $this->expectException，
        // 而 static 闭包没有对象上下文，PHP 会报
        // "Using $this when not in object context"（实测 2026-11-27）。
        $this->withEnv('production', function (): void {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('回环地址');
            SitemapBuilder::assertBase('http://127.0.0.1:8090');
        });
    }

    public function testAllowsLoopbackBaseInLocal(): void
    {
        $this->withEnv('local', static function (): void {
            // 本机 php -S 开发本来就该用 localhost，不该被这条挡住
            SitemapBuilder::assertBase('http://127.0.0.1:8090');
        });

        self::assertTrue(true);
    }

    public function testAllowsRealDomainInProduction(): void
    {
        $this->withEnv('production', static function (): void {
            SitemapBuilder::assertBase('https://zha.ruletree.club');
        });

        self::assertTrue(true);
    }

    /**
     * 临时改写 APP_ENV 并在结束后还原，避免污染同进程里的其他用例。
     *
     * 只动 $_ENV，不碰 putenv —— server/php.ini:52 的 disable_functions 里有 putenv，
     * 测试进程一调用就直接 Fatal。assertBase 先读 $_ENV，正好够用。
     */
    private function withEnv(string $env, callable $body): void
    {
        $hadEnv = array_key_exists('APP_ENV', $_ENV);
        $oldEnv = $_ENV['APP_ENV'] ?? null;

        $_ENV['APP_ENV'] = $env;

        try {
            $body();
        } finally {
            if ($hadEnv) {
                $_ENV['APP_ENV'] = $oldEnv;
            } else {
                unset($_ENV['APP_ENV']);
            }
        }
    }
}
