<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Application\Gate\TimetableGateSettings;
use App\Application\Gate\TimetableGateState;
use App\Application\Gate\TimetableGateVersion;
use App\Application\Gate\WithdrawalCause;
use App\Application\Port\TimetableGateRepositoryInterface;
use App\Domain\Flight\Direction;

/**
 * TEST ONLY. Transactions are not rolled back; tests that need a rollback
 * use the Doctrine repository.
 */
final class InMemoryTimetableGateRepository implements TimetableGateRepositoryInterface
{
    /** @var list<TimetableGateVersion> */
    public array $versions = [];

    /** @var list<array{version: int, direction: Direction, cause: WithdrawalCause, at: \DateTimeImmutable}> */
    public array $withdrawals = [];

    /** @var list<array{version: int, direction: Direction, reason: string, at: \DateTimeImmutable}> */
    public array $manualWithdrawals = [];

    public int $transactions = 0;

    public function transactional(callable $callback): mixed
    {
        ++$this->transactions;

        return $callback();
    }

    public function findCurrentState(): ?TimetableGateState
    {
        $version = $this->versions[\count($this->versions) - 1] ?? null;
        if (null === $version) {
            return null;
        }

        $withdrawn = [];
        foreach ($this->withdrawals as $withdrawal) {
            if ($withdrawal['version'] === $version->id) {
                $withdrawn[] = $withdrawal['direction'];
            }
        }

        return new TimetableGateState($version, $withdrawn);
    }

    public function addVersion(TimetableGateSettings $settings, \DateTimeImmutable $setAt): TimetableGateVersion
    {
        $version = new TimetableGateVersion(\count($this->versions) + 1, $settings, $setAt);
        $this->versions[] = $version;

        return $version;
    }

    public function withdraw(int $versionId, Direction $direction, WithdrawalCause $cause, \DateTimeImmutable $withdrawnAt): bool
    {
        foreach ($this->withdrawals as $withdrawal) {
            if ($withdrawal['version'] === $versionId && $withdrawal['direction'] === $direction) {
                return false;
            }
        }
        $this->withdrawals[] = ['version' => $versionId, 'direction' => $direction, 'cause' => $cause, 'at' => $withdrawnAt];

        return true;
    }

    public function recordManualWithdrawal(int $versionId, Direction $direction, string $reason, \DateTimeImmutable $executedAt): void
    {
        $this->manualWithdrawals[] = ['version' => $versionId, 'direction' => $direction, 'reason' => $reason, 'at' => $executedAt];
    }
}
