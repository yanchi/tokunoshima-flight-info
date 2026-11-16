<?php

declare(strict_types=1);

namespace App\Application\DTO;

/**
 * What the board can show for one day and direction
 * (docs/data-source-verification.md §8 「画面実装への申し送り」).
 */
enum BoardState
{
    /** No timetable has ever been saved for the direction. */
    case NeverFetched;

    /**
     * Past the stop-publishing threshold, or the thresholds are invalid
     * (§7 step 7: both are shown the same way).
     */
    case Suspended;

    /** The fetch time is later than now: not shown, fetch time hidden. */
    case FetchTimeUnknown;

    /**
     * The day is outside the published period: no gate version, the
     * direction is withdrawn, or the day is outside the compared period.
     * The reason is not shown (2026-11-16 operator decision).
     */
    case OutsidePublishedPeriod;

    case Published;
}
