<?php

declare(strict_types=1);

namespace App\Infrastructure\Lock;

use App\Application\Port\TimetableLockInterface;
use App\Application\Port\TimetableLockUnavailable;
use Symfony\Component\Lock\LockFactory;

/**
 * The store comes from LOCK_DSN (a flock file by default). That works only
 * while production is a single container (§7 step 7, unverified); review
 * the store before running more than one.
 */
final readonly class SymfonyTimetableLock implements TimetableLockInterface
{
    private const NAME = 'tkn-timetable';

    public function __construct(private LockFactory $lockFactory)
    {
    }

    public function runExclusively(callable $callback): mixed
    {
        // No TTL: a flock is freed by the OS when the process ends.
        $lock = $this->lockFactory->createLock(self::NAME, null);
        if (!$lock->acquire(false)) {
            throw new TimetableLockUnavailable('The timetable lock is held by another process.');
        }

        try {
            return $callback();
        } finally {
            $lock->release();
        }
    }
}
