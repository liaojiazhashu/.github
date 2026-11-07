<?php

declare(strict_types=1);

namespace App\Application\Work;

/**
 * 原文版本差异（M4「版本 + diff」）。
 *
 * 三处刻意的取舍：
 *   1. **按段落比，不按字符比**。作者改的是一段话，不是第 3712 个字符；
 *      段落级 diff 的结果人一眼能看，字符级 diff 的结果只有机器能看。
 *   2. **LCS 有上限**。500×500 段就已经是 25 万格；两万字的小说正文段落数可以上千，
 *      不设上限会把内存吃光。超过上限退化为「按位置对齐比较」——
 *      结论会粗一点（整段移动会被误判成改了很多），但不会把进程拖死。
 *   3. **成对的删/增做行内收窄**。段落被判为"改了"时，把两侧公共的前后缀剥掉，
 *      只高亮中间那段，读者才知道到底改了哪句话。
 */
final class TextDiff
{
    /** LCS 的格子上限（段数乘积），超过就退化为按位置比较 */
    private const MAX_CELLS = 1_500_000;

    /** 没有任何换行的超长正文，按这个长度切块再比 */
    private const BLOCK_CHARS = 300;

    /**
     * 把正文切成可比的段落序列。
     *
     * @return list<string>
     */
    public static function paragraphs(string $text): array
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);

        $lines = preg_split('/\n/u', $text) ?: [];
        $lines = array_values(array_filter(
            array_map(static fn (string $l): string => trim($l), $lines),
            static fn (string $l): bool => $l !== ''
        ));

        // 整篇没有换行（不少 txt 导出就是一大坨）：按长度切块，避免"整篇一段"导致 diff 无意义
        if (count($lines) === 1 && mb_strlen($lines[0], 'UTF-8') > self::BLOCK_CHARS * 2) {
            $chars = preg_split('//u', $lines[0], -1, PREG_SPLIT_NO_EMPTY) ?: [];

            return array_values(array_map(
                static fn (array $chunk): string => implode('', $chunk),
                array_chunk($chars, self::BLOCK_CHARS)
            ));
        }

        return $lines;
    }

    /**
     * 逐段比较。
     *
     * @return list<array{type:string, text:string, other:?string}>
     *         type = keep / del / add；del 与 add 相邻出现时 other 指向配对那一段
     */
    public static function compare(string $oldText, string $newText): array
    {
        $a = self::paragraphs($oldText);
        $b = self::paragraphs($newText);

        if (count($a) * count($b) > self::MAX_CELLS) {
            return self::compareByPosition($a, $b);
        }

        $n = count($a);
        $m = count($b);

        // LCS 长度表（自底向上），$lcs[$i][$j] = a[i:] 与 b[j:] 的最长公共子序列长度
        $lcs = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
        for ($i = $n - 1; $i >= 0; $i--) {
            for ($j = $m - 1; $j >= 0; $j--) {
                $lcs[$i][$j] = $a[$i] === $b[$j]
                    ? $lcs[$i + 1][$j + 1] + 1
                    : max($lcs[$i + 1][$j], $lcs[$i][$j + 1]);
            }
        }

        $ops = [];
        $i = 0;
        $j = 0;
        while ($i < $n && $j < $m) {
            if ($a[$i] === $b[$j]) {
                $ops[] = ['type' => 'keep', 'text' => $a[$i], 'other' => null];
                $i++;
                $j++;
            } elseif ($lcs[$i + 1][$j] >= $lcs[$i][$j + 1]) {
                $ops[] = ['type' => 'del', 'text' => $a[$i], 'other' => null];
                $i++;
            } else {
                $ops[] = ['type' => 'add', 'text' => $b[$j], 'other' => null];
                $j++;
            }
        }
        while ($i < $n) {
            $ops[] = ['type' => 'del', 'text' => $a[$i], 'other' => null];
            $i++;
        }
        while ($j < $m) {
            $ops[] = ['type' => 'add', 'text' => $b[$j], 'other' => null];
            $j++;
        }

        return self::pairBlocks($ops);
    }

    /**
     * 把连续的 del / add 块配成对：一块删、一块增，说明是"同一处改写"而不是"删了一段又加了一段"。
     *
     * @param list<array{type:string, text:string, other:?string}> $ops
     * @return list<array{type:string, text:string, other:?string}>
     */
    private static function pairBlocks(array $ops): array
    {
        $result = [];
        $count  = count($ops);

        for ($k = 0; $k < $count; $k++) {
            if ($ops[$k]['type'] === 'keep') {
                $result[] = $ops[$k];
                continue;
            }

            // 收集这一轮的删除段与新增段
            $dels = [];
            $adds = [];
            while ($k < $count && $ops[$k]['type'] !== 'keep') {
                if ($ops[$k]['type'] === 'del') {
                    $dels[] = $ops[$k]['text'];
                } else {
                    $adds[] = $ops[$k]['text'];
                }
                $k++;
            }
            $k--;

            $pairs = min(count($dels), count($adds));
            for ($p = 0; $p < $pairs; $p++) {
                $result[] = ['type' => 'del', 'text' => $dels[$p], 'other' => $adds[$p]];
            }
            for ($p = $pairs; $p < count($dels); $p++) {
                $result[] = ['type' => 'del', 'text' => $dels[$p], 'other' => null];
            }
            for ($p = $pairs; $p < count($adds); $p++) {
                $result[] = ['type' => 'add', 'text' => $adds[$p], 'other' => null];
            }
        }

        // 配对是"删在前增在后"，读起来别扭：把成对的改成一行「改动」，原文与新文都在 other 里
        return $result;
    }

    /**
     * 超长文本的退化比较：同位置相同算 kept，不同算一条改动（旧 + 新）。
     *
     * @param list<string> $a
     * @param list<string> $b
     * @return list<array{type:string, text:string, other:?string}>
     */
    private static function compareByPosition(array $a, array $b): array
    {
        $ops = [];
        $max = max(count($a), count($b));

        for ($i = 0; $i < $max; $i++) {
            $left  = $a[$i] ?? null;
            $right = $b[$i] ?? null;

            if ($left === $right) {
                $ops[] = ['type' => 'keep', 'text' => (string) $left, 'other' => null];
            } elseif ($left !== null && $right !== null) {
                $ops[] = ['type' => 'del', 'text' => $left, 'other' => $right];
            } elseif ($left !== null) {
                $ops[] = ['type' => 'del', 'text' => $left, 'other' => null];
            } else {
                $ops[] = ['type' => 'add', 'text' => (string) $right, 'other' => null];
            }
        }

        return $ops;
    }

    /**
     * @param list<array{type:string, text:string, other:?string}> $ops
     * @return array{kept:int, changed:int, added:int, removed:int, old_paragraphs:int, new_paragraphs:int}
     */
    public static function stats(array $ops): array
    {
        $kept = 0;
        $changed = 0;
        $added = 0;
        $removed = 0;

        foreach ($ops as $op) {
            match ($op['type']) {
                'keep' => $kept++,
                'del'  => $op['other'] === null ? $removed++ : $changed++,
                default => $added++,
            };
        }

        return [
            'kept'           => $kept,
            'changed'        => $changed,
            'added'          => $added,
            'removed'        => $removed,
            'old_paragraphs' => $kept + $changed + $removed,
            'new_paragraphs' => $kept + $changed + $added,
        ];
    }

    /**
     * 行内收窄：剥掉两侧公共的前缀与后缀，只把中间的差异交给界面高亮。
     *
     * @return array{prefix:string, old_mid:string, new_mid:string, suffix:string}
     */
    public static function inline(string $old, string $new): array
    {
        $a = preg_split('//u', $old, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $b = preg_split('//u', $new, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $max = min(count($a), count($b));

        $pre = 0;
        while ($pre < $max && $a[$pre] === $b[$pre]) {
            $pre++;
        }

        $suf = 0;
        while ($suf < $max - $pre && $a[count($a) - 1 - $suf] === $b[count($b) - 1 - $suf]) {
            $suf++;
        }

        return [
            'prefix'  => implode('', array_slice($a, 0, $pre)),
            'old_mid' => implode('', array_slice($a, $pre, count($a) - $pre - $suf)),
            'new_mid' => implode('', array_slice($b, $pre, count($b) - $pre - $suf)),
            'suffix'  => $suf === 0 ? '' : implode('', array_slice($a, count($a) - $suf)),
        ];
    }
}
