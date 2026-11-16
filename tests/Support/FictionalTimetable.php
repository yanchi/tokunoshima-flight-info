<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Application\DTO\TimetableEntry;
use App\Application\DTO\TimetableFetchResult;
use App\Application\Gate\ComparedPeriod;
use App\Application\Gate\TimetableGateSettings;
use App\Domain\Flight\AirportCode;
use App\Domain\Flight\Direction;
use App\Domain\Flight\FlightNumber;
use App\Domain\Flight\ServiceDate;
use App\Domain\Flight\Weekday;

/**
 * TEST ONLY. Fictional stored timetables and gate settings. The ZZ
 * designator and ZZZ airport are placeholders so these never pass for a
 * real timetable; hashes and the commit are placeholders too.
 */
final class FictionalTimetable
{
    public static function departure(
        string $flightNumber,
        Weekday $weekday,
        string $originTime,
        ?string $note = null,
        string $validFrom = '2026-10-25',
        string $validTo = '2027-01-04',
    ): TimetableEntry {
        return self::entry($flightNumber, 'TKN', 'ZZZ', $weekday, $originTime, self::plus40($originTime), $note, $validFrom, $validTo);
    }

    public static function arrival(
        string $flightNumber,
        Weekday $weekday,
        string $destinationTime,
        ?string $note = null,
        string $validFrom = '2026-10-25',
        string $validTo = '2027-01-04',
    ): TimetableEntry {
        return self::entry($flightNumber, 'ZZZ', 'TKN', $weekday, self::minus40($destinationTime), $destinationTime, $note, $validFrom, $validTo);
    }

    /**
     * @param list<TimetableEntry> $entries
     */
    public static function fetch(Direction $direction, string $requestStartedAt, array $entries): TimetableFetchResult
    {
        return new TimetableFetchResult(
            $direction,
            new \DateTimeImmutable($requestStartedAt),
            new \DateTimeImmutable('2026-08-21T13:00:00+09:00'),
            1,
            $entries,
        );
    }

    public static function settings(string $from, string $to): TimetableGateSettings
    {
        return new TimetableGateSettings(
            new ComparedPeriod(ServiceDate::fromString($from), ServiceDate::fromString($to)),
            ServiceDate::fromString($from),
            '0123456789abcdef0123456789abcdef01234567',
            new \DateTimeImmutable('2026-08-21T13:00:00+09:00'),
            new \DateTimeImmutable('2026-08-21T13:00:00+09:00'),
            str_repeat('0', 64),
            str_repeat('f', 64),
        );
    }

    private static function entry(
        string $flightNumber,
        string $origin,
        string $destination,
        Weekday $weekday,
        string $originTime,
        string $destinationTime,
        ?string $note,
        string $validFrom,
        string $validTo,
    ): TimetableEntry {
        return new TimetableEntry(
            FlightNumber::fromString($flightNumber),
            AirportCode::fromString($origin),
            AirportCode::fromString($destination),
            $weekday,
            $originTime,
            $destinationTime,
            null,
            null,
            ServiceDate::fromString($validFrom),
            ServiceDate::fromString($validTo),
            $note,
            'odpt.Operator:ZZ',
        );
    }

    private static function plus40(string $time): string
    {
        return new \DateTimeImmutable('2000-01-01 '.$time)->modify('+40 minutes')->format('H:i');
    }

    private static function minus40(string $time): string
    {
        return new \DateTimeImmutable('2000-01-01 '.$time)->modify('-40 minutes')->format('H:i');
    }
}
