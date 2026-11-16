<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\UseCase;

use App\Application\DTO\BoardState;
use App\Application\DTO\DailyFlightBoard;
use App\Application\DTO\FlightRow;
use App\Application\DTO\NotedFlightRow;
use App\Application\DTO\TimetableEntry;
use App\Application\Gate\WithdrawalCause;
use App\Application\UseCase\GetDailyFlights;
use App\Domain\Flight\Direction;
use App\Domain\Flight\FlightStatus;
use App\Domain\Flight\ServiceDate;
use App\Domain\Flight\TimetableFreshnessPolicy;
use App\Domain\Flight\Weekday;
use App\Tests\Support\FictionalTimetable;
use App\Tests\Support\InMemoryTimetableGateRepository;
use App\Tests\Support\InMemoryTimetableRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * TEST ONLY data: fictional flights (FictionalTimetable).
 */
final class GetDailyFlightsTest extends TestCase
{
    /** 2026-11-16 12:00 JST, a Monday. */
    private const NOW = '2026-11-16T03:00:00Z';
    private const SOURCE = 'テスト用の取得元';

    private MockClock $clock;
    private InMemoryTimetableRepository $timetable;
    private InMemoryTimetableGateRepository $gate;
    private ServiceDate $monday;

    protected function setUp(): void
    {
        $this->clock = new MockClock(self::NOW);
        $this->timetable = new InMemoryTimetableRepository($this->clock);
        $this->gate = new InMemoryTimetableGateRepository();
        $this->monday = ServiceDate::fromString('2026-11-16');
    }

    public function testNothingFetchedShowsNothing(): void
    {
        $board = $this->useCase()->execute($this->monday, Direction::Departure);

        self::assertSame(BoardState::NeverFetched, $board->state);
        self::assertNull($board->fetchedAt);
        self::assertNull($board->sourceName);
        self::assertSame([], $board->flights);
    }

    public function testFetchedButNoGateVersionIsOutsideThePublishedPeriod(): void
    {
        $this->storeDeparture('2026-11-16T02:00:00Z', [FictionalTimetable::departure('ZZ1001', Weekday::Monday, '09:00')]);

        $board = $this->useCase()->execute($this->monday, Direction::Departure);

        self::assertSame(BoardState::OutsidePublishedPeriod, $board->state);
        self::assertEquals(new \DateTimeImmutable('2026-11-16T02:00:00Z'), $board->fetchedAt);
        self::assertSame(self::SOURCE, $board->sourceName);
        self::assertSame([], $board->flights);
    }

    /**
     * @return iterable<string, array{string, BoardState}>
     */
    public static function daysAroundThePeriod(): iterable
    {
        yield 'day before the period' => ['2026-11-08', BoardState::OutsidePublishedPeriod];
        yield 'first day' => ['2026-11-09', BoardState::Published];
        yield 'last day' => ['2026-11-23', BoardState::Published];
        yield 'day after the period' => ['2026-11-30', BoardState::OutsidePublishedPeriod];
    }

    #[DataProvider('daysAroundThePeriod')]
    public function testPublishesOnlyTheComparedPeriod(string $day, BoardState $expected): void
    {
        $this->clock->modify($day.'T03:00:00Z');
        $this->storeDeparture($day.'T02:00:00Z', [FictionalTimetable::departure('ZZ1001', Weekday::Monday, '09:00')]);
        $this->gate->addVersion(FictionalTimetable::settings('2026-11-09', '2026-11-23'), new \DateTimeImmutable('2026-11-09T00:00:00Z'));

        $board = $this->useCase()->execute(ServiceDate::fromString($day), Direction::Departure);

        self::assertSame($expected, $board->state);
        self::assertCount(BoardState::Published === $expected ? 1 : 0, $board->flights);
    }

    public function testWithdrawnDirectionIsOutsideWhileTheOtherIsPublished(): void
    {
        $this->storeDeparture('2026-11-16T02:00:00Z', [FictionalTimetable::departure('ZZ1001', Weekday::Monday, '09:00')]);
        $this->timetable->store(FictionalTimetable::fetch(Direction::Arrival, '2026-11-16T02:00:00Z', [FictionalTimetable::arrival('ZZ1002', Weekday::Monday, '11:00')]));
        $version = $this->publish();
        $this->gate->withdraw($version, Direction::Departure, WithdrawalCause::ComparedItemsChanged, new \DateTimeImmutable('2026-11-16T02:30:00Z'));

        self::assertSame(BoardState::OutsidePublishedPeriod, $this->useCase()->execute($this->monday, Direction::Departure)->state);
        self::assertSame(BoardState::Published, $this->useCase()->execute($this->monday, Direction::Arrival)->state);
    }

    public function testReadsTheTimetableAndTheGateInOneTransaction(): void
    {
        $this->useCase()->execute($this->monday, Direction::Departure);

        self::assertSame(1, $this->gate->transactions);
    }

    public function testDepartureBoardListsTheDaysFlightsByDepartureTime(): void
    {
        $this->storeDeparture('2026-11-16T02:00:00Z', [
            FictionalTimetable::departure('ZZ1005', Weekday::Monday, '15:00'),
            FictionalTimetable::departure('ZZ1001', Weekday::Monday, '09:00'),
            FictionalTimetable::departure('ZZ1003', Weekday::Tuesday, '10:00'),
        ]);
        $this->publish();

        $board = $this->useCase()->execute($this->monday, Direction::Departure);

        self::assertSame(BoardState::Published, $board->state);
        self::assertFalse($board->fetchOverdue);
        self::assertSame(['ZZ1001', 'ZZ1005'], self::numbers($board->flights));
        $first = $board->flights[0];
        self::assertEquals(new \DateTimeImmutable('2026-11-16T09:00:00+09:00'), $first->scheduledAt);
        self::assertSame('Asia/Tokyo', $first->scheduledAt->getTimezone()->getName());
        self::assertNull($first->estimatedAt);
        self::assertSame(FlightStatus::Scheduled, $first->status);
        self::assertSame(['TKN', 'ZZZ'], [$first->origin->value, $first->destination->value]);
    }

    public function testArrivalBoardUsesAndSortsByTheArrivalTime(): void
    {
        $this->timetable->store(FictionalTimetable::fetch(Direction::Arrival, '2026-11-16T02:00:00Z', [
            FictionalTimetable::arrival('ZZ1004', Weekday::Monday, '18:10'),
            FictionalTimetable::arrival('ZZ1002', Weekday::Monday, '11:10'),
        ]));
        $this->publish();

        $board = $this->useCase()->execute($this->monday, Direction::Arrival);

        self::assertSame(['ZZ1002', 'ZZ1004'], self::numbers($board->flights));
        self::assertEquals(new \DateTimeImmutable('2026-11-16T11:10:00+09:00'), $board->flights[0]->scheduledAt);
    }

    public function testSameTimeIsOrderedByFlightNumber(): void
    {
        $this->storeDeparture('2026-11-16T02:00:00Z', [
            FictionalTimetable::departure('ZZ1009', Weekday::Monday, '09:00'),
            FictionalTimetable::departure('ZZ1001', Weekday::Monday, '09:00'),
        ]);
        $this->publish();

        self::assertSame(['ZZ1001', 'ZZ1009'], self::numbers($this->useCase()->execute($this->monday, Direction::Departure)->flights));
    }

    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function validDays(): iterable
    {
        yield 'valid from the day' => ['2026-11-16', '2027-01-04', true];
        yield 'valid to the day' => ['2026-10-25', '2026-11-16', true];
        yield 'ended the day before' => ['2026-10-25', '2026-11-15', false];
        yield 'starts the day after' => ['2026-11-17', '2027-01-04', false];
    }

    #[DataProvider('validDays')]
    public function testListsAnEntryOnlyOnItsValidDays(string $validFrom, string $validTo, bool $listed): void
    {
        $this->storeDeparture('2026-11-16T02:00:00Z', [FictionalTimetable::departure('ZZ1001', Weekday::Monday, '09:00', null, $validFrom, $validTo)]);
        $this->publish();

        self::assertCount($listed ? 1 : 0, $this->useCase()->execute($this->monday, Direction::Departure)->flights);
    }

    /**
     * The note is never parsed: on the first and last day of the period it
     * names, and outside it, the flight is in the noted list only (§5).
     *
     * @return iterable<string, array{string}>
     */
    public static function daysAroundANotedPeriod(): iterable
    {
        yield 'before the noted period' => ['2026-11-09'];
        yield 'first day of the noted period' => ['2026-11-16'];
        yield 'last day of the noted period' => ['2026-11-23'];
        yield 'after the noted period' => ['2026-11-30'];
    }

    #[DataProvider('daysAroundANotedPeriod')]
    public function testNotedFlightIsOnlyInTheNotedListWhateverTheNoteSays(string $day): void
    {
        $this->clock->modify($day.'T03:00:00Z');
        $this->storeDeparture($day.'T02:00:00Z', [
            FictionalTimetable::departure('ZZ1001', Weekday::Monday, '09:00'),
            FictionalTimetable::departure('ZZ1003', Weekday::Monday, '15:00', '11/16-23・テスト用の備考'),
            FictionalTimetable::departure('ZZ1007', Weekday::Monday, '12:00', '12/28・1/1・テスト用の運航日'),
        ]);
        $this->gate->addVersion(FictionalTimetable::settings('2026-11-09', '2026-11-30'), new \DateTimeImmutable('2026-11-09T00:00:00Z'));

        $board = $this->useCase()->execute(ServiceDate::fromString($day), Direction::Departure);

        self::assertSame(['ZZ1001'], self::numbers($board->flights));
        self::assertSame(['ZZ1007', 'ZZ1003'], self::numbers($board->notedFlights));
        self::assertSame('11/16-23・テスト用の備考', $board->notedFlights[1]->note);
        self::assertSame(FlightStatus::Scheduled, $board->notedFlights[1]->status());
    }

    public function testDayWithoutFlightsIsPublishedAndEmpty(): void
    {
        $this->storeDeparture('2026-11-16T02:00:00Z', [FictionalTimetable::departure('ZZ1003', Weekday::Tuesday, '10:00')]);
        $this->publish();

        $board = $this->useCase()->execute($this->monday, Direction::Departure);

        self::assertSame(BoardState::Published, $board->state);
        self::assertTrue($board->hasNoFlights());
    }

    public function testDayWithOnlyNotedFlightsHasFlights(): void
    {
        $this->storeDeparture('2026-11-16T02:00:00Z', [FictionalTimetable::departure('ZZ1003', Weekday::Monday, '10:00', 'テスト用の備考')]);
        $this->publish();

        self::assertFalse($this->useCase()->execute($this->monday, Direction::Departure)->hasNoFlights());
    }

    /**
     * Thresholds 259200 s (warning) and 604800 s (stop); an age equal to a
     * threshold is still within it (§7 step 7).
     *
     * @return iterable<string, array{string, BoardState, bool}>
     */
    public static function fetchAges(): iterable
    {
        yield 'just fetched' => ['2026-11-16T03:00:00Z', BoardState::Published, false];
        yield 'exactly the warning threshold' => ['2026-11-13T03:00:00Z', BoardState::Published, false];
        yield 'past the warning threshold' => ['2026-11-13T02:59:59Z', BoardState::Published, true];
        yield 'exactly the stop threshold' => ['2026-11-09T03:00:00Z', BoardState::Published, true];
        yield 'past the stop threshold' => ['2026-11-09T02:59:59Z', BoardState::Suspended, false];
    }

    #[DataProvider('fetchAges')]
    public function testFreshnessDecidesWhetherTheTimetableIsShown(string $fetchedAt, BoardState $state, bool $overdue): void
    {
        $this->storeDeparture($fetchedAt, [FictionalTimetable::departure('ZZ1001', Weekday::Monday, '09:00')]);
        $this->publish();

        $board = $this->useCase()->execute($this->monday, Direction::Departure);

        self::assertSame($state, $board->state);
        self::assertSame($overdue, $board->fetchOverdue);
        self::assertCount(BoardState::Published === $state ? 1 : 0, $board->flights);
        self::assertEquals(new \DateTimeImmutable($fetchedAt), $board->fetchedAt);
        self::assertSame(self::SOURCE, $board->sourceName);
    }

    public function testSuspendedWinsOverAnUnpublishedDay(): void
    {
        $this->storeDeparture('2026-11-01T00:00:00Z', [FictionalTimetable::departure('ZZ1001', Weekday::Monday, '09:00')]);

        self::assertSame(BoardState::Suspended, $this->useCase()->execute($this->monday, Direction::Departure)->state);
    }

    public function testFetchTimeInTheFutureHidesTheFetchTime(): void
    {
        $this->storeDeparture('2026-11-16T03:00:01Z', [FictionalTimetable::departure('ZZ1001', Weekday::Monday, '09:00')]);
        $this->publish();

        $board = $this->useCase()->execute($this->monday, Direction::Departure);

        self::assertSame(BoardState::FetchTimeUnknown, $board->state);
        self::assertNull($board->fetchedAt);
        self::assertSame(self::SOURCE, $board->sourceName);
        self::assertSame([], $board->flights);
    }

    public function testInvalidThresholdsAreShownAsSuspended(): void
    {
        $this->storeDeparture('2026-11-16T02:00:00Z', [FictionalTimetable::departure('ZZ1001', Weekday::Monday, '09:00')]);
        $this->publish();

        $board = $this->useCase(new TimetableFreshnessPolicy('604800', '259200'))->execute($this->monday, Direction::Departure);

        self::assertSame(BoardState::Suspended, $board->state);
        self::assertSame([], $board->flights);
    }

    public function testTodayIsTheJstDate(): void
    {
        // 2026-11-15 15:00 UTC is already Monday 11-16 in JST.
        $this->clock->modify('2026-11-15T15:00:00Z');
        $this->storeDeparture('2026-11-15T14:00:00Z', [
            FictionalTimetable::departure('ZZ1001', Weekday::Monday, '09:00'),
            FictionalTimetable::departure('ZZ1003', Weekday::Sunday, '09:00'),
        ]);
        $this->publish();

        $board = $this->useCase()->today(Direction::Departure);

        self::assertSame('2026-11-16', $board->date->toString());
        self::assertSame(['ZZ1001'], self::numbers($board->flights));
    }

    public function testBoardRejectsFlightsOutsideThePublishedState(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new DailyFlightBoard($this->monday, Direction::Departure, BoardState::Suspended, true, [], [], new \DateTimeImmutable(), self::SOURCE);
    }

    public function testBoardRejectsAFetchTimeWhenItMustBeHidden(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new DailyFlightBoard($this->monday, Direction::Departure, BoardState::FetchTimeUnknown, false, [], [], new \DateTimeImmutable(), self::SOURCE);
    }

    public function testBoardRejectsASourceWhenNothingWasFetched(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new DailyFlightBoard($this->monday, Direction::Departure, BoardState::NeverFetched, false, [], [], null, self::SOURCE);
    }

    private function useCase(?TimetableFreshnessPolicy $policy = null): GetDailyFlights
    {
        return new GetDailyFlights(
            $this->timetable,
            $this->gate,
            $policy ?? new TimetableFreshnessPolicy('259200', '604800'),
            $this->clock,
            self::SOURCE,
        );
    }

    /**
     * @param list<TimetableEntry> $entries
     */
    private function storeDeparture(string $requestStartedAt, array $entries): void
    {
        $this->timetable->store(FictionalTimetable::fetch(Direction::Departure, $requestStartedAt, $entries));
    }

    private function publish(): int
    {
        return $this->gate->addVersion(FictionalTimetable::settings('2026-11-09', '2026-11-30'), new \DateTimeImmutable('2026-11-09T00:00:00Z'))->id;
    }

    /**
     * @param list<FlightRow>|list<NotedFlightRow> $rows
     *
     * @return list<string>
     */
    private static function numbers(array $rows): array
    {
        return array_map(static fn (FlightRow|NotedFlightRow $row): string => $row->flightNumber->value, $rows);
    }
}
