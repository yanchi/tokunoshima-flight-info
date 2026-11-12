<?php

declare(strict_types=1);

namespace App\Application\UseCase;

use App\Application\DTO\TimetableFetchResult;
use App\Application\Gate\ComparedItemsHasher;
use App\Application\Gate\ComparedPeriod;
use App\Application\Port\TimetableFetchFailed;
use App\Application\Port\TimetableLockInterface;
use App\Application\Port\TimetableLockUnavailable;
use App\Application\Port\TimetableProviderInterface;
use App\Application\Port\TimetableRepositoryInterface;
use App\Application\Port\TimetableSaveOutcome;
use App\Application\Port\TimetableSaveResult;
use App\Application\Sync\TimetableSyncReport;
use App\Domain\Flight\Direction;
use App\Domain\Flight\TimetableFreshness;
use App\Domain\Flight\TimetableFreshnessPolicy;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Fetches and saves the timetable of both directions
 * (docs/data-source-verification.md §7 step 7). Each direction is fetched
 * and mapped outside the lock and saved inside it, so a slow API never
 * holds the lock.
 *
 * The comparison with the previous fetch runs inside the save transaction
 * (§7 step 7 「同期のトランザクション」); comparison (a) and the released state
 * will be added there with the gate settings. Until then nothing is
 * published from here, and a content change is only logged.
 *
 * Log lines carry directions, times, counts, exception classes, the fixed
 * failure reasons of the provider and its rate limit counts: never
 * credentials, request URLs or response values.
 */
final readonly class SyncTimetable
{
    public function __construct(
        private TimetableProviderInterface $provider,
        private TimetableRepositoryInterface $repository,
        private TimetableLockInterface $lock,
        private ClockInterface $clock,
        private TimetableFreshnessPolicy $freshnessPolicy,
        private ComparedItemsHasher $hasher,
        private LoggerInterface $logger,
    ) {
    }

    public function execute(): TimetableSyncReport
    {
        $thresholdsInvalid = !$this->freshnessPolicy->isValid();
        if ($thresholdsInvalid) {
            $this->logger->error('Timetable freshness thresholds are invalid; no timetable is published.');
        }

        $futureAtStart = $this->detectFutureRecordsAtStart();
        $futureRecordDetected = [] !== $futureAtStart;
        $notSaved = [];

        foreach (Direction::cases() as $direction) {
            $context = ['direction' => $direction->value];

            try {
                $fetched = $this->provider->fetch($direction);
            } catch (TimetableFetchFailed $e) {
                $this->logger->error('Timetable fetch failed: {reason}', $context + ['reason' => $e->getMessage()]);
                $notSaved[] = $direction;
                continue;
            } catch (\Throwable $e) {
                // The class only: an unexpected message may quote anything.
                $this->logger->error('Timetable fetch failed ({exception}).', $context + ['exception' => $e::class]);
                $notSaved[] = $direction;
                continue;
            }

            $changes = [];
            try {
                // Not an arrow function: it would capture $changes by value,
                // and the comparison result would never reach this scope.
                $saved = $this->lock->runExclusively(function () use ($fetched, &$changes): TimetableSaveResult {
                    return $this->repository->save(
                        $fetched,
                        function (?TimetableFetchResult $previous) use ($fetched, &$changes): void {
                            $changes = null === $previous ? [] : $this->changesSince($previous, $fetched);
                        },
                    );
                });
            } catch (TimetableLockUnavailable) {
                $this->logger->error('Timetable save skipped: the lock is held by another process.', $context);
                $notSaved[] = $direction;
                continue;
            } catch (\Throwable $e) {
                // The class only: driver messages may quote row values.
                $this->logger->error('Timetable save failed ({exception}).', $context + ['exception' => $e::class]);
                $notSaved[] = $direction;
                continue;
            }

            $context += [
                'fetch_started_at' => self::format($fetched->storedRequestStartedAt()),
                'stored_started_at' => null === $saved->previous ? null : self::format($saved->previous->requestStartedAt),
                'judged_at' => self::format($saved->judgedAt),
            ];

            switch ($saved->outcome) {
                case TimetableSaveOutcome::Saved:
                    $this->logger->info('Timetable saved ({entries} entries in {records} records).', $context + ['entries' => \count($fetched->entries), 'records' => $fetched->recordCount]);
                    break;
                case TimetableSaveOutcome::SavedOverFutureRecord:
                    $this->logger->error('Timetable saved over a stored fetch record dated in the future.', $context);
                    $futureRecordDetected = true;
                    break;
                case TimetableSaveOutcome::OlderThanStored:
                    $this->logger->info('Timetable not replaced: the stored fetch started later.', $context);
                    break;
                case TimetableSaveOutcome::SameSecondAsStored:
                    $this->logger->info('Timetable not replaced: the stored fetch started in the same second.', $context);
                    break;
                case TimetableSaveOutcome::CurrentFetchInFuture:
                    $this->logger->error('Timetable not saved: this fetch is dated after the time it was judged at.', $context);
                    $notSaved[] = $direction;
                    $futureRecordDetected = true;
                    break;
            }

            if ($saved->outcome->replaced() && null !== $saved->previous) {
                $this->logChanges($changes, $saved->previous, $fetched, $context);
            }
        }

        $futureRecordDetected = $this->logFreshness($futureAtStart) || $futureRecordDetected;

        return new TimetableSyncReport($notSaved, $thresholdsInvalid, $futureRecordDetected);
    }

    /**
     * Stored records later than the start of the run (§7 step 7 (3)).
     *
     * @return list<Direction>
     */
    private function detectFutureRecordsAtStart(): array
    {
        $now = $this->clock->now();
        $detected = [];
        foreach (Direction::cases() as $direction) {
            $stored = $this->repository->findLatest($direction);
            if (null !== $stored && $stored->requestStartedAt > $now) {
                $this->logger->error('Stored timetable fetch record is dated in the future (at the start).', [
                    'direction' => $direction->value,
                    'stored_started_at' => self::format($stored->requestStartedAt),
                    'now' => self::format($now),
                ]);
                $detected[] = $direction;
            }
        }

        return $detected;
    }

    /**
     * What changed against the replaced fetch. Content is compared by
     * hashing both over a period that holds every entry of either whole,
     * with the same hasher as the gate, so nothing is cut away (§7 step 7,
     * comparison (b), option A). The mapper has proved both fetches
     * hashable before they were saved; a failure here still rolls the save
     * back.
     *
     * @return list<'data_date'|'content'>
     *
     * @throws \JsonException
     */
    private function changesSince(TimetableFetchResult $previous, TimetableFetchResult $current): array
    {
        $changes = [];
        if ($previous->latestDataDate != $current->latestDataDate) {
            $changes[] = 'data_date';
        }

        $span = ComparedPeriod::covering([...$previous->entries, ...$current->entries]);
        if (null !== $span && $this->hasher->hash($span, $previous->entries) !== $this->hasher->hash($span, $current->entries)) {
            $changes[] = 'content';
        }

        return $changes;
    }

    /**
     * Logged only after the save has been committed.
     *
     * @param list<'data_date'|'content'> $changes
     * @param array<string, mixed>        $context
     */
    private function logChanges(array $changes, TimetableFetchResult $previous, TimetableFetchResult $current, array $context): void
    {
        if (\in_array('data_date', $changes, true)) {
            $this->logger->notice('Timetable dc:date changed; compare with the official timetable again.', $context + [
                'previous_data_date' => self::format($previous->latestDataDate),
                'data_date' => self::format($current->latestDataDate),
            ]);
        }
        if (\in_array('content', $changes, true)) {
            // Without gate settings, (a) and (b) cannot be told apart yet.
            $this->logger->notice('Timetable content changed since the previous fetch (no compared period is set).', $context);
        }
    }

    /**
     * Logs every direction whose stored timetable is past a threshold, on
     * every run while it lasts (§7 step 7 「運営者が気づく方法」).
     *
     * @param list<Direction> $futureAtStart already logged at the start
     *
     * @return bool whether a stored record is dated in the future
     */
    private function logFreshness(array $futureAtStart): bool
    {
        $now = $this->clock->now();
        $futureDetected = false;
        foreach (Direction::cases() as $direction) {
            $stored = $this->repository->findLatest($direction);
            if (null === $stored) {
                continue;
            }

            $context = [
                'direction' => $direction->value,
                'stored_started_at' => self::format($stored->requestStartedAt),
                'now' => self::format($now),
            ];
            switch ($this->freshnessPolicy->judge($stored->requestStartedAt, $now)) {
                case TimetableFreshness::Warning:
                    $this->logger->error('Timetable is past the warning threshold.', $context);
                    break;
                case TimetableFreshness::Suspended:
                    $this->logger->error('Timetable publication is suspended: past the stop-publishing threshold.', $context);
                    break;
                case TimetableFreshness::FutureDated:
                    // E.g. the clock was set back during the run.
                    if (!\in_array($direction, $futureAtStart, true)) {
                        $this->logger->error('Stored timetable fetch record is dated in the future (after the run).', $context);
                    }
                    $futureDetected = true;
                    break;
                case TimetableFreshness::Fresh:
                case TimetableFreshness::InvalidThresholds:
                    // Invalid thresholds are logged once at the start.
                    break;
            }
        }

        return $futureDetected;
    }

    private static function format(\DateTimeInterface $time): string
    {
        return $time->format(\DateTimeInterface::ATOM);
    }
}
