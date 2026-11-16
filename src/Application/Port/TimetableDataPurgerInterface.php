<?php

declare(strict_types=1);

namespace App\Application\Port;

use App\Application\Purge\TimetablePurgeResult;

/**
 * Deletes everything stored from the ODPT timetable when the licence ends
 * (docs/data-source-verification.md §2 「実装への影響」, 基本ライセンス 第13条第3項):
 * the timetable, the fetch records and the gate settings versions with
 * their withdrawals (2026-11-16 operator decision, Issue #21).
 *
 * Writers are expected to hold the application timetable lock.
 */
interface TimetableDataPurgerInterface
{
    /**
     * Deletes every row in one transaction, then frees the space the rows
     * took in the database file where the database supports it. When the
     * deletion fails, nothing is deleted and the exception is thrown again.
     *
     * @throws \App\Application\Purge\TimetableSpaceNotReclaimed when the rows are
     *                                                           deleted but freeing the space
     *                                                           failed; running it again frees it
     */
    public function purgeAll(): TimetablePurgeResult;
}
