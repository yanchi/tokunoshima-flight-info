<?php

declare(strict_types=1);

namespace App\Application\Purge;

/**
 * The rows are deleted and committed, but the database file could not be
 * rewritten without them. Running the purge again frees the space.
 */
final class TimetableSpaceNotReclaimed extends \RuntimeException
{
    /**
     * @param array<string, int> $deletedRows deleted rows by table, in deletion order
     */
    public function __construct(public readonly array $deletedRows, \Throwable $previous)
    {
        parent::__construct('The rows are deleted, but the database file was not rewritten without them.', 0, $previous);
    }
}
