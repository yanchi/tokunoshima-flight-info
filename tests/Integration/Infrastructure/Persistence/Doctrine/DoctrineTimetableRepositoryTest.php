<?php

declare(strict_types=1);

namespace App\Tests\Integration\Infrastructure\Persistence\Doctrine;

use App\Application\DTO\TimetableEntry;
use App\Application\DTO\TimetableFetchResult;
use App\Application\Port\TimetableRepositoryInterface;
use App\Application\Port\TimetableSaveOutcome;
use App\Domain\Flight\AirportCode;
use App\Domain\Flight\Direction;
use App\Domain\Flight\FlightNumber;
use App\Domain\Flight\ServiceDate;
use App\Domain\Flight\Weekday;
use App\Infrastructure\Persistence\Doctrine\DoctrineTimetableRepository;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception as DBALException;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * TEST ONLY data: the ZZ designator and ZZZ airport are placeholders, so
 * these entries never pass for a real timetable.
 */
final class DoctrineTimetableRepositoryTest extends KernelTestCase
{
    use ClockSensitiveTrait;

    /** Later than every fixture's fetch time, so none of them is in the future. */
    private const NOW = '2026-11-13T00:00:00Z';

    private TimetableRepositoryInterface $repository;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::mockTime(self::NOW);
        $container = self::getContainer();
        $entityManager = $container->get(EntityManagerInterface::class);
        $this->entityManager = $entityManager;

        $schemaTool = new SchemaTool($entityManager);
        $metadata = $entityManager->getMetadataFactory()->getAllMetadata();
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);

        $this->repository = $container->get('test.timetable_repository');
    }

    public function testNothingIsFoundBeforeTheFirstSave(): void
    {
        self::assertNull($this->repository->findLatest(Direction::Departure));
        self::assertNull($this->repository->findLatest(Direction::Arrival));
    }

    public function testSavedFetchIsReadBackUnchanged(): void
    {
        $saved = self::departures('2026-11-12T03:00:00Z', [
            self::entry('ZZ1001', 'TKN', 'ZZZ', Weekday::Monday, '09:00', '09:40'),
            self::entry('ZZ1003', 'TKN', 'ZZZ', Weekday::Sunday, '15:00', '15:40', note: 'テスト用の備考', originDayDifference: 0, destinationDayDifference: 0),
        ]);

        self::assertSame(TimetableSaveOutcome::Saved, $this->repository->save($saved)->outcome);
        $this->entityManager->clear();

        $found = $this->repository->findLatest(Direction::Departure);
        self::assertNotNull($found);
        self::assertSame(self::described($saved), self::described($found));
        self::assertNull($this->repository->findLatest(Direction::Arrival));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function defaultTimezones(): iterable
    {
        yield 'west of UTC' => ['America/Los_Angeles'];
        yield 'east of JST' => ['Pacific/Kiritimati'];
    }

    #[DataProvider('defaultTimezones')]
    public function testPeriodDaysDoNotDependOnPhpDefaultTimezone(string $timezone): void
    {
        $saved = self::departures('2026-11-12T03:00:00Z', [
            self::entry('ZZ1001', 'TKN', 'ZZZ', Weekday::Monday, '09:00', '09:40'),
        ]);

        $original = date_default_timezone_get();
        date_default_timezone_set($timezone);
        try {
            $this->repository->save($saved);
            $this->entityManager->clear();
            $found = $this->repository->findLatest(Direction::Departure);
        } finally {
            date_default_timezone_set($original);
        }

        self::assertNotNull($found);
        self::assertSame('2026-10-25', $found->entries[0]->validFrom->toString());
        self::assertSame('2027-01-04', $found->entries[0]->validTo->toString());
    }

    public function testRequestStartIsStoredInUtcRoundedDownToTheSecond(): void
    {
        $this->repository->save(self::departures('2026-11-12T12:00:05.987654+09:00'));
        $this->entityManager->clear();

        $found = $this->repository->findLatest(Direction::Departure);
        self::assertNotNull($found);
        self::assertSame('2026-11-12T03:00:05.000000+00:00', $found->requestStartedAt->format('Y-m-d\TH:i:s.uP'));
        self::assertSame('UTC', $found->requestStartedAt->getTimezone()->getName());
    }

    public function testNewerFetchReplacesTheWholeDirection(): void
    {
        $this->repository->save(self::departures('2026-11-12T03:00:00Z', [
            self::entry('ZZ1001', 'TKN', 'ZZZ', Weekday::Monday, '09:00', '09:40'),
            self::entry('ZZ1003', 'TKN', 'ZZZ', Weekday::Monday, '15:00', '15:40'),
        ]));
        $newer = self::departures('2026-11-12T04:00:00Z', [
            self::entry('ZZ1005', 'TKN', 'ZZZ', Weekday::Tuesday, '10:00', '10:40'),
        ], latestDataDate: '2026-11-12T12:30:00+09:00', recordCount: 1);

        self::assertSame(TimetableSaveOutcome::Saved, $this->repository->save($newer)->outcome);
        $this->entityManager->clear();

        // Earlier fetches are not kept (§9 M1).
        self::assertSame(self::described($newer), self::described($this->latest(Direction::Departure)));
        self::assertSame(1, $this->flightRowCount());
    }

    public function testOlderFetchReplacesNothing(): void
    {
        $stored = self::departures('2026-11-12T04:00:00Z');
        $this->repository->save($stored);

        $older = self::departures('2026-11-12T03:59:59Z', [
            self::entry('ZZ1005', 'TKN', 'ZZZ', Weekday::Tuesday, '10:00', '10:40'),
        ]);

        $saved = $this->repository->save($older);
        self::assertSame(TimetableSaveOutcome::OlderThanStored, $saved->outcome);
        self::assertNotNull($saved->previous);
        self::assertSame(self::described($stored), self::described($saved->previous));
        $this->entityManager->clear();
        self::assertSame(self::described($stored), self::described($this->latest(Direction::Departure)));
    }

    public function testFetchInTheSameSecondReplacesNothing(): void
    {
        $stored = self::departures('2026-11-12T04:00:00.100000Z');
        $this->repository->save($stored);

        $sameSecond = self::departures('2026-11-12T04:00:00.900000Z', [
            self::entry('ZZ1005', 'TKN', 'ZZZ', Weekday::Tuesday, '10:00', '10:40'),
        ]);

        self::assertSame(TimetableSaveOutcome::SameSecondAsStored, $this->repository->save($sameSecond)->outcome);
        $this->entityManager->clear();
        self::assertSame(self::described($stored)['entries'], self::described($this->latest(Direction::Departure))['entries']);
    }

    public function testFetchOneSecondLaterReplacesTheStoredOne(): void
    {
        $this->repository->save(self::departures('2026-11-12T04:00:00.900000Z'));

        $nextSecond = self::departures('2026-11-12T04:00:01.000000Z', [
            self::entry('ZZ1005', 'TKN', 'ZZZ', Weekday::Tuesday, '10:00', '10:40'),
        ]);

        self::assertSame(TimetableSaveOutcome::Saved, $this->repository->save($nextSecond)->outcome);
        $this->entityManager->clear();
        self::assertSame(self::described($nextSecond), self::described($this->latest(Direction::Departure)));
    }

    public function testOlderFetchDoesNotReplaceWhatAnotherProcessSavedMeanwhile(): void
    {
        // The record of this save stays managed in this entity manager.
        $this->repository->save(self::departures('2026-11-12T03:00:00Z'));

        // Another process (own connection and entity manager) saves a newer fetch.
        $otherConnection = DriverManager::getConnection(
            $this->entityManager->getConnection()->getParams(),
            $this->entityManager->getConnection()->getConfiguration(),
        );
        $otherEntityManager = new EntityManager($otherConnection, $this->entityManager->getConfiguration());
        $newer = self::departures('2026-11-12T05:00:00Z', [
            self::entry('ZZ1005', 'TKN', 'ZZZ', Weekday::Tuesday, '10:00', '10:40'),
        ]);
        self::assertSame(TimetableSaveOutcome::Saved, new DoctrineTimetableRepository($otherEntityManager, new MockClock(self::NOW))->save($newer)->outcome);
        $otherConnection->close();

        // No clear(): the stale managed record must not be trusted.
        $older = self::departures('2026-11-12T04:00:00Z');
        self::assertSame(TimetableSaveOutcome::OlderThanStored, $this->repository->save($older)->outcome);

        $this->entityManager->clear();
        self::assertSame(self::described($newer), self::described($this->latest(Direction::Departure)));
    }

    public function testDirectionsAreStoredIndependently(): void
    {
        $departures = self::departures('2026-11-12T04:00:00Z');
        $this->repository->save($departures);

        // An older arrival fetch is still the first one for its direction.
        $arrivals = new TimetableFetchResult(
            Direction::Arrival,
            new \DateTimeImmutable('2026-11-12T03:00:00Z'),
            new \DateTimeImmutable('2026-08-21T13:00:00+09:00'),
            1,
            [self::entry('ZZ1002', 'ZZZ', 'TKN', Weekday::Monday, '10:30', '11:10')],
        );
        self::assertSame(TimetableSaveOutcome::Saved, $this->repository->save($arrivals)->outcome);
        $this->repository->save(self::departures('2026-11-12T05:00:00Z'));
        $this->entityManager->clear();

        self::assertSame(self::described($arrivals), self::described($this->latest(Direction::Arrival)));
    }

    public function testFailedSaveKeepsThePreviousFetchAndRecord(): void
    {
        $stored = self::departures('2026-11-12T03:00:00Z');
        $this->repository->save($stored);

        // TEST ONLY: makes the insert of one row fail after the old rows
        // were deleted, so a partial replacement would be visible.
        $this->entityManager->getConnection()->executeStatement(<<<'SQL'
            CREATE TRIGGER test_reject_flight BEFORE INSERT ON timetable_flight
            WHEN NEW.flight_number = 'ZZ9999'
            BEGIN SELECT RAISE(ABORT, 'rejected by test'); END
            SQL);

        $failing = self::departures('2026-11-12T04:00:00Z', [
            self::entry('ZZ1005', 'TKN', 'ZZZ', Weekday::Tuesday, '10:00', '10:40'),
            self::entry('ZZ9999', 'TKN', 'ZZZ', Weekday::Tuesday, '11:00', '11:40'),
        ]);

        try {
            $this->repository->save($failing);
            self::fail('The save should have failed.');
        } catch (DBALException $e) {
            self::assertStringContainsString('rejected by test', $e->getMessage());
        }

        // The repository resets the closed entity manager itself, so the
        // other direction can still be saved in the same run.
        self::assertSame(self::described($stored), self::described($this->latest(Direction::Departure)));
        $arrivals = self::arrivals('2026-11-12T04:00:00Z');
        self::assertSame(TimetableSaveOutcome::Saved, $this->repository->save($arrivals)->outcome);
    }

    public function testFirstSaveHasNoPreviousFetchAndIsJudgedAtTheClockTime(): void
    {
        $saved = $this->repository->save(self::departures('2026-11-12T03:00:00Z'));

        self::assertSame(TimetableSaveOutcome::Saved, $saved->outcome);
        self::assertNull($saved->previous);
        self::assertSame(new \DateTimeImmutable(self::NOW)->getTimestamp(), $saved->judgedAt->getTimestamp());
    }

    public function testReplacingReturnsThePreviousFetch(): void
    {
        $stored = self::departures('2026-11-12T03:00:00Z');
        $this->repository->save($stored);

        $saved = $this->repository->save(self::departures('2026-11-12T04:00:00Z', [
            self::entry('ZZ1005', 'TKN', 'ZZZ', Weekday::Tuesday, '10:00', '10:40'),
        ]));

        self::assertSame(TimetableSaveOutcome::Saved, $saved->outcome);
        self::assertNotNull($saved->previous);
        self::assertSame(self::described($stored), self::described($saved->previous));
    }

    public function testFetchDatedAfterTheJudgmentReplacesNothing(): void
    {
        $stored = self::departures('2026-11-12T03:00:00Z');
        $this->repository->save($stored);

        // The clock was set back mid-fetch.
        $saved = $this->repository->save(self::departures('2026-11-13T00:00:01Z', [
            self::entry('ZZ1005', 'TKN', 'ZZZ', Weekday::Tuesday, '10:00', '10:40'),
        ]));

        self::assertSame(TimetableSaveOutcome::CurrentFetchInFuture, $saved->outcome);
        $this->entityManager->clear();
        self::assertSame(self::described($stored), self::described($this->latest(Direction::Departure)));
    }

    public function testFirstFetchDatedAfterTheJudgmentIsNotSaved(): void
    {
        $saved = $this->repository->save(self::departures('2026-11-13T00:00:01Z'));

        self::assertSame(TimetableSaveOutcome::CurrentFetchInFuture, $saved->outcome);
        self::assertNull($this->repository->findLatest(Direction::Departure));
    }

    public function testStoredRecordInTheFutureIsReplacedByAnEarlierFetch(): void
    {
        $this->repository->save(self::departures('2026-11-12T03:00:00Z'));
        $this->entityManager->getConnection()->executeStatement(
            "UPDATE timetable_fetch_record SET request_started_at = '2026-11-20 00:00:00'",
        );
        $this->entityManager->clear();

        $current = self::departures('2026-11-12T04:00:00Z', [
            self::entry('ZZ1005', 'TKN', 'ZZZ', Weekday::Tuesday, '10:00', '10:40'),
        ]);
        $saved = $this->repository->save($current);

        self::assertSame(TimetableSaveOutcome::SavedOverFutureRecord, $saved->outcome);
        $this->entityManager->clear();
        self::assertSame(self::described($current), self::described($this->latest(Direction::Departure)));
    }

    public function testStoredRecordOnlyJustNotInTheFutureIsJudgedNormally(): void
    {
        $this->repository->save(self::departures('2026-11-12T03:00:00Z'));
        $this->entityManager->getConnection()->executeStatement(
            "UPDATE timetable_fetch_record SET request_started_at = '2026-11-13 00:00:00'",
        );
        $this->entityManager->clear();

        // Equal to now is not the future, so the older fetch replaces nothing.
        self::assertSame(TimetableSaveOutcome::OlderThanStored, $this->repository->save(self::departures('2026-11-12T04:00:00Z'))->outcome);
    }

    private function latest(Direction $direction): TimetableFetchResult
    {
        $found = $this->repository->findLatest($direction);
        self::assertNotNull($found);

        return $found;
    }

    private function flightRowCount(): int
    {
        $count = $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM timetable_flight');
        self::assertIsNumeric($count);

        return (int) $count;
    }

    private static function arrivals(string $requestStartedAt): TimetableFetchResult
    {
        return new TimetableFetchResult(
            Direction::Arrival,
            new \DateTimeImmutable($requestStartedAt)->setTimezone(new \DateTimeZone('UTC')),
            new \DateTimeImmutable('2026-08-21T13:00:00+09:00'),
            1,
            [self::entry('ZZ1002', 'ZZZ', 'TKN', Weekday::Monday, '10:30', '11:10')],
        );
    }

    /**
     * @param list<TimetableEntry>|null $entries
     */
    private static function departures(
        string $requestStartedAt,
        ?array $entries = null,
        string $latestDataDate = '2026-08-21T13:00:00+09:00',
        int $recordCount = 2,
    ): TimetableFetchResult {
        return new TimetableFetchResult(
            Direction::Departure,
            new \DateTimeImmutable($requestStartedAt)->setTimezone(new \DateTimeZone('UTC')),
            new \DateTimeImmutable($latestDataDate),
            $recordCount,
            $entries ?? [
                self::entry('ZZ1001', 'TKN', 'ZZZ', Weekday::Monday, '09:00', '09:40'),
                self::entry('ZZ1003', 'TKN', 'ZZZ', Weekday::Monday, '15:00', '15:40', note: 'テスト用の備考'),
            ],
        );
    }

    private static function entry(
        string $flightNumber,
        string $origin,
        string $destination,
        Weekday $weekday,
        string $originTime,
        string $destinationTime,
        ?string $note = null,
        ?int $originDayDifference = null,
        ?int $destinationDayDifference = null,
    ): TimetableEntry {
        return new TimetableEntry(
            FlightNumber::fromString($flightNumber),
            AirportCode::fromString($origin),
            AirportCode::fromString($destination),
            $weekday,
            $originTime,
            $destinationTime,
            $originDayDifference,
            $destinationDayDifference,
            ServiceDate::fromString('2026-10-25'),
            ServiceDate::fromString('2027-01-04'),
            $note,
            'odpt.Operator:ZZ',
        );
    }

    /**
     * Comparable form of a result. Instants keep their offset, so a value
     * read back in another zone would not compare equal. The request start
     * is compared at whole seconds, as it is stored.
     *
     * @return array<string, mixed>
     */
    private static function described(TimetableFetchResult $result): array
    {
        return [
            'direction' => $result->direction,
            'requestStartedAt' => $result->requestStartedAt->format('Y-m-d\TH:i:sP'),
            'latestDataDate' => $result->latestDataDate->format('Y-m-d\TH:i:s.uP'),
            'recordCount' => $result->recordCount,
            'entries' => array_map(static fn (TimetableEntry $entry): array => [
                $entry->flightNumber->value,
                $entry->originAirport->value,
                $entry->destinationAirport->value,
                $entry->weekday,
                $entry->originTime,
                $entry->destinationTime,
                $entry->originDayDifference,
                $entry->destinationDayDifference,
                $entry->validFrom->toString(),
                $entry->validTo->toString(),
                $entry->note,
                $entry->airline,
            ], $result->entries),
        ];
    }
}
