<?php

declare(strict_types=1);

namespace App\Application\Port;

use App\Application\Gate\TimetableGateSettings;
use App\Application\Gate\TimetableGateState;
use App\Application\Gate\TimetableGateVersion;
use App\Application\Gate\WithdrawalCause;
use App\Domain\Flight\Direction;

/**
 * Gate settings versions and the directions withdrawn from them
 * (docs/data-source-verification.md §7 step 7 「設定値と外した状態の保存・解除」).
 * Kept in the same database as the timetable, so the sync can record a
 * withdrawal in the transaction that replaces the timetable.
 *
 * Writers are expected to hold the application timetable lock.
 */
interface TimetableGateRepositoryInterface
{
    /**
     * Runs the callback in one transaction, also covering what the
     * timetable repository reads and writes inside it. Whatever the callback
     * throws rolls everything back and is thrown again.
     *
     * @template T
     *
     * @param callable(): T $callback
     *
     * @return T
     */
    public function transactional(callable $callback): mixed;

    /**
     * The latest version with its withdrawn directions, or null before the
     * first version is set.
     */
    public function findCurrentState(): ?TimetableGateState;

    public function addVersion(TimetableGateSettings $settings, \DateTimeImmutable $setAt): TimetableGateVersion;

    /**
     * Withdraws the direction from the given version. A direction already
     * withdrawn from it keeps its first withdrawal.
     *
     * @param ?string $reason the operator's reason for a manual withdrawal
     *
     * @return bool false when the direction was already withdrawn from it
     */
    public function withdraw(int $versionId, Direction $direction, WithdrawalCause $cause, ?string $reason, \DateTimeImmutable $withdrawnAt): bool;
}
