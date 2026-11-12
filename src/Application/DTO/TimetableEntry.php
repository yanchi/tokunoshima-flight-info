<?php

declare(strict_types=1);

namespace App\Application\DTO;

use App\Domain\Flight\AirportCode;
use App\Domain\Flight\FlightNumber;
use App\Domain\Flight\Weekday;

/**
 * One flight of a weekly timetable record, limited to the items that are
 * compared with the official timetable (docs/data-source-verification.md
 * §7 step 7). Items outside that list are not carried, so they cannot be
 * shown by accident.
 */
final readonly class TimetableEntry
{
    /**
     * @param string  $originTime               local time "HH:MM" without a date
     * @param string  $destinationTime          local time "HH:MM" without a date
     * @param ?int    $originDayDifference      null when the source omits it; never defaulted to 0
     * @param ?int    $destinationDayDifference null when the source omits it; never defaulted to 0
     * @param ?string $note                     Japanese free text, shown as is and never parsed
     * @param string  $airline                  raw source value (e.g. "odpt.Operator:JAL"). Not the
     *                                          operating carrier: JAC flights carry it too (§4)
     */
    public function __construct(
        public FlightNumber $flightNumber,
        public AirportCode $originAirport,
        public AirportCode $destinationAirport,
        public Weekday $weekday,
        public string $originTime,
        public string $destinationTime,
        public ?int $originDayDifference,
        public ?int $destinationDayDifference,
        public \DateTimeImmutable $validFrom,
        public \DateTimeImmutable $validTo,
        public ?string $note,
        public string $airline,
    ) {
    }
}
