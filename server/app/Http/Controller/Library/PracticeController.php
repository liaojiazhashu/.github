<?php

declare(strict_types=1);

namespace App\Http\Controller\Library;

use App\Infrastructure\Pdo\PracticeStatRepository;
use App\Support\Config;
use App\View\View;
use App\Application\Library\PublicTextGuard;
use App\Infrastructure\Pdo\LibraryRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * 练笔统计：每一期与总计的六个数。
 *
 * ## 为什么这一页要在馆内、并且要缓存一小时
 *
 * 数据每月 16 日 18:00 重算一次（见 /etc/cron.d/hqlib-practice），一天之内不会变。
 * 让每一次访问都去查库、甚至去官网抓一遍，既没有意义，也会在对方限流时把这一页
 * 变成全站最慢的一页。所以整页缓存一小时，且登录态无关（公开页不启 session）。
 *
 * ## 为什么把口径写在页面上
 *
 * 这一页的数字会被人截图拿去汇报。「公开 1183 篇」这句话离开口径就没有意义 ——
 * 分母含不含已公开未发布的、匿名算不算、评稿含不含 AI，四个问题各有一个答案。
 * 与其等别人来问，不如把口径放在数字下面，和数字一样显眼。
 *
 * ## 为什么没有数据时不是 404
 *
 * 每月 16 日才重算，新部署或第一次跑之前，这一页本来就该是空的。
 * 给 404 会让「还没算」看起来像「这一页不存在」；给 200 + 空态说明，
 * 才说得清现在是哪一种。
 */
final class PracticeController extends LibraryController
{
    public function __construct(
        View $view,
        LibraryRepository $library,
        Config $config,
        PublicTextGuard $guard,
        private readonly PracticeStatRepository $stats,
    ) {
        parent::__construct($view, $library, $config, $guard);
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $snapshot = $this->stats->latest();

        $rows  = [];
        $total = [];
        $run   = [];

        if ($snapshot !== null) {
            $run   = $snapshot['run'];
            $total = $snapshot['total'];

            foreach ($snapshot['rows'] as $r) {
                $works = (int) $r['works_total'];
                $rows[] = $r + [
                    'avg_words' => $works > 0 ? intdiv((int) $r['words_total'], $works) : 0,
                    'open_rate' => $works > 0 ? (int) round((int) $r['works_public'] * 100 / $works) : 0,
                ];
            }

            if ($total !== []) {
                $tw = (int) $total['works_total'];
                $total['avg_words'] = $tw > 0 ? intdiv((int) $total['words_total'], $tw) : 0;
                $total['open_rate'] = $tw > 0 ? (int) round((int) $total['works_public'] * 100 / $tw) : 0;
            }
        }

        // 已知的、值得在页面上说清楚的三个落差。写死在这里而不是模板里，
        // 是因为它们属于口径，不该散落在版式里。
        $notes = [
            'works'   => '只数正式稿：草稿箱（未投出的稿）与回收站（已删）不进分母。'
                . '「已公开但未通过」是投出去了、但读者还读不到的那一批 —— 它既不算公开，也不该被悄悄丢掉。',
            'authors' => '同一人投多期只算一位作者；匿名作品的作者名被抹掉，但账号仍是他本人，所以照样计入。',
            'reviews' => '评稿指带五维打分的那种（不是短文评论）；AI 评稿单列，不算进「有多少人评过稿」。',
        ];

        return $this->render($response, 'library/practice', [
            'page_title' => '练笔统计：每一期收到了多少稿 · 银河图书馆',
            'page_desc'  => '荒启科幻练笔按期统计：公开与未公开作品数、作者数、评稿成员与评稿数、作品总字数，每月 16 日重算。',
            'canonical'  => $this->config->string('app.url') . '/library/practice',
            'nav'        => 'practice',
            'rows'       => $rows,
            'total'      => $total,
            'run'        => $run,
            'notes'      => $notes,
        ]);
    }
}
