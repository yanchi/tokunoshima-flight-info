<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\UseCase;

use App\Application\Gate\ComparedItemsHasher;
use App\Application\Gate\TimetableGateRefused;
use App\Application\Gate\TimetableGateRequest;
use App\Application\Gate\WithdrawalCause;
use App\Application\Port\TimetableLockUnavailable;
use App\Application\UseCase\SetTimetableGate;
use App\Domain\Flight\Direction;
use App\Tests\Support\InMemoryTimetableGateRepository;
use App\Tests\Support\InMemoryTimetableRepository;
use App\Tests\Support\StubTimetableLock;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * TEST ONLY data (SyncTimetableTest::fetched): placeholder flights only.
 */
final class SetTimetableGateTest extends TestCase
{
    private const COMMIT = '0123456789abcdef0123456789abcdef01234567';

    private MockClock $clock;
    private InMemoryTimetableRepository $timetables;
    private InMemoryTimetableGateRepository $gate;

    protected function setUp(): void
    {
        $this->clock = new MockClock('2026-11-16T03:00:00Z');
        $this->timetables = new InMemoryTimetableRepository($this->clock);
        $this->gate = new InMemoryTimetableGateRepository();
        foreach (Direction::cases() as $direction) {
            $this->timetables->store(SyncTimetableTest::fetched($direction, '2026-11-16T02:00:00Z', dataDate: '2026-08-21T13:00:00+09:00'));
        }
    }

    public function testSetsAVersionWithTheHashesOfTheStoredTimetable(): void
    {
        $version = $this->useCase()->execute(self::request());

        $settings = $version->settings;
        $hasher = new ComparedItemsHasher();
        foreach (Direction::cases() as $direction) {
            $stored = $this->timetables->findLatest($direction);
            self::assertNotNull($stored);
            self::assertSame($hasher->hash($settings->period, $stored->entries), $settings->hashFor($direction));
        }
        self::assertSame(self::COMMIT, $settings->recordCommit);
        self::assertSame('2026-11-12', $settings->comparedOn->toString());
        self::assertEquals($this->clock->now(), $version->setAt);
        self::assertSame(1, $this->gate->transactions);
        self::assertSame([], $this->gate->findCurrentState()?->withdrawn);
    }

    public function testSameDataDateInAnotherOffsetMatches(): void
    {
        $version = $this->useCase()->execute(self::request(departure: '2026-08-21T04:00:00Z'));

        self::assertSame(1, $version->id);
    }

    public function testSettingTheSameValuesAgainClearsTheWithdrawal(): void
    {
        $first = $this->useCase()->execute(self::request());
        $this->gate->withdraw($first->id, Direction::Departure, WithdrawalCause::ComparedItemsChanged, $this->clock->now());

        $second = $this->useCase()->execute(self::request());

        self::assertSame($first->settings->departureHash, $second->settings->departureHash);
        self::assertSame([], $this->gate->findCurrentState()?->withdrawn);
    }

    public function testDifferentDataDateIsRefused(): void
    {
        $this->expectRefusal('arrival dc:date does not match');

        $this->useCase()->execute(self::request(arrival: '2026-08-21T13:00:01+09:00'));
    }

    public function testMissingFetchRecordIsRefused(): void
    {
        $this->timetables = new InMemoryTimetableRepository($this->clock);
        $this->timetables->store(SyncTimetableTest::fetched(Direction::Departure, '2026-11-16T02:00:00Z'));

        $this->expectRefusal('No arrival timetable');

        $this->useCase()->execute(self::request());
    }

    public function testPeriodBeyondTheStoredDataIsRefused(): void
    {
        $this->expectRefusal('outside the stored departure timetable (2026-10-25 to 2027-01-04)');

        $this->useCase()->execute(self::request(to: '2027-03-31'));
    }

    public function testPeriodBeforeTheStoredDataIsRefused(): void
    {
        $this->expectRefusal('outside the stored');

        $this->useCase()->execute(self::request(from: '2026-10-24'));
    }

    public function testPeriodBeyondOneDirectionOnlyIsRefused(): void
    {
        $this->timetables->store(SyncTimetableTest::fetched(Direction::Arrival, '2026-11-16T02:00:00Z', validTo: '2026-12-31'));

        $this->expectRefusal('outside the stored arrival timetable');

        $this->useCase()->execute(self::request());
    }

    public function testHeldLockSetsNothing(): void
    {
        try {
            $this->useCase(new StubTimetableLock(unavailableAttempts: 1))->execute(self::request());
            self::fail('The lock was not respected.');
        } catch (TimetableLockUnavailable) {
        }

        self::assertSame([], $this->gate->versions);
    }

    private function expectRefusal(string $messagePart): void
    {
        $this->expectException(TimetableGateRefused::class);
        $this->expectExceptionMessage($messagePart);
    }

    private function useCase(?StubTimetableLock $lock = null): SetTimetableGate
    {
        return new SetTimetableGate($this->timetables, $this->gate, $lock ?? new StubTimetableLock(), new ComparedItemsHasher(), $this->clock);
    }

    private static function request(
        string $from = '2026-11-11',
        string $to = '2027-01-04',
        string $departure = '2026-08-21T13:00:00+09:00',
        string $arrival = '2026-08-21T13:00:00+09:00',
    ): TimetableGateRequest {
        return TimetableGateRequest::fromStrings($from, $to, '2026-11-12', self::COMMIT, $departure, $arrival);
    }
}
