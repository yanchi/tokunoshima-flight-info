<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\UseCase;

use App\Application\Port\TimetableDataPurgerInterface;
use App\Application\Port\TimetableLockUnavailable;
use App\Application\Purge\TimetablePurgeResult;
use App\Application\UseCase\PurgeTimetableData;
use App\Tests\Support\StubTimetableLock;
use PHPUnit\Framework\TestCase;

final class PurgeTimetableDataTest extends TestCase
{
    public function testPurgesWhileHoldingTheLock(): void
    {
        $lock = new StubTimetableLock();
        $purger = new class($lock) implements TimetableDataPurgerInterface {
            public bool $heldWhilePurging = false;

            public function __construct(private readonly StubTimetableLock $lock)
            {
            }

            public function purgeAll(): TimetablePurgeResult
            {
                $this->heldWhilePurging = $this->lock->held;

                return new TimetablePurgeResult(['t' => 1], true);
            }
        };

        $result = new PurgeTimetableData($purger, $lock)->execute();

        self::assertTrue($purger->heldWhilePurging);
        self::assertSame(['t' => 1], $result->deletedRows);
    }

    public function testNothingIsPurgedWhenTheLockIsHeld(): void
    {
        $purger = new class implements TimetableDataPurgerInterface {
            public bool $called = false;

            public function purgeAll(): TimetablePurgeResult
            {
                $this->called = true;

                return new TimetablePurgeResult([], true);
            }
        };

        try {
            new PurgeTimetableData($purger, new StubTimetableLock(1))->execute();
            self::fail('The lock was not reported as held.');
        } catch (TimetableLockUnavailable) {
        }

        self::assertFalse($purger->called);
    }
}
