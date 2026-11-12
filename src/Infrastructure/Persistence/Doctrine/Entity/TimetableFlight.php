<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Doctrine\Entity;

use App\Application\DTO\TimetableEntry;
use App\Domain\Flight\AirportCode;
use App\Domain\Flight\Direction;
use App\Domain\Flight\FlightNumber;
use App\Domain\Flight\ServiceDate;
use App\Domain\Flight\Weekday;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One stored TimetableEntry. Columns are limited to the entry's items, so
 * nothing outside the compared items is stored either (§7 step 7).
 */
#[ORM\Entity]
#[ORM\Table(name: 'timetable_flight')]
#[ORM\Index(name: 'idx_timetable_flight_direction', columns: ['direction'])]
class TimetableFlight
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    private function __construct(
        #[ORM\Column(length: 16, enumType: Direction::class)]
        private Direction $direction,
        #[ORM\Column(length: 8)]
        private string $flightNumber,
        #[ORM\Column(length: 3)]
        private string $originAirport,
        #[ORM\Column(length: 3)]
        private string $destinationAirport,
        #[ORM\Column(type: Types::SMALLINT, enumType: Weekday::class)]
        private Weekday $weekday,
        #[ORM\Column(length: 5)]
        private string $originTime,
        #[ORM\Column(length: 5)]
        private string $destinationTime,
        #[ORM\Column(nullable: true)]
        private ?int $originDayDifference,
        #[ORM\Column(nullable: true)]
        private ?int $destinationDayDifference,
        // JST calendar days (§7 step 7). The date type keeps only Y-m-d,
        // so no time zone is involved in storing them.
        #[ORM\Column(type: Types::DATE_IMMUTABLE)]
        private \DateTimeImmutable $validFrom,
        #[ORM\Column(type: Types::DATE_IMMUTABLE)]
        private \DateTimeImmutable $validTo,
        #[ORM\Column(type: Types::TEXT, nullable: true)]
        private ?string $note,
        #[ORM\Column(length: 255)]
        private string $airline,
    ) {
    }

    public static function fromEntry(Direction $direction, TimetableEntry $entry): self
    {
        return new self(
            $direction,
            $entry->flightNumber->value,
            $entry->originAirport->value,
            $entry->destinationAirport->value,
            $entry->weekday,
            $entry->originTime,
            $entry->destinationTime,
            $entry->originDayDifference,
            $entry->destinationDayDifference,
            $entry->validFrom->toDateTime(),
            $entry->validTo->toDateTime(),
            $entry->note,
            $entry->airline,
        );
    }

    public function toEntry(): TimetableEntry
    {
        return new TimetableEntry(
            FlightNumber::fromString($this->flightNumber),
            AirportCode::fromString($this->originAirport),
            AirportCode::fromString($this->destinationAirport),
            $this->weekday,
            $this->originTime,
            $this->destinationTime,
            $this->originDayDifference,
            $this->destinationDayDifference,
            ServiceDate::fromString($this->validFrom->format('Y-m-d')),
            ServiceDate::fromString($this->validTo->format('Y-m-d')),
            $this->note,
            $this->airline,
        );
    }
}
