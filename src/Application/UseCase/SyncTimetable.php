<?php

declare(strict_types=1);

namespace App\Application\UseCase;

use App\Application\DTO\TimetableEntry;
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
 * outside the lock and saved inside it, so a slow API never holds the lock.
 *
 * No gate settings exist yet, so comparison (a) is not made and nothing is
 * published from here. A change against the previous fetch is only logged.
 *
 * Log messages carry directions, times and counts only: never credentials,
 * request URLs or timetable contents.
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

        $futureRecordDetected = $this->detectFutureRecordsAtStart();
        $notSaved = [];
        $comparisonFailed = false;

        foreach (Direction::cases() as $direction) {
            $context = ['direction' => $direction->value];

            try {
                $fetched = $this->provider->fetch($direction);
            } catch (TimetableFetchFailed $e) {
                $this->logger->error('Timetable fetch failed: {reason}', $context + ['reason' => $e->getMessage()]);
                $notSaved[] = $direction;
                continue;
            }

            try {
                $saved = $this->lock->runExclusively(fn (): TimetableSaveResult => $this->repository->save($fetched));
            } catch (TimetableLockUnavailable) {
                $this->logger->error('Timetable save skipped: the lock is held by another process.', $context);
                $notSaved[] = $direction;
                continue;
            } catch (\Exception $e) {
                // The exception class only: driver messages may quote row values.
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
                $comparisonFailed = !$this->logChanges($saved->previous, $fetched, $context) || $comparisonFailed;
            }
        }

        $futureRecordDetected = $this->logFreshness() || $futureRecordDetected;

        return new TimetableSyncReport($notSaved, $thresholdsInvalid, $futureRecordDetected, $comparisonFailed);
    }

    /**
     * A stored record later than the start of the run (§7 step 7 (3)).
     */
    private function detectFutureRecordsAtStart(): bool
    {
        $now = $this->clock->now();
        $detected = false;
        foreach (Direction::cases() as $direction) {
            $stored = $this->repository->findLatest($direction);
            if (null !== $stored && $stored->requestStartedAt > $now) {
                $this->logger->error('Stored timetable fetch record is dated in the future.', [
                    'direction' => $direction->value,
                    'stored_started_at' => self::format($stored->requestStartedAt),
                    'now' => self::format($now),
                ]);
                $detected = true;
            }
        }

        return $detected;
    }

    /**
     * Logs a changed dc:date and changed content against the replaced fetch.
     * The content is compared by hashing both over a period that covers
     * every entry of either, with the same hasher as the gate, so nothing is
     * cut away (§7 step 7, comparison (b), option A).
     *
     * @param array<string, mixed> $context
     *
     * @return bool false when the content could not be compared
     */
    private function logChanges(TimetableFetchResult $previous, TimetableFetchResult $current, array $context): bool
    {
        if ($previous->latestDataDate != $current->latestDataDate) {
            $this->logger->notice('Timetable dc:date changed; compare with the official timetable again.', $context + [
                'previous_data_date' => self::format($previous->latestDataDate),
                'data_date' => self::format($current->latestDataDate),
            ]);
        }

        $span = self::span([...$previous->entries, ...$current->entries]);
        try {
            $changed = null !== $span
                && $this->hasher->hash($span, $previous->entries) !== $this->hasher->hash($span, $current->entries);
        } catch (\JsonException $e) {
            $this->logger->error('Timetable could not be compared with the previous fetch ({exception}).', $context + ['exception' => $e::class]);

            return false;
        }

        if ($changed) {
            $this->logger->notice('Timetable content changed since the previous fetch.', $context);
        }

        return true;
    }

    /**
     * Logs every direction whose stored timetable is past a threshold, on
     * every run while it lasts (§7 step 7 「運営者が気づく方法」).
     *
     * @return bool whether a stored record is dated in the future
     */
    private function logFreshness(): bool
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
            match ($this->freshnessPolicy->judge($stored->requestStartedAt, $now)) {
                TimetableFreshness::Warning => $this->logger->error('Timetable is past the warning threshold.', $context),
                TimetableFreshness::Suspended => $this->logger->error('Timetable publication is suspended: past the stop-publishing threshold.', $context),
                TimetableFreshness::FutureDated => $futureDetected = true,
                // Invalid thresholds are logged once at the start.
                TimetableFreshness::Fresh, TimetableFreshness::InvalidThresholds => null,
            };
        }

        return $futureDetected;
    }

    /**
     * @param list<TimetableEntry> $entries
     */
    private static function span(array $entries): ?ComparedPeriod
    {
        if ([] === $entries) {
            return null;
        }

        $from = $entries[0]->validFrom;
        $to = $entries[0]->validTo;
        foreach ($entries as $entry) {
            $from = $entry->validFrom->compareTo($from) < 0 ? $entry->validFrom : $from;
            $to = $entry->validTo->compareTo($to) > 0 ? $entry->validTo : $to;
        }

        return new ComparedPeriod($from, $to);
    }

    private static function format(\DateTimeInterface $time): string
    {
        return $time->format(\DateTimeInterface::ATOM);
    }
}
