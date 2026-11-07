<?php

declare(strict_types=1);

namespace App\Http\Controller\Admin;

use App\Application\Analysis\TermEncyclopedia;
use App\Application\Library\PageCache;
use App\Application\Library\PublicTextGuard;
use App\Application\Library\VisibilityService;
use App\Infrastructure\Pdo\AuditLogger;
use App\Infrastructure\Pdo\TermEncyclopediaRepository;
use App\Infrastructure\Pdo\TermRepository;
use App\Support\Session;
use App\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * 词条治理。
 *
 * 1300 篇跑完之后词条会到两三千条，其中相当一部分是「只被一篇用到」的孤词。
 * 词库是给人按概念找作品的入口，所以运营在这里主要做三件事：
 *
 *   1. **看**：哪些词被藏了、哪些词只有一篇作品、哪些词一条都不剩；
 *   2. **藏**：把不该出现在词库里的词（泄底、错别字、过于私人的专名）收起来；
 *   3. **判断依据**：详情页给出「前台可见几篇 / 实际关联几篇」两个数字，
 *      差值就是被隐藏的作品吃掉的量 —— 这比只看一个数字有用得多。
 *
 * 这里**不提供删除**。词条被删掉之后，作品页上的关键词就断链、检索里那条记录也没了，
 * 恢复只能靠重跑。隐藏是可逆的，删除不是；治理动作应当优先选可逆的那个。
 */
final class TermAdminController extends AdminController
{
    private const PER_PAGE = 50;

    public function __construct(
        View $view,
        Session $session,
        private readonly TermRepository $terms,
        private readonly VisibilityService $visibility,
        private readonly AuditLogger $audit,
        private readonly TermEncyclopedia $encyclopedia,
        private readonly TermEncyclopediaRepository $encyclopediaRepo,
        private readonly PublicTextGuard $guard,
        private readonly PageCache $cache,
    ) {
        parent::__construct($view, $session);
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $query  = $request->getQueryParams();
        $page   = max(1, (int) ($query['page'] ?? 1));
        $filter = [
            'keyword'    => (string) ($query['q'] ?? ''),
            'category'   => (string) ($query['cat'] ?? ''),
            'visibility' => (string) ($query['vis'] ?? ''),
            'sort'       => (string) ($query['sort'] ?? 'refs'),
        ];

        $result = $this->terms->paginate($filter, $page, self::PER_PAGE);

        return $this->render($response, 'admin/terms/index', [
            'nav'    => 'terms',
            'user'   => (array) $request->getAttribute('user', []),
            'rows'   => $result['rows'],
            'total'  => $result['total'],
            'page'   => $page,
            'pages'  => max(1, (int) ceil($result['total'] / self::PER_PAGE)),
            'filter' => $filter,
            'stats'  => $this->terms->adminStats(),
        ]);
    }

    public function show(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $term = $this->terms->findForAdmin((int) ($args['id'] ?? 0));
        if ($term === null) {
            $this->session->flash('warn', '没有这个词条。');

            return $this->redirect($response, '/admin/terms');
        }

        return $this->render($response, 'admin/terms/show', $this->showPayload(
            $term,
            (array) $request->getAttribute('user', [])
        ));
    }

    /**
     * 保存「百科」两块：起源与发展、世界科幻中的它。
     *
     * 为什么单独一条路由、而不是并进分析页的词条编辑：这两段文字与拆解结果无关，
     * 它们本来就该在不碰 AI 流水线的前提下可改。放到这里之后，运营补一段考据
     * 不需要重跑任何分析，也不需要等部署。
     */
    public function saveEncyclopedia(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $term = $this->terms->findForAdmin((int) ($args['id'] ?? 0));
        if ($term === null) {
            $this->session->flash('warn', '没有这个词条。');

            return $this->redirect($response, '/admin/terms');
        }

        $termId = (int) $term['id'];
        $user   = (array) $request->getAttribute('user', []);
        $body   = (array) ($request->getParsedBody() ?? []);

        $history  = trim((string) ($body['history'] ?? ''));
        $classics = [];
        $errors   = [];

        if ($history === '') {
            $errors[] = '「起源与发展」不能为空 —— 这一节是公开页的正文，不接受空提交。';
        } elseif (mb_strlen($history, 'UTF-8') > 1000) {
            // 80–200 只是写作提示（模板里带计数），1000 才是硬边界：列类型是 VARCHAR(1000)
            $errors[] = '「起源与发展」超过 1000 字，请压缩后再保存。';
        }

        /** @var array<int|string, mixed> $rows */
        $rows = is_array($body['classics'] ?? null) ? $body['classics'] : [];
        foreach (array_slice(array_values($rows), 0, TermEncyclopediaRepository::MAX_CLASSICS) as $i => $row) {
            if (!is_array($row)) {
                continue;
            }
            $title  = trim((string) ($row['title'] ?? ''));
            $author = trim((string) ($row['author'] ?? ''));
            $year   = (int) ($row['year'] ?? 0);

            // 空行忽略：三组输入框里只用一组是常态，不该被当成三条错误
            if ($title === '' && $author === '' && $year === 0) {
                continue;
            }
            $n = $i + 1;
            if ($title === '' || $author === '') {
                $errors[] = sprintf('第 %d 部作品的书名与作者都要填；整行留空则忽略。', $n);
                continue;
            }
            if ($year < 1000 || $year > 2035) {
                $errors[] = sprintf('第 %d 部作品的年份不像年份（%d）。', $n, $year);
                continue;
            }

            $classics[] = ['title' => $title, 'author' => $author, 'year' => $year];
        }

        if ($errors !== []) {
            return $this->render($response, 'admin/terms/show', $this->showPayload($term, $user, [
                'enc_form'   => ['history' => $history, 'classics' => $classics],
                'enc_errors' => $errors,
            ]), 422);
        }

        $actor  = ((int) ($user['id'] ?? 0)) ?: null;
        $before = $this->encyclopediaRepo->findByTermId($termId);

        $this->encyclopediaRepo->upsert($termId, $history, $classics, $actor);
        $this->audit->log(
            $actor,
            'term.encyclopedia.update',
            'term',
            (string) $termId,
            $before,
            ['history' => $history, 'classics' => $classics]
        );

        // 公开页按 term:{id} 与 terms 两个标签缓存 300 秒；不失效就要等五分钟才看得到
        $this->cache->invalidateTags(['terms', 'term:' . $termId]);

        // 这段文字直接上公开页，和前台走同一道守卫。守卫是「渲染时遮蔽」而不是
        // 「保存时改写」——把 ＊ 写进库会把原文永久毁掉，隐藏解除后也回不来。
        // 这里只提示运营：他写的词里有被隐藏的词条，公开页会打码。
        if ($this->guard->text($history) !== $history) {
            $this->session->flash('warn', '内容已保存。文字里提到了已隐藏的词条，公开页上那几处会显示为 ＊（原文保持不变）。');
        }

        $this->session->flash('ok', '百科内容已保存，公开页已同步。');

        return $this->redirect($response, '/admin/terms/' . $termId . '#enc');
    }

    /**
     * 详情页的公共数据。
     *
     * 抽出来的原因：保存失败要把用户刚敲的内容原样带回去（422 重渲染），
     * 那时还需要 term / aliases / works —— 复制一遍就会有两份会走偏的取数逻辑。
     *
     * @param array<string, mixed> $term
     * @param array<string, mixed> $user
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function showPayload(array $term, array $user, array $extra = []): array
    {
        $termId = (int) $term['id'];

        // 「来自数据库」与「来自文件基线」对运营是两种不同的状态：前者改得动，
        // 后者一保存就会落库。页面上必须说清楚，否则会以为文件里的内容也在这里改。
        $dbRow  = $this->encyclopediaRepo->findByTermId($termId);
        $enc    = $this->encyclopedia->find($termId, (string) $term['canonical']);
        $source = $dbRow !== null ? 'db' : ($enc !== null ? 'file' : 'none');

        // 表单永远给三组（空的也占位），模板就不用处理「下标不存在」——
        // Twig 是 strict_variables，少一个键会直接炸而不是渲染空白。
        $form     = is_array($extra['enc_form'] ?? null) ? $extra['enc_form'] : null;
        $fromForm = is_array($form['classics'] ?? null) ? array_values($form['classics']) : null;
        $values   = $fromForm ?? ($enc['classics'] ?? []);

        $rows = [];
        for ($i = 0; $i < TermEncyclopediaRepository::MAX_CLASSICS; $i++) {
            $row    = is_array($values[$i] ?? null) ? $values[$i] : [];
            $rows[] = [
                'title'  => (string) ($row['title'] ?? ''),
                'author' => (string) ($row['author'] ?? ''),
                'year'   => (int) ($row['year'] ?? 0) ?: '',
            ];
        }

        return array_merge([
            'nav'        => 'terms',
            'user'       => $user,
            'term'       => $term,
            'aliases'    => $this->terms->aliasesOf($termId),
            'works'      => $this->terms->worksOfTermForAdmin($termId),
            'enc'        => $enc,
            'enc_source' => $source,
            'enc_history' => $form !== null ? (string) ($form['history'] ?? '') : (string) ($enc['history'] ?? ''),
            'enc_rows'   => $rows,
            'enc_errors' => $extra['enc_errors'] ?? [],
        ], $extra);
    }

    /** 批量隐藏。scope=ids 用勾选的，scope=filter 用当前筛选条件。 */
    public function hide(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->toggle($request, $response, 'hidden');
    }

    public function restore(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->toggle($request, $response, 'visible');
    }

    private function toggle(ServerRequestInterface $request, ResponseInterface $response, string $to): ResponseInterface
    {
        $user = (array) $request->getAttribute('user', []);
        $body = (array) ($request->getParsedBody() ?? []);

        $ids = (string) ($body['scope'] ?? 'ids') === 'filter'
            ? $this->terms->idsByFilter([
                'keyword'    => (string) ($body['q'] ?? ''),
                'category'   => (string) ($body['cat'] ?? ''),
                'visibility' => (string) ($body['vis'] ?? ''),
            ], 2000)
            : array_values(array_unique(array_filter(
                array_map('intval', is_array($body['ids'] ?? null) ? $body['ids'] : []),
                static fn (int $id): bool => $id > 0
            )));

        if ($ids === []) {
            $this->session->flash('warn', '没有选中任何词条。');

            return $this->redirect($response, $this->backUrl($body));
        }

        $actor = ((int) ($user['id'] ?? 0)) ?: null;
        $result = $to === 'hidden'
            ? $this->visibility->hide(VisibilityService::TERM, $ids, $actor, (string) ($body['reason'] ?? ''))
            : $this->visibility->restore(VisibilityService::TERM, $ids, $actor);

        $this->session->flash(
            'ok',
            sprintf(
                '%s %d 个词条，%d 个页面缓存已失效。',
                $to === 'hidden' ? '已隐藏' : '已恢复',
                $result['changed'],
                $result['pages']
            )
        );

        return $this->redirect($response, $this->backUrl($body));
    }

    /** @param array<string, mixed> $body */
    private function backUrl(array $body): string
    {
        $back = (string) ($body['back'] ?? '/admin/terms');

        // 只接受站内路径：这个值来自表单，放任它就是个开放重定向
        return str_starts_with($back, '/admin/') ? $back : '/admin/terms';
    }
}
