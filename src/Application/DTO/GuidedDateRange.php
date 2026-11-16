<?php

declare(strict_types=1);

namespace App\Application\DTO;

use App\Domain\Flight\ServiceDate;

/**
 * The days a board may offer for one direction: from today (JST) to the
 * end of the published period, both inclusive (CLAUDE.md §6 「取得可能な
 * 日付範囲のみ案内」, docs/data-source-verification.md §8).
 */
final readonly class GuidedDateRange
{
    public function __construct(
        public ServiceDate $from,
        public ServiceDate $to,
    ) {
        if ($from->compareTo($to) > 0) {
            throw new \InvalidArgumentException(\sprintf('Guided dates start after they end: %s to %s.', $from->toString(), $to->toString()));
        }
    }

    public function contains(ServiceDate $date): bool
    {
        return $this->from->compareTo($date) <= 0 && $this->to->compareTo($date) >= 0;
    }
}
