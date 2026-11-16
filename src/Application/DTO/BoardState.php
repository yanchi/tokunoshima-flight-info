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
     * The stored data could not be read (e.g. the database or its tables
     * are missing). Nothing is known, not even the fetch time.
     */
    case ReadFailed;

    /** Past the stop-publishing threshold. */
    case Suspended;

    /**
     * The freshness thresholds are invalid: not shown, like a suspended
     * direction (§7 step 7), but without blaming the fetch.
     */
    case ThresholdsInvalid;

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
