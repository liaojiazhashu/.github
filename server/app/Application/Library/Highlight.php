<?php

declare(strict_types=1);

namespace App\Application\Library;

/**
 * 检索搜到位置的高亮。
 *
 * 关键决定：**这里不生成任何 HTML**。它只把原文切成「对上 / 对不上」的片段，
 * 由模板（Twig 自动转义）负责显示，`<mark>` 也在模板里写。
 *
 * 为什么这样绕一道：最常见的 XSS 写法是「先把用户输入拼进 `<mark>` 再输出」，
 * 一旦忘记转义就中招；而「切片段 + 模板转义」从结构上就没有拼字符串这一步，
 * 用户搜什么都不会变成标签。代价是模板里多一层循环，这个代价值得付。
 */
final class Highlight
{
    /**
     * @return list<array{text: string, hit: bool}>
     */
    public static function segments(string $text, string $needle, int $maxHits = 40): array
    {
        $needle = trim($needle);

        if ($text === '' || $needle === '') {
            return [['text' => $text, 'hit' => false]];
        }

        $length = mb_strlen($text, 'UTF-8');
        $step   = max(1, mb_strlen($needle, 'UTF-8'));
        $out    = [];
        $offset = 0;
        $hits   = 0;

        while ($offset < $length && $hits < $maxHits) {
            $pos = mb_stripos($text, $needle, $offset, 'UTF-8');
            if ($pos === false) {
                break;
            }

            if ($pos > $offset) {
                $out[] = ['text' => mb_substr($text, $offset, $pos - $offset, 'UTF-8'), 'hit' => false];
            }
            $out[] = ['text' => mb_substr($text, $pos, $step, 'UTF-8'), 'hit' => true];

            $offset = $pos + $step;
            $hits++;
        }

        if ($offset < $length) {
            $out[] = ['text' => mb_substr($text, $offset, null, 'UTF-8'), 'hit' => false];
        }

        return $out === [] ? [['text' => $text, 'hit' => false]] : $out;
    }

    /**
     * 摘要片段：把窗口挪到第一个搜到处附近，前后留一点上下文。
     *
     * 搜索结果如果只显示开头 80 字，而关键词落在第三段，读者会以为"没搜到"。
     */
    public static function excerptAround(string $text, string $needle, int $radius = 48): string
    {
        $text   = trim((string) preg_replace('/\s+/u', ' ', $text));
        $needle = trim($needle);

        if ($text === '' || $needle === '' || mb_strlen($text, 'UTF-8') <= $radius * 2) {
            return $text;
        }

        $pos = mb_stripos($text, $needle, 0, 'UTF-8');
        if ($pos === false) {
            return mb_substr($text, 0, $radius * 2, 'UTF-8') . '…';
        }

        $start = max(0, $pos - $radius);
        $piece = mb_substr($text, $start, $radius * 2, 'UTF-8');

        return ($start > 0 ? '…' : '') . $piece . ($start + $radius * 2 < mb_strlen($text, 'UTF-8') ? '…' : '');
    }
}
