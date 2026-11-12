<?php

declare(strict_types=1);

namespace App\Application\Port;

use App\Application\DTO\TimetableFetchResult;
use App\Domain\Flight\Direction;

/**
 * Fetches the weekly timetable of Tokunoshima flights for one direction.
 */
interface TimetableProviderInterface
{
    /**
     * @throws TimetableFetchFailed when the fetch fails or any record is invalid
     */
    public function fetch(Direction $direction): TimetableFetchResult;
}
