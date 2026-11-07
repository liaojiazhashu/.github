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
}
