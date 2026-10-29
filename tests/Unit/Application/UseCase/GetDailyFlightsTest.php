<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\UseCase;

use App\Application\DTO\DataAvailability;
use App\Application\DTO\FlightBoardSnapshot;
use App\Application\UseCase\GetDailyFlights;
use App\Domain\Flight\Direction;
use App\Domain\Flight\FlightStatus;
use App\Domain\Flight\FreshnessPolicy;
use App\Domain\Flight\ServiceDate;
use App\Tests\Support\FictionalFlights;
use App\Tests\Support\InMemoryFlightBoardRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class GetDailyFlightsTest extends TestCase
{
    private InMemoryFlightBoardRepository $repository;
    private GetDailyFlights $useCase;
    private ServiceDate $date;

    protected function setUp(): void
    {
        $this->repository = new InMemoryFlightBoardRepository();
        $this->useCase = new GetDailyFlights(
            $this->repository,
            new FreshnessPolicy(600),
            new MockClock('2026-10-29T03:00:00+00:00'),
        );
        $this->date = ServiceDate::fromString('2026-10-29');
    }

    public function testNothingFetchedIsUnavailable(): void
    {
        $board = $this->useCase->execute($this->date, Direction::Departure);

        self::assertSame(DataAvailability::Unavailable, $board->availability);
        self::assertSame([], $board->flights);
        self::assertNull($board->fetchedAt);
        self::assertNull($board->sourceName);
    }

    public function testRecentFetchIsFresh(): void
    {
        $this->putFetchedAt('2026-10-29T02:55:00+00:00');

        $board = $this->useCase->execute($this->date, Direction::Departure);

        self::assertSame(DataAvailability::Fresh, $board->availability);
        self::assertCount(1, $board->flights);
        self::assertSame('test source', $board->sourceName);
    }

    public function testOldFetchIsStale(): void
    {
        $this->putFetchedAt('2026-10-29T02:49:59+00:00');

        $board = $this->useCase->execute($this->date, Direction::Departure);

        self::assertSame(DataAvailability::Stale, $board->availability);
        self::assertCount(1, $board->flights);
    }

    public function testReadsOnlyTheRequestedDirection(): void
    {
        $this->putFetchedAt('2026-10-29T02:55:00+00:00');

        $board = $this->useCase->execute($this->date, Direction::Arrival);

        self::assertSame(Direction::Arrival, $board->direction);
        self::assertSame(DataAvailability::Unavailable, $board->availability);
    }

    public function testTodayUsesTheJstDate(): void
    {
        $useCase = new GetDailyFlights(
            $this->repository,
            new FreshnessPolicy(600),
            new MockClock('2026-10-29T15:00:00+00:00'),
        );

        self::assertSame('2026-10-30', $useCase->today(Direction::Departure)->date->toString());
    }

    private function putFetchedAt(string $fetchedAt): void
    {
        $this->repository->put($this->date, Direction::Departure, new FlightBoardSnapshot(
            [FictionalFlights::departure(FlightStatus::Scheduled, '2026-10-29T10:00:00+09:00')],
            new \DateTimeImmutable($fetchedAt),
            'test source',
        ));
    }
}
