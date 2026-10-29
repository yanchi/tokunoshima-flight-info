<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Application\DTO\FlightBoardSnapshot;
use App\Application\Port\FlightBoardRepositoryInterface;
use App\Domain\Flight\Direction;
use App\Domain\Flight\ServiceDate;

/**
 * Used until a data source is approved (CLAUDE.md §7): there is no stored
 * flight data, so every board is reported as unavailable.
 */
final readonly class NoDataFlightBoardRepository implements FlightBoardRepositoryInterface
{
    public function findDaily(ServiceDate $date, Direction $direction): FlightBoardSnapshot
    {
        return FlightBoardSnapshot::unavailable();
    }
}
