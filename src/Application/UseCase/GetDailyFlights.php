<?php

declare(strict_types=1);

namespace App\Application\UseCase;

use App\Application\DTO\DailyFlightBoard;
use App\Application\DTO\DataAvailability;
use App\Application\Port\FlightBoardRepositoryInterface;
use App\Domain\Flight\Direction;
use App\Domain\Flight\FreshnessPolicy;
use App\Domain\Flight\ServiceDate;
use Psr\Clock\ClockInterface;

/**
 * Builds the departure or arrival board for one service date.
 */
final readonly class GetDailyFlights
{
    public function __construct(
        private FlightBoardRepositoryInterface $repository,
        private FreshnessPolicy $freshnessPolicy,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Board for the current JST date. The clock is read once so that the
     * date and the freshness check refer to the same instant.
     */
    public function today(Direction $direction): DailyFlightBoard
    {
        $now = $this->clock->now();

        return $this->build(ServiceDate::fromInstant($now), $direction, $now);
    }

    public function execute(ServiceDate $date, Direction $direction): DailyFlightBoard
    {
        return $this->build($date, $direction, $this->clock->now());
    }

    private function build(ServiceDate $date, Direction $direction, \DateTimeImmutable $now): DailyFlightBoard
    {
        $snapshot = $this->repository->findDaily($date, $direction);

        // Freshness is judged on the whole snapshot for now. Timetable-only
        // data needs its own rule before a timetable source is added
        // (docs/data-source-verification.md §8).
        $availability = match (true) {
            null === $snapshot->fetchedAt => DataAvailability::Unavailable,
            $this->freshnessPolicy->isFresh($snapshot->fetchedAt, $now) => DataAvailability::Fresh,
            default => DataAvailability::Stale,
        };

        return new DailyFlightBoard(
            $date,
            $direction,
            $availability,
            $snapshot->flights,
            $snapshot->fetchedAt,
            $snapshot->sourceName,
        );
    }
}
