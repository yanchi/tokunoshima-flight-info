<?php

declare(strict_types=1);

namespace App\Application\Port;

use App\Application\DTO\TimetableFetchResult;

/**
 * The outcome of a save, with what it was judged against.
 */
final readonly class TimetableSaveResult
{
    /**
     * @param ?TimetableFetchResult $previous the stored fetch as re-read inside the
     *                                        transaction before the judgment, or null
     *                                        when none was stored. When nothing was
     *                                        replaced, it is still the stored one.
     * @param \DateTimeImmutable    $judgedAt the time the judgment was made against
     */
    public function __construct(
        public TimetableSaveOutcome $outcome,
        public ?TimetableFetchResult $previous,
        public \DateTimeImmutable $judgedAt,
    ) {
    }
}
