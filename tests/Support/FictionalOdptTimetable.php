<?php

declare(strict_types=1);

namespace App\Tests\Support;

/**
 * TEST ONLY. Fictional odpt:FlightSchedule records in the shape of the
 * verified responses (docs/data-source-verification.md §3, §5). Flight
 * numbers, times and notes are invented; the ZZ designator and ZZZ airport
 * are placeholders so these never pass for a real timetable. Real
 * responses are never copied into tests (docs/evidence/README.md).
 */
final class FictionalOdptTimetable
{
    /**
     * @return array<string, mixed>
     */
    public static function departureRecord(string $calendar = 'Monday', string $destination = 'ZZZ'): array
    {
        return self::record('TKN', $destination, $calendar, [
            self::flight('ZZ1001', '09:00', '09:40'),
            self::flight('ZZ1003', '15:00', '15:40', ['ja' => 'テスト用の備考']),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function arrivalRecord(string $calendar = 'Monday', string $origin = 'ZZZ'): array
    {
        return self::record($origin, 'TKN', $calendar, [
            self::flight('ZZ1002', '10:30', '11:10'),
        ]);
    }

    /**
     * @param list<array<string, mixed>> $flights
     *
     * @return array<string, mixed>
     */
    public static function record(string $origin, string $destination, string $calendar, array $flights): array
    {
        return [
            '@id' => 'urn:uuid:00000000-0000-0000-0000-000000000000',
            '@type' => 'odpt:FlightSchedule',
            'dc:date' => '2026-08-21T13:00:00+09:00',
            '@context' => 'http://vocab.odpt.org/context_odpt_FlightSchedule.jsonld',
            'owl:sameAs' => \sprintf('odpt.FlightSchedule:JAL.%s.%s.%s', $origin, $destination, $calendar),
            'odpt:calendar' => 'odpt.Calendar:'.$calendar,
            'odpt:operator' => 'odpt.Operator:JAL',
            'odpt:originAirport' => 'odpt.Airport:'.$origin,
            'odpt:destinationAirport' => 'odpt.Airport:'.$destination,
            'odpt:flightScheduleObject' => $flights,
        ];
    }

    /**
     * @param array<string, string>|null $note
     *
     * @return array<string, mixed>
     */
    public static function flight(string $number, string $originTime, string $destinationTime, ?array $note = null): array
    {
        $flight = [
            'odpt:airline' => 'odpt.Operator:JAL',
            'odpt:isValidTo' => '2027-01-04T23:59:59+09:00',
            'odpt:originTime' => $originTime,
            'odpt:isValidFrom' => '2026-10-25T00:00:00+09:00',
            'odpt:aircraftType' => 'ZZZ',
            'odpt:flightNumber' => [$number],
            'odpt:destinationTime' => $destinationTime,
        ];
        if (null !== $note) {
            $flight['odpt:note'] = $note;
        }

        return $flight;
    }
}
