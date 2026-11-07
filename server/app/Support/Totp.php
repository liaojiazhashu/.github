<?php

declare(strict_types=1);

namespace App\Support;

/**
 * TOTP（RFC 6238，SHA-1 / 6 位 / 30 秒），自实现以避免为一个算法引第三方包。
 *
 * 校验窗口 ±1 个周期（容忍客户端时钟偏差 30 秒），并且拒绝重复使用同一窗口内的验证码
 * 由调用方按 user.last_totp_step 记录（本期未启用重放标记，生产强制 TOTP 时再补）。
 */
final class Totp
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function verify(string $secretBase32, string $code, int $window = 1, int $period = 30, int $digits = 6): bool
    {
        $code = preg_replace('/\D/', '', $code) ?? '';
        if (strlen($code) !== $digits) {
            return false;
        }

        $key = self::base32Decode($secretBase32);
        if ($key === '') {
            return false;
        }

        $step = (int) floor(time() / $period);

        for ($offset = -$window; $offset <= $window; $offset++) {
            $expected = self::code($key, $step + $offset, $digits);
            if (hash_equals($expected, $code)) {
                return true;
            }
        }

        return false;
    }

    public static function code(string $binaryKey, int $step, int $digits = 6): string
    {
        $time  = pack('N*', 0) . pack('N*', $step);
        $hash  = hash_hmac('sha1', $time, $binaryKey, true);
        $off   = ord(substr($hash, -1)) & 0x0F;
        $slice = substr($hash, $off, 4);
        $value = unpack('N', $slice)[1] & 0x7FFFFFFF;

        return str_pad((string) ($value % (10 ** $digits)), $digits, '0', STR_PAD_LEFT);
    }

    public static function base32Decode(string $input): string
    {
        $input = strtoupper(preg_replace('/[^A-Z2-7]/i', '', $input) ?? '');
        if ($input === '') {
            return '';
        }

        $buffer = 0;
        $bits   = 0;
        $output = '';

        foreach (str_split($input) as $char) {
            $pos = strpos(self::ALPHABET, $char);
            if ($pos === false) {
                return '';
            }
            $buffer = ($buffer << 5) | $pos;
            $bits  += 5;
            if ($bits >= 8) {
                $bits   -= 8;
                $output .= chr(($buffer >> $bits) & 0xFF);
            }
        }

        return $output;
    }

    /** 生成新密钥（base32，无填充，32 字符 = 160 bit）。 */
    public static function generateSecret(): string
    {
        $bytes = random_bytes(20);
        $out   = '';
        $buffer = 0;
        $bits   = 0;
        foreach (str_split($bytes) as $byte) {
            $buffer = ($buffer << 8) | ord($byte);
            $bits  += 8;
            while ($bits >= 5) {
                $bits -= 5;
                $out  .= self::ALPHABET[($buffer >> $bits) & 31];
            }
        }
        if ($bits > 0) {
            $out .= self::ALPHABET[($buffer << (5 - $bits)) & 31];
        }

        return $out;
    }
}
