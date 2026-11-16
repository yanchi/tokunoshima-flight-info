<?php

declare(strict_types=1);

namespace App\Application\UseCase;

use App\Application\Gate\TimetableGateInputInvalid;
use App\Application\Gate\WithdrawalCause;
use App\Application\Port\TimetableGateRepositoryInterface;
use App\Application\Port\TimetableLockInterface;
use App\Domain\Flight\Direction;
use Psr\Clock\ClockInterface;

/**
 * Withdraws one direction from the version in force by hand, e.g. when a
 * mismatch is found while comparing again (docs/data-source-verification.md
 * §7 step 7 「手動で外す操作」). It stays withdrawn until a new version is set.
 * Not a sync, so nothing is written to the sync log; every operation is
 * stored with its reason.
 */
final readonly class WithdrawTimetableDirection
{
    public function __construct(
        private TimetableGateRepositoryInterface $gate,
        private TimetableLockInterface $lock,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param string $reason stored with its surrounding white space trimmed
     *
     * @throws TimetableGateInputInvalid                      when the reason is blank
     * @throws \App\Application\Port\TimetableLockUnavailable when a sync or another command holds the lock
     */
    public function execute(Direction $direction, string $reason): WithdrawalOutcome
    {
        $reason = trim($reason);
        if ('' === $reason) {
            throw new TimetableGateInputInvalid('A reason is required.');
        }

        return $this->lock->runExclusively(fn (): WithdrawalOutcome => $this->gate->transactional(function () use ($direction, $reason): WithdrawalOutcome {
            $state = $this->gate->findCurrentState();
            if (null === $state) {
                return WithdrawalOutcome::NoVersion;
            }

            $now = $this->clock->now();
            $withdrawn = $this->gate->withdraw($state->version->id, $direction, WithdrawalCause::Manual, $now);
            // Recorded either way: the operator's finding is kept even when the
            // direction was already out (2026-11-16 operator decision).
            $this->gate->recordManualWithdrawal($state->version->id, $direction, $reason, $now);

            return $withdrawn ? WithdrawalOutcome::Withdrawn : WithdrawalOutcome::AlreadyWithdrawn;
        }));
    }
}
