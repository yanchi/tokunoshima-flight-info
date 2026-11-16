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
     * Reads inside it must see one snapshot (the board relies on this).
     * SQLite gives that; on a database whose default is READ COMMITTED
     * (e.g. PostgreSQL), the implementation must set REPEATABLE READ or
     * stricter.
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
     * withdrawn from it keeps its first withdrawal (cause and time).
     *
     * @return bool false when the direction was already withdrawn from it
     */
    public function withdraw(int $versionId, Direction $direction, WithdrawalCause $cause, \DateTimeImmutable $withdrawnAt): bool;

    /**
     * Records one manual withdrawal operation with the operator's reason.
     * Every operation is kept, also one on a direction already withdrawn
     * (2026-11-16 operator decision), apart from the withdrawn state.
     */
    public function recordManualWithdrawal(int $versionId, Direction $direction, string $reason, \DateTimeImmutable $executedAt): void;
}
