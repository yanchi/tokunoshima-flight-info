<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Flight;

use App\Domain\Flight\FlightStatus;
use PHPUnit\Framework\TestCase;

final class FlightStatusTest extends TestCase
{
    public function testTimetableOnlyAndUnknownAreNotObserved(): void
    {
        self::assertFalse(FlightStatus::Scheduled->isObserved());
        self::assertFalse(FlightStatus::Unknown->isObserved());
    }

    public function testRealtimeStatusesAreObserved(): void
    {
        foreach ([FlightStatus::OnTime, FlightStatus::Delayed, FlightStatus::Cancelled, FlightStatus::Departed, FlightStatus::Arrived] as $status) {
            self::assertTrue($status->isObserved(), $status->value);
        }
    }

    public function testBackingValuesMatchDataModel(): void
    {
        self::assertSame(
            ['scheduled', 'on_time', 'delayed', 'cancelled', 'departed', 'arrived', 'unknown'],
            array_map(static fn (FlightStatus $s): string => $s->value, FlightStatus::cases()),
        );
    }
}
