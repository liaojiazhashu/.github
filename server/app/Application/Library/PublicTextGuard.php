<?php

declare(strict_types=1);

namespace App\Application\Library;

use PDO;

/**
 * 公开文本守卫：把「在馆里已经没有实体的词」从一切将要输出的文本里抹掉。
 *
 * ## 什么叫「没有实体」
 *
 * 判断标准不是「后台有没有点过隐藏」，而是**这个词在公开区还活不活得下去**：
 *
 *   1. 词条自己被隐藏（`hq_index_term.visibility = 'hidden'`）；
 *   2. 词条没被点名，但引用它的拆解全被隐藏了 —— 词条页已经 404、词条列表里
 *      也没有它、计数不算它。它事实上已经不存在，只是 `visibility` 字段还没改。
 *
 * 第 2 条是拆解级隐藏的直接后果：藏掉一篇拆解，只被它用过的词就失去了全部出处。
 * 这种词如果还出现在**别的**文章正文里（模型写叙述句时爱把词条名写进去），
 * 读者就会在没有任何入口的情况下看到它 —— 等于告诉人「馆里有过这个话题」。
 *
 * 反过来说，**从未被任何拆解引用过的词表词**不在遮蔽集合里：它们从来没在馆内
 * 露过面，正文提到它们不构成泄漏，而它们往往是「飞船」这类太通用的说法，
 * 纳进来会把全站正文打成星号。
 *
 * ## 为什么 SQL 过滤不够
 *
 * 可见性收口在 LibraryRepository 的 JOIN 常量里，挡掉的是**结构化引用**：
 * 关联表、计数、列表、检索、sitemap。但拆解产物存的是**自由文本** ——
 * 一句话索引、主题说明、情节摘要、节点细节、伏笔正文、修改建议、读者看点、
 * 术语说明。这些是模型读完小说写出来的叙述，它会把词条名写进句子里
 * （"串起冷冻休眠、世代飞船与人工生态圈"）。这些字不在 hq_work_term 里，
 * SQL 根本看不见它，只改查询条件，页面上照旧出现。
 *
 * ## 为什么是遮蔽而不是整段删除
 *
 * 整段删除会让文本断裂（一句话索引删掉就空了）、甚至整页没有可读内容。
 * 遮蔽保留上下文，只让那个词不再出现 —— 读者看到 ＊＊＊＊ 就知道此处有内容
 * 被隐去，这比假装没有更诚实，信息也确实没有泄漏。
 *
 * 注意：**被隐藏的拆解本身不需要这一层**。它不会渲染成任何页面（详情页 404，
 * 列表里也没有它），产物根本没有机会进模板。这一层管的是「别的文章的正文」。
 *
 * ## 三条边界（都无法机械化，必须写明）
 *
 * 1. **作品标题不遮**。词条叫「蒲公英」，作品也叫《蒲公英》，是同名巧合而不是
 *    泄漏。因为隐藏一个词条就把作品改名，读者会找不到这篇。所以与任何可见作品
 *    标题完全相同的词形，退出遮蔽集合。
 * 2. **单字词形不遮**。会遮到任何句子里偶然的同字，噪声大于信号。
 * 3. **别名照遮**。别名是「归并到该词条名下的其他叫法」，正文里写「冷冻仓飞船」
 *    和写「世代飞船」指的是同一件事，只遮标准名等于没遮。但同样受边界 1 约束。
 */
final class PublicTextGuard
{
    /** 遮蔽符：全角星号。等宽、字体必然支持、语义明确（此处被打码） */
    public const MASK = '＊';

    /**
     * 「这个词在公开区还有出处吗」—— 与 LibraryRepository::TERM_LIVE 同一口径。
     *
     * 常量不能传参，而这里要嵌进两段查询的 WHERE 里，所以单独写一份。两处都
     * 以 `hq_index_term t` 为外层别名，改的时候必须两边一起改。
     */
    private const LIVE_TERM_SQL = "EXISTS (
        SELECT 1 FROM hq_work_term wtl
          JOIN hq_work wl
            ON wl.id = wtl.work_id
           AND wl.status = 'published'
           AND wl.deleted_at IS NULL
           AND wl.visibility = 'visible'
           AND wl.current_analysis_id = wtl.analysis_id
          JOIN hq_analysis al
            ON al.id = wtl.analysis_id
           AND al.status = 'published'
           AND al.visibility = 'visible'
         WHERE wtl.term_id = t.id)";

    /**
     * 需要遮蔽的词形，按长度倒序 —— 长词先替换，否则「世代飞船项目」
     * 会被「世代飞船」先切碎，留下一个孤零零的「项目」。
     *
     * @var list<string>
     */
    private array $forms = [];

    /** @var array<string, true> 隐藏词条的标准名（精确匹配，用于剔除产物里的词条条目） */
    private array $hiddenCanonicals = [];

    /**
     * 豁免词：其他**仍然可见**的对象的正式名称（作品标题、可见词条的标准名）。
     *
     * 场景：隐藏了「世代飞船」，而「冷冻仓世代飞船」是另一条可见词条。前者是后者的
     * 子串，按字面遮蔽会把后者打成「冷冻仓＊＊＊＊」—— 一条还挂着的词条，名字被截断了。
     * 规则是**可见的名称优先**：落在这些名称区间内的匹配一律不动。
     *
     * 只保留「确实包含某个待遮词形」的名称，避免每次比较几百个无关字符串。
     *
     * @var list<string>
     */
    private array $exempts = [];

    /**
     * 从库里装配（生产用）。
     *
     * 构造刻意分成「取数」与「算规则」两步：规则里有好几条边界（同名作品、
     * 可见词条名、单字词形），这些是纯逻辑，不该为了测它们去连一个数据库。
     */
    public static function fromDatabase(PDO $pdo): self
    {
        // 显式隐藏的词条 ∪「因某篇已发布拆解被隐藏而失去全部出处」的词条。
        //
        // 第二个条件必须同时满足两件事，缺一就会误伤：
        //   · 它曾经在一篇**已发布**的拆解里出现过（否则只是词表里的备用词、
        //     或某篇失败/未发布分析的产物 —— 它们从没在馆内露过面）；
        //   · 那篇已发布的拆解现在被隐藏了（这正是「藏一篇拆解，连它的关键词一起
        //     消失」这个动作本身）；
        //   · 并且它已经没有任何可见出处（否则别的文章还在用这个词，词条页还在，
        //     遮它等于把别人的正文也改了）。
        //
        // 只写「现在没有出处」是不够的：实测库里就有 4 个词条只挂在失败或
        // 未发布的分析上（「警报」「仿生人」这类），它们本来就不在公开区，
        // 按「没出处」判会把它们从别人的正文里抹掉 —— 过度遮蔽比漏遮更难发现。
        $hidden = $pdo->query(
            "SELECT t.id, t.canonical
               FROM hq_index_term t
              WHERE t.visibility = 'hidden'
                 OR (EXISTS (
                        SELECT 1 FROM hq_work_term wh
                          JOIN hq_analysis ah
                            ON ah.id = wh.analysis_id
                           AND ah.status = 'published'
                           AND ah.visibility = 'hidden'
                         WHERE wh.term_id = t.id)
                     AND NOT " . self::LIVE_TERM_SQL . ")"
        )->fetchAll(PDO::FETCH_ASSOC);

        if ($hidden === []) {
            return new self([], [], []);
        }

        $ids = array_map(static fn (array $r): int => (int) $r['id'], $hidden);
        $in  = implode(',', array_fill(0, count($ids), '?'));

        $aliasStmt = $pdo->prepare("SELECT alias FROM hq_index_term_alias WHERE term_id IN ($in)");
        $aliasStmt->execute($ids);

        // 豁免词只收「仍然活着」的名称。不能直接按 visibility='visible' 取 ——
        // 那会把上面刚判死的词条名收进来，于是它自己给自己豁免，遮蔽集合被
        // 清空（完全同名 → 整个词形退出），整套规则静默失效。
        // 作品标题同理：没有可见拆解的作品在公开区已经不存在，不该再享有豁免权。
        $visibleNames = $pdo->query(
            "SELECT t.canonical AS name
               FROM hq_index_term t
              WHERE t.visibility = 'visible' AND " . self::LIVE_TERM_SQL . "
             UNION
             SELECT w.title AS name
               FROM hq_work w
              WHERE w.visibility = 'visible'
                AND w.deleted_at IS NULL
                AND w.status = 'published'
                AND EXISTS (SELECT 1 FROM hq_analysis al
                             WHERE al.id = w.current_analysis_id
                               AND al.status = 'published'
                               AND al.visibility = 'visible')"
        )->fetchAll(PDO::FETCH_COLUMN);

        return new self(
            $hidden,
            $aliasStmt->fetchAll(PDO::FETCH_COLUMN),
            $visibleNames
        );
    }

    /**
     * @param list<array{id:int|string, canonical:string}> $hiddenTerms 隐藏词条
     * @param list<string> $aliases 这些隐藏词条名下的全部别名（已归并）
     * @param list<string> $visibleNames 仍然公开的名称：可见词条的标准名 + 可见作品标题
     */
    public function __construct(array $hiddenTerms, array $aliases, array $visibleNames)
    {
        if ($hiddenTerms === []) {
            return;
        }

        $candidates = [];
        foreach ($hiddenTerms as $term) {
            $canonical = (string) $term['canonical'];
            $this->hiddenCanonicals[$canonical] = true;
            $candidates[$canonical] = true;
        }
        foreach ($aliases as $alias) {
            $candidates[(string) $alias] = true;
        }

        $forms = [];
        foreach (array_keys($candidates) as $form) {
            if (mb_strlen($form) < 2) {
                continue;   // 边界 2：单字词形噪声太大
            }
            $forms[] = $form;
        }

        usort($forms, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

        // 完全同名的可见名称（作品《蒲公英》vs 别名「蒲公英」）：该词形整个退出遮蔽
        $exact = [];
        foreach ($visibleNames as $name) {
            $exact[(string) $name] = true;
        }

        $kept = [];
        foreach ($forms as $form) {
            if (isset($exact[$form])) {
                continue;   // 边界 1：词条与作品同名是巧合，不是泄漏
            }
            $kept[] = $form;
        }

        $this->forms = $kept;

        if ($kept === []) {
            return;
        }

        // 只留「包含某个待遮词形、且比它更长」的名称做区间豁免
        foreach ($visibleNames as $name) {
            $name = (string) $name;
            $nlen = mb_strlen($name);
            foreach ($kept as $form) {
                if ($nlen > mb_strlen($form) && str_contains($name, $form)) {
                    $this->exempts[] = $name;

                    break;
                }
            }
        }
    }

    /** 当前是否有需要遮蔽的词形（绝大多数时候是 0，此时全部方法直接返回原值） */
    public function active(): bool
    {
        return $this->forms !== [];
    }

    /** @return list<string> */
    public function forms(): array
    {
        return $this->forms;
    }

    /**
     * 这个字符串本身是不是一个「已被隐藏的词」。
     *
     * 检索用它做一件事：读者搜的词如果正是被隐藏的那个词，就不能再拿它去
     * 匹配作品简介、作者的话这类**原文**。原文不属于词条索引，不该被改写，
     * 但也不该成为隐藏词的检索入口 —— 否则搜索页等于告诉人「这个词存在过」。
     */
    public function isMasked(string $value): bool
    {
        $value = trim($value);
        if ($value === '') {
            return false;
        }

        foreach ($this->forms as $form) {
            if (mb_stripos($value, $form) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * 遮蔽一段文本。
     *
     * 用「先占位、后替换」而不是连环 str_replace：连环替换会让短词吃进长词的残骸
     * （「世代飞船项目」被「世代飞船」切一刀，剩一个孤零零的「项目」），
     * 也说不清哪些字落进了不可动的名称里。这里先按 mb 字符逐位占有区间，
     * 再从后往前一次性回写 —— 长词优先、互不重叠、偏移不失效。
     */
    public function text(string $value): string
    {
        if ($this->forms === [] || $value === '') {
            return $value;
        }

        $length = mb_strlen($value);
        /** @var array<int, true> $occupied */
        $occupied = [];
        /** @var list<array{0:int,1:int}> $spans */
        $spans = [];

        foreach ($this->forms as $form) {
            $flen = mb_strlen($form);
            $offset = 0;
            while (($pos = mb_strpos($value, $form, $offset)) !== false) {
                $offset = $pos + 1;

                $free = true;
                for ($i = $pos; $i < $pos + $flen; ++$i) {
                    if (isset($occupied[$i])) {
                        $free = false;

                        break;
                    }
                }
                if (!$free) {
                    continue;
                }

                if ($this->coveredByExempt($value, $pos, $flen)) {
                    continue;
                }

                for ($i = $pos; $i < $pos + $flen; ++$i) {
                    $occupied[$i] = true;
                }
                $spans[] = [$pos, $flen];
            }
        }

        if ($spans === []) {
            return $value;
        }

        // 从后往前回写：前面的偏移不会被后面的替换推走
        usort($spans, static fn (array $a, array $b): int => $b[0] <=> $a[0]);
        foreach ($spans as [$pos, $flen]) {
            $value = mb_substr($value, 0, $pos)
                . str_repeat(self::MASK, $flen)
                . mb_substr($value, $pos + $flen);
        }

        return $value;
    }

    /** 这个位置上的匹配，是否落在某个仍然可见的名称内部（那样就不该动它） */
    private function coveredByExempt(string $value, int $pos, int $len): bool
    {
        foreach ($this->exempts as $exempt) {
            $elen = mb_strlen($exempt);
            $offset = 0;
            while (($start = mb_strpos($value, $exempt, $offset)) !== false) {
                if ($start <= $pos && $pos + $len <= $start + $elen) {
                    return true;
                }
                $offset = $start + 1;
            }
        }

        return false;
    }

    /**
     * 递归处理整棵产物树：所有字符串过一遍遮蔽，数组结构与键名保持原样。
     *
     * 模板读的是 $artifacts['beats'][3]['detail'] 这种深层路径，所以必须整棵处理，
     * 不能逐个字段点名 —— 点名必然漏，漏一处就等于没隐藏。
     */
    public function tree(mixed $node): mixed
    {
        if ($this->forms === []) {
            return $node;
        }

        if (is_string($node)) {
            return $this->text($node);
        }

        if (is_array($node)) {
            $out = [];
            foreach ($node as $k => $v) {
                $out[$k] = $this->tree($v);
            }

            return $out;
        }

        return $node;
    }

    /**
     * 剔除产物里指向隐藏词条的条目（整条删，不是遮蔽）。
     *
     * 理由：一条词条记录是「该词在本文中的档案」，字段包括 canonical、definition、
     * evidence（原文引证）、aliases。把它遮成 ＊＊＊＊ 等于还留着一条空档案，
     * 读者会看到一张只有星号的表格。整条删掉才是「这个词不存在」。
     *
     * 用 canonical **精确**匹配：产物里可能同时有「世代飞船」和「冷冻仓世代飞船」，
     * 后者是独立词条，不能因为包含前者就被误删。
     *
     * @param list<array<string,mixed>> $terms
     * @return list<array<string,mixed>>
     */
    public function stripTermEntries(array $terms): array
    {
        if ($this->hiddenCanonicals === []) {
            return $terms;
        }

        return array_values(array_filter(
            $terms,
            fn (array $t): bool => !isset($this->hiddenCanonicals[(string) ($t['canonical'] ?? '')])
        ));
    }

    /**
     * 拆解产物的统一出口。
     *
     * 顺序不能反：**先剔除条目，再遮蔽文本**。反过来的话，被遮成 ＊＊＊＊ 的词条
     * 就认不出 canonical 了，页面上会留下一张只有星号的空档案表。
     *
     * 这是「四件产出」进模板前的最后一道。所有自由文本都在这里被处理 ——
     * 一句话索引、主题说明、剧情四问、节点细节、伏笔正文、建议与替代路径、
     * 修改项、读者看点、术语说明。逐个字段点名必然漏，所以整棵递归。
     *
     * @param array<string,mixed> $artifacts
     * @return array<string,mixed>
     */
    public function artifacts(array $artifacts): array
    {
        if ($this->forms === [] && $this->hiddenCanonicals === []) {
            return $artifacts;
        }

        if (isset($artifacts['terms']) && is_array($artifacts['terms'])) {
            $artifacts['terms'] = $this->stripTermEntries($artifacts['terms']);
        }

        /** @var array<string,mixed> $masked */
        $masked = $this->tree($artifacts);

        return $masked;
    }

    /**
     * 遮蔽一组列表行里的摘要字段。
     *
     * 只处理摘要类字段，**不动 title / author_name / issue 名** —— 那是作品的身份证，
     * 不是词条索引。词条名叫「蒲公英」而作品也叫《蒲公英》时，遮标题会让读者找不到这篇。
     *
     * @param list<array<string,mixed>> $rows
     * @param list<string> $fields
     * @return list<array<string,mixed>>
     */
    public function rows(array $rows, array $fields = ['one_liner']): array
    {
        if ($this->forms === []) {
            return $rows;
        }

        foreach ($rows as $i => $row) {
            foreach ($fields as $field) {
                if (isset($row[$field]) && is_string($row[$field])) {
                    $rows[$i][$field] = $this->text($row[$field]);
                }
            }
        }

        return $rows;
    }
}
