<?php

declare(strict_types=1);

namespace App\Application\UseCase;

use App\Application\Gate\ComparedItemsHasher;
use App\Application\Gate\ComparedPeriod;
use App\Application\Gate\TimetableGateRefused;
use App\Application\Gate\TimetableGateRequest;
use App\Application\Gate\TimetableGateSettings;
use App\Application\Gate\TimetableGateVersion;
use App\Application\Port\TimetableGateRepositoryInterface;
use App\Application\Port\TimetableLockInterface;
use App\Application\Port\TimetableRepositoryInterface;
use App\Domain\Flight\Direction;
use Psr\Clock\ClockInterface;

/**
 * Sets a new gate settings version from the stored timetable
 * (docs/data-source-verification.md §7 step 7 「設定値と外した状態の保存・解除」).
 * Setting a version clears every withdrawal, even when its values equal the
 * previous version's: the operator has compared again.
 *
 * The dc:date check, the hashes and the new version are made in one
 * transaction inside the application lock, so a sync cannot replace the
 * timetable in between.
 */
final readonly class SetTimetableGate
{
    public function __construct(
        private TimetableRepositoryInterface $timetables,
        private TimetableGateRepositoryInterface $gate,
        private TimetableLockInterface $lock,
        private ComparedItemsHasher $hasher,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws TimetableGateRefused                           when the stored timetable does not match the request
     * @throws \App\Application\Port\TimetableLockUnavailable when a sync or another command holds the lock
     */
    public function execute(TimetableGateRequest $request): TimetableGateVersion
    {
        return $this->lock->runExclusively(fn (): TimetableGateVersion => $this->gate->transactional(function () use ($request): TimetableGateVersion {
            $hashes = [];
            foreach (Direction::cases() as $direction) {
                $hashes[$direction->value] = $this->hashStored($request, $direction);
            }

            return $this->gate->addVersion(new TimetableGateSettings(
                $request->period,
                $request->comparedOn,
                $request->recordCommit,
                $request->departureDataDate,
                $request->arrivalDataDate,
                $hashes[Direction::Departure->value],
                $hashes[Direction::Arrival->value],
            ), $this->clock->now());
        }));
    }

    private function hashStored(TimetableGateRequest $request, Direction $direction): string
    {
        $stored = $this->timetables->findLatest($direction);
        if (null === $stored) {
            throw new TimetableGateRefused(\sprintf('No %s timetable has been saved yet.', $direction->value));
        }

        // Compared as instants: the same time in another offset is the same dc:date.
        if ($stored->latestDataDate != $request->dataDateFor($direction)) {
            throw new TimetableGateRefused(\sprintf('The %s dc:date does not match the stored timetable (stored: %s); compare again.', $direction->value, $stored->latestDataDate->format(\DateTimeInterface::ATOM)));
        }

        // Days without data must not pass for compared ones (§7 step 7).
        $span = ComparedPeriod::covering($stored->entries);
        $period = $request->period;
        if (null === $span || $period->from->compareTo($span->from) < 0 || $period->to->compareTo($span->to) > 0) {
            throw new TimetableGateRefused(\sprintf('The compared period is outside the stored %s timetable (%s).', $direction->value, null === $span ? 'no flights' : $span->from->toString().' to '.$span->to->toString()));
        }

        return $this->hasher->hash($period, $stored->entries);
    }
}
