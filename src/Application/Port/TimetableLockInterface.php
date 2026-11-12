<?php

declare(strict_types=1);

namespace App\Application\Port;

/**
 * The single application lock taken by the sync, the gate setting command
 * and the manual release (docs/data-source-verification.md §7 step 7
 * 「ロックの範囲と、取れなかったとき」). It never waits: the caller fails
 * instead, so a stuck run shows up in cron monitoring.
 */
interface TimetableLockInterface
{
    /**
     * Runs the callback while holding the lock.
     *
     * @template T
     *
     * @param callable(): T $callback
     *
     * @return T
     *
     * @throws TimetableLockUnavailable when another process holds the lock
     * @throws \Exception               whatever the callback throws
     */
    public function runExclusively(callable $callback): mixed;
}
