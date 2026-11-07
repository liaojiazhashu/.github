<?php

declare(strict_types=1);

namespace App\Http\Controller\Library;

use App\Application\Analysis\TermEncyclopedia;
use App\Application\Library\PublicTextGuard;
use App\Infrastructure\Pdo\LibraryRepository;
use App\Support\Config;
use App\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * 科幻词库：总览与词条详情。
 *
 * 这一层解决的是一句最常被问到的话：「还有哪些作品也写这个东西？」
 * 答案落在 `hq_work_term` 上——它是全站唯一能把「作品」与「概念」连起来的地方。
 */
final class TermsController extends LibraryController
{
    /**
     * 只为本控制器多注入一个 TermEncyclopedia（词条页要看百科两节）。
     * 不动 LibraryController 的构造器：那个基类服务于全部公开页，
     * 为了一页的需求给所有子类多加一个参数，是往基类里堆无关依赖。
     */
    public function __construct(
        View $view,
        LibraryRepository $library,
        Config $config,
        PublicTextGuard $guard,
        private readonly TermEncyclopedia $encyclopedia,
    ) {
        parent::__construct($view, $library, $config, $guard);
    }
    /** 三类顺序固定（v2）：词库只收可被检索的概念，题材与情绪不再进来 */
    private const CATEGORIES = ['setting', 'world', 'image'];

    private const PER_PAGE = 60;

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $query    = $request->getQueryParams();
        $category = (string) ($query['cat'] ?? '');
        $category = in_array($category, self::CATEGORIES, true) ? $category : '';
        $keyword  = $this->clean($query['q'] ?? '');
        $page     = max(1, (int) ($query['page'] ?? 1));

        if ($category === '' && $keyword === '') {
            // 总览：五类各取前 24 个最热的，不埋在一张长列表里
            $groups = [];
            $counts = $this->library->termCategoryCounts();
            foreach (self::CATEGORIES as $code) {
                $groups[] = [
                    'code'  => $code,
                    'total' => $counts[$code] ?? 0,
                    'terms' => $this->library->termIndex($code, '', 24, 0),
                ];
            }

            return $this->render($response, 'library/terms', [
                // 词库这两个页面原来没传 nav，模板里三个 {% if nav == ... %} 全部不成立，
                // 于是导航一个都不高亮 —— 读者在词库里，顶上却看不出自己在哪。
                'nav'        => 'terms',
                'page_title' => '科幻词库 · 银河图书馆',
                'page_desc'  => '按三类浏览馆藏作品的科幻词库：科幻设定、世界观、意象。一个词一条路，顺着走就能找到作品。',
                'canonical'  => $this->config->string('app.url') . '/library/terms',
                'category'   => '',
                'keyword'    => '',
                'groups'     => $groups,
                'rows'       => [],
                'total'      => array_sum($counts),
                'page'       => 1,
                'pages'      => 1,
            ]);
        }

        $total = $this->library->countTerms($category === '' ? null : $category, $keyword);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page  = min($page, $pages);
        $rows  = $this->library->termIndex(
            $category === '' ? null : $category,
            $keyword,
            self::PER_PAGE,
            ($page - 1) * self::PER_PAGE
        );

        $label = $category === '' ? '全部词条' : $this->categoryLabel($category);

        return $this->render($response, 'library/terms', [
            'nav'        => 'terms',
            'page_title' => $label . ' · 银河图书馆',
            'page_desc'  => sprintf('%s：共 %d 个词条，按关联的作品数排序。', $label, $total),
            'canonical'  => $this->config->string('app.url') . '/library/terms',
            'category'   => $category,
            'keyword'    => $keyword,
            'groups'     => [],
            'rows'       => $rows,
            'total'      => $total,
            'page'       => $page,
            'pages'      => $pages,
            'noindex'    => $page > 1 || $keyword !== '',   // 分页与检索结果不进索引，避免重复内容
        ]);
    }

    public function show(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $term = $this->library->findTerm((int) ($args['id'] ?? 0));
        if ($term === null || (string) $term['status'] !== 'active') {
            return $this->notFound($response, '没有这个词条。它可能已被归并到别的词，或已停用。');
        }

        $works = $this->library->worksOfTerm((int) $term['id'], 60);
        $ency  = $this->encyclopedia->find((int) $term['id'], (string) $term['canonical']);

        // 词条释义和百科条目都是人写的长文本，里面可能顺手提到别的词 ——
        // 如果提到的那个词已被隐藏，这里就是一条最容易被忽略的泄漏路径：
        // 页面上明明没有它的入口，却在一段定义里写出了它的名字。
        if (isset($term['definition']) && is_string($term['definition'])) {
            $term['definition'] = $this->guard->text($term['definition']);
        }
        // classics 不动：那是《基地》《沙丘》这类外部经典书名，不是本站词条，
        // 也不会因为站内隐藏某个词就该消失。
        if (is_array($ency) && isset($ency['history']) && is_string($ency['history'])) {
            $ency['history'] = $this->guard->text($ency['history']);
        }

        $cooccur = $this->library->cooccurringTerms((int) $term['id'], 12);

        // 千篇级馆藏后热门词条关联的作品会远超 60：页面按权重只展示最相关的 60 篇，
        // 「全部 N 篇」走 /library/works?term= 的分页列表 —— 承诺的是全部，不偷偷截断
        $worksFilter = ['issue' => 0, 'genre' => '', 'tag' => '', 'term' => (int) $term['id'],
            'year' => 0, 'min_words' => 0, 'max_words' => 0, 'sort' => 'newest'];
        $worksTotal = $this->library->countWorks($worksFilter);

        return $this->render($response, 'library/term', [
            'nav'        => 'terms',
            'page_title' => sprintf('「%s」· 词库词条 | 银河图书馆', (string) $term['canonical']),
            'page_desc'  => sprintf(
                '词库词条「%s」（%s）：释义、起源与发展、世界科幻经典，馆内作品 %d 篇。',
                (string) $term['canonical'],
                $this->categoryLabel((string) $term['category']),
                $worksTotal
            ),
            'canonical'   => $this->config->string('app.url') . '/library/terms/' . $term['id'],
            'term'        => $term,
            'works'       => $works,
            'works_total' => $worksTotal,
            'enc'         => $ency,
            'related'    => $this->library->categoryTerms((int) $term['id'], (string) $term['category'], 12),
            'cooccur'    => $cooccur,
            'categories' => $this->library->termCategoryCounts(),
            'json_ld'    => $this->jsonLd([
                '@context' => 'https://schema.org',
                '@type'    => 'DefinedTerm',
                'name'     => (string) $term['canonical'],
                'description' => sprintf(
                    '%s类词条：%s 馆内作品 %d 篇。',
                    $this->categoryLabel((string) $term['category']),
                    ($ency !== null && $ency['history'] !== '') ? $ency['history'] : ((string) ($term['definition'] ?? '')),
                    $worksTotal
                ),
                'inDefinedTermSet' => ['@type' => 'DefinedTermSet', 'name' => '银河图书馆科幻词库'],
            ]),
        ]);
    }

    private function categoryLabel(string $code): string
    {
        return [
            'setting' => '科幻设定',
            'world'   => '世界观',
            'image'   => '意象',
        ][$code] ?? $code;
    }

    private function clean(mixed $value): string
    {
        $value = trim((string) preg_replace('/[\s\x{3000}]+/u', ' ', (string) $value));

        return mb_substr($value, 0, 40, 'UTF-8');
    }
}
