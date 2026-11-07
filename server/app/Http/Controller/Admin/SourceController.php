<?php

declare(strict_types=1);

namespace App\Http\Controller\Admin;

use App\Application\Source\AuthorActivity;
use App\Application\Source\SourceApi;
use App\Application\Source\SourceCatalog;
use App\Support\Session;
use App\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

/**
 * 站内文章（官网内容源）。
 *
 * 后台要能回答一个问题：官网这 1843 篇里，哪些是练笔、哪些已经能拿来用。
 * 页面给三层口径，数字必须能对上：
 *
 *   全站 1843 → 参加练笔 1375 → 可用的 1177
 *
 * 三个数字的差就是被删/未公开/非练笔的部分 —— 运营看到「练笔 1375、可用 1177」
 * 立刻知道有 198 篇要么没公开要么被删了，而不用自己去翻。
 *
 * 「可用」是四个条件：正式稿（`type = post`）、没删、公开、已发布。
 * 第四条是后补的 —— 有一批草稿在列表接口里三个状态字段全合格，
 * 但详情接口一律「暂未公开访问」，见 SourceCatalog::isAvailable。
 *
 * 这里**只读**，不写任何东西到官网，也不自动导入到馆藏。导入是另一件事：
 * 那要决定「导进来算作品还是素材」「作者账号怎么认领」，不该顺手做掉。
 */
final class SourceController extends AdminController
{
    private const PER_PAGE = 30;

    /** 三个视图口径。白名单之外一律回落到默认值，不接受任意字符串 */
    private const SCOPES = ['practice_ready', 'practice', 'all'];

    public function __construct(
        View $view,
        Session $session,
        private readonly SourceCatalog $catalog,
        private readonly SourceApi $api,
        private readonly AuthorActivity $activity,
    ) {
        parent::__construct($view, $session);
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $params = $request->getQueryParams();

        $scope = (string) ($params['scope'] ?? 'practice_ready');
        if (!in_array($scope, self::SCOPES, true)) {
            $scope = 'practice_ready';
        }
        $keyword = trim((string) ($params['q'] ?? ''));
        $issue   = max(0, (int) ($params['issue'] ?? 0));
        $page    = max(1, (int) ($params['page'] ?? 1));

        $cache = $this->catalog->load();

        if ($cache === null) {
            // 没有缓存不是错误，是「还没同步过」。页面照常渲染，只是给一个同步按钮。
            return $this->render($response, 'admin/source/index', [
                'nav'         => 'source',
                'user'        => (array) $request->getAttribute('user', []),
                'page_title'  => '站内文章',
                'missing'     => true,
                'scope'       => $scope,
                'q'           => $keyword,
                'issue'       => $issue,
                'stats'       => null,
                'issues'      => [],
                'items'       => [],
                'pagination'  => null,
                'scopes'      => $this->scopeLabels(),
            ]);
        }

        $all = $cache['items'];

        // 顺序：先按范围筛，再按期次筛，最后按关键词筛。
        // 「练笔可用 1197 篇 → 第 22 期 91 篇 → 其中含『飞船』的 3 篇」，逐层收窄才读得懂。
        $filtered = $this->catalog->filter($all, $scope);
        $filtered = $this->catalog->filterIssue($filtered, $issue);
        $filtered = $this->catalog->search($filtered, $keyword);

        $total = count($filtered);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        if ($page > $pages) {
            $page = $pages;
        }

        $items = array_slice($filtered, ($page - 1) * self::PER_PAGE, self::PER_PAGE);

        return $this->render($response, 'admin/source/index', [
            'nav'         => 'source',
            'user'        => (array) $request->getAttribute('user', []),
            'page_title'  => '站内文章',
            'missing'     => false,
            'scope'       => $scope,
            'q'           => $keyword,
            'issue'       => $issue,
            'stats'       => $this->catalog->stats($all),
            'issues'      => $this->catalog->issueStats($this->catalog->filter($all, 'practice')),
            'items'       => $items,
            'pagination'  => [
                'page'  => $page,
                'pages' => $pages,
                'total' => $total,
            ],
            'scopes'      => $this->scopeLabels(),
        ]);
    }

    /**
     * 作者活跃度：近一年有没有投稿或评稿。
     *
     * 这一页回答的是「先拆谁的」——1197 篇不能平铺着拆，得先知道哪些作者还在站里、
     * 哪些已经一年多没动静。和列表页的口径必须一致：都从「练笔可用」出发，
     * 差别只在于这里按作者聚合。
     *
     * 数据来自离线快照（`source:activity` 跑出来的 activity.json），不在页面里
     * 现查 —— 一次要问两百多个人，页面等不起。
     */
    public function activity(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $params = $request->getQueryParams();

        $state = (string) ($params['state'] ?? AuthorActivity::ACTIVE);
        if (!isset(AuthorActivity::LABELS[$state])) {
            $state = AuthorActivity::ACTIVE;
        }
        $page = max(1, (int) ($params['page'] ?? 1));

        $cache = $this->catalog->load();
        $snap  = $this->activity->load();

        if ($cache === null || $snap === null) {
            return $this->render($response, 'admin/source/activity', [
                'nav'        => 'source',
                'user'       => (array) $request->getAttribute('user', []),
                'page_title' => '作者分类',
                'subnav'     => 'activity',
                'missing'    => true,
                'state'      => $state,
                'labels'     => AuthorActivity::LABELS,
                'meta'       => $snap['meta'] ?? null,
                'summary'    => [],
                'authors'    => [],
                'anon'       => ['works' => 0, 'words' => 0],
                'pagination' => null,
            ]);
        }

        $works  = $this->catalog->filter($cache['items'], 'practice_ready');
        $tagged = $this->activity->tagItems($works, $snap['authors']);
        $sum    = $this->activity->summarize($tagged);

        // 按作者聚合。匿名作品单独计 —— 它不属于任何一位可指认的作者，
        // 若按 uid 混进作者行，等于用 UID 把匿名揭开了。
        $rows = [];
        $anon = ['works' => 0, 'words' => 0];
        foreach ($tagged as $i) {
            if ((string) $i['activity'] === AuthorActivity::ANON) {
                ++$anon['works'];
                $anon['words'] += (int) ($i['wordCount'] ?? 0);
                continue;
            }

            $uid = (int) ($i['authorUid'] ?? 0);
            $a   = $i['author_row'] ?? [];

            $rows[$uid] ??= [
                'uid'               => $uid,
                'name'              => (string) ($a['name'] ?? $i['authorName'] ?? ''),
                'state'             => (string) $i['activity'],
                'works'             => 0,
                'words'             => 0,
                'posts'             => (int) ($a['posts'] ?? 0),
                'latest_post_at'    => (int) ($a['latest_post_at'] ?? 0),
                'review_total'      => (int) ($a['review_total'] ?? 0),
                'latest_review_at'  => (int) ($a['latest_review_at'] ?? 0),
                'comment_total'     => (int) ($a['comment_total'] ?? 0),
                'latest_comment_at' => (int) ($a['latest_comment_at'] ?? 0),
            ];
            ++$rows[$uid]['works'];
            $rows[$uid]['words'] += (int) ($i['wordCount'] ?? 0);
        }

        $rows = array_values($rows);
        // 排法：先按篇数（篇数多的作者影响大），同篇数按最近投稿
        usort($rows, static fn (array $a, array $b): int
            => [$b['works'], $b['latest_post_at']] <=> [$a['works'], $a['latest_post_at']]);

        $filtered = array_values(array_filter($rows, static fn (array $r): bool => $r['state'] === $state));
        $total    = count($filtered);
        $pages    = max(1, (int) ceil($total / self::PER_PAGE));
        if ($page > $pages) {
            $page = $pages;
        }

        return $this->render($response, 'admin/source/activity', [
            'nav'        => 'source',
            'user'       => (array) $request->getAttribute('user', []),
            'page_title' => '作者分类',
            'subnav'     => 'activity',
            'missing'    => false,
            'state'      => $state,
            'labels'     => AuthorActivity::LABELS,
            'meta'       => $snap['meta'],
            'summary'    => $sum,
            'authors'    => array_slice($filtered, ($page - 1) * self::PER_PAGE, self::PER_PAGE),
            'anon'       => $anon,
            'pagination' => [
                'page'  => $page,
                'pages' => $pages,
                'total' => $total,
            ],
        ]);
    }

    public function show(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $cid = (int) ($args['cid'] ?? 0);

        // 详情实时拉：目录缓存里没有全文（刻意不存，见 SourceCatalog::normalize），
        // 而且刚发布的作品可能在两次同步之间。
        $article = $this->api->detail($cid);

        if ($article === null) {
            return $this->render($response, 'admin/source/show', [
                'nav'        => 'source',
                'user'       => (array) $request->getAttribute('user', []),
                'page_title' => '文章 #' . $cid,
                'cid'        => $cid,
                'article'    => null,
                'issue'      => ['no' => 0, 'label' => '', 'endsAt' => 0],
            ], 404);
        }

        // 接口返回的 text 是**HTML 源码**（尽管传了 isMd=0），满屏 <p><span style=...>。
        // 后台要看的是「这段文字能不能用」，不是标签，所以这里转一份纯文本给主视图；
        // 原文收进折叠区，需要核对格式时再展开。
        $article['plain_text'] = $this->toPlainText((string) ($article['text'] ?? ''));

        // 期号只能从活动名解析（slug 有三种错拼，见 SourceCatalog 类注释）。
        // 详情页比列表页多一样东西：活动的 deadline，也就是官网自己记的截稿时刻。
        $issue = ['no' => 0, 'label' => '', 'endsAt' => 0];
        foreach (($article['active'] ?? []) as $a) {
            if (!is_array($a) || ($a['type'] ?? '') !== 'active') {
                continue;
            }
            $name = (string) ($a['name'] ?? '');
            if (mb_strpos($name, SourceCatalog::PRACTICE_MARK) === false) {
                continue;
            }
            if (!preg_match('/第\s*(.+?)\s*期/u', $name, $m)) {
                continue;
            }
            $no = SourceCatalog::parseChineseNumber($m[1]);
            if ($no > $issue['no']) {
                $issue = [
                    'no'     => $no,
                    'label'  => '第 ' . $no . ' 期',
                    'endsAt' => (int) ($a['deadline'] ?? 0),
                ];
            }
        }

        return $this->render($response, 'admin/source/show', [
            'nav'        => 'source',
            'user'       => (array) $request->getAttribute('user', []),
            'page_title' => (string) ($article['title'] ?? '文章') . ' · 站内文章',
            'cid'        => $cid,
            'article'    => $article,
            'issue'      => $issue,
        ]);
    }

    /**
     * HTML → 可读纯文本。
     *
     * 只做「块级标签转换行、再剥标签、再解实体」三步，不做排版还原。
     * 顺序很重要：先换行再 strip_tags，否则段落会全部粘成一行。
     */
    private function toPlainText(string $html): string
    {
        if ($html === '') {
            return '';
        }

        $text = (string) preg_replace(
            '#<(?:br|/p|/div|/h[1-6]|/li|/blockquote|/tr|/table)\s*/?>#i',
            "\n",
            $html
        );
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = (string) preg_replace('/[ \t]+/u', ' ', $text);
        $text = (string) preg_replace('/\n{3,}/u', "\n\n", $text);

        return trim($text);
    }

    /**
     * 导出当前筛选结果为 CSV。
     *
     * 导出的**不是页面那一页**，而是当前筛选条件下的全部 —— 页面一页 30 条，
     * 运营要的是「第 22 期这 91 篇的字数、作者」，一页一页复制没有意义。
     *
     * 参数与列表页完全一致（scope / issue / q），从列表页点「导出」带过来的就是
     * 当前看到的条件，所见即所导。
     */
    public function export(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $params = $request->getQueryParams();

        $scope = (string) ($params['scope'] ?? 'practice_ready');
        if (!in_array($scope, self::SCOPES, true)) {
            $scope = 'practice_ready';
        }
        $keyword = trim((string) ($params['q'] ?? ''));
        $issue   = max(0, (int) ($params['issue'] ?? 0));

        $cache = $this->catalog->load();
        if ($cache === null) {
            $this->session->flash('error', '还没有同步过目录，先同步再导出。');

            return $this->redirect($response, '/admin/source');
        }

        $items = $this->catalog->filter($cache['items'], $scope);
        $items = $this->catalog->filterIssue($items, $issue);
        $items = $this->catalog->search($items, $keyword);

        $csv = $this->catalog->toCsv($items);

        $name = sprintf(
            '荒启科幻练笔-%s-%s.csv',
            $issue > 0 ? '第' . $issue . '期' : $this->scopeLabels()[$scope],
            date('Ymd-Hi')
        );

        // 中文文件名必须走 RFC 5987 的 filename*：HTTP 头是 latin-1，
        // 直接写中文会让浏览器收到乱码文件名，或者干脆丢掉扩展名。
        $response->getBody()->write($csv);

        return $response
            ->withHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->withHeader('Content-Disposition', sprintf(
                'attachment; filename="source-export.csv"; filename*=UTF-8\'\'%s',
                rawurlencode($name)
            ))
            ->withHeader('Content-Length', (string) strlen($csv))
            // 导出的是运营数据，不该被任何中间层缓存住
            ->withHeader('Cache-Control', 'no-store');
    }

    /**
     * 同步入口。内置服务器是单进程，这一跑就是一二十秒，期间别的请求会被挡住 ——
     * 所以只在后台手动触发，不做成定时/自动。真要自动化就走命令行
     * `php bin/console.php source:sync`，让运维自己排进计划任务。
     */
    public function sync(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        try {
            $result = $this->catalog->sync();
            $this->session->flash('success', sprintf(
                '已同步：全站 %d 篇（%d 页）。',
                $result['fetched'],
                $result['pages']
            ));
        } catch (Throwable $e) {
            $this->session->flash('error', '同步失败：' . $e->getMessage());
        }

        return $this->redirect($response, '/admin/source');
    }

    /**
     * @return array<string, string>
     */
    private function scopeLabels(): array
    {
        return [
            'practice_ready' => '练笔可用',
            'practice'       => '练笔全部',
            'all'            => '全站',
        ];
    }
}
