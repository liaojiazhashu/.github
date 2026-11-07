<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Application\Library\IssueSchedule;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * 期次日历：规则 4.1 里最容易写错的两件事 —— 期次不等长、边界在早上 7 点。
 */
final class IssueScheduleTest extends TestCase
{
    public function testFirstIssueStartsOn2024NewYear(): void
    {
        $p = IssueSchedule::period(1);

        self::assertSame('2024-01-01 07:00:00', $p['start']->format('Y-m-d H:i:s'));
        self::assertSame('2024-03-01 07:00:00', $p['end']->format('Y-m-d H:i:s'));
        self::assertSame('1-2 月', $p['months']);
    }

    public function testCurrentIssueIsTheDocumentedOne(): void
    {
        // 规则文档与官网都写着：当前第 25 期 = 2026-09-01 07:00 → 2026-11-01 07:00
        $p = IssueSchedule::period(25);

        self::assertSame('2026-09-01 07:00:00', $p['start']->format('Y-m-d H:i:s'));
        self::assertSame('2026-11-01 07:00:00', $p['end']->format('Y-m-d H:i:s'));
        self::assertSame('9-10 月', $p['months']);
    }

    public function testDecemberIssueCrossesTheYear(): void
    {
        $p = IssueSchedule::period(9);

        self::assertSame('2024-12-01 07:00:00', $p['start']->format('Y-m-d H:i:s'));
        self::assertSame('2025-01-01 07:00:00', $p['end']->format('Y-m-d H:i:s'));
    }

    public function testNineIssuesPerYear(): void
    {
        $inYear = 0;
        for ($no = 1; $no <= 60; $no++) {
            if ($no > 1 && (int) IssueSchedule::period($no)['start']->format('n') === 1) {
                break;
            }
            $inYear = $no;
        }

        self::assertSame(9, $inYear, '2024 年应当正好排 9 期');
        self::assertSame('2025-01-01 07:00:00', IssueSchedule::period(10)['start']->format('Y-m-d H:i:s'));
    }

    public function testPeriodsAreContiguousForThirtyIssues(): void
    {
        // 这条是全类的核心不变式：上一期的结束就是下一期的开始，一秒不差
        for ($no = 1; $no < 30; $no++) {
            self::assertSame(
                IssueSchedule::period($no)['end']->format('c'),
                IssueSchedule::period($no + 1)['start']->format('c'),
                sprintf('第 %d 期与第 %d 期之间有空隙', $no, $no + 1)
            );
        }
    }

    /**
     * @return array<string, array{string,int}>
     */
    public static function momentProvider(): array
    {
        return [
            '开期前一分钟'   => ['2026-09-01 06:59:00', 24],
            '开期那一分钟'   => ['2026-09-01 07:00:00', 25],
            '截稿前一分钟'   => ['2026-11-01 06:59:59', 25],
            '截稿那一分钟'   => ['2026-11-01 07:00:00', 26],
            '期中'           => ['2026-10-15 12:00:00', 25],
            '跨年凌晨'       => ['2025-01-01 03:00:00', 9],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('momentProvider')]
    public function testNoAtRespectsTheSevenOClockCutoff(string $moment, int $expected): void
    {
        self::assertSame($expected, IssueSchedule::noAt(new DateTimeImmutable($moment)));
    }

    public function testRejectsIssueZero(): void
    {
        $this->expectException(InvalidArgumentException::class);
        IssueSchedule::period(0);
    }
}
