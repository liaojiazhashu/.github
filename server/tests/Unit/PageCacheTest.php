<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Application\Library\PageCache;
use PHPUnit\Framework\TestCase;

/**
 * 整页缓存。
 *
 * 这里的断言都围绕「缓存坏了不能把功能弄坏」：目录不可写、文件被截断、
 * 元数据是垃圾 —— 每一种都只允许「当作没命中」，不允许抛异常。
 */
final class PageCacheTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        // 必须落在 server/ 内（php.ini 的 open_basedir 只放行 server/ 与 D:\hqlib\tmp）
        $this->dir = dirname(__DIR__, 2) . '/storage/cache/test-pages-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0750, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
    }

    public function testMissOnEmptyCache(): void
    {
        self::assertNull((new PageCache($this->dir))->get('home', 60));
    }

    public function testPutThenGetReturnsSameHtml(): void
    {
        $cache = new PageCache($this->dir);
        $etag  = $cache->put('home', '<html>馆藏</html>', ['works']);

        $hit = $cache->get('home', 60);

        self::assertNotNull($hit);
        self::assertSame('<html>馆藏</html>', $hit['html']);
        self::assertSame($etag, $hit['etag']);
        self::assertSame(['works'], $hit['tags']);
        self::assertIsInt($hit['age']);
    }

    public function testEtagIsQuotedSha1(): void
    {
        $etag = (new PageCache($this->dir))->put('home', 'x', []);

        // 强校验的 ETag 必须带引号，否则 If-None-Match 比较会失败
        self::assertMatchesRegularExpression('/^"[0-9a-f]{40}"$/', $etag);
    }

    public function testTtlZeroAlwaysMisses(): void
    {
        $cache = new PageCache($this->dir);
        $cache->put('search?q=x', '<html>结果</html>', []);

        // 检索结果永不缓存：TTL=0 直接当未命中
        self::assertNull($cache->get('search?q=x', 0));
        self::assertNull($cache->get('search?q=x', -1));
    }

    public function testExpiredEntryMisses(): void
    {
        $cache = new PageCache($this->dir);
        $cache->put('home', '<html>旧</html>', []);

        // 把 created_at 往前拨 120 秒，模拟过期
        $base = $this->dir . '/' . sha1('home');
        $meta = json_decode((string) file_get_contents($base . '.json'), true);
        $meta['created_at'] = time() - 120;
        file_put_contents($base . '.json', (string) json_encode($meta));

        self::assertNull($cache->get('home', 60));
        self::assertNotNull($cache->get('home', 300));
    }

    public function testCorruptMetadataIsDiscarded(): void
    {
        $cache = new PageCache($this->dir);
        $cache->put('home', '<html>x</html>', []);

        $base = $this->dir . '/' . sha1('home');
        file_put_contents($base . '.json', 'not json at all');

        self::assertNull($cache->get('home', 60));
        // 坏元数据应当被清掉，而不是留着下次继续报错
        self::assertFileDoesNotExist($base . '.json');
        self::assertFileDoesNotExist($base . '.html');
    }

    public function testInvalidateTagsRemovesOnlyMatchingPages(): void
    {
        $cache = new PageCache($this->dir);
        $cache->put('works', '<html>列表</html>', ['works']);
        $cache->put('work:ABC', '<html>详情</html>', ['work:ABC', 'works']);
        $cache->put('terms', '<html>词条</html>', ['terms']);

        $removed = $cache->invalidateTags(['work:ABC']);

        self::assertSame(1, $removed, '只该扔掉挂了 work:ABC 的那一页');
        self::assertNull($cache->get('work:ABC', 300));
        self::assertNotNull($cache->get('works', 60));
        self::assertNotNull($cache->get('terms', 300));
    }

    public function testInvalidateWithEmptyTagsIsNoop(): void
    {
        $cache = new PageCache($this->dir);
        $cache->put('home', '<html>x</html>', ['works']);

        self::assertSame(0, $cache->invalidateTags([]));
        self::assertSame(0, $cache->invalidateTags(['']));
        self::assertNotNull($cache->get('home', 60));
    }

    public function testClearEmptiesEverything(): void
    {
        $cache = new PageCache($this->dir);
        $cache->put('a', '1', ['x']);
        $cache->put('b', '2', ['y']);

        self::assertSame(4, $cache->clear());
        self::assertNull($cache->get('a', 60));
        self::assertNull($cache->get('b', 60));
    }

    public function testStatsCountsPagesAndBytes(): void
    {
        $cache = new PageCache($this->dir);
        $cache->put('a', str_repeat('x', 100), []);
        $cache->put('b', str_repeat('y', 50), []);

        self::assertSame(['pages' => 2, 'bytes' => 150], $cache->stats());
    }

    public function testMissingDirDegradesToMissInsteadOfThrowing(): void
    {
        // 目录被运维清掉、或者首次部署还没建：读、失效、统计都必须安静地返回空。
        // （put 那一侧的「目录建不出来」在 Windows 上没法用 chmod 稳定复现，
        //  而且 open_basedir 会先把不存在的中间目录判成越界 —— 所以只测读这一侧。）
        $cache = new PageCache($this->dir . '/never-created');

        self::assertNull($cache->get('home', 60));
        self::assertSame(0, $cache->invalidateTags(['works']));
        self::assertSame(0, $cache->clear());
        self::assertSame(['pages' => 0, 'bytes' => 0], $cache->stats());
    }

    public function testWritesAtomicallyWithoutLeavingTmpFiles(): void
    {
        (new PageCache($this->dir))->put('home', '<html>原子</html>', []);

        self::assertSame([], glob($this->dir . '/*.tmp') ?: []);
    }

    // ---- prune：回收「再也不可能命中」的文件 ------------------------------------
    //
    // get() 过期**故意不删**（省一次 IO），put() 只覆盖同一个 key，所以键空间
    // （带查询串的列表页、翻页游标、词条）里那些「只被访问过一次」的页面会永远留着，
    // 而 invalidateTags() 每次发布都要 glob 整个目录逐个解析 —— 成本全压在这里。

    public function testPruneKeepsFreshAndRemovesExpiredPages(): void
    {
        $cache = new PageCache($this->dir);
        $cache->put('fresh', '<html>新</html>', ['works']);
        $cache->put('old', '<html>旧</html>', ['works']);

        $this->ageEntry('old', 3 * 86400);

        $result = $cache->prune(86400);

        self::assertSame(1, $result['removed'], '只该删掉三天前那一页');
        self::assertGreaterThan(0, $result['bytes']);
        self::assertNotNull($cache->get('fresh', 3600), '门槛内的页面必须原样可命中');
        self::assertFileDoesNotExist($this->dir . '/' . sha1('old') . '.html');
        self::assertFileDoesNotExist($this->dir . '/' . sha1('old') . '.json');
    }

    public function testPruneRemovesCorruptMetadataAndStaleTmpFiles(): void
    {
        $cache = new PageCache($this->dir);
        $cache->put('fresh', '<html>新</html>', []);

        // 读不出来的元数据：get() 会判成未命中，prune 应当直接清掉，
        // 否则它会一直占着位置、还拖慢每次 glob。
        file_put_contents($this->dir . '/deadbeef.json', '{ 不是 json');
        file_put_contents($this->dir . '/deadbeef.html', 'x');

        // put() 在 rename 失败时留下的半截临时文件
        $tmp = $this->dir . '/stale.html.tmp';
        file_put_contents($tmp, 'x');
        touch($tmp, time() - 3 * 86400);

        $result = $cache->prune(86400);

        self::assertSame(1, $result['removed'], '坏元数据按「一页」计；tmp 不计入页数');
        self::assertFileDoesNotExist($this->dir . '/deadbeef.json');
        self::assertFileDoesNotExist($this->dir . '/deadbeef.html');
        self::assertFileDoesNotExist($tmp);
        self::assertNotNull($cache->get('fresh', 3600));
    }

    public function testPruneWithNonPositiveMaxAgeIsNoop(): void
    {
        $cache = new PageCache($this->dir);
        $cache->put('old', '<html>旧</html>', []);
        $this->ageEntry('old', 10 * 86400);

        // 门槛非法时必须什么都不删：删错了不会报错，只会让某些页莫名其妙变慢
        self::assertSame(['removed' => 0, 'bytes' => 0], $cache->prune(0));
        self::assertSame(['removed' => 0, 'bytes' => 0], $cache->prune(-1));
        self::assertFileExists($this->dir . '/' . sha1('old') . '.html');
    }

    public function testPruneOnMissingDirIsNoop(): void
    {
        self::assertSame(
            ['removed' => 0, 'bytes' => 0],
            (new PageCache($this->dir . '/never-created'))->prune(86400)
        );
    }

    /** 把某一页的 created_at 往前拨，模拟「很久没人访问」 */
    private function ageEntry(string $key, int $seconds): void
    {
        $metaFile = $this->dir . '/' . sha1($key) . '.json';
        $meta     = json_decode((string) file_get_contents($metaFile), true);

        self::assertIsArray($meta, '测试自身的前提：这一页应当已经写进去了');

        $meta['created_at'] = time() - $seconds;
        file_put_contents($metaFile, (string) json_encode($meta));
    }
}
