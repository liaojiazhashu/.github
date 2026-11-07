<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\LogMaskingProcessor;
use PHPUnit\Framework\TestCase;

/**
 * 日志脱敏：判的是「该藏的藏住了」，以及「不该动的一个字都别改」。
 * 后者同样重要 —— 过度脱敏会把排查问题需要的信息一起吃掉。
 */
final class LogMaskingProcessorTest extends TestCase
{
    public function testMasksApiKey(): void
    {
        self::assertSame('key=sk-***', LogMaskingProcessor::mask('key=sk-abcdefgh1234567890'));
    }

    public function testMasksEmailButKeepsDomain(): void
    {
        $masked = LogMaskingProcessor::mask('联系 reader.name+tag@example.com 处理');

        self::assertStringContainsString('r***@example.com', $masked);
        self::assertStringNotContainsString('reader.name', $masked);
    }

    public function testMasksPhoneKeepingPrefixAndSuffix(): void
    {
        self::assertStringContainsString('138****5678', LogMaskingProcessor::mask('手机 13812345678 已记录'));
    }

    public function testDoesNotMaskUnexpectedDigitStrings(): void
    {
        // 26 位 ULID 里也会出现 1 开头的长串，不能被当成手机号切碎
        $ulid = '01M61HYFQ9KQD9B8B509AC5QKK';
        self::assertSame($ulid, LogMaskingProcessor::mask($ulid));

        // 13 位以上的数字串不是手机号
        self::assertStringContainsString('138123456789', LogMaskingProcessor::mask('流水号 138123456789'));
    }

    public function testMasksCredentialPairs(): void
    {
        $masked = LogMaskingProcessor::mask('password=hunter2&token=abc123def456');

        self::assertStringNotContainsString('hunter2', $masked);
        self::assertStringNotContainsString('abc123def456', $masked);
        self::assertStringContainsString('password=***', $masked);
    }

    public function testMasksBearerToken(): void
    {
        // 带 Authorization 头时整段值抹掉（Bearer 与 token 之间是空格，只抹一个词会漏）
        self::assertSame(
            'Authorization: ***',
            LogMaskingProcessor::mask('Authorization: Bearer eyJhbGciOi.payload.sig')
        );

        // 没有 Authorization 前缀、只出现 Bearer 时，也要抹掉 token
        self::assertSame('got Bearer ***', LogMaskingProcessor::mask('got Bearer eyJhbGciOi.payload.sig'));
    }

    public function testPlainTextSurvivesUntouched(): void
    {
        $plain = '作品《你的星空》拆解完成，关键词 97 条，耗时 12.4s';

        self::assertSame($plain, LogMaskingProcessor::mask($plain));
    }
}
