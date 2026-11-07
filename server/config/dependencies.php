<?php

declare(strict_types=1);

use App\Application\Analysis\Chunker;
use App\Application\Analysis\Pipeline;
use App\Application\Analysis\PublishService;
use App\Application\Analysis\TermEncyclopedia;
use App\Application\Analysis\PromptLibrary;
use App\Application\Analysis\PromptTemplateRepository;
use App\Application\Library\PageCache;
use App\Application\Library\PublicSurface;
use App\Application\Library\PublicTextGuard;
use App\Application\Library\VisibilityService;
use App\Application\Source\AuthorActivity;
use App\Application\Source\SourceApi;
use App\Application\Source\SourceCatalog;
use App\Infrastructure\Ai\AiClientInterface;
use App\Infrastructure\Ai\CostModel;
use App\Infrastructure\Ai\DeepSeekClient;
use App\Infrastructure\Pdo\AiCallRepository;
use App\Infrastructure\Pdo\AnalysisRepository;
use App\Infrastructure\Pdo\AuditLogger;
use App\Infrastructure\Pdo\JobRepository;
use App\Infrastructure\Pdo\LibraryRepository;
use App\Infrastructure\Pdo\SessionRepository;
use App\Infrastructure\Pdo\SettingRepository;
use App\Infrastructure\Pdo\TermEncyclopediaRepository;
use App\Infrastructure\Pdo\UserRepository;
use App\Infrastructure\Pdo\WorkRepository;
use App\Infrastructure\Storage\UploadStore;
use App\Infrastructure\Import\TextExtractor;
use App\Support\Config;
use App\Support\Crypto;
use App\Support\Db;
use App\Support\Log;
use App\Support\RequestContext;
use App\Support\Session;
use App\View\TwigFactory;
use App\View\View;
use Monolog\Logger;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\StreamFactory;
use Twig\Environment;

/**
 * 容器定义。约定：
 *   · 有外部输入（配置、路径、连接）的类在这里显式装配；
 *   · 纯逻辑类交给 PHP-DI 自动装配；
 *   · 单例只用于「一个请求内应当共享」的东西（PDO、Session、Crypto、Context）。
 */

return [
    Config::class => static fn (): Config => new Config(require __DIR__ . '/settings.php'),

    PDO::class => static fn (Config $config): PDO => Db::connect($config),

    Logger::class => static fn (Config $config): Logger => Log::create($config),

    ResponseFactoryInterface::class => static fn (): ResponseFactoryInterface => new ResponseFactory(),
    StreamFactoryInterface::class => static fn (): StreamFactoryInterface => new StreamFactory(),

    Session::class => static fn (Config $config): Session => new Session($config),
    Crypto::class => static fn (Config $config): Crypto => new Crypto($config),
    RequestContext::class => static fn (Crypto $crypto): RequestContext => new RequestContext($crypto),

    Environment::class => static fn (Config $config, Session $session): Environment => TwigFactory::create($config, $session),
    View::class => static fn (Environment $twig): View => new View($twig),

    UploadStore::class => static fn (Config $config): UploadStore => new UploadStore($config->string('upload.dir')),

    UserRepository::class => static fn (PDO $pdo): UserRepository => new UserRepository($pdo),
    SessionRepository::class => static fn (PDO $pdo): SessionRepository => new SessionRepository($pdo),
    WorkRepository::class => static fn (PDO $pdo): WorkRepository => new WorkRepository($pdo),
    LibraryRepository::class => static fn (PDO $pdo): LibraryRepository => new LibraryRepository($pdo),

    // 词条百科：仓储 + 读取器。读取器不是纯逻辑类（它要查库再回落文件），
    // 而 PHP-DI 的自动装配会把可空的仓储参数当成「不传」，所以这里显式给。
    TermEncyclopediaRepository::class => static fn (PDO $pdo): TermEncyclopediaRepository => new TermEncyclopediaRepository($pdo),
    TermEncyclopedia::class => static fn (TermEncyclopediaRepository $repo): TermEncyclopedia => new TermEncyclopedia($repo),

    // 公开文本守卫：每次请求构造一次，内部只读两个小查询（隐藏词条 + 可见名称）。
    // 没有隐藏词条时构造函数立刻返回，text()/tree() 全是空转 —— 常态零开销。
    PublicTextGuard::class => static fn (PDO $pdo): PublicTextGuard => PublicTextGuard::fromDatabase($pdo),
    AuditLogger::class => static fn (PDO $pdo, RequestContext $context): AuditLogger => new AuditLogger($pdo, $context),
    SettingRepository::class => static fn (PDO $pdo): SettingRepository => new SettingRepository($pdo),
    JobRepository::class => static fn (PDO $pdo): JobRepository => new JobRepository($pdo),
    AiCallRepository::class => static fn (PDO $pdo): AiCallRepository => new AiCallRepository($pdo),

    // ---------------------------------------------------------------- AI / 分析
    CostModel::class => static fn (Config $config): CostModel => new CostModel($config),

    // 接口 → 实现。测试里换成 FakeAiClient 就能在无网络、无费用的情况下跑流水线
    AiClientInterface::class => static fn (Config $config, CostModel $cost): AiClientInterface => new DeepSeekClient($config, $cost),

    PromptTemplateRepository::class => static fn (PDO $pdo): PromptTemplateRepository => new PromptTemplateRepository($pdo),
    PromptLibrary::class => static fn (Config $config, PromptTemplateRepository $templates): PromptLibrary => new PromptLibrary($config, $templates),

    Chunker::class => static fn (Config $config): Chunker => new Chunker(
        $config->int('ai.chunk_max_chars', 12000),
        $config->int('ai.chunk_overlap', 300),
    ),

    AnalysisRepository::class => static fn (PDO $pdo, Config $config): AnalysisRepository => new AnalysisRepository(
        $pdo,
        $config->string('ai.artifact_dir', $config->string('app.root') . '/storage/artifacts'),
    ),

    // 页面缓存要一个目录字符串，PHP-DI 没法自动装配标量参数，只能显式给
    PageCache::class => static fn (Config $config): PageCache => new PageCache(
        $config->string('library.cache_dir', $config->string('app.root') . '/storage/cache/pages')
    ),

    // 「隐藏 / 恢复」的唯一入口。后台按钮、命令行都走它 —— 因为它同时负责
    // 改状态、写审计、失效页面缓存，少任何一步都会出现「藏了但还看得见」。
    VisibilityService::class => static fn (
        PDO $pdo,
        AuditLogger $audit,
        PageCache $cache,
        LibraryRepository $library,
        Config $config,
    ): VisibilityService => new VisibilityService($pdo, $audit, $cache, $library, $config),

    // 「发布 / 下线」的唯一入口：状态 → 快照 → 审计 → 检索索引与页面缓存 → sitemap。
    // 六个入口（后台单篇、一键整批、worker 自动上架、控制台、代拆命令两条路）都只调它。
    // 依赖本来就能自动装配，这里显式登记的理由与 VisibilityService 相同：
    // 这一行本身就是「这件事只有一个入口」的声明。命令行（bin/console.php）不走容器，
    // 那边按同样的形状手工 new 一份。
    PublishService::class => static fn (
        AnalysisRepository $analyses,
        AuditLogger $audit,
        PublicSurface $surface,
    ): PublishService => new PublishService($analyses, $audit, $surface),

    Pipeline::class => static fn (
        Config $config,
        AiClientInterface $client,
        PromptLibrary $prompts,
        AnalysisRepository $analyses,
        AiCallRepository $aiCalls,
        SettingRepository $settings,
        WorkRepository $works,
        UploadStore $store,
        TextExtractor $extractor,
        Chunker $chunker,
        Logger $logger,
    ): Pipeline => new Pipeline($config, $client, $prompts, $analyses, $aiCalls, $settings, $works, $store, $extractor, $chunker, $logger),

    // 官网站内文章：只读客户端 + 全量目录缓存。
    // 目录整份落盘（1843 条），后台翻页读本地；全文走实时详情接口。
    SourceApi::class => static fn (Config $config): SourceApi => new SourceApi(
        $config->string('source.base'),
        $config->string('source.key'),
        $config->string('source.ca', ''),
    ),

    SourceCatalog::class => static fn (Config $config, SourceApi $api): SourceCatalog => new SourceCatalog(
        $api,
        $config->string('source.cache_dir'),
    ),

    // 作者活跃度：与目录共用同一个缓存目录，快照落在 activity.json。
    // 需要显式工厂 —— 构造函数第二个参数是路径字符串，反射自动装配给不出来。
    AuthorActivity::class => static fn (Config $config, SourceApi $api): AuthorActivity => new AuthorActivity(
        $api,
        $config->string('source.cache_dir'),
    ),
];
