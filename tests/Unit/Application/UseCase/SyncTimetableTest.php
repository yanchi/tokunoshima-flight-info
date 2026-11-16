<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\UseCase;

use App\Application\DTO\TimetableEntry;
use App\Application\DTO\TimetableFetchResult;
use App\Application\Gate\ComparedItemsHasher;
use App\Application\Gate\ComparedPeriod;
use App\Application\Gate\TimetableGateSettings;
use App\Application\Gate\WithdrawalCause;
use App\Application\Port\TimetableFetchFailed;
use App\Application\Port\TimetableProviderInterface;
use App\Application\UseCase\SyncTimetable;
use App\Domain\Flight\AirportCode;
use App\Domain\Flight\Direction;
use App\Domain\Flight\FlightNumber;
use App\Domain\Flight\ServiceDate;
use App\Domain\Flight\TimetableFreshnessPolicy;
use App\Domain\Flight\Weekday;
use App\Tests\Support\InMemoryTimetableGateRepository;
use App\Tests\Support\InMemoryTimetableRepository;
use App\Tests\Support\RecordingLogger;
use App\Tests\Support\StubTimetableLock;
use App\Tests\Support\StubTimetableProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * TEST ONLY data: the ZZ designator and ZZZ airport are placeholders, so
 * these entries never pass for a real timetable.
 */
final class SyncTimetableTest extends TestCase
{
    private const NOW = '2026-11-12T03:00:00Z';

    private MockClock $clock;
    private InMemoryTimetableRepository $repository;
    private StubTimetableProvider $provider;
    private RecordingLogger $logger;
    private InMemoryTimetableGateRepository $gate;

    protected function setUp(): void
    {
        $this->clock = new MockClock(self::NOW);
        $this->repository = new InMemoryTimetableRepository($this->clock);
        $this->provider = new StubTimetableProvider();
        $this->logger = new RecordingLogger();
        $this->gate = new InMemoryTimetableGateRepository();
    }

    public function testFirstRunSavesBothDirectionsAndSucceeds(): void
    {
        $this->respondBoth('2026-11-12T02:59:58Z');

        $report = $this->sync()->execute();

        self::assertTrue($report->isSuccessful());
        self::assertNotNull($this->repository->findLatest(Direction::Departure));
        self::assertNotNull($this->repository->findLatest(Direction::Arrival));
        self::assertTrue($this->logger->has('info', 'Timetable saved', 'departure'));
        self::assertTrue($this->logger->has('info', 'Timetable saved', 'arrival'));
        // Nothing to compare with on the first fetch.
        self::assertFalse($this->logger->has('notice', 'changed'));
    }

    public function testFetchFailureFailsTheRunButTheOtherDirectionIsSaved(): void
    {
        $this->provider->respond(Direction::Departure, self::fetched(Direction::Departure, '2026-11-12T02:59:58Z'));
        $this->provider->respond(Direction::Arrival, new TimetableFetchFailed('ODPT returned HTTP 503.'));

        $report = $this->sync()->execute();

        self::assertFalse($report->isSuccessful());
        self::assertSame([Direction::Arrival], $report->notSaved);
        self::assertNotNull($this->repository->findLatest(Direction::Departure));
        self::assertTrue($this->logger->has('error', 'Timetable fetch failed', 'arrival'));
        self::assertSame('ODPT returned HTTP 503.', $this->recordFor('Timetable fetch failed')['context']['reason']);
    }

    public function testHeldLockSkipsTheDirectionAndFailsTheRun(): void
    {
        $this->respondBoth('2026-11-12T02:59:58Z');

        $report = $this->sync(new StubTimetableLock(unavailableAttempts: 1))->execute();

        self::assertSame([Direction::Departure], $report->notSaved);
        self::assertNull($this->repository->findLatest(Direction::Departure));
        self::assertNotNull($this->repository->findLatest(Direction::Arrival));
        self::assertTrue($this->logger->has('error', 'skipped', 'departure'));
    }

    public function testSaveFailureLogsOnlyTheExceptionClassAndGoesOn(): void
    {
        $this->respondBoth('2026-11-12T02:59:58Z');
        $this->repository->failNextSave(Direction::Departure, new \RuntimeException("SQLSTATE: value 'ZZ1001' rejected"));

        $report = $this->sync()->execute();

        self::assertSame([Direction::Departure], $report->notSaved);
        self::assertNotNull($this->repository->findLatest(Direction::Arrival));
        $record = $this->recordFor('Timetable save failed');
        self::assertSame(\RuntimeException::class, $record['context']['exception']);
        self::assertStringNotContainsString('ZZ1001', implode("\n", $this->logger->lines()));
    }

    public function testFetchNotNewerThanStoredIsNotReplacedAndDoesNotFailTheRun(): void
    {
        $this->repository->store(self::fetched(Direction::Departure, '2026-11-12T02:59:58Z'));
        $this->repository->store(self::fetched(Direction::Arrival, '2026-11-12T02:59:58Z'));
        $this->provider->respond(Direction::Departure, self::fetched(Direction::Departure, '2026-11-12T02:59:57Z', flightNumber: 'ZZ1005'));
        $this->provider->respond(Direction::Arrival, self::fetched(Direction::Arrival, '2026-11-12T02:59:58.5Z', flightNumber: 'ZZ1006'));

        $report = $this->sync()->execute();

        self::assertTrue($report->isSuccessful());
        self::assertTrue($this->logger->has('info', 'the stored fetch started later', 'departure'));
        self::assertTrue($this->logger->has('info', 'in the same second', 'arrival'));
        self::assertSame('ZZ1001', $this->repository->findLatest(Direction::Departure)?->entries[0]->flightNumber->value);
        // Both times are logged.
        $record = $this->recordFor('the stored fetch started later');
        self::assertSame('2026-11-12T02:59:57+00:00', $record['context']['fetch_started_at']);
        self::assertSame('2026-11-12T02:59:58+00:00', $record['context']['stored_started_at']);
        // Not-replaced directions are not compared.
        self::assertFalse($this->logger->has('notice', 'changed'));
    }

    public function testFetchDatedAfterTheJudgmentIsNotSavedAndFailsTheRun(): void
    {
        $this->repository->store(self::fetched(Direction::Departure, '2026-11-12T02:00:00Z'));
        $this->respondBoth('2026-11-12T02:59:58Z');
        $this->provider->respond(Direction::Departure, self::fetched(Direction::Departure, '2026-11-12T03:00:01Z', flightNumber: 'ZZ1005'));

        $report = $this->sync()->execute();

        self::assertSame([Direction::Departure], $report->notSaved);
        self::assertTrue($report->futureRecordDetected);
        self::assertSame('ZZ1001', $this->repository->findLatest(Direction::Departure)?->entries[0]->flightNumber->value);
        self::assertTrue($this->logger->has('error', 'this fetch is dated after', 'departure'));
        self::assertSame('2026-11-12T03:00:00+00:00', $this->recordFor('this fetch is dated after')['context']['judged_at']);
    }

    public function testStoredRecordInTheFutureIsDetectedAtTheStartAndReplaced(): void
    {
        $this->repository->store(self::fetched(Direction::Departure, '2026-11-20T00:00:00Z'));
        $this->respondBoth('2026-11-12T02:59:58Z');

        $report = $this->sync()->execute();

        self::assertFalse($report->isSuccessful());
        self::assertSame([], $report->notSaved);
        self::assertTrue($report->futureRecordDetected);
        self::assertTrue($this->logger->has('error', 'Stored timetable fetch record is dated in the future (at the start).', 'departure'));
        self::assertTrue($this->logger->has('error', 'saved over a stored fetch record dated in the future', 'departure'));
        self::assertSame('2026-11-12T02:59:58+00:00', $this->repository->findLatest(Direction::Departure)?->requestStartedAt->format(\DATE_ATOM));
    }

    public function testRecordFutureAtTheStartButPastWhenJudgedIsReplacedByALaterFetch(): void
    {
        // Future at the start (03:00:00), past by the time of the save.
        $this->repository->store(self::fetched(Direction::Departure, '2026-11-12T03:00:30Z'));
        $this->repository->beforeJudgment(fn () => $this->clock->modify('2026-11-12T03:01:00Z'));
        $this->respondBoth('2026-11-12T03:00:40Z');

        $report = $this->sync()->execute();

        self::assertTrue($report->futureRecordDetected);
        self::assertFalse($report->isSuccessful());
        self::assertSame([], $report->notSaved);
        self::assertTrue($this->logger->has('error', 'dated in the future (at the start)', 'departure'));
        // Judged normally: a later fetch replaces it, not as a future record.
        self::assertTrue($this->logger->has('info', 'Timetable saved', 'departure'));
        self::assertFalse($this->logger->has('error', 'saved over a stored fetch record'));
        self::assertSame('2026-11-12T03:00:40+00:00', $this->repository->findLatest(Direction::Departure)?->requestStartedAt->format(\DATE_ATOM));
    }

    public function testRecordFutureAtTheStartButPastWhenJudgedIsKeptAgainstAnEarlierFetch(): void
    {
        $this->repository->store(self::fetched(Direction::Departure, '2026-11-12T03:00:30Z'));
        $this->repository->beforeJudgment(fn () => $this->clock->modify('2026-11-12T03:01:00Z'));
        $this->respondBoth('2026-11-12T03:00:20Z');

        $report = $this->sync()->execute();

        self::assertTrue($report->futureRecordDetected);
        self::assertFalse($report->isSuccessful());
        self::assertSame([], $report->notSaved);
        self::assertTrue($this->logger->has('info', 'the stored fetch started later', 'departure'));
        self::assertSame('2026-11-12T03:00:30+00:00', $this->repository->findLatest(Direction::Departure)?->requestStartedAt->format(\DATE_ATOM));
    }

    public function testRecordFutureOnlyAfterTheRunIsLoggedAndFailsTheRun(): void
    {
        $this->repository->store(self::fetched(Direction::Departure, '2026-11-12T02:59:00Z'));
        // The clock is set back during the run; the fetch fails, so the
        // stored record is kept and is now ahead of the clock.
        $this->provider->respond(Direction::Departure, new TimetableFetchFailed('ODPT returned HTTP 503.'));
        $this->provider->respond(Direction::Arrival, self::fetched(Direction::Arrival, '2026-11-12T02:00:00Z'));
        $this->repository->beforeJudgment(fn () => $this->clock->modify('2026-11-12T02:30:00Z'));

        $report = $this->sync()->execute();

        self::assertTrue($report->futureRecordDetected);
        self::assertTrue($this->logger->has('error', 'dated in the future (after the run)', 'departure'));
        self::assertFalse($this->logger->has('error', 'dated in the future (at the start)'));
    }

    public function testRecordFutureAtTheStartIsNotLoggedAgainAfterTheRun(): void
    {
        $this->repository->store(self::fetched(Direction::Departure, '2026-11-20T00:00:00Z'));
        $this->provider->respond(Direction::Departure, new TimetableFetchFailed('ODPT returned HTTP 503.'));

        $report = $this->sync()->execute();

        self::assertTrue($report->futureRecordDetected);
        self::assertTrue($this->logger->has('error', 'dated in the future (at the start)', 'departure'));
        self::assertFalse($this->logger->has('error', 'dated in the future (after the run)'));
    }

    public function testFailedComparisonRollsTheSaveBackAndCountsAsNotSaved(): void
    {
        // TEST ONLY: invalid UTF-8 can only get in by bypassing the mapper,
        // which rejects it; the stored note makes the hash fail.
        $stored = self::fetched(Direction::Departure, '2026-11-12T02:00:00Z', entries: [self::entry('ZZ1001', '09:00', note: "\xff")]);
        $this->repository->store($stored);
        $this->respondBoth('2026-11-12T02:59:58Z');
        $this->provider->respond(Direction::Departure, self::fetched(Direction::Departure, '2026-11-12T02:59:58Z', flightNumber: 'ZZ1005'));

        $report = $this->sync()->execute();

        self::assertSame([Direction::Departure], $report->notSaved);
        self::assertSame(\JsonException::class, $this->recordFor('Timetable save failed')['context']['exception']);
        self::assertSame('ZZ1001', $this->repository->findLatest(Direction::Departure)?->entries[0]->flightNumber->value);
        self::assertFalse($this->logger->has('notice', 'changed'));
    }

    public function testUnexpectedProviderErrorIsLoggedByClassAndTheOtherDirectionGoesOn(): void
    {
        $provider = new class implements TimetableProviderInterface {
            public function fetch(Direction $direction): TimetableFetchResult
            {
                if (Direction::Departure === $direction) {
                    throw new \TypeError('value ZZ1001 broke');
                }

                return SyncTimetableTest::fetched($direction, '2026-11-12T02:59:58Z');
            }
        };

        $report = new SyncTimetable($provider, $this->repository, new StubTimetableLock(), $this->clock, self::policy(), new ComparedItemsHasher(), $this->gate, $this->logger)->execute();

        self::assertSame([Direction::Departure], $report->notSaved);
        self::assertSame(\TypeError::class, $this->recordFor('Timetable fetch failed')['context']['exception']);
        self::assertStringNotContainsString('ZZ1001', implode("\n", $this->logger->lines()));
        self::assertNotNull($this->repository->findLatest(Direction::Arrival));
    }

    public function testUnexpectedSaveErrorIsLoggedByClass(): void
    {
        $this->respondBoth('2026-11-12T02:59:58Z');
        $this->repository->failNextSave(Direction::Departure, new \Error('broken'));

        $report = $this->sync()->execute();

        self::assertSame([Direction::Departure], $report->notSaved);
        self::assertSame(\Error::class, $this->recordFor('Timetable save failed')['context']['exception']);
    }

    public function testInvalidThresholdsFailTheRunButDataIsStillSaved(): void
    {
        $this->respondBoth('2026-11-12T02:59:58Z');

        $report = $this->sync(policy: new TimetableFreshnessPolicy('', '604800'))->execute();

        self::assertFalse($report->isSuccessful());
        self::assertTrue($report->thresholdsInvalid);
        self::assertSame([], $report->notSaved);
        self::assertNotNull($this->repository->findLatest(Direction::Departure));
        self::assertCount(1, array_filter($this->logger->lines(), static fn (string $l): bool => str_contains($l, 'thresholds are invalid')));
    }

    public function testChangedDataDateIsLoggedWithoutAContentChange(): void
    {
        $this->repository->store(self::fetched(Direction::Departure, '2026-11-12T02:00:00Z'));
        $this->respondBoth('2026-11-12T02:59:58Z');
        $this->provider->respond(Direction::Departure, self::fetched(Direction::Departure, '2026-11-12T02:59:58Z', dataDate: '2026-11-11T09:00:00+09:00'));

        $report = $this->sync()->execute();

        self::assertTrue($report->isSuccessful());
        self::assertTrue($this->logger->has('notice', 'dc:date changed', 'departure'));
        self::assertFalse($this->logger->has('notice', 'content changed'));
    }

    public function testSameDataDateInAnotherOffsetIsNotAChange(): void
    {
        $this->repository->store(self::fetched(Direction::Departure, '2026-11-12T02:00:00Z', dataDate: '2026-08-21T04:00:00+00:00'));
        $this->provider->respond(Direction::Departure, self::fetched(Direction::Departure, '2026-11-12T02:59:58Z'));

        $this->sync()->execute();

        self::assertFalse($this->logger->has('notice', 'dc:date changed'));
    }

    public function testChangedContentIsLogged(): void
    {
        $this->repository->store(self::fetched(Direction::Departure, '2026-11-12T02:00:00Z'));
        $this->provider->respond(Direction::Departure, self::fetched(Direction::Departure, '2026-11-12T02:59:58Z', flightNumber: 'ZZ1005'));

        $this->sync()->execute();

        self::assertTrue($this->logger->has('notice', 'content changed', 'departure'));
        self::assertFalse($this->logger->has('notice', 'dc:date changed'));
    }

    public function testExtendedValidityIsAContentChange(): void
    {
        $this->repository->store(self::fetched(Direction::Departure, '2026-11-12T02:00:00Z'));
        $this->provider->respond(Direction::Departure, self::fetched(Direction::Departure, '2026-11-12T02:59:58Z', validTo: '2027-03-31'));

        $this->sync()->execute();

        // Nothing is cut away: the span covers both fetches.
        self::assertTrue($this->logger->has('notice', 'content changed', 'departure'));
    }

    public function testReorderedEntriesAreNotAContentChange(): void
    {
        $first = self::entry('ZZ1001', '09:00');
        $second = self::entry('ZZ1003', '15:00');
        $this->repository->store(self::fetched(Direction::Departure, '2026-11-12T02:00:00Z', entries: [$first, $second]));
        $this->provider->respond(Direction::Departure, self::fetched(Direction::Departure, '2026-11-12T02:59:58Z', entries: [$second, $first]));

        $this->sync()->execute();

        self::assertFalse($this->logger->has('notice', 'content changed'));
    }

    public function testStaleTimetableIsLoggedOnEveryRunWhileTheFetchFails(): void
    {
        // 3 days and 1 second before now.
        $this->repository->store(self::fetched(Direction::Departure, '2026-11-09T02:59:59Z'));
        // 7 days and 1 second before now.
        $this->repository->store(self::fetched(Direction::Arrival, '2026-11-05T02:59:59Z'));

        $this->sync()->execute();
        $this->sync()->execute();

        $warnings = array_filter($this->logger->records, static fn (array $r): bool => str_contains($r['message'], 'past the warning threshold'));
        $suspensions = array_filter($this->logger->records, static fn (array $r): bool => str_contains($r['message'], 'publication is suspended'));
        self::assertCount(2, $warnings);
        self::assertCount(2, $suspensions);
        // Both are errors, told apart by the message (§7 step 7 「運営者が気づく方法」).
        self::assertSame(['error'], array_values(array_unique(array_map(static fn (array $r): string => $r['level'], [...$warnings, ...$suspensions]))));
        self::assertSame(['departure'], array_values(array_unique(array_map(static fn (array $r): string => self::directionOf($r), $warnings))));
        self::assertSame(['arrival'], array_values(array_unique(array_map(static fn (array $r): string => self::directionOf($r), $suspensions))));
    }

    public function testSuccessfulSaveClearsTheWarning(): void
    {
        $this->repository->store(self::fetched(Direction::Departure, '2026-11-01T00:00:00Z'));
        $this->respondBoth('2026-11-12T02:59:58Z');

        $report = $this->sync()->execute();

        self::assertTrue($report->isSuccessful());
        self::assertFalse($this->logger->has('error', 'threshold'));
        self::assertFalse($this->logger->has('error', 'suspended'));
    }

    public function testSaveRunsInsideTheLockAndTheFetchOutsideIt(): void
    {
        $lock = new StubTimetableLock();
        $provider = new class($lock) implements TimetableProviderInterface {
            /** @var list<bool> */
            public array $lockedDuringFetch = [];

            public function __construct(private readonly StubTimetableLock $lock)
            {
            }

            public function fetch(Direction $direction): TimetableFetchResult
            {
                $this->lockedDuringFetch[] = $this->lock->held;

                return SyncTimetableTest::fetched($direction, '2026-11-12T02:59:58Z');
            }
        };
        $this->repository->watchLock($lock);

        new SyncTimetable($provider, $this->repository, $lock, $this->clock, self::policy(), new ComparedItemsHasher(), $this->gate, $this->logger)->execute();

        self::assertSame([false, false], $provider->lockedDuringFetch);
        self::assertSame([true, true], $this->repository->lockedDuringSave);
    }

    public function testChangeInsideTheComparedPeriodWithdrawsOnlyThatDirection(): void
    {
        $this->storeBoth('2026-11-12T02:00:00Z');
        $version = $this->setGate();
        $this->respondBoth('2026-11-12T02:59:58Z');
        $this->provider->respond(Direction::Departure, self::fetched(Direction::Departure, '2026-11-12T02:59:58Z', entries: [self::entry('ZZ1001', '09:05')]));

        $report = $this->sync()->execute();

        // (a) is not a failure of the run (§7 step 7 「同期の終了コード」).
        self::assertTrue($report->isSuccessful());
        $state = $this->gate->findCurrentState();
        self::assertNotNull($state);
        self::assertNull($state->publishedPeriod(Direction::Departure));
        self::assertNotNull($state->publishedPeriod(Direction::Arrival));
        self::assertSame($version, $this->gate->withdrawals[0]['version']);
        self::assertSame(WithdrawalCause::ComparedItemsChanged, $this->gate->withdrawals[0]['cause']);
        self::assertTrue($this->logger->has('warning', 'withdrawn from publication', 'departure'));
        self::assertSame($version, $this->recordFor('withdrawn from publication')['context']['gate_version']);
        // (a) takes precedence over (b).
        self::assertFalse($this->logger->has('notice', 'outside the compared period'));
        self::assertFalse($this->logger->has('warning', 'withdrawn', 'arrival'));
    }

    public function testChangedNoteInsideTheComparedPeriodWithdraws(): void
    {
        $this->storeBoth('2026-11-12T02:00:00Z');
        $this->setGate();
        $this->respondBoth('2026-11-12T02:59:58Z');
        $this->provider->respond(Direction::Arrival, self::fetched(Direction::Arrival, '2026-11-12T02:59:58Z', entries: [self::entry('ZZ1001', '09:00', Direction::Arrival, note: 'テスト用の備考')]));

        $this->sync()->execute();

        self::assertSame([Direction::Arrival], $this->gate->findCurrentState()?->withdrawn);
    }

    public function testExtendedValidityIsLoggedAsAChangeOutsideAndStaysPublished(): void
    {
        $this->storeBoth('2026-11-12T02:00:00Z');
        $version = $this->setGate();
        $this->respondBoth('2026-11-12T02:59:58Z');
        $this->provider->respond(Direction::Departure, self::fetched(Direction::Departure, '2026-11-12T02:59:58Z', validTo: '2027-03-31'));

        $this->sync()->execute();

        self::assertSame([], $this->gate->findCurrentState()?->withdrawn);
        self::assertTrue($this->logger->has('notice', 'outside the compared period (b)', 'departure'));
        self::assertSame($version, $this->recordFor('outside the compared period')['context']['gate_version']);
        self::assertFalse($this->logger->has('notice', 'outside the compared period', 'arrival'));
    }

    public function testValidityShortenedToTheLastComparedDayIsAChangeOutside(): void
    {
        $this->storeBoth('2026-11-12T02:00:00Z');
        $this->setGate(to: '2026-12-31');
        $this->respondBoth('2026-11-12T02:59:58Z');
        $this->provider->respond(Direction::Departure, self::fetched(Direction::Departure, '2026-11-12T02:59:58Z', validTo: '2026-12-31'));

        $this->sync()->execute();

        self::assertSame([], $this->gate->findCurrentState()?->withdrawn);
        self::assertTrue($this->logger->has('notice', 'outside the compared period (b)', 'departure'));
    }

    public function testValidityShortenedBeforeTheLastComparedDayWithdraws(): void
    {
        $this->storeBoth('2026-11-12T02:00:00Z');
        $this->setGate(to: '2026-12-31');
        $this->respondBoth('2026-11-12T02:59:58Z');
        $this->provider->respond(Direction::Departure, self::fetched(Direction::Departure, '2026-11-12T02:59:58Z', validTo: '2026-12-30'));

        $this->sync()->execute();

        self::assertSame([Direction::Departure], $this->gate->findCurrentState()?->withdrawn);
    }

    public function testWithdrawnDirectionStaysWithdrawnWhenTheDataComesBack(): void
    {
        $this->storeBoth('2026-11-12T02:00:00Z');
        $this->setGate();
        $this->provider->respond(Direction::Departure, self::fetched(Direction::Departure, '2026-11-12T02:30:00Z', entries: [self::entry('ZZ1001', '09:05')]));
        $this->provider->respond(Direction::Arrival, self::fetched(Direction::Arrival, '2026-11-12T02:30:00Z'));
        $this->sync()->execute();

        $this->logger = new RecordingLogger();
        $this->respondBoth('2026-11-12T02:59:58Z');
        $this->sync()->execute();

        self::assertSame([Direction::Departure], $this->gate->findCurrentState()?->withdrawn);
        self::assertCount(1, $this->gate->withdrawals);
        // Logged once, when it was withdrawn; the change back is not (b).
        self::assertFalse($this->logger->has('warning', 'withdrawn from publication'));
        self::assertFalse($this->logger->has('notice', 'outside the compared period'));
        self::assertTrue($this->logger->has('notice', 'changed since the previous fetch (withdrawn from publication)', 'departure'));
    }

    public function testNewVersionClearsTheWithdrawal(): void
    {
        $this->storeBoth('2026-11-12T02:00:00Z');
        $this->setGate();
        $this->provider->respond(Direction::Departure, self::fetched(Direction::Departure, '2026-11-12T02:30:00Z', entries: [self::entry('ZZ1001', '09:05')]));
        $this->provider->respond(Direction::Arrival, self::fetched(Direction::Arrival, '2026-11-12T02:30:00Z'));
        $this->sync()->execute();

        $this->setGate();
        $this->provider->respond(Direction::Departure, self::fetched(Direction::Departure, '2026-11-12T02:59:58Z', entries: [self::entry('ZZ1001', '09:05')]));
        $this->provider->respond(Direction::Arrival, self::fetched(Direction::Arrival, '2026-11-12T02:59:58Z'));
        $this->sync()->execute();

        self::assertSame([], $this->gate->findCurrentState()?->withdrawn);
    }

    public function testNotReplacedDirectionIsNotCompared(): void
    {
        $this->storeBoth('2026-11-12T02:59:58Z');
        $this->setGate();
        $this->respondBoth('2026-11-12T02:59:58Z');
        $this->provider->respond(Direction::Departure, self::fetched(Direction::Departure, '2026-11-12T02:00:00Z', entries: [self::entry('ZZ1001', '09:05')]));

        $this->sync()->execute();

        self::assertSame([], $this->gate->withdrawals);
    }

    public function testFailedComparisonWithTheGateKeepsTheStoredTimetable(): void
    {
        $this->storeBoth('2026-11-12T02:00:00Z');
        $this->setGate();
        $this->respondBoth('2026-11-12T02:59:58Z');
        // TEST ONLY: invalid UTF-8 bypassing the mapper, so comparison (a) fails.
        $this->provider->respond(Direction::Departure, self::fetched(Direction::Departure, '2026-11-12T02:59:58Z', entries: [self::entry('ZZ1001', '09:00', note: "\xff")]));

        $report = $this->sync()->execute();

        self::assertSame([Direction::Departure], $report->notSaved);
        self::assertSame('2026-11-12T02:00:00+00:00', $this->repository->findLatest(Direction::Departure)?->requestStartedAt->format(\DATE_ATOM));
        self::assertSame([], $this->gate->withdrawals);
        self::assertTrue($this->logger->has('error', 'Timetable save failed', 'departure'));
    }

    public function testManuallyWithdrawnDirectionStaysWithdrawnAndOnlyLogsTheChange(): void
    {
        $this->storeBoth('2026-11-12T02:00:00Z');
        $version = $this->setGate();
        $this->gate->withdraw($version, Direction::Departure, WithdrawalCause::Manual, $this->clock->now());
        $this->respondBoth('2026-11-12T02:59:58Z');
        $this->provider->respond(Direction::Departure, self::fetched(Direction::Departure, '2026-11-12T02:59:58Z', validTo: '2027-03-31'));

        $this->sync()->execute();

        self::assertSame([Direction::Departure], $this->gate->findCurrentState()?->withdrawn);
        self::assertCount(1, $this->gate->withdrawals);
        self::assertSame(WithdrawalCause::Manual, $this->gate->withdrawals[0]['cause']);
        self::assertFalse($this->logger->has('warning', 'withdrawn from publication'));
        self::assertFalse($this->logger->has('notice', 'outside the compared period'));
        self::assertTrue($this->logger->has('notice', 'changed since the previous fetch (withdrawn from publication)', 'departure'));
    }

    public function testManuallyWithdrawnDirectionStaysWithdrawnWithUnchangedData(): void
    {
        $this->storeBoth('2026-11-12T02:00:00Z');
        $version = $this->setGate();
        $this->gate->withdraw($version, Direction::Arrival, WithdrawalCause::Manual, $this->clock->now());
        $this->respondBoth('2026-11-12T02:59:58Z');

        $this->sync()->execute();

        self::assertSame([Direction::Arrival], $this->gate->findCurrentState()?->withdrawn);
        self::assertFalse($this->logger->has('notice', 'changed'));
    }

    public function testChangedDataDateAloneKeepsTheComparedPeriodPublished(): void
    {
        $this->storeBoth('2026-11-12T02:00:00Z');
        $this->setGate();
        $this->respondBoth('2026-11-12T02:59:58Z');
        $this->provider->respond(Direction::Departure, self::fetched(Direction::Departure, '2026-11-12T02:59:58Z', dataDate: '2026-11-10T13:00:00+09:00'));

        $this->sync()->execute();

        self::assertSame([], $this->gate->withdrawals);
        self::assertTrue($this->logger->has('notice', 'dc:date changed', 'departure'));
        self::assertFalse($this->logger->has('notice', 'outside the compared period'));
        self::assertFalse($this->logger->has('warning', 'withdrawn'));
    }

    public function testAddedPeriodAfterTheComparedOneIsAChangeOutside(): void
    {
        $this->storeBoth('2026-11-12T02:00:00Z');
        $this->setGate();
        $this->respondBoth('2026-11-12T02:59:58Z');
        $this->provider->respond(Direction::Departure, self::fetched(Direction::Departure, '2026-11-12T02:59:58Z', entries: [
            self::entry('ZZ1001', '09:00'),
            self::entry('ZZ1001', '09:10', validTo: '2027-03-31', validFrom: '2027-01-05'),
        ]));

        $this->sync()->execute();

        self::assertSame([], $this->gate->withdrawals);
        self::assertTrue($this->logger->has('notice', 'outside the compared period (b)', 'departure'));
    }

    public function testVersionSetBetweenTheTwoDirectionsIsTheOneTheSecondIsComparedWith(): void
    {
        $this->storeBoth('2026-11-12T02:00:00Z');
        $first = $this->setGate();
        $saves = 0;
        // E.g. the gate command ran while the lock was free between the directions.
        $this->repository->beforeJudgment(function () use (&$saves, $first): void {
            if (2 === ++$saves) {
                $settings = $this->gate->versions[$first - 1]->settings;
                $this->gate->addVersion(new TimetableGateSettings($settings->period, $settings->comparedOn, $settings->recordCommit, $settings->departureDataDate, $settings->arrivalDataDate, $settings->departureHash, str_repeat('0', 64)), $this->clock->now());
            }
        });
        $this->respondBoth('2026-11-12T02:59:58Z');

        $this->sync()->execute();

        self::assertCount(1, $this->gate->withdrawals);
        self::assertSame(Direction::Arrival, $this->gate->withdrawals[0]['direction']);
        self::assertSame($first + 1, $this->gate->withdrawals[0]['version']);
    }

    private function storeBoth(string $requestStartedAt): void
    {
        foreach (Direction::cases() as $direction) {
            $this->repository->store(self::fetched($direction, $requestStartedAt));
        }
    }

    /**
     * A version made from the stored timetable, as the gate command makes it.
     *
     * @return int the version id
     */
    private function setGate(string $from = '2026-11-11', string $to = '2027-01-04'): int
    {
        $period = new ComparedPeriod(ServiceDate::fromString($from), ServiceDate::fromString($to));
        $hasher = new ComparedItemsHasher();
        $departure = $this->repository->findLatest(Direction::Departure);
        $arrival = $this->repository->findLatest(Direction::Arrival);
        self::assertNotNull($departure);
        self::assertNotNull($arrival);

        return $this->gate->addVersion(new TimetableGateSettings(
            $period,
            ServiceDate::fromString('2026-11-12'),
            str_repeat('a', 40),
            $departure->latestDataDate,
            $arrival->latestDataDate,
            $hasher->hash($period, $departure->entries),
            $hasher->hash($period, $arrival->entries),
        ), $this->clock->now())->id;
    }

    /**
     * @param list<TimetableEntry>|null $entries
     */
    public static function fetched(
        Direction $direction,
        string $requestStartedAt,
        string $flightNumber = 'ZZ1001',
        string $dataDate = '2026-08-21T13:00:00+09:00',
        string $validTo = '2027-01-04',
        ?array $entries = null,
    ): TimetableFetchResult {
        return new TimetableFetchResult(
            $direction,
            new \DateTimeImmutable($requestStartedAt),
            new \DateTimeImmutable($dataDate),
            1,
            $entries ?? [self::entry($flightNumber, '09:00', $direction, $validTo)],
        );
    }

    /**
     * An entry valid from one day to another, for tests of other classes.
     */
    public static function entryOf(string $flightNumber, string $validFrom, string $validTo, Direction $direction = Direction::Departure): TimetableEntry
    {
        return self::entry($flightNumber, '09:00', $direction, $validTo, validFrom: $validFrom);
    }

    private static function entry(string $flightNumber, string $originTime, Direction $direction = Direction::Departure, string $validTo = '2027-01-04', ?string $note = null, string $validFrom = '2026-10-25'): TimetableEntry
    {
        [$origin, $destination] = Direction::Departure === $direction ? ['TKN', 'ZZZ'] : ['ZZZ', 'TKN'];

        return new TimetableEntry(
            FlightNumber::fromString($flightNumber),
            AirportCode::fromString($origin),
            AirportCode::fromString($destination),
            Weekday::Monday,
            $originTime,
            '23:00',
            null,
            null,
            ServiceDate::fromString($validFrom),
            ServiceDate::fromString($validTo),
            $note,
            'odpt.Operator:ZZ',
        );
    }

    private function respondBoth(string $requestStartedAt): void
    {
        foreach (Direction::cases() as $direction) {
            $this->provider->respond($direction, self::fetched($direction, $requestStartedAt));
        }
    }

    private function sync(?StubTimetableLock $lock = null, ?TimetableFreshnessPolicy $policy = null): SyncTimetable
    {
        return new SyncTimetable(
            $this->provider,
            $this->repository,
            $lock ?? new StubTimetableLock(),
            $this->clock,
            $policy ?? self::policy(),
            new ComparedItemsHasher(),
            $this->gate,
            $this->logger,
        );
    }

    private static function policy(): TimetableFreshnessPolicy
    {
        return new TimetableFreshnessPolicy('259200', '604800');
    }

    /**
     * @return array{level: string, message: string, context: array<mixed>}
     */
    private function recordFor(string $messagePart): array
    {
        foreach ($this->logger->records as $record) {
            if (str_contains($record['message'], $messagePart)) {
                return $record;
            }
        }
        self::fail(\sprintf('No log record contains "%s".', $messagePart));
    }

    /**
     * @param array{level: string, message: string, context: array<mixed>} $record
     */
    private static function directionOf(array $record): string
    {
        $direction = $record['context']['direction'] ?? null;
        self::assertIsString($direction);

        return $direction;
    }
}
