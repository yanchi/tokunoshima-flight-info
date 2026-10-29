<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Application\DTO\FlightRow;
use App\Domain\Flight\AirportCode;
use App\Domain\Flight\FlightNumber;
use App\Domain\Flight\FlightStatus;

/**
 * TEST ONLY. Fictional flights. The ZZ designator and ZZZ airport are
 * placeholders assumed not to be in real use (not verified); they are only
 * there so these rows are not mistaken for a real timetable.
 */
final class FictionalFlights
{
    public static function departure(FlightStatus $status, string $scheduledAt, ?string $estimatedAt = null): FlightRow
    {
        return self::row('ZZ1001', 'TKN', 'ZZZ', $status, $scheduledAt, $estimatedAt);
    }

    public static function arrival(FlightStatus $status, string $scheduledAt, ?string $estimatedAt = null): FlightRow
    {
        return self::row('ZZ1002', 'ZZZ', 'TKN', $status, $scheduledAt, $estimatedAt);
    }

    private static function row(
        string $flightNumber,
        string $origin,
        string $destination,
        FlightStatus $status,
        string $scheduledAt,
        ?string $estimatedAt,
    ): FlightRow {
        return new FlightRow(
            FlightNumber::fromString($flightNumber),
            AirportCode::fromString($origin),
            AirportCode::fromString($destination),
            new \DateTimeImmutable($scheduledAt),
            null === $estimatedAt ? null : new \DateTimeImmutable($estimatedAt),
            $status,
        );
    }
}
