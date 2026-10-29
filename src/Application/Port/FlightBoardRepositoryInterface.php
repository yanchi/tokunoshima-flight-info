<?php

declare(strict_types=1);

namespace App\Application\Port;

use App\Application\DTO\FlightBoardSnapshot;
use App\Domain\Flight\Direction;
use App\Domain\Flight\ServiceDate;

/**
 * Read side of the stored flight data used by the departure/arrival board.
 */
interface FlightBoardRepositoryInterface
{
    public function findDaily(ServiceDate $date, Direction $direction): FlightBoardSnapshot;
}
