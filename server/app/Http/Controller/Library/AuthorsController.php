<?php

declare(strict_types=1);

namespace App\Http\Controller\Library;

use App\Application\Library\Cursor;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * 作者页：一个人名下的全部馆内作品。
 *
 * 为什么要有这一页：作品卡上写着作者名，读者喜欢一篇之后第一反应就是
 * 「这个人的其它篇在哪」——此前那条线是断的，作者名只是几个不能点的字。
 * 1300 篇入馆以后，同一个人名下会有三五篇到十几篇，没有聚合页就等于
 * 让人一篇篇去列表里翻。
 *
 * 这一页**只认 code，不认名字**。笔名会改、会重名、会被写成全半角两种；
 * hq_author.code 是 ULID，改笔名不影响链接，也不会把两个人并成一个人。
 *
 * 篇数、字数、卡片、词条全部走 LIVE_WORK 那套条件：写了什么由可见性决定。
 * 但**页面在不在**由「馆内有没有这个人的作品记录」决定，不由可见性决定。
 *
 * 这里曾经反过来写过 —— 一个人所有作品都被隐藏，作者页就 404。那是错的：
 * 隐藏一篇作品是让这篇作品在馆里消失，不是让作者从来没写过。整页 404 等于
 * 在拆解级隐藏之外又开了一层作者级隐藏，用户没有授权过这件事。
 * 现在 0 篇就是 0 篇：页面照常打开，写「馆内作品 0 篇」，不摆卡片、不列词条
 * —— 那才是「作品被藏了」本来的样子。
 */
final class AuthorsController extends LibraryController
{
    /**
     * 作者索引：谁在馆里写东西，写得多少。
     *
     * 为什么要有这一页：作者页此前只有一个入口 —— 某篇作品页顶端那一行作者名。
     * 那意味着「先找到一篇你喜欢的」是唯一的路径，而这恰恰是读者最不常有的一步：
     * 他是想看某个人的全部，而不是从 1111 篇里碰运气撞到一篇。
     *
     * 排序用「馆内可见篇数」，不按姓名字典序：这里的读者问题是「谁写得最多」，
     * 不是「找 Z 开头的名字」。姓名倒序那种排法属于通讯录，不属于藏书楼。
     */
    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $rows  = $this->library->listAuthors(400);
        $total = $this->library->countAuthors();

        return $this->render($response, 'library/authors', [
            'page_title' => '作者 · 银河图书馆',
            'page_desc'  => sprintf(
                '馆内 %s 位作者，按作品数排列。点一个名字，看这个人在馆里的全部作品，以及他反复写到的概念。',
                number_format($total)
            ),
            'canonical'  => $this->config->string('app.url') . '/library/authors',
            'nav'        => 'authors',
            'rows'       => $rows,
            'total'      => $total,
            'shown'      => count($rows),
            'json_ld'    => $this->jsonLd([
                '@context' => 'https://schema.org',
                '@type'    => 'CollectionPage',
                'name'     => '作者 · 银河图书馆',
                'numberOfItems' => $total,
                'about'    => ['@type' => 'Thing', 'name' => '馆内作者'],
            ]),
        ]);
    }

    public function show(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $code   = (string) ($args['code'] ?? '');
        $author = $this->library->findAuthorByCode($code);

        if ($author === null) {
            return $this->notFound($response, '没有这位作者。可能名字记错了，也可能他的作品还没进馆。');
        }

        $limit = max(1, min(48, $this->config->int('library.per_page', 12)));

        // 与列表页共用同一套双向游标：一位作者写满十几篇之后，
        // 一页 12 条仍然会翻页，固定全量渲染既不必要也拖慢首屏。
        $query    = $request->getQueryParams();
        $filter   = ['author' => $code, 'sort' => 'newest'] + [
            'issue' => 0, 'genre' => '', 'tag' => '', 'term' => 0,
            'year' => 0, 'min_words' => 0, 'max_words' => 0,
        ];
        $cursor   = Cursor::decode((string) ($query['cursor'] ?? ''));
        $before   = Cursor::decode((string) ($query['before'] ?? ''));
        $backward = $cursor === null && $before !== null;
        $result   = $this->library->listWorks($filter, $limit, $backward ? $before : $cursor, $backward);
        $total    = $this->library->countWorks($filter);

        $base    = '/library/authors/' . rawurlencode($code);
        $nextUrl = $result['next'] !== null
            ? $base . '?' . http_build_query(['cursor' => Cursor::encode($result['next'])])
            : null;
        $prevUrl = $result['prev'] !== null
            ? $base . '?' . http_build_query(['before' => Cursor::encode($result['prev'])])
            : null;
        $firstUrl = ($cursor !== null || $before !== null) ? $base : null;

        $name  = (string) $author['name'];
        $works = (int) $author['works'];
        $words = (int) $author['words'];

        return $this->render($response, 'library/author', [
            'page_title' => $name . ' · 作者 | 银河图书馆',
            'page_desc'  => $works === 0
                ? sprintf('%s在银河图书馆的作者页。', $name)
                : mb_substr(sprintf(
                    '%s在银河图书馆的 %d 篇作品，共 %s 字，已做关键词索引、剧情分析与修改建议。',
                    $name,
                    $works,
                    number_format($words)
                ), 0, 120),
            'canonical'  => $this->config->string('app.url') . '/library/authors/' . rawurlencode($code),
            'nav'        => 'works',
            'author'     => $author,
            'rows'       => $this->maskRows($result['rows']),
            'terms'      => $this->library->authorTerms((int) $author['id'], 16),
            'total'      => $total,
            'next_url'   => $nextUrl,
            'prev_url'   => $prevUrl,
            'first_url'  => $firstUrl,
            'json_ld'    => $this->jsonLd([
                '@context' => 'https://schema.org',
                '@type'    => 'ProfilePage',
                'mainEntity' => [
                    '@type' => 'Person',
                    'name'  => $name,
                    'description' => (string) ($author['bio'] ?? ''),
                ],
                'about' => [
                    '@type' => 'CollectionPage',
                    'name'  => $name . '在银河图书馆的作品',
                    'numberOfItems' => $works,
                ],
            ]),
        ]);
    }
}
