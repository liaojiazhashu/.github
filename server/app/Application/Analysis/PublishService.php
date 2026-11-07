<?php

declare(strict_types=1);

namespace App\Application\Analysis;

use App\Application\Library\PublicSurface;
use App\Infrastructure\Pdo\AnalysisRepository;
use App\Infrastructure\Pdo\AuditLogger;
use RuntimeException;
use Throwable;

/**
 * 发布 / 下线：这条业务流程的**唯一入口**。
 *
 * 一次发布是五件事，且必须一起发生：
 *
 *   1. **状态变更** —— hq_analysis 切 published / hq_work 指向当前版本；
 *   2. **快照** —— 留一版「这次上线的是什么」，日后能原样退回去；
 *   3. **审计** —— 谁在什么时候把哪一篇推到了读者面前；
 *   4. **检索索引 + 页面缓存** —— 索引是物化表，缓存是整页文件，两样都不会自己跟上；
 *   5. **sitemap** —— 新 URL 要进索引，下线的 URL 要退出去。
 *
 * 为什么必须先收成一处：这五步以前散在六个入口各写一遍（后台单篇发布、一键拆解
 * 整批上架、worker 自动上架、控制台 library:publish、代拆命令的两条路），
 * 每处漏一到三步。最贵的一次事故是 worker 自动上架的 604 篇从没写进
 * hq_search_keyword —— 作品在馆里、在列表里、在 sitemap 里，唯独搜不到，
 * 而界面只会说「没有找到」，看不出是自己坏了（见 bin/worker.php 里那段注释）。
 *
 * 行为基准：后台 WorkController::publish 是当初唯一写全的那一处，本类按它的
 * 顺序与语义实现；其余入口原先漏掉的步骤在这里补齐。唯一被统一掉的分歧是
 * mark_edited —— 六个入口里五个吃的是 snapshot() 默认的 true（于是每一次发布
 * 都把 hq_analysis.source 翻成 mixed，后台显示「模型生成 + 人工调整」，
 * 而全程没有人动过一个字），现在一律 false：**发布不是编辑**。
 *
 * 失败语义：前置条件不满足 → 抛 RuntimeException（调用方决定是 flash 报错还是
 * 计一条失败）；公开区那三样（索引、缓存、sitemap）的失败由 PublicSurface
 * 自己吞掉并记 error_log —— 数据已提交，事实已生效，不该因此让人重复点一遍。
 */
final class PublishService
{
    /**
     * 可发布的分析状态。
     *
     * succeeded = 跑完了但还没上线；published = 已经在线（重复点、或修 hq_work
     * 状态漂移）。后一种必须放行：历史上 runWork 会把 published 回落成 analyzed，
     * 拦掉的话前台列表里会永远少那一篇。
     */
    public const PUBLISHABLE = ['succeeded', 'published'];

    /** 控制台批量上架额外允许的状态：产物不全但已落库，历史上一直允许上线 */
    public const PUBLISHABLE_WITH_PARTIAL = ['succeeded', 'published', 'partially_failed'];

    /** 下线的前置状态：只有在线的东西才谈得上撤下来 */
    private const UNPUBLISHABLE = ['published'];

    public function __construct(
        private readonly AnalysisRepository $analyses,
        private readonly AuditLogger $audit,
        private readonly PublicSurface $surface,
    ) {
    }

    /**
     * 发布一次分析（成为这篇作品的当前版本）。
     *
     * @param int         $analysisId hq_analysis.id
     * @param int         $workId     hq_work.id（与分析的 work_id 一致，由调用方给，
     *                                保持与 AnalysisRepository::publish 同一套签名）
     * @param int|null    $actorId    操作者；null = 系统/无登录上下文
     * @param array{
     *     note?: string,
     *     mark_edited?: bool,
     *     audit?: bool,
     *     sitemap?: bool,
     *     cache?: bool,
     *     allow_status?: list<string>
     * } $options
     *   · note         —— 快照备注，各入口的措辞不一样，默认「发布」
     *   · mark_edited  —— 快照是否把 hq_analysis.source 翻成 mixed。默认 false：
     *                     **发布不是编辑**。六个入口现在都不传它，走这个默认值。
     *                     只有人工确实改过四件产出时才该传 true（那条路在
     *                     AnalysisRepository 的编辑方法里，不在发布链上）。
     *   · audit        —— 是否写逐条审计。批量入口自己有汇总审计时传 false，避免 N 条重复。
     *   · sitemap      —— 是否在本次操作后重写 sitemap（批量时由 publishMany 统一写一次）。
     *   · cache        —— 是否在本次操作后失效页面缓存。**检索索引不受这个开关影响**，
     *                     每次都重建。批量时由 publishMany 传 false：PageCache::invalidateTags
     *                     是「glob 全目录 + 逐个 file_get_contents + json_decode」，
     *                     100 篇逐条做就是 100 次全目录扫描，批末一次 forgetMany 就够。
     *   · allow_status —— 放行哪些分析状态，默认 self::PUBLISHABLE
     *
     * @return array{analysis_id:int, work_id:int, work_code:string, version_no:int, status:string}
     *
     * @throws RuntimeException 前置条件不满足（找不到分析 / 状态不对）
     */
    public function publish(int $analysisId, int $workId, ?int $actorId, array $options = []): array
    {
        $opts  = $this->options($options);
        $actor = (int) ($actorId ?? 0);

        $analysis = $this->requireAnalysis($analysisId);
        $status   = (string) $analysis['status'];

        if (!in_array($status, $opts['allow_status'], true)) {
            throw new RuntimeException('只有已完成的分析才能发布（当前：' . $status . '）');
        }

        $workCode = (string) ($analysis['work_code'] ?? '');

        // ① 状态变更：分析切 published，同作品的旧版本切 superseded，作品指向这一份
        $this->analyses->publish($analysisId, $workId, $actor);

        // ② 快照：发布留下的是「这次上线的内容」，所以拍在 publish 之后、
        //    与编辑路径（拍在改动之前）相反 —— 编辑要能退回被覆盖的旧值，
        //    发布要能退回上线时的那一版。
        $versionNo = $this->analyses->snapshot(
            $analysisId,
            $actor,
            (string) $opts['note'],
            null,
            (bool) $opts['mark_edited']
        );

        // ③ 审计：actor 原样传 null —— 0 会撞 hq_user 的外键，让整条审计写失败
        if ($opts['audit']) {
            $this->audit->log($actorId, 'analysis.publish', 'analysis', (string) ($analysis['code'] ?? ''), null, [
                'work_code' => $workCode,
            ]);
        }

        // ④⑤ 检索索引 → 页面缓存 → sitemap，一次调用走完。
        //     顺序由 PublicSurface::afterPublish 定，不在这里重排：索引是物化表，
        //     不重建就永久错着；缓存至少还有 300 秒 TTL 兜底，所以索引必须排在前。
        //     批量场景下 sitemap 与逐条缓存失效都由调用方关掉，批末各做一次。
        $this->surface->afterPublish(
            $workCode,
            [],
            (bool) $opts['sitemap'],
            (bool) $opts['cache']
        );

        return [
            'analysis_id' => $analysisId,
            'work_id'     => $workId,
            'work_code'   => $workCode,
            'version_no'  => $versionNo,
            'status'      => 'published',
        ];
    }

    /**
     * 批量发布：一次上架一批（一键拆解「上架本批」、控制台 library:publish --all）。
     *
     * 与逐条 publish 的差别只有一处：sitemap 收敛到批末写一次。
     * 1300 篇的 --all 若逐条重写，就是把一份几百个 URL 的 XML 写 1300 遍。
     *
     * 单条失败**不中断整批**：已经上线的那些不该因为第 87 篇数据不对而回滚，
     * 失败的逐条收集起来交给调用方汇报（后台计数 + 提示去作品页处理）。
     *
     * @param list<array{analysis_id:int|string, work_id:int|string}> $items
     * @param array{
     *     note?: string,
     *     mark_edited?: bool,
     *     audit?: bool,
     *     sitemap?: bool,
     *     cache?: bool,
     *     allow_status?: list<string>
     * } $options
     *
     * @return array{
     *     published:int,
     *     failed:int,
     *     codes:list<string>,
     *     failures:list<array{analysis_id:int, work_id:int, error:string}>
     * }
     */
    public function publishMany(array $items, ?int $actorId, array $options = []): array
    {
        $opts = $this->options($options);

        // 逐条时不写 sitemap、也不逐条失效页面缓存，批末各做一次
        $single = array_merge($opts, ['sitemap' => false, 'cache' => false]);

        $codes    = [];
        $failures = [];

        foreach ($items as $item) {
            $analysisId = (int) ($item['analysis_id'] ?? 0);
            $workId     = (int) ($item['work_id'] ?? 0);

            try {
                $result  = $this->publish($analysisId, $workId, $actorId, $single);
                $codes[] = (string) $result['work_code'];
            } catch (Throwable $e) {
                $failures[] = [
                    'analysis_id' => $analysisId,
                    'work_id'     => $workId,
                    'error'       => $e->getMessage(),
                ];
            }
        }

        if ($codes !== []) {
            // 批内**唯一**的缓存失效点（逐条时 cache=false 已关掉）。
            // 标签一次收集完再失效：含 terms —— 整批上线会让词条的「关联篇数」一起变；
            // 每篇的 work:{code} 也在这里一次扫完，而不是 glob 目录 N 次。
            $this->surface->forgetMany($codes);

            if ($opts['sitemap']) {
                $this->surface->refreshSitemap();
            }
        }

        return [
            'published' => count($codes),
            'failed'    => count($failures),
            'codes'     => $codes,
            'failures'  => $failures,
        ];
    }

    /**
     * 下线当前发布的分析。
     *
     * 五步里少一步「快照」：下线不改产物内容，产物还在（分析退回 succeeded），
     * 没有「被覆盖掉的旧值」需要留副本。
     *
     * @param int      $analysisId hq_analysis.id
     * @param int      $workId     hq_work.id
     * @param int|null $actorId    操作者
     * @param array{audit?: bool, sitemap?: bool, cache?: bool} $options
     *
     * @return array{analysis_id:int, work_id:int, work_code:string, status:string}
     *
     * @throws RuntimeException 前置条件不满足（找不到分析 / 当前不在线）
     */
    public function unpublish(int $analysisId, int $workId, ?int $actorId, array $options = []): array
    {
        $opts = $this->options($options);

        $analysis = $this->requireAnalysis($analysisId);
        $status   = (string) $analysis['status'];

        if (!in_array($status, self::UNPUBLISHABLE, true)) {
            throw new RuntimeException('这次分析当前不是发布状态');
        }

        $workCode = (string) ($analysis['work_code'] ?? '');

        // ① 状态变更：分析退回 succeeded，作品回到已分析、当前版本置空
        $this->analyses->unpublish($analysisId, $workId);

        // ② 审计（无快照，理由见方法注释）
        if ($opts['audit']) {
            $this->audit->log($actorId, 'analysis.unpublish', 'analysis', (string) ($analysis['code'] ?? ''));
        }

        // ③④ 索引、缓存、sitemap 一起处理：下线之后还能搜到、点进去是 404，
        //     比搜不到更糟；sitemap 里留着那条 URL 等于指着空页让人来。
        $this->surface->afterPublish(
            $workCode,
            [],
            (bool) $opts['sitemap'],
            (bool) $opts['cache']
        );

        return [
            'analysis_id' => $analysisId,
            'work_id'     => $workId,
            'work_code'   => $workCode,
            'status'      => 'analyzed',
        ];
    }

    /**
     * 批量下线：一次撤下一批（控制台 library:unpublish --all）。
     *
     * @param list<array{analysis_id:int|string, work_id:int|string}> $items
     * @param array{audit?: bool, sitemap?: bool, cache?: bool} $options
     *
     * @return array{
     *     unpublished:int,
     *     failed:int,
     *     codes:list<string>,
     *     failures:list<array{analysis_id:int, work_id:int, error:string}>
     * }
     */
    public function unpublishMany(array $items, ?int $actorId, array $options = []): array
    {
        $opts   = $this->options($options);
        $single = array_merge($opts, ['sitemap' => false, 'cache' => false]);

        $codes    = [];
        $failures = [];

        foreach ($items as $item) {
            $analysisId = (int) ($item['analysis_id'] ?? 0);
            $workId     = (int) ($item['work_id'] ?? 0);

            try {
                $result  = $this->unpublish($analysisId, $workId, $actorId, $single);
                $codes[] = (string) $result['work_code'];
            } catch (Throwable $e) {
                $failures[] = [
                    'analysis_id' => $analysisId,
                    'work_id'     => $workId,
                    'error'       => $e->getMessage(),
                ];
            }
        }

        if ($codes !== []) {
            $this->surface->forgetMany($codes);

            if ($opts['sitemap']) {
                $this->surface->refreshSitemap();
            }
        }

        return [
            'unpublished' => count($codes),
            'failed'      => count($failures),
            'codes'       => $codes,
            'failures'    => $failures,
        ];
    }

    /**
     * 分析必须存在：不存在时干跑 UPDATE 会「静默成功但什么都没发生」，
     * 调用方以为上线了，前台还是空的。宁可抛。
     *
     * @return array<string, mixed>
     */
    private function requireAnalysis(int $analysisId): array
    {
        if ($analysisId <= 0) {
            throw new RuntimeException('缺少分析 id');
        }

        $analysis = $this->analyses->findById($analysisId);
        if ($analysis === null) {
            throw new RuntimeException('找不到这次分析（#' . $analysisId . '）');
        }

        return $analysis;
    }

    /**
     * 把调用方给的选项补成完整结构。幂等：已解析的数组再传进来结果不变
     * （publishMany 就是先解析好、再逐条传下去）。
     *
     * @param array<string, mixed> $options
     *
     * @return array{note:string, mark_edited:bool, audit:bool, sitemap:bool, cache:bool, allow_status:list<string>}
     */
    private function options(array $options): array
    {
        $allow = $options['allow_status'] ?? self::PUBLISHABLE;

        return [
            'note'         => (string) ($options['note'] ?? '发布'),
            'mark_edited'  => (bool) ($options['mark_edited'] ?? false),
            'audit'        => (bool) ($options['audit'] ?? true),
            'sitemap'      => (bool) ($options['sitemap'] ?? true),
            'cache'        => (bool) ($options['cache'] ?? true),
            'allow_status' => is_array($allow) && $allow !== []
                ? array_values(array_map(static fn (mixed $s): string => (string) $s, $allow))
                : self::PUBLISHABLE,
        ];
    }
}
