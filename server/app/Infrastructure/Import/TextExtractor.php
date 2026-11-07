<?php

declare(strict_types=1);

namespace App\Infrastructure\Import;

use App\Support\Text;
use RuntimeException;

/**
 * 原文抽取入口：按扩展名分发，统一产出 UTF-8 纯文本 + 原始编码。
 */
final class TextExtractor
{
    public function __construct(private readonly DocxExtractor $docx)
    {
    }

    /**
     * @return array{text: string, encoding: string}
     */
    public function extract(string $path, string $ext): array
    {
        $ext = strtolower($ext);

        if ($ext === 'docx') {
            return ['text' => Text::normalizeBody($this->docx->extract($path)), 'encoding' => 'UTF-8'];
        }

        if (!in_array($ext, ['txt', 'md', 'markdown'], true)) {
            throw new RuntimeException('不支持的文件类型');
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new RuntimeException('文件读取失败');
        }
        if (str_contains($raw, "\0")) {
            // 纯文本里出现 NUL，基本可判定是二进制伪装
            throw new RuntimeException('文件内容不是纯文本');
        }

        $converted = Text::toUtf8($raw);

        return [
            'text'     => Text::normalizeBody($converted['text']),
            'encoding' => $converted['encoding'],
        ];
    }
}
