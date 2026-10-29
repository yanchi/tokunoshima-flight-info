<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Flight;

use App\Domain\Flight\ServiceDate;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ServiceDateTest extends TestCase
{
    public function testFromStringKeepsDate(): void
    {
        self::assertSame('2026-10-27', ServiceDate::fromString('2026-10-27')->toString());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidDates(): iterable
    {
        yield 'overflowing day' => ['2026-02-30'];
        yield 'missing zero padding' => ['2026-1-05'];
        yield 'with time' => ['2026-10-27 10:00'];
        yield 'empty' => [''];
    }

    #[DataProvider('invalidDates')]
    public function testFromStringRejectsInvalidDate(string $value): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ServiceDate::fromString($value);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function instantsAroundJstMidnight(): iterable
    {
        yield 'UTC 14:59 is JST 23:59 same day' => ['2026-10-27T14:59:59+00:00', '2026-10-27'];
        yield 'UTC 15:00 is JST 00:00 next day' => ['2026-10-27T15:00:00+00:00', '2026-10-28'];
        yield 'JST offset given explicitly' => ['2026-10-28T00:30:00+09:00', '2026-10-28'];
        yield 'year boundary' => ['2026-12-31T15:00:00+00:00', '2027-01-01'];
    }

    #[DataProvider('instantsAroundJstMidnight')]
    public function testFromInstantUsesJst(string $instant, string $expected): void
    {
        self::assertSame($expected, ServiceDate::fromInstant(new \DateTimeImmutable($instant))->toString());
    }

    public function testEquals(): void
    {
        $date = ServiceDate::fromString('2026-10-27');

        self::assertTrue($date->equals(ServiceDate::fromInstant(new \DateTimeImmutable('2026-10-27T00:00:00+00:00'))));
        self::assertFalse($date->equals(ServiceDate::fromString('2026-10-28')));
    }

    public function testToDateTimeIsJstMidnight(): void
    {
        self::assertSame(
            '2026-10-27T00:00:00+09:00',
            ServiceDate::fromString('2026-10-27')->toDateTime()->format(\DateTimeInterface::ATOM),
        );
    }
}
