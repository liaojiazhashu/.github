<?php

declare(strict_types=1);

namespace App\Application\Library;

use App\Infrastructure\Pdo\LibraryRepository;
use App\Support\Config;
use Throwable;

/**
 * 前台「表面」：页面缓存 + 静态 sitemap + 检索索引。
 *
 * 为什么要有这个类：库里的状态改完之后，公开区还有三样东西停在旧世界 ——
 *
 *   1. **页面缓存**（storage/cache/pages）。详情页 TTL 300 秒，编辑内容不改状态，
 *      VisibilityService 那条路不触发，于是「后台改了、前台还是旧的」；
 *   2. **public/sitemap.xml**。它是构建时快照，会盖住动态路由。下线一篇作品后
 *      那条 URL 变成 404，而 sitemap 还指着它 —— 搜索引擎按图索骥过来一片空白。
 *   3. **检索索引**（hq_search_keyword）。它是一张物化表，只在 reindexWork 被
 *      调用时才更新。后台发布、worker 自动上架这两条最常走的路都没调过它，
 *      于是**作品在馆里、在列表里、在 sitemap 里，唯独搜不到** —— 2026-11-02
 *      实测：622 篇已发布作品里只有 18 篇进了索引，站内检索事实上是坏的，
 *      而界面上一句错话都没有（搜不到就显示「没有找到」，看起来像内容少）。
 *
 * 发布 / 下线 / 一键上架 / worker 自动上架 / 编辑产出，走的是五条不同的代码
 * 路径，但对外要改的是同样这三件事。以前散在各控制器里各写一遍，漏一处就变成
 * 上面那三种症状，而且都不会报错 —— 所以收在这里，一处调用。
 *
 * 失败一律吞掉：数据已经提交，事实已生效，不该因为清缓存、写 SEO 文件或重建
 * 索引失败把整次操作判成失败、让人重复点一遍。每一处都留 error_log。
 */
final class PublicSurface
{
    /** 静态 sitemap 的位置：server/public/sitemap.xml */
    private const SITEMAP = __DIR__ . '/../../../public/sitemap.xml';

    public function __construct(
        private readonly PageCache $cache,
        private readonly LibraryRepository $library,
        private readonly Config $config,
        private readonly SearchService $search,
    ) {
    }

    /**
     * 一次发布 / 下线之后要做的三件事，按「便宜的先做」排列。
     *
     * 单独调用点（后台单篇发布、worker 自动上架）用这个，不要自己去拼
     * forget + reindex + refreshSitemap —— 拼漏一步就是本文档开头那三种症状。
     *
     * @param string|null $workCode 作品编号
     * @param list<int>   $termIds  这篇引用、数字会变的词条
     * @param bool        $sitemap  批量场景最后一次传 true，前面几次传 false
     * @param bool        $withCache 批量场景逐条传 false：invalidateTags 是
     *                              「glob 全目录 + 逐个 file_get_contents + json_decode」，
     *                              500 篇就是 500 次全目录扫描；批末一次 forgetMany 就够。
     *                              **检索索引不在此列**，它每篇都必须重建。
     */
    public function afterPublish(
        ?string $workCode,
        array $termIds = [],
        bool $sitemap = true,
        bool $withCache = true
    ): void {
        $this->reindex($workCode);

        if ($withCache) {
            $this->forget($workCode, $termIds);
        }

        if ($sitemap) {
            $this->refreshSitemap();
        }
    }

    /**
     * 重建一篇作品的检索索引。
     *
     * 作品已不公开时这里做的是「删索引」而不是「跳过」—— 下线之后还能搜到，
     * 点进去是 404，比搜不到更糟。所以不判断状态，一律重建。
     */
    public function reindex(?string $workCode): void
    {
        if ($workCode === null || $workCode === '') {
            return;
        }

        try {
            $this->search->reindexByCode($workCode);
        } catch (Throwable $e) {
            error_log('[surface] 检索索引重建失败：' . $e->getMessage());
        }
    }

    /**
     * 批量重建。一键上架一次几十上百篇，逐篇调 reindex 也行，
     * 但这里统一走一遍，方便以后加批量优化（例如合并成一条多行 INSERT）。
     *
     * @param list<string> $workCodes
     * @param bool         $sitemap  只在整个批次结束后写一次
     */
    public function reindexMany(array $workCodes, bool $sitemap = true): void
    {
        foreach ($workCodes as $code) {
            $this->reindex((string) $code);
        }

        if ($sitemap) {
            $this->refreshSitemap();
        }
    }

    /**
     * 让公开区这些页面失效。
     *
     * @param string|null  $workCode  具体作品（详情页、作者页都会跟着变）
     * @param list<int>    $termIds   被这篇引用、数字会变的词条
     * @param bool         $withTerms 词库整体变了（归并、移除、新增）
     */
    public function forget(?string $workCode = null, array $termIds = [], bool $withTerms = false): void
    {
        $tags = ['works'];

        if ($workCode !== null && $workCode !== '') {
            $tags[] = 'work:' . $workCode;
        }

        if ($withTerms) {
            $tags[] = 'terms';
        }

        foreach ($termIds as $termId) {
            $tags[] = 'term:' . (int) $termId;
        }

        try {
            $this->cache->invalidateTags($tags);
        } catch (Throwable $e) {
            // 最差也只是等 TTL 自然过期
            error_log('[surface] 缓存失效失败：' . $e->getMessage());
        }
    }

    /**
     * 批量：一批作品同时变了（一键上架）。
     *
     * @param list<string> $workCodes
     */
    public function forgetMany(array $workCodes): void
    {
        $tags = ['works', 'terms'];

        foreach ($workCodes as $code) {
            $code = (string) $code;
            if ($code !== '') {
                $tags[] = 'work:' . $code;
            }
        }

        try {
            $this->cache->invalidateTags($tags);
        } catch (Throwable $e) {
            error_log('[surface] 批量缓存失效失败：' . $e->getMessage());
        }
    }

    /**
     * 重写静态 sitemap.xml。
     *
     * 只重写不新建：走动态路由的部署本来就没有这个文件，凭空生成一份只会让
     * 两份真相并存。
     */
    public function refreshSitemap(): void
    {
        if (!is_file(self::SITEMAP)) {
            return;
        }

        try {
            SitemapBuilder::write($this->library, $this->config->string('app.url'), self::SITEMAP);
        } catch (Throwable $e) {
            error_log('[surface] sitemap 重写失败：' . $e->getMessage());
        }
    }
}
