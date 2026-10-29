<?php

declare(strict_types=1);

namespace App\Domain\Flight;

/**
 * Direction of a flight as seen from Tokunoshima Airport.
 */
enum Direction: string
{
    case Departure = 'departure';
    case Arrival = 'arrival';
}
