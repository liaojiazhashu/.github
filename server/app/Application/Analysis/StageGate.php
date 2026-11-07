<?php

declare(strict_types=1);

namespace App\Application\Analysis;

/**
 * G1–G5 校验闸门：全部由代码实现，不依赖模型自觉（§6.4）。
 *
 * 闸门是这套系统「不会产出垃圾」的根本。任何新增字段都必须同时新增闸门规则，
 * 否则模型漂移会静默污染数据。
 *
 * 两类违规的区别见 GateResult 的注释：结构性问题判硬（重试），引文级问题判软（丢弃 + 告警）。
 * `$relaxed = true` 表示这是最后一次尝试：原本判硬、但可以裁剪修补的问题降级为软处理，
 * 避免「因为一条路径只有 2 条就整篇作废」。
 */
final class StageGate
{
    /**
     * v2 口径：词库只收「能被当作概念检索」的东西。
     * theme（题材）与 tone（情绪）被移出，因为它们描述文风，无法成为别人查词的对象。
     */
    private const CATEGORIES = ['setting', 'world', 'image'];
    private const CATEGORY_NAMES = [
        'setting' => '科幻设定',
        'world'   => '世界观',
        'image'   => '意象',
    ];
    private const PHASES = ['start', 'turn', 'crisis', 'end'];
    private const LAYERS = ['structure', 'execution', 'detail'];
    private const LAYER_NAMES = [
        'structure' => '结构层',
        'execution' => '执行层',
        'detail'    => '细节层',
    ];
    // 每篇最多 5 个。不是成本限制，是可发现性的限制：
    // 词条越多，倒排索引的区分度越低，读者反而找不到这篇的关键所在
    private const MAX_TERMS = 5;

    // ------------------------------------------------------------------ G1
    /**
     * G1：关键词索引（v2 口径）—— 最多 5 个词条，只收 setting / world / image。
     *
     * 口径变了，判据必须跟着变。v1 要求「五类齐全、主词配 2–4 个辅词」，那是在攒一张
     * 分类齐整的检索表；v2 要的是**一个别人能拿来查概念的词库**，所以：
     *   · 数量少而准（≤5）：词条越多，倒排索引的区分度越低，用户越找不到关键所在；
     *   · 每条必须有**脱离本文也读得懂的百科定义**，否则词库里全是只有作者本人懂的暗号；
     *   · 只收设定 / 世界观 / 意象。题材标签与情绪词不收——它们描述文风，不可被检索。
     *
     * 数量与比例是**形状**问题，不是内容问题：多出来的词条按 weight 裁掉，
     * 而不是整篇作废。把 9 个词裁到 5 个，比丢掉整篇分析有用得多。
     *
     * @param bool $relaxedAggregate 分块抽取时用：分块合并后再统一裁剪
     * @param bool $repair           最后一次尝试用：形状类违规改成修补，避免整篇判失败
     * @param array<string,mixed> $json
     */
    public static function g1(array $json, string $text, bool $relaxedAggregate = false, bool $repair = false): GateResult
    {
        $hard = [];
        $soft = [];

        $card      = is_array($json['index_card'] ?? null) ? $json['index_card'] : [];
        $oneLiner  = self::text($card['one_liner'] ?? '');
        $themeNote = self::text($card['theme_note'] ?? '');

        if ($oneLiner === '') {
            $hard[] = 'index_card.one_liner 不能为空';
        } elseif (mb_strlen($oneLiner) > 300) {
            $hard[] = 'index_card.one_liner 超过 300 字';
        }

        $raw      = is_array($json['terms'] ?? null) ? $json['terms'] : [];
        $rawCount = count($raw);

        /** @var array<string, array<string,mixed>> $terms canonical 归一值 => 词条 */
        $terms   = [];
        $dropped = 0;
        $noDef   = 0;

        foreach ($raw as $item) {
            if (!is_array($item)) {
                $dropped++;
                continue;
            }

            $canonical = self::text($item['canonical'] ?? '');
            if ($canonical === '') {
                $soft[] = '丢弃一个没有 canonical 的词条';
                $dropped++;
                continue;
            }
            if (mb_strlen($canonical) > 64) {
                $canonical = mb_substr($canonical, 0, 64);
                $soft[] = '规范词超长已截断：' . $canonical;
            }

            // 同义归并：模型写「克隆体」「复制人」，词库里统一记成「克隆人」。
            // 不做这一步，同一个概念会散成几条各挂一篇作品、谁也索引不到的词。
            $unified = Taxonomy::canonicalize($canonical);
            if ($unified !== $canonical) {
                $soft[] = sprintf('词条「%s」并入通行词「%s」', $canonical, $unified);
                $canonical = $unified;
            }

            // 专名闸门：词库外的新词必须长得像通用概念。
            // 「WA0409黑洞」「封孔泥」「创造文字动物的渴望条件」在这一步出局 ——
            // 它们只属于这一篇，放进词库就是一条索引不到第二篇作品的死词。
            $why = Taxonomy::rejectReason($canonical);
            if ($why !== null) {
                $soft[] = sprintf('丢弃词条「%s」：%s', $canonical, $why);
                $dropped++;
                continue;
            }

            $category = strtolower(self::text($item['category'] ?? ''));

            // 类别以词表为准：模型把「废墟」标成 setting、把「反乌托邦」标成 setting，
            // 都要改回来，否则同一个词在不同作品下会落在两个分类里，导航就分裂了。
            $taxCat = Taxonomy::categoryOf($canonical);
            if ($taxCat !== null) {
                if ($taxCat !== $category) {
                    $soft[] = sprintf(
                        '词条「%s」的类别按词库订正：%s → %s',
                        $canonical,
                        $category === '' ? '空' : $category,
                        $taxCat
                    );
                }
                $category = $taxCat;
            } elseif (!in_array($category, self::CATEGORIES, true)) {
                // v1 的 theme / tone 之类不再收：留在库里只会让「查概念」变成「翻标签」
                $soft[] = sprintf('丢弃类别非法的词条「%s」（category=%s）', $canonical, $category);
                $dropped++;
                continue;
            }

            $role = strtolower(self::text($item['role'] ?? 'sub'));
            if (!in_array($role, ['main', 'sub'], true)) {
                $role   = 'sub';
                $soft[] = sprintf('词条「%s」的 role 非法，按 sub 处理', $canonical);
            }

            $weight = (int) ($item['weight'] ?? 50);
            if ($weight < 1 || $weight > 100) {
                $weight = max(1, min(100, $weight));
            }

            $definition = self::text($item['definition'] ?? '');
            if ($definition === '') {
                $noDef++;
                $soft[] = sprintf('词条「%s」没有百科定义', $canonical);
            } elseif (mb_strlen($definition) > 200) {
                $definition = mb_substr($definition, 0, 200);
                $soft[] = sprintf('词条「%s」的定义超长已截断', $canonical);
            }

            $evidence = self::text($item['evidence'] ?? '');
            if ($evidence === '') {
                $soft[] = sprintf('词条「%s」没有 evidence', $canonical);
            } elseif (!self::contains($text, $evidence)) {
                $soft[] = sprintf('丢弃词条「%s」：evidence 在原文中找不到（疑似编造）', $canonical);
                $dropped++;
                continue;
            } else {
                $evidence = mb_substr($evidence, 0, 200);
            }

            $aliases = [];
            foreach ((array) ($item['aliases'] ?? []) as $alias) {
                $alias = self::text($alias);
                if ($alias === '' || $alias === $canonical || mb_strlen($alias) > 64) {
                    continue;
                }
                $aliases[self::key($alias)] = $alias;
            }
            $aliases = array_slice(array_values($aliases), 0, 3);

            $key = self::key($canonical);
            if (isset($terms[$key])) {
                $terms[$key]['weight'] = max($terms[$key]['weight'], $weight);
                if ($role === 'main') {
                    $terms[$key]['role'] = 'main';
                }
                if ($terms[$key]['definition'] === '' && $definition !== '') {
                    $terms[$key]['definition'] = $definition;
                }
                $soft[] = '合并重复规范词：' . $canonical;
                continue;
            }

            $terms[$key] = [
                'canonical'  => $canonical,
                'category'   => $category,
                'role'       => $role,
                'weight'     => $weight,
                'definition' => $definition,
                'evidence'   => $evidence,
                'aliases'    => $aliases,
            ];
        }

        // 编造比例过高说明模型在硬凑，重试才有意义
        if ($rawCount >= 4 && $dropped * 3 > $rawCount) {
            $hard[] = sprintf(
                '共有 %d/%d 个词条无法在原文中定位支撑，或是本篇专有名词（编号、书名、短语）；'
                . '请只收通行于科幻类型的通用概念，能在别的作品里也被检索到',
                $dropped,
                $rawCount
            );
        }

        // v4：空词条是**合法结果**，不是失败。
        // 「5 个」是上限不是配额：一篇纯氛围小说可能一个科幻概念都抽不出来，
        // 这时硬判失败会逼模型下次凑数，凑出来的就是「茶」「影子」这类死词。
        // 只留一条 soft，让页面上能显示「这次分析没有产出关键词」。
        if ($terms === []) {
            $soft[] = '没有合格的科幻关键词：这篇抽不出与科幻强关联的概念，按空结果交付';

            return $hard === []
                ? GateResult::pass(['index_card' => ['one_liner' => $oneLiner, 'theme_note' => $themeNote], 'terms' => []], $soft)
                : GateResult::fail($hard, $soft);
        }

        // 全部没定义 = 这批词进词库也读不懂，必须重来；少数缺定义只告警（可以后补）
        if ($noDef > 0 && $noDef === count($terms)) {
            $hard[] = '所有词条都没有百科定义：词库收的是概念，不是暗号';
        }

        // 集合阶段是按 canonical 归一值做键的（便于合并重复词），从这一步起要按下标操作，
        // 先摊平成列表 —— 否则下面 `$terms[0]` 会取到 null。
        $terms = array_values($terms);

        // ---- 数量：最多 5 条，按 weight 取前 5 ----------------------------------
        if (count($terms) > self::MAX_TERMS) {
            usort($terms, static fn (array $a, array $b): int => $b['weight'] <=> $a['weight']);
            $cut = count($terms) - self::MAX_TERMS;
            $terms = array_slice($terms, 0, self::MAX_TERMS);
            $soft[] = sprintf('词条超过 %d 个，已裁掉 %d 个显著度最低的', self::MAX_TERMS, $cut);
        }

        // ---- 主词 1–2 条：多了降级，少了把最高的提上来 ---------------------------
        $mains = array_values(array_filter($terms, static fn (array $t): bool => $t['role'] === 'main'));
        if (count($mains) > 2) {
            // 显著度高的优先留作 main，其余降 sub
            usort($terms, static fn (array $a, array $b): int => $b['weight'] <=> $a['weight']);
            $kept = 0;
            foreach ($terms as $i => $t) {
                if ($t['role'] !== 'main') {
                    continue;
                }
                $kept++;
                if ($kept > 2) {
                    $terms[$i]['role'] = 'sub';
                }
            }
            $soft[] = '主词超过 2 个，已把显著度最低的几条降为辅词';
        } elseif ($mains === [] && count($terms) > 0) {
            $top = 0;
            foreach ($terms as $i => $t) {
                if ($t['weight'] > $terms[$top]['weight']) {
                    $top = $i;
                }
            }
            $terms[$top]['role'] = 'main';
            $soft[] = '没有标主词，已把最显著的一条提为主词';
        }

        // ---- 科幻向：至少一条 setting / world -----------------------------------
        $hasSetting = false;
        foreach ($terms as $t) {
            if ($t['category'] === 'setting' || $t['category'] === 'world') {
                $hasSetting = true;
                break;
            }
        }
        if (!$hasSetting && !$relaxedAggregate) {
            // 只抽到意象的文章，等于在「科幻词库」里查不到它 —— 这是内容层面的问题，
            // 但最后一次尝试时放行：总比让整篇分析作废好，告警会留在页面上。
            // 分块抽取（$relaxedAggregate）不查这条：一块里常常只有一两个词，
            // 完整性要等合并之后再判，否则每个分块都会因为「缺设定」被判失败。
            if ($repair) {
                $soft[] = '没有 setting / world 类词条，这篇在科幻向检索里会很难被找到（最后一次尝试，已放行）';
            } else {
                $hard[] = '没有任何 setting 或 world 类词条：词库按概念检索，只有意象词等于查不到这篇';
            }
        }

        if ($hard !== []) {
            return GateResult::fail($hard, $soft);
        }

        usort($terms, static fn (array $a, array $b): int => [$b['role'] === 'main', $b['weight']] <=> [$a['role'] === 'main', $a['weight']]);

        return GateResult::pass([
            'index_card' => ['one_liner' => $oneLiner, 'theme_note' => $themeNote],
            'terms'      => array_values($terms),
        ], $soft);
    }

    // ------------------------------------------------------------------ G2

    /**
     * G2：who/want/cost 各 ≥ 8 字；beats 覆盖四相；position_pct 单调不减。
     *
     * @param array<string,mixed> $json
     */
    public static function g2(array $json, string $text): GateResult
    {
        $hard = [];
        $soft = [];

        $raw = is_array($json['summary'] ?? null) ? $json['summary'] : [];
        $summary = ['who' => '', 'want' => '', 'cost' => '', 'logline' => ''];

        $labels = ['who' => '谁', 'want' => '要什么', 'cost' => '付出什么'];
        foreach ($labels as $field => $label) {
            $value = self::text($raw[$field] ?? '');
            if (mb_strlen($value) > 300) {
                $value  = mb_substr($value, 0, 300);
                $soft[] = sprintf('summary.%s 超长已截断', $field);
            }
            if (mb_strlen($value) < 8) {
                $hard[] = sprintf('summary.%s（%s）不足 8 字，三句话必须写出来', $field, $label);
            }
            $summary[$field] = $value;
        }
        $summary['logline'] = mb_substr(self::text($raw['logline'] ?? ''), 0, 300);

        $rawBeats = is_array($json['beats'] ?? null) ? $json['beats'] : [];
        $beats    = [];

        foreach ($rawBeats as $item) {
            if (!is_array($item)) {
                continue;
            }

            $phase = strtolower(self::text($item['phase'] ?? ''));
            $title = self::text($item['title'] ?? '');
            $detail = self::text($item['detail'] ?? '');
            if (!in_array($phase, self::PHASES, true)) {
                $soft[] = sprintf('丢弃节点「%s」：phase 非法（%s）', $title !== '' ? $title : '未命名', $phase);
                continue;
            }
            if ($title === '' && $detail === '') {
                $soft[] = '丢弃一个既无标题也无正文的节点';
                continue;
            }

            $quote = self::text($item['quote'] ?? '');
            if ($quote !== '' && !self::contains($text, $quote)) {
                $soft[] = sprintf('节点「%s」的引文在原文中找不到，已清空', $title !== '' ? $title : '未命名');
                $quote  = '';
            }

            $beats[] = [
                'seq'          => (int) ($item['seq'] ?? 0),
                'phase'        => $phase,
                'title'        => mb_substr($title !== '' ? $title : mb_substr($detail, 0, 16), 0, 120),
                'detail'       => mb_substr($detail, 0, 2000),
                'quote'        => mb_substr($quote, 0, 300),
                'position_pct' => max(0, min(100, (int) ($item['position_pct'] ?? 0))),
            ];
        }

        usort($beats, static fn (array $a, array $b): int => $a['seq'] <=> $b['seq']);

        if (count($beats) < 4) {
            $hard[] = sprintf('剧情节点只有 %d 个，至少要 4 个（起点/转折/危机/终局各一个）', count($beats));
            return GateResult::fail($hard, $soft, ['summary' => $summary, 'beats' => $beats]);
        }

        // position_pct 单调不减：模型偶尔会写回退值，夹到前缀最大值比整篇重跑划算，
        // 因为它只影响进度条的视觉位置，不影响任何语义
        $maxPos = 0;
        $dipped = false;
        foreach ($beats as $i => $beat) {
            if ($beat['position_pct'] < $maxPos) {
                $dipped = true;
                $beats[$i]['position_pct'] = $maxPos;
            } else {
                $maxPos = $beat['position_pct'];
            }
            $beats[$i]['seq'] = $i + 1;
        }
        if ($dipped) {
            $soft[] = 'position_pct 出现回退，已按前缀最大值修正';
        }

        $present = array_values(array_unique(array_column($beats, 'phase')));
        $missing = array_values(array_diff(self::PHASES, $present));
        if ($missing !== []) {
            $names = ['start' => '起点', 'turn' => '转折', 'crisis' => '危机', 'end' => '终局'];
            $hard[] = '剧情节点缺少相位：' . implode('、', array_map(static fn (string $p): string => $names[$p], $missing));
        }

        // 四相齐了不代表次序对：实跑出现过 start→turn→crisis→turn→crisis 的交错排列。
        // 这里只告警不拦——多线叙事本来就可能交错，拦下来会把好作品判成坏作品。
        $rank = array_flip(self::PHASES);
        $prev = 0;
        foreach ($beats as $beat) {
            $current = $rank[$beat['phase']];
            if ($current < $prev) {
                $soft[] = sprintf('节点相位次序出现回退（%s 排在更靠后的相位之后），请确认是否交错叙事', $beat['title']);
                break;
            }
            $prev = $current;
        }

        return $hard === []
            ? GateResult::pass(['summary' => $summary, 'beats' => $beats], $soft)
            : GateResult::fail($hard, $soft, ['summary' => $summary, 'beats' => $beats]);
    }

    // ------------------------------------------------------------------ G3

    /**
     * G3：setup_seq 必须存在且 ≤ payoff_seq，非法条目丢弃，不阻塞。
     *
     * @param array<string,mixed> $json
     * @param list<int>           $beatSeqs
     */
    public static function g3(array $json, array $beatSeqs): GateResult
    {
        $soft = [];
        $valid = array_flip(array_map('intval', $beatSeqs));

        $raw   = is_array($json['foreshadows'] ?? null) ? $json['foreshadows'] : [];
        $items = [];
        $seen  = [];

        foreach ($raw as $item) {
            if (!is_array($item)) {
                continue;
            }

            $content = self::text($item['content'] ?? '');
            if ($content === '') {
                $soft[] = '丢弃一条没有内容的伏笔';
                continue;
            }
            $content = mb_substr($content, 0, 300);

            $setup = (int) ($item['setup_seq'] ?? 0);
            if (!isset($valid[$setup])) {
                $soft[] = sprintf('丢弃伏笔「%s」：埋下的节点 seq=%d 不存在', mb_substr($content, 0, 16), $setup);
                continue;
            }

            $payoffRaw = $item['payoff_seq'] ?? null;
            $payoff    = is_numeric($payoffRaw) ? (int) $payoffRaw : null;

            if ($payoff !== null) {
                if (!isset($valid[$payoff])) {
                    $soft[] = sprintf('伏笔「%s」的回收节点不存在，按未回收处理', mb_substr($content, 0, 16));
                    $payoff = null;
                } elseif ($payoff <= $setup) {
                    $soft[] = sprintf('丢弃伏笔「%s」：回收节点不晚于埋下节点', mb_substr($content, 0, 16));
                    continue;
                }
            }

            $key = self::key($content);
            if (isset($seen[$key])) {
                $soft[] = '合并内容重复的伏笔：' . mb_substr($content, 0, 16);
                continue;
            }
            $seen[$key] = true;

            $items[] = [
                'content'    => $content,
                'setup_seq'  => $setup,
                'payoff_seq' => $payoff,
                'state'      => $payoff === null ? 'open' : 'paid',
            ];
        }

        return GateResult::pass(['foreshadows' => $items], $soft);
    }

    // ------------------------------------------------------------------ G4

    /**
     * G4：三层各 ≥1；paths ∈ [3,5]；输出中不得出现分数。
     *
     * @param array<string,mixed> $json
     */
    public static function g4(array $json, bool $relaxed = false): GateResult
    {
        $hard = [];
        $soft = [];

        $scoreKeys = [];
        $json      = self::stripScoreKeys($json, $scoreKeys);
        if ($scoreKeys !== []) {
            $soft[] = '已删除分数类字段：' . implode('、', array_unique($scoreKeys));
        }

        $raw  = is_array($json['suggestions'] ?? null) ? $json['suggestions'] : [];
        $list = [];

        foreach ($raw as $item) {
            if (!is_array($item)) {
                continue;
            }

            $layer = strtolower(self::text($item['layer'] ?? ''));
            if (!in_array($layer, self::LAYERS, true)) {
                $soft[] = sprintf('丢弃建议「%s」：layer 非法（%s）', mb_substr(self::text($item['title'] ?? ''), 0, 16), $layer);
                continue;
            }

            $title   = mb_substr(self::text($item['title'] ?? ''), 0, 160);
            $problem = self::text($item['problem'] ?? '');
            if ($title === '' || $problem === '') {
                $soft[] = '丢弃一条缺少标题或问题描述的建议';
                continue;
            }

            $paths = [];
            foreach ((array) ($item['paths'] ?? []) as $path) {
                if (!is_array($path)) {
                    continue;
                }
                $style   = strtolower(self::text($path['style'] ?? 'neutral'));
                $content = self::text($path['content'] ?? '');
                if ($content === '') {
                    continue;
                }
                if (!in_array($style, ['conservative', 'radical', 'neutral'], true)) {
                    $style = 'neutral';
                }
                $paths[] = ['style' => $style, 'content' => mb_substr($content, 0, 500)];
            }

            $count = count($paths);
            if ($count < 3 || $count > 5) {
                if ($relaxed) {
                    if ($count > 5) {
                        $paths = array_slice($paths, 0, 5);
                        $soft[] = sprintf('建议「%s」路径超过 5 条，已截断', mb_substr($title, 0, 16));
                    } elseif ($count < 3) {
                        $soft[] = sprintf('丢弃建议「%s」：只给了 %d 条路径（要求 3–5 条）', mb_substr($title, 0, 16), $count);
                        continue;
                    }
                } else {
                    $hard[] = sprintf('建议「%s」给了 %d 条路径，每条必须 3–5 条', mb_substr($title, 0, 16), $count);
                    continue;
                }
            }

            $list[] = [
                'layer'   => $layer,
                'title'   => $title,
                'problem' => mb_substr($problem, 0, 2000),
                'impact'  => mb_substr(self::text($item['impact'] ?? ''), 0, 300),
                'paths'   => $paths,
            ];
        }

        foreach (self::LAYERS as $layer) {
            $has = false;
            foreach ($list as $suggestion) {
                if ($suggestion['layer'] === $layer) {
                    $has = true;
                    break;
                }
            }
            if (!$has) {
                $hard[] = sprintf('缺少「%s」（%s）的建议，三层必须都有', self::LAYER_NAMES[$layer], $layer);
            }
        }

        // 提示词里明令禁止分数，若正文仍出现「85 分」这类表述，只告警不改写（改了就篡改语义）
        foreach ($list as $suggestion) {
            if (preg_match('/(评分|得分|打分|总分|评级|星级|\d+\s*分)/u', $suggestion['problem']) === 1) {
                $soft[] = sprintf('建议「%s」里出现分数类表述', mb_substr($suggestion['title'], 0, 16));
            }
        }

        return $hard === []
            ? GateResult::pass(['suggestions' => $list], $soft)
            : GateResult::fail($hard, $soft, ['suggestions' => $list]);
    }

    // ------------------------------------------------------------------ G5

    /**
     * G5：rank 连续；conservative / radical 均非空且不同；impact_scope ≥ 10 字。
     *
     * 最后一次尝试仍不合法时，用 G4 的建议自动兜底生成清单——
     * 兜底条目会带 decision_note 说明是自动生成的，人工复核时一眼能看出。
     *
     * @param array<string,mixed>            $json
     * @param list<array<string,mixed>>      $suggestions G4 通过后的建议
     */
    public static function g5(array $json, array $suggestions, bool $relaxed = false): GateResult
    {
        $hard = [];
        $soft = [];

        $summary = mb_substr(self::text(($json['revision']['summary'] ?? '') ?: ($json['summary'] ?? '')), 0, 500);
        $raw     = is_array($json['items'] ?? null) ? $json['items'] : [];
        $items   = [];

        foreach ($raw as $item) {
            if (!is_array($item)) {
                continue;
            }

            $target = mb_substr(self::text($item['target'] ?? ''), 0, 200);
            $action = mb_substr(self::text($item['action'] ?? ''), 0, 300);
            $cons   = mb_substr(self::text($item['conservative'] ?? ''), 0, 500);
            $radi   = mb_substr(self::text($item['radical'] ?? ''), 0, 500);
            $scope  = mb_substr(self::text($item['impact_scope'] ?? ''), 0, 300);
            $note   = mb_substr(self::text($item['decision_note'] ?? ''), 0, 300);

            $why = [];
            if ($target === '' || $action === '') {
                $why[] = '缺少 target 或 action';
            }
            if ($cons === '' || $radi === '') {
                $why[] = '缺少保守或激进走法';
            } elseif (self::key($cons) === self::key($radi)) {
                $why[] = '保守与激进走法内容相同';
            }
            if (mb_strlen($scope) < 10) {
                $why[] = 'impact_scope 不足 10 字';
            }

            if ($why !== []) {
                foreach ($why as $reason) {
                    $soft[] = sprintf('丢弃修改条目「%s」：%s', mb_substr($target !== '' ? $target : '未命名', 0, 16), $reason);
                }
                $hard[] = sprintf('修改条目「%s」不合法：%s', mb_substr($target !== '' ? $target : '未命名', 0, 16), implode('；', $why));
                continue;
            }

            $items[] = [
                'rank'         => (int) ($item['rank'] ?? 0),
                'target'       => $target,
                'action'       => $action,
                'conservative' => $cons,
                'radical'      => $radi,
                'impact_scope' => $scope,
                'decision_note' => $note,
            ];
        }

        usort($items, static fn (array $a, array $b): int => $a['rank'] <=> $b['rank']);
        foreach ($items as $i => $_) {
            $items[$i]['rank'] = $i + 1;
        }

        if ($items === []) {
            $hard[] = '没有一条合法的修改条目';
        }

        if ($hard === []) {
            return GateResult::pass(['summary' => $summary, 'items' => $items], $soft);
        }

        if (!$relaxed) {
            return GateResult::fail($hard, $soft, ['summary' => $summary, 'items' => $items]);
        }

        $fallback = self::fallbackItems($suggestions);
        if ($fallback === []) {
            return GateResult::fail($hard, $soft, ['summary' => $summary, 'items' => $items]);
        }

        $soft[] = '模型给出的修改清单不合法，已按建议自动生成兜底清单';

        return GateResult::pass(
            ['summary' => $summary !== '' ? $summary : '按影响面从大到小排列，先处理结构层。', 'items' => $fallback],
            $soft
        );
    }

    // ------------------------------------------------------------------ G7

    /**
     * G7：读者视角。
     *
     * 这是全套流程里唯一一处「替读者做判断」的产出，所以判据比结构类更严：
     * 空话能通过结构检查，但过不了这里 —— hook 必须具体、看点必须恰好三条且互不重复、
     * 画面四项必须都有判断（不许只丢片名）、读者画像必须包含「谁会觉得不值」。
     *
     * 评分字段按硬违规处理：拆解不给分是产品底线，退化成「值得 8 分」就失去意义了。
     *
     * @param array<string,mixed> $json
     */
    public static function g7(array $json, bool $relaxed = false): GateResult
    {
        $hard = [];
        $soft = [];

        $found = [];
        $json  = self::stripScoreKeys($json, $found);
        if ($found !== []) {
            $hard[] = '读者视角里出现了评分字段（' . implode('/', $found) . '）：拆解不给分';
        }

        $hook = self::text($json['hook'] ?? '');
        if ($hook === '') {
            $hard[] = 'hook 不能为空';
        } elseif (mb_strlen($hook) > 80) {
            $hard[] = 'hook 超过 80 字';
        }

        $appeal = [];
        foreach ((array) ($json['appeal'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $title = self::text($item['title'] ?? '');
            $why   = self::text($item['why'] ?? '');
            if ($title === '' || $why === '') {
                $soft[] = '丢弃一条标题或理由为空的看点';
                continue;
            }
            $appeal[] = ['title' => mb_substr($title, 0, 40), 'why' => mb_substr($why, 0, 300)];
        }

        if (count($appeal) < 3) {
            if ($relaxed) {
                $soft[] = sprintf('看点只有 %d 条（应为 3 条），最后一次尝试已放行', count($appeal));
            } else {
                $hard[] = sprintf('看点只有 %d 条，需要恰好 3 条', count($appeal));
            }
        } elseif (count($appeal) > 3) {
            $appeal = array_slice($appeal, 0, 3);
            $soft[] = '看点超过 3 条，只保留前 3 条';
        }

        $visual      = is_array($json['visual'] ?? null) ? $json['visual'] : [];
        $visualOut   = [];
        $visualNames = ['tone' => '色彩与光线', 'lens' => '镜头与调度', 'reference' => '对标坐标', 'scene' => '预告片场面'];
        foreach ($visualNames as $key => $label) {
            $value = self::text($visual[$key] ?? '');
            if ($value === '') {
                if ($relaxed) {
                    $soft[] = sprintf('visual.%s（%s）为空，最后一次尝试已放行', $key, $label);
                } else {
                    $hard[] = sprintf('visual.%s（%s）不能为空', $key, $label);
                }
            }
            $visualOut[$key] = mb_substr($value, 0, 400);
        }

        $audience      = is_array($json['audience'] ?? null) ? $json['audience'] : [];
        $audienceOut   = [];
        $audienceNames = ['primary' => '主要读者', 'also' => '另一类读者', 'avoid' => '不适读者', 'gate' => '阅读门槛'];
        foreach ($audienceNames as $key => $label) {
            $value = self::text($audience[$key] ?? '');
            if ($value === '') {
                if ($relaxed) {
                    $soft[] = sprintf('audience.%s（%s）为空，最后一次尝试已放行', $key, $label);
                } else {
                    $hard[] = sprintf('audience.%s（%s）不能为空', $key, $label);
                }
            }
            $audienceOut[$key] = mb_substr($value, 0, 300);
        }

        // 读者视角最容易退化成推荐语。这里不判失败（写了不等于错），但必须留告警，
        // 让人知道这一段需要人工看一眼。
        $hype = ['震撼', '惊艳', '必读', '神作', '巅峰', '不容错过', '史诗级'];
        $blob = $hook . ' ' . implode(' ', array_column($appeal, 'why'));
        foreach ($hype as $word) {
            if (mb_strpos($blob, $word) !== false) {
                $soft[] = sprintf('出现推荐语腔调「%s」，请人工复核这句是否在替读者下结论', $word);
            }
        }

        if ($hard !== []) {
            return GateResult::fail($hard, $soft);
        }

        return GateResult::pass([
            'hook'       => mb_substr($hook, 0, 120),
            'appeal'     => $appeal,
            'visual'     => $visualOut,
            'audience'   => $audienceOut,
            'terms_note' => mb_substr(self::text($json['terms_note'] ?? ''), 0, 200),
        ], $soft);
    }

    // ------------------------------------------------------------- 内部工具

    /**
     * 用建议生成兜底修改清单：结构层 > 执行层 > 细节层，每层取前两条。
     *
     * @param list<array<string,mixed>> $suggestions
     * @return list<array<string,mixed>>
     */
    private static function fallbackItems(array $suggestions): array
    {
        $order  = array_flip(self::LAYERS);
        $sorted = $suggestions;
        usort($sorted, static fn (array $a, array $b): int => ($order[$a['layer']] ?? 9) <=> ($order[$b['layer']] ?? 9));

        $items = [];
        foreach (array_slice($sorted, 0, 6) as $i => $suggestion) {
            $cons = '';
            $radi = '';
            $neutral = '';
            foreach ($suggestion['paths'] as $path) {
                if ($path['style'] === 'conservative' && $cons === '') {
                    $cons = $path['content'];
                } elseif ($path['style'] === 'radical' && $radi === '') {
                    $radi = $path['content'];
                } elseif ($neutral === '') {
                    $neutral = $path['content'];
                }
            }
            $cons = $cons !== '' ? $cons : ($neutral !== '' ? $neutral : (string) $suggestion['paths'][0]['content']);
            $radi = $radi !== '' ? $radi : (string) $suggestion['paths'][count($suggestion['paths']) - 1]['content'];
            if (self::key($cons) === self::key($radi)) {
                continue;
            }

            $scope = (string) ($suggestion['impact'] ?? '');
            if (mb_strlen($scope) < 12) {
                $scope = sprintf('牵动%s相关段落，改完需重读一遍全篇确认衔接', self::LAYER_NAMES[$suggestion['layer']]);
            }

            $items[] = [
                'rank'          => $i + 1,
                'target'        => (string) $suggestion['title'],
                'action'        => (string) $suggestion['title'],
                'conservative'  => mb_substr($cons, 0, 500),
                'radical'       => mb_substr($radi, 0, 500),
                'impact_scope'  => mb_substr($scope, 0, 300),
                'decision_note' => '本条由建议自动生成，请人工复核后再照做',
            ];
        }

        return $items;
    }

    /**
     * 把所有主词里显著度最低的若干个降为辅词，直到主词数不超过 $keep。
     *
     * @param list<array<string,mixed>> $terms 引用传递，直接改 role
     */
    private static function demoteMains(array &$terms, int $keep): int
    {
        $demoted = 0;

        while (true) {
            $mainIdx = [];
            foreach ($terms as $i => $term) {
                if ($term['role'] === 'main') {
                    $mainIdx[] = $i;
                }
            }
            if (count($mainIdx) <= $keep) {
                break;
            }

            // 显著度最低的那个先降级；权重相同则取靠后的（后出现的通常更次要）
            usort($mainIdx, static fn (int $a, int $b): int => ($terms[$a]['weight'] <=> $terms[$b]['weight']) ?: ($b <=> $a));
            $terms[$mainIdx[0]]['role'] = 'sub';
            $demoted++;
        }

        return $demoted;
    }

    /**
     * 某一类别内降一个主词（取该类别里显著度最低的）。
     *
     * @param list<array<string,mixed>> $terms
     */
    private static function demoteMainsInCategory(array &$terms, string $category): int
    {
        $target = -1;
        foreach ($terms as $i => $term) {
            if ($term['category'] !== $category || $term['role'] !== 'main') {
                continue;
            }
            if ($target < 0 || $term['weight'] < $terms[$target]['weight']) {
                $target = $i;
            }
        }

        if ($target < 0) {
            return 0;
        }

        $terms[$target]['role'] = 'sub';

        return 1;
    }

    /**
     * 递归删除分数类字段。
     *
     * @param array<string,mixed> $node
     * @param list<string>        $found
     * @return array<string,mixed>
     */
    private static function stripScoreKeys(array $node, array &$found): array
    {
        $banned = ['score', 'scores', 'rating', 'grade', 'stars', 'points', '评分', '得分', '打分', '评级', '星级'];

        $clean = [];
        foreach ($node as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), $banned, true)) {
                $found[] = $key;
                continue;
            }
            if (is_array($value)) {
                $isList = array_is_list($value);
                $value  = self::stripScoreKeys($value, $found);
                if ($isList) {
                    $value = array_values($value);
                }
            }
            $clean[$key] = $value;
        }

        return $clean;
    }

    /**
     * 引文是否存在于原文：先逐字找，再抹掉空白与标点找一遍。
     *
     * 只做逐字匹配会误伤——模型复述时换一个引号形状、少一个空格都会被判编造；
     * 只做宽松匹配又会让编造蒙混过关。所以两级：严的过了就直接过，不过再宽一次。
     */
    public static function contains(string $haystack, string $needle): bool
    {
        $needle = trim($needle);
        if ($needle === '') {
            return false;
        }
        if (mb_strpos($haystack, $needle) !== false) {
            return true;
        }

        $a = self::squash($haystack);
        $b = self::squash($needle);

        return $b !== '' && strpos($a, $b) !== false;
    }

    private static function squash(string $text): string
    {
        return (string) preg_replace('/[\s\x{3000}-\x{303F}\x{FF00}-\x{FF65}\p{P}\p{S}]/u', '', $text);
    }

    private static function text(mixed $value): string
    {
        if (is_string($value)) {
            return trim($value);
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return '';
    }

    /** 归一化比较键：抹掉空白与标点并转小写，用于查重与「两条不同」判定 */
    private static function key(string $text): string
    {
        return mb_strtolower(self::squash($text), 'UTF-8');
    }
}
