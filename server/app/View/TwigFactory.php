<?php

declare(strict_types=1);

namespace App\View;

use App\Support\Config;
use App\Support\Session;
use Twig\Environment;
use Twig\Extension\CoreExtension;
use Twig\Loader\FilesystemLoader;

/**
 * Twig 环境。
 *
 * strict_variables=true 是刻意的：模板里写错变量名要立刻炸，而不是渲染出一片空白。
 * autoescape 用默认 html，模板中不允许 |raw（除白名单外不提供该过滤器）。
 */
final class TwigFactory
{
    public static function create(Config $config, Session $session): Environment
    {
        $root   = $config->string('app.root');
        $loader = new FilesystemLoader($root . '/resources/views');

        $cacheDir = $config->string('storage.cache') . '/twig';
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0750, true);
        }

        $twig = new Environment($loader, [
            'cache'            => $config->bool('app.debug', false) ? false : $cacheDir,
            'debug'            => $config->bool('app.debug', false),
            'strict_variables' => true,
            'autoescape'       => 'html',
            'charset'          => 'UTF-8',
            // ⚠️ 必须显式打开：Twig 的 auto_reload 默认跟随 debug，而生产是 debug=false，
            // 结果是改了模板刷新页面毫无变化（缓存永远不会失效）。8 个模板每次多一次 stat，可以忽略。
            'auto_reload'      => true,
        ]);

        $twig->addFunction(new \Twig\TwigFunction('csrf_token', static fn (): string => $session->csrfToken()));
        $twig->addFunction(new \Twig\TwigFunction('num', static fn (int|float|string $value): string => number_format((float) $value)));
        // 金额统一两位小数：后台一处写 ¥19.7306、一处写 ¥20.12，读起来像两套账。
        // 单篇均价确实很小（¥0.1 量级），所以另给一个三位的，只用在「单篇均价」这一处。
        $twig->addFunction(new \Twig\TwigFunction('yuan', static fn (int|float|string $value): string => number_format((float) $value, 2)));

        // 模板里判权限只走这一个函数，写法固定为 can(user.permissions, 'work.write')。
        // 不做成全局 user 变量：渲染入口有控制器和中间件两处，全局变量容易只在一处被赋值，
        // 漏掉的那处往往就是出错页 —— 而"出错页上按钮全没了"比报错更难查。
        $twig->addFunction(new \Twig\TwigFunction(
            'can',
            static fn (mixed $perms, string $code): bool => \App\Application\Auth\Permission::can(
                is_array($perms) ? $perms : [],
                $code
            )
        ));
        $twig->addFilter(new \Twig\TwigFilter(
            'perm_label',
            static fn (string $code): string => \App\Application\Auth\Permission::LABELS[$code] ?? $code
        ));

        // 状态码只在数据库与接口里用英文，界面上必须是中文（同一个事实不出现两种说法）
        $twig->addFilter(new \Twig\TwigFilter('status_label', static fn (string $status): string => [
            // 作品
            'draft'            => '草稿',
            'imported'         => '已导入',
            'analyzing'        => '分析中',
            'analyzed'         => '已分析',
            'published'        => '已发布',
            'archived'         => '已归档',
            // 分析
            'queued'           => '排队中',
            'running'          => '执行中',
            'succeeded'        => '已完成',
            'partially_failed' => '部分失败',
            'failed'           => '失败',
            'superseded'       => '已被替换',
            // 阶段
            'pending'          => '待执行',
            'stale'            => '已过期',
            // 任务
            'canceled'         => '已取消',
            // 索引词
            'active'           => '启用',
            'merged'           => '已归并',
            'deprecated'       => '停用',
        ][$status] ?? $status));

        // 阶段代号 → 中文名。**唯一来源在 StageSpec::LABELS**，这里只转调，不许再抄一份。
        // 抄过一次的代价：这份副本漏了 S7_READER，后台「执行记录」表把机器代号
        // 原样显示给运营看（模板里 stage_code|stage_label 查不到就回落到代号）。
        $twig->addFilter(new \Twig\TwigFilter(
            'stage_label',
            static fn (string $code): string => \App\Application\Analysis\StageSpec::label($code)
        ));

        // 可重跑阶段的下拉选项（code => 中文名），顺序即流水线顺序。
        // 模板里原本手写了六个 <option>，是阶段口径的第三份副本 —— 它和 LABELS
        // 各说各的（同一个 S1，下拉框叫「科幻词条」、执行记录叫「索引词」），
        // 加阶段时也必然只想起一处。这里由 ORDER 生成，模板只管循环。
        // S6_ASSEMBLE 是纯代码组装、不是模型阶段，所以不在 ORDER 里，也就不会出现在选项中。
        $twig->addFunction(new \Twig\TwigFunction('stage_options', static function (): array {
            $options = [];

            foreach (\App\Application\Analysis\StageSpec::order() as $code) {
                $options[$code] = \App\Application\Analysis\StageSpec::label($code);
            }

            return $options;
        }));

        // 相位 / 层级的中文名
        $twig->addFilter(new \Twig\TwigFilter('phase_label', static fn (string $phase): string => [
            'start'  => '起点',
            'turn'   => '转折',
            'crisis' => '危机',
            'end'    => '终局',
        ][$phase] ?? $phase));

        $twig->addFilter(new \Twig\TwigFilter('layer_label', static fn (string $layer): string => [
            'structure' => '结构层',
            'execution' => '执行层',
            'detail'    => '细节层',
        ][$layer] ?? $layer));

        // 词库只有三类：能被当作概念检索的东西。
        // 题材（theme）与情绪（tone）在 v2 被移出 —— 它们描述文风，查不到实体。
        $twig->addFilter(new \Twig\TwigFilter('category_label', static fn (string $category): string => [
            'setting' => '科幻设定',
            'world'   => '世界观',
            'image'   => '意象',
        ][$category] ?? $category));

        $twig->addFilter(new \Twig\TwigFilter('category_note', static fn (string $category): string => [
            'setting' => '技术、装置、物理规则、信息形态',
            'world'   => '社会结构、制度、秩序、历史前提',
            'image'   => '反复出现并承担意义的物象',
        ][$category] ?? ''));

        return $twig;
    }
}
