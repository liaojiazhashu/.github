<?php

declare(strict_types=1);

namespace App\Application\Analysis;

use RuntimeException;

/**
 * 拆解领域内的「说得清原因」的失败。
 *
 * 为什么不直接抛 RuntimeException：调用方（队列 worker、命令行、后台页面）
 * 需要按**原因分类**决定动作 —— 正文太短是永不重试（换个字数也不会变），
 * 没原文版本是数据缺陷，超预算是今天不许再花钱。靠 message 里猜中文关键词太脆。
 *
 * 与 Infrastruct\Ai\AiException 的分工：AiException 表示「模型这一侧出问题」
 * （网络、限流、空返回、闸门不过），这里表示「还没走到模型这一步就确定不行」。
 */
final class AnalysisException extends RuntimeException
{
    /** 正文短于闸门可判定的下限：重试无意义，也不该花一次调用的钱 */
    public const TEXT_TOO_SHORT = 'TEXT_TOO_SHORT';

    /** 作品没有任何原文版本（导入流程被打断的残留） */
    public const NO_VERSION = 'NO_VERSION';

    /** 作品不存在或已软删 */
    public const WORK_NOT_FOUND = 'WORK_NOT_FOUND';

    public function __construct(
        string $message,
        public readonly string $errorCode = self::WORK_NOT_FOUND,
    ) {
        parent::__construct($message);
    }

    public static function textTooShort(int $chars, int $floor = 500): self
    {
        return new self(
            sprintf(
                '正文只有 %d 个字符，太短，拆不出四相节点轴与五类索引词（规矩下限是 3000 字，本系统按 %d 字符兜底）',
                $chars,
                $floor
            ),
            self::TEXT_TOO_SHORT
        );
    }

    public static function noVersion(): self
    {
        return new self('作品还没有原文版本，无法分析', self::NO_VERSION);
    }

    public static function workNotFound(): self
    {
        return new self('作品不存在或已删除', self::WORK_NOT_FOUND);
    }
}
