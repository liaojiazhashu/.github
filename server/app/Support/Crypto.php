<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

/**
 * AES-256-GCM 对称加密，用于 TOTP 密钥、作者联系方式等。
 *
 * 封装格式（再 base64）：version(1) ‖ nonce(12) ‖ tag(16) ‖ ciphertext
 * - version 便于将来轮换算法；
 * - AAD 固定为 'hqlib:v1'，防止密文被挪到别的用途上重放。
 */
final class Crypto
{
    private const CIPHER  = 'aes-256-gcm';
    private const VERSION = "\x01";
    private const AAD     = 'hqlib:v1';

    private readonly string $key;

    public function __construct(Config $config)
    {
        $encoded = $config->string('app.kek');
        if ($encoded === '') {
            throw new RuntimeException('APP_KEK 未配置，拒绝启动（密钥缺失时加密等于明文）');
        }
        $key = base64_decode($encoded, true);
        if ($key === false || strlen($key) !== 32) {
            throw new RuntimeException('APP_KEK 必须是 32 字节密钥的 base64 编码');
        }
        $this->key = $key;
    }

    public function seal(string $plain): string
    {
        $nonce = random_bytes(12);
        $tag   = '';
        $ct    = openssl_encrypt($plain, self::CIPHER, $this->key, OPENSSL_RAW_DATA, $nonce, $tag, self::AAD, 16);
        if ($ct === false) {
            throw new RuntimeException('加密失败');
        }
        return base64_encode(self::VERSION . $nonce . $tag . $ct);
    }

    public function open(string $sealed): string
    {
        $raw = base64_decode($sealed, true);
        if ($raw === false || strlen($raw) < 1 + 12 + 16) {
            throw new RuntimeException('密文格式非法');
        }
        if ($raw[0] !== self::VERSION) {
            throw new RuntimeException('密文版本不支持');
        }
        $nonce = substr($raw, 1, 12);
        $tag   = substr($raw, 13, 16);
        $ct    = substr($raw, 29);
        $plain = openssl_decrypt($ct, self::CIPHER, $this->key, OPENSSL_RAW_DATA, $nonce, $tag, self::AAD);
        if ($plain === false) {
            throw new RuntimeException('解密失败（密钥不匹配或密文被篡改）');
        }
        return $plain;
    }

    /**
     * 把不可逆的标识（IP / UA / 会话 ID）压成定长哈希后落库：
     * 既能等值比对，又不把原文写进数据库（隐私 + 泄露防护，§8.0.5）。
     */
    public function digest(string $value): string
    {
        return hash_hmac('sha256', $value, $this->key);
    }
}
