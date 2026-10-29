<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\DTO;

use App\Application\DTO\FlightBoardSnapshot;
use App\Domain\Flight\FlightStatus;
use App\Tests\Support\FictionalFlights;
use PHPUnit\Framework\TestCase;

final class FlightBoardSnapshotTest extends TestCase
{
    public function testRejectsFetchTimeWithoutSource(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new FlightBoardSnapshot([], new \DateTimeImmutable('2026-10-29T03:00:00+00:00'), null);
    }

    public function testRejectsFlightsWithoutFetchTime(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new FlightBoardSnapshot(
            [FictionalFlights::departure(FlightStatus::Scheduled, '2026-10-29T10:00:00+09:00')],
            null,
            null,
        );
    }
}
