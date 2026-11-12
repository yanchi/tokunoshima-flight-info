<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Doctrine\Entity;

use App\Domain\Flight\Direction;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * The fetch record of the stored timetable of one direction (§7 step 7:
 * request start time, newest dc:date, record count). Freshness and the
 * gate command read this, not the flight rows.
 */
#[ORM\Entity]
#[ORM\Table(name: 'timetable_fetch_record')]
class TimetableFetchRecord
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(length: 16, enumType: Direction::class)]
        private Direction $direction,
        #[ORM\Column(type: Types::DATETIME_UTC_IMMUTABLE)]
        private \DateTimeImmutable $requestStartedAt,
        #[ORM\Column(type: Types::DATETIME_UTC_IMMUTABLE)]
        private \DateTimeImmutable $latestDataDate,
        #[ORM\Column]
        private int $recordCount,
    ) {
    }

    public function replace(\DateTimeImmutable $requestStartedAt, \DateTimeImmutable $latestDataDate, int $recordCount): void
    {
        $this->requestStartedAt = $requestStartedAt;
        $this->latestDataDate = $latestDataDate;
        $this->recordCount = $recordCount;
    }

    public function direction(): Direction
    {
        return $this->direction;
    }

    public function requestStartedAt(): \DateTimeImmutable
    {
        return $this->requestStartedAt;
    }

    public function latestDataDate(): \DateTimeImmutable
    {
        return $this->latestDataDate;
    }

    public function recordCount(): int
    {
        return $this->recordCount;
    }
}
