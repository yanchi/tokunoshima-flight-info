<?php

declare(strict_types=1);

namespace App\Application\Sync;

use App\Application\Port\TimetableSaveOutcome;

/**
 * Decides whether a fetch may replace the stored one (docs/data-source-verification.md
 * §7 step 7 「古い取得による置き換え」). Repositories call it inside their
 * transaction, right after re-reading the stored fetch record, with the
 * current time taken at that moment.
 */
final class TimetableReplacementRule
{
    /**
     * @param \DateTimeImmutable  $current the request start of this fetch, as stored (whole seconds)
     * @param ?\DateTimeImmutable $stored  the request start of the stored fetch, or null when none
     * @param \DateTimeImmutable  $now     the current time, not rounded
     */
    public static function decide(\DateTimeImmutable $current, ?\DateTimeImmutable $stored, \DateTimeImmutable $now): TimetableSaveOutcome
    {
        // Checked before everything else: a time later than now must not be
        // stored, whatever the stored record is (PR #24 review).
        if ($current > $now) {
            return TimetableSaveOutcome::CurrentFetchInFuture;
        }

        if (null === $stored) {
            return TimetableSaveOutcome::Saved;
        }

        // Strictly later only. A record in the future would keep a correct
        // fetch from replacing it until that time passed (Issue #20).
        if ($stored > $now) {
            return TimetableSaveOutcome::SavedOverFutureRecord;
        }

        return match ($current <=> $stored) {
            1 => TimetableSaveOutcome::Saved,
            0 => TimetableSaveOutcome::SameSecondAsStored,
            -1 => TimetableSaveOutcome::OlderThanStored,
        };
    }
}
