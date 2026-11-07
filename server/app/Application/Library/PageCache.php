<?php

declare(strict_types=1);

namespace App\Application\Library;

/**
 * 整页 HTML 缓存（`storage/cache/pages/`）。
 *
 * 为什么是文件而不是 Redis / 数据库：
 *   · 本机与生产都不引额外服务（§4.2 的立场）；
 *   · 一页 HTML 30–200 KB，落在磁盘上一次 write + rename，比查 12 条 SQL 便宜得多；
 *   · 缓存丢了只是变慢，不是出错 —— 这类数据不值得为它增加一个依赖。
 *
 * 三条实现细节，都是踩过才知道的：
 *   1. **原子写**：先写 `.tmp` 再 `rename`。直接写目标文件时，并发读会读到半截 HTML，
 *      浏览器拿到的是一段被截断的页面（比 500 更难查）。
 *   2. **元数据与正文分开存**：正文要能直接当文件读，标签要能被批量扫描。
 *   3. **失效按标签**：发布/重跑一篇后只该扔掉与这篇相关的页，不能整目录清空 ——
 *      清空会让下一次访问把全部页面重新渲染一遍，正是缓存本来要避免的事。
 *      标签扫描是全目录遍历（当前量级几百个小文件，几毫秒），换来的是不需要维护一张索引表。
 */
final class PageCache
{
    public function __construct(private readonly string $dir)
    {
    }

    /**
     * @return array{html:string, etag:string, age:int, tags:list<string>}|null 未命中/过期返回 null
     */
    public function get(string $key, int $ttl): ?array
    {
        if ($ttl <= 0) {
            return null;
        }

        $base = $this->base($key);
        if (!is_file($base . '.html') || !is_file($base . '.json')) {
            return null;
        }

        $meta = json_decode((string) @file_get_contents($base . '.json'), true);
        if (!is_array($meta) || !isset($meta['created_at'], $meta['etag'])) {
            $this->delete($base);

            return null;
        }

        $age = time() - (int) $meta['created_at'];
        if ($age < 0 || $age > $ttl) {
            return null;   // 过期文件留着不删：下次 put 会覆盖，删了反而多一次 IO
        }

        $html = @file_get_contents($base . '.html');
        if ($html === false) {
            return null;
        }

        return [
            'html' => $html,
            'etag' => (string) $meta['etag'],
            'age'  => $age,
            'tags' => is_array($meta['tags'] ?? null) ? $meta['tags'] : [],
        ];
    }

    /**
     * @param list<string> $tags
     * @return string ETag（正文的 sha1，强校验）
     */
    public function put(string $key, string $html, array $tags): string
    {
        if (!$this->ensureDir()) {
            return '';   // 目录不可写就不要让页面因此失败，缓存是加速不是功能
        }

        $base = $this->base($key);
        $etag = '"' . sha1($html) . '"';

        $tmp = $base . '.html.tmp';
        if (@file_put_contents($tmp, $html, LOCK_EX) === false) {
            return '';
        }
        if (!@rename($tmp, $base . '.html')) {
            @unlink($tmp);

            return '';
        }

        @file_put_contents(
            $base . '.json',
            (string) json_encode([
                'key'        => $key,
                'etag'       => $etag,
                'tags'       => array_values(array_unique($tags)),
                'created_at' => time(),
                'bytes'      => strlen($html),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );

        return $etag;
    }

    /**
     * 按标签失效。返回删掉的页面数。
     *
     * @param list<string> $tags
     */
    public function invalidateTags(array $tags): int
    {
        $tags = array_values(array_filter(array_unique($tags), static fn (string $t): bool => $t !== ''));
        if ($tags === [] || !is_dir($this->dir)) {
            return 0;
        }

        $removed = 0;
        foreach (glob($this->dir . '/*.json') ?: [] as $metaFile) {
            $meta = json_decode((string) @file_get_contents($metaFile), true);
            if (!is_array($meta)) {
                @unlink($metaFile);
                continue;
            }
            $pageTags = is_array($meta['tags'] ?? null) ? $meta['tags'] : [];
            if (array_intersect($tags, $pageTags) === []) {
                continue;
            }

            @unlink($metaFile);
            @unlink(substr($metaFile, 0, -5) . '.html');
            $removed++;
        }

        return $removed;
    }

    public function clear(): int
    {
        if (!is_dir($this->dir)) {
            return 0;
        }

        $removed = 0;
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            if (is_file($file) && @unlink($file)) {
                $removed++;
            }
        }

        return $removed;
    }

    /**
     * 回收「无论哪一页都不可能再命中」的缓存文件。返回删掉的页数与释放字节数。
     *
     * 为什么需要它：get() 过期**故意不删**（省一次 IO，下次 put 会覆盖），
     * put() 也只覆盖同一个 key。于是「被访问过一次、此后再没人要」的页面会永远留着 ——
     * 而键里带完整查询串（筛选、翻页游标、词条），取值空间没有上限。
     * 代价落在两处：磁盘，以及 invalidateTags() —— 它每次发布都要 glob 整个目录
     * 并逐个 file_get_contents + json_decode，陈旧文件越多，每次发布越慢。
     *
     * $maxAge 必须**大于所有页面 TTL**：TTL 是「从 created_at 起算还能不能用」，
     * 拿比最长 TTL 还大的门槛去删，删掉的一定是死文件，不存在误删还能命中的页。
     * 最长 TTL 是 method 页的 3600 秒，调用方给 86400 起步。
     *
     * @param int $maxAge 秒；<=0 视为不合法，直接返回不做事
     *
     * @return array{removed:int, bytes:int}
     */
    public function prune(int $maxAge): array
    {
        if ($maxAge <= 0 || !is_dir($this->dir)) {
            return ['removed' => 0, 'bytes' => 0];
        }

        $cutoff  = time() - $maxAge;
        $removed = 0;
        $bytes   = 0;

        foreach (glob($this->dir . '/*.json') ?: [] as $metaFile) {
            $raw  = @file_get_contents($metaFile);
            $meta = $raw === false ? null : json_decode($raw, true);

            // 元数据读不出来 = 这页已经没人能命中（get 里同样会判 null 并删掉），
            // 这里一并清掉；否则它会一直占着位置，还拖慢每次 glob
            if (!is_array($meta) || !isset($meta['created_at'])) {
                @unlink($metaFile);
                @unlink(substr($metaFile, 0, -5) . '.html');
                $removed++;
                continue;
            }

            if ((int) $meta['created_at'] > $cutoff) {
                continue;
            }

            $html   = substr($metaFile, 0, -5) . '.html';
            $bytes += (int) @filesize($html) + (int) @filesize($metaFile);

            @unlink($metaFile);
            @unlink($html);
            $removed++;
        }

        // put() 在 rename 失败时会留下 .html.tmp，同样按门槛清
        foreach (glob($this->dir . '/*.tmp') ?: [] as $tmp) {
            if ((int) @filemtime($tmp) < $cutoff) {
                @unlink($tmp);
            }
        }

        return ['removed' => $removed, 'bytes' => $bytes];
    }

    /** @return array{pages:int, bytes:int} */
    public function stats(): array
    {
        $pages = 0;
        $bytes = 0;

        foreach (glob($this->dir . '/*.html') ?: [] as $file) {
            $pages++;
            $bytes += (int) @filesize($file);
        }

        return ['pages' => $pages, 'bytes' => $bytes];
    }

    private function base(string $key): string
    {
        return $this->dir . '/' . sha1($key);
    }

    private function delete(string $base): void
    {
        @unlink($base . '.html');
        @unlink($base . '.json');
    }

    private function ensureDir(): bool
    {
        if (is_dir($this->dir)) {
            return is_writable($this->dir);
        }

        return @mkdir($this->dir, 0750, true) || is_dir($this->dir);
    }
}
