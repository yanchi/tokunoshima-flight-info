<?php

declare(strict_types=1);

namespace App\Application\DTO;

use App\Domain\Flight\Direction;
use App\Domain\Flight\ServiceDate;

final readonly class DailyFlightBoard
{
    /**
     * @param bool                 $fetchOverdue past the warning threshold; only while published
     * @param list<FlightRow>      $flights      flights without a note; only while published
     * @param list<NotedFlightRow> $notedFlights flights with a note; only while published
     * @param ?\DateTimeImmutable  $fetchedAt    null when never fetched, unreadable or in the future
     * @param ?string              $sourceName   null only when never fetched or unreadable
     * @param ?GuidedDateRange     $guidedDates  the days that may be offered; null unless
     *                                           published or outside the published period
     */
    public function __construct(
        public ServiceDate $date,
        public Direction $direction,
        public BoardState $state,
        public bool $fetchOverdue,
        public array $flights,
        public array $notedFlights,
        public ?\DateTimeImmutable $fetchedAt,
        public ?string $sourceName,
        public ?GuidedDateRange $guidedDates,
    ) {
        $published = BoardState::Published === $state;
        if (!$published && ($fetchOverdue || [] !== $flights || [] !== $notedFlights)) {
            throw new \InvalidArgumentException('Only a published board has flights or a fetch warning.');
        }
        $nothingKnown = BoardState::NeverFetched === $state || BoardState::ReadFailed === $state;
        if ($nothingKnown !== (null === $sourceName)) {
            throw new \InvalidArgumentException('The source name is missing exactly when nothing was fetched or read.');
        }
        $hidesFetchTime = $nothingKnown || BoardState::FetchTimeUnknown === $state;
        if ($hidesFetchTime !== (null === $fetchedAt)) {
            throw new \InvalidArgumentException('The fetch time is shown unless nothing was fetched or read, or it is in the future.');
        }
        if ($published && !($guidedDates?->contains($date) ?? false)) {
            throw new \InvalidArgumentException('A published day is one of the guided dates.');
        }
        if (null !== $guidedDates && !$published && BoardState::OutsidePublishedPeriod !== $state) {
            throw new \InvalidArgumentException('Dates are offered only while the timetable may be shown.');
        }
        if (BoardState::OutsidePublishedPeriod === $state && ($guidedDates?->contains($date) ?? false)) {
            throw new \InvalidArgumentException('A guided day is published, not outside the published period.');
        }
    }

    /**
     * The day before, when it may be offered.
     */
    public function previousDate(): ?ServiceDate
    {
        return $this->guidedOrNull($this->date->addDays(-1));
    }

    /**
     * The day after, when it may be offered.
     */
    public function nextDate(): ?ServiceDate
    {
        return $this->guidedOrNull($this->date->addDays(1));
    }

    public function isPublished(): bool
    {
        return BoardState::Published === $this->state;
    }

    /**
     * Published, but the day has no flight at all in the timetable.
     */
    public function hasNoFlights(): bool
    {
        return $this->isPublished() && [] === $this->flights && [] === $this->notedFlights;
    }

    private function guidedOrNull(ServiceDate $date): ?ServiceDate
    {
        return ($this->guidedDates?->contains($date) ?? false) ? $date : null;
    }
}
