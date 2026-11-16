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
}
