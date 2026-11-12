<?php

declare(strict_types=1);

namespace App\Application\DTO;

use App\Domain\Flight\Direction;

/**
 * A complete, validated timetable fetch for one direction. A fetch with any
 * invalid record never becomes a result (§7 step 7: no partial saves).
 */
final readonly class TimetableFetchResult
{
    /**
     * @param \DateTimeImmutable   $requestStartedAt start of the first attempt, in UTC
     * @param \DateTimeImmutable   $latestDataDate   newest dc:date among the records
     * @param int                  $recordCount      number of weekly records fetched
     * @param list<TimetableEntry> $entries
     */
    public function __construct(
        public Direction $direction,
        public \DateTimeImmutable $requestStartedAt,
        public \DateTimeImmutable $latestDataDate,
        public int $recordCount,
        public array $entries,
    ) {
    }
}
