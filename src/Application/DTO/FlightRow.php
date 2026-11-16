<?php

declare(strict_types=1);

namespace App\Application\DTO;

use App\Domain\Flight\AirportCode;
use App\Domain\Flight\FlightNumber;
use App\Domain\Flight\FlightStatus;

/**
 * One line of the departure or arrival board.
 *
 * The time is the departure time on the departure board and the arrival
 * time on the arrival board. There is no estimated time until operation
 * data is shown (Phase 2); it must then come from the source, never be
 * derived from the scheduled time.
 */
final readonly class FlightRow
{
    public function __construct(
        public FlightNumber $flightNumber,
        public AirportCode $origin,
        public AirportCode $destination,
        public \DateTimeImmutable $scheduledAt,
        public FlightStatus $status,
    ) {
    }
}
