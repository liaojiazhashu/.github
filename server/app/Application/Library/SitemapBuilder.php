<?php

declare(strict_types=1);

namespace App\Application\Library;

use App\Infrastructure\Pdo\LibraryRepository;

/**
 * sitemap.xml 生成。
 *
 * 只收录**已发布**的作品与词条；未发布的东西出现在 sitemap 里等于告诉搜索引擎
 * 「这里有个页面，只是你看不到」，白白攒一堆 404/403 的外链。
 *
 * 交付两种用法，共用这一个生成器（同一个真相）：
 *   · `GET /sitemap.xml` 动态输出（本机、单机部署够用，带 1 小时缓存）
 *   · `bin/console.php sitemap` 落成 `public/sitemap.xml` 静态文件（生产推荐，
 *     让 Nginx 的 try_files 直接返回文件，PHP 完全不参与）
 */
final class SitemapBuilder
{
    /**
     * 收集全部该收录的 URL。
     *
     * 抽出来是为了让「写静态文件」和「清词库之后重写」共用一份真相 ——
     * 两处各写一遍，早晚会出现一份多了词条页、另一份没有。
     *
     * @return list<array{loc:string, lastmod?:string, changefreq?:string, priority?:string}>
     */
    public static function collect(LibraryRepository $library, string $base): array
    {
        $base    = rtrim($base, '/');
        $entries = $library->sitemapEntries();

        $urls = [
            ['loc' => $base . '/library', 'changefreq' => 'daily', 'priority' => '1.0'],
            ['loc' => $base . '/library/works', 'changefreq' => 'daily', 'priority' => '0.9'],
            ['loc' => $base . '/library/authors', 'changefreq' => 'weekly', 'priority' => '0.8'],
            ['loc' => $base . '/library/terms', 'changefreq' => 'weekly', 'priority' => '0.8'],
            /* 练笔统计：每月 16 日才变一次，改月度。放在这里而不是作品之后 ——
               它是一页「社区规模」的说明，检索意图是「荒启科幻练笔多少篇」，
               不是某一篇作品，优先级低于作品与作者。 */
            ['loc' => $base . '/library/practice', 'changefreq' => 'monthly', 'priority' => '0.6'],
        ];

        foreach ($entries['issues'] as $no) {
            $urls[] = ['loc' => $base . '/library/issues/' . $no, 'changefreq' => 'monthly', 'priority' => '0.6'];
        }
        foreach ($entries['works'] as $work) {
            $urls[] = [
                'loc'        => $base . '/library/works/' . $work['code'],
                'lastmod'    => $work['lastmod'] === null ? '' : substr((string) $work['lastmod'], 0, 10),
                'changefreq' => 'monthly',
                'priority'   => '0.8',
            ];
        }
        // 作者页排在作品之后：它是「顺着一篇找到更多」的第二跳，
        // 优先级低于作品本身，但比词条更该被收录（人名是真实的检索意图）。
        foreach ($entries['authors'] ?? [] as $author) {
            $urls[] = [
                'loc'        => $base . '/library/authors/' . $author['code'],
                'lastmod'    => $author['lastmod'] === null ? '' : substr((string) $author['lastmod'], 0, 10),
                'changefreq' => 'weekly',
                'priority'   => '0.7',
            ];
        }
        foreach ($entries['terms'] as $termId) {
            $urls[] = ['loc' => $base . '/library/terms/' . $termId, 'changefreq' => 'weekly', 'priority' => '0.5'];
        }

        return $urls;
    }

    /**
     * 把 sitemap 落成静态文件（生产推荐：让 Nginx 直接返回，PHP 不参与）。
     *
     * 先写临时文件再 rename：写到一半被中断时旧文件还在，不会留下半截 XML
     * 让搜索引擎抓到一份坏 sitemap。
     *
     * 隐藏内容之后必须调用它 —— 静态文件是**快照**，不像动态路由那样每次重建。
     * 藏掉一篇作品却把它留在 sitemap 里，等于告诉搜索引擎「这里有个页面」，
     * 而那边已经是 404。
     *
     * @return int 写入的 URL 条数
     */
    public static function write(LibraryRepository $library, string $base, string $path): int
    {
        $urls = self::collect($library, $base);

        // 临时文件名带上进程号。原来固定叫 sitemap.xml.tmp，而 3 个 worker 会同时
        // 在发布后各写一次 —— 两个进程写同一个临时文件，后 rename 的那个可能是
        // 交错出来的半截 XML，而且谁都不会报错。rename 在同一磁盘上是原子的，
        // 各写各的临时文件之后，最后一个赢，但赢的是一份完整文件。
        $tmp = $path . '.' . getmypid() . '.' . bin2hex(random_bytes(3)) . '.tmp';

        if (file_put_contents($tmp, self::build($urls)) === false || !rename($tmp, $path)) {
            if (is_file($tmp)) {
                unlink($tmp);
            }

            throw new \RuntimeException('sitemap 写入失败：' . $path);
        }

        return count($urls);
    }

    /**
     * @param list<array{loc:string, lastmod?:string, changefreq?:string, priority?:string}> $urls
     */
    public static function build(array $urls): string
    {
        $lines = [
            '<?xml version="1.0" encoding="UTF-8"?>',
            '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">',
        ];

        foreach ($urls as $url) {
            $loc = trim((string) ($url['loc'] ?? ''));
            if ($loc === '') {
                continue;
            }

            $lines[] = '  <url>';
            $lines[] = '    <loc>' . self::escape($loc) . '</loc>';

            if (($url['lastmod'] ?? '') !== '') {
                $lines[] = '    <lastmod>' . self::escape((string) $url['lastmod']) . '</lastmod>';
            }
            if (($url['changefreq'] ?? '') !== '') {
                $lines[] = '    <changefreq>' . self::escape((string) $url['changefreq']) . '</changefreq>';
            }
            if (($url['priority'] ?? '') !== '') {
                $lines[] = '    <priority>' . self::escape((string) $url['priority']) . '</priority>';
            }

            $lines[] = '  </url>';
        }

        $lines[] = '</urlset>';

        return implode("\n", $lines) . "\n";
    }

    /** sitemap 里全部是自造 URL，但仍然转义 —— 作品标题里的 & 曾经毁掉过整份 XML。 */
    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
