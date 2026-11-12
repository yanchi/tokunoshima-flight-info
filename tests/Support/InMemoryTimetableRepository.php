<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Application\DTO\TimetableFetchResult;
use App\Application\Port\TimetableRepositoryInterface;
use App\Application\Port\TimetableSaveResult;
use App\Application\Sync\TimetableReplacementRule;
use App\Domain\Flight\Direction;
use Psr\Clock\ClockInterface;

/**
 * TEST ONLY. Applies the same replacement rule as the Doctrine repository.
 */
final class InMemoryTimetableRepository implements TimetableRepositoryInterface
{
    /** @var array<string, TimetableFetchResult> */
    private array $stored = [];

    /** @var array<string, \Exception> */
    private array $failures = [];

    /** @var list<bool> whether the watched lock was held, per save */
    public array $lockedDuringSave = [];

    private ?StubTimetableLock $watchedLock = null;

    public function __construct(private readonly ClockInterface $clock)
    {
    }

    public function watchLock(StubTimetableLock $lock): void
    {
        $this->watchedLock = $lock;
    }

    public function store(TimetableFetchResult $result): void
    {
        $this->stored[$result->direction->value] = self::asStored($result);
    }

    public function failNextSave(Direction $direction, \Exception $exception): void
    {
        $this->failures[$direction->value] = $exception;
    }

    public function save(TimetableFetchResult $result): TimetableSaveResult
    {
        if (null !== $this->watchedLock) {
            $this->lockedDuringSave[] = $this->watchedLock->held;
        }
        if (isset($this->failures[$result->direction->value])) {
            $failure = $this->failures[$result->direction->value];
            unset($this->failures[$result->direction->value]);
            throw $failure;
        }

        $previous = $this->stored[$result->direction->value] ?? null;
        $now = $this->clock->now();
        $outcome = TimetableReplacementRule::decide($result->storedRequestStartedAt(), $previous?->requestStartedAt, $now);
        if ($outcome->replaced()) {
            $this->store($result);
        }

        return new TimetableSaveResult($outcome, $previous, $now);
    }

    public function findLatest(Direction $direction): ?TimetableFetchResult
    {
        return $this->stored[$direction->value] ?? null;
    }

    private static function asStored(TimetableFetchResult $result): TimetableFetchResult
    {
        return new TimetableFetchResult($result->direction, $result->storedRequestStartedAt(), $result->latestDataDate, $result->recordCount, $result->entries);
    }
}
