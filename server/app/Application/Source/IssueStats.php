<?php

declare(strict_types=1);

namespace App\Application\Source;

use App\Infrastructure\Pdo\PracticeStatRepository;
use App\Support\Ulid;
use RuntimeException;

/**
 * 练笔统计：按「每一期」与「总计」两个维度，数清六件事。
 *
 * ## 六个数各自的口径（这是本类唯一重要的事）
 *
 * | 指标 | 口径 |
 * | --- | --- |
 * | 公开作品数 | `type=post` ∧ 未进回收站 ∧ `isopen=1` ∧ `status=publish` |
 * | 未公开作品数 | 同上，但 `isopen=0`（**含匿名作品** —— 匿名只抹作者名，不动可见性） |
 * | 作者数 | 上述作品的作者按 uid 去重（匿名作品的 uid 是真实的，照样计入） |
 * | 评稿成员数 | 落在本期作品上的评稿，按评稿人 uid 去重（不含 AI 评稿） |
 * | 评稿数 | 同上，条数（不含 AI 评稿，AI 单列） |
 * | 作品总字数 | 上述作品的 `size` 之和 |
 *
 * ## 为什么分母里还有「已公开但未通过」这一类
 *
 * 站里有 129 篇 `isopen=1` 却 `status=reject` 的稿子：对读者而言它们读不到，
 * 对作者而言它们确实投出去了。把它们算进「公开」会让公开数虚高，
 * 直接丢掉又会让「本期一共收到多少稿」对不上。所以它们单列一档
 * （`works_rejected`），并计入分母 —— 页面上的三档相加正好等于投稿总数。
 *
 * ## 为什么评稿要翻页，不能直接用站方的 reviewNum
 *
 * 站方 `hqMetas/metaInfo` 给了 `reviewNum`，但实测比可枚举的条数多：
 * 第 25 期 274 vs 271、第 22 期 969 vs 941。差的那几条是被删掉的评稿，
 * 仍然留在计数器里。需求要的是「评稿数量」，一个能指出是哪几条的数
 * 比一个对不上的大数有用。站方那两个值仍然存下来（`site_review*`），
 * 差得离谱时报警用。
 *
 * ## 为什么评稿要再按 cid 过一遍
 *
 * `activeid` 返回的评稿里，可能夹着打在本期草稿上的评稿。分母既然排除了草稿箱，
 * 评稿也要跟着排除，否则会出现「61 篇作品、271 条评稿，其中几条评的是不存在的稿」。
 *
 * ## 限流的代价
 *
 * 26 个期次、约一百五十页评稿，翻页间隔 400ms，一次重算两分钟左右。
 * 这是一月一次的活，慢一点没关系；快一点的结果是被官网丢包、然后重试更久。
 */
final class IssueStats
{
    /** 每页条数：服务端硬上限就是 50，传大了也只回 50 */
    private const PER_PAGE = SourceApi::MAX_LIMIT;

    /** 翻页间隔（微秒）。400ms × 150 页 ≈ 一分钟，实测不触发丢包 */
    private const PAGE_DELAY_US = 400_000;

    private const PAGE_ATTEMPTS = 3;

    private const BACKOFF_US = [0, 2_000_000, 5_000_000];

    /** 单期最多翻多少页，防 total 异常时打不完 */
    private const MAX_PAGES = 100;

    public function __construct(
        private readonly SourceApi $api,
        private readonly SourceCatalog $catalog,
        private readonly PracticeStatRepository $repo,
    ) {
    }

    /**
     * 算一遍。
     *
     * @param bool $withReviews 是否连评稿一起数（false 时评稿相关字段为 0，用来快跑）
     * @param callable(int,string):void|null $onIssue 每算完一期回调（期号、说明）
     * @return array<string,mixed>
     */
    public function build(bool $withReviews = true, ?callable $onIssue = null): array
    {
        $catalog = $this->catalog->load();
        if ($catalog === null) {
            throw new RuntimeException('还没有目录快照，先跑一次 `bin/console.php source:sync`');
        }

        $items = $catalog['items'] ?? [];
        if ($items === []) {
            throw new RuntimeException('目录快照是空的，先重跑 `source:sync`');
        }

        /** @var array<int,list<array<string,mixed>>> $byIssue */
        $byIssue = [];
        foreach ($items as $item) {
            $no = (int) ($item['issueNo'] ?? 0);
            if ($no <= 0) {
                continue;
            }
            $byIssue[$no][] = $item;
        }
        ksort($byIssue);

        $fetchedAt = date('Y-m-d H:i:s');
        $rows      = [];
        $allUids   = [];
        $pubUids   = [];
        $revUids   = [];
        $revUidsAll = [];

        $sum = [
            'works_public' => 0, 'works_hidden' => 0, 'works_rejected' => 0, 'works_total' => 0,
            'words_public' => 0, 'words_hidden' => 0, 'words_rejected' => 0, 'words_total' => 0,
            'works_anon' => 0, 'review_count' => 0, 'review_ai' => 0,
            'site_works' => 0, 'site_words' => 0, 'site_review' => 0, 'site_review_people' => 0,
        ];

        foreach ($byIssue as $no => $list) {
            $row = $this->countIssue($no, $list);

            if ($withReviews) {
                $rev = $this->countReviews((int) $row['source_mid'], $row['cids']);
                $row = array_merge($row, $rev['counts']);
                // 去重集合是给「总计」用的中间量：合计里的评稿成员数必须是并集，
                // 不能把每期的 47 人加起来再说「一共 47 人」
                $row['__rev_uids']     = $rev['uids'];
                $row['__rev_uids_all'] = $rev['uids_all'];

                $meta = $this->retry(fn (): ?array => $this->api->activity((int) $row['source_mid']));
                if ($meta !== null) {
                    $row['site_works']         = (int) ($meta['count'] ?? 0);
                    $row['site_words']         = (int) ($meta['wordCount'] ?? 0);
                    $row['site_review']        = (int) ($meta['reviewNum'] ?? 0);
                    $row['site_review_people'] = (int) ($meta['reviewPeople'] ?? 0);
                }

                if ($onIssue !== null) {
                    $onIssue($no, sprintf(
                        '公开 %d · 未公开 %d · 作者 %d · 评稿 %d 条 / %d 人',
                        $row['works_public'], $row['works_hidden'], $row['authors'],
                        $row['review_count'], $row['review_people']
                    ));
                }
            } elseif ($onIssue !== null) {
                $onIssue($no, sprintf(
                    '公开 %d · 未公开 %d · 作者 %d',
                    $row['works_public'], $row['works_hidden'], $row['authors']
                ));
            }

            $row['fetched_at'] = $fetchedAt;
            unset($row['cids']);
            $rows[] = $row;

            foreach ($row['__uids'] as $uid) {
                $allUids[$uid] = true;
            }
            foreach ($row['__pub_uids'] as $uid) {
                $pubUids[$uid] = true;
            }
            foreach ($row['__rev_uids'] as $uid) {
                $revUids[$uid] = true;
            }
            foreach ($row['__rev_uids_all'] as $uid) {
                $revUidsAll[$uid] = true;
            }
            unset($row['__uids'], $row['__pub_uids'], $row['__rev_uids'], $row['__rev_uids_all']);

            foreach (['works_public', 'works_hidden', 'works_rejected', 'works_total',
                      'words_public', 'words_hidden', 'words_rejected', 'words_total',
                      'works_anon', 'review_count', 'review_ai',
                      'site_works', 'site_words', 'site_review', 'site_review_people'] as $k) {
                $sum[$k] += (int) ($rows[count($rows) - 1][$k] ?? 0);
            }
        }

        // 明细行里的集合字段是「算下一步用的中间量」，不该进数据库，也不该出现在返回值里
        foreach ($rows as &$r) {
            unset($r['__uids'], $r['__pub_uids'], $r['__rev_uids'], $r['__rev_uids_all']);
        }
        unset($r);

        $total = [
            'issue_no'            => PracticeStatRepository::TOTAL_ISSUE,
            'source_mid'          => 0,
            'label'               => '总计',
            'works_public'        => $sum['works_public'],
            'works_hidden'        => $sum['works_hidden'],
            'works_rejected'      => $sum['works_rejected'],
            'works_total'         => $sum['works_total'],
            'words_public'        => $sum['words_public'],
            'words_hidden'        => $sum['words_hidden'],
            'words_rejected'      => $sum['words_rejected'],
            'words_total'         => $sum['words_total'],
            'authors'             => count($allUids),
            'authors_public'      => count($pubUids),
            'works_anon'          => $sum['works_anon'],
            'review_count'        => $sum['review_count'],
            'review_people'       => count($revUids),
            'review_ai'           => $sum['review_ai'],
            'review_people_all'   => count($revUidsAll),
            'site_works'          => $sum['site_works'],
            'site_words'          => $sum['site_words'],
            'site_review'         => $sum['site_review'],
            'site_review_people'  => $sum['site_review_people'],
            'ended_at'            => 0,
            'reveal_at'           => 0,
            'fetched_at'          => $fetchedAt,
        ];

        return [
            'catalog_at' => (int) ($catalog['meta']['fetched_at'] ?? 0),
            'catalog_n'  => count($items),
            'fetched_at' => $fetchedAt,
            'rows'       => $rows,
            'total'      => $total,
        ];
    }

    /**
     * 把算出来的结果封存成一轮快照。
     *
     * 失败也要在 run 上留一行 `failed`：一条「跑失败了」的记录比「什么都没有」
     * 有用得多 —— 至少下一次看到的是「上次 18:00 失败」，而不是「好像从来没跑过」。
     *
     * @param array<string,mixed> $built build() 的返回值
     * @param int|null $startedAt 这一轮真正开始的时刻（Unix 秒）；不给则按「现在」算
     * @return array{run_id:int, code:string}
     */
    public function persist(array $built, string $triggered = 'cli', string $note = '', ?int $startedAt = null): array
    {
        $code = Ulid::generate();
        $run  = $this->repo->beginRun(
            $code,
            $triggered,
            $note,
            $startedAt === null ? null : date('Y-m-d H:i:s', $startedAt)
        );

        try {
            $this->repo->saveRows(
                $run['id'],
                array_merge($built['rows'], [$built['total']]),
                (int) $built['catalog_at'] > 0 ? date('Y-m-d H:i:s', (int) $built['catalog_at']) : '',
                (int) $built['catalog_n'],
                count($built['rows'])
            );
        } catch (\Throwable $e) {
            $this->repo->fail($run['id'], $e->getMessage());
            throw $e;
        }

        return ['run_id' => $run['id'], 'code' => $code];
    }

    /**
     * 导出成 CSV（一期一行 + 末行总计）。
     *
     * 带 BOM 与 CRLF，理由同 SourceCatalog::toCsv：这份文件是给人拿 Excel 打开的。
     *
     * @param array<string,mixed> $built
     */
    public function toCsv(array $built): string
    {
        $head = ['期次', '活动mid', '公开作品', '未公开作品', '已公开未发布', '作品合计',
                 '作者数', '匿名作品', '评稿成员', '评稿数', 'AI评稿', '总字数', '平均字数', '截止时间'];

        $fh = fopen('php://temp', 'r+');
        if ($fh === false) {
            throw new RuntimeException('无法创建临时流');
        }
        fputcsv($fh, $head, ',', '"', '\\');

        foreach (array_merge($built['rows'], [$built['total']]) as $r) {
            $total = (int) $r['works_total'];
            fputcsv($fh, [
                (string) $r['label'],
                (int) $r['source_mid'] ?: '',
                (int) $r['works_public'],
                (int) $r['works_hidden'],
                (int) $r['works_rejected'],
                $total,
                (int) $r['authors'],
                (int) $r['works_anon'],
                (int) $r['review_people'],
                (int) $r['review_count'],
                (int) $r['review_ai'],
                (int) $r['words_total'],
                $total > 0 ? intdiv((int) $r['words_total'], $total) : 0,
                (int) $r['ended_at'] > 0 ? date('Y-m-d H:i', (int) $r['ended_at']) : '',
            ], ',', '"', '\\');
        }

        rewind($fh);
        $csv = (string) stream_get_contents($fh);
        fclose($fh);

        return "\xEF\xBB\xBF" . str_replace("\n", "\r\n", $csv);
    }

    /**
     * 单期：只数作品，不碰网络。
     *
     * @param list<array<string,mixed>> $list 本期在目录里的全部条目（含草稿、含未公开）
     * @return array<string,mixed>
     */
    private function countIssue(int $no, array $list): array
    {
        $public = $hidden = $rejected = 0;
        $wPublic = $wHidden = $wRejected = 0;
        $anon = 0;

        $uids    = [];
        $pubUids = [];
        $cids    = [];
        $label   = '';
        $mid     = 0;
        $ended   = 0;
        $reveal  = 0;

        foreach ($list as $i) {
            $label  = (string) ($i['issueLabel'] ?? ('第 ' . $no . ' 期'));
            $mid    = (int) ($i['practiceMid'] ?? 0) ?: $mid;
            $ended  = max($ended, (int) ($i['endsAt'] ?? 0));
            $reveal = max($reveal, (int) ($i['revealAt'] ?? 0));

            // 草稿箱与回收站不进分母。这两条是需求的硬要求，也正好是目录里
            // 唯一两类「看起来像作品、实际不是」的东西。
            if ((string) ($i['type'] ?? '') !== 'post') {
                continue;
            }
            if ((int) ($i['isDelete'] ?? 0) === 1) {
                continue;
            }

            $words  = (int) ($i['wordCount'] ?? 0);
            $open   = (int) ($i['isopen'] ?? 1) === 1;
            $pub    = (string) ($i['status'] ?? '') === 'publish';
            $uid    = (int) ($i['authorUid'] ?? 0);
            $name   = (string) ($i['authorName'] ?? '');

            $cids[] = (int) $i['cid'];
            if ($uid > 0) {
                $uids[$uid] = true;
            }
            if (in_array($name, AuthorActivity::ANON_NAMES, true)) {
                ++$anon;
            }

            if ($open && $pub) {
                ++$public;
                $wPublic += $words;
                if ($uid > 0) {
                    $pubUids[$uid] = true;
                }
            } elseif (!$open) {
                ++$hidden;
                $wHidden += $words;
            } else {
                ++$rejected;
                $wRejected += $words;
            }
        }

        return [
            'issue_no'       => $no,
            'source_mid'     => $mid,
            'label'          => $label,
            'works_public'   => $public,
            'works_hidden'   => $hidden,
            'works_rejected' => $rejected,
            'works_total'    => $public + $hidden + $rejected,
            'words_public'   => $wPublic,
            'words_hidden'   => $wHidden,
            'words_rejected' => $wRejected,
            'words_total'    => $wPublic + $wHidden + $wRejected,
            'authors'        => count($uids),
            'authors_public' => count($pubUids),
            'works_anon'     => $anon,
            'review_count'   => 0,
            'review_people'  => 0,
            'review_ai'      => 0,
            'review_people_all' => 0,
            'site_works'     => 0,
            'site_words'     => 0,
            'site_review'    => 0,
            'site_review_people' => 0,
            'ended_at'       => $ended,
            'reveal_at'      => $reveal,
            // 下面几个是中间量，build() 用完即弃
            'cids'           => $cids,
            '__uids'         => array_keys($uids),
            '__pub_uids'     => array_keys($pubUids),
            '__rev_uids'     => [],
            '__rev_uids_all' => [],
        ];
    }

    /**
     * 一期的评稿：翻完所有页，只留打在本期分母内作品上的。
     *
     * @param list<int> $cids 本期计入的作品 cid
     * @return array{counts:array<string,int>, uids:list<int>}
     */
    private function countReviews(int $mid, array $cids): array
    {
        $empty = ['counts' => [
            'review_count' => 0, 'review_people' => 0, 'review_ai' => 0, 'review_people_all' => 0,
        ]];

        if ($mid <= 0 || $cids === []) {
            return $empty;
        }

        $inIssue = array_fill_keys($cids, true);
        $seen    = [];
        $seenAll = [];
        $ai      = 0;
        $rows    = 0;
        $page    = 1;
        $total   = null;

        while ($page <= self::MAX_PAGES) {
            $res = $this->page($mid, $page);
            if ($res === null) {
                // 翻到一半失败就整期作废：给一个偏小的数比给一个错的好，
                // 但也绝不能把「没翻完」当成「就这么多」—— 所以返回全 0 并让人重跑。
                return $empty;
            }

            $total ??= $res['total'];
            if ($res['data'] === []) {
                break;
            }

            foreach ($res['data'] as $r) {
                $cid = (int) ($r['cid'] ?? 0);
                if (!isset($inIssue[$cid])) {
                    continue;
                }
                ++$rows;

                $uid = (int) ($r['uid'] ?? 0);
                if ($uid > 0) {
                    $seenAll[$uid] = true;
                }
                if ((int) ($r['isAi'] ?? 0) === 1) {
                    ++$ai;
                    continue;
                }
                if ($uid > 0) {
                    $seen[$uid] = true;
                }
            }

            if ($rows >= $total) {
                break;
            }

            ++$page;
            usleep(self::PAGE_DELAY_US);
        }

        return [
            'counts' => [
                'review_count'      => $rows - $ai,
                'review_people'     => count($seen),
                'review_ai'         => $ai,
                'review_people_all' => count($seenAll),
            ],
            'uids'     => array_keys($seen),
            'uids_all' => array_keys($seenAll),
        ];
    }

    /**
     * 拉一页评稿，失败按退避重试。
     *
     * @return array{total:int, data:list<array<string,mixed>>}|null
     */
    private function page(int $mid, int $page): ?array
    {
        for ($i = 0; $i < self::PAGE_ATTEMPTS; ++$i) {
            if ($i > 0) {
                usleep(self::BACKOFF_US[$i] ?? 5_000_000);
            }
            $r = $this->api->reviewsByIssue($mid, $page, self::PER_PAGE);
            if ($r !== null) {
                return $r;
            }
        }

        return null;
    }

    /**
     * @template T
     * @param callable():?T $fn
     * @return T|null
     */
    private function retry(callable $fn): mixed
    {
        for ($i = 0; $i < self::PAGE_ATTEMPTS; ++$i) {
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
}
