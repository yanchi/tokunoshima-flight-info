<?php

declare(strict_types=1);

namespace App\Infrastructure\Lock;

use App\Application\Port\TimetableLockInterface;
use App\Application\Port\TimetableLockUnavailable;
use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\LockFactory;

/**
 * The store comes from LOCK_DSN (a flock file by default). That works only
 * while production is a single container (§7 step 7, unverified); review
 * the store before running more than one.
 */
final readonly class SymfonyTimetableLock implements TimetableLockInterface
{
    private const NAME = 'tkn-timetable';

    public function __construct(
        private LockFactory $lockFactory,
        private LoggerInterface $logger,
    ) {
    }

    public function runExclusively(callable $callback): mixed
    {
        // No TTL and no release on destruct, so a release that already failed
        // below is not retried, and thrown, from a destructor. This assumes
        // the flock store (LOCK_DSN=flock): its file handle is closed, and the
        // lock freed, when the lock's key is destroyed. Other stores may keep
        // a lock whose release failed until the process ends.
        $lock = $this->lockFactory->createLock(self::NAME, null, false);
        if (!$lock->acquire(false)) {
            throw new TimetableLockUnavailable('The timetable lock is held by another process.');
        }

        try {
            return $callback();
        } finally {
            // A failed release must neither turn a committed save into a
            // failure nor hide what the callback threw.
            try {
                $lock->release();
            } catch (\Throwable $e) {
                $this->logger->error('Timetable lock could not be released ({exception}).', ['exception' => $e::class]);
            }
        }
    }
}
