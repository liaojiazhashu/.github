<?php

declare(strict_types=1);

namespace App\Application\Analysis;

/**
 * 闸门结果。
 *
 * 硬违规（hard）＝ 这份产出不能入库，必须重试或整篇失败；
 * 软违规（soft）＝ 局部数据有问题，丢掉那几条、留个 warning 就能入库。
 *
 * 分开的理由：如果所有违规都硬失败，一篇作品只要有一处引文没对上就全废；
 * 如果都软处理，模型开始瞎编也发现不了。所以「结构性」的判硬，「引文级」的判软。
 */
final class GateResult
{
    /**
     * @param list<string>     $hard
     * @param list<string>     $soft
     * @param array<string,mixed> $data 清洗后的数据
     */
    private function __construct(
        public readonly bool $passed,
        public readonly array $hard,
        public readonly array $soft,
        public readonly array $data,
    ) {
    }

    /** @param array<string,mixed> $data */
    public static function pass(array $data, array $soft = []): self
    {
        return new self(true, [], array_values($soft), $data);
    }

    /** @param array<string,mixed> $data */
    public static function fail(array $hard, array $soft = [], array $data = []): self
    {
        return new self(false, array_values($hard), array_values($soft), $data);
    }

    /** 回灌给模型的失败清单（不夹带内部文档编号，避免模型照着编号编内容） */
    public function feedback(): string
    {
        if ($this->hard === []) {
            return '';
        }

        $lines = ['你上一次的输出不符合要求，请只修正以下问题后重新输出完整 json：'];
        foreach ($this->hard as $i => $item) {
            $lines[] = ($i + 1) . '. ' . $item;
        }

        return implode("\n", $lines);
    }

    public function summary(): string
    {
        $parts = [];
        if ($this->hard !== []) {
            $parts[] = '硬违规 ' . count($this->hard) . ' 条';
        }
        if ($this->soft !== []) {
            $parts[] = '软告警 ' . count($this->soft) . ' 条';
        }

        return $parts === [] ? '通过' : implode('，', $parts);
    }
}
