<?php

declare(strict_types=1);

namespace App\Support;

/**
 * 文本处理：编码归一、笔名归一、字数统计。
 *
 * 字数口径（与练笔规则 4.1 的 3000–33000 字对齐）：**汉字按字计，连续拉丁字母/数字按词计**，
 * 标点与空白不计。这个口径必须稳定，否则同一篇作品在不同批次里字数会跳变。
 */
final class Text
{
    /**
     * 统一转 UTF-8，并返回检测到的原始编码。
     *
     * @return array{text: string, encoding: string}
     */
    public static function toUtf8(string $raw): array
    {
        if ($raw === '') {
            return ['text' => '', 'encoding' => 'UTF-8'];
        }

        if (str_starts_with($raw, "\xEF\xBB\xBF")) {
            return ['text' => substr($raw, 3), 'encoding' => 'UTF-8'];
        }

        if (str_starts_with($raw, "\xFF\xFE")) {
            $converted = @mb_convert_encoding(substr($raw, 2), 'UTF-8', 'UTF-16LE');
            return ['text' => (string) $converted, 'encoding' => 'UTF-16LE'];
        }

        if (str_starts_with($raw, "\xFE\xFF")) {
            $converted = @mb_convert_encoding(substr($raw, 2), 'UTF-8', 'UTF-16BE');
            return ['text' => (string) $converted, 'encoding' => 'UTF-16BE'];
        }

        if (mb_check_encoding($raw, 'UTF-8')) {
            return ['text' => $raw, 'encoding' => 'UTF-8'];
        }

        // 不是合法 UTF-8：国内投稿绝大多数是 GBK 系，先按 GB18030 试
        $converted = @mb_convert_encoding($raw, 'UTF-8', 'GB18030');
        if (is_string($converted) && $converted !== '') {
            $bad = substr_count($converted, "\u{FFFD}");
            if ($bad * 100 < mb_strlen($converted)) {
                return ['text' => $converted, 'encoding' => 'GB18030'];
            }
        }

        return ['text' => (string) mb_convert_encoding($raw, 'UTF-8', 'GB18030'), 'encoding' => 'GB18030'];
    }

    /** 笔名归一：全角转半角、去空白、小写。用于去重比对，不用于展示。 */
    public static function normalizeName(string $name): string
    {
        $name = (string) mb_convert_kana($name, 'asKV', 'UTF-8');
        $name = (string) preg_replace('/[\s\x{3000}]+/u', '', $name);
        return mb_strtolower($name, 'UTF-8');
    }

    /**
     * 汉字按字、连续拉丁数字按词。
     *
     * ⚠️ 不要用 `\p{Han}`：PCRE 的 Han 脚本把 U+3001「、」U+3002「。」U+FF01「！」这类
     * CJK 标点也算进去（ScriptExtensions 含 Han），实测「一，二。三、四！」会数成 6 而不是 4。
     * 这里按**码位区段**判定真正的汉字。
     */
    public static function countChars(string $text): int
    {
        $han = (int) preg_match_all(
            '/[\x{3400}-\x{4DBF}\x{4E00}-\x{9FFF}\x{F900}-\x{FAFF}\x{20000}-\x{2FA1F}]/u',
            $text
        );
        $words = (int) preg_match_all('/[A-Za-z0-9]+/u', $text);

        return $han + $words;
    }

    /** 去首尾空白 + 折叠连续空行，用于摘要与预览。 */
    public static function normalizeBody(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = (string) preg_replace("/\n{3,}/u", "\n\n", $text);
        return trim($text);
    }

    public static function excerpt(string $text, int $limit = 120): string
    {
        $text = (string) preg_replace('/\s+/u', ' ', $text);
        return mb_strlen($text) > $limit ? mb_substr($text, 0, $limit) . '…' : $text;
    }

    /**
     * 中文 2-gram（不引分词库）。标点与空白先归一，再两两切。
     *
     * 为什么是 2-gram 而不是单字：中文单字区分度过低（「的」「一」几乎每篇都有），
     * 会把候选集放大到没法排序；也不引 jieba 级依赖 —— 一万字的标题+作者+索引词最多几百个
     * 二元组，PHP 直接算就够快（远低于一次数据库往返）。
     *
     * @return list<string>
     */
    public static function grams(string $text): array
    {
        $clean = (string) preg_replace('/[\s\x{3000}\p{P}\p{S}]+/u', '', $text);
        $chars = preg_split('//u', $clean, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if (count($chars) < 2) {
            return [];
        }

        $grams = [];
        for ($i = 0, $n = count($chars) - 1; $i < $n; $i++) {
            $grams[] = $chars[$i] . $chars[$i + 1];
        }

        return array_values(array_unique($grams));
    }

    /**
     * 按字符（不是字节）算编辑距离。PHP 内置的 levenshtein() 按字节算，
     * 一个汉字 3 字节，改一个字会被算成改 3 次 —— 用在中文字面上完全失真。
     */
    public static function distance(string $a, string $b): int
    {
        $left  = preg_split('//u', $a, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $right = preg_split('//u', $b, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $n = count($left);
        $m = count($right);

        if ($n === 0) {
            return $m;
        }
        if ($m === 0) {
            return $n;
        }

        $prev = range(0, $m);
        for ($i = 1; $i <= $n; $i++) {
            $cur = [$i];
            for ($j = 1; $j <= $m; $j++) {
                $cost = $left[$i - 1] === $right[$j - 1] ? 0 : 1;
                $cur[$j] = min($prev[$j] + 1, $cur[$j - 1] + 1, $prev[$j - 1] + $cost);
            }
            $prev = $cur;
        }

        return $prev[$m];
    }
}
