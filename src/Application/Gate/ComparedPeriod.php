<?php

declare(strict_types=1);

namespace App\Application\Gate;

use App\Application\DTO\TimetableEntry;
use App\Domain\Flight\ServiceDate;

/**
 * The JST calendar days whose timetable the operator compared with the
 * official timetable (docs/data-source-verification.md §7 step 7). Both
 * ends are inclusive.
 */
final readonly class ComparedPeriod
{
    public function __construct(
        public ServiceDate $from,
        public ServiceDate $to,
    ) {
        if ($from->compareTo($to) > 0) {
            throw new \InvalidArgumentException(\sprintf('Compared period starts after it ends: %s to %s.', $from->toString(), $to->toString()));
        }
    }

    /**
     * The smallest period that holds every entry's valid days whole, or
     * null when there are no entries.
     *
     * @param list<TimetableEntry> $entries
     */
    public static function covering(array $entries): ?self
    {
        if ([] === $entries) {
            return null;
        }

        $from = $entries[0]->validFrom;
        $to = $entries[0]->validTo;
        foreach ($entries as $entry) {
            $from = $entry->validFrom->compareTo($from) < 0 ? $entry->validFrom : $from;
            $to = $entry->validTo->compareTo($to) > 0 ? $entry->validTo : $to;
        }

        return new self($from, $to);
    }

    /**
     * Whether the inclusive day range shares at least one day with this
     * period.
     */
    public function overlaps(ServiceDate $from, ServiceDate $to): bool
    {
        return $from->compareTo($this->to) <= 0 && $to->compareTo($this->from) >= 0;
    }

    /**
     * The inclusive day range cut to this period, as [from, to].
     *
     * @return array{ServiceDate, ServiceDate}
     *
     * @throws \InvalidArgumentException when the range does not overlap the period
     */
    public function clamp(ServiceDate $from, ServiceDate $to): array
    {
        if (!$this->overlaps($from, $to)) {
            throw new \InvalidArgumentException(\sprintf('Range %s to %s does not overlap the compared period.', $from->toString(), $to->toString()));
        }

        return [
            $from->compareTo($this->from) < 0 ? $this->from : $from,
            $to->compareTo($this->to) > 0 ? $this->to : $to,
        ];
    }
}
