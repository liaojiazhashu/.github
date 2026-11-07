<?php

declare(strict_types=1);

namespace App\Http\Support;

use App\Infrastructure\Pdo\LibraryRepository;
use App\Support\Config;

/**
 * 公开页的公共模板变量。
 *
 * 抽出来的唯一理由：**顶层错误处理器也要渲染前台 404**。它拿不到控制器实例，
 * 如果那段默认值只写在 LibraryController 里，错误页就得复制一份 —— 而这个系统里
 * 「同一件事有两份写法」几乎必然会在某次改动后漂移（见 MEMORY 里那些踩坑记录）。
 *
 * Twig 开了 strict_variables，缺任何一个变量都直接抛错，所以这里的每一项都是
 * 「布局模板会用到」而不是「某些页面可能用到」。
 */
final class PublicPageData
{
    /**
     * @return array<string, mixed>
     */
    public static function build(LibraryRepository $library, Config $config): array
    {
        $method = $config->string('library.method_url', '');

        return [
            'stats'      => $library->stats(),
            'site_url'   => rtrim($config->string('app.url'), '/'),
            'method_url' => $method,
            'page_title' => $config->string('app.name', '银河图书馆'),
            'page_desc'  => '荒启科幻对入馆作品做的结构化拆解：关键词索引、剧情分析、建议、修改方向。',
            'canonical'  => null,
            'noindex'    => false,
            'json_ld'    => null,
            'nav'        => '',
            'asset_v'    => self::assetVersion($config),
            'site_home'  => self::siteHome($method),
        ];
    }

    /**
     * 静态资源版本号（取文件修改时间）。
     *
     * 用它而不是自己维护一个 v=3：CSS 改了但忘了改版本号，读者会拿着旧样式看上一天，
     * 而这种问题在开发机上永远复现不了。
     */
    public static function assetVersion(Config $config, string $file = 'library.css'): string
    {
        $path = $config->string('app.root') . '/public/assets/' . $file;

        return (string) (@filemtime($path) ?: '1');
    }

    /**
     * 静态站首页地址（页脚「荒启科幻」那条链接）。
     *
     * 从 METHOD_URL 反推 origin：同域时它是 `/library.html`，推不出 origin，
     * 那就干脆不显示这条链接 —— 宁可少一个链接，也不要链到不存在的地址上。
     */
    public static function siteHome(string $methodUrl): string
    {
        if (preg_match('#^(https?://[^/]+)#', $methodUrl, $m) !== 1) {
            return '';
        }

        return $m[1] . '/';
    }
}
