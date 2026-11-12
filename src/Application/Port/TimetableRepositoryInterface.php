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
     * record in one transaction. A fetch that did not start after the stored
     * one replaces nothing, so an overlapping manual sync cannot overwrite
     * newer data with older data.
     */
    public function save(TimetableFetchResult $result): TimetableSaveOutcome;

    /**
     * The stored timetable for the direction, or null when no fetch has been
     * saved yet. The request start time is in UTC, at whole seconds.
     */
    public function findLatest(Direction $direction): ?TimetableFetchResult;
}
