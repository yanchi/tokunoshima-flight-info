<?php

declare(strict_types=1);

namespace App\Application\Port;

/**
 * What a save did with one direction's fetch (docs/data-source-verification.md
 * §7 step 7 「古い取得による置き換え」).
 */
enum TimetableSaveOutcome
{
    /** Replaced the stored fetch, or saved the first one. */
    case Saved;
    /**
     * The stored fetch record was later than now when judged, so it was
     * replaced without the older-fetch check (Issue #20). The run ends with
     * exit code 1.
     */
    case SavedOverFutureRecord;
    /** The stored fetch started at a later second; nothing was replaced. */
    case OlderThanStored;
    /** The stored fetch started in the same second, so the two cannot be ordered; nothing was replaced. */
    case SameSecondAsStored;
    /**
     * This fetch started later than now when judged (the clock was set back
     * mid-fetch), so its time cannot be trusted; nothing was replaced. Counts
     * as a direction that could not be saved.
     */
    case CurrentFetchInFuture;

    public function replaced(): bool
    {
        return self::Saved === $this || self::SavedOverFutureRecord === $this;
    }
}
