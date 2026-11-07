<?php

declare(strict_types=1);

use App\Http\Controller\Admin\AccountController;
use App\Http\Controller\Admin\AuditController;
use App\Http\Controller\Admin\AuthController;
use App\Http\Controller\Admin\DashboardController;
use App\Http\Controller\Admin\HiddenController;
use App\Http\Controller\Admin\OneClickController;
use App\Http\Controller\Admin\ProgressController;
use App\Http\Controller\Admin\QueueController;
use App\Http\Controller\Admin\SourceController;
use App\Http\Controller\Admin\TermAdminController;
use App\Http\Controller\Admin\WorkController;
use App\Http\Controller\HealthController;
use App\Http\Controller\Library\AuthorsController;
use App\Http\Controller\Library\MethodController;
use App\Http\Controller\Library\PracticeController;
use App\Http\Controller\Library\SearchController;
use App\Http\Controller\Library\SitemapController;
use App\Http\Controller\Library\TermsController;
use App\Http\Controller\Library\WorksController;
use App\Http\Middleware\AuthMiddleware;
use App\Http\Middleware\PermissionMiddleware;
use App\Http\Middleware\PublicCacheMiddleware;
use App\Http\Middleware\PublicRateLimitMiddleware;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

/**
 * 路由表。约定：
 *   · 一个路径只注册一次，不带尾斜杠的版本为准（/admin 与 /admin/ 都指向同一动作）；
 *   · 所有写操作只走 POST，成功后 303 重定向（防刷新重复提交）；
 *   · /admin/login 在认证组之外，其余 /admin/* 全部套 AuthMiddleware + PermissionMiddleware；
 *   · **每条路由都必须 setName**：权限表按路由名查，没有名字就等于没有权限声明，
 *     PermissionMiddleware 会直接 403（默认拒绝）。bin/check-permissions.php 会核对遗漏。
 */
return static function (App $app): void {
    $app->get('/healthz', HealthController::class . ':index')->setName('healthz');
    // 注意：这里**不能写 static 闭包** —— Slim 的 CallableResolver 会把闭包 bindTo 容器，
    // 而 PHP 不允许给 static 闭包绑定 $this，结果是「Cannot bind an instance to a static
    // closure」+ 500。库根给公众看，落到 /library；后台自己走 /admin/login。
    $app->get('/', fn ($request, $response) => $response->withStatus(302)->withHeader('Location', '/library'))->setName('home');
    $app->get('/admin/', fn ($request, $response) => $response->withStatus(302)->withHeader('Location', '/admin'))->setName('admin.slash');

    // 登录（无需认证）
    $app->get('/admin/login', AuthController::class . ':showLogin')->setName('admin.login');
    $app->post('/admin/login', AuthController::class . ':login')->setName('admin.login.post');

    $code = '[0-9A-HJKMNP-TV-Z]{26}';

    // 认证 + 授权区。
    // ⚠️ 顺序：add() 是 LIFO，最后加的先跑。权限中间件要读 AuthMiddleware 挂上去的 user，
    //    所以 Permission 先 add、Auth 后 add —— 反过来就是「403 页面上没有当前用户」。
    $app->group('/admin', function (RouteCollectorProxy $group) use ($code): void {
        $group->post('/logout', AuthController::class . ':logout')->setName('admin.logout');

        $group->get('', DashboardController::class . ':index')->setName('admin.dashboard');

        // ---- 作品 ----
        $group->get('/works', WorkController::class . ':index')->setName('admin.works.index');
        $group->get('/works/new', WorkController::class . ':create')->setName('admin.works.create');
        $group->post('/works', WorkController::class . ':store')->setName('admin.works.store');
        $group->get('/works/import', WorkController::class . ':importForm')->setName('admin.works.import');
        $group->post('/works/import', WorkController::class . ':importBatch')->setName('admin.works.import.post');

        // 批量动作（1300 篇的主操作面）。必须注册在 /works/{code} 之前：
        // {code} 是 26 位 ULID 正则，理论上不会匹配 "bulk"，但路由匹配是顺序的，
        // 写在前面的固定路径永远更明确。
        $group->post('/works/bulk', WorkController::class . ':bulk')->setName('admin.works.bulk');

        $group->get('/works/{code:' . $code . '}', WorkController::class . ':show')->setName('admin.works.show');

        // 版本与差异（M4）
        $group->get('/works/{code:' . $code . '}/versions', WorkController::class . ':versions')->setName('admin.works.versions');
        $group->post('/works/{code:' . $code . '}/versions', WorkController::class . ':storeVersion')->setName('admin.works.version.post');
        $group->get('/works/{code:' . $code . '}/diff', WorkController::class . ':diff')->setName('admin.works.diff');
        $group->post('/works/{code:' . $code . '}/delete', WorkController::class . ':delete')->setName('admin.works.delete');

        // ---- 分析 ----
        $group->post('/works/{code:' . $code . '}/analyze', WorkController::class . ':analyze')->setName('admin.works.analyze');
        $group->get('/analyses/{code:' . $code . '}', WorkController::class . ':analysis')->setName('admin.analyses.show');

        // 重分析：单阶段重跑 + 级联（M4）
        $group->post('/analyses/{code:' . $code . '}/rerun', WorkController::class . ':rerun')->setName('admin.analyses.rerun');

        // 发布 / 下线（M4）
        $group->post('/analyses/{code:' . $code . '}/publish', WorkController::class . ':publish')->setName('admin.analyses.publish');
        $group->post('/analyses/{code:' . $code . '}/unpublish', WorkController::class . ':unpublish')->setName('admin.analyses.unpublish');

        // ---- 四件产出的编辑 ----
        $group->post('/analyses/{code:' . $code . '}/terms/merge', WorkController::class . ':mergeTerms')->setName('admin.terms.merge');
        $group->post('/analyses/{code:' . $code . '}/terms/{termId:[0-9]+}', WorkController::class . ':updateTerm')->setName('admin.terms.update');
        $group->post('/analyses/{code:' . $code . '}/terms/{termId:[0-9]+}/delete', WorkController::class . ':deleteTerm')->setName('admin.terms.delete');
        $group->post('/analyses/{code:' . $code . '}/terms/{termId:[0-9]+}/unmerge', WorkController::class . ':unmergeTerm')->setName('admin.terms.unmerge');
        $group->post('/analyses/{code:' . $code . '}/suggestions/{id:[0-9]+}', WorkController::class . ':updateSuggestion')->setName('admin.suggestions.update');
        $group->post('/analyses/{code:' . $code . '}/revisions/{id:[0-9]+}', WorkController::class . ':updateRevision')->setName('admin.revisions.update');

        // 人工增删改四件产出。这一组**一次 AI 调用都没有**，只写库，所以是零现金成本；
        // 与下面 /rerun 是两条完全不同的路（那条要再付一次模型钱）。
        // 删改类动作统一 303 回列表锚点，不跳回被删掉的那一行。
        $group->post('/analyses/{code:' . $code . '}/card', WorkController::class . ':updateCard')->setName('admin.card.update');
        $group->post('/analyses/{code:' . $code . '}/summary', WorkController::class . ':updateSummary')->setName('admin.summary.update');

        $group->post('/analyses/{code:' . $code . '}/beats', WorkController::class . ':createBeat')->setName('admin.beats.create');
        $group->post('/analyses/{code:' . $code . '}/beats/{id:[0-9]+}', WorkController::class . ':updateBeat')->setName('admin.beats.update');
        $group->post('/analyses/{code:' . $code . '}/beats/{id:[0-9]+}/delete', WorkController::class . ':deleteBeat')->setName('admin.beats.delete');

        $group->post('/analyses/{code:' . $code . '}/foreshadows', WorkController::class . ':createForeshadow')->setName('admin.foreshadows.create');
        $group->post('/analyses/{code:' . $code . '}/foreshadows/{id:[0-9]+}', WorkController::class . ':updateForeshadow')->setName('admin.foreshadows.update');
        $group->post('/analyses/{code:' . $code . '}/foreshadows/{id:[0-9]+}/delete', WorkController::class . ':deleteForeshadow')->setName('admin.foreshadows.delete');

        $group->post('/analyses/{code:' . $code . '}/suggestions', WorkController::class . ':createSuggestion')->setName('admin.suggestions.create');
        $group->post('/analyses/{code:' . $code . '}/suggestions/{id:[0-9]+}/delete', WorkController::class . ':deleteSuggestion')->setName('admin.suggestions.delete');
        $group->post('/analyses/{code:' . $code . '}/suggestions/{id:[0-9]+}/paths', WorkController::class . ':createSuggestionPath')->setName('admin.paths.create');
        $group->post('/analyses/{code:' . $code . '}/paths/{id:[0-9]+}', WorkController::class . ':updateSuggestionPath')->setName('admin.paths.update');
        $group->post('/analyses/{code:' . $code . '}/paths/{id:[0-9]+}/delete', WorkController::class . ':deleteSuggestionPath')->setName('admin.paths.delete');

        $group->post('/analyses/{code:' . $code . '}/revisions', WorkController::class . ':createRevisionItem')->setName('admin.revisions.create');
        $group->post('/analyses/{code:' . $code . '}/revisions/{id:[0-9]+}/delete', WorkController::class . ':deleteRevisionItem')->setName('admin.revisions.delete');

        $group->post('/analyses/{code:' . $code . '}/reader', WorkController::class . ':updateReader')->setName('admin.reader.update');
        $group->post('/analyses/{code:' . $code . '}/terms', WorkController::class . ':addTerm')->setName('admin.terms.create');

        // 回滚：读 hq_analysis_revision 里已存的 JSON 重建，不经过模型，同样零成本。
        // 回滚前会再拍一版当前状态，所以回滚本身也能反悔。
        $group->post('/analyses/{code:' . $code . '}/snapshots/{version:[0-9]+}/restore', WorkController::class . ':restore')->setName('admin.snapshots.restore');

        // ---- 一键拆解（整条流水线：抓正文 → 入馆 → 拆解 → 可选上架）----
        // 与调度台的分工：这里是「接下来拆哪些」，调度台是「现在怎么样」。
        $group->get('/oneclick', OneClickController::class . ':index')->setName('admin.oneclick');
        $group->post('/oneclick/launch', OneClickController::class . ':launch')->setName('admin.oneclick.launch');
        $group->post('/oneclick/publish', OneClickController::class . ':publish')->setName('admin.oneclick.publish');
        $group->post('/oneclick/cancel', OneClickController::class . ':cancel')->setName('admin.oneclick.cancel');

        // ---- 拆解实况（只读仪表盘，页面自己轮询 /progress/data）----
        // 固定路径 /progress/data 必须先于任何可能吃掉它的动态段注册；
        // 这里没有冲突的 {slug}，但仍然按「固定在前」写，避免以后加路由时踩到。
        $group->get('/progress', ProgressController::class . ':index')->setName('admin.progress');
        $group->get('/progress/data', ProgressController::class . ':data')->setName('admin.progress.data');

        // ---- 拆解调度台（1300 篇批跑的控制面）----
        $group->get('/queue', QueueController::class . ':index')->setName('admin.queue');
        $group->post('/queue/enqueue', QueueController::class . ':enqueue')->setName('admin.queue.enqueue');
        $group->post('/queue/pause', QueueController::class . ':pause')->setName('admin.queue.pause');
        $group->post('/queue/cancel-queued', QueueController::class . ':cancelQueued')->setName('admin.queue.cancel_queued');
        $group->post('/queue/jobs/{id:[0-9]+}/cancel', QueueController::class . ':cancelJob')->setName('admin.queue.cancel_job');
        $group->post('/queue/jobs/{id:[0-9]+}/retry', QueueController::class . ':retryJob')->setName('admin.queue.retry_job');
        $group->post('/queue/jobs/{id:[0-9]+}/dismiss', QueueController::class . ':dismissJob')->setName('admin.queue.dismiss_job');

        // ---- 词条治理 ----
        // hide / restore 写在 {id} 之前：{id} 是纯数字正则，理论不冲突，
        // 但固定路径先注册可以让「词条名叫 hide」这类假想在路由层就结束。
        $group->get('/terms', TermAdminController::class . ':index')->setName('admin.terms.index');
        $group->post('/terms/hide', TermAdminController::class . ':hide')->setName('admin.terms.hide');
        $group->post('/terms/restore', TermAdminController::class . ':restore')->setName('admin.terms.restore');
        $group->get('/terms/{id:[0-9]+}', TermAdminController::class . ':show')->setName('admin.terms.show');
        $group->post('/terms/{id:[0-9]+}/encyclopedia', TermAdminController::class . ':saveEncyclopedia')->setName('admin.terms.encyclopedia');

        // ---- 站内文章（官网内容源）----
        // 只读浏览官网全站文章，用来判断「哪些练笔作品还没进馆」。
        // 不写官网、不自动导入：导入要决定算作品还是素材、作者怎么认领，是另一件事。
        $group->get('/source', SourceController::class . ':index')->setName('admin.source.index');
        $group->post('/source/sync', SourceController::class . ':sync')->setName('admin.source.sync');
        // export 必须排在 {cid} 前面：虽然 {cid} 限定了纯数字，但把它放前面会让
        // 以后改成宽松匹配时悄悄把 /source/export 吃成详情页，排查起来很费劲。
        $group->get('/source/export', SourceController::class . ':export')->setName('admin.source.export');
        // 作者分类：同属「站内文章」的读视图，排在 {cid} 前面（理由同上）
        $group->get('/source/activity', SourceController::class . ':activity')->setName('admin.source.activity');
        $group->get('/source/{cid:[0-9]+}', SourceController::class . ':show')->setName('admin.source.show');

        // ---- 隐藏归档 ----
        $group->get('/hidden', HiddenController::class . ':index')->setName('admin.hidden.index');
        $group->post('/hidden/{type:work|analysis|term}/{id:[0-9]+}/restore', HiddenController::class . ':restore')->setName('admin.hidden.restore');

        // ---- 审计与账号 ----
        $group->get('/audit', AuditController::class . ':index')->setName('admin.audit.index');
        $group->get('/account', AccountController::class . ':index')->setName('admin.account.index');
        $group->post('/account/password', AccountController::class . ':password')->setName('admin.account.password');
    })
        ->add(PermissionMiddleware::class)
        ->add(AuthMiddleware::class);

    // ---- 公开馆藏（M5）----
    //
    // 这一组**不挂 AuthMiddleware / PermissionMiddleware**：公开页没有登录态。
    // 但它们仍然要 setName 并在 config/permissions.php 里登记 null —— bin/check-permissions.php
    // 靠这张表核对「每条路由都有归属」，漏登记会在自检里报错。
    //
    // 中间件顺序（add 是 LIFO，后加的先跑）：Cache 在外、RateLimit 在内。
    // 于是「缓存命中的请求不消耗限流额度」，限流表里的数字等于真正打到后端的请求数。
    $app->get('/sitemap.xml', SitemapController::class . ':index')->setName('library.sitemap');

    $app->get('/library/', static fn ($request, $response) => $response->withStatus(302)->withHeader('Location', '/library'))
        ->setName('library.slash');

    $app->group('/library', function (RouteCollectorProxy $group) use ($code): void {
        $group->get('', WorksController::class . ':home')->setName('library.home');

        $group->get('/works', WorksController::class . ':index')->setName('library.works');
        $group->get('/works/{code:' . $code . '}', WorksController::class . ':show')->setName('library.work');
        $group->get('/works/{code:' . $code . '}/author', WorksController::class . ':author')->setName('library.work.author');

        // 作者索引：没有这一页时，作者页只有一个入口 —— 某篇作品页顶端那一行名字。
        // 固定路径写在 {code} 之前：{code} 是 ULID 正则，理论上不会匹配 authors，
        // 但路由是按顺序匹配的，明确在前不吃亏。
        $group->get('/authors', AuthorsController::class . ':index')->setName('library.authors');

        // 作者页：一个人名下的全部馆内作品。
        // 用 code 不用名字 —— 笔名会改、会重名、会有全半角两种写法，
        // 而 code 是 ULID，改笔名不会让已经分享出去的链接失效。
        $group->get('/authors/{code:' . $code . '}', AuthorsController::class . ':show')->setName('library.author');

        $group->get('/terms', TermsController::class . ':index')->setName('library.terms');
        $group->get('/terms/{id:[0-9]+}', TermsController::class . ':show')->setName('library.term');

        $group->get('/search', SearchController::class . ':index')->setName('library.search');

        $group->get('/issues/{no:[0-9]+}', WorksController::class . ':issue')->setName('library.issue');

        /* 方法说明放在馆内（v2.4）：官网那一页只讲理念，方法论归馆内 ——
           人站在馆里看着某一页的结论，才会问「这条判断凭什么」。 */
        $group->get('/method', MethodController::class . ':index')->setName('library.method');

        /* 练笔统计：每月 16 日 18:00 由 cron 重算一次，页面只读快照。
           放在公开区而不是后台，是因为这组数字本身就是对外可讲的事实 ——
           多少人来写、多少人愿意评，比「馆里收录了多少篇」更能说明这个社区的样子。 */
        $group->get('/practice', PracticeController::class . ':index')->setName('library.practice');
    })
        ->add(PublicRateLimitMiddleware::class)
        ->add(PublicCacheMiddleware::class);

    // 兜底 404 由 errorMiddleware 处理（见 middleware.php）
};
