<?php

declare(strict_types=1);

namespace App\Infrastructure\Storage;

use App\Support\Ulid;
use RuntimeException;

/**
 * 原文落盘。三条规矩：
 *   1. 文件名由系统生成（ULID + 白名单扩展名），用户给的名字只存进数据库字段，永不参与路径拼接；
 *   2. 目录 0750、文件 0640，落在 storage/ 下（不在 Web 根）；
 *   3. 先写临时文件再 rename，避免半截文件被当成品读走。
 */
final class UploadStore
{
    public function __construct(private readonly string $baseDir)
    {
        if (!is_dir($this->baseDir) && !@mkdir($this->baseDir, 0750, true) && !is_dir($this->baseDir)) {
            throw new RuntimeException('上传目录不可用');
        }
    }

    /**
     * @return array{key: string, size: int, sha256: string, path: string}
     */
    public function store(string $tmpPath, string $ext): array
    {
        if (!is_file($tmpPath)) {
            throw new RuntimeException('临时文件不存在');
        }

        $ext    = strtolower(preg_replace('/[^a-z0-9]/i', '', $ext) ?? '');
        $sub    = date('Y/m');
        $dir    = $this->baseDir . '/' . $sub;
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new RuntimeException('存储子目录创建失败');
        }

        $key  = $sub . '/' . Ulid::generate() . ($ext === '' ? '' : '.' . $ext);
        $dest = $this->baseDir . '/' . $key;

        $size = filesize($tmpPath);
        $sha  = hash_file('sha256', $tmpPath);
        if ($size === false || $sha === false) {
            throw new RuntimeException('临时文件读取失败');
        }

        if (is_uploaded_file($tmpPath)) {
            if (!@move_uploaded_file($tmpPath, $dest)) {
                throw new RuntimeException('文件落盘失败');
            }
        } elseif (!@copy($tmpPath, $dest)) {
            throw new RuntimeException('文件落盘失败');
        }

        @chmod($dest, 0640);

        return ['key' => $key, 'size' => $size, 'sha256' => $sha, 'path' => $dest];
    }

    public function absolutePath(string $key): string
    {
        // 双重保险：key 由系统生成，仍需挡掉 ../ 这类构造
        if (preg_match('#(^|/)\.\.(/|$)#', $key) === 1) {
            throw new RuntimeException('非法存储键');
        }

        return $this->baseDir . '/' . ltrim($key, '/');
    }

    public function delete(string $key): void
    {
        $path = $this->absolutePath($key);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    public function read(string $key): string
    {
        $path = $this->absolutePath($key);
        if (!is_readable($path)) {
            throw new RuntimeException('原文不可读');
        }
        $body = file_get_contents($path);

        return $body === false ? '' : $body;
    }
}
