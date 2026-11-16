<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Doctrine\Entity;

use App\Application\Gate\ComparedPeriod;
use App\Application\Gate\TimetableGateSettings;
use App\Application\Gate\TimetableGateVersion;
use App\Domain\Flight\ServiceDate;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One gate settings version (§7 step 7). Never updated: setting the gate
 * again adds a version, and the latest id is the one in force.
 */
#[ORM\Entity]
#[ORM\Table(name: 'timetable_gate_version')]
class TimetableGateVersionRecord
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    private function __construct(
        // JST calendar days, as in timetable_flight.
        #[ORM\Column(type: Types::DATE_IMMUTABLE)]
        private \DateTimeImmutable $comparedFrom,
        #[ORM\Column(type: Types::DATE_IMMUTABLE)]
        private \DateTimeImmutable $comparedTo,
        #[ORM\Column(type: Types::DATE_IMMUTABLE)]
        private \DateTimeImmutable $comparedOn,
        #[ORM\Column(length: 40)]
        private string $recordCommit,
        #[ORM\Column(type: Types::DATETIME_UTC_IMMUTABLE)]
        private \DateTimeImmutable $departureDataDate,
        #[ORM\Column(type: Types::DATETIME_UTC_IMMUTABLE)]
        private \DateTimeImmutable $arrivalDataDate,
        #[ORM\Column(length: 64)]
        private string $departureHash,
        #[ORM\Column(length: 64)]
        private string $arrivalHash,
        #[ORM\Column(type: Types::DATETIME_UTC_IMMUTABLE)]
        private \DateTimeImmutable $setAt,
    ) {
    }

    public static function fromSettings(TimetableGateSettings $settings, \DateTimeImmutable $setAt): self
    {
        return new self(
            $settings->period->from->toDateTime(),
            $settings->period->to->toDateTime(),
            $settings->comparedOn->toDateTime(),
            $settings->recordCommit,
            $settings->departureDataDate,
            $settings->arrivalDataDate,
            $settings->departureHash,
            $settings->arrivalHash,
            $setAt,
        );
    }

    public function id(): int
    {
        return $this->id ?? throw new \LogicException('The gate version has not been stored yet.');
    }

    public function toVersion(): TimetableGateVersion
    {
        return new TimetableGateVersion(
            $this->id(),
            new TimetableGateSettings(
                new ComparedPeriod(self::day($this->comparedFrom), self::day($this->comparedTo)),
                self::day($this->comparedOn),
                $this->recordCommit,
                $this->departureDataDate,
                $this->arrivalDataDate,
                $this->departureHash,
                $this->arrivalHash,
            ),
            $this->setAt,
        );
    }

    private static function day(\DateTimeImmutable $date): ServiceDate
    {
        return ServiceDate::fromString($date->format('Y-m-d'));
    }
}
