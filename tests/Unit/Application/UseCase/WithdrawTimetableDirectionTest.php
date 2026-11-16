<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\UseCase;

use App\Application\Gate\ComparedPeriod;
use App\Application\Gate\TimetableGateSettings;
use App\Application\Gate\WithdrawalCause;
use App\Application\Port\TimetableLockUnavailable;
use App\Application\UseCase\WithdrawalOutcome;
use App\Application\UseCase\WithdrawTimetableDirection;
use App\Domain\Flight\Direction;
use App\Domain\Flight\ServiceDate;
use App\Tests\Support\InMemoryTimetableGateRepository;
use App\Tests\Support\StubTimetableLock;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * TEST ONLY data: the ZZ flight in the reason is a placeholder.
 */
final class WithdrawTimetableDirectionTest extends TestCase
{
    private MockClock $clock;
    private InMemoryTimetableGateRepository $gate;

    protected function setUp(): void
    {
        $this->clock = new MockClock('2026-11-16T03:00:00Z');
        $this->gate = new InMemoryTimetableGateRepository();
    }

    public function testWithdrawsTheDirectionFromTheVersionInForce(): void
    {
        $this->addVersion();
        $current = $this->addVersion();

        $outcome = $this->useCase()->execute(Direction::Departure, '  再照合で ZZ1001 の時刻が不一致  ');

        self::assertSame(WithdrawalOutcome::Withdrawn, $outcome);
        self::assertSame([Direction::Departure], $this->gate->findCurrentState()?->withdrawn);
        self::assertEquals([
            'version' => $current,
            'direction' => Direction::Departure,
            'cause' => WithdrawalCause::Manual,
            'reason' => '再照合で ZZ1001 の時刻が不一致',
            'at' => $this->clock->now(),
        ], $this->gate->withdrawals[0]);
    }

    public function testAlreadyWithdrawnKeepsTheFirstWithdrawal(): void
    {
        $version = $this->addVersion();
        $this->gate->withdraw($version, Direction::Arrival, WithdrawalCause::ComparedItemsChanged, null, $this->clock->now());

        $outcome = $this->useCase()->execute(Direction::Arrival, 'test');

        self::assertSame(WithdrawalOutcome::AlreadyWithdrawn, $outcome);
        self::assertCount(1, $this->gate->withdrawals);
        self::assertSame(WithdrawalCause::ComparedItemsChanged, $this->gate->withdrawals[0]['cause']);
    }

    public function testNothingHappensWithoutAVersion(): void
    {
        self::assertSame(WithdrawalOutcome::NoVersion, $this->useCase()->execute(Direction::Departure, 'test'));
        self::assertSame([], $this->gate->withdrawals);
    }

    public function testBlankReasonIsRefused(): void
    {
        $this->addVersion();

        try {
            $this->useCase()->execute(Direction::Departure, " \t\n");
            self::fail('A blank reason was accepted.');
        } catch (\InvalidArgumentException) {
        }

        self::assertSame([], $this->gate->withdrawals);
    }

    public function testHeldLockWithdrawsNothing(): void
    {
        $this->addVersion();

        $this->expectException(TimetableLockUnavailable::class);
        try {
            $this->useCase(new StubTimetableLock(unavailableAttempts: 1))->execute(Direction::Departure, 'test');
        } finally {
            self::assertSame([], $this->gate->withdrawals);
        }
    }

    private function useCase(?StubTimetableLock $lock = null): WithdrawTimetableDirection
    {
        return new WithdrawTimetableDirection($this->gate, $lock ?? new StubTimetableLock(), $this->clock);
    }

    private function addVersion(): int
    {
        $period = new ComparedPeriod(ServiceDate::fromString('2026-11-11'), ServiceDate::fromString('2027-01-04'));
        $dataDate = new \DateTimeImmutable('2026-08-21T13:00:00+09:00');

        return $this->gate->addVersion(new TimetableGateSettings($period, ServiceDate::fromString('2026-11-12'), str_repeat('a', 40), $dataDate, $dataDate, str_repeat('0', 64), str_repeat('1', 64)), $this->clock->now())->id;
    }
}
