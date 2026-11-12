<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Gate;

use App\Application\Gate\ComparedPeriod;
use App\Domain\Flight\ServiceDate;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ComparedPeriodTest extends TestCase
{
    public function testSingleDayPeriodIsAccepted(): void
    {
        $period = new ComparedPeriod(self::date('2026-11-01'), self::date('2026-11-01'));

        self::assertTrue($period->overlaps(self::date('2026-11-01'), self::date('2026-11-01')));
    }

    public function testStartAfterEndIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ComparedPeriod(self::date('2026-11-02'), self::date('2026-11-01'));
    }

    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function ranges(): iterable
    {
        yield 'ends the day before' => ['2026-10-01', '2026-10-31', false];
        yield 'ends on the first day' => ['2026-10-01', '2026-11-01', true];
        yield 'inside' => ['2026-11-10', '2026-11-12', true];
        yield 'covers the period' => ['2026-10-01', '2026-12-31', true];
        yield 'starts on the last day' => ['2026-11-30', '2026-12-31', true];
        yield 'starts the day after' => ['2026-12-01', '2026-12-31', false];
    }

    #[DataProvider('ranges')]
    public function testOverlapsIncludesBothEnds(string $from, string $to, bool $expected): void
    {
        self::assertSame($expected, self::period()->overlaps(self::date($from), self::date($to)));
    }

    /**
     * @return iterable<string, array{string, string, string, string}>
     */
    public static function clampedRanges(): iterable
    {
        yield 'covers the period' => ['2026-10-25', '2027-01-04', '2026-11-01', '2026-11-30'];
        yield 'inside' => ['2026-11-05', '2026-11-20', '2026-11-05', '2026-11-20'];
        yield 'ends on the first day' => ['2026-10-01', '2026-11-01', '2026-11-01', '2026-11-01'];
        yield 'starts on the last day' => ['2026-11-30', '2026-12-31', '2026-11-30', '2026-11-30'];
    }

    #[DataProvider('clampedRanges')]
    public function testClampCutsToThePeriod(string $from, string $to, string $expectedFrom, string $expectedTo): void
    {
        [$clampedFrom, $clampedTo] = self::period()->clamp(self::date($from), self::date($to));

        self::assertSame([$expectedFrom, $expectedTo], [$clampedFrom->toString(), $clampedTo->toString()]);
    }

    public function testClampRejectsARangeOutsideThePeriod(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        self::period()->clamp(self::date('2026-12-01'), self::date('2026-12-31'));
    }

    private static function period(): ComparedPeriod
    {
        return new ComparedPeriod(self::date('2026-11-01'), self::date('2026-11-30'));
    }

    private static function date(string $value): ServiceDate
    {
        return ServiceDate::fromString($value);
    }
}
