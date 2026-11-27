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
        $base = rtrim($base, '/');
        self::assertBase($base);

        $entries = $library->sitemapEntries();

        $urls = [
            /* 官网七页。它们是纯静态文件，Nginx 的 try_files $uri 直接命中，
               根本不经过这个应用 —— 但 sitemap 只有这一处生成器，robots.txt
               又只声明一份 sitemap，所以它们必须在这里露一次面。
               2026-11-27 之前这七页一条都没有：整份 sitemap 只有 /library* 的
               1088 条，搜索引擎除了靠外链，无从知道官网还有六个页面。 */
            ['loc' => $base . '/',               'changefreq' => 'monthly', 'priority' => '1.0'],
            ['loc' => $base . '/about.html',     'changefreq' => 'monthly', 'priority' => '0.8'],
            ['loc' => $base . '/community.html', 'changefreq' => 'monthly', 'priority' => '0.8'],
            ['loc' => $base . '/tools.html',     'changefreq' => 'monthly', 'priority' => '0.8'],
            ['loc' => $base . '/contact.html',   'changefreq' => 'monthly', 'priority' => '0.7'],
            /* 静态介绍页，不是 /library 应用本身。两者内容不同、canonical 各自自指
               （见 tools/build-pages.cjs 的 SITE_URL），所以两条都该在 sitemap 里。 */
            ['loc' => $base . '/library.html',   'changefreq' => 'monthly', 'priority' => '0.7'],
            /* 隐私说明：内容一年也变不了一次，给最低优先级。收录它不是为了排名，
               是让「这家公司有没有隐私政策」这类查询能找到页面。 */
            ['loc' => $base . '/privacy.html',   'changefreq' => 'yearly',  'priority' => '0.3'],

            ['loc' => $base . '/library', 'changefreq' => 'daily', 'priority' => '1.0'],
            ['loc' => $base . '/library/works', 'changefreq' => 'daily', 'priority' => '0.9'],
            ['loc' => $base . '/library/authors', 'changefreq' => 'weekly', 'priority' => '0.8'],
            ['loc' => $base . '/library/terms', 'changefreq' => 'weekly', 'priority' => '0.8'],
            /* 练笔统计：每月 16 日才变一次，改月度。放在这里而不是作品之后 ——
               它是一页「社区规模」的说明，检索意图是「荒启科幻练笔多少篇」，
               不是某一篇作品，优先级低于作品与作者。 */
            ['loc' => $base . '/library/practice', 'changefreq' => 'monthly', 'priority' => '0.6'],
            /* 方法说明。官网 library.html 有两处链到它（「这套结果怎么来的」「看方法」），
               页面本身 canonical 自指、无 noindex，是公开可索引的说明页 ——
               有入口、能打开、却没进 sitemap，等于让搜索引擎只能靠那两条外链发现它。
               内容讲的是口径与边界，一年也改不了几次，所以给 monthly / 0.5。
               /library/search 不收录：那个模板显式输出 noindex, follow。 */
            ['loc' => $base . '/library/method', 'changefreq' => 'monthly', 'priority' => '0.5'],
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
     * 站点绝对地址守卫：APP_URL 忘配时，不许静默产出一份对外无效的 sitemap。
     *
     * settings.php 的 app.url 默认值是 `http://127.0.0.1:8090`。生产漏配 APP_URL 时，
     * 每一个 <loc> 都指向回环地址 —— HTTP 200、条数正确、XML 合法、自检全过，只有
     * 搜索引擎一条都收不进去。没有任何迹象提示配漏了一项，这比报错危险得多。
     *
     * 本机开发不受影响：APP_ENV=local/testing 时本来就用 localhost，放行。
     * 判据是 APP_ENV 而不是 PHP_SAPI —— `php bin/console.php sitemap` 与 fpm 一样
     * 会把结果落成对外文件，只看 SAPI 会让命令行这条路径漏过去。
     */
    public static function assertBase(string $base): void
    {
        $host = strtolower((string) parse_url($base, PHP_URL_HOST));
        if (!in_array($host, ['127.0.0.1', 'localhost', '::1', '0.0.0.0'], true)) {
            return;
        }

        $env = $_ENV['APP_ENV'] ?? getenv('APP_ENV');
        $env = is_string($env) ? strtolower(trim($env)) : '';
        if (in_array($env, ['local', 'development', 'testing', 'test'], true)) {
            return;
        }

        throw new \RuntimeException(
            'app.url 指向回环地址（' . $base . '），整份 sitemap 对外无效。'
            . '请在 server/.env 设置 APP_URL=https://正式域名。'
        );
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
