<?php

declare(strict_types=1);

namespace App\Application\UseCase;

use App\Application\DTO\BoardState;
use App\Application\DTO\DailyFlightBoard;
use App\Application\DTO\FlightRow;
use App\Application\DTO\NotedFlightRow;
use App\Application\DTO\TimetableEntry;
use App\Application\DTO\TimetableFetchResult;
use App\Application\Gate\TimetableGateState;
use App\Application\Port\TimetableGateRepositoryInterface;
use App\Application\Port\TimetableRepositoryInterface;
use App\Domain\Flight\Direction;
use App\Domain\Flight\FlightStatus;
use App\Domain\Flight\ServiceDate;
use App\Domain\Flight\TimetableFreshness;
use App\Domain\Flight\TimetableFreshnessPolicy;
use App\Domain\Flight\Weekday;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Builds the departure or arrival board for one service date from the
 * stored timetable. A day is shown only when the gate publishes it and the
 * direction's fetch is fresh enough (docs/data-source-verification.md §7
 * step 7, §8 「画面実装への申し送り」). Writes nothing, not even the sync log.
 */
final readonly class GetDailyFlights
{
    /**
     * @param string $timetableSourceName shown as the source of the stored timetable
     */
    public function __construct(
        private TimetableRepositoryInterface $timetableRepository,
        private TimetableGateRepositoryInterface $gateRepository,
        private TimetableFreshnessPolicy $freshnessPolicy,
        private ClockInterface $clock,
        private string $timetableSourceName,
        private LoggerInterface $logger,
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
        // One transaction, so the timetable and the gate come from the same
        // state: a sync that replaces the timetable and withdraws its period
        // commits both at once, and the board must not see only one of them.
        try {
            /** @var array{?TimetableFetchResult, ?TimetableGateState} $read */
            $read = $this->gateRepository->transactional(fn (): array => [
                $this->timetableRepository->findLatest($direction),
                $this->gateRepository->findCurrentState(),
            ]);
        } catch (\Throwable $e) {
            // Any failure shows a safe "unavailable" board instead of an error
            // page; nothing read before the failure is used (CLAUDE.md §8).
            $this->logger->error('The board could not read the stored timetable.', [
                'direction' => $direction->value,
                'exception' => $e,
            ]);

            return new DailyFlightBoard($date, $direction, BoardState::ReadFailed, false, [], [], null, null);
        }
        [$timetable, $gate] = $read;

        if (null === $timetable) {
            return new DailyFlightBoard($date, $direction, BoardState::NeverFetched, false, [], [], null, null);
        }

        $fetchedAt = $timetable->requestStartedAt;
        $freshness = $this->freshnessPolicy->judge($fetchedAt, $now);
        // judge() reports invalid thresholds before a future fetch time; the
        // board checks the time itself so a future time is never shown (§7 step 7).
        if ($fetchedAt > $now || TimetableFreshness::FutureDated === $freshness) {
            return $this->board($date, $direction, BoardState::FetchTimeUnknown, null);
        }
        if (TimetableFreshness::InvalidThresholds === $freshness) {
            return $this->board($date, $direction, BoardState::ThresholdsInvalid, $fetchedAt);
        }
        if (TimetableFreshness::Suspended === $freshness) {
            return $this->board($date, $direction, BoardState::Suspended, $fetchedAt);
        }

        // publishedPeriod() does not know about freshness; both are needed (§8).
        $period = $gate?->publishedPeriod($direction);
        if (null === $period || !$period->overlaps($date, $date)) {
            return $this->board($date, $direction, BoardState::OutsidePublishedPeriod, $fetchedAt);
        }

        $flights = [];
        $notedFlights = [];
        foreach (self::entriesOn($date, $direction, $timetable->entries) as $entry) {
            if (null !== $entry->note) {
                $notedFlights[] = new NotedFlightRow($entry->flightNumber, $entry->originAirport, $entry->destinationAirport, $entry->note);
                continue;
            }
            $flights[] = new FlightRow(
                $entry->flightNumber,
                $entry->originAirport,
                $entry->destinationAirport,
                self::scheduledAt($date, self::boardTime($direction, $entry)),
                // Timetable only: never shown as on time (CLAUDE.md §6).
                FlightStatus::Scheduled,
            );
        }

        return new DailyFlightBoard(
            $date,
            $direction,
            BoardState::Published,
            TimetableFreshness::Warning === $freshness,
            $flights,
            $notedFlights,
            $fetchedAt,
            $this->timetableSourceName,
        );
    }

    private function board(ServiceDate $date, Direction $direction, BoardState $state, ?\DateTimeImmutable $fetchedAt): DailyFlightBoard
    {
        return new DailyFlightBoard($date, $direction, $state, false, [], [], $fetchedAt, $this->timetableSourceName);
    }

    /**
     * The entries for the day's weekday whose valid days include it, in the
     * order of the time on the board, then the flight number. Notes are
     * never read here: a note limiting the operating days does not remove
     * the flight (§5).
     *
     * @param list<TimetableEntry> $entries
     *
     * @return list<TimetableEntry>
     */
    private static function entriesOn(ServiceDate $date, Direction $direction, array $entries): array
    {
        $weekday = Weekday::from((int) $date->toDateTime()->format('N'));
        $matching = array_values(array_filter(
            $entries,
            static fn (TimetableEntry $entry): bool => $entry->weekday === $weekday
                && $entry->validFrom->compareTo($date) <= 0
                && $entry->validTo->compareTo($date) >= 0,
        ));

        // The import rejects day differences other than 0 or null, so both
        // times fall on the service date (§7 step 7 「有効期間の暦日」).
        usort($matching, static fn (TimetableEntry $a, TimetableEntry $b): int => [self::boardTime($direction, $a), $a->flightNumber->value]
            <=> [self::boardTime($direction, $b), $b->flightNumber->value]);

        return $matching;
    }

    /**
     * The departure time on the departure board, the arrival time on the
     * arrival board.
     */
    private static function boardTime(Direction $direction, TimetableEntry $entry): string
    {
        return Direction::Departure === $direction ? $entry->originTime : $entry->destinationTime;
    }

    /**
     * @param string $time local time "HH:MM", validated on import
     */
    private static function scheduledAt(ServiceDate $date, string $time): \DateTimeImmutable
    {
        [$hour, $minute] = array_map(intval(...), explode(':', $time));

        return $date->toDateTime()->setTime($hour, $minute);
    }
}
