<?php

declare(strict_types=1);

namespace App\Http\Controller\Admin;

use App\Application\Analysis\BatchDispatcher;
use App\Application\Analysis\Pipeline;
use App\Application\Analysis\PublishService;
use App\Application\Auth\Permission;
use App\Application\Library\PageCache;
use App\Application\Library\PublicSurface;
use App\Application\Library\VisibilityService;
use App\Application\Work\TextDiff;
use App\Application\Work\WorkImportService;
use App\Http\Support\Uploads;
use App\Infrastructure\Ai\CostModel;
use App\Infrastructure\Import\TextExtractor;
use App\Infrastructure\Pdo\AiCallRepository;
use App\Infrastructure\Pdo\AnalysisRepository;
use App\Infrastructure\Pdo\AuditLogger;
use App\Infrastructure\Pdo\JobRepository;
use App\Infrastructure\Pdo\TermRepository;
use App\Infrastructure\Pdo\WorkRepository;
use App\Infrastructure\Storage\UploadStore;
use App\Support\Config;
use App\Support\Session;
use App\View\View;
use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use RuntimeException;
use Throwable;

/**
 * 作品：列表 / 详情 / 单篇导入 / 批量导入 / 发起分析 / 分析结果。
 */
final class WorkController extends AdminController
{
    /**
     * 批量动作 → 需要的权限码。
     *
     * 为什么要这张表：路由 admin.works.bulk 一条路径承载两类语义 ——
     * 入队 / 取消是「花钱与停机」（analysis.run），隐藏 / 恢复是「内容对外与否」
     * （visibility.toggle），设计上刻意分成两个码（见 config/permissions.php）。
     * 权限表一条路由只能写一组码，所以那边登记的是「两者之一」，
     * 具体到动作的判定必须在控制器里补一层：
     *
     *   · 有 analysis.run 但没有 visibility.toggle 的人，不能借批量入口把内容藏掉；
     *   · 有 visibility.toggle 的审校（reviewer）必须能用批量隐藏 ——
     *     模板里 `canHide` 那一组按钮就是这么承诺的（works/index.twig），
     *     权限层不能反过来打它的脸。
     *
     * 新增批量动作必须在这里登记。漏登记的动作不会越权：它会落到 match 的
     * default 分支（抛「未知的批量动作」），根本执行不到仓储层。
     */
    private const ACTION_PERMISSIONS = [
        'enqueue'      => Permission::ANALYSIS_RUN,
        'reenqueue'    => Permission::ANALYSIS_RUN,
        'cancel'       => Permission::ANALYSIS_RUN,
        'hide'         => Permission::VISIBILITY_TOGGLE,
        'restore'      => Permission::VISIBILITY_TOGGLE,
        'hide-deck'    => Permission::VISIBILITY_TOGGLE,
        'restore-deck' => Permission::VISIBILITY_TOGGLE,
    ];

    public function __construct(
        View $view,
        Session $session,
        private readonly WorkRepository $works,
        private readonly WorkImportService $importer,
        private readonly UploadStore $store,
        private readonly TextExtractor $extractor,
        private readonly Config $config,
        private readonly AnalysisRepository $analyses,
        private readonly JobRepository $jobs,
        private readonly AiCallRepository $aiCalls,
        private readonly CostModel $cost,
        private readonly AuditLogger $audit,
        private readonly Pipeline $pipeline,
        private readonly TermRepository $terms,
        private readonly BatchDispatcher $dispatcher,
        private readonly VisibilityService $visibility,
        private readonly PublicSurface $surface,
        private readonly PageCache $cache,
        private readonly PublishService $publisher,
        private readonly PDO $pdo,
    ) {
        parent::__construct($view, $session);
    }

    /**
     * 编辑完产出后，让前台立刻看到。
     *
     * 前台详情页缓存 300 秒，标签是 work:{code} / works / terms / term:{id}。编辑不改
     * 状态，VisibilityService 那条路不会触发，所以必须在这里补一次失效 —— 否则就是
     * 「后台明明改了，前台还是旧的」，最长要等五分钟，人会以为保存失败。
     *
     * 顺手重建检索索引：关键词表是物化的，标题、作者、词条、建议标题这四样
     * 都在编辑范围内，不重建就会出现「改完词条，搜旧词还能搜到、搜新词搜不到」。
     *
     * 只管**编辑**这条路。发布与下线不走这里，走 PublishService ——
     * 那样五步（状态 → 快照 → 审计 → 索引缓存 → sitemap）才不会各入口漏一处。
     */
    private function forgetPublicPages(?string $workCode, array $termIds = [], bool $withTerms = false): void
    {
        $this->surface->reindex($workCode);
        $this->surface->forget($workCode, $termIds, $withTerms);
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $query  = $request->getQueryParams();
        $page   = max(1, (int) ($query['page'] ?? 1));
        $filter = [
            'keyword'    => (string) ($query['q'] ?? ''),
            'issue'      => (int) ($query['issue'] ?? 0),
            'stage'      => (string) ($query['stage'] ?? ''),
            'visibility' => (string) ($query['visibility'] ?? ''),
            'sort'       => (string) ($query['sort'] ?? 'created'),
        ];

        // 每页 50（原 20）：批量操作要在同一屏里覆盖尽可能多的行，
        // 1300 篇配 20 条一页就是 65 页，勾选得手酸
        $perPage = 50;
        $result  = $this->works->paginate($filter, $page, $perPage);
        $pages   = max(1, (int) ceil($result['total'] / $perPage));

        return $this->render($response, 'admin/works/index', [
            'nav'    => 'works',
            'user'   => (array) $request->getAttribute('user', []),
            'rows'   => $result['rows'],
            'total'  => $result['total'],
            'page'   => $page,
            'pages'  => $pages,
            'filter' => $filter,
            'issues' => $this->works->issues(),
            // 列表要显示「这篇在队列里吗」—— 运营最常问的就是这个
            'queues' => $this->jobs->statusByWork(array_map(
                static fn (array $r): int => (int) $r['id'],
                $result['rows']
            )),
        ]);
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->render($response, 'admin/works/new', [
            'nav'    => 'works',
            'user'   => (array) $request->getAttribute('user', []),
            'issues' => $this->works->issues(),
            'genres' => $this->works->genres(),
            'error'  => null,
            'old'    => ['title' => '', 'author' => '', 'issue_no' => '', 'genre_code' => '', 'tags' => '', 'summary' => ''],
        ]);
    }

    public function store(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = (array) $request->getAttribute('user', []);
        $body = (array) ($request->getParsedBody() ?? []);
        $file = $request->getUploadedFiles()['file'] ?? null;

        $old = [
            'title'      => (string) ($body['title'] ?? ''),
            'author'     => (string) ($body['author'] ?? ''),
            'issue_no'   => (string) ($body['issue_no'] ?? ''),
            'genre_code' => (string) ($body['genre_code'] ?? ''),
            'tags'       => (string) ($body['tags'] ?? ''),
            'summary'    => (string) ($body['summary'] ?? ''),
        ];

        $errorPage = static fn (string $message, array $old) => $message;

        try {
            if (!$file instanceof UploadedFileInterface) {
                throw new RuntimeException('请选择要导入的文件');
            }
            Uploads::checkError($file);

            if ($file->getSize() !== null && $file->getSize() > $this->config->int('upload.max_bytes')) {
                throw new RuntimeException('文件超过 ' . round($this->config->int('upload.max_bytes') / 1048576) . ' MiB 上限');
            }

            $ext = Uploads::extensionOf($file);
            if (!in_array($ext, $this->config->list('upload.allowed_ext'), true)) {
                throw new RuntimeException('只接受 txt / md / docx 文件');
            }

            $tmp  = Uploads::toTempPath($file);
            $mime = Uploads::sniffMime($tmp);

            try {
                $result = $this->importer->importFile([
                    'title'      => $old['title'],
                    'author'     => $old['author'],
                    'issue_no'   => (int) $old['issue_no'],
                    'genre_code' => $old['genre_code'] !== '' ? $old['genre_code'] : null,
                    'tags'       => $old['tags'],
                    'summary'    => $old['summary'] !== '' ? $old['summary'] : null,
                    'source'     => 'import',
                ], [
                    'tmp_path'   => $tmp,
                    'orig_name'  => (string) $file->getClientFilename(),
                    'ext'        => $ext,
                    'mime'       => $mime,
                    'size_bytes' => (int) ($file->getSize() ?? 0),
                ], (int) ($user['id'] ?? 0));
            } finally {
                if (is_file($tmp)) {
                    @unlink($tmp);
                }
            }

            $this->session->flash('ok', '已导入《' . $old['title'] . '》');
            foreach ($result['warnings'] as $warning) {
                $this->session->flash('warn', $warning);
            }

            return $this->redirect($response, '/admin/works/' . $result['code']);
        } catch (Throwable $e) {
            return $this->render($response, 'admin/works/new', [
                'nav'    => 'works',
                'user'   => $user,
                'issues' => $this->works->issues(),
                'genres' => $this->works->genres(),
                'error'  => $errorPage($e->getMessage(), $old),
                'old'    => $old,
            ], 422);
        }
    }

    public function importForm(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->render($response, 'admin/works/import', [
            'nav'    => 'works',
            'user'   => (array) $request->getAttribute('user', []),
            'issues' => $this->works->issues(),
            'genres' => $this->works->genres(),
            'error'  => null,
            'result' => null,
            'old'    => ['author' => '', 'issue_no' => '', 'genre_code' => '', 'tags' => ''],
        ]);
    }

    public function importBatch(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = (array) $request->getAttribute('user', []);
        $body = (array) ($request->getParsedBody() ?? []);
        $old  = [
            'author'     => (string) ($body['author'] ?? ''),
            'issue_no'   => (string) ($body['issue_no'] ?? ''),
            'genre_code' => (string) ($body['genre_code'] ?? ''),
            'tags'       => (string) ($body['tags'] ?? ''),
        ];

        $maxFiles = 50;
        try {
            $raw   = $request->getUploadedFiles()['files'] ?? [];
            $files = is_array($raw) ? $raw : [$raw];
            $files = array_values(array_filter($files, static fn ($f) => $f instanceof UploadedFileInterface));
            $files = array_values(array_filter($files, static fn (UploadedFileInterface $f) => $f->getError() !== UPLOAD_ERR_NO_FILE));

            if ($files === []) {
                throw new RuntimeException('请选择至少一个文件');
            }
            if (count($files) > $maxFiles) {
                throw new RuntimeException('单次最多 ' . $maxFiles . ' 个文件，请分批导入');
            }
            if ($old['author'] === '') {
                throw new RuntimeException('批量导入必须填写作者笔名');
            }

            $prepared = [];
            foreach ($files as $file) {
                // 类型与大小的判定放到逐篇导入里做：一个坏文件不该让整批停下
                Uploads::checkError($file);
                $tmp = Uploads::toTempPath($file);
                $prepared[] = [
                    'tmp_path'   => $tmp,
                    'orig_name'  => (string) $file->getClientFilename(),
                    'ext'        => Uploads::extensionOf($file),
                    'mime'       => Uploads::sniffMime($tmp),
                    'size_bytes' => (int) ($file->getSize() ?? 0),
                ];
            }

            try {
                $result = $this->importer->importBatch($prepared, [
                    'author'     => $old['author'],
                    'issue_no'   => (int) $old['issue_no'],
                    'genre_code' => $old['genre_code'] !== '' ? $old['genre_code'] : null,
                    'tags'       => $old['tags'],
                ], (int) ($user['id'] ?? 0));
            } finally {
                foreach ($prepared as $p) {
                    if (is_file($p['tmp_path'])) {
                        @unlink($p['tmp_path']);
                    }
                }
            }

            return $this->render($response, 'admin/works/import', [
                'nav'    => 'works',
                'user'   => $user,
                'issues' => $this->works->issues(),
                'genres' => $this->works->genres(),
                'error'  => null,
                'result' => $result,
                'old'    => $old,
            ]);
        } catch (Throwable $e) {
            return $this->render($response, 'admin/works/import', [
                'nav'    => 'works',
                'user'   => $user,
                'issues' => $this->works->issues(),
                'genres' => $this->works->genres(),
                'error'  => $e->getMessage(),
                'result' => null,
                'old'    => $old,
            ], 422);
        }
    }

    public function show(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $code = (string) ($args['code'] ?? '');
        $work = $this->works->findByCode($code);

        if ($work === null) {
            return $this->render($response, 'admin/errors/404', [
                'nav'  => 'works',
                'user' => (array) $request->getAttribute('user', []),
            ], 404);
        }

        return $this->render($response, 'admin/works/show', [
            'nav'       => 'works',
            'user'      => (array) $request->getAttribute('user', []),
            'work'      => $work,
            'files'     => $this->works->filesOf((int) $work['id']),
            'tags'      => $this->works->tagsOf((int) $work['id']),
            'preview'   => $this->preview($work),
            'analyses'  => $this->analyses->listForWork((int) $work['id']),
            'latest'    => $this->analyses->latestForWork((int) $work['id']),
            'aiEnabled' => $this->config->bool('ai.enabled'),
        ]);
    }

    /**
     * 发起分析：只入队，不在请求里跑。
     *
     * 为什么不在 Web 请求里同步跑：六阶段 × 单阶段最长 300 秒，随便一篇就能把
     * PHP-FPM 的 request_terminate_timeout 顶掉，而且浏览器一关任务就没了。
     */
    public function analyze(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $code = (string) ($args['code'] ?? '');
        $work = $this->works->findByCode($code);
        $user = (array) $request->getAttribute('user', []);

        if ($work === null) {
            return $this->render($response, 'admin/errors/404', ['nav' => 'works', 'user' => $user], 404);
        }

        if (!$this->config->bool('ai.enabled')) {
            $this->session->flash('warn', 'AI 总开关已关闭（AI_ENABLED=false），开启后才能分析。');

            return $this->redirect($response, '/admin/works/' . $code);
        }

        if ($work['status'] === 'analyzing') {
            $this->session->flash('warn', '这篇正在分析中，等这一轮结束再发起。');

            return $this->redirect($response, '/admin/works/' . $code);
        }

        $body  = (array) ($request->getParsedBody() ?? []);
        $force = (string) ($body['force'] ?? '') === '1';

        $jobId = $this->jobs->enqueue('analysis.run', [
            'work_code'  => $code,
            'work_id'    => (int) $work['id'],
            'force'      => $force,
            'created_by' => (int) ($user['id'] ?? 0) ?: null,
        ], 8);

        $this->audit->log(
            (int) ($user['id'] ?? 0) ?: null,
            'analysis.enqueue',
            'work',
            $code,
            null,
            ['job_id' => $jobId, 'force' => $force]
        );

        $this->session->flash(
            'ok',
            sprintf('已加入队列（任务 #%d）。启动 worker 后会自动开始：php -c php.ini bin/worker.php', $jobId)
        );

        return $this->redirect($response, '/admin/works/' . $code);
    }

    /** 一次分析的完整结果：四件产出 + 六阶段执行记录 + 成本 */
    public function analysis(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $code     = (string) ($args['code'] ?? '');
        $analysis = $this->analyses->findByCode($code);
        $user     = (array) $request->getAttribute('user', []);

        if ($analysis === null) {
            return $this->render($response, 'admin/errors/404', ['nav' => 'works', 'user' => $user], 404);
        }

        $analysisId = (int) $analysis['id'];
        $snapshots  = $this->analyses->snapshots($analysisId);

        // 「这次分析被人改过几次」——重跑会覆盖对应阶段，而这个数字是操作前唯一的提醒。
        // 口径只看备注前缀：编辑 / 归并是人工改动，发布与重跑是状态动作，不算「改过内容」。
        $edited = 0;
        foreach ($snapshots as $snap) {
            $note = (string) ($snap['note'] ?? '');
            if (str_starts_with($note, '编辑') || str_starts_with($note, '归并')) {
                ++$edited;
            }
        }

        return $this->render($response, 'admin/works/analysis', [
            'nav'       => 'works',
            'user'      => $user,
            'analysis'  => $analysis,
            'stages'    => $this->analyses->stagesOf($analysisId),
            'artifacts' => $this->analyses->loadArtifacts($analysisId),
            // 索引词走仓储而不是产物：编辑要 term_id，而 loadArtifacts 出来的是展示形状
            'terms'     => $this->terms->forAnalysis($analysisId),
            'snapshots' => $snapshots,
            'edited'    => $edited,
            'cost'      => CostModel::toYuan($this->aiCalls->costOfAnalysis($analysisId)),
            'calls'     => $this->aiCalls->forAnalysis($analysisId),
        ]);
    }

    /**
     * 软删作品。
     *
     * 为什么只做软删：分析、审计、调用记录必须以"作品"为锚点留在库里。
     * 真删会把「这篇花了多少钱、谁发布的」一起抹掉，而那正是出事之后要查的东西。
     */
    public function delete(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $code = (string) ($args['code'] ?? '');
        $work = $this->works->findByCode($code);
        $user = (array) $request->getAttribute('user', []);

        if ($work === null) {
            return $this->render($response, 'admin/errors/404', ['nav' => 'works', 'user' => $user], 404);
        }

        try {
            if ((string) $work['status'] === 'published') {
                throw new RuntimeException('先下线这篇作品再删除');
            }

            $this->works->softDelete((int) $work['id']);
            $this->audit->log((int) ($user['id'] ?? 0) ?: null, 'work.delete', 'work', $code, ['title' => (string) $work['title']], null);

            $this->session->flash('ok', sprintf('《%s》已软删，列表里不再出现（数据仍在库里，可由运维恢复）。', (string) $work['title']));
        } catch (Throwable $e) {
            $this->session->flash('err', '删除失败：' . $e->getMessage());

            return $this->redirect($response, '/admin/works/' . $code);
        }

        return $this->redirect($response, '/admin/works');
    }

    // =====================================================================
    // M4：版本、diff、重分析、发布、四件产出编辑
    // =====================================================================

    /** 原文版本列表 + 上传新版本 */
    public function versions(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $code = (string) ($args['code'] ?? '');
        $work = $this->works->findByCode($code);

        if ($work === null) {
            return $this->render($response, 'admin/errors/404', [
                'nav'  => 'works',
                'user' => (array) $request->getAttribute('user', []),
            ], 404);
        }

        return $this->render($response, 'admin/works/versions', [
            'nav'      => 'works',
            'user'     => (array) $request->getAttribute('user', []),
            'work'     => $work,
            'versions' => $this->works->versionsOf((int) $work['id']),
        ]);
    }

    /**
     * 上传一个新版本（作者改稿后重新投稿／编辑修订后回填）。
     *
     * 为什么新版本不覆盖旧文件：hq_work_file 是不可变的，版本只是一条指向文件的记录。
     * 这样"某个分析是基于哪一版跑的"永远查得回来，diff 也有两侧可比。
     */
    public function storeVersion(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $code = (string) ($args['code'] ?? '');
        $work = $this->works->findByCode($code);
        $user = (array) $request->getAttribute('user', []);
        $back = '/admin/works/' . $code . '/versions';

        if ($work === null) {
            return $this->render($response, 'admin/errors/404', ['nav' => 'works', 'user' => $user], 404);
        }

        $file = $request->getUploadedFiles()['file'] ?? null;
        $note = trim((string) (($request->getParsedBody()['note'] ?? '')));

        if (!$file instanceof UploadedFileInterface) {
            $this->session->flash('err', '请选择要上传的版本文件');

            return $this->redirect($response, $back);
        }

        try {
            Uploads::checkError($file);
            $tmp = Uploads::toTempPath($file);
            $ext = Uploads::extensionOf($file);
            $mime = Uploads::sniffMime($tmp);

            if (!in_array($ext, ['txt', 'md', 'markdown', 'docx'], true)) {
                throw new RuntimeException('只接受 txt / md / docx');
            }

            $parsed = $this->extractor->extract($tmp, $ext);
            $chars  = mb_strlen($parsed['text'], 'UTF-8');
            if ($chars === 0) {
                throw new RuntimeException('新版本正文为空');
            }

            $stored = $this->store->store($tmp, $ext);
            @unlink($tmp);

            $workId = (int) $work['id'];
            $fileId = $this->works->addFile($workId, [
                'storage_key' => $stored['key'],
                'orig_name'   => (string) $file->getClientFilename(),
                'mime'        => $mime,
                'size_bytes'  => (int) $stored['size'],
                'sha256'      => (string) $stored['sha256'],
                'encoding'    => (string) ($parsed['encoding'] ?? 'UTF-8'),
                'char_count'  => $chars,
            ]);

            $versionNo = $this->works->nextVersionNo($workId);
            $versionId = $this->works->addVersion($workId, $fileId, $versionNo, (string) $stored['sha256'], $chars, $note === '' ? null : $note);
            $this->works->updateWordCount($workId, $chars);

            $this->audit->log((int) ($user['id'] ?? 0) ?: null, 'work.version.add', 'work', $code, null, [
                'version_no' => $versionNo,
                'version_id' => $versionId,
                'char_count' => $chars,
            ]);

            $this->session->flash('ok', sprintf(
                '已上传版本 %d（%d 字）。要让它成为当前分析依据，需要重跑一次分析。',
                $versionNo,
                $chars
            ));
        } catch (Throwable $e) {
            $this->session->flash('err', '版本上传失败：' . $e->getMessage());
        }

        return $this->redirect($response, $back);
    }

    /** 两个原文版本的差异 */
    public function diff(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $code = (string) ($args['code'] ?? '');
        $work = $this->works->findByCode($code);

        if ($work === null) {
            return $this->render($response, 'admin/errors/404', [
                'nav'  => 'works',
                'user' => (array) $request->getAttribute('user', []),
            ], 404);
        }

        $query    = $request->getQueryParams();
        $versions = $this->works->versionsOf((int) $work['id']);
        $total    = count($versions);

        if ($total < 2) {
            $this->session->flash('warn', '这篇只有一个版本，没有可比的对象。先在版本页上传一版。');

            return $this->redirect($response, '/admin/works/' . $code . '/versions');
        }

        // 默认比最新两版：新的一侧是 to
        $fromNo = max(1, (int) ($query['from'] ?? ($versions[1]['version_no'] ?? 1)));
        $toNo   = max(1, (int) ($query['to'] ?? ($versions[0]['version_no'] ?? 2)));

        $fromRow = $this->works->findVersionByNo((int) $work['id'], $fromNo);
        $toRow   = $this->works->findVersionByNo((int) $work['id'], $toNo);

        if ($fromRow === null || $toRow === null) {
            $this->session->flash('err', '找不到指定的版本');

            return $this->redirect($response, '/admin/works/' . $code . '/versions');
        }

        $fromText = $this->textOfVersion($fromRow);
        $toText   = $this->textOfVersion($toRow);

        $ops   = TextDiff::compare($fromText, $toText);
        $stats = TextDiff::stats($ops);

        // 逐条算行内收窄：视图只负责渲染，不在模板里做字符串运算
        $inline = [];
        foreach ($ops as $i => $op) {
            if ($op['type'] === 'del' && $op['other'] !== null) {
                $inline[$i] = TextDiff::inline($op['text'], (string) $op['other']);
            }
        }

        return $this->render($response, 'admin/works/diff', [
            'nav'      => 'works',
            'user'     => (array) $request->getAttribute('user', []),
            'work'     => $work,
            'versions' => $versions,
            'from'     => $fromRow,
            'to'       => $toRow,
            'ops'      => $ops,
            'inline'   => $inline,
            'stats'    => $stats,
        ]);
    }

    /**
     * 单阶段重跑（可选级联）。
     *
     * 这里**同步跑**，与 analyze 的入队不同：一次重跑只有一到五个阶段、最长几分钟，
     * 而且操作者就等在这个页面上想看结果。队列那条路留给"整篇重跑"。
     */
    public function rerun(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $code     = (string) ($args['code'] ?? '');
        $analysis = $this->analyses->findByCode($code);
        $user     = (array) $request->getAttribute('user', []);
        $back     = '/admin/analyses/' . $code;

        if ($analysis === null) {
            return $this->render($response, 'admin/errors/404', ['nav' => 'works', 'user' => $user], 404);
        }

        $body    = (array) ($request->getParsedBody() ?? []);
        $stage   = (string) ($body['stage'] ?? '');
        $cascade = (string) ($body['cascade'] ?? '') === '1';

        try {
            $result = $this->pipeline->rerunStage((int) $analysis['id'], $stage, $cascade, (int) ($user['id'] ?? 0) ?: null);

            $this->audit->log((int) ($user['id'] ?? 0) ?: null, 'analysis.rerun', 'analysis', $code, null, [
                'plan'       => $result['plan'],
                'stale'      => $result['stale'],
                'cost_micro' => $result['cost_micro'],
            ]);

            $this->session->flash('ok', sprintf(
                '已重跑 %s%s，花费 %.4f 元%s',
                implode(' + ', $result['plan']),
                $cascade ? '（含级联）' : '',
                CostModel::toYuan((int) $result['cost_micro']),
                $result['stale'] === [] ? '' : '；' . implode('、', $result['stale']) . ' 未重跑，已标记过期'
            ));
        } catch (Throwable $e) {
            $this->session->flash('err', '重跑失败：' . $e->getMessage());
        }

        return $this->redirect($response, $back);
    }

    // ------------------------------------------------------------ 批量操作

    /**
     * 作品列表的批量动作（1300 篇运营的主操作面）。
     *
     * 为什么批量走一个统一入口而不是六个动作：六个动作意味着六份「怎么选出这批作品」
     * 的逻辑，而选法恰恰是最容易出错的地方（勾选 vs 按筛选全选）。
     * 这里只解析一次目标集合，再分发到具体动作。
     *
     * 目标集合的两种来源：
     *   · scope=ids    —— 列表里勾选的（受 max_input_vars 与人工选择限制）
     *   · scope=filter —— 服务端按同一套筛选条件重算，**不信前端传来的 id**
     *
     * 每个动作都只调仓储/服务层已有的方法，不在这里写 SQL ——
     * 这样「单篇按钮」和「批量」走的是同一条不变式。
     *
     * 隐藏类动作在入口处**二次鉴权**（visibility.toggle），见方法内注释。
     */
    public function bulk(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user   = (array) $request->getAttribute('user', []);
        $actor  = ((int) ($user['id'] ?? 0)) ?: null;
        $body   = (array) ($request->getParsedBody() ?? []);
        $action = (string) ($body['action'] ?? '');
        $back   = $this->safeBack((string) ($body['back'] ?? '/admin/works'));

        // 二次鉴权：这条路由在权限表里只能映射一个权限码（analysis.run），
        // 但它同时承载隐藏类动作，于是有两个方向的错：
        //   · 有 analysis.run、没有 visibility.toggle 的人能隐藏内容（越权止血）；
        //   · 有 visibility.toggle 的审校（reviewer）用不了批量隐藏（该给的没给）。
        //
        // 这里补的是**第一条**。当前的权限矩阵里 admin 与 editor 两个码都齐，
        // 所以今天没有哪条角色会撞上它 —— 这是一道给「以后改矩阵」留的护栏，
        // 不是修一个正在发生的洞。
        //
        // 第二条**没修**：路由级只认一个码，reviewer 缺 analysis.run，进不来。
        // 要真的放开，得把路由改登记为「analysis.run 或 visibility.toggle」再靠下面
        // 这张表逐动作定 —— 逐动作这一半已经就位（self::ACTION_PERMISSIONS），
        // 但改路由是一致的权限语义变更，得单独评估，不夹带在这次改动里。
        // 审校眼下仍可逐篇隐藏（那条路由走 visibility.toggle，不受这里影响），
        // 只是不能用批量条。
        //
        // 放在解析目标集合之前：不通过就不要再去做一次全表筛选。
        $need = self::ACTION_PERMISSIONS[$action] ?? '';
        if ($need !== '' && !Permission::can((array) ($user['permissions'] ?? []), $need)) {
            return $this->denyPermission($response, $user, $need);
        }

        // 入队一次最多 500（防手滑烧预算），其余动作按 2000 上限
        $limit = in_array($action, ['enqueue'], true) ? 500 : 2000;
        $ids   = $this->bulkTargetIds($body, $limit);

        if ($ids === []) {
            $this->session->flash('warn', '没有符合条件的作品。');

            return $this->redirect($response, $back);
        }

        try {
            $message = match ($action) {
                'enqueue'   => $this->bulkEnqueue($ids, $actor, false),
                // 「重跑（含已发布）」单独一个动作：把 force 做成按钮而不是勾选框，
                // 是为了让「我现在要覆盖已上架的成果」成为一个必须被看见的选择
                'reenqueue' => $this->bulkEnqueue($ids, $actor, true),
                'cancel'    => $this->bulkCancel($ids),
                'hide'      => $this->bulkVisibility($ids, $actor, true),
                'restore'   => $this->bulkVisibility($ids, $actor, false),
                'hide-deck' => $this->bulkAnalysisVisibility($ids, $actor, true),
                'restore-deck' => $this->bulkAnalysisVisibility($ids, $actor, false),
                default     => throw new RuntimeException('未知的批量动作：' . $action),
            };

            $this->session->flash('ok', $message);
        } catch (Throwable $e) {
            $this->session->flash('err', '批量操作失败：' . $e->getMessage());
        }

        return $this->redirect($response, $back);
    }

    /**
     * 批量入口的二次鉴权没过时怎么拒绝。
     *
     * 与 PermissionMiddleware 的表现刻意保持一致：同一个 403 模板、同一句 reason、
     * 同样写一条 auth.denied 审计 —— 越权尝试是最该被事后追问的那类事件，
     * 不能因为「这是控制器内部拦的」就不留痕。
     *
     * @param array<string, mixed> $user
     * @param string               $need 动作要求、当前账号缺少的那个权限码
     */
    private function denyPermission(ResponseInterface $response, array $user, string $need): ResponseInterface
    {
        $reason = '缺少权限：' . $need;

        try {
            $this->audit->log(
                ((int) ($user['id'] ?? 0)) ?: null,
                'auth.denied',
                'route',
                'POST /admin/works/bulk',
                null,
                ['reason' => $reason, 'permission' => $need]
            );
        } catch (Throwable) {
            // 审计写失败不能把 403 变成 500：拒绝本身是安全行为，先拒绝，再谈记录
        }

        return $this->render($response, 'admin/errors/403', [
            'nav'    => 'works',
            'user'   => $user,
            'reason' => $reason,
            'need'   => Permission::LABELS[$need] ?? $need,
        ], 403);
    }

    /**
     * @param list<int> $ids
     */
    private function bulkEnqueue(array $ids, ?int $actor, bool $force): string
    {
        $result = $this->dispatcher->enqueue($ids, $actor, $force);

        $this->audit->log($actor, 'analysis.enqueue', 'analysis.batch', null, null, [
            'requested' => count($ids),
            'queued'    => $result['queued'],
            'skipped'   => $result['skipped'],
            'reasons'   => $result['reasons'],
            'force'     => $force,
        ]);

        $text = sprintf('已入队 %d 篇', $result['queued']);
        if ($result['reasons'] !== []) {
            $parts = [];
            foreach ($result['reasons'] as $reason => $count) {
                $parts[] = $count . ' 篇' . $reason;
            }
            $text .= '；跳过：' . implode('、', $parts);
        }

        return $text . '。';
    }

    /**
     * @param list<int> $ids
     */
    private function bulkCancel(array $ids): string
    {
        $states = $this->jobs->statusByWork($ids);
        $canceled = 0;
        $requested = 0;

        foreach ($states as $state) {
            if (!in_array($state['status'], ['queued', 'running'], true)) {
                continue;
            }
            $outcome = $this->jobs->cancel((int) $state['job_id']);
            if ($outcome === 'canceled') {
                $canceled++;
            } elseif ($outcome === 'requested') {
                $requested++;
            }
        }

        return sprintf('已撤销 %d 条排队任务，%d 条正在跑的将在阶段边界停下。', $canceled, $requested);
    }

    /**
     * @param list<int> $ids
     */
    private function bulkVisibility(array $ids, ?int $actor, bool $hide): string
    {
        $result = $hide
            ? $this->visibility->hide(VisibilityService::WORK, $ids, $actor, '列表批量隐藏')
            : $this->visibility->restore(VisibilityService::WORK, $ids, $actor);

        return sprintf(
            '%s %d 篇作品，%d 个页面缓存已失效。',
            $hide ? '已隐藏' : '已恢复',
            $result['changed'],
            $result['pages']
        );
    }

    /**
     * 改「拆解结果」的可见性：作品壳留着，内容收走。
     *
     * 只对已有当前分析的作品有效 —— 还没发布的没有「拆解结果」可藏。
     *
     * @param list<int> $ids
     */
    private function bulkAnalysisVisibility(array $ids, ?int $actor, bool $hide): string
    {
        $analysisIds = [];
        foreach (array_chunk($ids, 500) as $batch) {
            $in   = implode(',', array_fill(0, count($batch), '?'));
            $stmt = $this->pdo->prepare("SELECT id FROM hq_analysis WHERE work_id IN ({$in}) AND status = 'published'");
            $stmt->execute($batch);
            foreach ($stmt->fetchAll() as $row) {
                $analysisIds[] = (int) $row['id'];
            }
        }

        if ($analysisIds === []) {
            return '这些作品都还没有已发布的拆解结果，没有可收存的内容。';
        }

        $result = $hide
            ? $this->visibility->hide(VisibilityService::ANALYSIS, $analysisIds, $actor, '列表批量收存拆解')
            : $this->visibility->restore(VisibilityService::ANALYSIS, $analysisIds, $actor);

        // 说清公开区到底发生了什么。运营最怕的是「点了隐藏，但列表里还看得见」——
        // 拆解被隐藏后，这篇作品在公开区等同于从未被拆解：列表、期次、统计、
        // sitemap 里都没有它，详情页 404，只被它引用的词条一并退出词库。
        return sprintf(
            '%s %d 份拆解结果，公开区已按「%s」处理，%d 个页面缓存失效%s。',
            $hide ? '已收存' : '已恢复',
            $result['changed'],
            $hide ? '从未拆解过' : '恢复拆解',
            $result['pages'],
            ($result['sitemap'] ?? false) ? '，sitemap 已重写' : ''
        );
    }

    /**
     * 批量操作的目标集合。
     *
     * @param array<string, mixed> $body
     * @return list<int>
     */
    private function bulkTargetIds(array $body, int $limit): array
    {
        if ((string) ($body['scope'] ?? 'ids') === 'filter') {
            return $this->works->idsByFilter([
                'keyword'    => (string) ($body['q'] ?? ''),
                'issue'      => (int) ($body['issue'] ?? 0),
                'stage'      => (string) ($body['stage'] ?? ''),
                'visibility' => (string) ($body['visibility'] ?? ''),
            ], $limit);
        }

        $raw = $body['ids'] ?? [];
        if (!is_array($raw)) {
            return [];
        }

        return array_slice(array_values(array_unique(array_filter(
            array_map('intval', $raw),
            static fn (int $id): bool => $id > 0
        ))), 0, $limit);
    }

    /** 只接受站内路径，挡住开放重定向 */
    private function safeBack(string $back): string
    {
        return str_starts_with($back, '/admin/') ? $back : '/admin/works';
    }

    /**
     * 发布一次分析（成为这篇作品的当前版本）。
     *
     * 五步全在 PublishService 里（状态 → 快照 → 审计 → 索引与缓存 → sitemap）。
     * 控制器只负责「找不到就 404」和把结果翻译成人话。
     */
    public function publish(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $code     = (string) ($args['code'] ?? '');
        $analysis = $this->analyses->findByCode($code);
        $user     = (array) $request->getAttribute('user', []);
        $back     = '/admin/analyses/' . $code;

        if ($analysis === null) {
            return $this->render($response, 'admin/errors/404', ['nav' => 'works', 'user' => $user], 404);
        }

        try {
            $this->publisher->publish(
                (int) $analysis['id'],
                (int) $analysis['work_id'],
                ((int) ($user['id'] ?? 0)) ?: null,
                ['note' => '发布']
            );

            $this->session->flash('ok', '已发布。这篇作品的当前版本切到了这次分析。');
        } catch (Throwable $e) {
            $this->session->flash('err', '发布失败：' . $e->getMessage());
        }

        return $this->redirect($response, $back . '#ops');
    }

    /** 下线当前发布的分析（步骤同样在 PublishService 里） */
    public function unpublish(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $code     = (string) ($args['code'] ?? '');
        $analysis = $this->analyses->findByCode($code);
        $user     = (array) $request->getAttribute('user', []);
        $back     = '/admin/analyses/' . $code;

        if ($analysis === null) {
            return $this->render($response, 'admin/errors/404', ['nav' => 'works', 'user' => $user], 404);
        }

        try {
            $this->publisher->unpublish(
                (int) $analysis['id'],
                (int) $analysis['work_id'],
                ((int) ($user['id'] ?? 0)) ?: null
            );

            $this->session->flash('ok', '已下线。作品回到"已分析"，前台不再展示这份产出。');
        } catch (Throwable $e) {
            $this->session->flash('err', '下线失败：' . $e->getMessage());
        }

        return $this->redirect($response, $back . '#ops');
    }

    /** 索引词归并：把 from 并入 to */
    public function mergeTerms(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $code     = (string) ($args['code'] ?? '');
        $analysis = $this->analyses->findByCode($code);
        $user     = (array) $request->getAttribute('user', []);
        $back     = '/admin/analyses/' . $code;

        if ($analysis === null) {
            return $this->render($response, 'admin/errors/404', ['nav' => 'works', 'user' => $user], 404);
        }

        $body   = (array) ($request->getParsedBody() ?? []);
        $fromId = (int) ($body['from_term_id'] ?? 0);
        $toId   = (int) ($body['to_term_id'] ?? 0);
        $toName = trim((string) ($body['to_term'] ?? ''));

        try {
            // 界面上允许直接写目标词条的名字：归并时人多半知道"并到哪个词"，而不是它的 id
            if ($toId <= 0 && $toName !== '') {
                $target = $this->terms->findByCanonical($toName);
                if ($target === null) {
                    throw new RuntimeException(sprintf('找不到名为「%s」的词条，请从本页已有词条里选一个', $toName));
                }
                $toId = (int) $target['id'];
            }

            if ($fromId <= 0 || $toId <= 0) {
                throw new RuntimeException('请选择要归并的词条与归并目标');
            }

            // 归并之前先存一版：搬走的作品关联没法一键搬回来，快照是唯一的退路
            $this->analyses->snapshot((int) $analysis['id'], (int) ($user['id'] ?? 0) ?: null, sprintf('归并词条 #%d → #%d 前', $fromId, $toId));

            $result = $this->terms->merge($fromId, $toId, (int) ($user['id'] ?? 0) ?: null);
            $this->audit->log((int) ($user['id'] ?? 0) ?: null, 'term.merge', 'index_term', (string) $fromId, null, [
                'to'            => $toId,
                'works_moved'   => $result['works_moved'],
                'works_deduped' => $result['works_deduped'],
                'aliases_moved' => $result['aliases_moved'],
            ]);

            $this->session->flash('ok', sprintf(
                '已归并：%d 篇作品的关联搬到目标词条，%d 篇因两个词都在用而合并成一条，%d 个别名一起搬过去。',
                $result['works_moved'],
                $result['works_deduped'],
                $result['aliases_moved']
            ));
            $this->forgetPublicPages($analysis['work_code'] ?? null, [$fromId, $toId], true);
        } catch (Throwable $e) {
            $this->session->flash('err', '归并失败：' . $e->getMessage());
        }

        return $this->redirect($response, $back . '#terms');
    }

    /** 改一条作品索引词的主/辅与权重 */
    public function updateTerm(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $code     = (string) ($args['code'] ?? '');
        $termId   = (int) ($args['termId'] ?? 0);
        $analysis = $this->analyses->findByCode($code);
        $user     = (array) $request->getAttribute('user', []);
        $back     = '/admin/analyses/' . $code;

        if ($analysis === null) {
            return $this->render($response, 'admin/errors/404', ['nav' => 'works', 'user' => $user], 404);
        }

        $body = (array) ($request->getParsedBody() ?? []);

        try {
            // 快照一律拍在改动之前：这样每一版就是「那次改动之前的样子」，
            // 误删误改都能原样退回去。拍在后面的话，被覆盖掉的旧值就没有任何副本了。
            $this->analyses->snapshot((int) $analysis['id'], (int) ($user['id'] ?? 0) ?: null, sprintf('编辑索引词 #%d 前', $termId));
            $this->terms->updateWorkTerm(
                (int) $analysis['id'],
                $termId,
                (string) ($body['role'] ?? 'sub'),
                (int) ($body['weight'] ?? 50)
            );
            $this->audit->log((int) ($user['id'] ?? 0) ?: null, 'term.update', 'analysis', $code, null, [
                'term_id' => $termId,
                'role'    => (string) ($body['role'] ?? ''),
                'weight'  => (int) ($body['weight'] ?? 0),
            ]);
            $this->session->flash('ok', '索引词已更新。');
            $this->forgetPublicPages($analysis['work_code'] ?? null, [$termId], true);
        } catch (Throwable $e) {
            $this->session->flash('err', '更新失败：' . $e->getMessage());
        }

        return $this->redirect($response, $back . '#term-' . $termId);
    }

    /** 从这一篇里移除一个索引词（不动全局词表） */
    public function deleteTerm(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $code     = (string) ($args['code'] ?? '');
        $termId   = (int) ($args['termId'] ?? 0);
        $analysis = $this->analyses->findByCode($code);
        $user     = (array) $request->getAttribute('user', []);
        $back     = '/admin/analyses/' . $code;

        if ($analysis === null) {
            return $this->render($response, 'admin/errors/404', ['nav' => 'works', 'user' => $user], 404);
        }

        try {
            $this->analyses->snapshot((int) $analysis['id'], (int) ($user['id'] ?? 0) ?: null, sprintf('移除索引词 #%d 前', $termId));
            $this->terms->deleteWorkTerm((int) $analysis['id'], $termId);
            $this->audit->log((int) ($user['id'] ?? 0) ?: null, 'term.delete', 'analysis', $code, null, ['term_id' => $termId]);
            $this->session->flash('ok', '已从这一篇移除该词条（全局词表不变）。');
            $this->forgetPublicPages($analysis['work_code'] ?? null, [$termId], true);
        } catch (Throwable $e) {
            $this->session->flash('err', '移除失败：' . $e->getMessage());
        }

        // 这一行已经不存在了，按 id 跳过去会落在页首，所以退回关键词区
        return $this->redirect($response, $back . '#terms');
    }

    /** 撤销归并（只恢复词条本身，作品关联不回滚） */
    public function unmergeTerm(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $code     = (string) ($args['code'] ?? '');
        $termId   = (int) ($args['termId'] ?? 0);
        $analysis = $this->analyses->findByCode($code);
        $user     = (array) $request->getAttribute('user', []);
        $back     = '/admin/analyses/' . $code;

        if ($analysis === null) {
            return $this->render($response, 'admin/errors/404', ['nav' => 'works', 'user' => $user], 404);
        }

        try {
            $this->terms->unmerge($termId);
            $this->audit->log((int) ($user['id'] ?? 0) ?: null, 'term.unmerge', 'index_term', (string) $termId);
            $this->session->flash('ok', '已撤销归并。注意：已经搬到目标词条的作品关联不会自动搬回来。');
            $this->forgetPublicPages($analysis['work_code'] ?? null, [$termId], true);
        } catch (Throwable $e) {
            $this->session->flash('err', '撤销失败：' . $e->getMessage());
        }

        return $this->redirect($response, $back . '#term-' . $termId);
    }

    /** 编辑一条建议的正文 */
    public function updateSuggestion(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $code     = (string) ($args['code'] ?? '');
        $id       = (int) ($args['id'] ?? 0);
        $analysis = $this->analyses->findByCode($code);
        $user     = (array) $request->getAttribute('user', []);
        $back     = '/admin/analyses/' . $code;

        if ($analysis === null) {
            return $this->render($response, 'admin/errors/404', ['nav' => 'works', 'user' => $user], 404);
        }

        $body = (array) ($request->getParsedBody() ?? []);

        try {
            $this->analyses->snapshot((int) $analysis['id'], (int) ($user['id'] ?? 0) ?: null, sprintf('编辑建议 #%d 前', $id));
            $this->analyses->updateSuggestion($id, (int) $analysis['id'], [
                'title'   => (string) ($body['title'] ?? ''),
                'problem' => (string) ($body['problem'] ?? ''),
                'impact'  => (string) ($body['impact'] ?? ''),
            ]);
            $this->audit->log((int) ($user['id'] ?? 0) ?: null, 'suggestion.update', 'analysis', $code, null, ['id' => $id]);
            $this->session->flash('ok', '建议已更新。');
            $this->forgetPublicPages($analysis['work_code'] ?? null);
        } catch (Throwable $e) {
            $this->session->flash('err', '更新失败：' . $e->getMessage());
        }

        return $this->redirect($response, $back . '#sugg-' . $id);
    }

    /** 编辑一条修改方向 */
    public function updateRevision(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $code     = (string) ($args['code'] ?? '');
        $id       = (int) ($args['id'] ?? 0);
        $analysis = $this->analyses->findByCode($code);
        $user     = (array) $request->getAttribute('user', []);
        $back     = '/admin/analyses/' . $code;

        if ($analysis === null) {
            return $this->render($response, 'admin/errors/404', ['nav' => 'works', 'user' => $user], 404);
        }

        $body = (array) ($request->getParsedBody() ?? []);

        try {
            $this->analyses->snapshot((int) $analysis['id'], (int) ($user['id'] ?? 0) ?: null, sprintf('编辑修改方向 #%d 前', $id));
            $this->analyses->updateRevisionItem((int) $analysis['id'], $id, [
                'conservative'  => (string) ($body['conservative'] ?? ''),
                'radical'       => (string) ($body['radical'] ?? ''),
                'impact_scope'  => (string) ($body['impact_scope'] ?? ''),
                'decision_note' => (string) ($body['decision_note'] ?? ''),
            ]);
            $this->audit->log((int) ($user['id'] ?? 0) ?: null, 'revision.update', 'analysis', $code, null, ['id' => $id]);
            $this->session->flash('ok', '修改方向已更新。');
            $this->forgetPublicPages($analysis['work_code'] ?? null);
        } catch (Throwable $e) {
            $this->session->flash('err', '更新失败：' . $e->getMessage());
        }

        return $this->redirect($response, $back . '#rev-' . $id);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // 四件产出的人工增删改
    //
    // 这一组动作的共同点：**一次 AI 调用都没有**，只写库。所以单次编辑的现金成本
    // 是 0 元，和「单阶段重跑」不是一回事。每次改动都做三件事：
    //   ① 改之前拍一版快照（regret 有药）；② 写审计；③ 失效前台页面与检索索引。
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * 写操作共用的前半段：取分析、取当前用户、算回跳地址。
     *
     * 取不到分析返回 null，调用方渲染 404 —— 抽出来是因为这 18 个动作的前 8 行
     * 完全一样，重复 18 遍之后改一处要改 18 处。
     *
     * @param array<string,string> $args
     * @return array{analysis:array<string,mixed>,user:array<string,mixed>,back:string}|null
     */
    private function editTarget(ServerRequestInterface $request, array $args): ?array
    {
        $code     = (string) ($args['code'] ?? '');
        $analysis = $this->analyses->findByCode($code);

        if ($analysis === null) {
            return null;
        }

        return [
            'analysis' => $analysis,
            'user'     => (array) $request->getAttribute('user', []),
            'back'     => '/admin/analyses/' . $code,
        ];
    }

    /** @param array<string,mixed> $user */
    private function renderMissingAnalysis(ResponseInterface $response, array $user): ResponseInterface
    {
        return $this->render($response, 'admin/errors/404', ['nav' => 'works', 'user' => $user], 404);
    }

    public function updateCard(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $ctx = $this->editTarget($request, $args);
        if ($ctx === null) {
            return $this->renderMissingAnalysis($response, (array) $request->getAttribute('user', []));
        }
        ['analysis' => $analysis, 'user' => $user, 'back' => $back] = $ctx;

        $body = (array) ($request->getParsedBody() ?? []);

        try {
            $this->analyses->snapshot((int) $analysis['id'], (int) ($user['id'] ?? 0) ?: null, '编辑索引卡前');
            $this->analyses->updateIndexCard(
                (int) $analysis['id'],
                (string) ($body['one_liner'] ?? ''),
                (string) ($body['theme_note'] ?? '')
            );
            $this->audit->log((int) ($user['id'] ?? 0) ?: null, 'card.update', 'analysis', (string) $analysis['code']);
            $this->session->flash('ok', '索引卡已更新。');
            $this->forgetPublicPages($analysis['work_code'] ?? null);
        } catch (Throwable $e) {
            $this->session->flash('err', '更新失败：' . $e->getMessage());
        }

        return $this->redirect($response, $back . '#terms');
    }

    public function updateSummary(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $ctx = $this->editTarget($request, $args);
        if ($ctx === null) {
            return $this->renderMissingAnalysis($response, (array) $request->getAttribute('user', []));
        }
        ['analysis' => $analysis, 'user' => $user, 'back' => $back] = $ctx;

        $body = (array) ($request->getParsedBody() ?? []);

        try {
            $this->analyses->snapshot((int) $analysis['id'], (int) ($user['id'] ?? 0) ?: null, '编辑剧情三句话前');
            $this->analyses->updatePlotSummary((int) $analysis['id'], [
                'who'     => (string) ($body['who'] ?? ''),
                'want'    => (string) ($body['want'] ?? ''),
                'cost'    => (string) ($body['cost'] ?? ''),
                'logline' => (string) ($body['logline'] ?? ''),
            ]);
            $this->audit->log((int) ($user['id'] ?? 0) ?: null, 'plot.summary.update', 'analysis', (string) $analysis['code']);
            $this->session->flash('ok', '剧情三句话已更新。');
            $this->forgetPublicPages($analysis['work_code'] ?? null);
        } catch (Throwable $e) {
            $this->session->flash('err', '更新失败：' . $e->getMessage());
        }

        return $this->redirect($response, $back . '#plot');
    }

    public function createBeat(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $ctx = $this->editTarget($request, $args);
        if ($ctx === null) {
            return $this->renderMissingAnalysis($response, (array) $request->getAttribute('user', []));
        }
        ['analysis' => $analysis, 'user' => $user, 'back' => $back] = $ctx;

        $body = (array) ($request->getParsedBody() ?? []);

        try {
            $this->analyses->snapshot((int) $analysis['id'], (int) ($user['id'] ?? 0) ?: null, '新增剧情节点前');
            $id = $this->analyses->createBeat((int) $analysis['id'], [
                'phase'        => (string) ($body['phase'] ?? 'turn'),
                'title'        => (string) ($body['title'] ?? ''),
                'detail'       => (string) ($body['detail'] ?? ''),
                'quote'        => (string) ($body['quote'] ?? ''),
                'position_pct' => (int) ($body['position_pct'] ?? 0),
            ]);
            $this->audit->log((int) ($user['id'] ?? 0) ?: null, 'beat.create', 'analysis', (string) $analysis['code'], null, ['id' => $id]);
            $this->session->flash('ok', '节点已添加，排在节点轴末尾。');
            $this->forgetPublicPages($analysis['work_code'] ?? null);
        } catch (Throwable $e) {
            $this->session->flash('err', '添加失败：' . $e->getMessage());
        }

        return $this->redirect($response, $back . '#plot');
    }

    public function updateBeat(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $ctx = $this->editTarget($request, $args);
        if ($ctx === null) {
            return $this->renderMissingAnalysis($response, (array) $request->getAttribute('user', []));
        }
        ['analysis' => $analysis, 'user' => $user, 'back' => $back] = $ctx;

        $beatId = (int) ($args['id'] ?? 0);
        $body   = (array) ($request->getParsedBody() ?? []);

        try {
            $this->analyses->snapshot((int) $analysis['id'], (int) ($user['id'] ?? 0) ?: null, sprintf('编辑剧情节点 #%d 前', $beatId));
            $this->analyses->updateBeat((int) $analysis['id'], $beatId, [
                'phase'        => (string) ($body['phase'] ?? 'turn'),
                'title'        => (string) ($body['title'] ?? ''),
                'detail'       => (string) ($body['detail'] ?? ''),
                'quote'        => (string) ($body['quote'] ?? ''),
                'position_pct' => (int) ($body['position_pct'] ?? 0),
            ]);
            $this->audit->log((int) ($user['id'] ?? 0) ?: null, 'beat.update', 'analysis', (string) $analysis['code'], null, ['id' => $beatId]);
            $this->session->flash('ok', '节点已更新。');
            $this->forgetPublicPages($analysis['work_code'] ?? null);
        } catch (Throwable $e) {
            $this->session->flash('err', '更新失败：' . $e->getMessage());
        }

        return $this->redirect($response, $back . '#beat-' . $beatId);
    }

    public function deleteBeat(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $ctx = $this->editTarget($request, $args);
        if ($ctx === null) {
            return $this->renderMissingAnalysis($response, (array) $request->getAttribute('user', []));
        }
        ['analysis' => $analysis, 'user' => $user, 'back' => $back] = $ctx;

        $beatId = (int) ($args['id'] ?? 0);

        try {
            $this->analyses->snapshot((int) $analysis['id'], (int) ($user['id'] ?? 0) ?: null, sprintf('删除剧情节点 #%d 前', $beatId));
            $this->analyses->deleteBeat((int) $analysis['id'], $beatId);
            $this->audit->log((int) ($user['id'] ?? 0) ?: null, 'beat.delete', 'analysis', (string) $analysis['code'], ['id' => $beatId], null);
            $this->session->flash('ok', '节点已删除；指向它的伏笔两端已清空，节点序号已重排。');
            $this->forgetPublicPages($analysis['work_code'] ?? null);
        } catch (Throwable $e) {
            $this->session->flash('err', '删除失败：' . $e->getMessage());
        }

        // 这一行已经没了，按 id 跳过去会落在页首
        return $this->redirect($response, $back . '#plot');
    }

    public function createForeshadow(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $ctx = $this->editTarget($request, $args);
        if ($ctx === null) {
            return $this->renderMissingAnalysis($response, (array) $request->getAttribute('user', []));
        }
        ['analysis' => $analysis, 'user' => $user, 'back' => $back] = $ctx;

        $body = (array) ($request->getParsedBody() ?? []);

        try {
            $this->analyses->snapshot((int) $analysis['id'], (int) ($user['id'] ?? 0) ?: null, '新增伏笔前');
            $id = $this->analyses->createForeshadow((int) $analysis['id'], [
                'content'         => (string) ($body['content'] ?? ''),
                'setup_beat_id'   => $body['setup_beat_id'] ?? null,
                'payoff_beat_id'  => $body['payoff_beat_id'] ?? null,
            ]);
            $this->audit->log((int) ($user['id'] ?? 0) ?: null, 'foreshadow.create', 'analysis', (string) $analysis['code'], null, ['id' => $id]);
            $this->session->flash('ok', '伏笔已添加。');
            $this->forgetPublicPages($analysis['work_code'] ?? null);
        } catch (Throwable $e) {
            $this->session->flash('err', '添加失败：' . $e->getMessage());
        }

        return $this->redirect($response, $back . '#plot');
    }

    public function updateForeshadow(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $ctx = $this->editTarget($request, $args);
        if ($ctx === null) {
            return $this->renderMissingAnalysis($response, (array) $request->getAttribute('user', []));
        }
        ['analysis' => $analysis, 'user' => $user, 'back' => $back] = $ctx;

        $id   = (int) ($args['id'] ?? 0);
        $body = (array) ($request->getParsedBody() ?? []);

        try {
            $this->analyses->snapshot((int) $analysis['id'], (int) ($user['id'] ?? 0) ?: null, sprintf('编辑伏笔 #%d 前', $id));
            $this->analyses->updateForeshadow((int) $analysis['id'], $id, [
                'content'        => (string) ($body['content'] ?? ''),
                'setup_beat_id'  => $body['setup_beat_id'] ?? null,
                'payoff_beat_id' => $body['payoff_beat_id'] ?? null,
            ]);
            $this->audit->log((int) ($user['id'] ?? 0) ?: null, 'foreshadow.update', 'analysis', (string) $analysis['code'], null, ['id' => $id]);
            $this->session->flash('ok', '伏笔已更新。');
            $this->forgetPublicPages($analysis['work_code'] ?? null);
        } catch (Throwable $e) {
            $this->session->flash('err', '更新失败：' . $e->getMessage());
        }

        return $this->redirect($response, $back . '#plot');
    }

    public function deleteForeshadow(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $ctx = $this->editTarget($request, $args);
        if ($ctx === null) {
            return $this->renderMissingAnalysis($response, (array) $request->getAttribute('user', []));
        }
        ['analysis' => $analysis, 'user' => $user, 'back' => $back] = $ctx;

        $id = (int) ($args['id'] ?? 0);

        try {
            $this->analyses->snapshot((int) $analysis['id'], (int) ($user['id'] ?? 0) ?: null, sprintf('删除伏笔 #%d 前', $id));
            $this->analyses->deleteForeshadow((int) $analysis['id'], $id);
            $this->audit->log((int) ($user['id'] ?? 0) ?: null, 'foreshadow.delete', 'analysis', (string) $analysis['code'], ['id' => $id], null);
            $this->session->flash('ok', '伏笔已删除。');
            $this->forgetPublicPages($analysis['work_code'] ?? null);
        } catch (Throwable $e) {
            $this->session->flash('err', '删除失败：' . $e->getMessage());
        }

        return $this->redirect($response, $back . '#plot');
    }

    public function createSuggestion(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $ctx = $this->editTarget($request, $args);
        if ($ctx === null) {
            return $this->renderMissingAnalysis($response, (array) $request->getAttribute('user', []));
        }
        ['analysis' => $analysis, 'user' => $user, 'back' => $back] = $ctx;

        $body = (array) ($request->getParsedBody() ?? []);

        try {
            $this->analyses->snapshot((int) $analysis['id'], (int) ($user['id'] ?? 0) ?: null, '新增建议前');
            $id = $this->analyses->createSuggestion((int) $analysis['id'], [
                'layer'   => (string) ($body['layer'] ?? 'execution'),
                'title'   => (string) ($body['title'] ?? ''),
                'problem' => (string) ($body['problem'] ?? ''),
                'impact'  => (string) ($body['impact'] ?? ''),
            ]);
            $this->audit->log((int) ($user['id'] ?? 0) ?: null, 'suggestion.create', 'analysis', (string) $analysis['code'], null, ['id' => $id]);
            $this->session->flash('ok', '建议已添加，可以接着给它补路径。');
            $this->forgetPublicPages($analysis['work_code'] ?? null);
        } catch (Throwable $e) {
            $this->session->flash('err', '添加失败：' . $e->getMessage());
        }

        return $this->redirect($response, $back . '#advice');
    }

    public function deleteSuggestion(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $ctx = $this->editTarget($request, $args);
        if ($ctx === null) {
            return $this->renderMissingAnalysis($response, (array) $request->getAttribute('user', []));
        }
        ['analysis' => $analysis, 'user' => $user, 'back' => $back] = $ctx;

        $id = (int) ($args['id'] ?? 0);

        try {
            $this->analyses->snapshot((int) $analysis['id'], (int) ($user['id'] ?? 0) ?: null, sprintf('删除建议 #%d 前', $id));
            $this->analyses->deleteSuggestion((int) $analysis['id'], $id);
            $this->audit->log((int) ($user['id'] ?? 0) ?: null, 'suggestion.delete', 'analysis', (string) $analysis['code'], ['id' => $id], null);
            $this->session->flash('ok', '建议已删除（它的路径一并清掉了）。');
            $this->forgetPublicPages($analysis['work_code'] ?? null);
        } catch (Throwable $e) {
            $this->session->flash('err', '删除失败：' . $e->getMessage());
        }

        return $this->redirect($response, $back . '#advice');
    }

    public function createSuggestionPath(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $ctx = $this->editTarget($request, $args);
        if ($ctx === null) {
            return $this->renderMissingAnalysis($response, (array) $request->getAttribute('user', []));
        }
        ['analysis' => $analysis, 'user' => $user, 'back' => $back] = $ctx;

        $suggestionId = (int) ($args['id'] ?? 0);
        $body         = (array) ($request->getParsedBody() ?? []);

        try {
            $this->analyses->snapshot((int) $analysis['id'], (int) ($user['id'] ?? 0) ?: null, sprintf('新增建议路径（#%d）前', $suggestionId));
            $this->analyses->createSuggestionPath((int) $analysis['id'], $suggestionId, [
                'style'   => (string) ($body['style'] ?? 'neutral'),
                'content' => (string) ($body['content'] ?? ''),
            ]);
            $this->audit->log((int) ($user['id'] ?? 0) ?: null, 'suggestion.path.create', 'analysis', (string) $analysis['code'], null, ['suggestion_id' => $suggestionId]);
            $this->session->flash('ok', '路径已添加。');
            $this->forgetPublicPages($analysis['work_code'] ?? null);
        } catch (Throwable $e) {
            $this->session->flash('err', '添加失败：' . $e->getMessage());
        }

        return $this->redirect($response, $back . '#sugg-' . $suggestionId);
    }

    public function updateSuggestionPath(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $ctx = $this->editTarget($request, $args);
        if ($ctx === null) {
            return $this->renderMissingAnalysis($response, (array) $request->getAttribute('user', []));
        }
        ['analysis' => $analysis, 'user' => $user, 'back' => $back] = $ctx;

        $pathId = (int) ($args['id'] ?? 0);
        $body   = (array) ($request->getParsedBody() ?? []);

        try {
            $this->analyses->snapshot((int) $analysis['id'], (int) ($user['id'] ?? 0) ?: null, sprintf('编辑建议路径 #%d 前', $pathId));
            $this->analyses->updateSuggestionPath((int) $analysis['id'], $pathId, [
                'style'   => (string) ($body['style'] ?? 'neutral'),
                'content' => (string) ($body['content'] ?? ''),
            ]);
            $this->audit->log((int) ($user['id'] ?? 0) ?: null, 'suggestion.path.update', 'analysis', (string) $analysis['code'], null, ['id' => $pathId]);
            $this->session->flash('ok', '路径已更新。');
            $this->forgetPublicPages($analysis['work_code'] ?? null);
        } catch (Throwable $e) {
            $this->session->flash('err', '更新失败：' . $e->getMessage());
        }

        return $this->redirect($response, $back . '#advice');
    }

    public function deleteSuggestionPath(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $ctx = $this->editTarget($request, $args);
        if ($ctx === null) {
            return $this->renderMissingAnalysis($response, (array) $request->getAttribute('user', []));
        }
        ['analysis' => $analysis, 'user' => $user, 'back' => $back] = $ctx;

        $pathId = (int) ($args['id'] ?? 0);

        try {
            $this->analyses->snapshot((int) $analysis['id'], (int) ($user['id'] ?? 0) ?: null, sprintf('删除建议路径 #%d 前', $pathId));
            $this->analyses->deleteSuggestionPath((int) $analysis['id'], $pathId);
            $this->audit->log((int) ($user['id'] ?? 0) ?: null, 'suggestion.path.delete', 'analysis', (string) $analysis['code'], ['id' => $pathId], null);
            $this->session->flash('ok', '路径已删除。');
            $this->forgetPublicPages($analysis['work_code'] ?? null);
        } catch (Throwable $e) {
            $this->session->flash('err', '删除失败：' . $e->getMessage());
        }

        return $this->redirect($response, $back . '#advice');
    }

    public function createRevisionItem(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $ctx = $this->editTarget($request, $args);
        if ($ctx === null) {
            return $this->renderMissingAnalysis($response, (array) $request->getAttribute('user', []));
        }
        ['analysis' => $analysis, 'user' => $user, 'back' => $back] = $ctx;

        $body = (array) ($request->getParsedBody() ?? []);

        try {
            $this->analyses->snapshot((int) $analysis['id'], (int) ($user['id'] ?? 0) ?: null, '新增修改方向前');
            $id = $this->analyses->createRevisionItem((int) $analysis['id'], [
                'target'        => (string) ($body['target'] ?? ''),
                'action'        => (string) ($body['action'] ?? ''),
                'conservative'  => (string) ($body['conservative'] ?? ''),
                'radical'       => (string) ($body['radical'] ?? ''),
                'impact_scope'  => (string) ($body['impact_scope'] ?? ''),
                'decision_note' => (string) ($body['decision_note'] ?? ''),
            ]);
            $this->audit->log((int) ($user['id'] ?? 0) ?: null, 'revision.create', 'analysis', (string) $analysis['code'], null, ['id' => $id]);
            $this->session->flash('ok', '修改方向已添加，排在清单末位。');
            $this->forgetPublicPages($analysis['work_code'] ?? null);
        } catch (Throwable $e) {
            $this->session->flash('err', '添加失败：' . $e->getMessage());
        }

        return $this->redirect($response, $back . '#revision');
    }

    public function deleteRevisionItem(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $ctx = $this->editTarget($request, $args);
        if ($ctx === null) {
            return $this->renderMissingAnalysis($response, (array) $request->getAttribute('user', []));
        }
        ['analysis' => $analysis, 'user' => $user, 'back' => $back] = $ctx;

        $id = (int) ($args['id'] ?? 0);

        try {
            $this->analyses->snapshot((int) $analysis['id'], (int) ($user['id'] ?? 0) ?: null, sprintf('删除修改方向 #%d 前', $id));
            $this->analyses->deleteRevisionItem((int) $analysis['id'], $id);
            $this->audit->log((int) ($user['id'] ?? 0) ?: null, 'revision.delete', 'analysis', (string) $analysis['code'], ['id' => $id], null);
            $this->session->flash('ok', '修改方向已删除，后面的名次已前移。');
            $this->forgetPublicPages($analysis['work_code'] ?? null);
        } catch (Throwable $e) {
            $this->session->flash('err', '删除失败：' . $e->getMessage());
        }

        return $this->redirect($response, $back . '#revision');
    }

    public function updateReader(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $ctx = $this->editTarget($request, $args);
        if ($ctx === null) {
            return $this->renderMissingAnalysis($response, (array) $request->getAttribute('user', []));
        }
        ['analysis' => $analysis, 'user' => $user, 'back' => $back] = $ctx;

        $body = (array) ($request->getParsedBody() ?? []);

        // 看点按三条固定输入：前端用三条而不是动态行，是因为这一块永远是三条，
        // 动态列表会让「第几条空了」变得难以判断。
        $appeal = [];
        for ($i = 0; $i < 3; $i++) {
            $appeal[] = [
                'title' => (string) ($body['appeal_title'][$i] ?? ''),
                'why'   => (string) ($body['appeal_why'][$i] ?? ''),
            ];
        }

        try {
            $this->analyses->snapshot((int) $analysis['id'], (int) ($user['id'] ?? 0) ?: null, '编辑读者视角前');
            $this->analyses->updateReaderBrief((int) $analysis['id'], [
                'hook'   => (string) ($body['hook'] ?? ''),
                'appeal' => $appeal,
                'visual' => [
                    'tone'      => (string) ($body['visual_tone'] ?? ''),
                    'lens'      => (string) ($body['visual_lens'] ?? ''),
                    'reference' => (string) ($body['visual_ref'] ?? ''),
                    'scene'     => (string) ($body['visual_scene'] ?? ''),
                ],
                'audience' => [
                    'primary' => (string) ($body['audience_primary'] ?? ''),
                    'also'    => (string) ($body['audience_also'] ?? ''),
                    'avoid'   => (string) ($body['audience_avoid'] ?? ''),
                    'gate'    => (string) ($body['audience_gate'] ?? ''),
                ],
                'terms_note' => (string) ($body['terms_note'] ?? ''),
            ]);
            $this->audit->log((int) ($user['id'] ?? 0) ?: null, 'reader.update', 'analysis', (string) $analysis['code']);
            $this->session->flash('ok', '读者视角已更新。');
            $this->forgetPublicPages($analysis['work_code'] ?? null);
        } catch (Throwable $e) {
            $this->session->flash('err', '更新失败：' . $e->getMessage());
        }

        return $this->redirect($response, $back . '#reader');
    }

    /**
     * 给这篇补一个索引词。
     *
     * 只能从已有词表里选：造词会让词库长出一堆只出现过一次的私有说法，
     * 之后没人敢删。要加新词去「词条治理」，那是另一个入口。
     */
    public function addTerm(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $ctx = $this->editTarget($request, $args);
        if ($ctx === null) {
            return $this->renderMissingAnalysis($response, (array) $request->getAttribute('user', []));
        }
        ['analysis' => $analysis, 'user' => $user, 'back' => $back] = $ctx;

        $body   = (array) ($request->getParsedBody() ?? []);
        $termId = (int) ($body['term_id'] ?? 0);
        $name   = trim((string) ($body['term'] ?? ''));

        try {
            // 允许直接写词名：界面上一行输入比一个几百项的下拉框好用
            if ($termId <= 0 && $name !== '') {
                $found = $this->terms->findByCanonical($name);
                if ($found === null) {
                    throw new RuntimeException(sprintf('词表里没有「%s」。要新造词，请去「词条治理」。', $name));
                }
                $termId = (int) $found['id'];
            }
            if ($termId <= 0) {
                throw new RuntimeException('请选择要加入的词条');
            }

            $this->analyses->snapshot((int) $analysis['id'], (int) ($user['id'] ?? 0) ?: null, sprintf('添加索引词 #%d 前', $termId));
            $this->terms->addWorkTerm(
                (int) $analysis['id'],
                $termId,
                (string) ($body['role'] ?? 'sub'),
                (int) ($body['weight'] ?? 50),
                (string) ($body['evidence'] ?? '')
            );
            $this->audit->log((int) ($user['id'] ?? 0) ?: null, 'term.create', 'analysis', (string) $analysis['code'], null, ['term_id' => $termId]);
            $this->session->flash('ok', '已加入这篇作品的词条。');
            $this->forgetPublicPages($analysis['work_code'] ?? null, [$termId], true);
        } catch (Throwable $e) {
            $this->session->flash('err', '加入失败：' . $e->getMessage());
        }

        return $this->redirect($response, $back . '#terms');
    }

    /**
     * 把四件产出回滚到某一版快照。
     *
     * 回滚本身也不花钱：它读的是 hq_analysis_revision 里已经存好的 JSON，
     * 重建过程不经过模型。回滚前会再拍一版「当前状态」，所以回滚也能反悔。
     */
    public function restore(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $ctx = $this->editTarget($request, $args);
        if ($ctx === null) {
            return $this->renderMissingAnalysis($response, (array) $request->getAttribute('user', []));
        }
        ['analysis' => $analysis, 'user' => $user, 'back' => $back] = $ctx;

        $version = (int) ($args['version'] ?? 0);
        $workId  = (int) ($analysis['work_id'] ?? 0);

        try {
            // 先把「现在的样子」存一版，回滚才可逆
            $this->analyses->snapshot((int) $analysis['id'], (int) ($user['id'] ?? 0) ?: null, sprintf('回滚到 v%d 前', $version));

            $stat = $this->analyses->restoreSnapshot((int) $analysis['id'], $workId, $version);

            $this->audit->log((int) ($user['id'] ?? 0) ?: null, 'artifacts.restore', 'analysis', (string) $analysis['code'], null, [
                'version' => $version,
            ] + $stat);

            $msg = sprintf(
                '已回滚到 v%d：%d 个节点、%d 条伏笔、%d 条建议、%d 条修改方向、%d 个索引词。',
                $version,
                $stat['beats'],
                $stat['foreshadows'],
                $stat['suggestions'],
                $stat['revision_items'],
                $stat['terms']
            );
            if ($stat['skipped_terms'] !== []) {
                $msg .= sprintf(
                    ' 有 %d 个词条已不在词表里（多半被归并或删了），跳过：%s',
                    count($stat['skipped_terms']),
                    implode('、', array_slice($stat['skipped_terms'], 0, 6))
                );
            }

            $this->session->flash('ok', $msg);
            $this->forgetPublicPages($analysis['work_code'] ?? null, [], true);
        } catch (Throwable $e) {
            $this->session->flash('err', '回滚失败：' . $e->getMessage());
        }

        return $this->redirect($response, $back . '#snapshot');
    }

    /**
     * 读某个版本的文件正文（diff 用）。
     *
     * 解不出来时返回空串而不是抛异常：diff 页能显示「这一版读不出来」比整个页面 500 有用。
     *
     * @param array<string, mixed> $version
     */
    private function textOfVersion(array $version): string
    {
        $file = $this->works->findFile((int) $version['file_id']);
        if ($file === null) {
            return '';
        }

        $ext = strtolower((string) pathinfo((string) $file['orig_name'], PATHINFO_EXTENSION));

        try {
            return $this->extractor->extract($this->store->absolutePath((string) $file['storage_key']), $ext)['text'];
        } catch (Throwable) {
            return '';
        }
    }

    /**
     * 读原文前 1200 字做预览。
     *
     * ⚠️ 不能直接 echo 文件字节：GBK / UTF-16 的原文按原样输出到 UTF-8 页面就是乱码。
     * 必须走 TextExtractor 做一次编码归一（docx 也顺带解析出正文）。
     */
    private function preview(array $work): ?string
    {
        $files = $this->works->filesOf((int) $work['id']);
        if ($files === []) {
            return null;
        }

        $file = $files[0];
        $ext  = strtolower((string) pathinfo((string) $file['orig_name'], PATHINFO_EXTENSION));

        try {
            $parsed = $this->extractor->extract($this->store->absolutePath((string) $file['storage_key']), $ext);
        } catch (Throwable) {
            return null;
        }

        $body = mb_substr($parsed['text'], 0, 1200);

        return $body === '' ? null : $body;
    }
}
