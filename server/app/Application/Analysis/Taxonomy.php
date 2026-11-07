<?php

declare(strict_types=1);

namespace App\Application\Analysis;

/**
 * 科幻通行词表（genre taxonomy）。
 *
 * 词库要有用，前提是同一个概念在所有作品里叫同一个名字。让模型每篇自由造词，
 * 结果就是 75 个关键词各挂 1 篇作品 —— 看起来有词库，实际上没有索引。
 * 所以这里做两件事：
 *
 *   1. 归并：模型写「克隆体」「复制人」，落库前一律变成「克隆人」；
 *   2. 拒收：词表之外的新词必须长得像通用概念（2–8 字、无编号、无书名、
 *      不是短语），否则它是这篇小说的专有名词，进词库只会变成一条死词。
 *
 * 表在 resources/taxonomy/sf_terms.php，改表后跑 bin/check-taxonomy.php 自检。
 */
final class Taxonomy
{
    private const PATH         = __DIR__ . '/../../../resources/taxonomy/sf_terms.php';
    private const RETIRED_PATH = __DIR__ . '/../../../resources/taxonomy/retired_terms.php';
    private const NAMES = ['setting' => '科幻设定', 'world' => '世界观', 'image' => '意象'];

    /** @var array<string, array<string,string[]>>|null canonical => aliases，按类分组 */
    private static ?array $table = null;

    /** @var array<string,string> 归一后的别名 => 规范词 */
    private static array $aliases = [];

    /** @var array<string,string> 归一后的规范词 => 类别 */
    private static array $cats = [];

    /** @var array<string,string>|null 归一后的退役词 => 原写法 */
    private static ?array $retired = null;

    /** 对不上词表时，新词允许的长度区间 —— 短于此是残词，长于此多半是短语 */
    private const MIN_LEN = 2;
    private const MAX_LEN = 8;

    /** 短语与专名的形状特征：出现即判非通用 */
    private const BANNED = ['《', '》', '「', '」', '“', '”', '：', '，', '、', '（', '）', '的', '之', '与', '及'];

    /**
     * 加载并建立反向索引。表很小（143 条），一次性建好常驻内存。
     *
     * @return array<string, array<string,string[]>>
     */
    public static function table(): array
    {
        if (self::$table !== null) {
            return self::$table;
        }

        $raw = is_file(self::PATH) ? require self::PATH : [];
        $raw = is_array($raw) ? $raw : [];

        self::$aliases = [];
        self::$cats    = [];

        foreach ($raw as $cat => $words) {
            if (!is_array($words)) {
                continue;
            }
            foreach ($words as $canonical => $aliases) {
                $canonical = (string) $canonical;
                $key       = self::key($canonical);
                self::$cats[$key] = (string) $cat;
                foreach ((array) $aliases as $alias) {
                    $alias = self::key((string) $alias);
                    // 规范词优先：别名撞上某个规范词时不覆盖，避免归并方向被别名带跑
                    if ($alias !== '' && $alias !== $key) {
                        self::$aliases[$alias] = $canonical;
                    }
                }
            }
        }

        self::$table = $raw;

        return self::$table;
    }

    /** 同义归一：对上别名返回规范词，否则原样返回（去掉首尾空白） */
    public static function canonicalize(string $term): string
    {
        self::table();
        $term = trim($term);

        return self::$aliases[self::key($term)] ?? $term;
    }

    /** 这个词是不是词表里的规范词；是则返回它的类别 */
    public static function categoryOf(string $term): ?string
    {
        self::table();

        return self::$cats[self::key($term)] ?? null;
    }

    /**
     * 退役词：形状合格、但拿掉科幻设定之后就站不住的说法。
     *
     * 这张表不参与归并，只做两件事：拦住新分析，清掉旧关联。
     *
     * @return array<string,string> 归一后的词 => 原写法
     */
    public static function retiredMap(): array
    {
        if (self::$retired !== null) {
            return self::$retired;
        }

        $raw = is_file(self::RETIRED_PATH) ? require self::RETIRED_PATH : [];
        $out = [];
        foreach ((array) $raw as $word) {
            $word = trim((string) $word);
            if ($word !== '') {
                $out[self::key($word)] = $word;
            }
        }

        return self::$retired = $out;
    }

    /** 对上退役词即返回 true；词表内的词永远优先，不算退役 */
    public static function isRetired(string $term): bool
    {
        if (self::categoryOf($term) !== null) {
            return false;
        }

        return isset(self::retiredMap()[self::key($term)]);
    }

    /**
     * 给 S1 提示词用的禁止清单。
     *
     * 全部列出来太长（两百多条），只给最容易犯的那一段：通用意象。
     * 剩下的靠 rejectReason() 在落库前拦。
     */
    public static function retiredPromptList(int $limit = 60): string
    {
        $words = array_values(self::retiredMap());
        $words = array_slice($words, 0, $limit);

        return implode('、', $words);
    }

    /**
     * 这个词能不能进词库；不能则返回原因。
     *
     * 词表内的词直接放行 —— 那张表是人工审过的，不必再过形状检查。
     * 表外新词走两道：先看是不是退役词（形状合格但语义不科幻），
     * 再看形状（通用概念都很短、没有编号、不是一句话）。
     */
    public static function rejectReason(string $term): ?string
    {
        $term = trim($term);
        if ($term === '') {
            return '空词';
        }

        if (self::categoryOf($term) !== null) {
            return null;
        }

        if (self::isRetired($term)) {
            return '与科幻没有强关联：拿掉科幻设定之后它依然成立，属于通用意象或通用制度';
        }

        $len = mb_strlen($term);
        if ($len < self::MIN_LEN) {
            return '单字词太宽，无法定位具体概念';
        }
        if ($len > self::MAX_LEN) {
            return sprintf('超过 %d 字，通常是短语或本篇专名，不是可复用的概念', self::MAX_LEN);
        }
        if (preg_match('/\d/', $term) === 1) {
            return '含数字编号，是这篇小说的专有名词';
        }
        if (preg_match('/[A-Za-z]/', $term) === 1) {
            return '含拉丁字母编号，是这篇小说的专有名词';
        }
        foreach (self::BANNED as $bad) {
            if (str_contains($term, $bad)) {
                return sprintf('含「%s」，是短语或书名，不是概念', $bad);
            }
        }

        return null;
    }

    /**
     * 给 S1 提示词用的清单：按类分组、顿号分隔。
     *
     * 143 条大概 400 字，相对一次分析的上下文可以忽略；换来的是一致命名。
     */
    public static function promptList(): string
    {
        $out = [];
        foreach (self::table() as $cat => $words) {
            $names = [];
            foreach (array_keys($words) as $canonical) {
                $names[] = (string) $canonical;
            }
            $out[] = '【' . (self::NAMES[$cat] ?? $cat) . '】' . implode('、', $names);
        }

        return implode("\n", $out);
    }

    /** 词表总量，日志与自检用 */
    public static function size(): int
    {
        $n = 0;
        foreach (self::table() as $words) {
            $n += count((array) $words);
        }

        return $n;
    }

    /**
     * 把 hq_index_term.is_canonical 与词表对齐。幂等，可重复跑。
     *
     * 词表是人工审过的清单，所以由它单向决定标记；不在表内的词一律标 0，
     * 靠「被 ≥2 篇作品引用」在公开词库里挣出位置。
     *
     * @return array{total:int, marked:int, cleared:int}
     */
    public static function syncFlags(\PDO $pdo): array
    {
        $names = [];
        foreach (self::table() as $words) {
            foreach (array_keys((array) $words) as $canonical) {
                $names[] = (string) $canonical;
            }
        }

        if ($names === []) {
            return ['total' => 0, 'marked' => 0, 'cleared' => 0];
        }

        $in = implode(',', array_fill(0, count($names), '?'));

        $mark = $pdo->prepare(
            "UPDATE hq_index_term SET is_canonical = 1, updated_at = NOW()
              WHERE is_canonical = 0 AND canonical IN ($in)"
        );
        $mark->execute($names);
        $marked = $mark->rowCount();

        $clear = $pdo->prepare(
            "UPDATE hq_index_term SET is_canonical = 0, updated_at = NOW()
              WHERE is_canonical = 1 AND canonical NOT IN ($in)"
        );
        $clear->execute($names);
        $cleared = $clear->rowCount();

        return ['total' => count($names), 'marked' => $marked, 'cleared' => $cleared];
    }

    private static function key(string $text): string
    {
        $text = preg_replace('/\s+/u', '', $text) ?? $text;

        return mb_strtolower($text, 'UTF-8');
    }
}
