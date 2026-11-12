<?php

declare(strict_types=1);

namespace App\Domain\Flight;

/**
 * How a direction's stored timetable may be shown, judged from the time
 * since its fetch (docs/data-source-verification.md §7 step 7 「時刻表の鮮度の閾値」).
 */
enum TimetableFreshness
{
    /** Within the warning threshold. */
    case Fresh;
    /** Past the warning threshold: still shown, with the fetch time and a warning. */
    case Warning;
    /** Past the stop-publishing threshold: not shown. */
    case Suspended;
    /** The fetch time is later than now (clock skew or bad data): not shown, fetch time hidden. */
    case FutureDated;
    /** The thresholds are invalid: no timetable is shown. */
    case InvalidThresholds;

    public function isPublishable(): bool
    {
        return self::Fresh === $this || self::Warning === $this;
    }
}
