<?php

declare(strict_types=1);

namespace App\Application\Purge;

/**
 * What a purge deleted, for the operator's output.
 */
final readonly class TimetablePurgeResult
{
    /**
     * @param array<string, int> $deletedRows    deleted rows by table, in deletion order
     * @param bool               $spaceReclaimed whether the database file was rewritten
     *                                           without the deleted rows (SQLite VACUUM);
     *                                           false on a database where this is not done
     */
    public function __construct(
        public array $deletedRows,
        public bool $spaceReclaimed,
    ) {
    }
}
