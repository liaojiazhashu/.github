<?php

declare(strict_types=1);

namespace App\Http\Controller\Library;

use App\Application\Library\SitemapBuilder;
use App\Infrastructure\Pdo\LibraryRepository;
use App\Support\Config;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * sitemap.xml。只含已发布内容。
 *
 * 动态输出与 `bin/console.php sitemap` 静态化共用 SitemapBuilder ——
 * 两个入口、一份实现，不会出现「命令行生成的和线上看到的不是一回事」。
 *
 * 这里**只允许调用 SitemapBuilder::collect()**，不要自己再拼一份 URL 列表。
 * 原来这个控制器各维护一份，于是 SitemapBuilder 后来加的作者页、练笔统计页
 * 一条都没进来：命令行写出的静态文件是全的，线上动态输出的却少两类，
 * 两边都 200、都不报错，只有搜索引擎那边少收了一千多个页面。
 */
final class SitemapController
{
    public function __construct(
        private readonly LibraryRepository $library,
        private readonly Config $config,
    ) {
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $urls = SitemapBuilder::collect($this->library, rtrim($this->config->string('app.url'), '/'));

        $response->getBody()->write(SitemapBuilder::build($urls));

        return $response
            ->withHeader('Content-Type', 'application/xml; charset=utf-8')
            ->withHeader('Cache-Control', 'public, max-age=3600');
    }
}
