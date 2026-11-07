<?php

declare(strict_types=1);

namespace App\Infrastructure\Import;

use DOMDocument;
use DOMXPath;
use RuntimeException;
use ZipArchive;

/**
 * docx 正文抽取：docx 本质是 zip，正文在 word/document.xml。
 *
 * 安全边界（比「能不能读出字」重要）：
 *   · 不调用任何外部命令（LibreOffice / antiword 都可能成为 RCE 面）；
 *   · 含 vbaProject.bin 的文档一律拒收（宏文档不属于投稿形态）；
 *   · 单个 XML 条目解压上限 30 MiB，防 zip 炸弹；
 *   · loadXML 不启用 LIBXML_NOENT，外部实体不展开（XXE），并加 LIBXML_NONET 断网。
 */
final class DocxExtractor
{
    private const MAX_ENTRY_BYTES = 30 * 1024 * 1024;

    private const NS_W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    public function extract(string $path): string
    {
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException('不是有效的 docx 文件');
        }

        try {
            if ($zip->locateName('word/vbaProject.bin') !== false) {
                throw new RuntimeException('含宏的文档不予接收，请另存为 .docx 后重试');
            }

            $stat = $zip->statName('word/document.xml');
            if ($stat === false) {
                throw new RuntimeException('docx 结构异常：缺少正文部件');
            }
            if ((int) $stat['size'] > self::MAX_ENTRY_BYTES) {
                throw new RuntimeException('文档正文过大，无法解析');
            }

            $xml = $zip->getFromName('word/document.xml');
            if (!is_string($xml) || $xml === '') {
                throw new RuntimeException('docx 正文为空或读取失败');
            }
        } finally {
            $zip->close();
        }

        return $this->xmlToText($xml);
    }

    private function xmlToText(string $xml): string
    {
        $doc = new DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $ok   = $doc->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        if (!$ok || $doc->documentElement === null) {
            throw new RuntimeException('docx 正文格式无法解析');
        }

        $xpath = new DOMXPath($doc);
        $xpath->registerNamespace('w', self::NS_W);

        $lines = [];
        foreach ($xpath->query('//w:p') ?: [] as $para) {
            $buffer = '';
            foreach ($xpath->query('.//w:t', $para) ?: [] as $node) {
                $buffer .= $node->textContent;
            }
            $lines[] = $buffer;
        }

        $text = implode("\n", $lines);
        if (trim($text) === '') {
            throw new RuntimeException('docx 里没有可读正文（可能整篇都是图片）');
        }

        return $text;
    }
}
