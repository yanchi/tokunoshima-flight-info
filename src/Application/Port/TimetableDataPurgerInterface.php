<?php

declare(strict_types=1);

namespace App\Application\Port;

use App\Application\Purge\TimetablePurgeResult;

/**
 * Deletes everything stored from the ODPT timetable when the licence ends
 * (docs/data-source-verification.md §2 「許諾終了時の削除」, 基本ライセンス 第13条第3項):
 * the timetable, the fetch records and the gate settings versions with
 * their withdrawals (2026-11-16 operator decision, Issue #21).
 *
 * Writers are expected to hold the application timetable lock.
 */
interface TimetableDataPurgerInterface
{
    /**
     * Deletes every row in one transaction, then rewrites the database file
     * without the deleted rows where the database supports it (SQLite; in
     * WAL mode also checkpointed back into the main file). When the
     * deletion fails, nothing is deleted and the exception is thrown again.
     *
     * @throws \App\Application\Purge\TimetableSpaceNotReclaimed when the rows are
     *                                                           deleted but anything after the
     *                                                           commit failed; running it again
     *                                                           rewrites the file
     */
    public function purgeAll(): TimetablePurgeResult;
}
