<?php

declare(strict_types=1);

namespace App\Application\DTO;

use App\Domain\Flight\AirportCode;
use App\Domain\Flight\FlightNumber;
use App\Domain\Flight\FlightStatus;

/**
 * One line of the departure or arrival board.
 *
 * The times are the departure times on the departure board and the arrival
 * times on the arrival board. The estimated time is null unless the source
 * reports one; it is never derived from the scheduled time.
 */
final readonly class FlightRow
{
    public function __construct(
        public FlightNumber $flightNumber,
        public AirportCode $origin,
        public AirportCode $destination,
        public \DateTimeImmutable $scheduledAt,
        public ?\DateTimeImmutable $estimatedAt,
        public FlightStatus $status,
    ) {
    }
}
