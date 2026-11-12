<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Application\Port\TimetableLockInterface;
use App\Application\Port\TimetableLockUnavailable;

/**
 * TEST ONLY. Held by "another process" for the given number of attempts.
 */
final class StubTimetableLock implements TimetableLockInterface
{
    public bool $held = false;

    public function __construct(private int $unavailableAttempts = 0)
    {
    }

    public function runExclusively(callable $callback): mixed
    {
        if ($this->unavailableAttempts > 0) {
            --$this->unavailableAttempts;
            throw new TimetableLockUnavailable('Held by a test.');
        }

        $this->held = true;
        try {
            return $callback();
        } finally {
            $this->held = false;
        }
    }
}
