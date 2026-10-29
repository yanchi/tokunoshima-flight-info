<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Application\DTO\FlightBoardSnapshot;
use App\Application\Port\FlightBoardRepositoryInterface;
use App\Domain\Flight\Direction;
use App\Domain\Flight\ServiceDate;

/**
 * TEST ONLY. Returns whatever snapshot the test put in; never wire this
 * outside the test suite.
 */
final class InMemoryFlightBoardRepository implements FlightBoardRepositoryInterface
{
    /** @var array<string, FlightBoardSnapshot> */
    private array $snapshots = [];

    public function put(ServiceDate $date, Direction $direction, FlightBoardSnapshot $snapshot): void
    {
        $this->snapshots[$date->toString().'/'.$direction->value] = $snapshot;
    }

    public function findDaily(ServiceDate $date, Direction $direction): FlightBoardSnapshot
    {
        return $this->snapshots[$date->toString().'/'.$direction->value] ?? FlightBoardSnapshot::unavailable();
    }
}
