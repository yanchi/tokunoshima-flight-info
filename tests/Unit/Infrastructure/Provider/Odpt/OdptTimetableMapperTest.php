<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Provider\Odpt;

use App\Application\Port\TimetableFetchFailed;
use App\Domain\Flight\Direction;
use App\Domain\Flight\Weekday;
use App\Infrastructure\Provider\Odpt\OdptTimetableMapper;
use App\Tests\Support\FictionalOdptTimetable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Uses fictional records only (see FictionalOdptTimetable).
 */
final class OdptTimetableMapperTest extends TestCase
{
    private OdptTimetableMapper $mapper;

    protected function setUp(): void
    {
        $this->mapper = new OdptTimetableMapper();
    }

    public function testMapsDepartureRecords(): void
    {
        $startedAt = new \DateTimeImmutable('2026-11-12T09:00:00+09:00');

        $result = $this->mapper->map(Direction::Departure, [
            FictionalOdptTimetable::departureRecord('Monday'),
            FictionalOdptTimetable::departureRecord('Sunday'),
        ], $startedAt);

        self::assertSame(Direction::Departure, $result->direction);
        self::assertSame(2, $result->recordCount);
        self::assertCount(4, $result->entries);
        self::assertSame('2026-11-12T00:00:00+00:00', $result->requestStartedAt->format(\DATE_ATOM));
        self::assertSame('2026-08-21T13:00:00+09:00', $result->latestDataDate->format(\DATE_ATOM));

        $first = $result->entries[0];
        self::assertSame('ZZ1001', $first->flightNumber->value);
        self::assertSame('TKN', $first->originAirport->value);
        self::assertSame('ZZZ', $first->destinationAirport->value);
        self::assertSame(Weekday::Monday, $first->weekday);
        self::assertSame('09:00', $first->originTime);
        self::assertSame('09:40', $first->destinationTime);
        self::assertSame('2026-10-25T00:00:00+09:00', $first->validFrom->format(\DATE_ATOM));
        self::assertSame('2027-01-04T23:59:59+09:00', $first->validTo->format(\DATE_ATOM));
        self::assertSame('odpt.Operator:JAL', $first->airline);
        self::assertNull($first->note);

        self::assertSame('テスト用の備考', $result->entries[1]->note);
        self::assertSame(Weekday::Sunday, $result->entries[3]->weekday);
    }

    public function testMissingDayDifferenceIsNullNotZero(): void
    {
        $result = $this->mapper->map(Direction::Arrival, [FictionalOdptTimetable::arrivalRecord()], new \DateTimeImmutable());

        self::assertNull($result->entries[0]->originDayDifference);
        self::assertNull($result->entries[0]->destinationDayDifference);
    }

    public function testKeepsDayDifferenceWhenPresent(): void
    {
        $flight = FictionalOdptTimetable::flight('ZZ1002', '23:30', '00:10');
        $flight['odpt:originDayDifference'] = 0;
        $flight['odpt:destinationDayDifference'] = 1;

        $result = $this->mapper->map(Direction::Arrival, [
            FictionalOdptTimetable::record('ZZZ', 'TKN', 'Friday', [$flight]),
        ], new \DateTimeImmutable());

        self::assertSame(0, $result->entries[0]->originDayDifference);
        self::assertSame(1, $result->entries[0]->destinationDayDifference);
    }

    public function testLatestDataDateIsTheNewestRecordDate(): void
    {
        $older = FictionalOdptTimetable::arrivalRecord('Monday');
        $newer = FictionalOdptTimetable::arrivalRecord('Tuesday');
        $newer['dc:date'] = '2026-09-01T10:00:00+09:00';

        $result = $this->mapper->map(Direction::Arrival, [$newer, $older], new \DateTimeImmutable());

        self::assertSame('2026-09-01T10:00:00+09:00', $result->latestDataDate->format(\DATE_ATOM));
    }

    public function testNullDayDifferenceIsNullNotZero(): void
    {
        $flight = FictionalOdptTimetable::flight('ZZ1002', '10:30', '11:10');
        $flight['odpt:originDayDifference'] = null;
        $flight['odpt:destinationDayDifference'] = null;

        $result = $this->mapper->map(Direction::Arrival, [
            FictionalOdptTimetable::record('ZZZ', 'TKN', 'Monday', [$flight]),
        ], new \DateTimeImmutable());

        self::assertNull($result->entries[0]->originDayDifference);
        self::assertNull($result->entries[0]->destinationDayDifference);
    }

    public function testOneRecordWithoutFlightsIsAllowed(): void
    {
        $result = $this->mapper->map(Direction::Arrival, [
            FictionalOdptTimetable::record('ZZZ', 'TKN', 'Monday', []),
            FictionalOdptTimetable::arrivalRecord('Tuesday'),
        ], new \DateTimeImmutable());

        self::assertSame(2, $result->recordCount);
        self::assertCount(1, $result->entries);
    }

    public function testAcceptsUtcOffsetWrittenAsZ(): void
    {
        $record = FictionalOdptTimetable::arrivalRecord();
        $record['dc:date'] = '2026-08-21T04:00:00Z';

        $result = $this->mapper->map(Direction::Arrival, [$record], new \DateTimeImmutable());

        self::assertSame('2026-08-21T13:00:00+09:00', $result->latestDataDate->setTimezone(new \DateTimeZone('Asia/Tokyo'))->format(\DATE_ATOM));
    }

    public function testConsecutivePeriodsOfOneFlightAreAllowed(): void
    {
        $until = FictionalOdptTimetable::flight('ZZ1002', '10:30', '11:10');
        $until['odpt:isValidFrom'] = '2026-09-01T00:00:00+09:00';
        $until['odpt:isValidTo'] = '2026-10-24T23:59:59+09:00';
        $from = FictionalOdptTimetable::flight('ZZ1002', '10:00', '10:40');

        $result = $this->mapper->map(Direction::Arrival, [
            FictionalOdptTimetable::record('ZZZ', 'TKN', 'Monday', [$until, $from]),
        ], new \DateTimeImmutable());

        self::assertCount(2, $result->entries);
    }

    /**
     * @return iterable<string, array{Direction, mixed}>
     */
    public static function invalidResponses(): iterable
    {
        $departure = FictionalOdptTimetable::departureRecord();
        $flight = FictionalOdptTimetable::flight('ZZ1001', '09:00', '09:40');
        $withFlight = static fn (array $f): array => FictionalOdptTimetable::record('TKN', 'ZZZ', 'Monday', [$f]);
        $withRecord = static fn (string $key, mixed $value): array => [array_replace($departure, [$key => $value])];
        $withoutRecord = static function (string $key) use ($departure): array {
            unset($departure[$key]);

            return [$departure];
        };
        $withFlightValue = static fn (string $key, mixed $value): array => [$withFlight(array_replace($flight, [$key => $value]))];
        $withoutFlightValue = static function (string $key) use ($flight, $withFlight): array {
            unset($flight[$key]);

            return [$withFlight($flight)];
        };

        yield 'not an array' => [Direction::Departure, 'Require acl:consumerKey.'];
        yield 'object instead of list' => [Direction::Departure, ['a' => $departure]];
        yield 'empty array' => [Direction::Departure, []];
        yield 'no flights in any record' => [Direction::Departure, [
            FictionalOdptTimetable::record('TKN', 'ZZZ', 'Monday', []),
            FictionalOdptTimetable::record('TKN', 'ZZZ', 'Tuesday', []),
        ]];
        yield 'flights key missing' => [Direction::Departure, $withoutRecord('odpt:flightScheduleObject')];
        yield 'possibly truncated' => [Direction::Departure, array_fill(0, OdptTimetableMapper::RECORD_LIMIT, $departure)];
        yield 'record is not an object' => [Direction::Departure, ['x']];
        yield 'wrong @type' => [Direction::Departure, $withRecord('@type', 'odpt:FlightInformationDeparture')];
        yield 'wrong operator' => [Direction::Departure, $withRecord('odpt:operator', 'odpt.Operator:ZZZ')];
        yield 'dc:date missing' => [Direction::Departure, $withoutRecord('dc:date')];
        yield 'dc:date without time zone' => [Direction::Departure, $withRecord('dc:date', '2026-08-21T13:00:00')];
        yield 'dc:date overflowing' => [Direction::Departure, $withRecord('dc:date', '2026-02-30T13:00:00+09:00')];
        yield 'dc:date offset out of range' => [Direction::Departure, $withRecord('dc:date', '2026-08-21T13:00:00+99:99')];
        yield 'dc:date offset other than JST or UTC' => [Direction::Departure, $withRecord('dc:date', '2026-08-21T13:00:00-05:00')];
        yield 'dc:date trailing newline' => [Direction::Departure, $withRecord('dc:date', "2026-08-21T13:00:00+09:00\n")];
        yield 'origin airport invalid' => [Direction::Departure, $withRecord('odpt:originAirport', 'odpt.Airport:tkn')];
        yield 'destination airport missing' => [Direction::Departure, $withoutRecord('odpt:destinationAirport')];
        yield 'departure not from TKN' => [Direction::Departure, [FictionalOdptTimetable::arrivalRecord()]];
        yield 'arrival not to TKN' => [Direction::Arrival, [$departure]];
        yield 'unsupported calendar' => [Direction::Departure, $withRecord('odpt:calendar', 'odpt.Calendar:Holiday')];
        yield 'calendar without prefix' => [Direction::Departure, $withRecord('odpt:calendar', 'Monday')];
        yield 'duplicate route and calendar' => [Direction::Departure, [$departure, $departure]];
        yield 'flights not a list' => [Direction::Departure, $withRecord('odpt:flightScheduleObject', ['a' => $flight])];
        yield 'flight is not an object' => [Direction::Departure, $withRecord('odpt:flightScheduleObject', ['x'])];
        yield 'no flight number' => [Direction::Departure, $withoutFlightValue('odpt:flightNumber')];
        yield 'flight number not a list' => [Direction::Departure, $withFlightValue('odpt:flightNumber', 'ZZ1001')];
        yield 'code-share flight numbers' => [Direction::Departure, $withFlightValue('odpt:flightNumber', ['ZZ1001', 'ZZ2001'])];
        yield 'invalid flight number' => [Direction::Departure, $withFlightValue('odpt:flightNumber', ['zz1001'])];
        yield 'origin time missing' => [Direction::Departure, $withoutFlightValue('odpt:originTime')];
        yield 'origin time 24:00' => [Direction::Departure, $withFlightValue('odpt:originTime', '24:00')];
        yield 'destination time with seconds' => [Direction::Departure, $withFlightValue('odpt:destinationTime', '09:40:00')];
        yield 'valid from missing' => [Direction::Departure, $withoutFlightValue('odpt:isValidFrom')];
        yield 'valid to without time zone' => [Direction::Departure, $withFlightValue('odpt:isValidTo', '2027-01-04T23:59:59')];
        yield 'valid from after valid to' => [Direction::Departure, $withFlightValue('odpt:isValidFrom', '2027-01-05T00:00:00+09:00')];
        yield 'overlapping periods of one flight' => [Direction::Departure, [FictionalOdptTimetable::record('TKN', 'ZZZ', 'Monday', [
            $flight,
            array_replace($flight, ['odpt:originTime' => '10:00', 'odpt:isValidFrom' => '2027-01-04T00:00:00+09:00']),
        ])]];
        yield 'day difference not an integer' => [Direction::Departure, $withFlightValue('odpt:destinationDayDifference', '1')];
        yield 'note as plain string' => [Direction::Departure, $withFlightValue('odpt:note', 'テスト用の備考')];
        yield 'note without Japanese' => [Direction::Departure, $withFlightValue('odpt:note', ['en' => 'test note'])];
        yield 'note empty' => [Direction::Departure, $withFlightValue('odpt:note', ['ja' => ' '])];
        yield 'airline missing' => [Direction::Departure, $withoutFlightValue('odpt:airline')];
    }

    #[DataProvider('invalidResponses')]
    public function testInvalidResponseFailsTheWholeDirection(Direction $direction, mixed $body): void
    {
        $this->expectException(TimetableFetchFailed::class);

        $this->mapper->map($direction, $body, new \DateTimeImmutable());
    }

    public function testOneInvalidFlightFailsEvenIfOthersAreValid(): void
    {
        $broken = FictionalOdptTimetable::flight('ZZ1005', '25:00', '25:40');

        $this->expectException(TimetableFetchFailed::class);
        $this->expectExceptionMessage('record 1 flight 1 has an invalid odpt:originTime');

        $this->mapper->map(Direction::Departure, [
            FictionalOdptTimetable::departureRecord('Monday'),
            FictionalOdptTimetable::record('TKN', 'ZZZ', 'Tuesday', [
                FictionalOdptTimetable::flight('ZZ1001', '09:00', '09:40'),
                $broken,
            ]),
        ], new \DateTimeImmutable());
    }
}
