<?php

declare(strict_types=1);

namespace App\Application\Source;

use RuntimeException;

/**
 * 官网站内文章的全量目录：同步、缓存、筛选口径。
 *
 * ## 为什么要有这一层，而不是每次翻页直接打官网
 *
 * 全站 1843 篇、每页上限 50 条，翻完要 37 个请求、二十秒左右。后台列表页
 * 每翻一页都这么打一次，既慢又不礼貌，还会在对方限流时把整个后台拖住。
 * 所以：**整份目录同步一次落盘，页面毫秒级读本地**；只有看全文时才实时调详情接口。
 *
 * 代价是数据会旧。这个代价是可控的 —— 页面顶部显示「数据截至 X」，
 * 运营看到旧了自己点同步。比「看起来实时但每次都慢三秒」诚实。
 *
 * ## 练笔的判据为什么不能用 slug
 *
 * 实测 29 个活动里，slug 有三种拼法并存：`PracticeWriting`、
 * `PracticeWrighting`（多一个 g）、`PricticeWrighting`（两个错字）。
 * 还混着 `QunXingBei02`（群星杯）、`weilaixiaoyuan01`（未来校园）这些不是练笔的活动。
 * 按 slug 前缀筛会漏掉一半。**只有活动名是可靠的**：「荒启科幻练笔第 X 期」。
 *
 * ## 「可用」的四个条件缺一不可
 *
 * `type = 'post'`（正式稿，不是草稿）、`isDelete = 0`（没被删）、
 * `isopen = 1`（公开）、`status = 'publish'`（已发布）。
 *
 * 前三个条件互相独立：实测有 84 篇 isopen=0 但 status 仍是 publish，
 * 也有 163 篇 status 不是 publish 却没被标记删除。只看一个都会放进不该放的东西。
 *
 * 第四个条件是后补的，也最阴：有一批 `type = post_draft` 的文章，
 * 列表接口里三个状态字段全都合格，但详情接口一律「文章暂未公开访问」——
 * 它们还在草稿箱，读者根本打不开。不按类型挡，第 25 期那 20 篇草稿
 * 和站方的测试稿都会被算进可用。
 *
 * ## 期号从活动名里解析，不从日期推
 *
 * 活动名是「荒启科幻练笔第二十六期」，期号就写在这句话里，26 期一一对应，
 * 且 `slug` 有三种错拼（见上），所以**期号只能从中文数字解析**。
 * 日期是用来核对和展示的，不参与「这篇属于第几期」的判定 ——
 * 一旦用日期去反推，遇到跨年、加期、提前截稿就会错，而且错得很难发现。
 *
 * ## 「字数」用的就是接口的 `size`，不是本地数出来的
 *
 * 实测 `size` 就是正文去掉标签后的字符数（《失聪者》`size`=17214，
 * 本地 strip_tags 后 mb_strlen=17182，差 32 个空白/实体）。两者口径一致，
 * 所以不必为 1800 篇各拉一次详情去数字数 —— 那是把 37 个请求变成 1800 个。
 */
final class SourceCatalog
{
    /** 练笔活动名的固定片段。判据只能是名字，slug 不可靠（见类注释） */
    public const PRACTICE_MARK = '荒启科幻练笔';

    /** 同步时最多翻多少页，防对方 total 异常时打不完 */
    private const MAX_PAGES = 200;

    private const PER_PAGE = SourceApi::MAX_LIMIT;

    /**
     * 翻页之间的间隔。
     *
     * 一开始是 200ms，实测连续三十几个请求后官网开始丢请求（第 10～21 页随机失败），
     * 说明对方有限流。所以放慢到 700ms，37 页大约 45 秒 —— 一次同步几十秒，
     * 换来的是「一次就能成功」，比失败重来三次更省时间，也对别人更客气。
     */
    private const PAGE_DELAY_US = 700_000;

    /** 单页最多试几次。限流是概率性的，隔几秒重试通常就过了 */
    private const PAGE_ATTEMPTS = 4;

    /** 第 n 次重试前等多久（微秒），指数退避 */
    private const RETRY_BACKOFF_US = [0, 2_000_000, 5_000_000, 10_000_000];

    public function __construct(
        private readonly SourceApi $api,
        private readonly string $dir,
    ) {
    }

    /**
     * 全量同步。
     *
     * @param callable(int,int):void|null $onPage 每翻完一页回调（页号, 已收条数），命令行用它打印进度
     * @return array{total:int, fetched:int, pages:int, fetched_at:int}
     */
    public function sync(?callable $onPage = null): array
    {
        $items = [];
        $total = null;
        $page  = 1;

        while ($page <= self::MAX_PAGES) {
            $res = $this->fetchPage($page);
            if ($res === null) {
                // 中途失败不落盘：半份目录比没有目录更危险 ——
                // 页面会拿着「练笔 800 篇」当真，而实际有 1375 篇。
                throw new RuntimeException(sprintf(
                    '第 %d 页连续 %d 次拉取失败，本次同步已放弃（旧缓存保持不变，可稍后重试）',
                    $page,
                    self::PAGE_ATTEMPTS
                ));
            }

            $total ??= $res['total'];
            if ($res['data'] === []) {
                break;
            }

            foreach ($res['data'] as $row) {
                $item = $this->normalize($row);
                if ($item !== null) {
                    $items[$item['cid']] = $item;
                }
            }

            if ($onPage !== null) {
                $onPage($page, count($items));
            }

            if (count($items) >= $total) {
                break;
            }

            ++$page;
            usleep(self::PAGE_DELAY_US);
        }

        $fetchedAt = time();
        $this->write([
            'meta' => [
                'fetched_at' => $fetchedAt,
                'total'      => $total ?? count($items),
                'fetched'    => count($items),
                'pages'      => $page,
            ],
            'items' => array_values($items),
        ]);

        return [
            'total'      => $total ?? count($items),
            'fetched'    => count($items),
            'pages'      => $page,
            'fetched_at' => $fetchedAt,
        ];
    }

    /**
     * 拉一页，失败按退避重试。
     *
     * 官网这个接口在连续请求后会间歇性失败（不是每次都失败，也没有明确的 429），
     * 所以「一次失败就整体放弃」太脆 —— 实测 37 页里几乎必然撞上一次。
     * 重试必须带等待：立刻重试还是撞在同一堵墙上。
     *
     * @return array{total:int, data:list<array<string,mixed>>}|null
     */
    private function fetchPage(int $page): ?array
    {
        for ($attempt = 0; $attempt < self::PAGE_ATTEMPTS; $attempt++) {
            if ($attempt > 0) {
                usleep(self::RETRY_BACKOFF_US[$attempt] ?? 10_000_000);
            }

            $res = $this->api->list($page, self::PER_PAGE);
            if ($res !== null) {
                return $res;
            }
        }

        return null;
    }

    /**
     * @return array{meta:array<string,int>, items:list<array<string,mixed>>}|null
     */
    public function load(): ?array
    {
        $path = $this->path();
        if (!is_file($path)) {
            return null;
        }

        $raw = @file_get_contents($path);
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !is_array($decoded['items'] ?? null)) {
            return null;
        }

        return $decoded;
    }

    public function hasCache(): bool
    {
        return is_file($this->path());
    }

    /**
     * 这条是否属于「荒启科幻练笔」。
     *
     * 只看活动名。slug 有错拼变体，且混着别的活动（见类注释）。
     *
     * @param array<string,mixed> $item
     */
    public function isPractice(array $item): bool
    {
        return (int) ($item['issueNo'] ?? 0) > 0;
    }

    /**
     * 中文数字 → 整数（练笔期号只到两位，不需要通用大数解析）。
     *
     * 支持「三」「十」「十二」「二十」「二十六」这几种写法。
     * 解析不出来返回 0，调用方按「不是练笔」处理 —— 宁可少算一篇，
     * 也不要把一篇不知道第几期的文章硬塞进某一期。
     */
    public static function parseChineseNumber(string $s): int
    {
        static $digits = [
            '零' => 0, '〇' => 0, '一' => 1, '二' => 2, '两' => 2, '三' => 3, '四' => 4,
            '五' => 5, '六' => 6, '七' => 7, '八' => 8, '九' => 9,
        ];

        $s = trim($s);
        if ($s === '') {
            return 0;
        }

        // 纯阿拉伯数字也接受：以后官方要是改成「第 27 期」不至于立刻失效
        if (ctype_digit($s)) {
            return (int) $s;
        }

        if (!str_contains($s, '十')) {
            return $digits[$s] ?? 0;
        }

        // 「十」的两种位置：「十二」= 12（十前为空按 1）、「二十」= 20（十后为空按 0）
        [$tens, $ones] = array_pad(explode('十', $s, 2), 2, '');
        $t = $tens === '' ? 1 : ($digits[$tens] ?? -100);
        $o = $ones === '' ? 0 : ($digits[$ones] ?? -100);

        return ($t < 0 || $o < 0) ? 0 : $t * 10 + $o;
    }

    /**
     * 「这篇现在读者打不打得开」的唯一判据。
     *
     * 四个条件，缺一不可。第四条是后补的，补的原因值得记下来：
     *
     * 站里有一批 `type = post_draft` 的文章，列表接口里 `status = publish`、
     * `isopen = 1`、没被删 —— 三个条件全满足，看起来完全可用。但拿 cid 去调
     * 详情接口，一律返回「文章暂未公开访问」。也就是说它们**在草稿箱里，读者打不开**。
     * 只按那三个字段判，会把 20 篇草稿当成正式作品（第 25 期匿名投稿那批全是
     * 这个形状），还会把站方自己的测试稿（`【接口自检-可删】`、`probe-cid`、`test`）
     * 一起算进「练笔可用」。
     *
     * 判据只能是 `type = post`：草稿和正式稿的区别不在状态字段里，在类型里。
     *
     * ## 为什么是静态的、而且只留这一个名字
     *
     * 采集方向用它决定「要不要采进来」，公开区闸门用它决定「要不要收起来」——
     * 同一个事实的两个方向。闸门只读 catalog.json、不装配本类（那要一个 SourceApi），
     * 所以这个判据必须是静态的。两边各写一份的后果是：采集认为「可用」而闸门认为
     * 「已关闭」，作品被自己人反复收起再放回，而两边都不报错。
     *
     * @param array<string,mixed> $item
     */
    public static function available(array $item): bool
    {
        return (string) ($item['type'] ?? '') === 'post'
            && (int) ($item['isDelete'] ?? 0) === 0
            && (int) ($item['isopen'] ?? 1) === 1
            && (string) ($item['status'] ?? '') === 'publish';
    }

    /**
     * 按范围筛。
     *
     * - `all`：全站，一个不筛
     * - `practice`：参加过练笔的（含未公开/已删除，用来核对总量）
     * - `practice_ready`：练笔 ∧ 可用（这是实际能用的那一批）
     *
     * @param list<array<string,mixed>> $items
     * @return list<array<string,mixed>>
     */
    public function filter(array $items, string $scope): array
    {
        return match ($scope) {
            'practice' => array_values(array_filter($items, fn (array $i): bool => $this->isPractice($i))),
            'practice_ready' => array_values(array_filter(
                $items,
                fn (array $i): bool => $this->isPractice($i) && self::available($i)
            )),
            default => array_values($items),
        };
    }

    /**
     * 按期号筛。`$no <= 0` 表示不筛。
     *
     * @param list<array<string,mixed>> $items
     * @return list<array<string,mixed>>
     */
    public function filterIssue(array $items, int $no): array
    {
        if ($no <= 0) {
            return array_values($items);
        }

        return array_values(array_filter($items, static fn (array $i): bool => (int) ($i['issueNo'] ?? 0) === $no));
    }

    /**
     * 每期一行：篇数、可用篇数、字数合计/均值、作者人数。
     *
     * 「作者人数」按 `authorUid|authorName` 去重 —— 匿名投稿的 uid 各不相同但名字都是
     * 「匿名用户」，只按名字去重会把几十个人算成一个。按 uid 去重又会把改名的人算成两个，
     * 所以用组合键，并且把匿名的单独标出来（见 anon 字段），让运营知道这个数字有多少水分。
     *
     * @param list<array<string,mixed>> $items
     * @return list<array{no:int,label:string,total:int,ready:int,words:int,avg:int,authors:int,anon:int,mid:int,endsAt:int,revealAt:int}>
     */
    public function issueStats(array $items): array
    {
        $rows = [];

        foreach ($items as $i) {
            $no = (int) ($i['issueNo'] ?? 0);
            if ($no <= 0) {
                continue;
            }

            $rows[$no] ??= [
                'no' => $no, 'label' => '第 ' . $no . ' 期',
                'total' => 0, 'ready' => 0, 'words' => 0,
                'authors' => [], 'anon' => 0,
                'mid' => 0, 'endsAt' => 0, 'revealAt' => 0,
            ];

            $rows[$no]['total']++;
            $rows[$no]['mid']      = (int) ($i['practiceMid'] ?? 0);
            $rows[$no]['endsAt']   = (int) ($i['endsAt'] ?? 0);
            $rows[$no]['revealAt'] = (int) ($i['revealAt'] ?? 0);

            if (self::available($i)) {
                $rows[$no]['ready']++;
                $rows[$no]['words'] += (int) ($i['wordCount'] ?? 0);

                $uid  = (int) ($i['authorUid'] ?? 0);
                $name = (string) ($i['authorName'] ?? '');
                $rows[$no]['authors'][$uid . '|' . $name] = true;
                if ($name === '' || $name === '匿名用户') {
                    $rows[$no]['anon']++;
                }
            }
        }

        ksort($rows);

        $out = [];
        foreach ($rows as $no => $r) {
            $n = count($r['authors']);
            $out[] = [
                'no'       => $no,
                'label'    => $r['label'],
                'total'    => $r['total'],
                'ready'    => $r['ready'],
                'words'    => $r['words'],
                'avg'      => $r['ready'] > 0 ? intdiv($r['words'], $r['ready']) : 0,
                'authors'  => $n,
                'anon'     => $r['anon'],
                'mid'      => $r['mid'],
                'endsAt'   => $r['endsAt'],
                'revealAt' => $r['revealAt'],
            ];
        }

        return $out;
    }

    /**
     * 导出用的扁平行：一篇文章一行，字段固定。
     *
     * 为什么要单独一个方法而不是把缓存结构直接丢给 CSV：缓存里的 `activities`
     * 是数组、`categories` 是数组，CSV 一格放不下；而且导出是给人拿去排期的，
     * 列的顺序和名字要稳定，不能跟着缓存结构改。
     *
     * @param list<array<string,mixed>> $items
     * @return array{head:list<string>, rows:list<list<string|int>>}
     */
    public function exportTable(array $items): array
    {
        $head = ['cid', '期号', '期次', '标题', '作者', '作者UID', '字数', '发布时间', '状态', '链接'];

        $rows = [];
        foreach ($items as $i) {
            $available = self::available($i);
            $rows[] = [
                (int) $i['cid'],
                (int) ($i['issueNo'] ?? 0),
                (string) ($i['issueLabel'] ?? ''),
                (string) $i['title'],
                (string) ($i['authorName'] ?? ''),
                (int) ($i['authorUid'] ?? 0),
                (int) ($i['wordCount'] ?? 0),
                (int) ($i['created'] ?? 0) > 0 ? date('Y-m-d H:i', (int) $i['created']) : '',
                $available ? '可用' : ($this->reason($i)),
                'https://www.huangqisf.com/h5/#/pages/contents/info?cid=' . (int) $i['cid'],
            ];
        }

        return ['head' => $head, 'rows' => $rows];
    }

    /**
     * 生成 CSV 文本。
     *
     * **必须带 BOM**：不带的话 Excel 会把 UTF-8 当 GBK 解，满屏乱码 ——
     * 这是中文 CSV 最常见的翻车点，而且只在别人的 Excel 上复现。
     */
    public function toCsv(array $items): string
    {
        $table = $this->exportTable($items);

        $fh = fopen('php://temp', 'r+');
        if ($fh === false) {
            throw new RuntimeException('无法创建临时流');
        }

        fputcsv($fh, $table['head'], ',', '"', '\\');
        foreach ($table['rows'] as $row) {
            fputcsv($fh, $row, ',', '"', '\\');
        }

        rewind($fh);
        $csv = (string) stream_get_contents($fh);
        fclose($fh);

        // CRLF：Excel 对纯 \n 的分行在个别版本上会把整张表读成一行
        $csv = str_replace("\n", "\r\n", $csv);

        return "\xEF\xBB\xBF" . $csv;
    }

    /**
     * 不可用的原因（导出时写在「状态」列里，比一个 false 有用）。
     *
     * @param array<string,mixed> $i
     */
    public function reason(array $i): string
    {
        if ((int) ($i['isDelete'] ?? 0) === 1) {
            return '已删除';
        }
        if ((int) ($i['isopen'] ?? 1) === 0) {
            return '未公开';
        }
        if ((string) ($i['status'] ?? '') !== 'publish') {
            return '非发布（' . (string) ($i['status'] ?? '') . '）';
        }

        return '可用';
    }

    /**
     * 关键词过滤（标题、作者、期次名都算）。
     *
     * 本地过滤而不是调 `searchKey`：本地已经握着全量，搜索是毫秒级；
     * 走服务端搜索还得先知道人家是怎么分词的，且只能搜标题。
     *
     * @param list<array<string,mixed>> $items
     * @return list<array<string,mixed>>
     */
    public function search(array $items, string $keyword): array
    {
        $keyword = trim($keyword);
        if ($keyword === '') {
            return array_values($items);
        }

        $needle = mb_strtolower($keyword);

        return array_values(array_filter($items, static function (array $i) use ($needle): bool {
            $haystack = mb_strtolower(implode(' ', [
                (string) ($i['title'] ?? ''),
                (string) ($i['authorName'] ?? ''),
                (string) ($i['issueLabel'] ?? ''),
                (int) ($i['issueNo'] ?? 0) > 0 ? (string) $i['issueNo'] : '',
                implode(' ', array_map(static fn (array $a): string => (string) ($a['name'] ?? ''), $i['activities'] ?? [])),
            ]));

            return mb_strpos($haystack, $needle) !== false;
        }));
    }

    /**
     * @param list<array<string,mixed>> $items
     * @return array{total:int, all:int, practice:int, practice_ready:int, deleted:int, closed:int, unpublished:int, fetched_at:int, pages:int}
     */
    public function stats(array $items): array
    {
        $practice = 0;
        $ready = 0;
        $deleted = 0;
        $closed = 0;
        $unpublished = 0;

        foreach ($items as $i) {
            if ((int) ($i['isDelete'] ?? 0) === 1) { ++$deleted; }
            if ((int) ($i['isopen'] ?? 1) === 0) { ++$closed; }
            if ((string) ($i['status'] ?? '') !== 'publish') { ++$unpublished; }

            if ($this->isPractice($i)) {
                ++$practice;
                if (self::available($i)) {
                    ++$ready;
                }
            }
        }

        $meta = $this->load()['meta'] ?? [];

        return [
            'total'          => count($items),
            'all'            => count($items),
            'practice'       => $practice,
            'practice_ready' => $ready,
            'deleted'        => $deleted,
            'closed'         => $closed,
            'unpublished'    => $unpublished,
            'fetched_at'     => (int) ($meta['fetched_at'] ?? 0),
            'pages'          => (int) ($meta['pages'] ?? 0),
        ];
    }

    /**
     * 把一页原始行收敛成缓存里的形状。
     *
     * 丢掉 400 字摘要：1843 条乘 400 字是七八百 KB，而列表页并不展示它
     * （要看内容就点详情读全文）。留在缓存里只会让读盘变慢。
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>|null
     */
    private function normalize(array $row): ?array
    {
        $cid = (int) ($row['cid'] ?? 0);
        if ($cid <= 0) {
            return null;
        }

        $activities = [];
        foreach (($row['active'] ?? []) as $a) {
            if (!is_array($a)) {
                continue;
            }
            $activities[] = [
                'slug'     => (string) ($a['slug'] ?? ''),
                'name'     => (string) ($a['name'] ?? ''),
                'type'     => (string) ($a['type'] ?? ''),
                'mid'      => (int) ($a['mid'] ?? 0),
                'deadline' => (int) ($a['deadline'] ?? 0),
                'revealAt' => (int) ($a['anonymousTime'] ?? 0),
            ];
        }

        $categories = [];
        foreach (($row['category'] ?? []) as $c) {
            if (is_array($c) && isset($c['name'])) {
                $categories[] = (string) $c['name'];
            }
        }

        // 期号：只在练笔活动上找。一篇只参加一期（实测 1375 篇里 0 篇挂多期），
        // 万一将来出现多期，取期号最大的那个 —— 那代表「最近一次参加」。
        $issueNo        = 0;
        $practiceMid    = 0;
        $practiceName   = '';
        $practiceEndsAt = 0;
        $practiceReveal = 0;
        foreach ($activities as $a) {
            if ($a['type'] !== 'active' || mb_strpos($a['name'], self::PRACTICE_MARK) === false) {
                continue;
            }
            if (!preg_match('/第\s*(.+?)\s*期/u', $a['name'], $m)) {
                continue;
            }
            $no = self::parseChineseNumber($m[1]);
            if ($no > $issueNo) {
                $issueNo        = $no;
                $practiceMid    = $a['mid'];
                $practiceName   = $a['name'];
                $practiceEndsAt = $a['deadline'];
                $practiceReveal = $a['revealAt'];
            }
        }

        return [
            'cid'          => $cid,
            'title'        => (string) ($row['title'] ?? ''),
            'status'       => (string) ($row['status'] ?? ''),
            'isopen'       => (int) ($row['isopen'] ?? 1),
            'isDelete'     => (int) ($row['isDelete'] ?? 0),
            'type'         => (string) ($row['type'] ?? ''),
            'created'      => (int) ($row['created'] ?? 0),
            'modified'     => (int) ($row['modified'] ?? 0),
            'size'         => (int) ($row['size'] ?? 0),
            'views'        => (int) ($row['views'] ?? 0),
            'likes'        => (int) ($row['likes'] ?? 0),
            'commentsNum'  => (int) ($row['commentsNum'] ?? 0),
            'authorUid'    => (int) ($row['authorId'] ?? 0),
            'authorName'   => (string) (($row['authorInfo']['name'] ?? '') ?: ''),
            'categories'   => $categories,
            'activities'   => $activities,
            // ---- 拆解排期要用的三个字段，全在这里 ----
            'issueNo'      => $issueNo,
            'issueLabel'   => $issueNo > 0 ? '第 ' . $issueNo . ' 期' : '',
            'practiceMid'  => $practiceMid,
            'practiceName' => $practiceName,
            'endsAt'       => $practiceEndsAt,
            'revealAt'     => $practiceReveal,
            'wordCount'    => (int) ($row['size'] ?? 0),
        ];
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function write(array $payload): void
    {
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0o775, true) && !is_dir($this->dir)) {
            throw new RuntimeException('缓存目录不可写：' . $this->dir);
        }

        $path = $this->path();
        $tmp  = $path . '.tmp';

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new RuntimeException('目录序列化失败');
        }

        if (@file_put_contents($tmp, $json) === false) {
            throw new RuntimeException('写入缓存失败：' . $tmp);
        }

        // 原子替换：读的一侧永远看到完整文件，不会读到写了一半的 JSON
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException('替换缓存失败：' . $path);
        }
    }

    private function path(): string
    {
        return rtrim($this->dir, '/\\') . '/catalog.json';
    }
}
