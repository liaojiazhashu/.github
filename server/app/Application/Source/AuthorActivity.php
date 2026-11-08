<?php

declare(strict_types=1);

namespace App\Application\Source;

use RuntimeException;

/**
 * 按「时间窗内站内有没有产出」给作者与作品分类。
 *
 * ## 两类怎么划，为什么这么划
 *
 * - **活跃**：时间窗内投过稿，或者评过稿。
 * - **僵尸**：时间窗内投稿与评稿**都没有**，只剩更早的东西。
 *
 * 时间窗长度只有一处定义（`WINDOW_MONTHS`，当前两个月）。后台「作者分类」、
 * 每月自动采集、公开区活跃度闸门都从这里取 —— 这三处一旦各写各的，
 * 后台显示「920 位活跃作者」而公开区只剩 659 篇，数字对不上且不报错。
 *
 * 「投稿」看的是这位作者在站内的**全部**文章（不只练笔那几篇）—— 一个人可能
 * 三年没参加练笔，却一直在别的栏目写，那他不是僵尸。只看练笔会把人冤枉死。
 *
 * ## 评稿为什么要单独调接口，不能拿评论凑
 *
 * 站里「评稿」（`review`）和「评论」（`comment`）是两套东西：评稿带五个维度打分
 * 和五段针对性评语，全站一万三千多条；评论是短文，对象可以是文章也可以是别人的
 * 评稿。一个人的评论数远多于评稿数，拿评论当活跃证据会把「只回了句好看」的人
 * 算成活跃作者。所以判据取评稿，评论另存一份 —— 既能把「只评论不评稿」的人
 * 单独拎出来给你定夺，又不会悄悄改变分类口径。
 *
 * ## 缓存的是事实，不是结论
 *
 * 落盘的每条记录只存「最近一次评稿在什么时候」这类原始时间戳，不存
 * 「他是活跃还是僵尸」。因为分类阈值（几个月）每天都在动，今天活跃的人
 * 可能明天就滑出去了；存结论会让缓存一过期就给出错误的分类。
 *
 * ## 查不到 ≠ 没有
 *
 * 接口失败时记 `ok=false`，分类落到 `unknown`，**绝不**当成「没评过稿」。
 * 那会把一个天天评稿的活跃作者静默地判成僵尸，而且没有任何提示。
 */

final class AuthorActivity
{
    /**
     * 活跃判定的时间窗（月）。**全站唯一一处定义。**
     *
     * 后台「作者分类」、每月自动采集（ActiveCollect）、公开区活跃度闸门
     * （ActiveGate）三处共用这个数字。不各写各的 `-1 year` / `-2 months`：
     * 这三处一旦不一致，后台看到的活跃名单和公开区实际保留的作品就对不上，
     * 而且不报错 —— 页面写 920 位活跃作者，公开区只剩 659 篇。
     */
    public const WINDOW_MONTHS = 2;

    /** 时间窗的中文说法。文案里只引用它，避免「两个月」「2 个月」「二月」三种写法 */
    public const WINDOW_LABEL = '两个月';

    /** 时间窗内投过稿或评过稿 */
    public const ACTIVE = 'active';

    /** 时间窗内什么都没有 */
    public const ZOMBIE = 'zombie';

    /** 只有评论、没有评稿（口径待定，单列不归两类） */
    public const COMMENT_ONLY = 'comment_only';

    /** 匿名：名字被站方抹成「匿名用户」，uid 还是临时的，无法归属到人 */
    public const ANON = 'anon';

    /** 账号已注销：人都不在了，谈不上活跃与否 */
    public const GONE = 'gone';

    /** 这次没查到（网络/接口失败），不能下结论 */
    public const UNKNOWN = 'unknown';

    public const LABELS = [
        self::ACTIVE       => '活跃（近' . self::WINDOW_LABEL . '有投稿或评稿）',
        self::ZOMBIE       => '僵尸（近' . self::WINDOW_LABEL . '无投稿无评稿）',
        self::COMMENT_ONLY => '仅评论（近' . self::WINDOW_LABEL . '只发过短评）',
        self::ANON         => '匿名（不处理）',
        self::GONE         => '作者已注销（不处理）',
        self::UNKNOWN      => '未查到（需重跑）',
    ];

    /**
     * 当前时间窗的起点时间戳。
     *
     * 自然月回推，不做整点对齐 —— 对齐到月初会让「今天」执行的那一次实际上
     * 少看几天。三处调用方必须同源：同一天里后台页面与公开区闸门要算出同一份名单。
     *
     * @param int|null $now 基准时刻，默认当前；只在测试里传固定值
     */
    public static function since(?int $now = null): int
    {
        $ts = strtotime('-' . self::WINDOW_MONTHS . ' months', $now ?? time());
        if ($ts === false) {
            throw new RuntimeException('时间窗算不出来：-' . self::WINDOW_MONTHS . ' months');
        }

        return $ts;
    }

    /**
     * 匿名与已注销在 catalog 里的名字。这两个不是人名，是站方给的状态。
     *
     * 公开出来是因为命令行要按它跳过 —— 冒烟跑 `--limit=5` 时不跳过的话，
     * 抽到的头五个人很可能全是「匿名用户」，看起来像功能坏了。
     */
    public const ANON_NAMES = ['匿名用户', ''];

    public const GONE_NAMES = ['用户已注销', '已注销'];

    /** 缓存的原始事实多久内可以直接复用。一天：分类是按天看的，再旧就该重查 */
    private const TTL = 86_400;

    /**
     * 人工指定的分类文件名（与 activity.json 同目录）。
     *
     * 为什么要单独一个文件、而不是直接改 activity.json：那个文件是**快照**，
     * 每次 `source:activity` 都会整份重写。把人工结论写进去，下一次重查就没了，
     * 而且不会有任何提示 —— 最典型的场景就是「这位作者我认他是活跃」，
     * 重跑一遍分类后他又悄悄变回僵尸，一键拆解的范围跟着变，没人知道为什么。
     *
     * 所以人工结论单独存、每次判定后统一覆盖上去，并在结果里留 `state_auto`
     * 记录机器原本判的是什么。谁改的、改前是什么，都能查。
     */
    private const OVERRIDE_FILE = 'activity-override.json';

    /** 请求间隔。评稿接口没有明确限流，但连着打两百多次会被丢包 */
    private const DELAY_US = 600_000;

    private const ATTEMPTS = 3;

    private const BACKOFF_US = [0, 2_000_000, 5_000_000];

    public function __construct(
        private readonly SourceApi $api,
        private readonly string $dir,
    ) {
    }

    /**
     * 建一份作者活跃度快照。
     *
     * @param list<array<string,mixed>> $items        全站目录（SourceCatalog::load()['items']）
     * @param int                       $since        判定阈值的时间戳，早于它算时间窗外（一般取 since()）
     * @param bool                      $probeComments 对评稿不达标的人再查一次评论（多一轮请求）
     * @param bool                      $force        忽略缓存全部重查
     * @param callable(int,int,string):void|null $onUser 每人回调（已完成数、总数、名字）
     * @return array{meta:array<string,mixed>, authors:list<array<string,mixed>>}
     */
    public function build(
        array $items,
        int $since,
        bool $probeComments = true,
        bool $force = false,
        ?callable $onUser = null
    ): array {
        $posts = $this->collectPosts($items);
        $prev  = $this->load()['authors'] ?? [];
        $prevByUid = [];
        foreach ($prev as $a) {
            $prevByUid[(int) $a['uid']] = $a;
        }

        $now    = time();
        $out    = [];
        $total  = count($posts);
        $done   = 0;

        foreach ($posts as $uid => $p) {
            ++$done;

            $name = (string) $p['name'];
            $old  = $prevByUid[$uid] ?? null;

            // 匿名与已注销不必问接口：前者问不出身份，后者问了也是空
            if (in_array($name, self::ANON_NAMES, true)) {
                $out[] = $this->row($uid, $name, $p, self::ANON, true, 0, 0, 0, 0, 0, 0);
                continue;
            }
            if (in_array($name, self::GONE_NAMES, true)) {
                $out[] = $this->row($uid, $name, $p, self::GONE, true, 0, 0, 0, 0, 0, 0);
                continue;
            }

            // 缓存够新就复用（只复用原始时间戳，分类下面重新算）
            if (!$force && $old !== null && (int) ($old['checked_at'] ?? 0) >= $now - self::TTL) {
                $rec = $old;
                $rec['name']           = $name;
                $rec['posts']          = $p['posts'];
                $rec['latest_post_at'] = $p['latest'];
                $rec['state']          = $this->judge($rec, $since, $probeComments);
                $out[] = $rec;
                if ($onUser !== null) {
                    $onUser($done, $total, $name . '（缓存）');
                }
                continue;
            }

            $review = $this->retry(fn (): ?array => $this->api->userReviews($uid));
            $ok     = $review !== null;

            $reviewTotal = $review['total'] ?? 0;
            $reviewLast  = $review['last'] ?? 0;

            // 评论只在「评稿还不足以判活跃」时才查：一是省一半请求，
            // 二是只要评稿够新，评论查不查都不影响结论。
            $commentTotal = 0;
            $commentLast  = 0;
            $needComment  = $probeComments && max($p['latest'], $reviewLast) < $since;

            if ($needComment) {
                $comment = $this->retry(fn (): ?array => $this->api->userComments($uid));
                if ($comment === null) {
                    $ok = false;
                } else {
                    $commentTotal = $comment['total'];
                    $commentLast  = $comment['last'];
                }
            }

            $rec = $this->row(
                $uid,
                $name,
                $p,
                self::UNKNOWN,
                $ok,
                $reviewTotal,
                $reviewLast,
                $commentTotal,
                $commentLast,
                $needComment ? 1 : 0,
                $now
            );
            $rec['state'] = $this->judge($rec, $since, $probeComments);
            $out[] = $rec;

            if ($onUser !== null) {
                $onUser($done, $total, $name);
            }
            if ($done < $total) {
                usleep(self::DELAY_US);
            }
        }

        usort($out, static fn (array $a, array $b): int => [$b['posts'], $b['uid']] <=> [$a['posts'], $a['uid']]);

        // 人工指定的分类最后覆盖：机器判的留在 state_auto 里，出问题能一眼看出是人改的还是算错的
        $overrides = $this->overrides();
        if ($overrides !== []) {
            foreach ($out as &$row) {
                $uid = (int) $row['uid'];
                if (!isset($overrides[$uid])) {
                    continue;
                }
                $row['state_auto']   = $row['state'];
                $row['state']        = $overrides[$uid];
                $row['state_source'] = 'manual';
            }
            unset($row);
        }

        $payload = [
            'meta' => [
                'built_at'       => $now,
                'since'          => $since,
                'since_label'    => date('Y-m-d H:i:s', $since),
                'probe_comments' => $probeComments,
                'authors'        => count($out),
            ],
            'authors' => $out,
        ];

        $this->write($payload);

        return $payload;
    }

    /**
     * 分类判定。**纯函数**，不碰网络与磁盘 —— 单独拎出来是因为这是唯一会出错
     * 且出错最难发现的地方（判错了没人报错，只是名单少了一个人）。
     *
     * @param array<string,mixed> $a 一位作者的记录
     */
    public function judge(array $a, int $since, bool $probeComments = true): string
    {
        $name = (string) ($a['name'] ?? '');
        if (in_array($name, self::ANON_NAMES, true)) {
            return self::ANON;
        }
        if (in_array($name, self::GONE_NAMES, true)) {
            return self::GONE;
        }

        // 查不到就不下结论。宁可多一个「未查到」让你重跑，
        // 也不要把一个还在写的人钉成僵尸。
        if (!(bool) ($a['ok'] ?? true)) {
            return self::UNKNOWN;
        }

        $post = (int) ($a['latest_post_at'] ?? 0);
        $revi = (int) ($a['latest_review_at'] ?? 0);

        if (max($post, $revi) >= $since) {
            return self::ACTIVE;
        }

        if ($probeComments && (int) ($a['latest_comment_at'] ?? 0) >= $since) {
            return self::COMMENT_ONLY;
        }

        return self::ZOMBIE;
    }

    /**
     * 在 `$since` 之后投过稿或评过稿的 uid 集合。
     *
     * 与 judge() 的区别不在时间窗，而在**不读现成的标签**：`state` 是给后台
     * 分类页看的结论，已经被 `activity-override.json` 改过一轮，而且 `ok=false`
     * 的人在里面只剩一个 `unknown`。公开区要的是「这个人最近到底动过没有」，
     * 只能回到原始时间戳上算，并自己处理 `ok`。
     *
     * 匿名作者照常参与：`state=anon` 只表示「这个名字不能归属到人」，
     * 不影响那条记录里的原始时间戳。已注销同理。
     *
     * `ok === false` 的人不进集合：那是「接口没查到」，不是「查到了没有」。
     * 调用方必须把他归到「未判定」而不是「不活跃」，否则一次接口抖动就会
     * 把一位天天评稿的作者从公开区抹掉。
     *
     * 边界取含（`>=`），与 judge() 的既有口径一致：正好在阈值那一刻投的稿
     * 算在窗内，否则阈值附近的人会随每次执行时间漂移一天。
     *
     * @return array<int,array{name:string,last:int}> uid → 名字与最近一次动作时间
     */
    public function activeUids(int $since): array
    {
        $snapshot = $this->load();
        if ($snapshot === null) {
            throw new RuntimeException('活跃度快照不存在，请先跑 source:activity');
        }

        $out = [];
        foreach ($snapshot['authors'] as $a) {
            if (!is_array($a)) {
                continue;
            }
            $uid = (int) ($a['uid'] ?? 0);
            if ($uid <= 0) {
                continue;
            }
            if (!(bool) ($a['ok'] ?? true)) {
                continue;
            }

            $last = max((int) ($a['latest_post_at'] ?? 0), (int) ($a['latest_review_at'] ?? 0));
            if ($last < $since) {
                continue;
            }

            $out[$uid] = ['name' => (string) ($a['name'] ?? ''), 'last' => $last];
        }

        return $out;
    }

    /**
     * 给每篇作品打上作者的分类状态。
     *
     * 匿名是**作品级**判定，优先于作者级：一篇显示成「匿名用户」的稿子，
     * 哪怕作者本人在别的期是实名的，这一篇也不能归到他名下 ——
     * 站方把它匿掉就是为了不让读者对上号。所以这类作品单独标 anon、
     * 不参与两类划分，而作者的其余实名作品照常分类。
     *
     * @param list<array<string,mixed>> $items    要分类的作品（通常是「练笔可用」那批）
     * @param list<array<string,mixed>> $authors  build() 的结果
     * @return list<array<string,mixed>>
     */
    public function tagItems(array $items, array $authors): array
    {
        $byUid = [];
        foreach ($authors as $a) {
            $byUid[(int) $a['uid']] = $a;
        }

        $out = [];
        foreach ($items as $i) {
            $name = (string) ($i['authorName'] ?? '');
            $a    = $byUid[(int) ($i['authorUid'] ?? 0)] ?? null;

            if (in_array($name, self::ANON_NAMES, true)) {
                $i['activity']   = self::ANON;
                $i['author_row'] = $a;
                $out[] = $i;
                continue;
            }

            $i['activity']   = $a['state'] ?? self::UNKNOWN;
            $i['author_row'] = $a;
            $out[] = $i;
        }

        return $out;
    }

    /**
     * 汇总：作者数、篇数、字数，按状态分组。
     *
     * @param list<array<string,mixed>> $tagged
     * @return array<string,array{authors:int, works:int, words:int}>
     */
    public function summarize(array $tagged): array
    {
        $out = [];
        foreach (array_keys(self::LABELS) as $state) {
            $out[$state] = ['authors' => 0, 'works' => 0, 'words' => 0];
        }

        $seen = [];
        foreach ($tagged as $i) {
            $s = (string) ($i['activity'] ?? self::UNKNOWN);
            $out[$s] ??= ['authors' => 0, 'works' => 0, 'words' => 0];
            ++$out[$s]['works'];
            $out[$s]['words'] += (int) ($i['wordCount'] ?? 0);
            $key = $s . '|' . (int) ($i['authorUid'] ?? 0);
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                ++$out[$s]['authors'];
            }
        }

        return $out;
    }

    /**
     * 作品清单 CSV（一篇一行）。
     *
     * @param list<array<string,mixed>> $tagged
     */
    public function worksCsv(array $tagged): string
    {
        $head = ['cid', '期号', '期次', '标题', '作者', '作者UID', '字数', '发布时间',
                 '最近投稿', '评稿数', '最近评稿', '分类', '链接'];

        $rows = [];
        foreach ($tagged as $i) {
            $a = $i['author_row'] ?? [];
            $rows[] = [
                (int) $i['cid'],
                (int) ($i['issueNo'] ?? 0),
                (string) ($i['issueLabel'] ?? ''),
                (string) $i['title'],
                (string) ($i['authorName'] ?? ''),
                (int) ($i['authorUid'] ?? 0),
                (int) ($i['wordCount'] ?? 0),
                $this->ts((int) ($i['created'] ?? 0)),
                $this->ts((int) ($a['latest_post_at'] ?? 0)),
                (int) ($a['review_total'] ?? 0),
                $this->ts((int) ($a['latest_review_at'] ?? 0)),
                self::LABELS[(string) ($i['activity'] ?? self::UNKNOWN)] ?? (string) $i['activity'],
                'https://www.huangqisf.com/h5/#/pages/contents/info?cid=' . (int) $i['cid'],
            ];
        }

        return $this->csv($head, $rows);
    }

    /**
     * 作者清单 CSV（一人一行）。
     *
     * 匿名作品不进这张表：匿名那一篇的作品行里已经标了「匿名（不处理）」，
     * 若再把它算到某个真实作者头上，等于用文件名之外的线索把人认出来了，
     * 也和他其他作品的分类对不上。
     *
     * @param list<array<string,mixed>> $tagged
     */
    public function authorsCsv(array $tagged): string
    {
        $head = ['UID', '作者', '分类', '可用作品数', '站内投稿总数', '最近投稿',
                 '评稿数', '最近评稿', '评论数', '最近评论', '查证时间'];

        $byUid = [];
        foreach ($tagged as $i) {
            if ((string) ($i['activity'] ?? '') === self::ANON) {
                continue;
            }
            $uid = (int) ($i['authorUid'] ?? 0);
            if (!isset($byUid[$uid])) {
                $byUid[$uid] = ['works' => 0, 'row' => $i['author_row'] ?? []];
            }
            ++$byUid[$uid]['works'];
        }

        $rows = [];
        foreach ($byUid as $uid => $v) {
            $a = $v['row'];
            $rows[] = [
                $uid,
                (string) ($a['name'] ?? ''),
                self::LABELS[(string) ($a['state'] ?? self::UNKNOWN)] ?? (string) ($a['state'] ?? ''),
                $v['works'],
                (int) ($a['posts'] ?? 0),
                $this->ts((int) ($a['latest_post_at'] ?? 0)),
                (int) ($a['review_total'] ?? 0),
                $this->ts((int) ($a['latest_review_at'] ?? 0)),
                (int) ($a['comment_total'] ?? 0),
                $this->ts((int) ($a['latest_comment_at'] ?? 0)),
                $this->ts((int) ($a['checked_at'] ?? 0)),
            ];
        }

        usort($rows, static fn (array $x, array $y): int => [$y[3], $x[0]] <=> [$x[3], $y[0]]);

        return $this->csv($head, $rows);
    }

    /**
     * @return array{meta:array<string,mixed>, authors:list<array<string,mixed>>}|null
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
        $d = json_decode($raw, true);

        return is_array($d) && is_array($d['authors'] ?? null) ? $d : null;
    }

    /**
     * 把全站目录收敛成「uid → 投稿情况」。
     *
     * 只认 `type = post`：站里还有 `post_draft`（草稿箱），那是没投出去的东西，
     * 不能当成产出证据；`isDelete = 1` 是回收站，同理。审核没过（`reject`）
     * 或未公开（`isopen = 0`）**要算**—— 稿子确实投出去了。
     *
     * ## 匿名是「作品级」的，不是「作者级」的
     *
     * 第一版按名字判「这人是不是匿名」，结果错了：实测有 39 个 uid
     * 既有匿名作品又有实名作品 —— 练笔揭晓前那几期，同一个人这一期显示
     * 「匿名用户」，往期还是真名。若拿「最近一篇的名字」当身份，这些人的
     * 全部实名作品都会被划到匿名里，等于凭空丢掉几十篇该处理的稿子。
     *
     * 所以这里的规则是：**这个人只要有过任一实名文章，他就是实名的**，
     * 名字取最近一篇实名的；匿名只对那一篇作品生效（见 tagItems）。
     * 只有「全部作品都是匿名」的 uid 才算匿名身份 —— 那种人根本无从归属。
     *
     * @param list<array<string,mixed>> $items
     * @return array<int,array{name:string, posts:int, latest:int}>
     */
    private function collectPosts(array $items): array
    {
        $out = [];
        foreach ($items as $i) {
            $uid = (int) ($i['authorUid'] ?? 0);
            if ($uid <= 0) {
                continue;
            }
            if ((string) ($i['type'] ?? '') !== 'post' || (int) ($i['isDelete'] ?? 0) !== 0) {
                continue;
            }

            $created = (int) ($i['created'] ?? 0);
            $name    = (string) ($i['authorName'] ?? '');

            $out[$uid] ??= ['name' => '', 'posts' => 0, 'latest' => 0, 'real_at' => 0];

            ++$out[$uid]['posts'];
            if ($created > $out[$uid]['latest']) {
                $out[$uid]['latest'] = $created;
            }

            // 只让实名文章参与「这人叫什么」的竞争，且取最近的一篇
            if ($name !== '' && !in_array($name, self::ANON_NAMES, true) && $created >= $out[$uid]['real_at']) {
                $out[$uid]['name']    = $name;
                $out[$uid]['real_at'] = $created;
            }
        }

        // 从没实名过的，名字留成「匿名用户」，后面按匿名处理
        foreach ($out as $uid => $p) {
            if ($p['name'] === '') {
                $out[$uid]['name'] = '匿名用户';
            }
            unset($out[$uid]['real_at']);
        }

        return $out;
    }

    /**
     * @param array{name:string, posts:int, latest:int} $p
     * @return array<string,mixed>
     */
    private function row(
        int $uid,
        string $name,
        array $p,
        string $state,
        bool $ok,
        int $reviewTotal,
        int $reviewLast,
        int $commentTotal,
        int $commentLast,
        int $probeComments,
        int $checkedAt
    ): array {
        return [
            'uid'              => $uid,
            'name'             => $name,
            'posts'            => $p['posts'],
            'latest_post_at'   => $p['latest'],
            'review_total'     => $reviewTotal,
            'latest_review_at' => $reviewLast,
            'comment_total'    => $commentTotal,
            'latest_comment_at' => $commentLast,
            'checked_at'       => $checkedAt,
            'ok'               => $ok,
            'comment_probed'   => $probeComments,
            'state'            => $state,
        ];
    }

    /**
     * 重试包装。这类「一人一次」的请求量在几百，偶发失败很常见；
     * 一次失败就记 unknown 会让每轮都冒出几个假「未查到」。
     *
     * @param callable():?array $fn
     * @return array{total:int,last:int}|null
     */
    private function retry(callable $fn): ?array
    {
        for ($i = 0; $i < self::ATTEMPTS; ++$i) {
            if ($i > 0) {
                usleep(self::BACKOFF_US[$i] ?? 5_000_000);
            }
            $r = $fn();
            if ($r !== null) {
                return $r;
            }
        }

        return null;
    }

    /**
     * @param list<string>            $head
     * @param list<list<string|int>>  $rows
     */
    private function csv(array $head, array $rows): string
    {
        $fh = fopen('php://temp', 'r+');
        if ($fh === false) {
            throw new RuntimeException('无法创建临时流');
        }

        fputcsv($fh, $head, ',', '"', '\\');
        foreach ($rows as $r) {
            fputcsv($fh, $r, ',', '"', '\\');
        }

        rewind($fh);
        $csv = (string) stream_get_contents($fh);
        fclose($fh);

        // 与 SourceCatalog::toCsv 一致：BOM 防 Excel 乱码，CRLF 防个别版本读成一行
        return "\xEF\xBB\xBF" . str_replace("\n", "\r\n", $csv);
    }

    private function ts(int $t): string
    {
        return $t > 0 ? date('Y-m-d H:i', $t) : '';
    }

    /** @param array<string,mixed> $payload */
    private function write(array $payload): void
    {
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0o775, true) && !is_dir($this->dir)) {
            throw new RuntimeException('缓存目录不可写：' . $this->dir);
        }

        $path = $this->path();
        $tmp  = $path . '.tmp';
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new RuntimeException('活跃度序列化失败');
        }
        if (@file_put_contents($tmp, $json) === false) {
            throw new RuntimeException('写入缓存失败：' . $tmp);
        }
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException('替换缓存失败：' . $path);
        }
    }

    /**
     * 上一次分类的结果：uid → 状态码。
     *
     * 一键拆解要用它做「只要活跃作者的」这种范围筛选。**只读缓存、绝不现算**：
     * 现算一遍是 297 个人、几分钟、上千次外部请求，放在后台页面的渲染路径上
     * 等于把页面卡死。缓存不存在就返回空数组，页面据此显示「还没做分类」，
     * 而不是悄悄把所有人都当成「不限」——那会让范围筛选看起来生效了、其实没有。
     *
     * @return array<int,string>
     */
    public function states(): array
    {
        $path = $this->path();
        if (!is_file($path)) {
            return [];
        }

        $raw = json_decode((string) file_get_contents($path), true);
        if (!is_array($raw) || !isset($raw['authors']) || !is_array($raw['authors'])) {
            return [];
        }

        $map = [];
        foreach ($raw['authors'] as $row) {
            if (is_array($row) && isset($row['uid'])) {
                $map[(int) $row['uid']] = (string) ($row['state'] ?? self::UNKNOWN);
            }
        }

        // 人工指定的分类即时生效：不必等下一次 build() 重写快照
        foreach ($this->overrides() as $uid => $state) {
            $map[$uid] = $state;
        }

        return $map;
    }

    /**
     * 人工指定的分类：uid → 状态。
     *
     * 只认 active / zombie 两种。允许指定成 comment_only / unknown 没有意义 ——
     * 那两种本身就表示「判不了」，人工再指定一个「判不了」等于没指定。
     *
     * 文件坏了当成空表：宁可回到机器判定，也不要让整页因为一个手改文件打不开。
     *
     * @return array<int,string>
     */
    public function overrides(): array
    {
        $path = $this->overridePath();
        if (!is_file($path)) {
            return [];
        }

        $raw = @file_get_contents($path);
        if (!is_string($raw) || $raw === '') {
            return [];
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return [];
        }

        // 兼容两种写法：{"1946":"active"} 与 {"authors":{"1946":"active"}}
        $rows = isset($data['authors']) && is_array($data['authors']) ? $data['authors'] : $data;

        $map = [];
        foreach ($rows as $uid => $state) {
            if (!is_string($state) && !is_int($state)) {
                continue;
            }
            $state = (string) $state;
            if (!in_array($state, [self::ACTIVE, self::ZOMBIE], true)) {
                continue;
            }
            if (ctype_digit((string) $uid) && (int) $uid > 0) {
                $map[(int) $uid] = $state;
            }
        }

        return $map;
    }

    /**
     * 指定一位作者属于哪一类（写 override 文件）。
     *
     * @param string $state active / zombie
     * @param string $note  备注，记账用（谁、为什么）
     */
    public function setOverride(int $uid, string $state, string $note = ''): void
    {
        if ($uid <= 0) {
            throw new RuntimeException('uid 必须是正整数');
        }
        if (!in_array($state, [self::ACTIVE, self::ZOMBIE], true)) {
            throw new RuntimeException('只能指定成 active 或 zombie');
        }

        $data = $this->overrides();
        $data[$uid] = $state;
        $this->writeOverrides($data, $note);
    }

    /** 取消人工指定，回到机器判定。@return bool 是否真的删掉了一条 */
    public function clearOverride(int $uid): bool
    {
        $data = $this->overrides();
        if (!isset($data[$uid])) {
            return false;
        }

        unset($data[$uid]);
        $this->writeOverrides($data, '');

        return true;
    }

    /**
     * @param array<int,string> $data
     */
    private function writeOverrides(array $data, string $note = ''): void
    {
        ksort($data);

        $path = $this->overridePath();
        $dir  = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0o775, true) && !is_dir($dir)) {
            throw new RuntimeException('缓存目录不可写：' . $dir);
        }

        $payload = [
            'meta'    => [
                'updated_at' => time(),
                'note'       => $note,
                'count'      => count($data),
            ],
            'authors' => $data === [] ? new \stdClass() : $data,
        ];

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($json === false) {
            throw new RuntimeException('人工分类序列化失败');
        }

        $tmp = $path . '.tmp';
        if (@file_put_contents($tmp, $json) === false) {
            throw new RuntimeException('写入失败：' . $tmp);
        }
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException('替换失败：' . $path);
        }
    }

    private function overridePath(): string
    {
        return rtrim($this->dir, '/\\') . '/' . self::OVERRIDE_FILE;
    }

    private function path(): string
    {
        return rtrim($this->dir, '/\\') . '/activity.json';
    }

    /** 分类快照的生成时间戳（0 = 还没做过）。一键拆解页用它提示「按作者类型筛选是否可用」。 */
    public function builtAt(): int
    {
        $path = $this->path();
        if (!is_file($path)) {
            return 0;
        }

        $raw = json_decode((string) file_get_contents($path), true);

        return is_array($raw) ? (int) ($raw['meta']['built_at'] ?? 0) : 0;
    }
}
