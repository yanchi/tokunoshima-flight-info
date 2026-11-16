<?php

declare(strict_types=1);

namespace App\Application\DTO;

use App\Domain\Flight\AirportCode;
use App\Domain\Flight\FlightNumber;
use App\Domain\Flight\FlightStatus;

/**
 * A flight whose timetable entry has a note (odpt:note). Its time is not
 * carried, so the timetable time cannot be shown as definite; the note is
 * shown as is and never parsed (CLAUDE.md §6, docs/data-source-verification.md §5).
 */
final readonly class NotedFlightRow
{
    public function __construct(
        public FlightNumber $flightNumber,
        public AirportCode $origin,
        public AirportCode $destination,
        public string $note,
    ) {
    }

    /**
     * Timetable only, so never on time (CLAUDE.md §6).
     */
    public function status(): FlightStatus
    {
        return FlightStatus::Scheduled;
    }
}
