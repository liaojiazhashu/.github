<?php

declare(strict_types=1);

namespace App\Application\Analysis;

use RuntimeException;

/**
 * 「这条分析被要求停下」。
 *
 * 单独一个异常类型，而不是借用 AnalysisException 或 RuntimeException，
 * 因为它在三个地方要走不同的路：
 *   · Pipeline 里不写失败告警（这是运营主动停的，不是事故）；
 *   · 分析记录标 failed 但错误码是 CANCELED（前台不看，后台能一眼分清）；
 *   · worker 里映射成 canceled 而不是 failed —— 不重试，也不进失败列表。
 *
 * 一旦混进普通异常，就会出现「运营自己停的任务在告警里报了一整天」这种事。
 */
final class AnalysisCanceled extends RuntimeException
{
    public static function byOperator(): self
    {
        return new self('已按运营要求停止（阶段边界生效）');
    }
}
