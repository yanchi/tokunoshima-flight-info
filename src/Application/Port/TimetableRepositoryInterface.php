<?php

declare(strict_types=1);

namespace App\Application\Port;

use App\Application\DTO\TimetableFetchResult;
use App\Domain\Flight\Direction;

/**
 * Keeps the latest saved timetable fetch per direction, together with its
 * fetch record (docs/data-source-verification.md §7 step 7). Earlier
 * fetches are not kept: ODPT allows timetable storage only until the next
 * fetch (§9 M1).
 */
interface TimetableRepositoryInterface
{
    /**
     * Replaces the stored timetable of the result's direction and its fetch
     * record in one transaction, when TimetableReplacementRule allows it.
     * The rule is applied right after the stored fetch record is re-read
     * inside the transaction, against the clock's time at that moment
     * (§7 step 7 「古い取得による置き換え」).
     *
     * Concurrent saves are expected to be serialised by the application lock
     * of the sync and gate commands (§7 step 7); without it, one of two
     * simultaneous saves may fail instead of returning a not-replaced outcome.
     * A failed save leaves the stored data unchanged and the repository
     * usable for the next save.
     *
     * @throws \Exception when the stored data cannot be read or written
     */
    public function save(TimetableFetchResult $result): TimetableSaveResult;

    /**
     * The stored timetable for the direction, or null when no fetch has been
     * saved yet. The request start time is in UTC, at whole seconds.
     */
    public function findLatest(Direction $direction): ?TimetableFetchResult;
}
