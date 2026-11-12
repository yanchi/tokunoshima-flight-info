<?php

declare(strict_types=1);

namespace App\Application\Sync;

use App\Domain\Flight\Direction;

/**
 * What a sync run found, reduced to what decides its exit code
 * (docs/data-source-verification.md §7 step 7 「同期の終了コード」).
 */
final readonly class TimetableSyncReport
{
    /**
     * @param list<Direction> $notSaved             (1) directions whose fetch could not be saved
     * @param bool            $thresholdsInvalid    (2)
     * @param bool            $futureRecordDetected (3) at the start, when judged, or after the run
     */
    public function __construct(
        public array $notSaved,
        public bool $thresholdsInvalid,
        public bool $futureRecordDetected,
    ) {
    }

    public function isSuccessful(): bool
    {
        return [] === $this->notSaved
            && !$this->thresholdsInvalid
            && !$this->futureRecordDetected;
    }
}
