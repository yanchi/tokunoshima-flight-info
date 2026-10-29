<?php

declare(strict_types=1);

namespace App\Application\DTO;

use App\Domain\Flight\Direction;
use App\Domain\Flight\ServiceDate;

final readonly class DailyFlightBoard
{
    /**
     * @param list<FlightRow> $flights
     */
    public function __construct(
        public ServiceDate $date,
        public Direction $direction,
        public DataAvailability $availability,
        public array $flights,
        public ?\DateTimeImmutable $fetchedAt,
        public ?string $sourceName,
    ) {
    }

    public function isUnavailable(): bool
    {
        return DataAvailability::Unavailable === $this->availability;
    }

    public function isStale(): bool
    {
        return DataAvailability::Stale === $this->availability;
    }
}
