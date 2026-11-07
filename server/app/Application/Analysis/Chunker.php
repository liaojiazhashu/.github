<?php

declare(strict_types=1);

namespace App\Application\Analysis;

use InvalidArgumentException;

/**
 * 正文切块（§6.3）。
 *
 * 1M 上下文装得下 3 万字，切块不是为了装得下，而是为了**成本、JSON 稳定性、可解释性**：
 * 长输入下模型返回合法 JSON 的概率会下降，出错时也很难定位是哪一段读错了。
 *
 * 切块三条硬规矩：
 *   1. 优先在段落边界（空行）切，其次在句末标点切，最后才硬切；
 *   2. **绝不在引号内切断**——引号被劈开会让模型把半句引文当原文；
 *   3. 相邻块留 overlap 字重叠，防止刚好在场景交界处切丢上下文。
 */
final class Chunker
{
    private const MAX_INPUT_CHARS = 200000;

    public function __construct(
        private readonly int $maxChars = 12000,
        private readonly int $overlap = 300,
    ) {
        if ($this->maxChars < 500) {
            throw new InvalidArgumentException('单块上限不能小于 500 字');
        }
    }

    public function needsSplit(int $charCount, int $threshold): bool
    {
        return $charCount > $threshold;
    }

    /**
     * @return list<array{index:int, total:int, start:int, end:int, text:string}>
     */
    public function split(string $text): array
    {
        $len = mb_strlen($text, 'UTF-8');
        if ($len > self::MAX_INPUT_CHARS) {
            throw new InvalidArgumentException(sprintf('正文 %d 字，超过切块上限 %d', $len, self::MAX_INPUT_CHARS));
        }

        if ($len <= $this->maxChars) {
            return [['index' => 1, 'total' => 1, 'start' => 0, 'end' => $len, 'text' => $text]];
        }

        $units = $this->units($text, $len);

        /** @var list<array{start:int, end:int}> $ranges */
        $ranges = [];
        $count  = count($units);
        $i      = 0;

        while ($i < $count) {
            $start = $units[$i]['start'];
            $end   = $units[$i]['start'] + $units[$i]['len'];
            $j     = $i + 1;

            while ($j < $count && ($units[$j]['start'] + $units[$j]['len'] - $start) <= $this->maxChars) {
                $end = $units[$j]['start'] + $units[$j]['len'];
                $j++;
            }

            $ranges[] = ['start' => $start, 'end' => $this->balanceEnd($text, $start, $end, $len)];
            $i = $j;
        }

        $chunks = [];
        $total  = count($ranges);

        foreach ($ranges as $k => $range) {
            $start = $range['start'];
            if ($k > 0 && $this->overlap > 0) {
                // 向前拖 overlap 字作为承接上下文，但不越过上一块自身的起点
                $start = max($ranges[$k - 1]['start'], $start - $this->overlap);
            }

            $chunks[] = [
                'index' => $k + 1,
                'total' => $total,
                'start' => $start,
                'end'   => $range['end'],
                'text'  => mb_substr($text, $start, $range['end'] - $start, 'UTF-8'),
            ];
        }

        return $chunks;
    }

    /**
     * 把正文拆成「不可再分的装载单元」：先按空行分段，超长的段落再按句末标点拆。
     *
     * @return list<array{start:int, len:int}>
     */
    private function units(string $text, int $len): array
    {
        $blocks = [];
        $offset = 0;

        foreach (preg_split('/\r\n|\n|\r/u', $text) ?: [] as $line) {
            $lineLen = mb_strlen($line, 'UTF-8');

            if (trim($line) === '') {
                $offset += $lineLen + 1;   // 空行本身不装载，只推进偏移
                continue;
            }

            $last = count($blocks) - 1;
            if ($last >= 0 && $blocks[$last]['start'] + $blocks[$last]['len'] === $offset - 1) {
                // 与上一段连续（中间只有一个换行）→ 归入同一块
                $blocks[$last]['len'] += 1 + $lineLen;
            } else {
                $blocks[] = ['start' => $offset, 'len' => $lineLen];
            }

            $offset += $lineLen + 1;
        }

        $units = [];
        foreach ($blocks as $block) {
            if ($block['len'] <= $this->maxChars) {
                $units[] = $block;
                continue;
            }
            foreach ($this->splitSentences($text, $block['start'], $block['len']) as $unit) {
                $units[] = $unit;
            }
        }

        // 极端情况（全篇没有段落也没有句号）：按 maxChars 硬切，保证一定能装下
        if ($units === []) {
            for ($pos = 0; $pos < $len; $pos += $this->maxChars) {
                $units[] = ['start' => $pos, 'len' => min($this->maxChars, $len - $pos)];
            }
        }

        return $units;
    }

    /**
     * @return list<array{start:int, len:int}>
     */
    private function splitSentences(string $text, int $start, int $len): array
    {
        $segment = mb_substr($text, $start, $len, 'UTF-8');

        preg_match_all('/[。！？!?…]+[」』”"”\')）]*/u', $segment, $matches, PREG_OFFSET_CAPTURE);
        if ($matches[0] === []) {
            return $this->hardSplit($len, $start);
        }

        $units    = [];
        $consumed = 0;

        foreach ($matches[0] as [$matched, $byteOffset]) {
            $charEnd = mb_strlen(substr($segment, 0, $byteOffset), 'UTF-8') + mb_strlen($matched, 'UTF-8');
            $unitLen = $charEnd - $consumed;
            if ($unitLen <= 0) {
                continue;
            }

            // 单句本身就超长 → 句内硬切
            if ($unitLen > $this->maxChars) {
                foreach ($this->hardSplit($unitLen, $start + $consumed) as $piece) {
                    $units[] = $piece;
                }
            } else {
                $units[] = ['start' => $start + $consumed, 'len' => $unitLen];
            }

            $consumed = $charEnd;
        }

        if ($consumed < $len) {
            $tail = $len - $consumed;
            foreach ($this->hardSplit($tail, $start + $consumed) as $piece) {
                $units[] = $piece;
            }
        }

        return $units;
    }

    /** @return list<array{start:int, len:int}> */
    private function hardSplit(int $len, int $start): array
    {
        $units = [];
        for ($pos = 0; $pos < $len; $pos += $this->maxChars) {
            $units[] = ['start' => $start + $pos, 'len' => min($this->maxChars, $len - $pos)];
        }

        return $units;
    }

    /**
     * 若切点落在引号内部，向后挪到引号闭合之后（最多挪 overlap 的两倍，防止把整块吃光）。
     */
    private function balanceEnd(string $text, int $start, int $end, int $total): int
    {
        if ($end >= $total) {
            return $total;
        }

        $segment = mb_substr($text, $start, $end - $start, 'UTF-8');
        if ($this->quotesBalanced($segment)) {
            return $end;
        }

        $limit  = min($total, $end + $this->overlap * 2);
        $cursor = $end;

        while ($cursor < $limit) {
            $cursor++;
            if ($this->quotesBalanced(mb_substr($text, $start, $cursor - $start, 'UTF-8'))) {
                return $cursor;
            }
        }

        // 挪不出平衡点：宁可带着不闭合的引号，也不要把块扩到无边界
        return $end;
    }

    private function quotesBalanced(string $segment): bool
    {
        $open  = substr_count($segment, '「') + substr_count($segment, '“') + substr_count($segment, '《');
        $close = substr_count($segment, '」') + substr_count($segment, '”') + substr_count($segment, '》');

        return $open === $close;
    }
}
