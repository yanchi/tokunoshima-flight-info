<?php

declare(strict_types=1);

namespace App\Application\UseCase;

use App\Application\Port\TimetableDataPurgerInterface;
use App\Application\Port\TimetableLockInterface;
use App\Application\Purge\TimetablePurgeResult;

/**
 * Deletes every stored timetable and gate row when the licence ends
 * (docs/data-source-verification.md §2 「許諾終了時の削除」). Takes the
 * timetable lock, so it never runs while a sync or a gate command writes.
 * Stopping the sync first (cron, ODPT_CONSUMER_KEY) is the operator's
 * procedure; this does not store any state that would stop it.
 */
final readonly class PurgeTimetableData
{
    public function __construct(
        private TimetableDataPurgerInterface $purger,
        private TimetableLockInterface $lock,
    ) {
    }

    /**
     * @throws \App\Application\Port\TimetableLockUnavailable    when a sync or another command holds the lock
     * @throws \App\Application\Purge\TimetableSpaceNotReclaimed when the rows are deleted but the file still holds them
     */
    public function execute(): TimetablePurgeResult
    {
        return $this->lock->runExclusively(fn (): TimetablePurgeResult => $this->purger->purgeAll());
    }
}
