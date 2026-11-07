<?php

declare(strict_types=1);

namespace App\Http\Controller\Library;

use App\Application\Library\Highlight;
use App\Application\Library\PublicTextGuard;
use App\Application\Library\SearchService;
use App\View\View;
use App\Support\Config;
use App\Infrastructure\Pdo\LibraryRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * 检索。不缓存（结果随词表与发布状态变化，且这个入口最需要被限流看见）。
 *
 * 高亮的实现方式见 Highlight：这里只产出「片段 + 是否对上」，HTML 由模板写。
 */
final class SearchController extends LibraryController
{
    public function __construct(
        View $view,
        LibraryRepository $library,
        Config $config,
        PublicTextGuard $guard,
        private readonly SearchService $search,
    ) {
        parent::__construct($view, $library, $config, $guard);
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $query  = (string) ($request->getQueryParams()['q'] ?? '');
        $result = $this->search->search($query);

        // 搜到的位置可能落在摘要之外，把窗口挪过去再切片段
        $rows = [];
        foreach ($result['rows'] as $row) {
            // 摘要原文先过守卫再切片段：反过来的话，被遮的会是已经插了 <mark> 的
            // 字符串，星号会落在标签之间，切出来的片段还带着半截高亮标记。
            $lead = $this->guard->text(
                (string) (($row['one_liner'] ?? '') !== '' ? $row['one_liner'] : ($row['summary'] ?? ''))
            );

            $rows[] = $row + [
                'title_parts' => Highlight::segments((string) $row['title'], $result['query'], 6),
                'lead_parts'  => Highlight::segments(
                    Highlight::excerptAround($lead, $result['query']),
                    $result['query'],
                    8
                ),
            ];
        }

        $modeLabel = [
            'keyword' => '按词条找到',
            'like'    => '按标题与摘要找到',
            'none'    => '没有找到',
        ][$result['mode']] ?? '';

        return $this->render($response, 'library/search', [
            'page_title' => $result['query'] === ''
                ? '检索 · 银河图书馆'
                : sprintf('「%s」的检索结果 · 银河图书馆', $result['query']),
            'page_desc'  => '在馆藏作品里按标题、作者、词库词条与建议标题检索，搜到的地方会高亮。',
            'canonical'  => $this->config->string('app.url') . '/library/search',
            'noindex'    => true,          // 检索结果页不进索引：它会随词表变化，收录进去就是一堆过期页面
            'query'      => $result['query'],
            'rows'       => $rows,
            'mode'       => $result['mode'],
            'mode_label' => $modeLabel,
            // 把「建议词就是你刚搜的那个词」滤掉。编辑距离算法会把同名词条排在
            // 第一位（距离 0），于是页面曾经出现过「没有找到『意识上传』。是不是
            // 想找：意识上传」——推荐的是同一个词，而点进去有 52 篇作品。
            'suggestions' => array_values(array_filter(
                $result['suggestions'],
                static fn (array $s): bool => (string) $s['canonical'] !== $result['query']
            )),
            'hot'        => $this->library->termIndex(null, '', 12, 0),
        ]);
    }
}
