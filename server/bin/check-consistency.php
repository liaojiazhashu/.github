<?php

declare(strict_types=1);

/**
 * 阶段口径自检：把「阶段顺序」与「阶段中文名」的每一份副本对齐。
 *
 * 为什么需要一个独立脚本而不是只靠单测：
 *   这几份口径都曾经各自写死过一遍，而**漂移不会报错** ——
 *     · PHP 里两份阶段顺序错开，Pipeline 就按 A 顺序写记录、按 B 顺序补跑，漏跑不报错；
 *     · 中文名漏一条，后台「执行记录」表直接把 S7_READER 这种机器代号显示给运营看，
 *       页面照常渲染，谁也不觉得是 bug。
 *   单测覆盖的是「同一个类里的不变量」（tests/Unit/StageDependencyTest.php），
 *   跨类、跨模板、跨 config 的那几份副本它看不见。
 *
 * 检查七件事：
 *   1. StageSpec::LABELS 的键集合 = StageSpec::order()（多一个少一个都算错）；
 *   2. order() 里每个阶段都能查到中文名（查到代号本身说明标签漏了）；
 *   3. StageDependency::ORDER = StageSpec::order()（合并成引用之后这是自反检查，保留）；
 *   4. StageDependency::CASCADE 的键 = ORDER，级联目标都在 ORDER 里且排在被依赖者之后；
 *   5. config/ai.php 的 ai.stage.* 覆盖 order()（缺了 fromConfig 会抛异常）；
 *      其中 label 文案与常量不一致只提示 —— **标签以常量为准**，config 只是兜底；
 *   6. 模板层：`stage_code` 必须套 `|stage_label`、不许手写 `<option value="S7_READER">`。
 *      裸出代号只在「同一行有中文名 + 自己包在 class 含 dim/mono 的元素里」时放行
 *      （analysis.twig:172 的暗字备注形态）。这三条查的是「换了单一来源、
 *      但下一张表又手写一遍 / 又漏套过滤器」的复发；
 *   7. TwigFactory 里不许再出现阶段代号字面量，且 stage_label / stage_options
 *      定义了就要有模板用（否则是死函数，等于口径搬了家没人知道）；
 *   8. `Pipeline::rerunStage()` 里参数校验必须排在环境校验之前（源码顺序断言，
 *      不用起库、不用桩）。顺序反了时「非法 stage」会被报成「AI 未启用」，
 *      原因与现象错位。**这一条当前是提示级**：缺陷已确认、修复归 Pipeline 的
 *      改动方，修好之后应当把它改成错误级。
 *
 * 本脚本只读：不连数据库、不调模型、不写文件。退出码 0 通过 / 1 有错误。
 *
 * 用法：
 *   php -c php.ini bin/check-consistency.php
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Application\Analysis\StageDependency;
use App\Application\Analysis\StageSpec;

$errors = [];
$warns  = [];

$order  = StageSpec::order();
$labels = StageSpec::LABELS;

// ---- 1. 标签键集合 vs 阶段顺序 ---------------------------------------------
foreach (array_diff($order, array_keys($labels)) as $code) {
    $errors[] = sprintf('StageSpec::LABELS 缺少阶段标签：%s（模板上会露出机器代号）', $code);
}
foreach (array_diff(array_keys($labels), $order) as $code) {
    $errors[] = sprintf(
        'StageSpec::LABELS 多出不在 order() 里的阶段：%s（要么加进 order()，要么移进 RESERVED_LABELS）',
        $code
    );
}

// ---- 2. 每个阶段都查得到中文名 ---------------------------------------------
foreach ($order as $code) {
    // label() 查不到时会回落成代号本身，所以「等于代号」就是漏了标签
    if (StageSpec::label($code) === $code) {
        $errors[] = sprintf('%s 查不到中文名（label() 回落成了代号）', $code);
    }
}
foreach (array_keys(StageSpec::RESERVED_LABELS) as $code) {
    if (in_array($code, $order, true)) {
        $errors[] = sprintf('%s 同时出现在 RESERVED_LABELS 与 order() 里（预留代号不该进流水线）', $code);
    }
    if (isset($labels[$code])) {
        $errors[] = sprintf('%s 同时出现在 LABELS 与 RESERVED_LABELS 里（两份标签，改一处忘一处）', $code);
    }
}

// ---- 3. StageDependency::ORDER vs StageSpec::order() -----------------------
// 顺序也算内容：Pipeline 用 ORDER 算「从哪一步开始补跑」，用 order() 写 stage 记录，
// 两套顺序一错开就会漏跑或重复跑，而且都不会报错。
if (StageDependency::ORDER !== $order) {
    $errors[] = sprintf(
        'StageDependency::ORDER 与 StageSpec::order() 不一致：%s ≠ %s',
        implode(',', StageDependency::ORDER),
        implode(',', $order)
    );
}

// ---- 4. 级联表 vs 阶段顺序 -------------------------------------------------
$cascadeKeys = array_keys(StageDependency::CASCADE);
if ($cascadeKeys !== $order) {
    $errors[] = sprintf(
        'StageDependency::CASCADE 的键与 order() 不一致：%s',
        implode(',', array_diff($cascadeKeys, $order)) !== ''
            ? '多出 ' . implode(',', array_diff($cascadeKeys, $order))
            : '缺少 ' . implode(',', array_diff($order, $cascadeKeys))
    );
}
foreach (StageDependency::CASCADE as $stage => $dependents) {
    $from = array_search($stage, $order, true);
    foreach ($dependents as $dependent) {
        if (!in_array($dependent, $order, true)) {
            $errors[] = sprintf('级联表里 %s 指向未知阶段 %s', $stage, $dependent);
            continue;
        }
        // 级联必须往前走：依赖一个排在自己前面的阶段，等于永远补不全
        if ((int) array_search($dependent, $order, true) <= (int) $from) {
            $errors[] = sprintf('级联表里 %s → %s 不是顺流方向（会被排到它前面）', $stage, $dependent);
        }
    }
}

// ---- 5. config/ai.php 的阶段表 ---------------------------------------------
// 纯数组文件，不连库也不调模型；缺了 ai.stage.<code>，StageSpec::fromConfig 会抛
// 「未定义的阶段」—— 那是一个只在跑到那一步才炸的错，值得在这里提前拦住。
$aiConfig = require dirname(__DIR__) . '/config/ai.php';
$aiStages = is_array($aiConfig['stage'] ?? null) ? $aiConfig['stage'] : [];

foreach ($order as $code) {
    if (!is_array($aiStages[$code] ?? null)) {
        $errors[] = sprintf('config/ai.php 里没有 ai.stage.%s（fromConfig 会抛「未定义的阶段」）', $code);
        continue;
    }
    // 文案不一致只提示：常量是唯一来源，config 的 label 现在只是兜底
    $configLabel = (string) ($aiStages[$code]['label'] ?? '');
    if ($configLabel !== '' && $configLabel !== StageSpec::label($code)) {
        $warns[] = sprintf(
            'config/ai.php 的 %s label 是「%s」，常量是「%s」（以常量为准，建议顺手改齐）',
            $code,
            $configLabel,
            StageSpec::label($code)
        );
    }
}
foreach (array_keys($aiStages) as $code) {
    if (!in_array($code, $order, true)) {
        $warns[] = sprintf('config/ai.php 多出一个不在 order() 里的阶段：%s（死配置）', $code);
    }
}

// ---- 6. 模板层：代号不许裸奔 / 选项不许手写 ---------------------------------
// 换了单一来源不等于不会复发：下一张「执行记录」表照样可以写 {{ row.stage_code }}，
// 下一个下拉照样可以手抄六个 <option> —— 两者都不报错，只是页面上又出现机器代号。
// 所以在模板文本上直接查：凡是渲染 stage_code 的地方必须套 stage_label。
$viewsRoot = dirname(__DIR__) . '/resources/views';
$shortPath = static function (string $path): string {
    return ltrim(str_replace('\\', '/', substr($path, strlen(dirname(__DIR__)))), '/');
};

$twigFiles = [];
if (is_dir($viewsRoot)) {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($viewsRoot, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if ($file->isFile() && str_ends_with($file->getFilename(), '.twig')) {
            $twigFiles[] = $file->getPathname();
        }
    }
    sort($twigFiles);
} else {
    $errors[] = '找不到模板目录 resources/views（模板层检查跳过）';
}

$usesStageOptions = false;
$usesStageLabel   = false;

foreach ($twigFiles as $path) {
    $source = (string) file_get_contents($path);

    // 6a. 手写的阶段下拉项：值就是阶段代号，说明它绕过了 stage_options()
    foreach (explode("\n", $source) as $index => $line) {
        if (preg_match('/<option[^>]*value="S\d+_[A-Z][A-Z_]*"/', $line) === 1) {
            $errors[] = sprintf(
                '%s:%d 手写了阶段选项（应改用 stage_options()，由 StageSpec::LABELS 出中文名）',
                $shortPath($path),
                $index + 1
            );
        }
    }

    // 6b. 裸输出 stage_code：查每个 {{ … }} 表达式，提到 stage_code 就必须有 |stage_label
    //
    // 唯一的例外形态是 analysis.twig:172 那种「中文名 + dim 暗字代号」：
    //   {{ s.stage_code|stage_label }}<span class="dim mono"> {{ s.stage_code }}</span>
    // 代号是刻意的排查辅助，不是给人读的主文案。放行条件必须**两条同时满足**：
    //   ① 同一行另有 |stage_label 的输出（有中文名兜着，运营不会只看到代号）；
    //   ② 这个裸输出自己包在 class 含 dim 或 mono 的元素里（明确标成机器备注）。
    // 只判 ①（早期实现）有个漏洞：`{{ a.stage_code|stage_label }} {{ b.stage_code }}`
    // 写在同一行时第二个能蒙过关 —— 那恰恰是「忘套过滤器」最容易出现的样子。
    if (preg_match_all('/\{\{(.*?)\}\}/s', $source, $matches, PREG_OFFSET_CAPTURE) > 0) {
        $lines = explode("\n", $source);
        foreach ($matches[1] as [$expression, $offset]) {
            if (!str_contains($expression, 'stage_code')) {
                continue;
            }
            if (str_contains($expression, 'stage_label')) {
                continue;
            }

            $offset   = (int) $offset;
            $lineNo   = substr_count(substr($source, 0, $offset), "\n") + 1;
            $lineText = $lines[$lineNo - 1] ?? '';

            // 条件 ②：往上找最近的未闭合开始标签，看它的 class 是否标了 dim / mono
            $before = substr($source, max(0, $offset - 400), min(400, $offset));
            $ltPos  = strrpos($before, '<');
            $openTag = $ltPos === false ? '' : rtrim(substr($before, $ltPos));
            $isDimHint = preg_match('/^<\w+[^>]*class="([^"]*)"/', $openTag, $classHit) === 1
                && preg_match('/(^|\s)(dim|mono)(\s|$)/', $classHit[1]) === 1;

            if (str_contains($lineText, 'stage_label') && $isDimHint) {
                continue;
            }

            $errors[] = sprintf(
                '%s:%d 裸输出了 stage_code（没套 |stage_label，运营会看到机器代号；'
                . '确要当暗字备注就包进 class="dim mono" 且同一行给出中文名）',
                $shortPath($path),
                $lineNo
            );
        }
    }

    if (str_contains($source, 'stage_options(')) {
        $usesStageOptions = true;
    }
    if (str_contains($source, 'stage_label')) {
        $usesStageLabel = true;
    }
}

// ---- 7. TwigFactory 自己也不许抄 -------------------------------------------
$twigFactory = (string) file_get_contents(dirname(__DIR__) . '/app/View/TwigFactory.php');

if (preg_match("/'S\d+_[A-Z][A-Z_]*'/", $twigFactory, $hit) === 1) {
    $errors[] = sprintf(
        'TwigFactory.php 里出现了阶段代号字面量 %s（阶段口径只许留在 StageSpec）',
        $hit[0]
    );
}
if (str_contains($twigFactory, "'stage_label'") && !$usesStageLabel) {
    $warns[] = 'TwigFactory 定义了 stage_label，但没有任何模板使用（死过滤器）';
}
if (str_contains($twigFactory, "'stage_options'") && !$usesStageOptions) {
    $warns[] = 'TwigFactory 定义了 stage_options，但没有任何模板使用（死函数）';
}

// ---- 8. 参数校验必须先于环境校验（源码顺序断言） ---------------------------
// 这条刻意不做行为测试：rerunStage() 一开头就要 AnalysisRepository(PDO) 和 Config，
// 纯 unit 构造不出来，集成测试又要真库。但「顺序」在源码里是可判定的 ——
// 取方法体子串、比两个符号的位置就行：零依赖、不连库、不要桩。
// 钉的是缺陷本身，不是它的间接后果：assertAiReady() 排在 isStage() 之前时，
// AI 关闭期间提交非法 stage 会报「AI 未启用」，把参数错说成环境错。
$pipelinePath = dirname(__DIR__) . '/app/Application/Analysis/Pipeline.php';
$pipelineSrc  = (string) file_get_contents($pipelinePath);

$rerunBody = '';
$rerunAt   = strpos($pipelineSrc, 'public function rerunStage(');
if ($rerunAt !== false) {
    // 方法体 = 从方法签名到下一个同级方法定义之前
    $tail = substr($pipelineSrc, $rerunAt);
    if (preg_match('/\n    (?:public|private|protected) (?:static )?function /', $tail, $next, PREG_OFFSET_CAPTURE, 16) === 1) {
        $rerunBody = substr($tail, 0, (int) $next[0][1]);
    } else {
        $rerunBody = $tail;
    }
}

if ($rerunBody === '') {
    // 找不到方法也要报：静默跳过等于这条检查悄悄失效
    $errors[] = '找不到 Pipeline::rerunStage()，第 8 项顺序断言无法执行（别静默跳过）';
} else {
    $posIsStage = strpos($rerunBody, 'isStage(');
    $posAiReady = strpos($rerunBody, 'assertAiReady(');

    if ($posIsStage === false || $posAiReady === false) {
        $errors[] = 'Pipeline::rerunStage() 里找不到 isStage() 或 assertAiReady()'
            . '（校验被移走或改名了，第 8 项断言失效，请更新它）';
    } elseif ($posAiReady < $posIsStage) {
        $warns[] = 'Pipeline::rerunStage() 的 assertAiReady() 排在 isStage() 之前：'
            . 'AI 关闭时提交非法 stage 会报「AI 未启用」而不是「未知阶段」，原因与现象错位'
            . '（已知缺陷；修好后把本条改成错误级）';
    }
}

// ---- 输出 -----------------------------------------------------------------
// 终端里中文占两列，str_pad 按字符排不齐，这里按显示宽度补空格
$displayWidth = static function (string $text): int {
    $width = 0;
    foreach (preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $char) {
        $width += strlen($char) > 1 ? 2 : 1;
    }

    return $width;
};
$pad = static fn (string $text, int $width): string => $text . str_repeat(' ', max(0, $width - $displayWidth($text)));

fwrite(STDOUT, "阶段口径（唯一来源：StageSpec::ORDER / StageSpec::LABELS）：\n\n");
fwrite(STDOUT, $pad('#', 4) . ' | ' . $pad('代号', 16) . ' | ' . $pad('中文名', 12) . " | 级联重跑\n");
fwrite(STDOUT, str_repeat('-', 4 + 3 + 16 + 3 + 12 + 3 + 20) . "\n");
foreach ($order as $index => $code) {
    $cascade = StageDependency::CASCADE[$code] ?? [];
    fwrite(STDOUT, sprintf(
        "%s | %s | %s | %s\n",
        $pad((string) ($index + 1), 4),
        $pad($code, 16),
        $pad(StageSpec::label($code), 12),
        $cascade === [] ? '（链尾）' : implode(' · ', array_map(
            static fn (string $c): string => StageSpec::label($c),
            $cascade
        ))
    ));
}
foreach (StageSpec::RESERVED_LABELS as $code => $label) {
    fwrite(STDOUT, sprintf("%s | %s | %s | %s\n", $pad('—', 4), $pad($code, 16), $pad($label, 12), '预留，无 stage 记录'));
}

fwrite(STDOUT, sprintf(
    "\n阶段 %d 个 · 标签 %d 条 · 预留 %d 条 · 级联表 %d 行 · 模板 %d 个（用 stage_label %s / stage_options %s）\n",
    count($order),
    count($labels),
    count(StageSpec::RESERVED_LABELS),
    count(StageDependency::CASCADE),
    count($twigFiles),
    $usesStageLabel ? '是' : '否',
    $usesStageOptions ? '是' : '否'
));

if ($warns !== []) {
    fwrite(STDOUT, "\n提示：\n");
    foreach ($warns as $warn) {
        fwrite(STDOUT, '  · ' . $warn . "\n");
    }
}

if ($errors !== []) {
    fwrite(STDERR, "\n错误：\n");
    foreach ($errors as $error) {
        fwrite(STDERR, '  × ' . $error . "\n");
    }
    fwrite(STDERR, sprintf("\n共 %d 项错误。\n", count($errors)));
    exit(1);
}

fwrite(STDOUT, "\n口径一致：StageSpec::LABELS = StageSpec::order() = StageDependency::ORDER = CASCADE 键 = config/ai.php = 模板\n");
exit(0);
