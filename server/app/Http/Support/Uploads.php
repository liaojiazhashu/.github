<?php

declare(strict_types=1);

namespace App\Http\Support;

use Psr\Http\Message\UploadedFileInterface;
use RuntimeException;

/**
 * 上传文件归一化：把 Slim 的 UploadedFile 落成可读临时路径，并把错误码翻译成人话。
 *
 * 为什么不直接信任 getClientMediaType()：那是客户端声明的，可以随便伪造。
 * 真正的类型判定分三处做——后缀白名单（这里）、内容结构（DocxExtractor / TextExtractor）、
 * 落盘位置（storage/ 不在 Web 根且不可执行）。
 */
final class Uploads
{
    public static function checkError(UploadedFileInterface $file): void
    {
        $error = $file->getError();

        if ($error === UPLOAD_ERR_OK) {
            return;
        }

        $message = match ($error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => '文件超过服务器允许的大小',
            UPLOAD_ERR_PARTIAL                        => '文件只上传了一部分，请重试',
            UPLOAD_ERR_NO_FILE                        => '没有选择文件',
            UPLOAD_ERR_NO_TMP_DIR                     => '服务器缺少临时目录',
            UPLOAD_ERR_CANT_WRITE                     => '服务器无法写入临时文件',
            UPLOAD_ERR_EXTENSION                      => '上传被服务器扩展中断',
            default                                   => '上传失败',
        };

        throw new RuntimeException($message);
    }

    public static function extensionOf(UploadedFileInterface $file): string
    {
        $name = (string) $file->getClientFilename();
        $ext  = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));

        return preg_replace('/[^a-z0-9]/', '', $ext) ?? '';
    }

    /** 把上传文件搬到临时路径，返回该路径（调用方负责删除）。 */
    public static function toTempPath(UploadedFileInterface $file): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'hqup');
        if ($tmp === false) {
            throw new RuntimeException('无法创建临时文件');
        }

        $file->moveTo($tmp);

        return $tmp;
    }

    /** 用 finfo 读真实 MIME（仅作记录与展示，不作为放行依据）。 */
    public static function sniffMime(string $path): string
    {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) {
            return 'application/octet-stream';
        }
        $mime = finfo_file($finfo, $path);
        finfo_close($finfo);

        return is_string($mime) && $mime !== '' ? $mime : 'application/octet-stream';
    }
}
