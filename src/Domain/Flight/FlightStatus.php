<?php

declare(strict_types=1);

namespace App\Domain\Flight;

/**
 * Operational status of a flight.
 *
 * Scheduled means "only timetable data is known" and must never be shown as
 * on time. Unknown covers statuses the source reports but we cannot map.
 */
enum FlightStatus: string
{
    case Scheduled = 'scheduled';
    case OnTime = 'on_time';
    case Delayed = 'delayed';
    case Cancelled = 'cancelled';
    case Departed = 'departed';
    case Arrived = 'arrived';
    case Unknown = 'unknown';

    /**
     * Whether the status was observed from real-time operation data
     * (as opposed to being derived from the timetable or unmappable).
     */
    public function isObserved(): bool
    {
        return match ($this) {
            self::Scheduled, self::Unknown => false,
            default => true,
        };
    }
}
