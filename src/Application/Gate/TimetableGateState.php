<?php

declare(strict_types=1);

namespace App\Application\Gate;

use App\Domain\Flight\Direction;

/**
 * The version in force and the directions withdrawn from it, by comparison
 * (a) or by hand (docs/data-source-verification.md §7 step 7). Freshness is
 * judged separately, when the timetable is shown.
 */
final readonly class TimetableGateState
{
    /**
     * @param list<Direction> $withdrawn directions withdrawn from this version
     */
    public function __construct(
        public TimetableGateVersion $version,
        public array $withdrawn,
    ) {
    }

    public function isWithdrawn(Direction $direction): bool
    {
        return \in_array($direction, $this->withdrawn, true);
    }

    /**
     * The days of the direction that may be published: the compared period,
     * or null once the direction has been withdrawn.
     */
    public function publishedPeriod(Direction $direction): ?ComparedPeriod
    {
        return $this->isWithdrawn($direction) ? null : $this->version->settings->period;
    }
}
