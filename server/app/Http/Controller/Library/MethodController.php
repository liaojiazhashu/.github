<?php

declare(strict_types=1);

namespace App\Http\Controller\Library;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * 方法说明：这套结果是怎么来的。
 *
 * 为什么在馆内而不在官网（v2.4）：官网那一页的职责是「讲这座馆是什么、把人送进来」，
 * 方法论属于**产品内部说明书** —— 人已经站在馆里，正看着某一页的结论，才会问
 * 「这条判断凭什么」。放在官网，读者点进来还得再跳一次；放在官网之外，四个
 * 「这套结果怎么来的」链接就会指向一片空地。
 *
 * 这一页只写口径与边界，不写实现细节（哪张表、哪个模型、几个 token）——
 * 那是 server/README 与规划文档的事。
 */
final class MethodController extends LibraryController
{
    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        // 页面上的示例词一律从词表实时取，不写死。
        // 写死的词一旦被后台隐藏，这一页就会公开点名一个不该出现的词 ——
        // 而它正是全站唯一解释「词怎么收」的地方，不能自己先破规矩。
        $samples = $this->library->termIndex('setting', '', 2, 0);
        $merge   = $this->library->mergeExample();

        return $this->render($response, 'library/method', [
            'page_title' => '方法说明：这套结果是怎么来的 · 银河图书馆',
            'page_desc'  => '银河图书馆的拆解口径：词库怎么收词、两页各写什么、结论凭什么可信、以及它刻意不做什么。',
            'canonical'  => $this->config->string('app.url') . '/library/method',
            'nav'        => 'method',
            'samples'    => array_map(static fn (array $t): string => (string) $t['canonical'], $samples),
            'merge'      => $merge,
        ]);
    }
}
