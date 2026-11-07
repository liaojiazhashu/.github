<?php

declare(strict_types=1);

namespace App\Http\Controller\Library;

use App\Application\Library\Cursor;
use App\Infrastructure\Pdo\AnalysisRepository;
use App\Infrastructure\Pdo\WorkRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * 馆藏首页 / 作品列表 / 作品详情 / 按期次。
 *
 * 详情页是本模块的重头：它把四件产出按「索引卡 → 五类词 → 剧情 → 建议 → 修改方向 → 元信息」
 * 的顺序摆出来，对应静态方法页讲的那套方法。方法页讲「为什么这么做」，这里给「这么做出来是什么」。
 */
final class WorksController extends LibraryController
{
    public function __construct(
        \App\View\View $view,
        \App\Infrastructure\Pdo\LibraryRepository $library,
        \App\Support\Config $config,
        // 前台唯一一道文本守卫。四个方法（首页/列表/详情/期次）都要用它：
        // 拆解产物里的词条名是模型写进句子的自由文本，SQL 层看不见。
        \App\Application\Library\PublicTextGuard $guard,
        private readonly AnalysisRepository $analyses,
        private readonly WorkRepository $works,
    ) {
        parent::__construct($view, $library, $config, $guard);
    }

    // ------------------------------------------------------------- 馆藏首页

    public function home(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $latest  = $this->library->listWorks([], 6);
        $counts  = $this->library->termCategoryCounts();
        $cats    = [];

        // 三类各取前 6 个最热的：首页要的是「这类词都长什么样」，不是完整清单。
        // v2 只有三类 —— 题材与情绪不再进词库（它们描述文风，不是可检索的概念）。
        foreach (['setting', 'world', 'image'] as $code) {
            $cats[] = [
                'code'  => $code,
                'total' => $counts[$code] ?? 0,
                'terms' => $this->library->termIndex($code, '', 6, 0),
            ];
        }

        return $this->render($response, 'library/home', [
            'page_title'  => '银河图书馆 · 馆藏与拆解 | 荒启科幻',
            'page_desc'   => '荒启科幻的动态馆藏：每篇入馆作品都有关键词索引、剧情分析、建议与修改方向四件产出，按词条或标题即可检索。',
            'canonical'   => $this->config->string('app.url') . '/library',
            'nav'         => 'home',
            'latest'      => $this->maskRows($latest['rows']),
            'issues'      => $this->library->publishedIssues(),
            'authors'     => $this->library->listAuthors(8),
            'author_total' => $this->library->countAuthors(),
            'cats'        => $cats,
            'json_ld'     => $this->jsonLd([
                '@context' => 'https://schema.org',
                '@type'    => 'CollectionPage',
                'name'     => '银河图书馆',
                'description' => '荒启科幻对入馆作品做的结构化拆解。',
                'isPartOf' => ['@type' => 'WebSite', 'name' => '荒启科幻', 'url' => $this->config->string('app.url')],
            ]),
        ]);
    }

    // ------------------------------------------------------------- 作品列表

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $query  = $request->getQueryParams();
        $filter = $this->filterFrom($query);
        $limit  = max(1, min(48, $this->config->int('library.per_page', 12)));

        // 按作者筛（?author=ULID）。**只在这一位作者根本不存在时**才把条件丢掉：
        // 手改地址栏、或那个人与馆内作品毫无关系，此时「什么都筛」比「筛出空」更友好。
        // 但「人在、作品目前都不在公开区」不算不存在 —— 那种情况条件必须留着，
        // 否则读者点了作者名，页面却悄悄变成全馆列表，比空列表更让人困惑。
        $authorCode = (string) $filter['author'];
        $authorName = '';
        if ($authorCode !== '') {
            $author = $this->library->findAuthorByCode($authorCode);
            if ($author === null) {
                $filter['author'] = '';
                $authorCode       = '';
            } else {
                $authorName = (string) $author['name'];
            }
        }

        // 下一页/上一页链接在 PHP 里拼好：Twig 里拼查询串既难读，也容易把空值带成 `issue=0`
        $carry = array_filter([
            'issue'  => (int) $filter['issue'] ?: null,
            'genre'  => (string) $filter['genre'] ?: null,
            'tag'    => (string) $filter['tag'] ?: null,
            'term'   => (int) $filter['term'] ?: null,
            'author' => $authorCode ?: null,
            'year'   => (int) $filter['year'] ?: null,
            'range'  => $this->rangeOf($filter),
            'sort'   => (string) $filter['sort'] !== 'newest' ? (string) $filter['sort'] : null,
        ], static fn ($v): bool => $v !== null);

        // cursor=向后翻的锚（上一页最后一行）；before=向前翻的锚（本页第一行）。
        // 两者同时出现按 cursor 走 —— 正常导航不会发生，只防手拼 URL。
        $cursor   = Cursor::decode((string) ($query['cursor'] ?? ''));
        $before   = Cursor::decode((string) ($query['before'] ?? ''));
        $backward = $cursor === null && $before !== null;
        $result   = $this->library->listWorks($filter, $limit, $backward ? $before : $cursor, $backward);
        $total    = $this->library->countWorks($filter);

        $nextUrl = $result['next'] !== null
            ? '/library/works?' . http_build_query($carry + ['cursor' => Cursor::encode($result['next'])])
            : null;
        $prevUrl = $result['prev'] !== null
            ? '/library/works?' . http_build_query($carry + ['before' => Cursor::encode($result['prev'])])
            : null;
        $firstUrl = ($cursor !== null || $before !== null) && $carry !== []
            ? '/library/works?' . http_build_query($carry)
            : (($cursor !== null || $before !== null) ? '/library/works' : null);

        return $this->render($response, 'library/works', [
            'page_title' => $this->listTitle($filter, $authorName) . ' · 银河图书馆',
            'page_desc'  => $authorName !== ''
                ? sprintf('%s在银河图书馆的全部作品：每篇都有关键词索引、剧情分析、建议与修改方向。', $authorName)
                : '按期次、体裁、标签、字数浏览已发布作品的拆解，每篇都有关键词索引、剧情分析、建议与修改方向。',
            'canonical'  => $this->config->string('app.url') . '/library/works',
            'nav'        => 'works',
            'heading'    => $this->listTitle($filter, $authorName),
            'filter'     => $filter + ['range' => $this->rangeOf($filter)],
            'rows'       => $this->maskRows($result['rows']),
            'next_url'   => $nextUrl,
            'prev_url'   => $prevUrl,
            'first_url'  => $firstUrl,
            'total'      => $total,
            'options'    => $this->library->filterOptions(),
            'active'     => $this->activeChips($filter, $query, $authorName),
        ]);
    }

    /** 内部用 min/max 两个整数表达字数区间，界面上是一个「3000–6000」下拉 —— 这里互换 */
    private function rangeOf(array $filter): string
    {
        $min = (int) $filter['min_words'];
        $max = (int) $filter['max_words'];
        if ($min === 0 && $max === 0) {
            return '';
        }

        return $min . '-' . $max;
    }

    /**
     * @param array<string,mixed> $query
     * @return array<string,mixed>
     */
    private function filterFrom(array $query): array
    {
        // 字数用「3000-6000」这种区间串，解析成 min/max 两个整数 ——
        // SQL 侧只认整数，界面侧不该出现两个输入框让人填。
        $min = (int) ($query['min'] ?? 0);
        $max = (int) ($query['max'] ?? 0);

        $range = trim((string) ($query['range'] ?? ''));
        if ($range !== '' && preg_match('/^(\d+)-(\d+)$/', $range, $m) === 1) {
            $min = (int) $m[1];
            $max = (int) $m[2];
        }

        return [
            'issue'     => (int) ($query['issue'] ?? 0),
            'genre'     => (string) ($query['genre'] ?? ''),
            'tag'       => (string) ($query['tag'] ?? ''),
            'term'      => (int) ($query['term'] ?? 0),
            'author'    => (string) ($query['author'] ?? ''),
            'year'      => (int) ($query['year'] ?? 0),
            'min_words' => max(0, $min),
            'max_words' => max(0, $max),
            'sort'      => (string) ($query['sort'] ?? 'newest'),
        ];
    }

    /** @param array<string,mixed> $filter */
    private function listTitle(array $filter, string $authorName = ''): string
    {
        // 按作者筛时标题直接写人名：「李张澜的作品」比「馆藏作品 · 李张澜」更像人话
        $parts = [$authorName !== '' ? $authorName . '的作品' : '馆藏作品'];

        if ((int) $filter['issue'] > 0) {
            $parts[] = '第 ' . (int) $filter['issue'] . ' 期';
        }
        if ((string) $filter['genre'] !== '') {
            $parts[] = (string) $filter['genre'];
        }
        // 「标签」不再出现在这里：`options.tags` 从来没有被赋过值，模板里那块
        // 标签条一次都没渲染过，而 `?tag=` 参数进了筛选却没人用它查库 ——
        // 唯一的实际效果是让 /library/works?tag=第一接触 返回「共 0 篇」。
        // 想去词条页按词找作品，走 ?term=（词条页的「全部 N 篇」就是这个）。

        return implode(' · ', $parts);
    }

    /**
     * 当前生效的筛选条件（每个都能单独摘掉）。
     *
     * @param array<string,mixed> $filter
     * @param array<string,mixed> $query
     * @return list<array{label:string, href:string}>
     */
    private function activeChips(array $filter, array $query, string $authorName = ''): array
    {
        $chips = [];
        $drop  = static function (string $key) use ($query): string {
            unset($query[$key], $query['cursor']);
            $rest = http_build_query(array_filter($query, static fn ($v): bool => $v !== '' && $v !== 0));

            return '/library/works' . ($rest === '' ? '' : '?' . $rest);
        };

        if ((int) $filter['issue'] > 0) {
            $chips[] = ['label' => '第 ' . (int) $filter['issue'] . ' 期', 'href' => $drop('issue')];
        }
        if ($authorName !== '') {
            $chips[] = ['label' => '作者 ' . $authorName, 'href' => $drop('author')];
        }
        if ((string) $filter['genre'] !== '') {
            $chips[] = ['label' => (string) $filter['genre'], 'href' => $drop('genre')];
        }
        if ((int) $filter['term'] > 0) {
            // 标签上写词条名，不写主键。从词条页「全部 50 篇」跳过来的人，第一眼
            // 看到的是「词条 #2435 ×」—— 数据库编号端到了读者脸上。
            $term    = $this->library->findTerm((int) $filter['term']);
            $chips[] = [
                'label' => (string) ($term['canonical'] ?? '按词条筛选'),
                'href'  => $drop('term'),
            ];
        }
        if ((int) $filter['year'] > 0) {
            $chips[] = ['label' => (int) $filter['year'] . ' 年', 'href' => $drop('year')];
        }
        if ((int) $filter['min_words'] > 0 || (int) $filter['max_words'] > 0) {
            $min = (int) $filter['min_words'];
            $max = (int) $filter['max_words'];
            $chips[] = [
                'label' => $max === 0 ? sprintf('%d 字以上', $min) : sprintf('%d–%d 字', $min, $max),
                'href'  => $drop('range'),
            ];
        }

        return $chips;
    }

    // ------------------------------------------------------------- 作品详情
    //
    // 同一篇作品有两个子页，因为它的读者是两种人：
    //   · reader（默认，/works/{code}）：来找作品的人 —— 学者、概念设计、影视买手。
    //     他们要知道核心设定、魅力所在、能不能拍、适不适合自己。
    //   · author（/works/{code}/author）：作者本人。要知道文学上的优劣与先改哪里。
    // 两个页面用两条路由而不是一个 ?view= 参数：缓存中间件按路径做键，
    // 查询串不进键，用参数会让两套内容互相覆盖（前台 60–300 秒的 TTL 足够暴露这个 bug）。

    public function show(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return $this->detail($response, $args, 'reader');
    }

    public function author(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return $this->detail($response, $args, 'author');
    }

    private function detail(ResponseInterface $response, array $args, string $view): ResponseInterface
    {
        // 仓储层已经挡掉两种隐藏（作品级 / 拆解级），这里拿到 null 就是「馆里没有这篇」。
        //
        // 拆解被隐藏时不给存目页、不给「拆解暂不公开」的提示：公开区唯一的内容就是
        // 拆解本身，结论收存之后这篇作品在馆里就应当**从未被拆解过** ——
        // 也就等于不存在。任何一句解释都是在承认「这里本来有东西」。
        $work = $this->library->findPublishedByCode((string) ($args['code'] ?? ''));
        if ($work === null) {
            return $this->notFound($response, '这篇作品不在馆藏里：可能还没发布，或者已经下线了。');
        }

        $analysisId = (int) $work['current_analysis_id'];

        // 四件产出进模板前的唯一一道文本层。
        //
        // 必须在这里、且只在这里：产物是一整棵嵌套数组（index_card / summary / beats /
        // foreshadows / suggestions / revision / reader），模板读的是
        // $artifacts['beats'][3]['detail'] 这种深层路径。逐个字段判断必然漏一处，
        // 而「漏一处」等于「没隐藏」。所以整棵递归过一遍，下游拿到的一律是干净的。
        $artifacts  = $this->guard->artifacts($this->analyses->loadArtifacts($analysisId));
        $terms      = $this->library->termsOfAnalysis($analysisId);
        $snapshots  = $this->analyses->snapshots($analysisId);

        // 「人工调整过」只认编辑类快照；重跑是机器行为，不算人改过
        $edits = array_values(array_filter(
            $snapshots,
            static fn (array $s): bool => !str_starts_with((string) ($s['note'] ?? ''), '重跑')
        ));

        $byCategory = [];
        foreach ($terms as $term) {
            $byCategory[(string) $term['category']][] = $term;
        }

        $title  = (string) $work['title'];
        $anchor = $this->config->string('app.url') . '/library/works/' . $work['code'];
        $reader = $artifacts['reader'] ?? null;
        $suffix = $view === 'author' ? '/author' : '';

        // 影视化只出文字，不出图。图一旦生成，读者会把它当成「作品插图」或作者原意，
        // 而它其实只是分析模型读完之后的一次推测 —— 误读的代价大于一张图带来的好看。
        // 这一页只保留 visual 四格里的文字判断。

        $desc = $view === 'author'
            ? sprintf('%s（%s）的作者视角拆解：剧情节点、伏笔台账、分层建议与修改方向。', $title, (string) ($work['author_name'] ?? '佚名'))
            : sprintf(
                '%s（%s）：%s',
                $title,
                (string) ($work['author_name'] ?? '佚名'),
                (string) ($reader['hook'] ?? $artifacts['index_card']['one_liner'] ?? '核心设定、看点、画面与适合的读者')
            );

        return $this->render($response, $view === 'author' ? 'library/work_author' : 'library/work', [
            'page_title' => $view === 'author'
                ? $title . ' · 作者视角 | 银河图书馆'
                : $title . ' · 作品卡 | 银河图书馆',
            'page_desc'  => mb_substr($desc, 0, 120),
            'canonical'  => $anchor . $suffix,
            'view'       => $view,
            'work'       => $work,
            'artifacts'  => $artifacts,
            'reader'     => $reader,
            'terms'      => $terms,
            'by_category' => $byCategory,
            'tags'       => $this->works->tagsOf((int) $work['id']),
            // 页尾不再推「同一期」，那只是时间上的邻近；推同一个关键词下的作品，
            // 才是读者真正会顺着往下点的线
            'same_term'  => $this->library->sameTermWorks((int) $work['id'], $analysisId, 3),
            'axis'       => $this->axis($artifacts['beats'] ?? []),
            'edits'      => $edits,
            'json_ld'    => $this->jsonLd([
                '@context'      => 'https://schema.org',
                '@type'         => 'CreativeWork',
                'name'          => $title,
                'author'        => ['@type' => 'Person', 'name' => (string) ($work['author_name'] ?? '佚名')],
                'wordCount'     => (int) $work['word_count'],
                'datePublished' => $work['published_at'] === null ? null : substr((string) $work['published_at'], 0, 10),
                'description'   => (string) ($reader['hook'] ?? $artifacts['index_card']['one_liner'] ?? ''),
                // 关键词就是词库里的那些词，最多 5 个 —— 结构化的东西不该为了 SEO 灌水
                'keywords'      => implode(',', array_map(static fn (array $t): string => (string) $t['canonical'], $terms)),
                'isPartOf'      => ['@type' => 'CollectionPage', 'name' => '银河图书馆'],
            ]),
        ]);
    }

    /**
     * 剧情节点轴：把 8 个节点摆到一条时间线上。
     *
     * 坐标在 PHP 里算好、模板只负责画 —— 模板里做浮点运算既难读，
     * 也会让「一个节点压住另一个」这类问题只能靠肉眼调数字。
     *
     * @param list<array<string,mixed>> $beats
     * @return array{width:int, height:int, points:list<array<string,mixed>>, phases:list<array<string,mixed>>}
     */
    /**
     * 剧情节点轴。
     *
     * 坐标全在 PHP 里算，Twig 只负责画 —— 因为「标签能写几个字」取决于它和同一行
     * 相邻节点之间有多少像素，而这件事在模板里算不了。
     *
     * 两个曾经的坑：
     *   1. 标签固定截 14 字。12 个节点铺在 872px 的轴上，同一行相邻只有 144px，
     *      14 个汉字要 160px —— 于是四五个标签压成一团。现在按实测间隔算字数。
     *   2. 上下交错的下行标签基线恰好落在阶段名（起点/转折/危机/终局）那条基线上，
     *      两者必然叠字。现在阶段名移出 SVG，改成上方一行色块图例。
     */
    private function axis(array $beats): array
    {
        $left  = 64;
        $right = 936;
        $y     = 76;
        $fs    = 11.5;   // 与 .axis__t 的字号一致，用来把像素换算成字数

        $points = [];
        foreach (array_values($beats) as $index => $beat) {
            $pct = max(0, min(100, (int) $beat['position_pct']));
            $points[] = [
                'seq'   => (int) $beat['seq'],
                'title' => trim((string) $beat['title']),
                'phase' => (string) $beat['phase'],
                'pct'   => $pct,
                'x'     => (int) round($left + ($right - $left) * $pct / 100),
                'row'   => $index % 2,
            ];
        }

        foreach ($points as $index => &$p) {
            // 同一行（相隔 2 个）的相邻节点才可能撞在一起
            $gap = 1000.0;
            if (isset($points[$index - 2])) {
                $gap = min($gap, (float) ($p['x'] - $points[$index - 2]['x']));
            }
            if (isset($points[$index + 2])) {
                $gap = min($gap, (float) ($points[$index + 2]['x'] - $p['x']));
            }

            $seqLen   = strlen((string) $p['seq']);
            $maxTitle = min(18, (int) floor($gap / $fs) - $seqLen - 1);

            // 挤到连四个汉字都放不下时，只留序号 —— 半截标题比一串省略号更没有信息量
            $p['label'] = $maxTitle >= 4
                ? $p['seq'] . '·' . mb_substr($p['title'], 0, $maxTitle)
                    . (mb_strlen($p['title']) > $maxTitle ? '…' : '')
                : (string) $p['seq'];

            $p['anchor'] = $p['row'] === 0 ? 'up' : 'down';
            $p['y']      = $p['row'] === 0 ? $y - 30 : $y + 34;
            $p['stem_y'] = $p['row'] === 0 ? $y - 22 : $y + 22;
            $p['tx']     = (float) $p['x'];
        }
        unset($p);

        // 按行做一次左→右、再右→左的避让扫描。
        // 只靠「按间隔算字数」不够：上一条是居中的、下一条贴边改成右对齐时，
        // 两条各自都没超预算却仍会互压 —— 这里用实测宽度把它们推开几像素即可。
        $rowIndexes = [0 => [], 1 => []];
        foreach ($points as $index => $p) {
            $rowIndexes[$p['row']][] = $index;
        }

        foreach ($rowIndexes as $indexes) {
            $prevRight = 48.0;
            foreach ($indexes as $index) {
                $half = mb_strlen($points[$index]['label']) * $fs / 2;
                $tx   = max((float) $points[$index]['x'], $prevRight + 6 + $half);
                $points[$index]['tx'] = $tx;
                $prevRight = $tx + $half;
            }

            $nextLeft = 952.0;
            for ($k = count($indexes) - 1; $k >= 0; $k--) {
                $index = $indexes[$k];
                $half  = mb_strlen($points[$index]['label']) * $fs / 2;
                $tx    = min($points[$index]['tx'], $nextLeft - 6 - $half);
                $points[$index]['tx'] = $tx;
                $nextLeft = $tx - $half;
            }
        }

        // 四段相位：按同类节点的最小/最大百分比取区间
        $bands = [];
        foreach (['start', 'turn', 'crisis', 'end'] as $phase) {
            $pcts = array_values(array_map(
                static fn (array $p): int => (int) $p['pct'],
                array_filter($points, static fn (array $p): bool => $p['phase'] === $phase)
            ));
            if ($pcts === []) {
                continue;
            }
            $from = min($pcts);
            $to   = max($pcts);
            $bands[] = [
                'phase' => $phase,
                'x1'    => (int) round($left + ($right - $left) * $from / 100),
                'x2'    => (int) round($left + ($right - $left) * $to / 100),
            ];
        }

        return [
            'width'  => 1000,
            'height' => 140,
            'y'      => $y,
            'points' => $points,
            'phases' => $bands,
        ];
    }

    // ------------------------------------------------------------- 按期次

    public function issue(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $no = (int) ($args['no'] ?? 0);

        $issue = $this->library->findIssueByNo($no);
        if ($issue === null) {
            return $this->notFound($response, '没有这一期。馆藏按期次归档，最新一期在列表页顶部。');
        }

        $filter = ['issue' => $no, 'sort' => 'newest'] + [
            'genre' => '', 'tag' => '', 'term' => 0, 'year' => 0, 'min_words' => 0, 'max_words' => 0,
        ];

        // 期次与列表页共用同一套双向游标：千篇级馆藏后一期可能装几十上百篇，
        // 固定 48 条一次性渲染既截断数据也拖慢页面
        $limit    = 48;
        $query    = $request->getQueryParams();
        $cursor   = Cursor::decode((string) ($query['cursor'] ?? ''));
        $before   = Cursor::decode((string) ($query['before'] ?? ''));
        $backward = $cursor === null && $before !== null;
        $result   = $this->library->listWorks($filter, $limit, $backward ? $before : $cursor, $backward);
        $total    = $this->library->countWorks($filter);

        $base    = '/library/issues/' . $no;
        $nextUrl = $result['next'] !== null
            ? $base . '?' . http_build_query(['cursor' => Cursor::encode($result['next'])])
            : null;
        $prevUrl = $result['prev'] !== null
            ? $base . '?' . http_build_query(['before' => Cursor::encode($result['prev'])])
            : null;
        $firstUrl = ($cursor !== null || $before !== null) ? $base : null;

        // 「其它期次」必须先在控制器里剔掉当前期再判断空 —— 直接写 if options.issues is not empty
        // 会在只有一期时留下一个光秃秃的标题（模板里那个 if i.no != issue.no 过滤完就什么都不剩）
        $others = array_values(array_filter(
            $this->library->publishedIssues(),
            static fn (array $i): bool => (int) $i['no'] !== $no
        ));

        return $this->render($response, 'library/issue', [
            'page_title' => sprintf('第 %d 期 · 馆藏 | 银河图书馆', $no),
            'page_desc'  => sprintf('荒启科幻第 %d 期入馆作品的拆解索引，共 %d 篇。', $no, $total),
            'canonical'  => $this->config->string('app.url') . '/library/issues/' . $no,
            'issue'      => $issue,
            'rows'       => $this->maskRows($result['rows']),
            'total'      => $total,
            'next_url'   => $nextUrl,
            'prev_url'   => $prevUrl,
            'first_url'  => $firstUrl,
            'others'     => $others,
        ]);
    }
}
